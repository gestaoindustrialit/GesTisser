<?php
require_once __DIR__ . '/helpers.php';

if (is_logged_in()) {
    $loggedUser = current_user($pdo);
    if ($loggedUser) {
        redirect(authenticated_home_url($loggedUser));
    }

    redirect('logout.php');
}

redirect('login.php');
