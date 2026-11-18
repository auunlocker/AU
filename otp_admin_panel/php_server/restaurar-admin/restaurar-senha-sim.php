<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire — Interface para Restaurar / Alterar Nome de Admin e Senha
 * Path: /restaurar-admin/restaurar-senha-sim.php
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

function rst_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function rst_root_url(): string
{
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/restaurar-admin/restaurar-senha-sim.php');
    $dir    = rtrim(dirname($script), '/');
    if (substr($dir, -16) === '/restaurar-admin') {
        $root = substr($dir, 0, -16);
        return $root === '' ? '' : $root;
    }
    return $dir === '/' ? '' : $dir;
}

if (empty($_SESSION['csrf_restore'])) {
    $_SESSION['csrf_restore'] = bin2hex(random_bytes(24));
}

$rootUrl      = rst_root_url();
$selfUrl      = $rootUrl . '/restaurar-admin/restaurar-senha-sim.php';
$adminLogin   = $rootUrl . '/admin/login.php';
$adminPanel   = $rootUrl . '/admin/server.php';
$flashMsg     = null;
$flashKind    = 'ok';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $sentCsrf = (string)($_POST['csrf'] ?? '');
    if ($sentCsrf === '' || !hash_equals((string)$_SESSION['csrf_restore'], $sentCsrf)) {
        $flashMsg  = 'Sessão expirada. Tente novamente.';
        $flashKind = 'err';
    } else {
        $newUsername     = trim((string)($_POST['new_username'] ?? ''));
        $newPassword     = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');

        if ($newUsername === '' || $newPassword === '') {
            $flashMsg  = 'Preencha o novo nome de Admin e a nova senha.';
            $flashKind = 'err';
        } elseif ($newPassword !== $confirmPassword) {
            $flashMsg  = 'A confirmação de senha não coincide com a nova senha.';
            $flashKind = 'err';
        } else {
            try {
                $updated = admin_update_credentials($config, $newUsername, $newPassword);

                // Reset any login rate-limit lockouts so the admin can sign in immediately
                $ip  = client_ip();
                $tFile = store_state($config) . '/throttle/admin_' . hash('sha256', $ip) . '.json';
                if (is_file($tFile)) {
                    @unlink($tFile);
                }

                // If an admin session is currently active, update its username
                if (!empty($_SESSION['admin_ok'])) {
                    $_SESSION['admin_user'] = $updated['username'];
                }

                $line = sprintf(
                    "[%s] ip=%s ADMIN_CREDENTIALS_RESTORED new_user=%s\n",
                    gmdate('Y-m-d\TH:i:s\Z'),
                    $ip,
                    $updated['username']
                );
                @file_put_contents(store_log_dir($config) . '/admin.log', $line, FILE_APPEND | LOCK_EX);

                $flashMsg  = 'Credenciais de Admin atualizadas com sucesso! Novo usuário: "' . $updated['username'] . '".';
                $flashKind = 'ok';
            } catch (Throwable $e) {
                $flashMsg  = 'Erro ao atualizar credenciais: ' . $e->getMessage();
                $flashKind = 'err';
            }
        }
    }
}

