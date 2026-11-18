<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire Admin Panel — Sign Out Handler (/admin/logout.php)
 */

require_once __DIR__ . '/bootstrap.php';

if (admin_is_logged_in() && csrf_ok()) {
    admin_log($config, 'LOGOUT');
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

redirect(admin_url('login'));
