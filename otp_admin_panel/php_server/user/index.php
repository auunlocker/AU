<?php
declare(strict_types=1);

/**
 * Let's Fire / Penumbra User Portal — Directory Router (/user/index.php)
 */

require_once __DIR__ . '/bootstrap.php';

if (!user_is_logged_in()) {
    redirect(user_url('login'));
}

redirect(user_url('dashboard'));
