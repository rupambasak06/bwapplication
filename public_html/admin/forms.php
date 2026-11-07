<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/form-renderer.php';

auth_require();

$currentPage = 'forms.php';
$page_title  = 'Application Forms';

$errors = [];

if (is_post()) {
    csrf_require();
    $do = (string)post('do', '');

    if ($do === 'create') {
        $sectionId = post_int('academic_section_id', 0);
        $title     = (string)post('title', '');
        $desc      = (string)post('description', '');

        $v = new Validator(['academic_section_id' => $sectionId, 'title' => $title]);
        $v->required('academic_section_id', 'Academic section')
          ->required('title', 'Form title')
          ->min('title', 2, 'Form title')
          ->max('title', 200, 'Form title');
        $errors = $v->errors();

        if (empty($errors)) {
            $stmt = db()->prepare('SELECT 1 FROM academic_sections WHERE id = ? AND is_archived = 0 LIMIT 1');
            $stmt->execute([$sectionId]);
            if (!$stmt->fetchColumn()) $errors['academic_section_id'] = 'Invalid academic section.';
        }

        if (empty($errors)) {
            $stmt = db()->prepare(
                'SELECT id FROM application_forms WHERE academic_section_id = ?
                 AND status IN ("draft","published") LIMIT 1'
            );
            $stmt->execute([$sectionId]);
            if ($stmt->fetchColumn()) {
                $errors['general'] = 'A form already exists for this academic section. Edit the existing one instead.';
            }
        }

        if (empty($errors)) {
            try {
                $pdo = db();
                $pdo->beginTransaction();

                $pdo->prepare(
                    'INSERT INTO application_forms (academic_section_id, title, description, status, created_at, updated_at)
                     VALUES (?, ?, ?, "draft", NOW(), NOW())'
                )->execute([$sectionId, $title, $desc !== '' ? $desc : null]);
                $newFormId = (int)$pdo->lastInsertId();

                $defaults = bw_default_form_fields();
                $stmtField = $pdo->prepare(
                    'INSERT INTO form_fields
                        (form_id, field_key, label, field_type, placeholder, help_text,
                         is_required, min_length, max_length, options_json, max_file_kb,
                         display_order, is_active, is_system, created_at, updated_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW(), NOW())'
                );
                $order = 0;
                foreach ($defaults as $d) {
                    $order++;
                    $stmtField->execute([
                        $newFormId,
                        $d['field_key'],
                        $d['label'],
                        $d['field_type'],
                        $d['placeholder'] ?? null,
                        $d['help_text'] ?? null,
                        $d['is_required'] ?? 0,
                        $d['min_length'] ?? null,
                        $d['max_length'] ?? null,
                        isset($d['options_json']) ? json_encode($d['options_json']) : null,
                        $d['max_file_kb'] ?? null,
                        $order,
                        $d['is_system'] ?? 0,
                    ]);
                }

                $pdo->commit();
                log_activity('form.created', 'application_form', $newFormId);
                flash_set('success', 'Application form created with default fields.');
                redirect('/admin/form-builder.php?id=' . $newFormId);
            } catch (Throwable $e) {
                if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
                error_log('[form create] ' . $e->getMessage());
                $errors['general'] = 'Could not create the form. Please try again.';
            }
        }
    } elseif ($do === 'publish') {
        $id = post_int('id', 0);
        try {
            bw_publish_form($id);
            flash_set('success', 'Form published successfully.');
        } catch (Throwable $e) {
            flash_set('error', $e->getMessage());
        }
        redirect('/admin/forms.php');
    } elseif ($do === 'unpublish') {
        $id = post_int('id', 0);
        db()->prepare('UPDATE application_forms SET status = "draft", updated_at = NOW() WHERE id = ? AND status = "published"')
            ->execute([$id]);
        log_activity('form.unpublished', 'application_form', $id);
        flash_set('success', 'Form unpublished.');
        redirect('/admin/forms.php');
    } elseif ($do === 'delete') {
        $id = post_int('id', 0);
        try {
            $stmt = db()->prepare(
                'SELECT COUNT(*) FROM applications WHERE form_id = ?'
            );
            $stmt->execute([$id]);
            $appCount = (int)$stmt->fetchColumn();
            if ($appCount > 0) {
                flash_set('error', 'This form has ' . $appCount . ' application(s). It cannot be deleted. Unpublish or archive it instead.');
            } else {
                db()->prepare('UPDATE application_forms SET status = "archived", updated_at = NOW() WHERE id = ?')
                    ->execute([$id]);
                log_activity('form.archived', 'application_form', $id);
                flash_set('success', 'Form archived.');
            }
        } catch (Throwable $e) {
            flash_set('error', 'Could not archive the form.');
        }
        redirect('/admin/forms.php');
    }
}

$forms = [];
try {
    $forms = db()->query(
        'SELECT f.*, s.name AS section_name, v.version_number,
                (SELECT COUNT(*) FROM applications WHERE form_id = f.id) AS app_count,
                (SELECT COUNT(*) FROM form_fields WHERE form_id = f.id AND is_active = 1) AS field_count
         FROM application_forms f
         JOIN academic_sections s ON s.id = f.academic_section_id
         LEFT JOIN form_versions v ON v.id = f.current_version_id
         WHERE f.status <> "archived"
         ORDER BY s.display_order, s.name'
    )->fetchAll();
} catch (Throwable $e) { /* ignore */ }

