<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/upload-handler.php';

auth_require();

$currentPage = 'institutions.php';
$page_title  = 'Institutions';

$errors = [];
$form = [
    'id'                  => 0,
    'academic_section_id' => 0,
    'name'                => '',
    'address'             => '',
    'district'            => '',
    'state'               => '',
    'contact_number'      => '',
    'email'               => '',
    'website'             => '',
    'description'         => '',
    'logo_path'           => '',
    'display_order'       => 0,
    'is_active'           => 1,
];

$action = (string)($_GET['action'] ?? 'list');
if (!in_array($action, ['list', 'new', 'edit'], true)) {
    $action = 'list';
}

/* Dropdown data for section selectors (filter + form). */
$sections = [];
try {
    $sections = db()->query(
        'SELECT id, name, is_active FROM academic_sections
         WHERE is_archived = 0
         ORDER BY display_order, name'
    )->fetchAll();
} catch (Throwable $e) { /* ignore */ }

/* ---------- Helpers local to this page ---------- */
function make_institution_slug(string $text, int $sectionId, ?int $excludeId = null): string
{
    $slug = strtolower(trim($text));
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
    $slug = trim($slug, '-');
    if ($slug === '') $slug = 'institution';

    $base = $slug;
    $n    = 1;
    while ($n < 500) {
        $stmt = db()->prepare(
            'SELECT 1 FROM institutions
             WHERE academic_section_id = ? AND slug = ? AND id != ? LIMIT 1'
        );
        $stmt->execute([$sectionId, $slug, $excludeId ?? 0]);
        if (!$stmt->fetchColumn()) return $slug;
        $n++;
        $slug = $base . '-' . $n;
    }
    throw new RuntimeException('Could not generate a unique institution slug.');
}

function validate_section(int $sectionId): bool
{
    if ($sectionId <= 0) return false;
    $stmt = db()->prepare('SELECT is_archived FROM academic_sections WHERE id = ? LIMIT 1');
    $stmt->execute([$sectionId]);
    $row = $stmt->fetch();
    return $row && (int)$row['is_archived'] === 0;
}

