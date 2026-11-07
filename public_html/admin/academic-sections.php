<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/upload-handler.php';

auth_require();

$currentPage = 'academic-sections.php';
$page_title  = 'Academic Sections';

$errors = [];
$form = [
    'id'            => 0,
    'name'          => '',
    'description'   => '',
    'image_path'    => '',
    'display_order' => 0,
    'is_active'     => 1,
];

$action = (string)($_GET['action'] ?? 'list');
if (!in_array($action, ['list', 'new', 'edit'], true)) {
    $action = 'list';
}

/* ---------- Helpers local to this page ---------- */
function make_section_slug(string $text, ?int $excludeId = null): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') $slug = 'section';

    $base = $slug;
    $n    = 1;
    while ($n < 500) {
        $stmt = db()->prepare('SELECT 1 FROM academic_sections WHERE slug = ? AND id != ? LIMIT 1');
        $stmt->execute([$slug, $excludeId ?? 0]);
        if (!$stmt->fetchColumn()) return $slug;
        $n++;
        $slug = $base . '-' . $n;
    }
    throw new RuntimeException('Could not generate a unique section slug.');
}

/* ---------- POST handling ---------- */
if (is_post()) {
    csrf_require();
    $do = (string)post('do', '');

    if ($do === 'save') {
        $id = post_int('id', 0);

        $form = [
            'id'            => $id,
            'name'          => (string)post('name', ''),
            'description'   => (string)post('description', ''),
            'image_path'    => (string)post('existing_image', ''),
            'display_order' => post_int('display_order', 0),
            'is_active'     => post('is_active') ? 1 : 0,
        ];

        $v = new Validator([
            'name'          => $form['name'],
            'display_order' => $form['display_order'],
        ]);
        $v->required('name', 'Section name')
          ->min('name', 2, 'Section name')
          ->max('name', 150, 'Section name')
          ->integer('display_order', 'Display order');
        $errors = $v->errors();

        if (post('remove_image')) {
            if ($form['image_path']) {
                delete_stored_file($form['image_path']);
            }
            $form['image_path'] = '';
        }

        if (empty($errors)
            && isset($_FILES['image'])
            && is_array($_FILES['image'])
            && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        ) {
            try {
                $result = process_image_upload(
                    $_FILES['image'],
                    BW_STORAGE_DIR . '/uploads/academic-sections',
                    102400
                );
                if ($form['image_path']) {
                    delete_stored_file($form['image_path']);
                }
                $form['image_path'] = $result['stored_path'];
            } catch (UploadError $e) {
                $errors['image'] = $e->getMessage();
            } catch (Throwable $e) {
                error_log('[section upload] ' . $e->getMessage());
                $errors['image'] = 'Image upload failed. Please try again.';
            }
        }

        if (empty($errors)) {
            try {
                $slug = make_section_slug($form['name'], $id ?: null);

                if ($id > 0) {
                    $stmt = db()->prepare(
                        'UPDATE academic_sections
                         SET name = ?, slug = ?, description = ?, image_path = ?, display_order = ?, is_active = ?, updated_at = NOW()
                         WHERE id = ?'
                    );
                    $stmt->execute([
                        $form['name'],
                        $slug,
                        $form['description'] !== '' ? $form['description'] : null,
                        $form['image_path'] !== '' ? $form['image_path'] : null,
                        $form['display_order'],
                        $form['is_active'],
                        $id,
                    ]);
                    log_activity('section.updated', 'academic_section', $id, ['name' => $form['name']]);
                    flash_set('success', 'Academic section updated.');
                } else {
                    $stmt = db()->prepare(
                        'INSERT INTO academic_sections
                         (name, slug, description, image_path, display_order, is_active, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
                    );
                    $stmt->execute([
                        $form['name'],
                        $slug,
                        $form['description'] !== '' ? $form['description'] : null,
                        $form['image_path'] !== '' ? $form['image_path'] : null,
                        $form['display_order'],
                        $form['is_active'],
                    ]);
                    $newId = (int)db()->lastInsertId();
                    log_activity('section.created', 'academic_section', $newId, ['name' => $form['name']]);
                    flash_set('success', 'Academic section created.');
                }
                redirect('/admin/academic-sections.php');
            } catch (Throwable $e) {
                error_log('[section save] ' . $e->getMessage());
                $errors['general'] = 'Could not save the academic section. Please try again.';
            }
        }

        if (!empty($errors)) {
            $action = $id > 0 ? 'edit' : 'new';
        }
    } elseif ($do === 'toggle') {
        $id = post_int('id', 0);
        if ($id > 0) {
            db()->prepare('UPDATE academic_sections SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?')
                ->execute([$id]);
            log_activity('section.toggled', 'academic_section', $id);
            flash_set('success', 'Status updated.');
        }
        redirect('/admin/academic-sections.php');
    } elseif ($do === 'archive') {
        $id = post_int('id', 0);
        if ($id > 0) {
            db()->prepare('UPDATE academic_sections SET is_archived = 1, updated_at = NOW() WHERE id = ?')
                ->execute([$id]);
            log_activity('section.archived', 'academic_section', $id);
            flash_set('success', 'Academic section archived.');
        }
        redirect('/admin/academic-sections.php');
    } elseif ($do === 'unarchive') {
        $id = post_int('id', 0);
        if ($id > 0) {
            db()->prepare('UPDATE academic_sections SET is_archived = 0, updated_at = NOW() WHERE id = ?')
                ->execute([$id]);
            log_activity('section.unarchived', 'academic_section', $id);
            flash_set('success', 'Academic section restored.');
        }
        redirect('/admin/academic-sections.php');
    }
}

/* ---------- Load record for edit ---------- */
if ($action === 'edit' && (int)$form['id'] === 0) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        flash_set('error', 'Invalid academic section.');
        redirect('/admin/academic-sections.php');
    }
    $stmt = db()->prepare('SELECT * FROM academic_sections WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'Academic section not found.');
        redirect('/admin/academic-sections.php');
    }
    $form = [
        'id'            => (int)$row['id'],
        'name'          => (string)$row['name'],
        'description'   => (string)($row['description'] ?? ''),
        'image_path'    => (string)($row['image_path'] ?? ''),
        'display_order' => (int)$row['display_order'],
        'is_active'     => (int)$row['is_active'],
    ];
}

