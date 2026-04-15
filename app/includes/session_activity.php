<?php
// app/includes/session_activity.php
declare(strict_types=1);

require_once __DIR__ . '/../../config.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/audit_log.php';

/**
 * Session activity tracking:
 * - Enforce inactivity timeout ($config['session']['timeout'])
 * - Log session_expired + session_resumed into audit log
 * - Update last activity timestamp
 *
 * Options:
 *  - is_api (bool)
 *  - is_fetch (bool)
 */
function sf_session_activity_tick(array $opts = []): void
{
    global $config;

    $user = sf_current_user();
    if (!$user) {
        return;
    }

    $now = time();

    $timeout = (int)($config['session']['timeout'] ?? 0); // inactivity timeout (seconds)
    $last    = isset($_SESSION['sf_last_activity']) ? (int)$_SESSION['sf_last_activity'] : $now;
    $gap     = max(0, $now - $last);

    $isApi   = !empty($opts['is_api']);
    $isFetch = !empty($opts['is_fetch']);

    // 1) Enforce inactivity timeout
    if ($timeout > 0 && $gap > $timeout) {
        // audit: session expired
        sf_audit_log(
            'session_expired',
            'user',
            (int)$user['id'],
            [
                'inactive_seconds' => $gap,
                'timeout_seconds'  => $timeout,
                'path'             => $_SERVER['REQUEST_URI'] ?? '',
                'ip'               => $_SERVER['REMOTE_ADDR'] ?? '',
                'ua'               => substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 180),
            ],
            (int)$user['id'],
            'info'
        );

        // destroy session securely
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'] ?? '/',
                $params['domain'] ?? '',
                (bool)($params['secure'] ?? false),
                (bool)($params['httponly'] ?? true)
            );
        }
        session_destroy();

        if ($isApi || $isFetch) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            // yhtenäinen formaatti frontendille
echo json_encode(['success' => false, 'error' => 'Session expired'], JSON_UNESCAPED_UNICODE);
            exit;
        }

        sf_redirect_to_login();
    }

    // 2) Log "resume" if user returns after a longer gap (but still within timeout)
    // Use threshold = min(900s, max(300s, timeout/2)) to avoid spam.
    if ($timeout > 0) {
        $resumeAfter = (int)max(300, min(900, (int)floor($timeout / 2)));
        if ($gap >= $resumeAfter) {
            $lastLogged = (int)($_SESSION['sf_last_resume_log'] ?? 0);

            // log at most once per resume window
            if ($now - $lastLogged >= $resumeAfter) {
                sf_audit_log(
                    'session_resumed',
                    'user',
                    (int)$user['id'],
                    [
                        'gap_seconds' => $gap,
                        'path'        => $_SERVER['REQUEST_URI'] ?? '',
                        'ip'          => $_SERVER['REMOTE_ADDR'] ?? '',
                    ],
                    (int)$user['id'],
                    'info'
                );

                $_SESSION['sf_last_resume_log'] = $now;
            }
        }
    }

    // 3) Update activity timestamp
    $_SESSION['sf_last_activity'] = $now;

    // Keep session cookie alive for active users
    sf_refresh_session_cookie();
}