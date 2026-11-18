<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire — Admin Main Entry Point (/admin/admin.php)
 *
 * Routes requests inside the /admin/ directory:
 *   - /admin/login.php
 *   - /admin/server.php
 *   - /admin/otps.php
 *   - /admin/users.php
 *   - /admin/price-service.php
 *   - /admin/profiles.php
 *   - /admin/signatures.php
 *   - /admin/assets.php
 */

require_once __DIR__ . '/bootstrap.php';

if (isset($_GET['logout'])) {
    redirect(admin_url('logout', ['csrf' => (string)($_GET['csrf'] ?? '')]));
}

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
