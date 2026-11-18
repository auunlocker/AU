<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire Admin Panel — OTP Codes Management (/admin/otps.php)
 */

require_once __DIR__ . '/bootstrap.php';
admin_require_login();

$dbError = null;
$filter  = (string)($_GET['filter'] ?? ($_POST['filter'] ?? 'all'));
if (!in_array($filter, ['all', 'valid', 'used', 'expired', 'inactive'], true)) {
    $filter = 'all';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        flash('Invalid CSRF token.', 'err');
        redirect(admin_url('otps', ['filter' => $filter]));
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'create_otp') {
            $custom = strtoupper(trim((string)($_POST['code'] ?? '')));
            if ($custom !== '' && !preg_match('/^[A-Z0-9_-]{4,64}$/', $custom)) {
                flash('Code must be 4–64 chars (A–Z, 0–9, _ or -).', 'err');
            } else {
                $code = $custom !== '' ? $custom : otp_generate_code(10);
                if (otp_find($config, $code) !== null) {
                    flash("Code $code already exists.", 'err');
                } else {
                    $mins      = max(0, (int)($_POST['minutes'] ?? 60));
                    $expiresAt = $mins > 0 ? gmdate('Y-m-d\TH:i:s\Z', time() + $mins * 60) : null;
                    $profile   = trim((string)($_POST['profile'] ?? 'default')) ?: 'default';
                    $userId    = trim((string)($_POST['user_id'] ?? 'admin')) ?: 'admin';
                    $note      = substr(trim((string)($_POST['note'] ?? '')), 0, 200);
                    otp_upsert($config, $code, [
                        'status'     => 'valid',
                        'used'       => false,
                        'user_id'    => $userId,
                        'profile'    => $profile,
                        'expires_at' => $expiresAt,
                        'note'       => $note,
                    ]);
                    admin_log($config, "OTP_CREATE code=$code profile=$profile mins=$mins");
                    flash("OTP created: $code");
                }
            }
        } elseif ($action === 'revoke_otp') {
            $code = (string)($_POST['code'] ?? '');
            if (otp_set_status($config, $code, 'inactive')) {
                admin_log($config, "OTP_REVOKE code=$code");
                flash("Revoked $code.");
            }
        } elseif ($action === 'delete_otp') {
            $code = (string)($_POST['code'] ?? '');
            if (otp_delete($config, $code)) {
                admin_log($config, "OTP_DELETE code=$code");
                flash("Deleted $code.");
            }
        } elseif ($action === 'purge_otps') {
            $n = otp_purge_finished($config);
            admin_log($config, "OTP_PURGE count=$n");
            flash("Purged $n used/expired/inactive codes.");
        }
    } catch (Throwable $e) {
        flash('Error: ' . $e->getMessage(), 'err');
    }
    redirect(admin_url('otps', ['filter' => $filter]));
}

try {
    $profiles = profiles_load($config);
    $otpRows  = otp_list($config, $filter, 300);
} catch (Throwable $e) {
    $dbError  = $e->getMessage();
    $profiles = ['default' => DEFAULT_PERMISSIONS];
    $otpRows  = [];
}

ob_start();
?>
<div class="card">
  <h2>Issue a code</h2>
  <form method="post" action="<?= h(admin_url('otps', ['filter' => $filter])) ?>" class="row">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="create_otp">
    <input type="hidden" name="filter" value="<?= h($filter) ?>">
    <div class="field">
      <label>Code (blank = random)</label>
      <input type="text" name="code" placeholder="auto-generate" maxlength="64">
    </div>
    <div class="field">
      <label>Valid for (minutes, 0 = no expiry)</label>
      <input type="number" name="minutes" value="60" min="0" max="525600" style="width:140px">
    </div>
    <div class="field">
      <label>Profile</label>
      <select name="profile">
        <?php foreach (array_keys($profiles) as $pName): ?>
          <option value="<?= h((string)$pName) ?>"><?= h((string)$pName) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="field">
      <label>Operator / user id</label>
      <input type="text" name="user_id" value="tech" maxlength="64">
    </div>
    <div class="field" style="flex:1">
      <label>Note</label>
      <input type="text" name="note" placeholder="ticket / customer" maxlength="200" style="width:100%">
    </div>
    <button type="submit">Create OTP</button>
  </form>
</div>

<div class="card">
  <h2>
    <span>Codes (<?= count($otpRows) ?>)</span>
    <div style="display:flex;gap:10px;align-items:center;flex-wrap:wrap">
      <div class="pills">
        <?php foreach (['all' => 'All', 'valid' => 'Valid', 'used' => 'Used', 'expired' => 'Expired', 'inactive' => 'Inactive'] as $k => $lbl): ?>
          <a class="<?= $filter === $k ? 'active' : '' ?>" href="<?= h(admin_url('otps', ['filter' => $k])) ?>"><?= h($lbl) ?></a>
        <?php endforeach; ?>
      </div>
      <form method="post" action="<?= h(admin_url('otps', ['filter' => $filter])) ?>" onsubmit="return confirm('Delete all used, expired and inactive codes?')">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="purge_otps">
        <input type="hidden" name="filter" value="<?= h($filter) ?>">
        <button class="ghost sm" type="submit">Purge old</button>
      </form>
    </div>
  </h2>
  <?php if (!$otpRows): ?>
    <div class="muted">No OTP codes match this filter.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Code</th>
            <th>State</th>
            <th>Profile</th>
            <th>User</th>
            <th>Created</th>
            <th>Expires</th>
            <th>Used</th>
            <th>Note</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($otpRows as $r): ?>
            <tr>
              <td class="mono"><?= h($r['code']) ?></td>
              <td><span class="badge b-<?= h($r['state']) ?>"><?= h($r['state']) ?></span></td>
              <td><?= h($r['profile']) ?></td>
              <td><?= h($r['user_id']) ?></td>
              <td class="muted"><?= when($r['created_at']) ?></td>
              <td class="muted"><?= when($r['expires_at']) ?></td>
              <td class="muted">
                <?= when($r['used_at']) ?>
                <?= $r['used_ip'] ? ' · ' . h((string)$r['used_ip']) : '' ?>
              </td>
              <td class="muted"><?= h($r['note']) ?></td>
              <td>
                <div class="right-actions">
                  <?php if ($r['state'] === 'valid'): ?>
                    <form method="post" action="<?= h(admin_url('otps', ['filter' => $filter])) ?>">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="revoke_otp">
                      <input type="hidden" name="filter" value="<?= h($filter) ?>">
                      <input type="hidden" name="code" value="<?= h($r['code']) ?>">
                      <button class="ghost sm" type="submit">Revoke</button>
                    </form>
                  <?php endif; ?>
                  <form method="post" action="<?= h(admin_url('otps', ['filter' => $filter])) ?>" onsubmit="return confirm('Delete <?= h($r['code']) ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_otp">
                    <input type="hidden" name="filter" value="<?= h($filter) ?>">
                    <input type="hidden" name="code" value="<?= h($r['code']) ?>">
                    <button class="ghost sm" type="submit">Delete</button>
                  </form>
                </div>
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
admin_render_layout($config, 'otps', 'OTP codes', $content, $dbError);
