<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire Admin Panel — Shared Bootstrap & Responsive Layout
 *
 * Provides session management, CSRF protection, login rate-limiting,
 * flash messages, URL helpers, and the responsive HTML layout used by
 * all pages under /admin/ (admin.php, login.php, server.php, otps.php,
 * users.php, price-service.php, profiles.php, signatures.php, assets.php).
 */

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/store.php';

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store, no-cache, must-revalidate');

ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Strict');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
session_name('pensla_admin');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const LOGIN_WINDOW   = 900; // 15 min
const LOGIN_MAX_FAIL = 10;

const VALID_ADMIN_PAGES = [
    'server',
    'otps',
    'users',
    'price-service',
    'profiles',
    'signatures',
    'assets',
];

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Compute the web root path (parent of /admin).
 */
function site_root_url(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/admin/server.php');
    $dir    = rtrim(dirname($script), '/');
    if (substr($dir, -6) === '/admin') {
        $root = substr($dir, 0, -6);
        return $root === '' ? '' : $root;
    }
    return $dir === '/' ? '' : $dir;
}

/**
 * Compute the base web path to the /admin directory.
 */
function admin_base_url(): string
{
    return site_root_url() . '/admin';
}

/**
 * Build a URL to a specific admin page (e.g. 'server', 'otps', 'users', 'price-service', 'login', 'logout')
 * with optional query parameters.
 */
function admin_url(string $page = 'server', array $query = []): string
{
    $base    = admin_base_url();
    $clean   = preg_replace('/\.php$/i', '', trim($page)) ?? 'server';
    $allowed = array_merge(['login', 'logout', 'admin'], VALID_ADMIN_PAGES);
    $file    = in_array($clean, $allowed, true) ? $clean . '.php' : 'server.php';
    $url     = $base . '/' . $file;
    if (!empty($query)) {
        $url .= '?' . http_build_query($query);
    }
    return $url;
}

function redirect(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function admin_log(array $config, string $event): void
{
    $ip   = client_ip();
    $user = $_SESSION['admin_user'] ?? '-';
    $line = sprintf("[%s] ip=%s admin=%s %s\n", gmdate('Y-m-d\TH:i:s\Z'), $ip, $user, $event);
    @file_put_contents(store_log_dir($config) . '/admin.log', $line, FILE_APPEND | LOCK_EX);
}

function log_tail(string $file, int $lines = 60): array
{
    if (!is_file($file)) {
        return [];
    }
    $data = @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($data)) {
        return [];
    }
    return array_reverse(array_slice($data, -$lines));
}

function flash(string $msg, string $kind = 'ok'): void
{
    $_SESSION['flash'] = ['msg' => $msg, 'kind' => $kind];
}

