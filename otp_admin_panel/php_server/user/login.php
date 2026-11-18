<?php
declare(strict_types=1);

/**
 * Let's Fire / Penumbra User Portal — Sign In (/user/login.php)
 */

require_once __DIR__ . '/bootstrap.php';

if (user_is_logged_in()) {
    redirect(user_url('dashboard'));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        flash('Session expired, please try again.', 'err');
        redirect(user_url('login'));
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    $user = user_verify_login($config, $username, $password);
    if ($user !== null) {
        if ($user['status'] !== 'active') {
            flash('Your account has been disabled by the administrator.', 'err');
            redirect(user_url('login'));
        }
        session_regenerate_id(true);
        $_SESSION['user_ok']   = true;
        $_SESSION['username']  = $user['username'];
        $_SESSION['user_at']   = time();
        $_SESSION['user_csrf'] = bin2hex(random_bytes(24));
        redirect(user_url('dashboard'));
    }

    usleep(random_int(120000, 280000));
    flash('Invalid username or password.', 'err');
    redirect(user_url('login'));
}

$flash  = take_flash();
$css    = user_css();
$webUrl = user_site_root_url() . '/tool-multi-web.php';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Sign in · Let's Fire User Portal</title>
<style><?= $css ?></style>
</head>
<body>
<div class="login-wrap">
  <div class="card login">
    <h2>Sign in · User Portal</h2>
    <?php if ($flash): ?>
      <div class="flash <?= h($flash['kind']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= h(user_url('login')) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <div class="field">
        <label for="username">Username</label>
        <input id="username" type="text" name="username" required autofocus autocomplete="username">
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" type="password" name="password" required autocomplete="current-password">
      </div>
      <button type="submit">Sign in</button>
    </form>
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid #eaeef2;display:flex;justify-content:space-between;font-size:13px;flex-wrap:wrap;gap:8px">
      <a href="<?= h(user_url('register')) ?>">Create an account</a>
      <a href="<?= h($webUrl) ?>">← Back to Website</a>
    </div>
  </div>
</div>
</body>
</html>
