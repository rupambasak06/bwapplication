<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/form-renderer.php';

auth_require();

$currentPage = 'forms.php';
$page_title  = 'Form Builder';

$formId = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
if ($formId <= 0) { flash_set('error', 'Invalid form.'); redirect('/admin/forms.php'); }

$stmt = db()->prepare(
    'SELECT f.*, s.name AS section_name FROM application_forms f
     JOIN academic_sections s ON s.id = f.academic_section_id
     WHERE f.id = ? LIMIT 1'
);
$stmt->execute([$formId]);
$form = $stmt->fetch();
if (!$form) { flash_set('error', 'Form not found.'); redirect('/admin/forms.php'); }

$errors = [];

// POST handlers
if (is_post()) {
    csrf_require();
    $do = (string)post('do', '');

    if ($do === 'save_fields') {
        $fieldsPost = $_POST['fields'] ?? [];
        if (!is_array($fieldsPost)) $fieldsPost = [];

        // Fetch existing system field info
        $stmt = db()->prepare('SELECT id, field_key, is_system FROM form_fields WHERE form_id = ?');
        $stmt->execute([$formId]);
        $existingById = [];
        foreach ($stmt->fetchAll() as $r) $existingById[(int)$r['id']] = $r;

        $processed = [];
        $seenKeys  = [];
        foreach ($fieldsPost as $idx => $f) {
            if (!is_array($f)) continue;
            $fid  = (int)($f['id'] ?? 0);
            $key  = trim((string)($f['field_key'] ?? ''));
            $lbl  = trim((string)($f['label'] ?? ''));
            $type = (string)($f['field_type'] ?? 'text');

            if ($lbl === '') { $errors[] = 'Every field must have a label.'; continue; }
            if ($key === '') {
                $key = preg_replace('/[^a-z0-9_]+/', '_', strtolower($lbl)) ?? 'field_' . random_hex(4);
                $key = trim($key, '_');
                if ($key === '') $key = 'field_' . random_hex(4);
            }
            $key = preg_replace('/[^a-z0-9_]/', '_', strtolower($key)) ?? $key;

            if (isset($seenKeys[$key])) {
                $errors[] = 'Duplicate field key: ' . $key;
                continue;
            }
            $seenKeys[$key] = true;

            // Convert options
            $optsText = (string)($f['options_text'] ?? '');
            $optionsJson = null;
            if (in_array($type, ['select', 'radio', 'checkbox'], true)) {
                $lines = preg_split('/\r\n|\r|\n/', $optsText) ?: [];
                $opts = array_values(array_filter(array_map('trim', $lines), fn($v) => $v !== ''));
                $optionsJson = !empty($opts) ? json_encode($opts, JSON_UNESCAPED_UNICODE) : null;
            } elseif ($type === 'document_upload') {
                $parts = preg_split('/[,\s]+/', $optsText) ?: [];
                $parts = array_values(array_filter(array_map(fn($v) => strtolower(trim($v)), $parts), fn($v) => $v !== ''));
                $allowed = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
                $parts = array_values(array_intersect($parts, $allowed));
                if (empty($parts)) $parts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
                $optionsJson = json_encode($parts);
            }

            $isSystem = $fid > 0 && isset($existingById[$fid]) && (int)$existingById[$fid]['is_system'] === 1;

            $processed[] = [
                'id'            => $fid,
                'field_key'     => $key,
                'label'         => $lbl,
                'field_type'    => $type,
                'placeholder'   => trim((string)($f['placeholder'] ?? '')) ?: null,
                'help_text'     => trim((string)($f['help_text'] ?? '')) ?: null,
                'is_required'   => !empty($f['is_required']) ? 1 : 0,
                'min_length'    => (isset($f['min_length']) && $f['min_length'] !== '') ? (int)$f['min_length'] : null,
                'max_length'    => (isset($f['max_length']) && $f['max_length'] !== '') ? (int)$f['max_length'] : null,
                'options_json'  => $optionsJson,
                'max_file_kb'   => (isset($f['max_file_kb']) && $f['max_file_kb'] !== '') ? (int)$f['max_file_kb'] : null,
                'display_order' => (int)($f['display_order'] ?? 0),
                'is_active'     => 1,
                'is_system'     => $isSystem ? 1 : 0,
            ];
        }

        // Identify fields removed (present in DB but not in POST)
        $submittedIds = array_filter(array_column($processed, 'id'));
        $toDelete = [];
        foreach ($existingById as $eid => $info) {
            if (!in_array($eid, $submittedIds, true)) {
                if ((int)$info['is_system'] === 1) {
                    $errors[] = 'System field "' . $info['field_key'] . '" cannot be removed.';
                } else {
                    $toDelete[] = $eid;
                }
            }
        }

        if (empty($errors)) {
            try {
                $pdo = db();
                $pdo->beginTransaction();

                // Soft delete removed fields
                if (!empty($toDelete)) {
                    $ph = implode(',', array_fill(0, count($toDelete), '?'));
                    $pdo->prepare("UPDATE form_fields SET is_active = 0, updated_at = NOW() WHERE id IN ($ph)")
                        ->execute(array_values($toDelete));
                }

                $update = $pdo->prepare(
                    'UPDATE form_fields SET
                        field_key = ?, label = ?, field_type = ?, placeholder = ?, help_text = ?,
                        is_required = ?, min_length = ?, max_length = ?, options_json = ?,
                        max_file_kb = ?, display_order = ?, is_active = 1, updated_at = NOW()
                     WHERE id = ? AND form_id = ?'
                );
                $insert = $pdo->prepare(
                    'INSERT INTO form_fields
                        (form_id, field_key, label, field_type, placeholder, help_text,
                         is_required, min_length, max_length, options_json, max_file_kb,
                         display_order, is_active, is_system, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())'
                );

                $order = 0;
                foreach ($processed as $p) {
                    $order++;
                    $p['display_order'] = $order;
                    if ($p['id'] > 0 && isset($existingById[$p['id']])) {
                        $update->execute([
                            $p['field_key'], $p['label'], $p['field_type'], $p['placeholder'], $p['help_text'],
                            $p['is_required'], $p['min_length'], $p['max_length'], $p['options_json'],
                            $p['max_file_kb'], $p['display_order'], $p['id'], $formId,
                        ]);
                    } else {
                        $insert->execute([
                            $formId, $p['field_key'], $p['label'], $p['field_type'], $p['placeholder'],
                            $p['help_text'], $p['is_required'], $p['min_length'], $p['max_length'],
                            $p['options_json'], $p['max_file_kb'], $p['display_order'], $p['is_system'],
                        ]);
                    }
                }

                // Unpublish form if fields changed and it was published
                $wasPublished = $form['status'] === 'published';
                if ($wasPublished) {
                    $pdo->prepare('UPDATE application_forms SET status = "draft", updated_at = NOW() WHERE id = ?')
                        ->execute([$formId]);
                }

                $pdo->commit();
                log_activity('form.fields_saved', 'application_form', $formId);
                flash_set('success', 'Fields saved.' . ($wasPublished ? ' The form was unpublished because fields changed. Republish when ready.' : ''));
                redirect('/admin/form-builder.php?id=' . $formId);
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                error_log('[form-builder save] ' . $e->getMessage());
                $errors[] = 'Could not save fields. Please try again.';
            }
        }
    } elseif ($do === 'publish') {
        try {
            bw_publish_form($formId);
            flash_set('success', 'Form published.');
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        redirect('/admin/form-builder.php?id=' . $formId);
    } elseif ($do === 'unpublish') {
        db()->prepare('UPDATE application_forms SET status = "draft", updated_at = NOW() WHERE id = ?')
            ->execute([$formId]);
        log_activity('form.unpublished', 'application_form', $formId);
        flash_set('success', 'Form unpublished.');
        redirect('/admin/form-builder.php?id=' . $formId);
    }
}

// Load current fields
$stmt = db()->prepare(
    'SELECT * FROM form_fields WHERE form_id = ? AND is_active = 1 ORDER BY display_order, id'
);
$stmt->execute([$formId]);
$fields = $stmt->fetchAll();

require __DIR__ . '/../../includes/admin-header.php';
?>

<div class="flex flex-wrap items-center justify-between gap-2 mb-4">
  <div>
    <a href="<?= e(absolute_url('/admin/forms.php')) ?>" class="text-sm text-slate-600 hover:text-indigo-900">← Back to Forms</a>
    <h2 class="text-xl font-semibold mt-1"><?= e($form['title']) ?></h2>
    <div class="text-xs text-slate-500 mt-1">
      <?= e($form['section_name']) ?> ·
      <?= $form['status'] === 'published' ? '<span class="badge badge-success">Published</span>' : '<span class="badge badge-warning">Draft</span>' ?>
    </div>
  </div>
  <div class="flex flex-wrap gap-2">
    <?php if ($form['status'] === 'published'): ?>
      <a href="<?= e(absolute_url('/apply.php?section_id=' . (int)$form['academic_section_id'])) ?>"
         target="_blank" rel="noopener" class="btn btn-secondary">Preview</a>
      <form method="post" class="inline" data-confirm="Unpublish this form? Applicants will not be able to submit until it is republished.">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="unpublish">
        <button type="submit" class="btn btn-secondary">Unpublish</button>
      </form>
    <?php else: ?>
      <form method="post" class="inline">
        <?= csrf_field() ?>
        <input type="hidden" name="do" value="publish">
        <button type="submit" class="btn btn-primary">Publish</button>
      </form>
    <?php endif; ?>
  </div>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-error mb-4">
    <ul class="list-disc pl-5 space-y-1">
      <?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<form method="post" id="form-builder-form">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save_fields">
  <input type="hidden" name="id" value="<?= $formId ?>">

  <div id="fields-container" class="space-y-3">
    <?php foreach ($fields as $idx => $f):
      $isSystem = (int)$f['is_system'] === 1;
      $type = $f['field_type'];
      $customTypes = ['text','textarea','email','tel','number','date','select','radio','checkbox','section_heading','info_text','document_upload'];
      $options = [];
      if (!empty($f['options_json'])) {
          $decoded = json_decode((string)$f['options_json'], true);
          if (is_array($decoded)) $options = $decoded;
      }
      $optionsText = '';
      if (in_array($type, ['select', 'radio', 'checkbox'], true)) $optionsText = implode("\n", $options);
      if ($type === 'document_upload') $optionsText = implode(',', $options ?: ['jpg','jpeg','png','webp','pdf']);
    ?>
      <div class="field-card" data-field-card>
        <input type="hidden" name="fields[<?= $idx ?>][id]" value="<?= (int)$f['id'] ?>">
        <input type="hidden" name="fields[<?= $idx ?>][display_order]" value="<?= (int)$f['display_order'] ?>" data-order-input>

        <div class="field-card-header">
          <div class="flex items-center gap-2 flex-wrap">
            <span class="badge badge-neutral"><?= e(strtoupper(str_replace('_', ' ', $type))) ?></span>
            <?php if ($isSystem): ?><span class="badge badge-info">System</span><?php endif; ?>
            <span class="text-xs text-slate-500"><?= e($f['field_key']) ?></span>
          </div>
          <div class="flex items-center gap-1">
            <?php if (!$isSystem): ?>
              <button type="button" class="btn-icon" data-move="up" title="Move up">▲</button>
              <button type="button" class="btn-icon" data-move="down" title="Move down">▼</button>
              <button type="button" class="btn-icon btn-icon-danger" data-remove-field title="Remove">✕</button>
            <?php endif; ?>
          </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
          <div>
            <label class="form-label">Label</label>
            <input type="text" name="fields[<?= $idx ?>][label]" value="<?= e($f['label']) ?>"
                   required maxlength="200" class="form-input">
          </div>
          <div>
            <label class="form-label">Field Key</label>
            <input type="text" name="fields[<?= $idx ?>][field_key]" value="<?= e($f['field_key']) ?>"
                   <?= $isSystem ? 'readonly' : '' ?> maxlength="80"
                   class="form-input <?= $isSystem ? 'bg-slate-100' : '' ?>">
          </div>
          <div>
            <label class="form-label">Type</label>
            <?php if ($isSystem): ?>
              <input type="text" value="<?= e($type) ?>" readonly class="form-input bg-slate-100">
              <input type="hidden" name="fields[<?= $idx ?>][field_type]" value="<?= e($type) ?>">
            <?php else: ?>
              <select name="fields[<?= $idx ?>][field_type]" class="form-select">
                <?php foreach (bw_field_type_options() as $v => $l): ?>
                  <option value="<?= e($v) ?>" <?= $type === $v ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
              </select>
            <?php endif; ?>
          </div>
          <div>
            <label class="form-label">Placeholder</label>
            <input type="text" name="fields[<?= $idx ?>][placeholder]" value="<?= e($f['placeholder'] ?? '') ?>"
                   maxlength="255" class="form-input">
          </div>
          <div class="sm:col-span-2">
            <label class="form-label">Help Text</label>
            <input type="text" name="fields[<?= $idx ?>][help_text]" value="<?= e($f['help_text'] ?? '') ?>"
                   maxlength="500" class="form-input">
          </div>
          <div>
            <label class="inline-flex items-center gap-2 mt-5">
              <input type="checkbox" name="fields[<?= $idx ?>][is_required]" value="1"
                     <?= (int)$f['is_required'] === 1 ? 'checked' : '' ?> class="rounded border-slate-300">
              <span class="text-sm">Required</span>
            </label>
          </div>
          <div></div>
          <div>
            <label class="form-label">Min Length</label>
            <input type="number" name="fields[<?= $idx ?>][min_length]" value="<?= $f['min_length'] !== null ? (int)$f['min_length'] : '' ?>"
                   min="0" max="10000" class="form-input">
          </div>
          <div>
            <label class="form-label">Max Length</label>
            <input type="number" name="fields[<?= $idx ?>][max_length]" value="<?= $f['max_length'] !== null ? (int)$f['max_length'] : '' ?>"
                   min="0" max="10000" class="form-input">
          </div>
          <?php if (in_array($type, ['select', 'radio', 'checkbox'], true)): ?>
            <div class="sm:col-span-2">
              <label class="form-label">Options (one per line)</label>
              <textarea name="fields[<?= $idx ?>][options_text]" rows="4" class="form-textarea"
                        placeholder="Option 1&#10;Option 2&#10;Option 3"><?= e($optionsText) ?></textarea>
            </div>
          <?php endif; ?>
          <?php if ($type === 'document_upload'): ?>
            <div class="sm:col-span-2">
              <label class="form-label">Accepted File Types</label>
              <input type="text" name="fields[<?= $idx ?>][options_text]" value="<?= e($optionsText) ?>"
                     placeholder="jpg,png,pdf" class="form-input">
              <p class="form-help">Comma-separated extensions. Images are compressed to 100 KB or below.</p>
            </div>
            <div>
              <label class="form-label">Max File Size (KB)</label>
              <input type="number" name="fields[<?= $idx ?>][max_file_kb]" value="<?= $f['max_file_kb'] !== null ? (int)$f['max_file_kb'] : 102400 ?>"
                     min="0" max="512000" class="form-input">
            </div>
          <?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="flex flex-wrap gap-2 mt-4">
    <button type="button" id="add-field-btn" class="btn btn-secondary">+ Add Field</button>
    <button type="submit" class="btn btn-primary">Save Fields</button>
    <a href="<?= e(absolute_url('/admin/forms.php')) ?>" class="btn btn-secondary">Cancel</a>
  </div>
</form>

<template id="field-template">
  <div class="field-card" data-field-card>
    <input type="hidden" name="fields[__IDX__][id]" value="0">
    <input type="hidden" name="fields[__IDX__][display_order]" value="0" data-order-input>
    <div class="field-card-header">
      <div class="flex items-center gap-2 flex-wrap">
        <span class="badge badge-neutral">NEW</span>
      </div>
      <div class="flex items-center gap-1">
        <button type="button" class="btn-icon" data-move="up" title="Move up">▲</button>
        <button type="button" class="btn-icon" data-move="down" title="Move down">▼</button>
        <button type="button" class="btn-icon btn-icon-danger" data-remove-field title="Remove">✕</button>
      </div>
    </div>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mt-3">
      <div>
        <label class="form-label">Label</label>
        <input type="text" name="fields[__IDX__][label]" required maxlength="200" class="form-input">
      </div>
      <div>
        <label class="form-label">Field Key</label>
        <input type="text" name="fields[__IDX__][field_key]" maxlength="80" placeholder="auto from label" class="form-input">
      </div>
      <div>
        <label class="form-label">Type</label>
        <select name="fields[__IDX__][field_type]" class="form-select">
          <?php foreach (bw_field_type_options() as $v => $l): ?>
            <option value="<?= e($v) ?>"><?= e($l) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="form-label">Placeholder</label>
        <input type="text" name="fields[__IDX__][placeholder]" maxlength="255" class="form-input">
      </div>
      <div class="sm:col-span-2">
        <label class="form-label">Help Text</label>
        <input type="text" name="fields[__IDX__][help_text]" maxlength="500" class="form-input">
      </div>
      <div>
        <label class="inline-flex items-center gap-2 mt-5">
          <input type="checkbox" name="fields[__IDX__][is_required]" value="1" class="rounded border-slate-300">
          <span class="text-sm">Required</span>
        </label>
      </div>
    </div>
  </div>
</template>

<script src="<?= e(absolute_url('/assets/js/form-builder.js')) ?>"></script>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>