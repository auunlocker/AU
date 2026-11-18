<?php
declare(strict_types=1);

/**
 * Router script for PHP's built-in development server:
 *   php -S 127.0.0.1:8080 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// Block direct access to internal directories
if (preg_match('#^/(?:state|logs|tools|assets)(?:/|$)#', $uri)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

// Main site root or /tool-multi-web.php
if (in_array($uri, ['/', '/index.php', '/tool-multi-web.php', '/tool-multi.php'], true)) {
    require __DIR__ . '/tool-multi-web.php';
    return true;
}

// Route /admin, /admin/, or legacy /admin.php to /admin/admin.php
if ($uri === '/admin' || $uri === '/admin/' || $uri === '/admin.php') {
    require __DIR__ . '/admin/admin.php';
    return true;
}

// Route /admin/<page>.php directly if the PHP file exists in /admin/
if (preg_match('#^/admin/([a-z0-9_-]+)\.php$#i', $uri, $m)) {
    $adminFile = __DIR__ . '/admin/' . $m[1] . '.php';
    if (is_file($adminFile)) {
        require $adminFile;
        return true;
    }
}

// Route /user or /user/ to /user/index.php
if ($uri === '/user' || $uri === '/user/') {
    require __DIR__ . '/user/index.php';
    return true;
}

// Route /user/<page>.php directly if the PHP file exists in /user/
if (preg_match('#^/user/([a-z0-9_-]+)\.php$#i', $uri, $m)) {
    $userFile = __DIR__ . '/user/' . $m[1] . '.php';
    if (is_file($userFile)) {
        require $userFile;
        return true;
    }
}

// Route /otp/*.php directly if file exists
if (preg_match('#^/otp/([a-z0-9_]+)\.php$#i', $uri, $m)) {
    $otpFile = __DIR__ . '/otp/' . $m[1] . '.php';
    if (is_file($otpFile)) {
        require $otpFile;
        return true;
    }
}

// Route /daNewPath/qc/daaptch.json
if ($uri === '/daNewPath/qc/daaptch.json' || $uri === '/qc/daaptch.json') {
    require __DIR__ . '/daNewPath/qc/daaptch.json.php';
    return true;
}

// Route /daNewPath/das/<variant>.qc
if (preg_match('#^/(?:daNewPath/das|das)/([A-Za-z0-9_.-]+)$#', $uri, $m)) {
    $_GET['file'] = $m[1];
    require __DIR__ . '/daNewPath/das/download.php';
    return true;
}

require __DIR__ . '/index.php';
return true;
