<?php
declare(strict_types=1);

/**
 * Let's Fire / Penumbra User Portal — Credits History & Account Settings (/user/credits.php)
 */

require_once __DIR__ . '/bootstrap.php';
$user = user_require_login($config);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        flash('Invalid CSRF token.', 'err');
        redirect(user_url('credits'));
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $newPass = (string)($_POST['new_password'] ?? '');
        $confirm = (string)($_POST['confirm_password'] ?? '');

        if (!password_verify($current, $user['password_hash'])) {
            flash('Current password is incorrect.', 'err');
        } elseif (strlen($newPass) < 4) {
            flash('New password must be at least 4 characters.', 'err');
        } elseif ($newPass !== $confirm) {
            flash('New passwords do not match.', 'err');
        } else {
            user_update($config, $user['username'], ['new_password' => $newPass]);
            flash('Your password has been updated.');
        }
    }
    redirect(user_url('credits'));
}

$user       = user_require_login($config);
$pricing    = pricing_load($config);
$currency   = (string)($pricing['currency_label'] ?? 'Credits');
$otpPrice   = otp_price_for_profile($config, $user['profile']);
$creditLogs = credit_logs_list($config, $user['username'], 100);

ob_start();
?>
<div class="stats">
  <div class="stat">
    <div class="num"><?= number_format((float)$user['credits'], 2) ?> <span style="font-size:13px;font-weight:500" class="muted"><?= h($currency) ?></span></div>
    <div class="lbl">Available Balance</div>
  </div>
  <div class="stat">
    <div class="num"><?= number_format($otpPrice, 2) ?> <span style="font-size:13px;font-weight:500" class="muted"><?= h($currency) ?></span></div>
    <div class="lbl">OTP Generation Cost</div>
  </div>
  <div class="stat">
    <div class="num"><?= h($user['profile']) ?></div>
    <div class="lbl">Permission Profile</div>
  </div>
  <div class="stat">
    <div class="num"><?= $otpPrice > 0 ? (int)floor((float)$user['credits'] / $otpPrice) : '∞' ?></div>
    <div class="lbl">OTPs Available to Mint</div>
  </div>
</div>

<div class="card">
  <h2>Credit Transactions &amp; Recharges (<?= count($creditLogs) ?>)</h2>
  <p class="muted" style="margin-top:0">
    <?= h((string)($pricing['min_credits_notice'] ?? 'Contact the administrator to recharge your account credits.')) ?>
  </p>
  <?php if (!$creditLogs): ?>
    <div class="muted">No credit transactions recorded yet.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>When</th>
            <th>Type</th>
            <th>Amount</th>
            <th>Balance After</th>
            <th>Description</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($creditLogs as $cl):
              $amt = (float)($cl['amount'] ?? 0.0);
          ?>
            <tr>
              <td class="muted"><?= when($cl['created_at'] ?? null) ?></td>
              <td><span class="badge <?= $amt >= 0 ? 'b-valid' : 'b-used' ?>"><?= h((string)($cl['type'] ?? '')) ?></span></td>
              <td class="mono" style="font-weight:600;color:<?= $amt >= 0 ? '#116329' : '#0550ae' ?>">
                <?= ($amt >= 0 ? '+' : '') . number_format($amt, 2) ?> <?= h($currency) ?>
              </td>
              <td class="mono"><?= number_format((float)($cl['balance_after'] ?? 0.0), 2) ?> <?= h($currency) ?></td>
              <td class="muted"><?= h((string)($cl['description'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>Change Account Password</h2>
  <form method="post" action="<?= h(user_url('credits')) ?>" class="row">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="change_password">
    <div class="field">
      <label>Current Password</label>
      <input type="password" name="current_password" required>
    </div>
    <div class="field">
      <label>New Password</label>
      <input type="password" name="new_password" required minlength="4">
    </div>
    <div class="field">
      <label>Confirm New Password</label>
      <input type="password" name="confirm_password" required minlength="4">
    </div>
    <button type="submit">Update Password</button>
  </form>
</div>
<?php
$content = (string)ob_get_clean();
user_render_layout($config, $user, 'credits', 'Credits & Account', $content);
