<?php
declare(strict_types=1);

if (defined('TASKFORCE_BOOTSTRAP_HELPERS_LOADED')) {
    return;
}
define('TASKFORCE_BOOTSTRAP_HELPERS_LOADED', true);

require_once dirname(__DIR__) . '/app/Http/request.php';
require_once dirname(__DIR__) . '/app/Http/response.php';
require_once dirname(__DIR__) . '/app/Http/csrf.php';

if (!function_exists('e')) {
    function e( $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('h')) {
    function h( $value): string
    {
        return e($value);
    }
}

if (!function_exists('flash')) {
    function flash(string $key, $message = null)
    {
        if ($message !== null) {
            $_SESSION['_flash'][$key] = $message;
            return null;
        }

        if (!isset($_SESSION['_flash'][$key])) {
            return null;
        }

        $value = (string) $_SESSION['_flash'][$key];
        unset($_SESSION['_flash'][$key]);

        return $value;
    }
}

if (!function_exists('old')) {
    function old(string $key, string $default = ''): string
    {
        return (string) ($_POST[$key] ?? $default);
    }
}

if (!function_exists('app_setting')) {
    function app_setting(PDO $pdo, string $settingKey, $default = null)
    {
        $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ?');
        $stmt->execute([$settingKey]);
        $value = $stmt->fetchColumn();

        return $value !== false ? (string) $value : $default;
    }
}

if (!function_exists('set_app_setting')) {
    function set_app_setting(PDO $pdo, string $settingKey, string $settingValue)
    {
        $updateStmt = $pdo->prepare('UPDATE app_settings SET setting_value = ? WHERE setting_key = ?');
        $updateStmt->execute([$settingValue, $settingKey]);

        if ($updateStmt->rowCount() > 0) {
            return;
        }

        $insertStmt = $pdo->prepare('INSERT INTO app_settings(setting_key, setting_value) VALUES (?, ?)');
        try {
            $insertStmt->execute([$settingKey, $settingValue]);
        } catch (PDOException $exception) {
            $updateStmt->execute([$settingValue, $settingKey]);
        }
    }
}
