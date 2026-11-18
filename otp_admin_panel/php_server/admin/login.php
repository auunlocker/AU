<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire Admin Panel — Sign In Interface (/admin/login.php)
 */

require_once __DIR__ . '/bootstrap.php';

if (admin_is_logged_in()) {
    $target = (string)($_GET['page'] ?? 'server');
    if (!in_array($target, VALID_ADMIN_PAGES, true)) {
        $target = 'server';
    }
    redirect(admin_url($target));
}

[$ADMIN_HASH, $ADMIN_CONFIGURED] = admin_hash_configured($config);
$ADMIN_USER = (string)($config['admin']['user'] ?? 'admin');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!$ADMIN_CONFIGURED) {
        flash('Admin password is not configured in config.php.', 'err');
        redirect(admin_url('login'));
    }
    if (!csrf_ok()) {
        flash('Session expired, please try again.', 'err');
        redirect(admin_url('login'));
    }
    if (login_failures($config) >= LOGIN_MAX_FAIL) {
        admin_log($config, 'LOGIN_THROTTLED');
        flash('Too many failed attempts. Wait 15 minutes.', 'err');
        redirect(admin_url('login'));
    }

    $u           = trim((string)($_POST['username'] ?? ''));
    $p           = (string)($_POST['password'] ?? '');
    $matchedUser = admin_verify_login($config, $u, $p);

    if ($matchedUser !== null) {
        session_regenerate_id(true);
        $_SESSION['admin_ok']   = true;
        $_SESSION['admin_user'] = $matchedUser;
        $_SESSION['admin_at']   = time();
        $_SESSION['csrf']       = bin2hex(random_bytes(24));
        login_reset($config);
        admin_log($config, 'LOGIN_OK user=' . $matchedUser);

        $next = (string)($_POST['next'] ?? $_GET['page'] ?? 'server');
        if (!in_array($next, VALID_ADMIN_PAGES, true)) {
            $next = 'server';
        }
        redirect(admin_url($next));
    }

    login_fail($config);
    admin_log($config, 'LOGIN_FAIL user=' . $u);
    usleep(random_int(150000, 350000));
    flash('Invalid username or password.', 'err');
    redirect(admin_url('login'));
}

$flash = take_flash();
$css   = admin_css();
$next  = (string)($_GET['page'] ?? 'server');
if (!in_array($next, VALID_ADMIN_PAGES, true)) {
    $next = 'server';
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Sign in · Penumbra Admin</title>
<style><?= $css ?></style>
</head>
<body>
<div class="login-wrap">
  <div class="card login">
    <h2>Sign in · Penumbra auth</h2>
    <?php if ($flash): ?>
      <div class="flash <?= h($flash['kind']) ?>"><?= h($flash['msg']) ?></div>
    <?php endif; ?>
    <?php if (!$ADMIN_CONFIGURED): ?>
      <div class="flash err">
        Admin password hash is not set. Run
        <code>php tools/hash_password.php &lt;password&gt;</code>
        and paste the hash into <code>config.php</code> under <code>admin.pass_hash</code>.
      </div>
    <?php else: ?>
      <form method="post" action="<?= h(admin_url('login')) ?>" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="next" value="<?= h($next) ?>">
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
    <?php endif; ?>
  </div>
</div>
</body>
</html>
