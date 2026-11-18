<?php
declare(strict_types=1);

/**
 * Let's Fire / Penumbra User Portal — Sign Out (/user/logout.php)
 */

require_once __DIR__ . '/bootstrap.php';

if (user_is_logged_in() && csrf_ok()) {
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
}

redirect(user_url('login'));