/* ---------- POST handling ---------- */
if (is_post()) {
    csrf_require();
    $do = (string)post('do', '');

    if ($do === 'save') {
        $id = post_int('id', 0);

        $form = [
            'id'                  => $id,
            'academic_section_id' => post_int('academic_section_id', 0),
            'name'                => (string)post('name', ''),
            'address'             => (string)post('address', ''),
            'district'            => (string)post('district', ''),
            'state'               => (string)post('state', ''),
            'contact_number'      => (string)post('contact_number', ''),
            'email'               => (string)post('email', ''),
            'website'             => (string)post('website', ''),
            'description'         => (string)post('description', ''),
            'logo_path'           => (string)post('existing_logo', ''),
            'display_order'       => post_int('display_order', 0),
            'is_active'           => post('is_active') ? 1 : 0,
        ];

        $v = new Validator([
            'academic_section_id' => $form['academic_section_id'],
            'name'                => $form['name'],
            'address'             => $form['address'],
            'email'               => $form['email'],
            'contact_number'      => $form['contact_number'],
            'display_order'       => $form['display_order'],
        ]);
        $v->required('academic_section_id', 'Academic section')
          ->required('name', 'Institution name')
          ->min('name', 2, 'Institution name')
          ->max('name', 180, 'Institution name')
          ->required('address', 'Address')
          ->max('address', 1000, 'Address')
          ->email('email', 'Email address')
          ->phone('contact_number', 'Contact number')
          ->integer('display_order', 'Display order');
        $errors = $v->errors();

        if (empty($errors) && !validate_section($form['academic_section_id'])) {
            $errors['academic_section_id'] = 'The selected academic section is not available.';
        }

        if (post('remove_logo')) {
            if ($form['logo_path']) {
                delete_stored_file($form['logo_path']);
            }
            $form['logo_path'] = '';
        }

        if (empty($errors)
            && isset($_FILES['logo'])
            && is_array($_FILES['logo'])
            && ($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
        ) {
            try {
                $result = process_image_upload(
                    $_FILES['logo'],
                    BW_STORAGE_DIR . '/uploads/institutions',
                    102400
                );
                if ($form['logo_path']) {
                    delete_stored_file($form['logo_path']);
                }
                $form['logo_path'] = $result['stored_path'];
            } catch (UploadError $e) {
                $errors['logo'] = $e->getMessage();
            } catch (Throwable $e) {
                error_log('[institution upload] ' . $e->getMessage());
                $errors['logo'] = 'Logo upload failed. Please try again.';
            }
        }

        if (empty($errors)) {
            try {
                $slug = make_institution_slug($form['name'], $form['academic_section_id'], $id ?: null);

                $params = [
                    $form['academic_section_id'],
                    $form['name'],
                    $slug,
                    $form['logo_path'] !== '' ? $form['logo_path'] : null,
                    $form['address'],
                    $form['district']       !== '' ? $form['district']       : null,
                    $form['state']          !== '' ? $form['state']          : null,
                    $form['contact_number'] !== '' ? $form['contact_number'] : null,
                    $form['email']          !== '' ? $form['email']          : null,
                    $form['website']        !== '' ? $form['website']        : null,
                    $form['description']    !== '' ? $form['description']    : null,
                    $form['display_order'],
                    $form['is_active'],
                ];

                if ($id > 0) {
                    $params[] = $id;
                    $stmt = db()->prepare(
                        'UPDATE institutions SET
                            academic_section_id = ?, name = ?, slug = ?, logo_path = ?,
                            address = ?, district = ?, state = ?, contact_number = ?,
                            email = ?, website = ?, description = ?, display_order = ?,
                            is_active = ?, updated_at = NOW()
                         WHERE id = ?'
                    );
                    $stmt->execute($params);
                    log_activity('institution.updated', 'institution', $id, ['name' => $form['name']]);
                    flash_set('success', 'Institution updated.');
                } else {
                    $stmt = db()->prepare(
                        'INSERT INTO institutions
                         (academic_section_id, name, slug, logo_path, address, district, state,
                          contact_number, email, website, description, display_order, is_active,
                          created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                    );
                    $stmt->execute($params);
                    $newId = (int)db()->lastInsertId();
                    log_activity('institution.created', 'institution', $newId, ['name' => $form['name']]);
                    flash_set('success', 'Institution created.');
                }
                redirect('/admin/institutions.php');
            } catch (Throwable $e) {
                error_log('[institution save] ' . $e->getMessage());
                $errors['general'] = 'Could not save the institution. Please try again.';
            }
        }

        if (!empty($errors)) {
            $action = $id > 0 ? 'edit' : 'new';
        }
    } elseif ($do === 'toggle') {
        $id = post_int('id', 0);
        if ($id > 0) {
            db()->prepare('UPDATE institutions SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?')
                ->execute([$id]);
            log_activity('institution.toggled', 'institution', $id);
            flash_set('success', 'Status updated.');
        }
        redirect('/admin/institutions.php');
    } elseif ($do === 'archive') {
        $id = post_int('id', 0);
        if ($id > 0) {
            db()->prepare('UPDATE institutions SET is_archived = 1, updated_at = NOW() WHERE id = ?')
                ->execute([$id]);
            log_activity('institution.archived', 'institution', $id);
            flash_set('success', 'Institution archived.');
        }
        redirect('/admin/institutions.php');
    } elseif ($do === 'unarchive') {
        $id = post_int('id', 0);
        if ($id > 0) {
            db()->prepare('UPDATE institutions SET is_archived = 0, updated_at = NOW() WHERE id = ?')
                ->execute([$id]);
            log_activity('institution.unarchived', 'institution', $id);
            flash_set('success', 'Institution restored.');
        }
        redirect('/admin/institutions.php');
    }
}

/* ---------- Load for edit ---------- */
if ($action === 'edit' && (int)$form['id'] === 0) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        flash_set('error', 'Invalid institution.');
        redirect('/admin/institutions.php');
    }
    $stmt = db()->prepare('SELECT * FROM institutions WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'Institution not found.');
        redirect('/admin/institutions.php');
    }
    $form = [
        'id'                  => (int)$row['id'],
        'academic_section_id' => (int)$row['academic_section_id'],
        'name'                => (string)$row['name'],
        'address'             => (string)$row['address'],
        'district'            => (string)($row['district'] ?? ''),
        'state'               => (string)($row['state'] ?? ''),
        'contact_number'      => (string)($row['contact_number'] ?? ''),
        'email'               => (string)($row['email'] ?? ''),
        'website'             => (string)($row['website'] ?? ''),
        'description'         => (string)($row['description'] ?? ''),
        'logo_path'           => (string)($row['logo_path'] ?? ''),
        'display_order'       => (int)$row['display_order'],
        'is_active'           => (int)$row['is_active'],
    ];
}

