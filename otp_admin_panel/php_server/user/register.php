<?php
declare(strict_types=1);

/**
 * Let's Fire / Penumbra User Portal — Create Account (/user/register.php)
 */

require_once __DIR__ . '/bootstrap.php';

if (user_is_logged_in()) {
    redirect(user_url('dashboard'));
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        flash('Session expired, please try again.', 'err');
        redirect(user_url('register'));
    }

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $confirm  = (string)($_POST['confirm_password'] ?? '');

    try {
        if ($password !== $confirm) {
            throw new InvalidArgumentException('Passwords do not match.');
        }
        $user = user_create($config, $username, $password, 0.0, 'default', 'Self-registered');
        session_regenerate_id(true);
        $_SESSION['user_ok']   = true;
        $_SESSION['username']  = $user['username'];
        $_SESSION['user_at']   = time();
        $_SESSION['user_csrf'] = bin2hex(random_bytes(24));
        flash('Account created! Ask the administrator to add credits to generate OTP codes.');
        redirect(user_url('dashboard'));
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
        redirect(user_url('register'));
    }
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
<title>Register · Let's Fire User Portal</title>
<style><?= $css ?></style>
</head>
<body>
<div class="login-wrap">
  <div class="card login">
    <h2>Create Account · User Portal</h2>
    <?php if ($flash): ?>
      <div class="flash <?= h($flash['kind']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>
    <form method="post" action="<?= h(user_url('register')) ?>" autocomplete="off">
      <?= csrf_field() ?>
      <div class="field">
        <label for="username">Username (3–32 characters)</label>
        <input id="username" type="text" name="username" required autofocus maxlength="32">
      </div>
      <div class="field">
        <label for="password">Password (min 4 characters)</label>
        <input id="password" type="password" name="password" required maxlength="64">
      </div>
      <div class="field">
        <label for="confirm_password">Confirm Password</label>
        <input id="confirm_password" type="password" name="confirm_password" required maxlength="64">
      </div>
      <button type="submit">Create Account</button>
    </form>
    <div style="margin-top:14px;padding-top:12px;border-top:1px solid #eaeef2;display:flex;justify-content:space-between;font-size:13px;flex-wrap:wrap;gap:8px">
      <a href="<?= h(user_url('login')) ?>">Already have an account? Sign in</a>
      <a href="<?= h($webUrl) ?>">← Back to Website</a>
    </div>
  </div>
</div>
</body>
</html>
