<?php
declare(strict_types=1);

if (defined('TASKFORCE_BOOTSTRAP_CONFIG_LOADED')) {
    return;
}
define('TASKFORCE_BOOTSTRAP_CONFIG_LOADED', true);

if (!function_exists('app_config')) {
    function app_config( $key = null, $default = null)
    {
        static $config;

        if (!is_array($config)) {
            $root = dirname(__DIR__);
            $installation = [];
            $installationFile = $root . '/storage/installation.json';
            if (is_file($installationFile)) {
                $decodedInstallation = json_decode((string) @file_get_contents($installationFile), true);
                if (is_array($decodedInstallation)) {
                    $installation = $decodedInstallation;
                }
            }
            $configuredEnvironment = getenv('APP_ENV') ?: ($installation['environment'] ?? 'production');
            $isProduction = (string) $configuredEnvironment === 'production';
            $environmentSuffix = preg_replace('/[^a-z0-9_-]/i', '_', (string) $configuredEnvironment);
            $configuredDbPath = getenv('GESTISSER_DB_PATH') ?: ($installation['database_path'] ?? ($root . '/database.sqlite'));
            $config = [
                'app_name' => getenv('APP_NAME') ?: 'GesTisser',
                'env' => $configuredEnvironment,
                'debug' => (bool) ((int) (getenv('APP_DEBUG') ?: 0)),
                'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/Lisbon',
                'db_path' => $configuredDbPath,
                'installation_uuid' => $installation['installation_uuid'] ?? null,
                'database_fingerprint' => $installation['database_fingerprint'] ?? null,
                'external_services_enabled' => isset($installation['external_services_enabled']) ? (bool) $installation['external_services_enabled'] : $isProduction,
                'cron_enabled' => isset($installation['cron_enabled']) ? (bool) $installation['cron_enabled'] : $isProduction,
                'session' => [
                    'name' => $installation['session_name'] ?? ('gestisser_' . $environmentSuffix . '_session'),
                    'lifetime' => 60 * 60 * 8,
                    'inactivity_timeout' => 60 * 30,
                    'secure' => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
                    'httponly' => true,
                    'samesite' => 'Lax',
                ],
                'security' => [
                    'install_enabled' => !$isProduction,
                    'csp' => "default-src 'self' data: blob: https:; img-src 'self' data: blob: https:; style-src 'self' 'unsafe-inline' https:; script-src 'self' 'unsafe-inline' https:; font-src 'self' data: https:; connect-src 'self' https:; frame-ancestors 'none'; base-uri 'self'",
                ],
                'paths' => [
                    'root' => $root,
                    'storage' => $root . '/storage',
                    'logs' => $installation['logs_path'] ?? ($root . '/storage/logs/' . $environmentSuffix),
                    'uploads' => $installation['uploads_path'] ?? ($root . '/storage/uploads/' . $environmentSuffix),
                    'cache' => $root . '/storage/cache',
                    'backups' => $root . '/storage/backups',
                ],
            ];

            date_default_timezone_set((string) $config['timezone']);
        }

        if ($key === null) {
            return $config;
        }

        $segments = explode('.', $key);
        $value = $config;
        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
