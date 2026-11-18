<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire Admin Panel — Users & Credits Management (/admin/users.php)
 *
 * Allows the administrator to:
 *   - Create and manage user accounts (active/disabled, profile, password reset, delete)
 *   - Add, deduct, or set credits for any user
 *   - View each user's credit balance, OTP usage, and recent credit transaction history
 */

require_once __DIR__ . '/bootstrap.php';
admin_require_login();

$dbError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        flash('Invalid CSRF token.', 'err');
        redirect(admin_url('users'));
    }
    $action    = (string)($_POST['action'] ?? '');
    $adminActor = (string)($_SESSION['admin_user'] ?? 'admin');

    try {
        if ($action === 'create_user') {
            $username = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $credits  = max(0.0, round((float)($_POST['credits'] ?? 0.0), 2));
            $profile  = trim((string)($_POST['profile'] ?? 'default')) ?: 'default';
            $note     = trim((string)($_POST['note'] ?? ''));

            user_create($config, $username, $password, $credits, $profile, $note);
            admin_log($config, sprintf('USER_CREATE user=%s credits=%.2f profile=%s', $username, $credits, $profile));
            flash(sprintf("User '%s' created with %.2f credits.", $username, $credits));
        } elseif ($action === 'adjust_credits') {
            $username = trim((string)($_POST['username'] ?? ''));
            $mode     = (string)($_POST['mode'] ?? 'add');
            $amount   = round((float)($_POST['amount'] ?? 0.0), 2);
            $reason   = trim((string)($_POST['reason'] ?? ''));

            $target = user_find($config, $username);
            if ($target === null) {
                flash("User '$username' not found.", 'err');
            } elseif ($amount < 0) {
                flash('Amount must be a positive number.', 'err');
            } else {
                if ($mode === 'set') {
                    $delta = round($amount - (float)$target['credits'], 2);
                    $type  = 'admin_set';
                    $desc  = $reason !== '' ? $reason : sprintf('Balance set to %.2f by admin', $amount);
                } elseif ($mode === 'deduct') {
                    $delta = -abs($amount);
                    $type  = 'admin_deduct';
                    $desc  = $reason !== '' ? $reason : 'Credits deducted by admin';
                } else {
                    $delta = abs($amount);
                    $type  = 'admin_add';
                    $desc  = $reason !== '' ? $reason : 'Credits recharged by admin';
                }

                $updated = user_adjust_credits($config, $username, $delta, $type, $desc, $adminActor);
                if ($updated === null) {
                    flash("Could not adjust credits for '$username' (insufficient balance for deduction).", 'err');
                } else {
                    admin_log($config, sprintf('USER_CREDITS user=%s mode=%s delta=%.2f bal=%.2f', $username, $mode, $delta, $updated['credits']));
                    flash(sprintf("Updated '%s' credits (new balance: %.2f).", $username, $updated['credits']));
                }
            }
        } elseif ($action === 'toggle_user') {
            $username  = trim((string)($_POST['username'] ?? ''));
            $newStatus = (($_POST['status'] ?? 'active') === 'disabled') ? 'disabled' : 'active';
            if (user_update($config, $username, ['status' => $newStatus])) {
                admin_log($config, "USER_STATUS user=$username status=$newStatus");
                flash("User '$username' is now $newStatus.");
            }
        } elseif ($action === 'update_user') {
            $username = trim((string)($_POST['username'] ?? ''));
            $profile  = trim((string)($_POST['profile'] ?? 'default')) ?: 'default';
            $newPass  = trim((string)($_POST['new_password'] ?? ''));
            $note     = trim((string)($_POST['note'] ?? ''));
            $fields   = ['profile' => $profile, 'note' => $note];
            if ($newPass !== '') {
                if (strlen($newPass) < 4) {
                    throw new InvalidArgumentException('New password must be at least 4 characters.');
                }
                $fields['new_password'] = $newPass;
            }
            if (user_update($config, $username, $fields)) {
                admin_log($config, "USER_UPDATE user=$username profile=$profile");
                flash("User '$username' updated.");
            }
        } elseif ($action === 'delete_user') {
            $username = trim((string)($_POST['username'] ?? ''));
            if (user_delete($config, $username)) {
                admin_log($config, "USER_DELETE user=$username");
                flash("Deleted user '$username'.");
            }
        }
    } catch (Throwable $e) {
        flash('Error: ' . $e->getMessage(), 'err');
    }
    redirect(admin_url('users'));
}

try {
    $users      = users_list($config);
    $profiles   = profiles_load($config);
    $pricing    = pricing_load($config);
    $creditLogs = credit_logs_list($config, null, 60);
} catch (Throwable $e) {
    $dbError    = $e->getMessage();
    $users      = [];
    $profiles   = ['default' => DEFAULT_PERMISSIONS];
    $pricing    = pricing_defaults();
    $creditLogs = [];
}

