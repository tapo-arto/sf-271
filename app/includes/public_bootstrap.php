<?php
// public_bootstrap.php - minimal bootstrap for public embed (no session, no auth)
declare(strict_types=1);

// Prevent direct access
if (basename(__FILE__) === basename($_SERVER['PHP_SELF'] ?? '')) {
    http_response_code(403);
    exit('Access Denied');
}

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/../../assets/lib/Database.php';
require_once __DIR__ . '/../../assets/lib/sf_terms.php';
require_once __DIR__ . '/../services/EmbedToken.php';
require_once __DIR__ . '/../services/PublicRateLimit.php';
require_once __DIR__ . '/../services/PublicAudit.php';

/**
 * Apply public-embed-specific security headers.
 *
 * This REPLACES the restrictive defaults set by security_headers.php
 * (which blocks all framing) with headers that permit embedding from
 * the token's allowed origin.
 *
 * @param string $allowedOrigin Full origin URL, e.g. "https://intra.company.fi",
 *                              or "'none'" to block all framing.
 */
function sf_public_headers(string $allowedOrigin): void
{
    // Sanitize allowed origin against CSP/header injection.
    // Remove characters that could terminate the directive or inject new headers.
    $safeOrigin = preg_replace('/[;\r\n\0]/', '', $allowedOrigin);

    // Validate that after sanitization the value still matches a valid HTTP(S) origin,
    // optionally with port number. Fall back to 'none' if it doesn't.
    if (!preg_match('#^https?://[a-zA-Z0-9._-]+(:[0-9]{1,5})?$#', $safeOrigin)) {
        $safeOrigin = "'none'";
    }

    // Remove conflicting headers set by security_headers.php
    header_remove('X-Frame-Options');
    header_remove('Content-Security-Policy');
    header_remove('Cross-Origin-Opener-Policy');
    header_remove('Cross-Origin-Resource-Policy');

    header(
        "Content-Security-Policy: default-src 'self'; "
        . "script-src 'self' 'unsafe-inline'; "
        . "style-src 'self' 'unsafe-inline'; "
        . "img-src 'self' data: https:; "
        . "font-src 'self' data:; "
        . "connect-src 'self'; "
        . "object-src 'none'; "
        . "frame-ancestors " . $safeOrigin . "; "
        . "base-uri 'self'; "
        . "form-action 'none';"
    );
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header('Cache-Control: private, no-store');
}

/**
 * Send a JSON error response and terminate.
 *
 * @param int    $code   HTTP status code
 * @param string $message Short error label
 * @param string $detail  Optional detail string
 * @return never
 */
function sf_public_error(int $code, string $message, string $detail = ''): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $message, 'detail' => $detail], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Return the best-guess client IP address.
 *
 * Takes the first IP from an X-Forwarded-For chain when available,
 * validates it, and falls back to '0.0.0.0' on failure.
 */
function sf_public_ip(): string
{
    $raw = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $ip  = trim(explode(',', (string)$raw)[0]);

    return filter_var($ip, FILTER_VALIDATE_IP) !== false ? $ip : '0.0.0.0';
}
