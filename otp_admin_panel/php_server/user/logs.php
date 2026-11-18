<?php
declare(strict_types=1);

/**
 * Let's Fire / Penumbra User Portal — App Service Logs (/user/logs.php)
 *
 * Shows all operations performed by the user's OTPs in the Let's Fire Tool:
 *   - OTP Generation (OTP_GENERATED)
 *   - Step 2 OTP Verification in App (APP_OTP_VERIFIED)
 *   - Hardware BROM / Preloader / DA SLA Signatures (SLA_SIGNED)
 *   - Step 8 Service Completion & OTP Expiration in App (APP_OTP_CONSUMED)
 */

require_once __DIR__ . '/bootstrap.php';
$user = user_require_login($config);

$serviceLogs = app_service_logs_list($config, $user['username'], 150);

// Filter signatures belonging to this user
$allSigs  = signatures_recent($config, 300);
$userSigs = [];
foreach ($allSigs as $s) {
    $sUser = (string)($s['user_id'] ?? $s['user'] ?? '');
    if (strcasecmp($sUser, $user['username']) === 0) {
        $userSigs[] = $s;
    }
}

$stats = otp_stats_for_user($config, $user['username']);

ob_start();
?>
<div class="stats">
  <div class="stat"><div class="num"><?= count($serviceLogs) ?></div><div class="lbl">Total App Events</div></div>
  <div class="stat"><div class="num"><?= (int)$stats['used'] ?></div><div class="lbl">Completed OTP Services</div></div>
  <div class="stat"><div class="num"><?= count($userSigs) ?></div><div class="lbl">SLA RSA Signatures</div></div>
  <div class="stat"><div class="num"><?= (int)$stats['valid'] ?></div><div class="lbl">Active OTPs</div></div>
</div>

<div class="card">
  <h2>
    <span>Let's Fire App Service Logs (<?= count($serviceLogs) ?>)</span>
    <span class="muted">Real-time activity from the desktop tool</span>
  </h2>
  <p class="muted" style="margin-top:0">
    Every time you validate an OTP in <strong>Let's Fire v2.0.1</strong> (Step 2), perform SLA authentication, or complete a device operation (Step 8), it appears here.
  </p>
  <?php if (!$serviceLogs): ?>
    <div class="muted">No app service activity recorded for your account yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>When</th>
            <th>Event</th>
            <th>OTP Code</th>
            <th>Details</th>
            <th>Client IP</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($serviceLogs as $log):
              $ev = (string)($log['event_type'] ?? '');
              $badgeCls = 'b-used';
              if ($ev === 'APP_OTP_CONSUMED') {
                  $badgeCls = 'b-valid';
              } elseif ($ev === 'OTP_GENERATED') {
                  $badgeCls = 'b-expired';
              }
          ?>
            <tr>
              <td class="muted"><?= when($log['created_at'] ?? null) ?></td>
              <td><span class="badge <?= $badgeCls ?>"><?= h($ev) ?></span></td>
              <td class="mono"><?= h((string)($log['otp_code'] ?? '—')) ?></td>
              <td><?= h((string)($log['details'] ?? '')) ?></td>
              <td class="muted"><?= h((string)($log['ip'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>My Hardware SLA Signatures (<?= count($userSigs) ?>)</h2>
  <?php if (!$userSigs): ?>
    <div class="muted">No RSA SLA signatures recorded for your account yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>When</th>
            <th>Purpose</th>
            <th>Payload Digest</th>
            <th>IP</th>
            <th>Signature</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($userSigs as $s): ?>
            <tr>
              <td class="muted"><?= when($s['ts'] ?? null) ?></td>
              <td><span class="badge b-used"><?= h((string)($s['purpose'] ?? '')) ?></span></td>
              <td class="mono"><?= h(substr((string)($s['sha256'] ?? ''), 0, 16)) ?>… (<?= (int)($s['bytes'] ?? 0) ?>B)</td>
              <td class="muted"><?= h((string)($s['ip'] ?? '')) ?></td>
              <td>
                <input type="text" readonly class="mono" style="width:240px;font-size:11.5px"
                       value="<?= h((string)($s['signature'] ?? '')) ?>"
                       onclick="this.select();navigator.clipboard&&navigator.clipboard.writeText(this.value)">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php
$content = (string)ob_get_clean();
user_render_layout($config, $user, 'logs', 'App Service Logs', $content);
