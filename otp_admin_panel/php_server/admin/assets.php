<?php
declare(strict_types=1);

/**
 * Penumbra / Let's Fire Admin Panel — DA Assets, Multi-Format Upload (.bin, .bin.qc, .mib, .mbn, .elf, .img),
 * Brand Configuration (Realme / Oppo / OnePlus), Multi-Model Batch Assignment, NEW Badge & Sync (/admin/assets.php)
 */

require_once __DIR__ . '/bootstrap.php';
admin_require_login();

$dbError = null;

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!csrf_ok()) {
        flash('Invalid CSRF token.', 'err');
        redirect(admin_url('assets'));
    }

    $action = (string)($_POST['action'] ?? '');
    try {
        switch ($action) {
            case 'sync_assets':
                $stats = assets_synchronize($config);
                admin_log($config, 'ASSETS_SYNC models=' . $stats['models_count'] . ' da=' . $stats['da_files_count']);
                flash(
                    'Synchronized with Website & Desktop App! ' .
                    $stats['models_count'] . ' models (' . $stats['new_count'] . ' marked NEW) and ' .
                    $stats['da_files_count'] . ' DA binaries are live (' . $stats['synchronized_at'] . ').'
                );
                break;

            case 'upload_da':
                $customVariant = trim((string)($_POST['custom_variant'] ?? ''));
                $replaceTarget = trim((string)($_POST['replace_variant'] ?? ''));
                $brand         = trim((string)($_POST['brand'] ?? 'Realme'));
                $operations    = trim((string)($_POST['operations'] ?? 'Format Data · Erase FRP · Unlock'));
                $isNew         = !empty($_POST['is_new']);
                $modelsRaw     = (string)($_POST['models_text'] ?? '');

                $targetVariantName = $replaceTarget !== '' ? $replaceTarget : $customVariant;
                $savedVariant = '';

                if (!empty($_FILES['da_file']['tmp_name']) && is_uploaded_file($_FILES['da_file']['tmp_name'])) {
                    $origName     = (string)($_FILES['da_file']['name'] ?? 'da.bin');
                    $rawContent   = (string)file_get_contents($_FILES['da_file']['tmp_name']);
                    $savedVariant = da_upload_save($config, $origName, $rawContent, $targetVariantName);
                } elseif ($targetVariantName !== '') {
                    $valid = da_variant_name($targetVariantName);
                    if ($valid === null) {
                        throw new RuntimeException('Invalid DA filename / variant.');
                    }
                    $savedVariant = $valid;
                } else {
                    throw new RuntimeException('Please select a DA file (.bin, .bin.qc, .mib, .mbn, .elf, .img) to upload or pick an existing DA.');
                }

                $modelList  = parse_models_input($modelsRaw);
                $addedCount = 0;
                if (!empty($modelList)) {
                    $addedCount = models_upsert_batch($config, $savedVariant, $brand, $modelList, $isNew, $operations);
                } elseif ($replaceTarget !== '' && !empty($_POST['apply_to_existing_models'])) {
                    $addedCount = variant_set_new_flag($config, $savedVariant, $isNew, $brand);
                }

                assets_synchronize($config);
                admin_log($config, "DA_UPLOAD variant=$savedVariant brand=$brand models=$addedCount new=" . ($isNew ? '1' : '0'));
                flash(
                    'DA "' . $savedVariant . '.qc" saved & synchronized! ' .
                    ($addedCount > 0 ? ($addedCount . ' model(s) configured (' . $brand . ($isNew ? ', marked NEW' : '') . ').') : 'Binary updated.')
                );
                break;

            case 'add_models_batch':
                $daVariant  = trim((string)($_POST['da_variant'] ?? ''));
                $brand      = trim((string)($_POST['brand'] ?? 'Realme'));
                $operations = trim((string)($_POST['operations'] ?? 'Format Data · Erase FRP · Unlock'));
                $isNew      = !empty($_POST['is_new']);
                $modelsRaw  = (string)($_POST['models_text'] ?? '');

                $modelList = parse_models_input($modelsRaw);
                if (empty($modelList)) {
                    throw new RuntimeException('Please enter at least one model name.');
                }
                $count = models_upsert_batch($config, $daVariant, $brand, $modelList, $isNew, $operations);
                assets_synchronize($config);
                admin_log($config, "MODELS_ADD_BATCH da=$daVariant brand=$brand count=$count");
                flash(
                    'Added/updated ' . $count . ' model(s) for DA "' . $daVariant . '" (' . $brand . ($isNew ? ' · NEW' : '') . ') and synchronized!'
                );
                break;

            case 'replace_da_file':
                $variant = trim((string)($_POST['da_variant'] ?? ''));
                if ($variant === '') {
                    throw new RuntimeException('Missing DA variant to update.');
                }
                if (empty($_FILES['replace_file']['tmp_name']) || !is_uploaded_file($_FILES['replace_file']['tmp_name'])) {
                    throw new RuntimeException('Please select the new .bin / .bin.qc / .mib file to replace "' . $variant . '".');
                }
                $origName   = (string)($_FILES['replace_file']['name'] ?? $variant);
                $rawContent = (string)file_get_contents($_FILES['replace_file']['tmp_name']);
                $saved      = da_upload_save($config, $origName, $rawContent, $variant);
                if (!empty($_POST['mark_new_on_update'])) {
                    variant_set_new_flag($config, $saved, true);
                }
                assets_synchronize($config);
                admin_log($config, "DA_REPLACE variant=$saved");
                flash('DA "' . $saved . '.qc" replaced with new binary and synchronized with App & Site!');
                break;

            case 'delete_da_file':
                $variant      = trim((string)($_POST['da_variant'] ?? ''));
                $deleteModels = !empty($_POST['delete_linked_models']);
                if (da_file_delete($config, $variant, $deleteModels)) {
                    assets_synchronize($config);
                    admin_log($config, "DA_DELETE variant=$variant delete_models=" . ($deleteModels ? '1' : '0'));
                    flash(
                        'Old DA "' . $variant . '.qc" deleted' . ($deleteModels ? ' along with its linked models' : '') . ' and synchronized.'
                    );
                } else {
                    flash('Could not delete DA "' . $variant . '".', 'err');
                }
                break;

            case 'variant_toggle_new':
                $variant = trim((string)($_POST['da_variant'] ?? ''));
                $isNew   = ((string)($_POST['set_new'] ?? '1')) === '1';
                $brand   = trim((string)($_POST['brand'] ?? ''));
                $updated = variant_set_new_flag($config, $variant, $isNew, $brand);
                assets_synchronize($config);
                admin_log($config, "DA_TOGGLE_NEW variant=$variant new=" . ($isNew ? '1' : '0'));
                flash(
                    'Updated ' . $updated . ' model(s) linked to "' . $variant . '" (' . ($isNew ? 'marked as NEW' : 'NEW badge cleared') . ').'
                );
                break;

            case 'toggle_model_new':
                $display = trim((string)($_POST['display'] ?? ''));
                $setNew  = ((string)($_POST['set_new'] ?? '1')) === '1';
                if (model_toggle_new($config, $display, $setNew)) {
                    assets_synchronize($config);
                    flash('Model "' . $display . '" ' . ($setNew ? 'marked as NEW on the site!' : 'NEW badge removed.'));
                } else {
                    flash('Model not found.', 'err');
                }
                break;

            case 'update_model_single':
                $oldDisplay = trim((string)($_POST['old_display'] ?? ''));
                $newDisplay = trim((string)($_POST['new_display'] ?? ''));
                $brand      = trim((string)($_POST['brand'] ?? 'Realme'));
                $daVariant  = trim((string)($_POST['da_variant'] ?? ''));
                $operations = trim((string)($_POST['operations'] ?? 'Format Data · Erase FRP · Unlock'));
                $isNew      = !empty($_POST['is_new']);
                if (model_update_single($config, $oldDisplay, $newDisplay, $brand, $daVariant, $isNew, $operations)) {
                    assets_synchronize($config);
                    admin_log($config, "MODEL_UPDATE model=$newDisplay da=$daVariant brand=$brand");
                    flash('Model "' . $newDisplay . '" updated and synchronized.');
                } else {
                    flash('Failed to update model.', 'err');
                }
                break;

            case 'delete_model':
                $display = trim((string)($_POST['display'] ?? ''));
                if (model_delete_single($config, $display)) {
                    assets_synchronize($config);
                    admin_log($config, "MODEL_DELETE model=$display");
                    flash('Model "' . $display . '" removed and synchronized.');
                } else {
                    flash('Model "' . $display . '" not found.', 'err');
                }
                break;
        }
    } catch (Throwable $ex) {
        flash('Error: ' . $ex->getMessage(), 'err');
    }

    redirect(admin_url('assets'));
}

