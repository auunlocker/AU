<?php
declare(strict_types=1);

/**
 * Let's Fire / Penumbra User Portal — OTP Generator & Dashboard (/user/dashboard.php)
 *
 * Displays:
 *   - User's current credit balance
 *   - Current OTP generation price configured in /admin/price-service.php
 *   - One-click OTP generation (enforcing sufficient credits)
 *   - Table of all OTP codes belonging to the user
 */

require_once __DIR__ . '/bootstrap.php';
$user = user_require_login($config);

$filter = (string)($_GET['filter'] ?? ($_POST['filter'] ?? 'all'));
if (!in_array($filter, ['all', 'valid', 'used', 'expired', 'inactive'], true)) {
    $filter = 'all';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        flash('Invalid CSRF token.', 'err');
        redirect(user_url('dashboard', ['filter' => $filter]));
    }

    $action = (string)($_POST['action'] ?? '');
    if ($action === 'generate_otp') {
        $note   = trim((string)($_POST['note'] ?? ''));
        $result = user_generate_otp_with_credits($config, $user['username'], $note);
        if (!$result['ok']) {
            flash((string)$result['error'], 'err');
        } else {
            $_SESSION['last_generated_otp'] = (string)$result['code'];
            flash(sprintf(
                'OTP generated: %s (Cost: %.2f · Remaining balance: %.2f)',
                $result['code'],
                (float)$result['cost'],
                (float)$result['balance']
            ));
        }
    }
    redirect(user_url('dashboard', ['filter' => $filter]));
}

// Reload fresh user data
$user       = user_require_login($config);
$pricing    = pricing_load($config);
$currency   = (string)($pricing['currency_label'] ?? 'Credits');
$otpPrice   = otp_price_for_profile($config, $user['profile']);
$defaultMin = (int)($pricing['otp_default_minutes'] ?? 60);
$hasCredits = ((float)$user['credits'] >= $otpPrice);
$canBuyCnt  = $otpPrice > 0 ? (int)floor((float)$user['credits'] / $otpPrice) : 999;

$stats   = otp_stats_for_user($config, $user['username']);
$otpRows = otp_list_for_user($config, $user['username'], $filter, 200);

$lastOtp = $_SESSION['last_generated_otp'] ?? null;
unset($_SESSION['last_generated_otp']);

ob_start();
?>
<div class="stats">
  <div class="stat">
    <div class="num" style="color:<?= $hasCredits ? '#116329' : '#a40e26' ?>">
      <?= number_format((float)$user['credits'], 2) ?> <span style="font-size:13px;font-weight:500" class="muted"><?= h($currency) ?></span>
    </div>
    <div class="lbl">Your Credit Balance</div>
  </div>
  <div class="stat">
    <div class="num"><?= number_format($otpPrice, 2) ?> <span style="font-size:13px;font-weight:500" class="muted"><?= h($currency) ?></span></div>
    <div class="lbl">Price per 1 OTP</div>
  </div>
  <div class="stat">
    <div class="num"><?= (int)$stats['valid'] ?></div>
    <div class="lbl">Valid OTPs Ready</div>
  </div>
  <div class="stat">
    <div class="num"><?= (int)$stats['used'] ?></div>
    <div class="lbl">Used in App Services</div>
  </div>
</div>

<?php if ($lastOtp): ?>
  <div class="otp-highlight">
    <div>
      <div class="muted" style="font-weight:600;color:#0550ae">New OTP Code Ready for Let's Fire v2.0.1:</div>
      <div class="code-big mono" id="newOtpVal"><?= h($lastOtp) ?></div>
    </div>
    <button type="button" onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= h($lastOtp) ?>');this.textContent='Copied!'">
      Copy OTP Code
    </button>
  </div>
<?php endif; ?>

