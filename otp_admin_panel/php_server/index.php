<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire — Main Front Controller & API Router (/index.php)
 *
 * Routes:
 *   - Browser visits to `/` or `/index.php`    -> Serves / redirects to `tool-multi-web.php`
 *   - Visits to `/admin` or `/admin.php`       -> Redirects to `/admin/admin.php`
 *   - Visits to `/user`                        -> Redirects to `/user/index.php`
 *   - POST /otp/otp_eu.php, /otp.php, /OtpMng.php -> otp/service.php (Let's Fire OTP validate & expire)
 *   - GET  /daNewPath/qc/daaptch.json          -> daNewPath/service.php (MTK models & DA variants JSON)
 *   - GET  /daNewPath/das/<variant>.qc         -> daNewPath/service.php (Compressed .bin.qc stream)
 *   - POST /api/auth/otp                       -> api/auth.php
 *   - GET  /api/auth/me                        -> api/auth.php
 *   - POST /api/auth/refresh                   -> api/auth.php
 *   - POST /api/v1/can-sign                    -> api/sign.php
 *   - POST /api/v1/is-authorized               -> api/sign.php
 *   - POST /api/v1/sign                        -> api/sign.php
 *   - GET  /api/v1/models                      -> api/da.php
 *   - GET  /api/v1/da/<variant>                -> api/da.php
 */

$config = require __DIR__ . '/config.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/api/tokens.php';
require_once __DIR__ . '/api/auth.php';
require_once __DIR__ . '/api/sign.php';
require_once __DIR__ . '/api/da.php';
require_once __DIR__ . '/otp/service.php';
require_once __DIR__ . '/daNewPath/service.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uri    = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$script = dirname($_SERVER['SCRIPT_NAME'] ?? '');
if ($script !== '/' && $script !== '\\' && $script !== '.' && strpos($uri, $script) === 0) {
    $uri = substr($uri, strlen($script)) ?: '/';
}
$path = '/' . trim($uri, '/');
$base = rtrim(($script === '\\' || $script === '/' || $script === '.') ? '' : $script, '/');

if ($method === 'OPTIONS') {
    http_response_code(204);
    exit;
}

try {
    $routeParam = (string)($_GET['route'] ?? '');

    // Any request carrying Let's Fire OTP payload or ?route=otp goes straight to OTP handler
    if (
        $routeParam === 'otp' ||
        isset($_REQUEST['ServiceEncryptedOtpV4']) ||
        isset($_REQUEST['ServiceEncryptedDeletOtp']) ||
        in_array($path, ['/otp/otp_eu.php', '/otp/otp.php', '/otp.php', '/OtpMng.php', '/ap/s/WelCome.php'], true)
    ) {
        handle_otp_service_request($config);
    }

    if ($method === 'POST' && in_array($path, ['/', '/index.php', '/tool-multi-web.php', '/tool-multi.php'], true)) {
        $rawBodyPeek = (string)file_get_contents('php://input');
        if (str_contains($rawBodyPeek, 'ServiceEncryptedOtpV4') || str_contains($rawBodyPeek, 'ServiceEncryptedDeletOtp')) {
            handle_otp_service_request($config);
        }
    }

    if ($routeParam === 'models') {
        handle_models($config);
    }
    if ($routeParam === 'da' && !empty($_GET['file'])) {
        handle_da_asset($config, (string)$_GET['file']);
    }

    // Main website index -> tool-multi-web.php
    if ($method === 'GET' && in_array($path, ['/', '/index.php', '/tool-multi-web.php', '/tool-multi.php'], true)) {
        if (isset($_GET['health'])) {
            respond(200, ['status' => 'ok', 'service' => 'penumbra-sla']);
        }
        require __DIR__ . '/tool-multi-web.php';
        exit;
    }

    // Legacy /admin.php or /admin shortcut -> redirect into /admin/admin.php
    if ($path === '/admin.php' || $path === '/admin') {
        $qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
        header('Location: ' . $base . '/admin/admin.php' . $qs);
        exit;
    }

    // /user shortcut -> redirect into /user/index.php
    if ($path === '/user') {
        $qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
        header('Location: ' . $base . '/user/index.php' . $qs);
        exit;
    }

    // /restaurar-admin shortcut -> redirect into /restaurar-admin/restaurar-senha-sim.php
    if ($path === '/restaurar-admin' || $path === '/restaurar-senha-sim.php') {
        $qs = !empty($_SERVER['QUERY_STRING']) ? ('?' . $_SERVER['QUERY_STRING']) : '';
        header('Location: ' . $base . '/restaurar-admin/restaurar-senha-sim.php' . $qs);
        exit;
    }

    if ($method === 'GET' && $path === '/health') {
        respond(200, ['status' => 'ok', 'service' => 'penumbra-sla']);
    }

    // Penumbra API endpoints
    if ($method === 'POST' && $path === '/api/auth/otp') {
        handle_otp($config);
    }
    if ($method === 'GET' && $path === '/api/auth/me') {
        handle_me($config);
    }
    if ($method === 'POST' && $path === '/api/auth/refresh') {
        handle_refresh($config);
    }
    if ($method === 'POST' && $path === '/api/v1/can-sign') {
        handle_can_sign($config);
    }
    if ($method === 'POST' && $path === '/api/v1/is-authorized') {
        handle_is_authorized($config);
    }
    if ($method === 'POST' && $path === '/api/v1/sign') {
        handle_sign($config);
    }

    // DA Models catalog & DA binary download endpoints
    if ($method === 'GET' && ($path === '/api/v1/models' || $path === '/daNewPath/qc/daaptch.json' || $path === '/qc/daaptch.json')) {
        handle_models($config);
    }
    if ($method === 'GET' && preg_match('#^/(?:api/v1/da|daNewPath/das|das)/([A-Za-z0-9_.-]+)$#', $path, $m)) {
        handle_da_asset($config, $m[1]);
    }

    respond(404, ['error' => 'not found', 'path' => $path]);
} catch (Throwable $e) {
    respond(500, ['error' => 'internal server error']);
}
