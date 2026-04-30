<?php
declare(strict_types=1);

/**
 * EmbedToken - HMAC-SHA256 signed embed token service.
 *
 * Token format: base64url(json_payload).base64url(hmac_sha256(json_payload, secret))
 *
 * Payload claims:
 *   iss  - issuer ('sf-embed')
 *   aud  - allowed origin URL
 *   view - view type ('carousel' or 'archive')
 *   site - site_id as string, or '*' for all sites
 *   exp  - expiry unix timestamp
 *   nbf  - not-before unix timestamp
 *   kid  - key id for secret lookup
 *   jti  - unique token id (UUID)
 */
class EmbedToken
{
    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Issue a signed token from the given payload.
     *
     * @param  array<string,mixed> $payload
     * @param  string              $kid     Key id (default 'v1')
     * @return string              Signed token string
     * @throws \RuntimeException   If no secret is configured for the kid
     */
    public static function issue(array $payload, string $kid = 'v1'): string
    {
        $secret      = self::getSecret($kid);
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($jsonPayload === false) {
            throw new \RuntimeException('Failed to encode token payload as JSON');
        }

        $sig = hash_hmac('sha256', $jsonPayload, $secret, true);

        return self::b64url($jsonPayload) . '.' . self::b64url($sig);
    }

    /**
     * Verify and decode a token string.
     *
     * @param  string $token Raw token from the request
     * @param  \PDO   $pdo   Database connection for revocation check
     * @return array<string,mixed> Decoded payload
     * @throws \InvalidArgumentException On malformed token or bad signature
     * @throws \RuntimeException         On expired / revoked / not-yet-valid token
     */
    public static function verify(string $token, \PDO $pdo): array
    {
        // ---- structural validation ----
        $parts = explode('.', $token);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException('Malformed embed token');
        }

        [$b64Payload, $b64Sig] = $parts;

        $jsonPayload = self::b64urlDecode($b64Payload);
        if ($jsonPayload === false) {
            throw new \InvalidArgumentException('Token payload base64 decode failed');
        }

        /** @var array<string,mixed>|null $payload */
        $payload = json_decode($jsonPayload, true);
        if (!is_array($payload)) {
            throw new \InvalidArgumentException('Token payload JSON decode failed');
        }

        // ---- signature verification ----
        $kid    = isset($payload['kid']) ? (string)$payload['kid'] : 'v1';
        $secret = self::getSecret($kid);

        $expectedSig = hash_hmac('sha256', $jsonPayload, $secret, true);
        $providedSig = self::b64urlDecode($b64Sig);

        if ($providedSig === false || !hash_equals($expectedSig, $providedSig)) {
            throw new \InvalidArgumentException('Token signature invalid');
        }

        // ---- time claims ----
        $now = time();

        if (isset($payload['nbf']) && $now < (int)$payload['nbf']) {
            throw new \RuntimeException('Token not yet valid');
        }

        if (isset($payload['exp']) && $now >= (int)$payload['exp']) {
            throw new \RuntimeException('Token has expired');
        }

        if (!isset($payload['iss']) || $payload['iss'] !== 'sf-embed') {
            throw new \InvalidArgumentException('Token issuer invalid');
        }

        // ---- revocation check & usage update ----
        $jti = isset($payload['jti']) ? (string)$payload['jti'] : '';
        if ($jti !== '') {
            $stmt = $pdo->prepare(
                'SELECT revoked_at FROM sf_embed_tokens WHERE jti = :jti LIMIT 1'
            );
            $stmt->execute([':jti' => $jti]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);

            if ($row === false) {
                throw new \RuntimeException('Token not found in registry');
            }

            if ($row['revoked_at'] !== null) {
                throw new \RuntimeException('Token has been revoked');
            }

            // Update usage tracking (non-critical: ignore errors)
            try {
                $upd = $pdo->prepare(
                    'UPDATE sf_embed_tokens
                        SET last_used_at = NOW(),
                            use_count    = use_count + 1
                      WHERE jti = :jti'
                );
                $upd->execute([':jti' => $jti]);
            } catch (\Throwable $ignored) {
                // usage tracking must not break the response
            }
        }

        return $payload;
    }

    /**
     * Revoke a token by its jti.
     *
     * @throws \RuntimeException If the token is not found
     */
    public static function revoke(string $jti, \PDO $pdo): void
    {
        $stmt = $pdo->prepare(
            'UPDATE sf_embed_tokens SET revoked_at = NOW() WHERE jti = :jti'
        );
        $stmt->execute([':jti' => $jti]);

        if ($stmt->rowCount() === 0) {
            throw new \RuntimeException('Token not found: ' . $jti);
        }
    }

    /**
     * Return the HMAC secret for the given key id.
     *
     * Resolution order:
     *  1. EMBED_KEYS_JSON env var  – JSON object {"v1":"secret1","v2":"secret2"}
     *  2. EMBED_SECRET env var     – used when kid === 'v1'
     *
     * @throws \RuntimeException If no secret is configured
     */
    public static function getSecret(string $kid): string
    {
        // Multi-key JSON map
        $keysJson = (string)(getenv('EMBED_KEYS_JSON') ?: '');
        if ($keysJson !== '') {
            /** @var array<string,string>|null $keys */
            $keys = json_decode($keysJson, true);
            if (is_array($keys) && isset($keys[$kid]) && $keys[$kid] !== '') {
                return $keys[$kid];
            }
        }

        // Single-key fallback (only for kid='v1')
        if ($kid === 'v1') {
            $secret = (string)(getenv('EMBED_SECRET') ?: '');
            if ($secret !== '') {
                return $secret;
            }
        }

        throw new \RuntimeException(
            'No embed secret configured for kid "' . $kid . '". '
            . 'Set EMBED_SECRET or EMBED_KEYS_JSON in .env'
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function b64url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * @return string|false
     */
    private static function b64urlDecode(string $data)
    {
        $padded = strtr($data, '-_', '+/');
        $mod    = strlen($padded) % 4;
        if ($mod !== 0) {
            $padded .= str_repeat('=', 4 - $mod);
        }

        return base64_decode($padded, true);
    }
}
