<?php
declare(strict_types=1);

if (!function_exists('post')) {
    function post(string $key, $default = null)
    {
        return $_POST[$key] ?? $default;
    }
}

if (!function_exists('get')) {
    function get(string $key, $default = null)
    {
        return $_GET[$key] ?? $default;
    }
}
