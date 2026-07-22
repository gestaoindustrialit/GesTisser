<?php
if (!function_exists('taskforce_routes')) {
    function taskforce_routes()
    {
        return array(
            'home' => 'dashboard.php',
            'login' => 'login.php',
            'logout' => 'logout.php',
            'install' => 'install.php',
            'erp' => 'erp.php',
            'shopfloor' => 'shopfloor.php',
            'users' => 'users.php',
            'hr' => 'hr.php',
        );
    }
}

if (!function_exists('route_url')) {
    function route_url($name, $fallback = '')
    {
        $routes = taskforce_routes();
        return isset($routes[$name]) ? $routes[$name] : $fallback;
    }
}