$currentAdmin = admin_current_account($config);
$enabled      = signing_enabled($config);
$storeMode    = use_mysql($config) ? 'MySQL (' . ($config['db']['name'] ?? 'db') . ')' : 'File store';
?>
<!doctype html>
<html lang="pt">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Restaurar Nome e Senha de Admin · Penumbra Admin</title>
<style>
*{box-sizing:border-box}
html{-webkit-text-size-adjust:100%}
body{margin:0;font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f4f5f7;color:#1b1f24;min-height:100vh;display:flex;flex-direction:column}
a{color:#2159d6;text-decoration:none}
a:hover{text-decoration:underline}
code,.mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12.5px}

/* Fixed / Sticky Top Navigation Bar */
header{background:#141a26;color:#fff;padding:0 24px;display:flex;align-items:center;justify-content:space-between;min-height:54px;gap:12px;flex-wrap:wrap;position:sticky;top:0;z-index:100;box-shadow:0 1px 4px rgba(0,0,0,.18)}
header .brand{font-weight:600;letter-spacing:.2px;display:flex;align-items:center;gap:10px;white-space:nowrap}
header .dot{width:9px;height:9px;border-radius:50%;background:#2ecc71;display:inline-block;flex-shrink:0}
header .dot.off{background:#e74c3c}
header .right{display:flex;align-items:center;gap:10px;color:#9aa3b2;font-size:13px;white-space:nowrap;flex-wrap:wrap}

main{max-width:560px;width:100%;margin:36px auto;padding:0 18px;flex:1}
.card{background:#fff;border:1px solid #e1e4e8;border-radius:8px;padding:22px 24px;margin-bottom:18px;box-shadow:0 1px 3px rgba(20,26,38,.05)}
.card h2{margin:0 0 6px;font-size:17px;font-weight:600;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.muted{color:#57606a;font-size:12.5px}

.info-box{background:#f6f8fa;border:1px solid #d0d7de;border-radius:6px;padding:12px 14px;margin:14px 0 18px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.badge{display:inline-block;padding:3px 9px;border-radius:12px;font-size:11.5px;font-weight:600;background:#dafbe1;color:#116329}

.field{display:flex;flex-direction:column;gap:5px;margin-bottom:14px}
.field label{font-size:12.5px;color:#24292f;font-weight:600}
.pass-wrap{position:relative;display:flex;align-items:center}
.pass-wrap input{width:100%;padding-right:70px}
.pass-toggle{position:absolute;right:6px;padding:4px 8px;font-size:11.5px;background:#eaeef2;color:#24292f;border:1px solid #d0d7de;border-radius:4px;cursor:pointer}
.pass-toggle:hover{background:#d8dee4}

input[type=text],input[type=password]{padding:9px 11px;border:1px solid #d0d7de;border-radius:6px;font:inherit;background:#fff;width:100%;color:#1b1f24}
input:focus{outline:2px solid #2159d6;outline-offset:-1px;border-color:#2159d6}

button,.btn{padding:9px 15px;border:1px solid transparent;border-radius:6px;font:600 13.5px/1.2 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;cursor:pointer;background:#2159d6;color:#fff;display:inline-flex;align-items:center;justify-content:center;gap:6px;white-space:nowrap;transition:background .15s}
button:hover,.btn:hover{background:#1a47b0;text-decoration:none}
.btn.good,button.good{background:#1a7f37;color:#fff}
.btn.good:hover,button.good:hover{background:#116329}
.btn.ghost{background:#fff;color:#24292f;border-color:#d0d7de}
.btn.ghost:hover{background:#f3f4f6}
.btn.sm{padding:6px 11px;font-size:12.5px;font-weight:500}

.flash{padding:12px 14px;border-radius:6px;margin-bottom:16px;font-weight:500;word-break:break-word}
.flash.ok{background:#dafbe1;color:#116329;border:1px solid #aceebb}
.flash.err{background:#ffebe9;color:#a40e26;border:1px solid #ffcecb}

.actions-row{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-top:18px;padding-top:14px;border-top:1px solid #eaeef2}

@media (max-width: 600px) {
    header{padding:10px 14px}
    main{margin:18px auto;padding:0 12px}
    .card{padding:16px}
    .actions-row .btn,.actions-row button{width:100%}
}
</style>
</head>
<body>

<header>
  <div class="brand">
    <span class="dot <?= $enabled ? '' : 'off' ?>"></span>
    <span>Penumbra Admin · Restaurar Credenciais</span>
  </div>
  <div class="right">
    <span><?= rst_h($storeMode) ?></span>
    <a class="btn ghost sm" href="<?= rst_h($adminLogin) ?>">Login Admin</a>
    <a class="btn sm" href="<?= rst_h($adminPanel) ?>">Painel Admin</a>
  </div>
</header>

<main>
  <div class="card">
    <h2>
      <span>Restaurar / Alterar Admin e Senha</span>
      <span class="badge">Superadmin</span>
    </h2>
    <div class="muted">
      Altere o nome de usuário do administrador e a senha de acesso ao painel <code>/admin/</code> no banco de dados MySQL e no servidor.
    </div>

    <div class="info-box">
      <div>
        <div class="muted">Usuário Admin Atual:</div>
        <strong class="mono" style="font-size:14px"><?= rst_h((string)$currentAdmin['username']) ?></strong>
      </div>
      <div style="text-align:right">
        <div class="muted">Último Acesso / Atualização:</div>
        <span class="mono" style="font-size:12px"><?= rst_h((string)($currentAdmin['last_login_at'] ?: 'Padrão')) ?></span>
      </div>
    </div>

    <?php if ($flashMsg !== null): ?>
      <div class="flash <?= rst_h($flashKind) ?>">
        <?= rst_h($flashMsg) ?>
        <?php if ($flashKind === 'ok'): ?>
          <div style="margin-top:10px">
            <a class="btn good sm" href="<?= rst_h($adminLogin) ?>">Ir para Login do Admin &rarr;</a>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <form method="post" action="<?= rst_h($selfUrl) ?>" autocomplete="off">
      <input type="hidden" name="csrf" value="<?= rst_h((string)$_SESSION['csrf_restore']) ?>">

      <div class="field">
        <label for="new_username">Novo Nome de Usuário Admin</label>
        <input
          id="new_username"
          type="text"
          name="new_username"
          value="<?= rst_h((string)$currentAdmin['username']) ?>"
          placeholder="Ex: admin"
          required
          maxlength="64"
        >
      </div>

      <div class="field">
        <label for="new_password">Nova Senha de Admin</label>
        <div class="pass-wrap">
          <input
            id="new_password"
            type="password"
            name="new_password"
            placeholder="Digite a nova senha"
            required
            minlength="4"
          >
          <button type="button" class="pass-toggle" onclick="togglePass('new_password', this)">Mostrar</button>
        </div>
      </div>

      <div class="field">
        <label for="confirm_password">Confirmar Nova Senha</label>
        <div class="pass-wrap">
          <input
            id="confirm_password"
            type="password"
            name="confirm_password"
            placeholder="Repita a nova senha"
            required
            minlength="4"
          >
          <button type="button" class="pass-toggle" onclick="togglePass('confirm_password', this)">Mostrar</button>
        </div>
      </div>

      <button type="submit" class="good" style="width:100%;padding:11px">
        Salvar Novo Nome de Admin e Senha
      </button>

      <div class="actions-row">
        <a class="btn ghost sm" href="<?= rst_h($adminLogin) ?>">&larr; Voltar para Login Admin</a>
        <a class="btn ghost sm" href="<?= rst_h($rootUrl . '/tool-multi-web.php') ?>">Ir para o Site Principal</a>
      </div>
    </form>
  </div>
</main>

<script>
function togglePass(id, btn) {
  var el = document.getElementById(id);
  if (!el) return;
  if (el.type === 'password') {
    el.type = 'text';
    btn.textContent = 'Ocultar';
  } else {
    el.type = 'password';
    btn.textContent = 'Mostrar';
  }
}
</script>
</body>
</html>
