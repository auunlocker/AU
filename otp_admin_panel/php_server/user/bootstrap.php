<?php
declare(strict_types=1);

/**
 * Let's Fire / Penumbra User Portal — Shared Bootstrap & Responsive Layout (/user/bootstrap.php)
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
session_name('pensla_user');
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

const VALID_USER_PAGES = ['dashboard', 'logs', 'credits'];

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function user_site_root_url(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/user/dashboard.php');
    $dir    = rtrim(dirname($script), '/');
    if (substr($dir, -5) === '/user') {
        $root = substr($dir, 0, -5);
        return $root === '' ? '' : $root;
    }
    return $dir === '/' ? '' : $dir;
}

function user_base_url(): string
{
    return user_site_root_url() . '/user';
}

function user_url(string $page = 'dashboard', array $query = []): string
{
    $base    = user_base_url();
    $allowed = array_merge(['login', 'register', 'logout'], VALID_USER_PAGES);
    $file    = in_array($page, $allowed, true) ? $page . '.php' : 'dashboard.php';
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

function flash(string $msg, string $kind = 'ok'): void
{
    $_SESSION['user_flash'] = ['msg' => $msg, 'kind' => $kind];
}

function take_flash(): ?array
{
    $f = $_SESSION['user_flash'] ?? null;
    unset($_SESSION['user_flash']);
    return $f;
}

function csrf_token(): string
{
    if (empty($_SESSION['user_csrf'])) {
        $_SESSION['user_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['user_csrf'];
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

function user_is_logged_in(): bool
{
    if (empty($_SESSION['user_ok']) || empty($_SESSION['username'])) {
        return false;
    }
    if (time() - (int)($_SESSION['user_at'] ?? 0) > 14400) {
        $_SESSION = [];
        session_destroy();
        return false;
    }
    $_SESSION['user_at'] = time();
    return true;
}

function user_require_login(array $config): array
{
    if (!user_is_logged_in()) {
        redirect(user_url('login'));
    }
    $u = user_find($config, (string)$_SESSION['username']);
    if ($u === null || $u['status'] !== 'active') {
        $_SESSION = [];
        session_destroy();
        session_start();
        flash('Account unavailable or disabled by administrator.', 'err');
        redirect(user_url('login'));
    }
    return $u;
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

function user_css(): string
{
    return <<<'CSS'
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f4f5f7;color:#1b1f24;min-height:100vh;display:flex;flex-direction:column}
a{color:#2159d6;text-decoration:none}
a:hover{text-decoration:underline}
code,.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px}

header{background:#141a26;color:#fff;padding:0 24px;display:flex;align-items:center;justify-content:space-between;min-height:54px;gap:12px;flex-wrap:wrap;position:sticky;top:0;z-index:100;box-shadow:0 1px 4px rgba(0,0,0,.18)}
header .brand{font-weight:600;letter-spacing:.2px;display:flex;align-items:center;gap:10px;white-space:nowrap}
header .dot{width:9px;height:9px;border-radius:50%;background:#2ecc71;display:inline-block;flex-shrink:0}
header .dot.off{background:#e74c3c}
nav{display:flex;gap:4px;align-items:center;flex-wrap:wrap}
nav a{color:#c7cdd8;padding:8px 12px;border-radius:6px;font-weight:500;white-space:nowrap;transition:background .15s,color .15s}
nav a.active,nav a:hover{color:#fff;background:#222b3d;text-decoration:none}
header .right{display:flex;align-items:center;gap:10px;color:#9aa3b2;font-size:13px;white-space:nowrap;flex-wrap:wrap}
header .right form{margin:0}
.credit-pill{background:#222b3d;color:#fff;padding:4px 10px;border-radius:14px;font-weight:600;font-size:12.5px;border:1px solid #303c54}
.price-pill{background:rgba(33,89,214,.22);color:#9ecbff;padding:4px 10px;border-radius:14px;font-weight:500;font-size:12px}

main{max-width:1100px;width:100%;margin:24px auto;padding:0 20px;flex:1}
.card{background:#fff;border:1px solid #e1e4e8;border-radius:8px;padding:18px 20px;margin-bottom:18px;box-shadow:0 1px 2px rgba(20,26,38,.03)}
.card h2{margin:0 0 12px;font-size:15px;font-weight:600;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.row{display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end}
.field{display:flex;flex-direction:column;gap:4px;min-width:0}
.field label{font-size:12px;color:#57606a;font-weight:500}

input[type=text],input[type=password],input[type=number],select{padding:8px 10px;border:1px solid #d0d7de;border-radius:6px;font:inherit;background:#fff;min-width:150px;max-width:100%;color:#1b1f24}
input:focus,select:focus{outline:2px solid #2159d6;outline-offset:-1px;border-color:#2159d6}
button,.btn{padding:8px 14px;border:1px solid transparent;border-radius:6px;font:500 13px/1.2 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;cursor:pointer;background:#2159d6;color:#fff;display:inline-flex;align-items:center;justify-content:center;gap:6px;white-space:nowrap;transition:background .15s}
button:hover,.btn:hover{background:#1a47b0;text-decoration:none}
button:disabled{opacity:.55;cursor:not-allowed}
button.ghost,.btn.ghost{background:#fff;color:#24292f;border-color:#d0d7de}
button.ghost:hover,.btn.ghost:hover{background:#f3f4f6}
button.good,.btn.good{background:#1a7f37;color:#fff}
button.good:hover,.btn.good:hover{background:#116329}
button.sm,.btn.sm{padding:5px 10px;font-size:12px}

.table-wrap{width:100%;overflow-x:auto;-webkit-overflow-scrolling:touch}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{text-align:left;padding:9px 10px;border-bottom:1px solid #eaeef2;vertical-align:middle}
th{font-weight:600;color:#57606a;font-size:12px;text-transform:uppercase;letter-spacing:.3px;background:#fafbfc;white-space:nowrap}
tr:last-child td{border-bottom:0}
tr:hover td{background:#fafbfc}

.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:11.5px;font-weight:600;white-space:nowrap}
.b-valid{background:#dafbe1;color:#116329}
.b-used{background:#ddf4ff;color:#0550ae}
.b-expired{background:#fff8c5;color:#7d4e00}
.b-inactive{background:#ffebe9;color:#a40e26}

.flash{padding:11px 14px;border-radius:6px;margin-bottom:16px;font-weight:500;word-break:break-word}
.flash.ok{background:#dafbe1;color:#116329;border:1px solid #aceebb}
.flash.err{background:#ffebe9;color:#a40e26;border:1px solid #ffcecb}
.flash.warn{background:#fff8c5;color:#7d4e00;border:1px solid #fae17d}

.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:12px;margin-bottom:18px}
.stat{background:#fff;border:1px solid #e1e4e8;border-radius:8px;padding:14px 16px}
.stat .num{font-size:24px;font-weight:700;line-height:1.2}
.stat .lbl{color:#57606a;font-size:12px;margin-top:2px}
.muted{color:#57606a;font-size:12.5px}
.pills{display:flex;gap:6px;flex-wrap:wrap}
.pills a{padding:5px 11px;border-radius:14px;background:#eaeef2;color:#24292f;font-size:12.5px;font-weight:500}
.pills a.active{background:#2159d6;color:#fff;text-decoration:none}

.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;padding:18px;width:100%}
.login{max-width:400px;width:100%;margin:0 auto}
.login .field{margin-bottom:12px}
.login input{width:100%}
.login button{width:100%;padding:10px}

.otp-highlight{background:#f0f7ff;border:1px solid #b6d4fe;border-radius:8px;padding:14px 16px;margin-bottom:18px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.otp-highlight .code-big{font-size:20px;font-weight:700;letter-spacing:1.2px;color:#0550ae}

@media (max-width: 900px) {
    .stats{grid-template-columns:repeat(2,1fr)}
    header{padding:10px 16px}
}
@media (max-width: 768px) {
    header{flex-direction:column;align-items:stretch;padding:12px 14px;gap:10px}
    nav{width:100%;overflow-x:auto;flex-wrap:nowrap;padding-bottom:2px;-webkit-overflow-scrolling:touch}
    nav a{padding:7px 11px;font-size:13px;flex-shrink:0}
    header .right{justify-content:space-between;width:100%;border-top:1px solid #222b3d;padding-top:8px}
    main{margin:14px auto;padding:0 12px}
    .card{padding:14px}
    .row{flex-direction:column;align-items:stretch;gap:10px}
    .row .field{width:100%}
    .row input,.row select,.row button,.row .btn{width:100%;min-width:0}
    .card h2{flex-direction:column;align-items:flex-start;gap:8px}
    th,td{padding:8px 8px;font-size:12.5px}
}
@media (max-width: 480px) {
    .stats{grid-template-columns:1fr 1fr;gap:8px}
    .stat{padding:11px 12px}
    .stat .num{font-size:20px}
    .pills{width:100%}
    .pills a{flex:1;text-align:center}
}
CSS;
}

function user_render_layout(
    array $config,
    array $user,
    string $activePage,
    string $title,
    string $contentHtml
): void {
    $enabled  = signing_enabled($config);
    $flash    = take_flash();
    $css      = user_css();
    $pricing  = pricing_load($config);
    $currency = (string)($pricing['currency_label'] ?? 'Credits');
    $otpPrice = otp_price_for_profile($config, $user['profile']);
    $webUrl   = user_site_root_url() . '/tool-multi-web.php';

    $navItems = [
        'dashboard' => 'OTP Generator',
        'logs'      => 'App Service Logs',
        'credits'   => 'Credits & Account',
    ];

    echo "<!doctype html>\n<html lang=\"en\">\n<head>\n";
    echo '<meta charset="utf-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">' . "\n";
    echo '<title>' . h($title) . ' · Let\'s Fire User Portal</title>' . "\n";
    echo '<style>' . $css . '</style>' . "\n";
    echo "</head>\n<body>\n";

    echo "<header>\n";
    echo '  <div class="brand"><span class="dot ' . ($enabled ? '' : 'off') . '"></span>Let\'s Fire · User Portal</div>' . "\n";
    echo "  <nav>\n";
    foreach ($navItems as $slug => $label) {
        $cls = ($activePage === $slug) ? 'active' : '';
        echo '    <a class="' . $cls . '" href="' . h(user_url($slug)) . '">' . h($label) . "</a>\n";
    }
    echo "  </nav>\n";
    echo '  <div class="right">' . "\n";
    echo '    <span class="price-pill">OTP Price: ' . number_format($otpPrice, 2) . ' ' . h($currency) . '</span>' . "\n";
    echo '    <span class="credit-pill">' . number_format((float)$user['credits'], 2) . ' ' . h($currency) . '</span>' . "\n";
    echo '    <a class="btn ghost sm" href="' . h($webUrl) . '">Site</a>' . "\n";
    echo '    <span>' . h($user['username']) . '</span>' . "\n";
    echo '    <form method="post" action="' . h(user_url('logout')) . '">' . csrf_field() . '<button class="ghost sm" type="submit">Sign out</button></form>' . "\n";
    echo "  </div>\n";
    echo "</header>\n";

    echo "<main>\n";
    if ($flash !== null) {
        echo '  <div class="flash ' . h($flash['kind']) . '">' . h($flash['msg']) . "</div>\n";
    }
    echo $contentHtml;
    echo "</main>\n</body>\n</html>\n";
}

if (basename((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === 'bootstrap.php') {
    redirect(user_url('dashboard'));
}