/* ---------- List data ---------- */
$list         = [];
$total        = 0;
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = max(1, min(100, (int)app_setting('pagination_size', '20')));
$q            = trim((string)($_GET['q'] ?? ''));
$sectionFilter = (int)($_GET['section_id'] ?? 0);
$statusFilter  = (string)($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'active', 'inactive', 'archived'], true)) {
    $statusFilter = 'all';
}

if ($action === 'list') {
    $where  = [];
    $params = [];

    if ($q !== '') {
        $where[]  = '(i.name LIKE ? OR i.address LIKE ? OR i.email LIKE ? OR i.contact_number LIKE ?)';
        $like     = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($sectionFilter > 0) {
        $where[]  = 'i.academic_section_id = ?';
        $params[] = $sectionFilter;
    }
    switch ($statusFilter) {
        case 'active':   $where[] = 'i.is_active = 1 AND i.is_archived = 0'; break;
        case 'inactive': $where[] = 'i.is_active = 0 AND i.is_archived = 0'; break;
        case 'archived': $where[] = 'i.is_archived = 1'; break;
        default:         $where[] = 'i.is_archived = 0'; break;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $countStmt = db()->prepare("SELECT COUNT(*) FROM institutions i $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare(
        "SELECT i.*, s.name AS section_name, s.is_archived AS section_archived
         FROM institutions i
         JOIN academic_sections s ON s.id = i.academic_section_id
         $whereSql
         ORDER BY s.display_order ASC, s.name ASC, i.display_order ASC, i.name ASC
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
        <h2 class="font-semibold">Institutions</h2>
        <p class="text-xs text-slate-500 mt-0.5">Each institution belongs to exactly one academic section.</p>
      </div>
      <a href="<?= e(absolute_url('/admin/institutions.php?action=new')) ?>" class="btn btn-primary">+ New Institution</a>
    </div>

    <form method="get" class="p-4 border-b border-slate-200 grid grid-cols-1 sm:grid-cols-4 gap-3">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search name / address / email"
             class="form-input">
      <select name="section_id" class="form-select">
        <option value="0">All sections</option>
        <?php foreach ($sections as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $sectionFilter === (int)$s['id'] ? 'selected' : '' ?>>
            <?= e($s['name']) ?><?= (int)$s['is_active'] === 0 ? ' (inactive)' : '' ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="status" class="form-select">
        <option value="all"      <?= $statusFilter === 'all'      ? 'selected' : '' ?>>Active &amp; Inactive</option>
        <option value="active"   <?= $statusFilter === 'active'   ? 'selected' : '' ?>>Active only</option>
        <option value="inactive" <?= $statusFilter === 'inactive' ? 'selected' : '' ?>>Inactive only</option>
        <option value="archived" <?= $statusFilter === 'archived' ? 'selected' : '' ?>>Archived</option>
      </select>
      <div class="flex gap-2">
        <button type="submit" class="btn btn-primary">Search</button>
        <a href="<?= e(absolute_url('/admin/institutions.php')) ?>" class="btn btn-secondary">Reset</a>
      </div>
    </form>

    <?php if (empty($list)): ?>
      <div class="p-8 text-center text-slate-500">
        <div class="text-lg font-medium text-slate-700 mb-1">No institutions found</div>
        <p class="text-sm">Create the first institution to get started.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead>
            <tr>
              <th>Name</th>
              <th>Section</th>
              <th>Contact</th>
              <th class="w-32">Status</th>
              <th class="w-64">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($list as $row): ?>
              <tr>
                <td>
                  <div class="flex items-center gap-3">
                    <?php if (!empty($row['logo_path'])): ?>
                      <img src="<?= e(absolute_url('/download-asset.php?kind=institution&id=' . (int)$row['id'])) ?>"
                           alt="" class="w-10 h-10 object-contain rounded border border-slate-200 bg-white">
                    <?php else: ?>
                      <span class="inline-flex items-center justify-center w-10 h-10 rounded bg-slate-100 text-slate-400 text-xs font-medium">No&nbsp;logo</span>
                    <?php endif; ?>
                    <div class="min-w-0">
                      <div class="font-medium truncate"><?= e($row['name']) ?></div>
                      <?php if (!empty($row['district']) || !empty($row['state'])): ?>
                        <div class="text-xs text-slate-500 truncate">
                          <?= e(trim(($row['district'] ?? '') . ', ' . ($row['state'] ?? ''), ', ')) ?>
                        </div>
                      <?php endif; ?>
                    </div>
                  </div>
                </td>
                <td>
                  <div class="text-sm"><?= e($row['section_name']) ?></div>
                  <?php if ((int)$row['section_archived'] === 1): ?>
                    <span class="badge badge-danger mt-1">Section archived</span>
                  <?php endif; ?>
                </td>
                <td class="text-sm text-slate-600">
                  <?php if (!empty($row['contact_number'])): ?>
                    <div><?= e($row['contact_number']) ?></div>
                  <?php endif; ?>
                  <?php if (!empty($row['email'])): ?>
                    <div class="truncate max-w-xs"><?= e($row['email']) ?></div>
                  <?php endif; ?>
                </td>
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
                      <a href="<?= e(absolute_url('/admin/institutions.php?action=edit&id=' . (int)$row['id'])) ?>"
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
                            data-confirm="Archive this institution? It will be hidden from new applications.">
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

  <div class="card max-w-4xl">
    <div class="card-header">
      <h2 class="font-semibold"><?= $form['id'] ? 'Edit Institution' : 'New Institution' ?></h2>
      <a href="<?= e(absolute_url('/admin/institutions.php')) ?>" class="btn btn-sm btn-secondary">Back</a>
    </div>

    <form method="post" enctype="multipart/form-data" class="card-body space-y-4" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">
      <input type="hidden" name="existing_logo" value="<?= e($form['logo_path']) ?>">

      <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error"><?= e($errors['general']) ?></div>
      <?php endif; ?>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="form-label" for="academic_section_id">Academic Section <span class="text-red-600">*</span></label>
          <select id="academic_section_id" name="academic_section_id" required class="form-select">
            <option value="">Choose…</option>
            <?php foreach ($sections as $s): ?>
              <option value="<?= (int)$s['id'] ?>"
                <?= (int)$form['academic_section_id'] === (int)$s['id'] ? 'selected' : '' ?>>
                <?= e($s['name']) ?><?= (int)$s['is_active'] === 0 ? ' (inactive)' : '' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <?php if (!empty($errors['academic_section_id'])): ?><p class="form-error"><?= e($errors['academic_section_id']) ?></p><?php endif; ?>
        </div>

        <div>
          <label class="form-label" for="name">Institution Name <span class="text-red-600">*</span></label>
          <input type="text" id="name" name="name" required minlength="2" maxlength="180"
                 value="<?= e($form['name']) ?>" class="form-input">
          <?php if (!empty($errors['name'])): ?><p class="form-error"><?= e($errors['name']) ?></p><?php endif; ?>
        </div>
      </div>

      <div>
        <label class="form-label" for="address">Address <span class="text-red-600">*</span></label>
        <textarea id="address" name="address" required rows="2" maxlength="1000"
                  class="form-textarea"><?= e($form['address']) ?></textarea>
        <?php if (!empty($errors['address'])): ?><p class="form-error"><?= e($errors['address']) ?></p><?php endif; ?>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="form-label" for="district">District</label>
          <input type="text" id="district" name="district" maxlength="120"
                 value="<?= e($form['district']) ?>" class="form-input">
        </div>
        <div>
          <label class="form-label" for="state">State</label>
          <input type="text" id="state" name="state" maxlength="120"
                 value="<?= e($form['state']) ?>" class="form-input">
        </div>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="form-label" for="contact_number">Contact Number</label>
          <input type="tel" id="contact_number" name="contact_number" maxlength="40"
                 value="<?= e($form['contact_number']) ?>" class="form-input">
          <?php if (!empty($errors['contact_number'])): ?><p class="form-error"><?= e($errors['contact_number']) ?></p><?php endif; ?>
        </div>
        <div>
          <label class="form-label" for="email">Email Address</label>
          <input type="email" id="email" name="email" maxlength="180"
                 value="<?= e($form['email']) ?>" class="form-input">
          <?php if (!empty($errors['email'])): ?><p class="form-error"><?= e($errors['email']) ?></p><?php endif; ?>
        </div>
        <div>
          <label class="form-label" for="website">Website</label>
          <input type="url" id="website" name="website" maxlength="255"
                 value="<?= e($form['website']) ?>" class="form-input" placeholder="https://">
        </div>
      </div>

      <div>
        <label class="form-label" for="description">Description</label>
        <textarea id="description" name="description" rows="3" maxlength="3000"
                  class="form-textarea"><?= e($form['description']) ?></textarea>
      </div>

      <div>
        <label class="form-label" for="logo">Institution Logo (optional)</label>
        <?php if ($form['logo_path'] !== ''): ?>
          <div class="mb-3 flex items-center gap-3">
            <img src="<?= e(absolute_url('/download-asset.php?kind=institution&id=' . (int)$form['id'])) ?>"
                 alt="" class="w-16 h-16 object-contain rounded border border-slate-200 bg-white">
            <label class="inline-flex items-center gap-2 text-sm">
              <input type="checkbox" name="remove_logo" value="1" class="rounded border-slate-300">
              <span>Remove current logo</span>
            </label>
          </div>
        <?php endif; ?>
        <input type="file" id="logo" name="logo" accept="image/jpeg,image/png,image/webp"
               class="form-input">
        <p class="form-help">JPEG, PNG, or WebP. Automatically compressed to 100 KB or less.</p>
        <?php if (!empty($errors['logo'])): ?><p class="form-error"><?= e($errors['logo']) ?></p><?php endif; ?>
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
            <span class="text-sm">Active (accepts new applications)</span>
          </label>
        </div>
      </div>

      <div class="flex gap-2 pt-3 border-t border-slate-200">
        <button type="submit" class="btn btn-primary">
          <?= $form['id'] ? 'Save Changes' : 'Create Institution' ?>
        </button>
        <a href="<?= e(absolute_url('/admin/institutions.php')) ?>" class="btn btn-secondary">Cancel</a>
      </div>
    </form>
  </div>

<?php endif; ?>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>