function take_flash(): ?array
{
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_ok(): bool
{
    $sent = (string)($_POST['csrf'] ?? $_GET['csrf'] ?? '');
    return $sent !== '' && hash_equals(csrf_token(), $sent);
}

function login_file(array $config): string
{
    $ip  = client_ip();
    $dir = store_state($config) . '/throttle';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return $dir . '/admin_' . hash('sha256', $ip) . '.json';
}

function login_failures(array $config): int
{
    $file = login_file($config);
    if (!is_file($file)) {
        return 0;
    }
    $raw   = (string)@file_get_contents($file);
    $items = json_decode($raw, true);
    if (!is_array($items)) {
        return 0;
    }
    $cut = time() - LOGIN_WINDOW;
    return count(array_filter($items, static fn($t) => is_int($t) && $t >= $cut));
}

function login_fail(array $config): void
{
    $file  = login_file($config);
    $raw   = is_file($file) ? (string)@file_get_contents($file) : '[]';
    $items = json_decode($raw, true);
    if (!is_array($items)) {
        $items = [];
    }
    $cut   = time() - LOGIN_WINDOW;
    $items = array_values(array_filter($items, static fn($t) => is_int($t) && $t >= $cut));
    $items[] = time();
    @file_put_contents($file, json_encode($items), LOCK_EX);
}

function login_reset(array $config): void
{
    @unlink(login_file($config));
}

function admin_hash_configured(array $config): array
{
    $hash = (string)($config['admin']['password_hash'] ?? $config['admin']['pass_hash'] ?? $config['admin']['password'] ?? '');
    $ok   = $hash !== '' && $hash !== 'REPLACE_WITH_BCRYPT_HASH';
    return [$hash, $ok];
}

function admin_is_logged_in(): bool
{
    if (empty($_SESSION['admin_ok'])) {
        return false;
    }
    if (time() - (int)($_SESSION['admin_at'] ?? 0) > 14400) {
        $_SESSION = [];
        session_destroy();
        return false;
    }
    $_SESSION['admin_at'] = time();
    return true;
}

function admin_require_login(): void
{
    if (!admin_is_logged_in()) {
        if (!empty($_SESSION['admin_at'])) {
            session_start();
            flash('Session expired. Please sign in again.', 'err');
        }
        redirect(admin_url('login'));
    }
}

function store_label(array $config): string
{
    if (mysql_configured($config)) {
        return use_mysql($config)
            ? 'MySQL (' . ($config['db']['user'] ?? 'db') . ')'
            : 'File store (MySQL fallback)';
    }
    return 'File store';
}

function when(?string $iso): string
{
    if (!$iso) {
        return '—';
    }
    $t = strtotime($iso);
    if (!$t) {
        return h($iso);
    }
    $diff = time() - $t;
    if ($diff >= 0 && $diff < 60) {
        return $diff . 's ago';
    }
    if ($diff >= 60 && $diff < 3600) {
        return intdiv($diff, 60) . 'm ago';
    }
    if ($diff >= 3600 && $diff < 86400) {
        return intdiv($diff, 3600) . 'h ago';
    }
    return gmdate('Y-m-d H:i', $t) . ' UTC';
}

/**
 * Shared CSS preserving the exact original admin.php look and feel while adding
 * full responsive adaptation for phones, tablets, and desktop screens.
 */
function admin_css(): string
{
    return <<<'CSS'
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f4f5f7;color:#1b1f24;min-height:100vh;display:flex;flex-direction:column}
a{color:#2159d6;text-decoration:none}
a:hover{text-decoration:underline}
code,.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px}

/* Top navigation bar */
header{background:#141a26;color:#fff;padding:0 24px;display:flex;align-items:center;justify-content:space-between;min-height:54px;gap:12px;flex-wrap:wrap;position:sticky;top:0;z-index:100;box-shadow:0 1px 4px rgba(0,0,0,.18)}
header .brand{font-weight:600;letter-spacing:.2px;display:flex;align-items:center;gap:10px;white-space:nowrap}
header .brand a{color:#fff;text-decoration:none;display:flex;align-items:center;gap:10px}
header .dot{width:9px;height:9px;border-radius:50%;background:#2ecc71;display:inline-block;flex-shrink:0}
header .dot.off{background:#e74c3c}
nav{display:flex;gap:4px;align-items:center;flex-wrap:wrap}
nav a{color:#c7cdd8;padding:8px 11px;border-radius:6px;font-weight:500;white-space:nowrap;font-size:13.5px;transition:background .15s,color .15s}
nav a.active,nav a:hover{color:#fff;background:#222b3d;text-decoration:none}
header .right{display:flex;align-items:center;gap:10px;color:#9aa3b2;font-size:13px;white-space:nowrap}
header .right form{margin:0}

/* Main container */
main{max-width:1140px;width:100%;margin:24px auto;padding:0 20px;flex:1}

/* Cards & layout */
.card{background:#fff;border:1px solid #e1e4e8;border-radius:8px;padding:18px 20px;margin-bottom:18px;box-shadow:0 1px 2px rgba(20,26,38,.03)}
.card h2{margin:0 0 12px;font-size:15px;font-weight:600;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.row{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end}
.field{display:flex;flex-direction:column;gap:4px;min-width:0}
.field label{font-size:12px;color:#57606a;font-weight:500}

/* Inputs & buttons */
input[type=text],input[type=password],input[type=number],input[type=file],select,textarea{padding:8px 10px;border:1px solid #d0d7de;border-radius:6px;font:inherit;background:#fff;min-width:140px;max-width:100%;color:#1b1f24}
textarea{width:100%;resize:vertical;line-height:1.45}
input:focus,select:focus,textarea:focus{outline:2px solid #2159d6;outline-offset:-1px;border-color:#2159d6}
button,.btn{padding:8px 14px;border:1px solid transparent;border-radius:6px;font:500 13px/1.2 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;cursor:pointer;background:#2159d6;color:#fff;display:inline-flex;align-items:center;justify-content:center;gap:6px;white-space:nowrap;transition:background .15s,border-color .15s}
button:hover,.btn:hover{background:#1a47b0;text-decoration:none}
button.ghost,.btn.ghost{background:#fff;color:#24292f;border-color:#d0d7de}
button.ghost:hover,.btn.ghost:hover{background:#f3f4f6}
button.danger,.btn.danger{background:#cf222e;color:#fff}
button.danger:hover,.btn.danger:hover{background:#a40e26}
button.good,.btn.good{background:#1a7f37;color:#fff}
button.good:hover,.btn.good:hover{background:#116329}
button.sm,.btn.sm{padding:5px 10px;font-size:12px}

/* Tables */
.table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
.table-scroll{max-height:460px;overflow-y:auto;border:1px solid #eaeef2;border-radius:6px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{text-align:left;padding:9px 10px;border-bottom:1px solid #eaeef2;vertical-align:middle}
th{font-weight:600;color:#57606a;font-size:12px;text-transform:uppercase;letter-spacing:.3px;background:#fafbfc;white-space:nowrap;position:sticky;top:0;z-index:2}
tr:last-child td{border-bottom:0}
tr:hover td{background:#fafbfc}

/* Badges */
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11.5px;font-weight:600;white-space:nowrap}
.b-valid,.b-active{background:#dafbe1;color:#116329}
.b-used{background:#ddf4ff;color:#0550ae}
.b-expired{background:#fff8c5;color:#7d4e00}
.b-inactive,.b-disabled{background:#ffebe9;color:#a40e26}

/* Flash messages */
.flash{padding:11px 14px;border-radius:6px;margin-bottom:16px;font-weight:500;word-break:break-word}
.flash.ok{background:#dafbe1;color:#116329;border:1px solid #aceebb}
.flash.err{background:#ffebe9;color:#a40e26;border:1px solid #ffcecb}

/* Stats grid */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.stat{background:#fff;border:1px solid #e1e4e8;border-radius:8px;padding:14px 16px}
.stat .num{font-size:24px;font-weight:700;line-height:1.2}
.stat .lbl{color:#57606a;font-size:12px;margin-top:2px}

/* Logs & Switch */
pre.log{background:#0d1117;color:#c9d1d9;padding:12px 14px;border-radius:6px;font-size:12px;max-height:360px;overflow:auto;margin:0;line-height:1.55}
.switch-bar{display:flex;align-items:center;justify-content:space-between;gap:16px;flex-wrap:wrap}
.switch-state{display:flex;align-items:center;gap:12px}
.switch-state .big{font-size:16px;font-weight:600}
.muted{color:#57606a;font-size:12.5px}
.checks{display:flex;gap:14px;flex-wrap:wrap;padding:6px 0}
.checks label{display:flex;align-items:center;gap:6px;font-size:13px;cursor:pointer}
.pills{display:flex;gap:6px;flex-wrap:wrap}
.pills a{padding:5px 11px;border-radius:14px;background:#eaeef2;color:#24292f;font-size:12.5px;font-weight:500}
.pills a.active{background:#2159d6;color:#fff;text-decoration:none}

/* Login box */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:18px;width:100%}
.login{max-width:380px;width:100%;margin:0 auto}
.login .field{margin-bottom:12px}
.login input{width:100%}
.login button{width:100%;padding:10px}
.right-actions{display:flex;gap:6px;justify-content:flex-end;flex-wrap:wrap}

/* Endpoints grid */
.endpoint-list{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:10px}
.endpoint-item{border:1px solid #eaeef2;border-radius:6px;padding:10px 12px;background:#fafbfc}
.endpoint-item .method{font-size:11px;font-weight:700;padding:2px 6px;border-radius:4px;background:#ddf4ff;color:#0550ae;margin-right:6px}
.endpoint-item .method.post{background:#dafbe1;color:#116329}

/* Grid helpers */
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px}

/* Responsive Tablet & Mobile Adaptation */
@media (max-width: 960px) {
    .stats{grid-template-columns:repeat(2,1fr)}
    .grid-2{grid-template-columns:1fr}
    header{padding:10px 16px}
}
@media (max-width: 768px) {
    header{flex-direction:column;align-items:stretch;padding:12px 14px;gap:10px}
    nav{width:100%;overflow-x:auto;flex-wrap:nowrap;padding-bottom:2px;-webkit-overflow-scrolling:touch}
    nav a{padding:7px 11px;font-size:13px;flex-shrink:0}
    header .right{justify-content:space-between;width:100%;border-top:1px solid #222b3d;padding-top:8px;flex-wrap:wrap}
    main{margin:14px auto;padding:0 12px}
    .card{padding:14px}
    .row{flex-direction:column;align-items:stretch;gap:10px}
    .row .field{width:100%}
    .row input[type=text],.row input[type=password],.row input[type=number],.row select{width:100%;min-width:0}
    .row button,.row .btn{width:100%;padding:10px 14px}
    .switch-bar{flex-direction:column;align-items:stretch}
    .switch-bar form,.switch-bar button{width:100%}
    .card h2{flex-direction:column;align-items:flex-start;gap:8px}
    th,td{padding:8px 8px;font-size:12.5px}
}
@media (max-width: 480px) {
    .stats{grid-template-columns:1fr 1fr;gap:8px}
    .stat{padding:11px 12px}
    .stat .num{font-size:20px}
    .pills{width:100%}
    .pills a{flex:1;text-align:center;min-width:60px}
    .checks{flex-direction:column;gap:8px}
}
CSS;
}

/**
 * Render the complete authenticated admin page shell.
 */
function admin_render_layout(
    array $config,
    string $activePage,
    string $title,
    string $contentHtml,
    ?string $dbError = null
): void {
    $enabled = signing_enabled($config);
    $flash   = take_flash();
    $css     = admin_css();
    $store   = store_label($config);
    $user    = (string)($_SESSION['admin_user'] ?? 'admin');
    $webUrl  = site_root_url() . '/tool-multi-web.php';

    $navItems = [
        'server'        => 'Server',
        'otps'          => 'OTP codes',
        'users'         => 'Users & Credits',
        'price-service' => 'Price Service',
        'profiles'      => 'Permissions',
        'signatures'    => 'Signatures',
        'assets'        => 'DA assets',
    ];

    echo "<!doctype html>\n<html lang=\"en\">\n<head>\n";
    echo '<meta charset="utf-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
    echo '<title>' . h($title) . ' · Penumbra Admin</title>' . "\n";
    echo '<style>' . $css . '</style>' . "\n";
    echo "</head>\n<body>\n";

    echo "<header>\n";
    echo '  <div class="brand"><span class="dot ' . ($enabled ? '' : 'off') . '"></span>Penumbra Admin</div>' . "\n";
    echo "  <nav>\n";
    foreach ($navItems as $slug => $label) {
        $cls = ($activePage === $slug) ? 'active' : '';
        echo '    <a class="' . $cls . '" href="' . h(admin_url($slug)) . '">' . h($label) . "</a>\n";
    }
    echo "  </nav>\n";
    echo '  <div class="right">' . "\n";
    echo '    <a class="btn ghost sm" href="' . h($webUrl) . '">Site</a>' . "\n";
    echo '    <span class="muted" style="color:#9aa3b2">' . h($store) . '</span>' . "\n";
    echo '    <span>' . h($user) . '</span>' . "\n";
    echo '    <form method="post" action="' . h(admin_url('logout')) . '">' . csrf_field() . '<button class="ghost sm" type="submit">Sign out</button></form>' . "\n";
    echo "  </div>\n";
    echo "</header>\n";

    echo "<main>\n";
    if ($flash !== null) {
        echo '  <div class="flash ' . h($flash['kind']) . '">' . h($flash['msg']) . "</div>\n";
    }
    if ($dbError !== null) {
        echo '  <div class="flash err">Database error: ' . h($dbError) . "</div>\n";
    }
    echo $contentHtml;
    echo "</main>\n</body>\n</html>\n";
}

if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'bootstrap.php') {
    redirect(admin_url('server'));
}
