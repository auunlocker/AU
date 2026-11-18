<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire Admin Panel — Directory Router (/admin/index.php)
 *
 * Automatically redirects to login.php if unauthenticated, or to the
 * requested admin service page (server.php, otps.php, profiles.php,
 * signatures.php, assets.php).
 */

require_once __DIR__ . '/bootstrap.php';

$page = (string)($_GET['page'] ?? 'server');
if (!in_array($page, VALID_ADMIN_PAGES, true)) {
    $page = 'server';
}

if (!admin_is_logged_in()) {
    $query = $page !== 'server' ? ['page' => $page] : [];
    redirect(admin_url('login', $query));
}

$params = $_GET;
unset($params['page']);
redirect(admin_url($page, $params));