<div class="card">
  <h2>
    <span>Generate OTP Code for Let's Fire Tool</span>
    <span class="muted">
      Cost: <strong><?= number_format($otpPrice, 2) ?> <?= h($currency) ?></strong> / OTP
      · Validity: <strong><?= $defaultMin > 0 ? $defaultMin . ' min' : 'No expiry' ?></strong>
    </span>
  </h2>

  <?php if (!$hasCredits): ?>
    <div class="flash err" style="margin-bottom:12px">
      <strong>Insufficient credits to generate an OTP!</strong><br>
      Each OTP costs <strong><?= number_format($otpPrice, 2) ?> <?= h($currency) ?></strong>, and your current balance is
      <strong><?= number_format((float)$user['credits'], 2) ?> <?= h($currency) ?></strong>.<br>
      <span style="font-weight:400"><?= h((string)($pricing['min_credits_notice'] ?? 'Contact the administrator to recharge your account credits.')) ?></span>
    </div>
  <?php endif; ?>

  <form method="post" action="<?= h(user_url('dashboard', ['filter' => $filter])) ?>" class="row">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="generate_otp">
    <input type="hidden" name="filter" value="<?= h($filter) ?>">
    <div class="field" style="flex:1">
      <label>Device / Service Note (optional)</label>
      <input type="text" name="note" placeholder="e.g. Realme 11 Pro+ FRP / Format Data" maxlength="200" style="width:100%" <?= !$hasCredits ? 'disabled' : '' ?>>
    </div>
    <div class="field">
      <label>Price to Deduct</label>
      <input type="text" readonly value="<?= number_format($otpPrice, 2) . ' ' . h($currency) ?>" style="width:160px;background:#fafbfc;font-weight:600">
    </div>
    <button type="submit" class="<?= $hasCredits ? 'good' : '' ?>" <?= !$hasCredits ? 'disabled' : '' ?>>
      Generate OTP (<?= number_format($otpPrice, 2) ?> <?= h($currency) ?>)
    </button>
  </form>
  <?php if ($hasCredits && $otpPrice > 0): ?>
    <div class="muted" style="margin-top:8px">
      You have enough balance to generate up to <strong><?= $canBuyCnt ?></strong> OTP code<?= $canBuyCnt === 1 ? '' : 's' ?>.
    </div>
  <?php endif; ?>
</div>

<div class="card">
  <h2>
    <span>My OTP Codes (<?= count($otpRows) ?>)</span>
    <div class="pills">
      <?php foreach (['all' => 'All', 'valid' => 'Valid', 'used' => 'Used', 'expired' => 'Expired', 'inactive' => 'Inactive'] as $k => $lbl): ?>
        <a class="<?= $filter === $k ? 'active' : '' ?>" href="<?= h(user_url('dashboard', ['filter' => $k])) ?>"><?= h($lbl) ?></a>
      <?php endforeach; ?>
    </div>
  </h2>
  <?php if (!$otpRows): ?>
    <div class="muted">No OTP codes found for this filter.</div>
  <?php else: ?>
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>OTP Code</th>
            <th>State</th>
            <th>Profile</th>
            <th>Created</th>
            <th>Expires</th>
            <th>Used in App</th>
            <th>Note</th>
            <th></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($otpRows as $r): ?>
            <tr>
              <td class="mono" style="font-weight:600"><?= h($r['code']) ?></td>
              <td><span class="badge b-<?= h($r['state']) ?>"><?= h($r['state']) ?></span></td>
              <td><?= h($r['profile']) ?></td>
              <td class="muted"><?= when($r['created_at']) ?></td>
              <td class="muted"><?= when($r['expires_at']) ?></td>
              <td class="muted">
                <?= when($r['used_at']) ?>
                <?= $r['used_ip'] ? ' · ' . h((string)$r['used_ip']) : '' ?>
              </td>
              <td class="muted"><?= h($r['note']) ?></td>
              <td style="text-align:right">
                <button type="button" class="ghost sm"
                        onclick="navigator.clipboard&&navigator.clipboard.writeText('<?= h($r['code']) ?>');this.textContent='Copied'">
                  Copy
                </button>
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
user_render_layout($config, $user, 'dashboard', 'OTP Generator', $content);