$currency     = (string)($pricing['currency_label'] ?? 'Credits');
$totalCredits = 0.0;
$activeUsers  = 0;
foreach ($users as $u) {
    $totalCredits += (float)$u['credits'];
    if ($u['status'] === 'active') {
        $activeUsers++;
    }
}

ob_start();
?>
<div class="stats">
  <div class="stat"><div class="num"><?= count($users) ?></div><div class="lbl">Registered Users</div></div>
  <div class="stat"><div class="num"><?= $activeUsers ?></div><div class="lbl">Active Users</div></div>
  <div class="stat"><div class="num"><?= number_format($totalCredits, 2) ?></div><div class="lbl">Total <?= h($currency) ?></div></div>
  <div class="stat">
    <div class="num"><?= number_format((float)$pricing['otp_price'], 2) ?></div>
    <div class="lbl">OTP Price (<a href="<?= h(admin_url('price-service')) ?>">change</a>)</div>
  </div>
</div>

<div class="grid-2">
  <div class="card">
    <h2>Add / Manage User Credits</h2>
    <p class="muted" style="margin-top:0">
      Add credits so the user can generate OTP codes in <code>/user/dashboard.php</code>.
    </p>
    <form method="post" action="<?= h(admin_url('users')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="adjust_credits">
      <div class="row" style="margin-bottom:12px">
        <div class="field" style="flex:1">
          <label>User</label>
          <?php if ($users): ?>
            <select name="username" required style="width:100%">
              <?php foreach ($users as $u): ?>
                <option value="<?= h($u['username']) ?>">
                  <?= h($u['username']) ?> (<?= number_format((float)$u['credits'], 2) ?> <?= h($currency) ?>)
                </option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="text" name="username" placeholder="username" required style="width:100%">
          <?php endif; ?>
        </div>
        <div class="field">
          <label>Operation</label>
          <select name="mode">
            <option value="add">Add Credits (+)</option>
            <option value="deduct">Deduct Credits (-)</option>
            <option value="set">Set Exact Balance (=)</option>
          </select>
        </div>
        <div class="field">
          <label>Amount (<?= h($currency) ?>)</label>
          <input type="number" name="amount" step="0.01" min="0" max="999999" value="10.00" required style="width:130px">
        </div>
      </div>
      <div class="row">
        <div class="field" style="flex:1">
          <label>Note / Payment Reference</label>
          <input type="text" name="reason" placeholder="e.g. Recharge 10 credits" maxlength="200" style="width:100%">
        </div>
        <button class="good" type="submit">Apply Credits</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Create New User Account</h2>
    <p class="muted" style="margin-top:0">
      Create a technician/user account with an initial credit balance.
    </p>
    <form method="post" action="<?= h(admin_url('users')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="create_user">
      <div class="row" style="margin-bottom:12px">
        <div class="field" style="flex:1">
          <label>Username</label>
          <input type="text" name="username" placeholder="e.g. tech01" required maxlength="32" style="width:100%">
        </div>
        <div class="field" style="flex:1">
          <label>Password</label>
          <input type="password" name="password" placeholder="min 4 chars" required maxlength="64" style="width:100%">
        </div>
        <div class="field">
          <label>Initial <?= h($currency) ?></label>
          <input type="number" name="credits" step="0.01" min="0" max="999999" value="5.00" style="width:120px">
        </div>
      </div>
      <div class="row">
        <div class="field">
          <label>Profile</label>
          <select name="profile">
            <?php foreach (array_keys($profiles) as $pName): ?>
              <option value="<?= h((string)$pName) ?>"><?= h((string)$pName) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="flex:1">
          <label>Note</label>
          <input type="text" name="note" placeholder="Customer / shop name" maxlength="200" style="width:100%">
        </div>
        <button type="submit">Create User</button>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <h2>
    <span>Registered Users (<?= count($users) ?>)</span>
    <span class="muted">User portal: <a href="<?= h(site_root_url() . '/user/login.php') ?>" target="_blank">/user/login.php</a></span>
  </h2>
  <?php if (!$users): ?>
    <div class="muted">No user accounts created yet. Use the form above to create one, or let users register at <code>/user/register.php</code>.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Username</th>
            <th>Status</th>
            <th>Balance (<?= h($currency) ?>)</th>
            <th>OTP Price</th>
            <th>OTPs (Valid / Used)</th>
            <th>Profile &amp; Password</th>
            <th>Quick +Credits</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u):
              $uStats = otp_stats_for_user($config, $u['username']);
              $uPrice = otp_price_for_profile($config, $u['profile']);
          ?>
            <tr>
              <td>
                <strong class="mono"><?= h($u['username']) ?></strong>
                <div class="muted" style="font-size:11.5px">Last login: <?= when($u['last_login_at']) ?></div>
              </td>
              <td>
                <span class="badge b-<?= h($u['status']) ?>"><?= h($u['status']) ?></span>
              </td>
              <td>
                <strong style="font-size:14px;color:<?= $u['credits'] >= $uPrice ? '#116329' : '#a40e26' ?>">
                  <?= number_format((float)$u['credits'], 2) ?>
                </strong>
                <span class="muted"><?= h($currency) ?></span>
              </td>
              <td class="muted"><?= number_format($uPrice, 2) ?> <?= h($currency) ?></td>
              <td>
                <span class="badge b-valid"><?= (int)$uStats['valid'] ?> valid</span>
                <span class="badge b-used"><?= (int)$uStats['used'] ?> used</span>
              </td>
              <td>
                <form method="post" action="<?= h(admin_url('users')) ?>" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="update_user">
                  <input type="hidden" name="username" value="<?= h($u['username']) ?>">
                  <select name="profile" style="min-width:95px;padding:5px 8px;font-size:12px">
                    <?php foreach (array_keys($profiles) as $pName): ?>
                      <option value="<?= h((string)$pName) ?>" <?= $u['profile'] === $pName ? 'selected' : '' ?>><?= h((string)$pName) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <input type="text" name="note" value="<?= h($u['note']) ?>" placeholder="note" style="min-width:90px;width:100px;padding:5px 8px;font-size:12px">
                  <input type="password" name="new_password" placeholder="new pass" style="min-width:85px;width:95px;padding:5px 8px;font-size:12px">
                  <button class="ghost sm" type="submit">Save</button>
                </form>
              </td>
              <td>
                <form method="post" action="<?= h(admin_url('users')) ?>" style="display:flex;gap:5px;align-items:center">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="adjust_credits">
                  <input type="hidden" name="username" value="<?= h($u['username']) ?>">
                  <input type="hidden" name="mode" value="add">
                  <input type="hidden" name="reason" value="Quick admin credit add">
                  <input type="number" name="amount" step="0.01" min="0.01" value="5.00" style="min-width:70px;width:75px;padding:5px 7px;font-size:12px">
                  <button class="good sm" type="submit">+Add</button>
                </form>
              </td>
              <td>
                <div class="right-actions">
                  <form method="post" action="<?= h(admin_url('users')) ?>">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_user">
                    <input type="hidden" name="username" value="<?= h($u['username']) ?>">
                    <input type="hidden" name="status" value="<?= $u['status'] === 'active' ? 'disabled' : 'active' ?>">
                    <button class="ghost sm" type="submit"><?= $u['status'] === 'active' ? 'Disable' : 'Enable' ?></button>
                  </form>
                  <form method="post" action="<?= h(admin_url('users')) ?>" onsubmit="return confirm('Delete user <?= h($u['username']) ?>?')">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_user">
                    <input type="hidden" name="username" value="<?= h($u['username']) ?>">
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

