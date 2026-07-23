<?php
declare(strict_types=1);

if (defined('TASKFORCE_BOOTSTRAP_SESSION_LOADED')) {
    return;
}
define('TASKFORCE_BOOTSTRAP_SESSION_LOADED', true);

if (!function_exists('taskforce_start_session')) {
    function taskforce_start_session()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        $session = app_config('session', []);
        session_name((string) ($session['name'] ?? 'taskforce_session'));
        $cookieLifetime = (int) ($session['lifetime'] ?? 28800);
        $cookiePath = '/';
        $cookieDomain = '';
        $cookieSecure = (bool) ($session['secure'] ?? false);
        $cookieHttpOnly = (bool) ($session['httponly'] ?? true);
        $cookieSameSite = (string) ($session['samesite'] ?? 'Lax');

        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params([
                'lifetime' => $cookieLifetime,
                'path' => $cookiePath,
                'domain' => $cookieDomain,
                'secure' => $cookieSecure,
                'httponly' => $cookieHttpOnly,
                'samesite' => $cookieSameSite,
            ]);
        } else {
            $legacyPath = $cookiePath;
            if ($cookieSameSite !== '') {
                $legacyPath .= '; SameSite=' . $cookieSameSite;
            }
            session_set_cookie_params($cookieLifetime, $legacyPath, $cookieDomain, $cookieSecure, $cookieHttpOnly);
        }

        session_start();

        $now = time();
        $timeout = (int) ($session['inactivity_timeout'] ?? 1800);
        $lastSeen = (int) ($_SESSION['_last_seen_at'] ?? $now);
        if ($now - $lastSeen > $timeout) {
            session_unset();
            session_destroy();
            session_start();
        }
        $_SESSION['_last_seen_at'] = $now;

        if (!isset($_SESSION['_initiated'])) {
            session_regenerate_id(true);
            $_SESSION['_initiated'] = time();
        }
    }
}

taskforce_start_session();