try {
    $assets  = assets_summary($config);
    $models  = models_list($config);
} catch (Throwable $e) {
    $dbError = $e->getMessage();
    $assets  = [
        'models_count'     => 0,
        'new_models_count' => 0,
        'synchronized_at'  => '',
        'variants_in_use'  => [],
        'da_files'         => [],
        'missing'          => [],
        'total_bytes'      => 0,
    ];
    $models = [];
}

$daFiles      = $assets['da_files'];
$inUse        = $assets['variants_in_use'];
$missing      = $assets['missing'];
$syncTime     = $assets['synchronized_at'] !== '' ? $assets['synchronized_at'] : 'Ready';
$filterBrand  = trim((string)($_GET['brand'] ?? 'all'));
$filterSearch = trim((string)($_GET['q'] ?? ''));
$editModelKey = trim((string)($_GET['edit'] ?? ''));

$filteredModels = array_values(array_filter($models, static function (array $m) use ($filterBrand, $filterSearch): bool {
    if ($filterBrand === 'new' && empty($m['is_new'])) {
        return false;
    }
    if (!in_array($filterBrand, ['all', 'new', ''], true) && strcasecmp((string)$m['brand'], $filterBrand) !== 0) {
        return false;
    }
    if ($filterSearch !== '') {
        $hay = strtolower($m['display'] . ' ' . $m['brand'] . ' ' . $m['da_variant'] . ' ' . ($m['operations'] ?? ''));
        if (!str_contains($hay, strtolower($filterSearch))) {
            return false;
        }
    }
    return true;
}));

