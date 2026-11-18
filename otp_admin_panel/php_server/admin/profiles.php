<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire Admin Panel — Permission Profiles (/admin/profiles.php)
 */

require_once __DIR__ . '/bootstrap.php';
admin_require_login();

$dbError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        flash('Invalid CSRF token.', 'err');
        redirect(admin_url('profiles'));
    }
    $action = (string)($_POST['action'] ?? '');
    try {
        if ($action === 'save_profile') {
            $name = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['name'] ?? ''));
            if ($name === '') {
                flash('Profile name required.', 'err');
            } else {
                $profiles = profiles_load($config);
                $perms    = [];
                foreach (array_keys(DEFAULT_PERMISSIONS) as $k) {
                    $perms[$k] = !empty($_POST['perm_' . $k]);
                }
                $profiles[$name] = $perms;
                profiles_save($config, $profiles);
                admin_log($config, "PROFILE_SAVE name=$name");
                flash("Profile '$name' saved.");
            }
        } elseif ($action === 'add_profile') {
            $name = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($_POST['name'] ?? ''));
            if ($name === '') {
                flash('Profile name required (letters, digits, _ or -).', 'err');
            } else {
                $profiles = profiles_load($config);
                if (isset($profiles[$name])) {
                    flash("Profile '$name' already exists.", 'err');
                } else {
                    $profiles[$name] = DEFAULT_PERMISSIONS;
                    profiles_save($config, $profiles);
                    admin_log($config, "PROFILE_ADD name=$name");
                    flash("Profile '$name' created.");
                }
            }
        } elseif ($action === 'delete_profile') {
            $name = (string)($_POST['name'] ?? '');
            if ($name === 'default') {
                flash('Cannot delete the default profile.', 'err');
            } else {
                $profiles = profiles_load($config);
                unset($profiles[$name]);
                profiles_save($config, $profiles);
                admin_log($config, "PROFILE_DELETE name=$name");
                flash("Profile '$name' deleted.");
            }
        }
    } catch (Throwable $e) {
        flash('Error: ' . $e->getMessage(), 'err');
    }
    redirect(admin_url('profiles'));
}

try {
    $profiles = profiles_load($config);
} catch (Throwable $e) {
    $dbError  = $e->getMessage();
    $profiles = ['default' => DEFAULT_PERMISSIONS];
}

ob_start();
?>
<div class="card">
  <h2>Permission profiles</h2>
  <p class="muted" style="margin-top:0">
    Each OTP is bound to a profile. The profile decides which SLA challenges the bearer token is allowed to sign.
    Changes apply immediately to active sessions via <code>/api/v1/is-authorized</code> and <code>/api/v1/sign</code>.
  </p>
  <?php foreach ($profiles as $pName => $perms): ?>
    <form method="post" action="<?= h(admin_url('profiles')) ?>" style="border-top:1px solid #eaeef2;padding-top:12px;margin-top:12px">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save_profile">
      <input type="hidden" name="name" value="<?= h((string)$pName) ?>">
      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px">
        <div>
          <strong><?= h((string)$pName) ?></strong>
          <?php if ($pName === 'default'): ?><span class="muted"> (fallback)</span><?php endif; ?>
        </div>
        <div class="checks">
          <?php foreach ([
              'brom_sla' => 'BROM SLA',
              'pl_sla'   => 'Preloader SLA',
              'meta_sla' => 'META SLA',
              'da_sla'   => 'DA SLA',
              'is_admin' => 'Admin flag',
          ] as $k => $lbl): ?>
            <label>
              <input type="checkbox" name="perm_<?= h($k) ?>" value="1" <?= !empty($perms[$k]) ? 'checked' : '' ?>>
              <?= h($lbl) ?>
            </label>
          <?php endforeach; ?>
        </div>
        <div class="right-actions">
          <button class="sm" type="submit">Save</button>
          <?php if ($pName !== 'default'): ?>
            <button class="ghost sm" type="submit" name="action" value="delete_profile"
                    onclick="return confirm('Delete profile <?= h((string)$pName) ?>?')">Delete</button>
          <?php endif; ?>
        </div>
      </div>
    </form>
  <?php endforeach; ?>
</div>

<div class="card">
  <h2>New profile</h2>
  <form method="post" action="<?= h(admin_url('profiles')) ?>" class="row">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add_profile">
    <div class="field">
      <label>Profile name</label>
      <input type="text" name="name" placeholder="e.g. brom_only" required maxlength="32">
    </div>
    <button type="submit">Add profile</button>
  </form>
</div>
<?php
$content = (string)ob_get_clean();
admin_render_layout($config, 'profiles', 'Permissions', $content, $dbError);