<div class="card">
  <h2>Recent Credit Transactions <span class="muted">newest first</span></h2>
  <?php if (!$creditLogs): ?>
    <div class="muted">No credit transactions recorded yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>When</th>
            <th>User</th>
            <th>Type</th>
            <th>Change</th>
            <th>Balance After</th>
            <th>Description</th>
            <th>By</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($creditLogs as $cl):
              $amt = (float)($cl['amount'] ?? 0.0);
          ?>
            <tr>
              <td class="muted"><?= when($cl['created_at'] ?? null) ?></td>
              <td class="mono"><?= h((string)($cl['username'] ?? '')) ?></td>
              <td><span class="badge <?= $amt >= 0 ? 'b-valid' : 'b-used' ?>"><?= h((string)($cl['type'] ?? '')) ?></span></td>
              <td class="mono" style="font-weight:600;color:<?= $amt >= 0 ? '#116329' : '#0550ae' ?>">
                <?= ($amt >= 0 ? '+' : '') . number_format($amt, 2) ?> <?= h($currency) ?>
              </td>
              <td class="mono"><?= number_format((float)($cl['balance_after'] ?? 0.0), 2) ?> <?= h($currency) ?></td>
              <td class="muted"><?= h((string)($cl['description'] ?? '')) ?></td>
              <td class="muted"><?= h((string)($cl['actor'] ?? 'admin')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>
<?php
$content = (string)ob_get_clean();
admin_render_layout($config, 'users', 'Users & Credits', $content, $dbError);