ob_start();
?>

<!-- Top Synchronization Card -->
<div class="card" style="border-left:4px solid #1a7f37">
  <div class="switch-bar">
    <div>
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px">
        <span class="badge b-valid">LIVE CATALOG SYNC</span>
        <strong style="font-size:15px">Website Index (<code>tool-multi-web.php</code>) &amp; Desktop App (<code>/daNewPath/qc/daaptch.json</code>)</strong>
      </div>
      <div class="muted">
        Upload <code>.bin</code>, <code>.bin.qc</code>, <code>.mib</code>, <code>.mbn</code>, <code>.elf</code>, or <code>.img</code> files, configure <strong>Realme</strong>, <strong>Oppo</strong>, or <strong>OnePlus</strong> models, mark models as <span class="badge b-valid">NEW</span>, or replace/delete old DA files.
        Last synchronized: <code><?= h($syncTime) ?></code>
      </div>
    </div>
    <form method="post" action="<?= h(admin_url('assets')) ?>" style="margin:0">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="sync_assets">
      <button class="good" type="submit">&#x21bb; Synchronize Now (Site + App)</button>
    </form>
  </div>
</div>

<!-- Stats Row -->
<div class="stats">
  <div class="stat">
    <div class="num"><?= (int)$assets['models_count'] ?></div>
    <div class="lbl">Total Models (App &amp; Site)</div>
  </div>
  <div class="stat">
    <div class="num" style="color:#1a7f37"><?= (int)$assets['new_models_count'] ?></div>
    <div class="lbl">Models Marked NEW on Site</div>
  </div>
  <div class="stat">
    <div class="num"><?= count($daFiles) ?></div>
    <div class="lbl">DA Files (<?= h(fmt_bytes((int)$assets['total_bytes'])) ?>)</div>
  </div>
  <div class="stat">
    <div class="num"><?= count($missing) ?></div>
    <div class="lbl"><?= count($missing) === 0 ? 'All Referenced DAs Present' : 'Missing DA Binaries' ?></div>
  </div>
