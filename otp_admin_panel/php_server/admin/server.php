<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire Admin Panel — Server Status & Activity (/admin/server.php)
 */

require_once __DIR__ . '/bootstrap.php';
admin_require_login();

$dbError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        flash('Invalid CSRF token.', 'err');
        redirect(admin_url('server'));
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'toggle_server') {
            $enable = ($_POST['enable'] ?? '0') === '1';
            set_signing_enabled($config, $enable);
            admin_log($config, 'SERVER_' . ($enable ? 'ENABLED' : 'DISABLED'));
            flash('Signing server is now ' . ($enable ? 'ENABLED' : 'DISABLED') . '.');
        }
    } catch (Throwable $e) {
        flash('Error: ' . $e->getMessage(), 'err');
    }
    redirect(admin_url('server'));
}

try {
    $enabled = signing_enabled($config);
    $counts  = otp_counts($config);
    $authLog = log_tail(store_log_dir($config) . '/otp_auth.log', 60);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    $enabled = signing_enabled($config);
    $counts  = ['total' => 0, 'valid' => 0, 'used' => 0, 'expired' => 0, 'inactive' => 0];
    $authLog = [];
}

ob_start();
?>
<div class="card">
  <div class="switch-bar">
    <div class="switch-state">
      <span class="dot <?= $enabled ? '' : 'off' ?>" style="width:12px;height:12px"></span>
      <div>
        <div class="big">Signing is <?= $enabled ? 'enabled' : 'disabled' ?></div>
        <div class="muted">When disabled, OTP login and <code>/api/v1/sign</code> return 503 immediately.</div>
      </div>
    </div>
    <form method="post" action="<?= h(admin_url('server')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="toggle_server">
      <input type="hidden" name="enable" value="<?= $enabled ? '0' : '1' ?>">
      <?php if ($enabled): ?>
        <button class="danger" type="submit">Turn server OFF</button>
      <?php else: ?>
        <button class="good" type="submit">Turn server ON</button>
      <?php endif; ?>
    </form>
  </div>
</div>

<div class="stats">
  <div class="stat"><div class="num"><?= (int)$counts['valid'] ?></div><div class="lbl">Valid OTPs</div></div>
  <div class="stat"><div class="num"><?= (int)$counts['used'] ?></div><div class="lbl">Used</div></div>
  <div class="stat"><div class="num"><?= (int)$counts['expired'] ?></div><div class="lbl">Expired</div></div>
  <div class="stat"><div class="num"><?= (int)$counts['total'] ?></div><div class="lbl">Total OTPs</div></div>
</div>

<div class="card">
  <h2>App &amp; API Service Endpoints <span class="muted">Active routes for Let's Fire Tool</span></h2>
  <div class="endpoint-list">
    <div class="endpoint-item">
      <div><span class="method post">POST</span><code>/otp/otp_eu.php</code></div>
      <div class="muted" style="margin-top:4px">OTP validation (<code>ServiceEncryptedOtpV4</code>) &amp; expiration (<code>ServiceEncryptedDeletOtp</code>)</div>
    </div>
    <div class="endpoint-item">
      <div><span class="method">GET</span><code>/daNewPath/qc/daaptch.json</code></div>
      <div class="muted" style="margin-top:4px">MTK Models &amp; DA variants JSON catalog</div>
    </div>
    <div class="endpoint-item">
      <div><span class="method">GET</span><code>/daNewPath/das/&lt;variant&gt;.qc</code></div>
      <div class="muted" style="margin-top:4px">Compressed Download Agent binary stream (<code>.bin.qc</code>)</div>
    </div>
    <div class="endpoint-item">
      <div><span class="method post">POST</span><code>/api/auth/otp</code> · <code>/api/v1/sign</code></div>
      <div class="muted" style="margin-top:4px">Bearer token exchange &amp; BROM / PL / DA SLA RSA signing</div>
    </div>
  </div>
</div>

<div class="card">
  <h2>Recent auth activity <span class="muted">newest first</span></h2>
  <?php if (!$authLog): ?>
    <div class="muted">No authentication events recorded yet.</div>
  <?php else: ?>
    <pre class="log"><?= h(implode("\n", $authLog)) ?></pre>
  <?php endif; ?>
</div>
<?php
$content = (string)ob_get_clean();
admin_render_layout($config, 'server', 'Server', $content, $dbError);
