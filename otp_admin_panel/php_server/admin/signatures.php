<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire Admin Panel — Generated RSA Signatures (/admin/signatures.php)
 */

require_once __DIR__ . '/bootstrap.php';
admin_require_login();

$dbError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        flash('Invalid CSRF token.', 'err');
        redirect(admin_url('signatures'));
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'clear_signatures') {
            signatures_clear($config);
            admin_log($config, 'SIGNATURES_CLEAR');
            flash('Signature log cleared.');
        }
    } catch (Throwable $e) {
        flash('Error: ' . $e->getMessage(), 'err');
    }
    redirect(admin_url('signatures'));
}

try {
    $sigs = signatures_recent($config, 200);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    $sigs    = [];
}

ob_start();
?>
<div class="card">
  <h2>
    <span>Generated signatures (<?= count($sigs) ?>)</span>
    <?php if ($sigs): ?>
      <form method="post" action="<?= h(admin_url('signatures')) ?>" onsubmit="return confirm('Clear the signature history?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="clear_signatures">
        <button class="ghost sm" type="submit">Clear history</button>
      </form>
    <?php endif; ?>
  </h2>
  <p class="muted" style="margin-top:0">
    Every successful <code>/api/v1/sign</code> call is recorded here with its SHA-256 payload digest and full RSA signature.
  </p>
  <?php if (!$sigs): ?>
    <div class="muted">No signatures generated yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>When</th>
            <th>User</th>
            <th>Purpose</th>
            <th>Payload digest</th>
            <th>IP</th>
            <th>Signature (click to copy)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($sigs as $s): ?>
            <tr>
              <td class="muted"><?= when($s['ts'] ?? null) ?></td>
              <td><?= h((string)($s['user_id'] ?? '')) ?> <span class="muted">(<?= h((string)($s['profile'] ?? '')) ?>)</span></td>
              <td><span class="badge b-used"><?= h((string)($s['purpose'] ?? '')) ?></span></td>
              <td class="mono"><?= h(substr((string)($s['sha256'] ?? ''), 0, 16)) ?>… (<?= (int)($s['bytes'] ?? 0) ?>B)</td>
              <td class="muted"><?= h((string)($s['ip'] ?? '')) ?></td>
              <td>
                <input type="text" readonly class="mono" style="width:260px;font-size:11.5px"
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
admin_render_layout($config, 'signatures', 'Signatures', $content, $dbError);