</div>

<div class="grid-2">
  <!-- Card 1: Upload / Replace DA File (.bin, .bin.qc, .mib, .mbn) & Assign Models -->
  <div class="card">
    <h2>
      <span>1. Upload / Update DA (<code>.bin</code>, <code>.bin.qc</code>, <code>.mib</code>) &amp; Models</span>
      <span class="muted">Auto-compresses to <code>.qc</code></span>
    </h2>
    <p class="muted" style="margin:0 0 12px">
      Upload any raw DA (<code>.bin</code>, <code>.mib</code>, <code>.mbn</code>, <code>.elf</code>, <code>.img</code>, <code>.auth</code>) or pre-compressed <code>.bin.qc</code>.
      Write one or multiple models below to link them to this DA and synchronize with the site and app.
    </p>

    <form method="post" action="<?= h(admin_url('assets')) ?>" enctype="multipart/form-data">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="upload_da">

      <div class="field" style="margin-bottom:12px">
        <label>Select DA File (<code>.bin</code>, <code>.bin.qc</code>, <code>.mib</code>, <code>.mbn</code>, <code>.elf</code>, <code>.img</code>)</label>
        <input type="file" name="da_file" accept=".bin,.qc,.mib,.mbn,.elf,.img,.auth,.da" style="width:100%">
      </div>

      <div class="row" style="margin-bottom:12px">
        <div class="field" style="flex:1">
          <label>Custom DA Name (Optional, e.g. <code>6789v1.bin</code> or <code>oppo_mt6835.mib</code>)</label>
          <input type="text" name="custom_variant" placeholder="Blank = use uploaded filename" style="width:100%">
        </div>
        <div class="field" style="flex:1">
          <label>Or Replace / Update an Existing DA</label>
          <select name="replace_variant" style="width:100%">
            <option value="">— Create / Use Filename Above —</option>
            <?php foreach ($daFiles as $variant => $meta): ?>
              <option value="<?= h((string)$variant) ?>"><?= h((string)$variant) ?> (<?= h((string)$meta['file']) ?> · <?= h(fmt_bytes((int)$meta['size'])) ?>)</option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <div class="row" style="margin-bottom:12px">
        <div class="field" style="flex:1">
          <label>Brand Support (Marca)</label>
          <select name="brand" style="width:100%">
            <option value="Realme">Realme</option>
            <option value="Oppo">Oppo</option>
            <option value="OnePlus">OnePlus</option>
            <option value="Auto">Auto-Detect from Model Name</option>
          </select>
        </div>
        <div class="field" style="flex:1">
          <label>Supported Operations (Public Website)</label>
          <input type="text" name="operations" value="Format Data · Erase FRP · Unlock" style="width:100%">
        </div>
      </div>

      <div class="field" style="margin-bottom:12px">
        <label>Models Supported by this DA (Write multiple models — one per line or comma-separated)</label>
        <textarea name="models_text" rows="5" placeholder="Realme 12 Pro+ 5G (RMX3840)&#10;Realme 12+ 5G (RMX3867)&#10;Oppo Reno 11F 5G (CPH2603)&#10;OnePlus Nord CE 4 Lite (CPH2619)"></textarea>
      </div>

      <div class="checks" style="margin-bottom:12px">
        <label>
          <input type="checkbox" name="is_new" value="1" checked>
          Mark these models as <span class="badge b-valid">NEW</span> on the Website (<code>tool-multi-web.php</code>)
        </label>
        <label class="muted">
          <input type="checkbox" name="apply_to_existing_models" value="1" checked>
          Apply Brand &amp; NEW flag to existing models if replacing DA
        </label>
      </div>

      <button type="submit" style="width:100%">Upload, Configure &amp; Synchronize</button>
    </form>
  </div>

  <!-- Card 2: Add Multiple Models to an Existing DA -->
  <div class="card">
    <h2>
      <span>2. Add Multiple Models to an Existing DA</span>
      <span class="muted">Realme · Oppo · OnePlus</span>
    </h2>
    <p class="muted" style="margin:0 0 12px">
      Link multiple models at once to any DA already stored on the server and display them with the <span class="badge b-valid">NEW</span> badge on the website.
    </p>

    <form method="post" action="<?= h(admin_url('assets')) ?>">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="add_models_batch">

      <div class="row" style="margin-bottom:12px">
        <div class="field" style="flex:1">
          <label>Select Existing DA Binary</label>
          <select name="da_variant" required style="width:100%">
            <?php foreach ($daFiles as $variant => $meta): ?>
              <option value="<?= h((string)$variant) ?>">
                <?= h((string)$variant) ?> (<?= (int)($inUse[$variant] ?? 0) ?> models · <?= h(implode('/', $meta['brands'] ?? []) ?: 'Unassigned') ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field" style="flex:1">
          <label>Brand Support (Marca)</label>
          <select name="brand" style="width:100%">
            <option value="Realme">Realme</option>
            <option value="Oppo">Oppo</option>
            <option value="OnePlus">OnePlus</option>
            <option value="Auto">Auto-Detect per Line</option>
          </select>
        </div>
      </div>

      <div class="field" style="margin-bottom:12px">
        <label>Supported Operations</label>
        <input type="text" name="operations" value="Format Data · Erase FRP · Unlock" style="width:100%">
      </div>

      <div class="field" style="margin-bottom:12px">
        <label>Models to Add / Update (One model per line or comma-separated)</label>
        <textarea name="models_text" rows="5" required placeholder="Realme C67 5G (RMX3782)&#10;Oppo A79 5G (CPH2553)&#10;OnePlus Nord N30 SE (CPH2605)"></textarea>
      </div>

      <div class="checks" style="margin-bottom:12px">
        <label>
          <input type="checkbox" name="is_new" value="1" checked>
          Show <span class="badge b-valid">NEW</span> badge next to these models on the Website
        </label>
      </div>

      <button class="good" type="submit" style="width:100%">Add Models &amp; Synchronize</button>
    </form>
  </div>
</div>

<!-- Card 3: Manage DA Files on Server (Update/Replace Binary, Toggle NEW, or Delete Old DA) -->
<div class="card">
  <h2>
    <span>3. DA Files on Server (<code>assets/da/*.qc</code>) — Replace, Update or Delete Old DA</span>
    <span class="muted"><?= count($daFiles) ?> binaries on disk</span>
  </h2>

  <?php if (!empty($missing)): ?>
    <div class="flash err">
      <strong>Missing DA binaries referenced by models:</strong>
      <?php foreach ($missing as $m): ?>
        <code style="margin-left:6px"><?= h((string)$m) ?>.qc</code>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if (empty($daFiles)): ?>
    <div class="muted">No DA files found in <code>assets/da/</code>. Upload one above!</div>
  <?php else: ?>
    <div class="table-wrap table-scroll">
      <table>
        <thead>
          <tr>
            <th>DA Variant</th>
            <th>Stored File</th>
            <th>Type</th>
            <th>Brands Supported</th>
            <th>Linked Models</th>
            <th>NEW Status</th>
            <th>Size &amp; Updated</th>
            <th>Replace / Update Binary</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($daFiles as $variant => $meta):
            $count    = (int)($inUse[$variant] ?? 0);
            $newCount = (int)($meta['new_count'] ?? 0);
            $brands   = $meta['brands'] ?? [];
          ?>
            <tr>
              <td class="mono"><strong><?= h((string)$variant) ?></strong></td>
              <td class="mono muted"><?= h((string)$meta['file']) ?></td>
              <td><span class="badge b-used"><?= h(strtoupper(ltrim((string)($meta['ext'] ?? '.bin'), '.'))) ?></span></td>
              <td>
                <?php if (empty($brands)): ?>
                  <span class="muted">No models</span>
                <?php else: foreach ($brands as $b): ?>
                  <span class="badge <?= strtolower((string)$b) === 'oppo' ? 'b-valid' : (strtolower((string)$b) === 'oneplus' ? 'b-expired' : 'b-used') ?>" style="margin-right:3px">
                    <?= h((string)$b) ?>
                  </span>
                <?php endforeach; endif; ?>
              </td>
              <td>
                <?php if ($count > 0): ?>
                  <span class="badge b-valid"><?= $count ?> model<?= $count === 1 ? '' : 's' ?></span>
                <?php else: ?>
                  <span class="badge b-expired">0 models</span>
                <?php endif; ?>
              </td>
              <td>
                <?php if ($newCount > 0): ?>
                  <span class="badge b-valid"><?= $newCount ?> NEW</span>
                <?php else: ?>
                  <span class="muted">Standard</span>
                <?php endif; ?>
              </td>
              <td class="mono" style="font-size:12px">
                <?= h(fmt_bytes((int)$meta['size'])) ?><br>
                <span class="muted"><?= !empty($meta['mtime']) ? h(gmdate('Y-m-d H:i', (int)$meta['mtime']) . ' UTC') : '—' ?></span>
              </td>
              <td>
                <form method="post" action="<?= h(admin_url('assets')) ?>" enctype="multipart/form-data" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="replace_da_file">
                  <input type="hidden" name="da_variant" value="<?= h((string)$variant) ?>">
                  <input type="file" name="replace_file" accept=".bin,.qc,.mib,.mbn,.elf,.img,.auth,.da" required style="width:170px;padding:4px;font-size:11.5px">
                  <label style="display:inline-flex;align-items:center;gap:4px;font-size:11.5px;cursor:pointer" title="Mark linked models as NEW">
                    <input type="checkbox" name="mark_new_on_update" value="1" checked> NEW
                  </label>
                  <button class="sm" type="submit">Update DA</button>
                </form>
              </td>
              <td style="text-align:right">
                <div class="right-actions">
                  <?php if ($count > 0): ?>
                    <form method="post" action="<?= h(admin_url('assets')) ?>" style="margin:0">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="variant_toggle_new">
                      <input type="hidden" name="da_variant" value="<?= h((string)$variant) ?>">
                      <input type="hidden" name="set_new" value="<?= $newCount < $count ? '1' : '0' ?>">
                      <button class="sm <?= $newCount < $count ? 'good' : 'ghost' ?>" type="submit">
                        <?= $newCount < $count ? 'Mark NEW' : 'Clear NEW' ?>
                      </button>
                    </form>
                  <?php endif; ?>

                  <form method="post" action="<?= h(admin_url('assets')) ?>" style="margin:0;display:inline-flex;align-items:center;gap:5px" onsubmit="return confirm('Delete old DA file <?= h(addslashes((string)$meta['file'])) ?> from server?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_da_file">
                    <input type="hidden" name="da_variant" value="<?= h((string)$variant) ?>">
                    <?php if ($count > 0): ?>
                      <label style="display:inline-flex;align-items:center;gap:3px;font-size:11px;cursor:pointer" class="muted" title="Also delete the <?= $count ?> model(s) linked to this DA">
                        <input type="checkbox" name="delete_linked_models" value="1"> +Models
                      </label>
                    <?php endif; ?>
                    <button class="danger sm" type="submit">Delete DA</button>
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

<!-- Card 4: Configured Models Catalog (Filter by Realme / Oppo / OnePlus / NEW, Edit, Toggle NEW, Delete) -->
<div class="card">
  <h2>
    <span>4. Configured Models Catalog (<?= count($filteredModels) ?> of <?= count($models) ?>)</span>
    <form method="get" action="<?= h(admin_url('assets')) ?>" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0">
      <select name="brand" onchange="this.form.submit()" style="min-width:150px">
        <option value="all" <?= $filterBrand === 'all' ? 'selected' : '' ?>>All Brands (<?= count($models) ?>)</option>
        <option value="new" <?= $filterBrand === 'new' ? 'selected' : '' ?>>&#x2728; NEW Models Only (<?= (int)$assets['new_models_count'] ?>)</option>
        <option value="Realme" <?= strcasecmp($filterBrand, 'Realme') === 0 ? 'selected' : '' ?>>Realme</option>
        <option value="Oppo" <?= strcasecmp($filterBrand, 'Oppo') === 0 ? 'selected' : '' ?>>Oppo</option>
        <option value="OnePlus" <?= strcasecmp($filterBrand, 'OnePlus') === 0 ? 'selected' : '' ?>>OnePlus</option>
      </select>
      <input type="text" name="q" value="<?= h($filterSearch) ?>" placeholder="Search model, RMX, CPH, DA..." style="min-width:200px">
      <button class="sm" type="submit">Filter</button>
      <?php if ($filterBrand !== 'all' || $filterSearch !== ''): ?>
        <a class="btn ghost sm" href="<?= h(admin_url('assets')) ?>">Reset</a>
      <?php endif; ?>
    </form>
  </h2>

  <?php if (empty($filteredModels)): ?>
    <div class="muted">No models match the current filter.</div>
  <?php else: ?>
    <div class="table-wrap table-scroll">
      <table>
        <thead>
          <tr>
            <th style="width:45px">#</th>
            <th>Model Name (App &amp; Website)</th>
            <th>Brand</th>
            <th>Website Badge</th>
            <th>DA Binary (Admin/App Only)</th>
            <th>Operations</th>
            <th style="text-align:right">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($filteredModels as $i => $m):
            $variant = (string)$m['da_variant'];
            $exists  = isset($daFiles[$variant]);
            $brand   = (string)($m['brand'] ?? 'Realme');
            $isNew   = !empty($m['is_new']);
            $isEdit  = ($editModelKey !== '' && strcasecmp($editModelKey, (string)$m['display']) === 0);
          ?>
            <?php if ($isEdit): ?>
              <tr style="background:#f0f6ff">
                <td class="mono"><?= $i + 1 ?></td>
                <td colspan="6">
                  <form method="post" action="<?= h(admin_url('assets')) ?>" class="row" style="align-items:flex-end">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="update_model_single">
                    <input type="hidden" name="old_display" value="<?= h((string)$m['display']) ?>">
                    <div class="field" style="flex:2;min-width:200px">
                      <label>Model Display Name</label>
                      <input type="text" name="new_display" value="<?= h((string)$m['display']) ?>" required style="width:100%">
                    </div>
                    <div class="field" style="min-width:130px">
                      <label>Brand</label>
                      <select name="brand">
                        <option value="Realme" <?= $brand === 'Realme' ? 'selected' : '' ?>>Realme</option>
                        <option value="Oppo" <?= $brand === 'Oppo' ? 'selected' : '' ?>>Oppo</option>
                        <option value="OnePlus" <?= $brand === 'OnePlus' ? 'selected' : '' ?>>OnePlus</option>
                      </select>
                    </div>
                    <div class="field" style="min-width:170px">
                      <label>DA Variant</label>
                      <select name="da_variant">
                        <?php foreach ($daFiles as $vKey => $vMeta): ?>
                          <option value="<?= h((string)$vKey) ?>" <?= strcasecmp((string)$vKey, $variant) === 0 ? 'selected' : '' ?>><?= h((string)$vKey) ?></option>
                        <?php endforeach; ?>
                        <?php if (!isset($daFiles[$variant])): ?>
                          <option value="<?= h($variant) ?>" selected><?= h($variant) ?> (missing)</option>
                        <?php endif; ?>
                      </select>
                    </div>
                    <div class="field" style="flex:1;min-width:180px">
                      <label>Operations</label>
                      <input type="text" name="operations" value="<?= h((string)($m['operations'] ?? 'Format Data · Erase FRP · Unlock')) ?>" style="width:100%">
                    </div>
                    <div style="display:flex;align-items:center;gap:8px">
                      <label style="display:inline-flex;align-items:center;gap:4px;cursor:pointer">
                        <input type="checkbox" name="is_new" value="1" <?= $isNew ? 'checked' : '' ?>> NEW
                      </label>
                      <button class="good sm" type="submit">Save</button>
                      <a class="btn ghost sm" href="<?= h(admin_url('assets', ['brand' => $filterBrand, 'q' => $filterSearch])) ?>">Cancel</a>
                    </div>
                  </form>
                </td>
              </tr>
            <?php else: ?>
              <tr>
                <td class="mono muted"><?= $i + 1 ?></td>
                <td>
                  <strong><?= h((string)$m['display']) ?></strong>
                  <?php if ($isNew): ?>
                    <span class="badge b-valid" style="margin-left:6px">NEW</span>
                  <?php endif; ?>
                </td>
                <td>
                  <span class="badge <?= strtolower($brand) === 'oppo' ? 'b-valid' : (strtolower($brand) === 'oneplus' ? 'b-expired' : 'b-used') ?>">
                    <?= h($brand) ?>
                  </span>
                </td>
                <td>
                  <form method="post" action="<?= h(admin_url('assets')) ?>" style="margin:0">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_model_new">
                    <input type="hidden" name="display" value="<?= h((string)$m['display']) ?>">
                    <input type="hidden" name="set_new" value="<?= $isNew ? '0' : '1' ?>">
                    <button class="sm <?= $isNew ? 'good' : 'ghost' ?>" type="submit">
                      <?= $isNew ? '★ NEW (Active)' : '☆ Set as NEW' ?>
                    </button>
                  </form>
                </td>
                <td class="mono">
                  <?= h($variant) ?>.qc
                  <?php if (!$exists): ?>
                    <span class="badge b-inactive" style="margin-left:4px">Missing File</span>
                  <?php endif; ?>
                </td>
                <td class="muted"><?= h((string)($m['operations'] ?? 'Format Data · Erase FRP · Unlock')) ?></td>
                <td style="text-align:right">
                  <div class="right-actions">
                    <a class="btn ghost sm" href="<?= h(admin_url('assets', ['edit' => (string)$m['display'], 'brand' => $filterBrand, 'q' => $filterSearch])) ?>">Edit</a>
                    <form method="post" action="<?= h(admin_url('assets')) ?>" style="margin:0" onsubmit="return confirm('Delete model <?= h(addslashes((string)$m['display'])) ?>?');">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="delete_model">
                      <input type="hidden" name="display" value="<?= h((string)$m['display']) ?>">
                      <button class="danger sm" type="submit">Delete</button>
                    </form>
                  </div>
                </td>
              </tr>
            <?php endif; ?>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php
$contentHtml = (string)ob_get_clean();
admin_render_layout($config, 'assets', 'DA assets', $contentHtml, $dbError);