$sectionsWithoutForms = [];
try {
    $sectionsWithoutForms = db()->query(
        'SELECT s.id, s.name FROM academic_sections s
         WHERE s.is_archived = 0
           AND NOT EXISTS (SELECT 1 FROM application_forms f
                           WHERE f.academic_section_id = s.id AND f.status IN ("draft","published"))
         ORDER BY s.display_order, s.name'
    )->fetchAll();
} catch (Throwable $e) { /* ignore */ }

require __DIR__ . '/../../includes/admin-header.php';
?>

<div class="card">
  <div class="card-header">
    <div>
      <h2 class="font-semibold">Application Forms</h2>
      <p class="text-xs text-slate-500 mt-0.5">One form per academic section. Only published forms appear publicly.</p>
    </div>
  </div>

  <?php if (!empty($errors['general'])): ?>
    <div class="p-4"><div class="alert alert-error"><?= e($errors['general']) ?></div></div>
  <?php endif; ?>

  <?php if (empty($forms)): ?>
    <div class="p-8 text-center text-slate-500">
      No forms exist yet. Create one below.
    </div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Section</th>
            <th>Title</th>
            <th class="w-24">Fields</th>
            <th class="w-24">Applications</th>
            <th class="w-32">Status</th>
            <th class="w-72">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($forms as $f): ?>
            <tr>
              <td class="text-sm"><?= e($f['section_name']) ?></td>
              <td>
                <div class="font-medium"><?= e($f['title']) ?></div>
                <?php if (!empty($f['description'])): ?>
                  <div class="text-xs text-slate-500 truncate max-w-md mt-0.5"><?= e($f['description']) ?></div>
                <?php endif; ?>
              </td>
              <td class="text-sm"><?= (int)$f['field_count'] ?></td>
              <td class="text-sm"><?= (int)$f['app_count'] ?></td>
              <td>
                <?php if ($f['status'] === 'published'): ?>
                  <span class="badge badge-success">Published</span>
                  <?php if (!empty($f['version_number'])): ?>
                    <div class="text-xs text-slate-500 mt-1">v<?= (int)$f['version_number'] ?></div>
                  <?php endif; ?>
                <?php else: ?>
                  <span class="badge badge-warning">Draft</span>
                <?php endif; ?>
              </td>
              <td>
                <div class="flex flex-wrap items-center gap-2">
                  <a href="<?= e(absolute_url('/admin/form-builder.php?id=' . (int)$f['id'])) ?>"
                     class="btn btn-sm btn-secondary">Edit</a>
                  <?php if ($f['status'] === 'published'): ?>
                    <a href="<?= e(absolute_url('/apply.php?section_id=' . (int)$f['academic_section_id'])) ?>"
                       target="_blank" rel="noopener"
                       class="btn btn-sm btn-secondary">Preview</a>
                    <form method="post" class="inline" data-confirm="Unpublish this form? It will no longer be visible to applicants.">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="unpublish">
                      <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-secondary">Unpublish</button>
                    </form>
                  <?php else: ?>
                    <form method="post" class="inline">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="publish">
                      <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-primary">Publish</button>
                    </form>
                    <form method="post" class="inline" data-confirm="Archive this draft form?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="do" value="delete">
                      <input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
                      <button type="submit" class="btn btn-sm btn-danger">Delete</button>
                    </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>
</div>

<?php if (!empty($sectionsWithoutForms)): ?>
  <div class="card mt-6 max-w-2xl">
    <div class="card-header"><h2 class="font-semibold">Create a New Form</h2></div>
    <form method="post" class="card-body space-y-4">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="create">

      <div>
        <label class="form-label" for="academic_section_id">Academic Section <span class="text-red-600">*</span></label>
        <select id="academic_section_id" name="academic_section_id" required class="form-select">
          <option value="">Choose section…</option>
          <?php foreach ($sectionsWithoutForms as $s): ?>
            <option value="<?= (int)$s['id'] ?>"><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
        <?php if (!empty($errors['academic_section_id'])): ?><p class="form-error"><?= e($errors['academic_section_id']) ?></p><?php endif; ?>
      </div>

      <div>
        <label class="form-label" for="title">Form Title <span class="text-red-600">*</span></label>
        <input type="text" id="title" name="title" required maxlength="200"
               class="form-input" placeholder="e.g. Undergraduate Application 2025-26">
        <?php if (!empty($errors['title'])): ?><p class="form-error"><?= e($errors['title']) ?></p><?php endif; ?>
      </div>

      <div>
        <label class="form-label" for="description">Description</label>
        <textarea id="description" name="description" rows="2" maxlength="1000"
                  class="form-textarea"></textarea>
      </div>

      <div class="pt-3 border-t border-slate-200">
        <button type="submit" class="btn btn-primary">Create Form</button>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>