/* ---------- List data ---------- */
$list         = [];
$total        = 0;
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = max(1, min(100, (int)app_setting('pagination_size', '20')));
$q            = trim((string)($_GET['q'] ?? ''));
$statusFilter = (string)($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'active', 'inactive', 'archived'], true)) {
    $statusFilter = 'all';
}

if ($action === 'list') {
    $where  = [];
    $params = [];

    if ($q !== '') {
        $where[]  = '(s.name LIKE ? OR s.description LIKE ?)';
        $like     = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
    }
    switch ($statusFilter) {
        case 'active':   $where[] = 's.is_active = 1 AND s.is_archived = 0'; break;
        case 'inactive': $where[] = 's.is_active = 0 AND s.is_archived = 0'; break;
        case 'archived': $where[] = 's.is_archived = 1'; break;
        default:         $where[] = 's.is_archived = 0'; break;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $countStmt = db()->prepare("SELECT COUNT(*) FROM academic_sections s $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare(
        "SELECT s.*,
                (SELECT COUNT(*) FROM institutions i
                 WHERE i.academic_section_id = s.id AND i.is_archived = 0) AS institution_count
         FROM academic_sections s
         $whereSql
         ORDER BY s.is_archived ASC, s.display_order ASC, s.name ASC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $list = $stmt->fetchAll();
}

require __DIR__ . '/../../includes/admin-header.php';
?>

<?php if ($action === 'list'): ?>

  <div class="card">
    <div class="card-header">
      <div>
        <h2 class="font-semibold">Academic Sections</h2>
        <p class="text-xs text-slate-500 mt-0.5">Top level of the hierarchy. Institutions belong to a section.</p>
      </div>
      <a href="<?= e(absolute_url('/admin/academic-sections.php?action=new')) ?>" class="btn btn-primary">+ New Section</a>
    </div>

    <form method="get" class="p-4 border-b border-slate-200 grid grid-cols-1 sm:grid-cols-3 gap-3">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search by name or description"
             class="form-input">
      <select name="status" class="form-select">
        <option value="all"      <?= $statusFilter === 'all'      ? 'selected' : '' ?>>Active &amp; Inactive</option>
        <option value="active"   <?= $statusFilter === 'active'   ? 'selected' : '' ?>>Active only</option>
        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive only</option>
        <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived</option>
      </select>
      <div class="flex gap-2">
        <button type="submit" class="btn btn-primary">Search</button>
        <a href="<?= e(absolute_url('/admin/academic-sections.php')) ?>" class="btn btn-secondary">Reset</a>
      </div>
    </form>

    <?php if (empty($list)): ?>
      <div class="p-8 text-center text-slate-500">
        <div class="text-lg font-medium text-slate-700 mb-1">No academic sections found</div>
        <p class="text-sm">Create the first section to get started.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead>
            <tr>
              <th class="w-16">Order</th>
              <th>Name</th>
              <th class="w-32">Institutions</th>
              <th class="w-32">Status</th>
              <th class="w-64">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($list as $row): ?>
              <tr>
                <td class="text-slate-500"><?= (int)$row['display_order'] ?></td>
                <td>
                  <div class="font-medium"><?= e($row['name']) ?></div>
                  <?php if (!empty($row['description'])): ?>
                    <div class="text-xs text-slate-500 truncate max-w-md mt-0.5"><?= e($row['description']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= (int)$row['institution_count'] ?></td>
                <td>
                  <?php if ((int)$row['is_archived'] === 1): ?>
                    <span class="badge badge-danger">Archived</span>
                  <?php elseif ((int)$row['is_active'] === 1): ?>
                    <span class="badge badge-success">Active</span>
                  <?php else: ?>
                    <span class="badge badge-warning">Inactive</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="flex flex-wrap items-center gap-2">
                    <?php if ((int)$row['is_archived'] === 0): ?>
                      <a href="<?= e(absolute_url('/admin/academic-sections.php?action=edit&id=' . (int)$row['id'])) ?>"
                         class="btn btn-sm btn-secondary">Edit</a>
                      <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-secondary">
                          <?= (int)$row['is_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                        </button>
                      </form>
                      <form method="post" class="inline"
                            data-confirm="Archive this section? It will be hidden from new selections. Existing institutions remain intact.">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="archive">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-danger">Archive</button>
                      </form>
                    <?php else: ?>
                      <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="unarchive">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-primary">Restore</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
        <div class="p-4 border-t border-slate-200 flex flex-wrap items-center justify-between gap-2">
          <div class="text-sm text-slate-500">
            Page <?= $page ?> of <?= $totalPages ?> &middot; <?= $total ?> total
          </div>
          <div class="flex gap-2">
            <?php if ($page > 1): ?>
              <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>"
                 class="btn btn-sm btn-secondary">Previous</a>
            <?php endif; ?>
            <?php if ($page < $totalPages): ?>
              <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>"
                 class="btn btn-sm btn-secondary">Next</a>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
  </div>

<?php else: ?>

  <div class="card max-w-3xl">
    <div class="card-header">
      <h2 class="font-semibold"><?= $form['id'] ? 'Edit Academic Section' : 'New Academic Section' ?></h2>
      <a href="<?= e(absolute_url('/admin/academic-sections.php')) ?>" class="btn btn-sm btn-secondary">Back</a>
    </div>

    <form method="post" enctype="multipart/form-data" class="card-body space-y-4" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
      <input type="hidden" name="existing_image" value="<?= e($form['image_path']) ?>">

      <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error"><?= e($errors['general']) ?></div>
      <?php endif; ?>

      <div>
        <label class="form-label" for="name">Section Name <span class="text-red-600">*</span></label>
        <input type="text" id="name" name="name" required minlength="2" maxlength="150"
               value="<?= e($form['name']) ?>" class="form-input">
        <?php if (!empty($errors['name'])): ?><p class="form-error"><?= e($errors['name']) ?></p><?php endif; ?>
      </div>

      <div>
        <label class="form-label" for="description">Description</label>
        <textarea id="description" name="description" rows="3" maxlength="2000"
                  class="form-textarea"><?= e($form['description']) ?></textarea>
      </div>

      <div>
        <label class="form-label" for="image">Section Image (optional)</label>
        <?php if ($form['image_path'] !== ''): ?>
          <div class="mb-3 flex items-center gap-3">
            <img src="<?= e(absolute_url('/download-asset.php?kind=section&id=' . (int)$form['id'])) ?>"
                 alt="" class="w-16 h-16 object-cover rounded border border-slate-200">
            <label class="inline-flex items-center gap-2 text-sm">
              <input type="checkbox" name="remove_image" value="1" class="rounded border-slate-300">
              <span>Remove current image</span>
            </label>
          </div>
        <?php endif; ?>
        <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp"
               class="form-input">
        <p class="form-help">JPEG, PNG, or WebP. Automatically compressed to 100 KB or less.</p>
        <?php if (!empty($errors['image'])): ?><p class="form-error"><?= e($errors['image']) ?></p><?php endif; ?>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
          <label class="form-label" for="display_order">Display Order</label>
          <input type="number" id="display_order" name="display_order" min="0" max="9999"
                 value="<?= (int)$form['display_order'] ?>" class="form-input">
          <p class="form-help">Lower numbers appear first.</p>
          <?php if (!empty($errors['display_order'])): ?><p class="form-error"><?= e($errors['display_order']) ?></p><?php endif; ?>
        </div>
        <div class="flex items-end pb-1">
          <label class="inline-flex items-center gap-2">
            <input type="checkbox" name="is_active" value="1"
                   <?= (int)$form['is_active'] === 1 ? 'checked' : '' ?>
                   class="rounded border-slate-300">
            <span class="text-sm">Active (visible to students)</span>
          </label>
        </div>
      </div>

      <div class="flex gap-2 pt-3 border-t border-slate-200">
        <button type="submit" class="btn btn-primary">
          <?= $form['id'] ? 'Save Changes' : 'Create Section' ?>
        </button>
        <a href="<?= e(absolute_url('/admin/academic-sections.php')) ?>" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>