<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire Admin Panel — OTP & Service Pricing (/admin/price-service.php)
 *
 * Allows the administrator to configure the credit cost required to generate
 * an OTP in the User Portal (/user/dashboard.php), default OTP validity duration,
 * currency/credit label, and per-profile OTP pricing.
 */

require_once __DIR__ . '/bootstrap.php';
admin_require_login();

$dbError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        flash('Invalid CSRF token.', 'err');
        redirect(admin_url('price-service'));
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_pricing') {
            $otpPrice   = max(0.0, round((float)($_POST['otp_price'] ?? 1.0), 2));
            $defaultMin = max(0, min(525600, (int)($_POST['otp_default_minutes'] ?? 60)));
            $currency   = trim((string)($_POST['currency_label'] ?? 'Credits')) ?: 'Credits';
            $notice     = substr(trim((string)($_POST['min_credits_notice'] ?? '')), 0, 250);

            $current = pricing_load($config);
            $profilePrices = is_array($current['profile_prices'] ?? null) ? $current['profile_prices'] : [];
            $profilePrices['default'] = $otpPrice;

            pricing_save($config, [
                'otp_price'           => $otpPrice,
                'otp_default_minutes' => $defaultMin,
                'currency_label'      => $currency,
                'min_credits_notice'  => $notice,
                'profile_prices'      => $profilePrices,
            ]);

            admin_log($config, sprintf('PRICE_UPDATE otp_price=%.2f mins=%d unit=%s', $otpPrice, $defaultMin, $currency));
            flash(sprintf('OTP generation price updated to %.2f %s.', $otpPrice, $currency));
        } elseif ($action === 'save_profile_prices') {
            $current  = pricing_load($config);
            $profiles = profiles_load($config);
            $newMap   = [];
            foreach (array_keys($profiles) as $pName) {
                $field = 'price_' . $pName;
                if (isset($_POST[$field]) && is_numeric($_POST[$field])) {
                    $newMap[(string)$pName] = max(0.0, round((float)$_POST[$field], 2));
                } else {
                    $newMap[(string)$pName] = (float)$current['otp_price'];
                }
            }
            $basePrice = $newMap['default'] ?? (float)$current['otp_price'];
            pricing_save($config, [
                'otp_price'      => $basePrice,
                'profile_prices' => $newMap,
            ]);
            admin_log($config, 'PROFILE_PRICES_UPDATE');
            flash('Profile OTP prices saved.');
        }
    } catch (Throwable $e) {
        flash('Error: ' . $e->getMessage(), 'err');
    }
    redirect(admin_url('price-service'));
}

try {
    $pricing  = pricing_load($config);
    $profiles = profiles_load($config);
} catch (Throwable $e) {
    $dbError  = $e->getMessage();
    $pricing  = pricing_defaults();
    $profiles = ['default' => DEFAULT_PERMISSIONS];
}

$currency = (string)($pricing['currency_label'] ?? 'Credits');

ob_start();
?>
<div class="stats">
  <div class="stat">
    <div class="num"><?= number_format((float)$pricing['otp_price'], 2) ?> <span style="font-size:13px;font-weight:500" class="muted"><?= h($currency) ?></span></div>
    <div class="lbl">Standard OTP Price</div>
  </div>
  <div class="stat">
    <div class="num"><?= (int)$pricing['otp_default_minutes'] > 0 ? (int)$pricing['otp_default_minutes'] . ' min' : 'No expiry' ?></div>
    <div class="lbl">Default OTP Validity</div>
  </div>
  <div class="stat">
    <div class="num"><?= h($currency) ?></div>
    <div class="lbl">Credit Unit Label</div>
  </div>
  <div class="stat">
    <div class="num" style="font-size:17px"><?= when($pricing['updated_at'] ?? null) ?></div>
    <div class="lbl">Last Price Update</div>
  </div>
</div>

<div class="card">
  <h2>OTP Generation Price &amp; Rules</h2>
  <p class="muted" style="margin-top:0">
    Set the amount of credits deducted whenever a user generates an OTP in the User Portal
    (<code>/user/dashboard.php</code>). Users without sufficient credits cannot generate any OTP.
  </p>
  <form method="post" action="<?= h(admin_url('price-service')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_pricing">
    <div class="row" style="margin-bottom:14px">
      <div class="field">
        <label>Price per 1 OTP (in <?= h($currency) ?>)</label>
        <input type="number" name="otp_price" step="0.01" min="0" max="999999"
               value="<?= h(number_format((float)$pricing['otp_price'], 2, '.', '')) ?>" required>
      </div>
      <div class="field">
        <label>Default OTP Validity (minutes, 0 = never expires)</label>
        <input type="number" name="otp_default_minutes" min="0" max="525600"
               value="<?= (int)$pricing['otp_default_minutes'] ?>" required>
      </div>
      <div class="field">
        <label>Credit / Currency Label</label>
        <input type="text" name="currency_label" maxlength="24"
               value="<?= h($currency) ?>" placeholder="Credits">
      </div>
    </div>
    <div class="row">
      <div class="field" style="flex:1">
        <label>Recharge Instructions (shown when a user has insufficient credits)</label>
        <input type="text" name="min_credits_notice" maxlength="250" style="width:100%"
               value="<?= h((string)($pricing['min_credits_notice'] ?? '')) ?>"
               placeholder="Contact the administrator to recharge your account credits.">
      </div>
      <button type="submit">Save OTP Price</button>
    </div>
  </form>
</div>

<div class="card">
  <h2>OTP Price by Permission Profile <span class="muted">optional custom rate per profile</span></h2>
  <p class="muted" style="margin-top:0">
    Allows assigning different OTP generation costs depending on the profile assigned to the user account.
  </p>
  <form method="post" action="<?= h(admin_url('price-service')) ?>">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="save_profile_prices">
    <div class="table-wrap">
      <table>
        <thead>
          <tr>
            <th>Profile</th>
            <th>Granted SLA Services</th>
            <th>Cost per OTP (<?= h($currency) ?>)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($profiles as $pName => $perms):
              $pCost = otp_price_for_profile($config, (string)$pName);
              $enabledPerms = [];
              foreach (['brom_sla' => 'BROM', 'pl_sla' => 'PL', 'meta_sla' => 'META', 'da_sla' => 'DA'] as $k => $lbl) {
                  if (!empty($perms[$k])) {
                      $enabledPerms[] = $lbl;
                  }
              }
          ?>
            <tr>
              <td>
                <strong><?= h((string)$pName) ?></strong>
                <?php if ($pName === 'default'): ?><span class="muted"> (standard)</span><?php endif; ?>
              </td>
              <td>
                <?php if ($enabledPerms): ?>
                  <?php foreach ($enabledPerms as $ep): ?>
                    <span class="badge b-used"><?= h($ep) ?> SLA</span>
                  <?php endforeach; ?>
                <?php else: ?>
                  <span class="muted">None</span>
                <?php endif; ?>
              </td>
              <td>
                <input type="number" name="price_<?= h((string)$pName) ?>" step="0.01" min="0" max="999999"
                       value="<?= h(number_format($pCost, 2, '.', '')) ?>" style="width:140px">
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:14px;display:flex;justify-content:flex-end">
      <button type="submit">Update Profile Prices</button>
    </div>
  </form>
</div>
<?php
$content = (string)ob_get_clean();
admin_render_layout($config, 'price-service', 'Price Service', $content, $dbError);
