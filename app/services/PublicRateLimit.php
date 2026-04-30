<?php
declare(strict_types=1);

/**
 * PublicRateLimit - sliding-window rate limiter for the public embed endpoints.
 *
 * Limits are enforced via the sf_public_rate_limit table using MySQL's
 * INSERT … ON DUPLICATE KEY UPDATE atomics.
 *
 * Limits:
 *   IP  – 120 req/min  (type='public'), 60 req/min (type='api')
 *   JTI – 1 000 req/h  per token
 */
class PublicRateLimit
{
    private const LIMITS = [
        'public' => ['seconds' => 60,   'ip_max' => 120],
        'api'    => ['seconds' => 60,   'ip_max' => 60],
    ];

    private const JTI_MAX     = 1000;
    private const JTI_SECONDS = 3600;

    /** Cleanup probability (1 in N requests triggers pruning) */
    private const CLEANUP_CHANCE = 200;

    /**
     * Check rate limits for the current request.
     *
     * @param  \PDO        $pdo
     * @param  string      $ip   Client IP address
     * @param  string|null $jti  Token JTI, or null for anonymous requests
     * @param  string      $type 'public' or 'api'
     * @return bool  true = allowed, false = rate limit exceeded
     */
    public static function check(\PDO $pdo, string $ip, ?string $jti, string $type = 'public'): bool
    {
        $limit = self::LIMITS[$type] ?? self::LIMITS['public'];

        // -- IP window --
        $windowStart = self::windowStart($limit['seconds']);
        $ipCount     = self::increment($pdo, $ip, 'ip', $windowStart, $limit['seconds']);

        if ($ipCount > $limit['ip_max']) {
            return false;
        }

        // -- JTI window --
        if ($jti !== null && $jti !== '') {
            $jtiWindow = self::windowStart(self::JTI_SECONDS);
            $jtiCount  = self::increment($pdo, $jti, 'jti', $jtiWindow, self::JTI_SECONDS);

            if ($jtiCount > self::JTI_MAX) {
                return false;
            }
        }

        // Probabilistic cleanup of old rows
        if (mt_rand(1, self::CLEANUP_CHANCE) === 1) {
            self::cleanup($pdo);
        }

        return true;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Upsert a counter row and return the new count.
     */
    private static function increment(
        \PDO   $pdo,
        string $keyValue,
        string $keyType,
        string $windowStart,
        int    $windowSeconds
    ): int {
        $sql = '
            INSERT INTO sf_public_rate_limit
                (key_value, key_type, window_start, window_seconds, request_count)
            VALUES
                (:kv, :kt, :ws, :wsec, 1)
            ON DUPLICATE KEY UPDATE
                request_count = request_count + 1
        ';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':kv'   => $keyValue,
            ':kt'   => $keyType,
            ':ws'   => $windowStart,
            ':wsec' => $windowSeconds,
        ]);

        // Fetch current count
        $sel = $pdo->prepare(
            'SELECT request_count FROM sf_public_rate_limit
              WHERE key_value = :kv AND key_type = :kt
                AND window_start = :ws AND window_seconds = :wsec
              LIMIT 1'
        );
        $sel->execute([
            ':kv'   => $keyValue,
            ':kt'   => $keyType,
            ':ws'   => $windowStart,
            ':wsec' => $windowSeconds,
        ]);

        return (int)($sel->fetchColumn() ?: 0);
    }

    /**
     * Return the start of the current window as a MySQL DATETIME string.
     */
    private static function windowStart(int $seconds): string
    {
        $now    = time();
        $wstart = $now - ($now % $seconds);
        return date('Y-m-d H:i:s', $wstart);
    }

    /**
     * Delete expired rate-limit rows (older than 2 hours).
     */
    private static function cleanup(\PDO $pdo): void
    {
        try {
            $pdo->exec(
                "DELETE FROM sf_public_rate_limit
                  WHERE window_start < DATE_SUB(NOW(), INTERVAL 2 HOUR)"
            );
        } catch (\Throwable $ignored) {
            // cleanup failures must not affect the response
        }
    }
}
