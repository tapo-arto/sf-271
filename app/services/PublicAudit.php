<?php
declare(strict_types=1);

/**
 * PublicAudit - write-only audit log for public embed views.
 *
 * All exceptions are swallowed so that audit logging can never break a response.
 */
class PublicAudit
{
    /**
     * Log a public embed view event.
     *
     * @param \PDO        $pdo
     * @param string|null $jti      Token JTI (null for anonymous / invalid token requests)
     * @param string      $viewType View type string ('carousel', 'archive', 'unknown', …)
     * @param int|null    $siteId   Site restriction from token, or null for all-sites
     * @param int         $status   HTTP status code being returned
     */
    public static function log(
        \PDO    $pdo,
        ?string $jti,
        string  $viewType,
        ?int    $siteId,
        int     $status
    ): void {
        try {
            $ip        = self::getIp();
            $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

            $stmt = $pdo->prepare('
                INSERT INTO sf_public_views_log
                    (jti, view_type, site_id, ip, user_agent, status)
                VALUES
                    (:jti, :view_type, :site_id, :ip, :user_agent, :status)
            ');

            $stmt->execute([
                ':jti'        => $jti !== '' ? $jti : null,
                ':view_type'  => $viewType,
                ':site_id'    => $siteId,
                ':ip'         => $ip,
                ':user_agent' => $userAgent !== '' ? $userAgent : null,
                ':status'     => $status,
            ]);
        } catch (\Throwable $ignored) {
            // Audit must never break the response
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function getIp(): string
    {
        $raw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $ip  = trim(explode(',', (string)$raw)[0]);

        return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
    }
}
