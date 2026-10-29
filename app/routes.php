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
            'hr.organogram' => 'hr_organogram.php',
            'hr.job_descriptions' => 'hr_job_descriptions.php',
            'hr.skills' => 'hr_skills.php',
            'erp.machines' => 'erp_machines.php',
            'erp.bi' => 'business_intelligence.php',
            'assist.admin' => 'assist_admin.php',
            'assist.api' => 'assist_api.php',
            'assist.article' => 'assist_article.php',
            'assist.template' => 'assist_template.php',
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
