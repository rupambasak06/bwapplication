<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

auth_require();

$currentPage = 'courses.php';
$page_title  = 'Courses';

$errors = [];
$form = [
    'id'                  => 0,
    'academic_section_id' => 0,
    'institution_id'      => 0,
    'name'                => '',
    'code'                => '',
    'description'         => '',
    'price'               => '0.00',
    'duration'            => '',
    'min_extra_subjects'  => 0,
    'max_extra_subjects'  => 0,
    'display_order'       => 0,
    'is_active'           => 1,
];

$action = (string)($_GET['action'] ?? 'list');
if (!in_array($action, ['list', 'new', 'edit'], true)) {
    $action = 'list';
}

/* ---------- Dropdown data (loaded from MySQL, never hardcoded) ---------- */
$sections = [];
$allInstitutionsForJs = [];
try {
    $sections = db()->query(
        'SELECT id, name, is_active FROM academic_sections
         WHERE is_archived = 0
         ORDER BY display_order, name'
    )->fetchAll();

    $allInstitutionsForJs = db()->query(
        'SELECT id, academic_section_id, name, is_active, is_archived
         FROM institutions
         WHERE is_archived = 0
         ORDER BY display_order, name'
    )->fetchAll();
} catch (Throwable $e) { /* ignore */ }

/* ---------- POST handling ---------- */
if (is_post()) {
    csrf_require();
    $do = (string)post('do', '');

    if ($do === 'save') {
        $id = post_int('id', 0);

        $form = [
            'id'                  => $id,
            'academic_section_id' => post_int('academic_section_id', 0),
            'institution_id'      => post_int('institution_id', 0),
            'name'                => (string)post('name', ''),
            'code'                => (string)post('code', ''),
            'description'         => (string)post('description', ''),
            'price'               => (string)post('price', '0'),
            'duration'            => (string)post('duration', ''),
            'min_extra_subjects'  => post_int('min_extra_subjects', 0),
            'max_extra_subjects'  => post_int('max_extra_subjects', 0),
            'display_order'       => post_int('display_order', 0),
            'is_active'           => post('is_active') ? 1 : 0,
        ];

        // Normalize price: strip commas, ensure numeric
        $form['price'] = str_replace(',', '', $form['price']);
        if ($form['price'] === '') $form['price'] = '0';

        $v = new Validator([
            'academic_section_id' => $form['academic_section_id'],
            'institution_id'      => $form['institution_id'],
            'name'                => $form['name'],
            'duration'            => $form['duration'],
            'price'               => $form['price'],
            'min_extra_subjects'  => $form['min_extra_subjects'],
            'max_extra_subjects'  => $form['max_extra_subjects'],
            'display_order'       => $form['display_order'],
        ]);
        $v->required('academic_section_id', 'Academic section')
          ->required('institution_id', 'Institution')
          ->required('name', 'Course name')
          ->min('name', 2, 'Course name')
          ->max('name', 200, 'Course name')
          ->required('duration', 'Duration')
          ->max('duration', 80, 'Duration')
          ->numeric('price', 'Course price')
          ->min_value('price', 0, 'Course price')
          ->max_value('price', 99999999.99, 'Course price')
          ->integer('min_extra_subjects', 'Minimum extra subjects')
          ->min_value('min_extra_subjects', 0, 'Minimum extra subjects')
          ->max_value('min_extra_subjects', 100, 'Minimum extra subjects')
          ->integer('max_extra_subjects', 'Maximum extra subjects')
          ->min_value('max_extra_subjects', 0, 'Maximum extra subjects')
          ->max_value('max_extra_subjects', 100, 'Maximum extra subjects')
          ->integer('display_order', 'Display order');
        $errors = $v->errors();

        if (empty($errors) && $form['min_extra_subjects'] > $form['max_extra_subjects']) {
            $errors['min_extra_subjects'] = 'Minimum extra subjects cannot exceed the maximum.';
        }

        // Backend re-validation of the section ↔ institution pair
        if (empty($errors)) {
            $stmt = db()->prepare(
                'SELECT 1 FROM institutions
                 WHERE id = ? AND academic_section_id = ? AND is_archived = 0
                 LIMIT 1'
            );
            $stmt->execute([$form['institution_id'], $form['academic_section_id']]);
            if (!$stmt->fetchColumn()) {
                $errors['institution_id'] = 'The selected institution does not belong to the chosen academic section.';
            }
        }

        if (empty($errors)) {
            try {
                $priceDecimal = number_format((float)$form['price'], 2, '.', '');
                $code         = $form['code'] !== '' ? $form['code'] : null;
                $desc         = $form['description'] !== '' ? $form['description'] : null;

                if ($id > 0) {
                    $stmt = db()->prepare(
                        'UPDATE courses SET
                            academic_section_id = ?, institution_id = ?, name = ?, code = ?, description = ?,
                            price = ?, duration = ?, min_extra_subjects = ?, max_extra_subjects = ?,
                            display_order = ?, is_active = ?, updated_at = NOW()
                         WHERE id = ?'
                    );
                    $stmt->execute([
                        $form['academic_section_id'],
                        $form['institution_id'],
                        $form['name'],
                        $code,
                        $desc,
                        $priceDecimal,
                        $form['duration'],
                        $form['min_extra_subjects'],
                        $form['max_extra_subjects'],
                        $form['display_order'],
                        $form['is_active'],
                        $id,
                    ]);
                    log_activity('course.updated', 'course', $id, ['name' => $form['name']]);
                    flash_set('success', 'Course updated.');
                } else {
                    $stmt = db()->prepare(
                        'INSERT INTO courses
                         (academic_section_id, institution_id, name, code, description, price, duration,
                          min_extra_subjects, max_extra_subjects, display_order, is_active, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
                    );
                    $stmt->execute([
                        $form['academic_section_id'],
                        $form['institution_id'],
                        $form['name'],
                        $code,
                        $desc,
                        $priceDecimal,
                        $form['duration'],
                        $form['min_extra_subjects'],
                        $form['max_extra_subjects'],
                        $form['display_order'],
                        $form['is_active'],
                    ]);
                    $newId = (int)db()->lastInsertId();
                    log_activity('course.created', 'course', $newId, ['name' => $form['name']]);
                    flash_set('success', 'Course created. You can now configure subjects.');
                    redirect('/admin/subjects.php?course_id=' . $newId);
                }
                redirect('/admin/courses.php');
            } catch (Throwable $e) {
                error_log('[course save] ' . $e->getMessage());
                $errors['general'] = 'Could not save the course. Please try again.';
            }
        }

        if (!empty($errors)) {
            $action = $id > 0 ? 'edit' : 'new';
        }
    } elseif ($do === 'toggle') {
        $id = post_int('id', 0);
        if ($id > 0) {
            db()->prepare('UPDATE courses SET is_active = 1 - is_active, updated_at = NOW() WHERE id = ?')
                ->execute([$id]);
            log_activity('course.toggled', 'course', $id);
            flash_set('success', 'Status updated.');
        }
        redirect('/admin/courses.php');
    } elseif ($do === 'archive') {
        $id = post_int('id', 0);
        if ($id > 0) {
            db()->prepare('UPDATE courses SET is_archived = 1, updated_at = NOW() WHERE id = ?')
                ->execute([$id]);
            log_activity('course.archived', 'course', $id);
            flash_set('success', 'Course archived.');
        }
        redirect('/admin/courses.php');
    } elseif ($do === 'unarchive') {
        $id = post_int('id', 0);
        if ($id > 0) {
            db()->prepare('UPDATE courses SET is_archived = 0, updated_at = NOW() WHERE id = ?')
                ->execute([$id]);
            log_activity('course.unarchived', 'course', $id);
            flash_set('success', 'Course restored.');
        }
        redirect('/admin/courses.php');
    }
}

/* ---------- Load for edit ---------- */
if ($action === 'edit' && (int)$form['id'] === 0) {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        flash_set('error', 'Invalid course.');
        redirect('/admin/courses.php');
    }
    $stmt = db()->prepare('SELECT * FROM courses WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash_set('error', 'Course not found.');
        redirect('/admin/courses.php');
    }
    $form = [
        'id'                  => (int)$row['id'],
        'academic_section_id' => (int)$row['academic_section_id'],
        'institution_id'      => (int)$row['institution_id'],
        'name'                => (string)$row['name'],
        'code'                => (string)($row['code'] ?? ''),
        'description'         => (string)($row['description'] ?? ''),
        'price'               => (string)$row['price'],
        'duration'            => (string)$row['duration'],
        'min_extra_subjects'  => (int)$row['min_extra_subjects'],
        'max_extra_subjects'  => (int)$row['max_extra_subjects'],
        'display_order'       => (int)$row['display_order'],
        'is_active'           => (int)$row['is_active'],
    ];
}

/* ---------- List data ---------- */
$list          = [];
$total         = 0;
$page          = max(1, (int)($_GET['page'] ?? 1));
$perPage       = max(1, min(100, (int)app_setting('pagination_size', '20')));
$q             = trim((string)($_GET['q'] ?? ''));
$sectionFilter = (int)($_GET['section_id'] ?? 0);
$instFilter    = (int)($_GET['institution_id'] ?? 0);
$statusFilter  = (string)($_GET['status'] ?? 'all');
if (!in_array($statusFilter, ['all', 'active', 'inactive', 'archived'], true)) {
    $statusFilter = 'all';
}

if ($action === 'list') {
    $where  = [];
    $params = [];

    if ($q !== '') {
        $where[]  = '(c.name LIKE ? OR c.code LIKE ? OR c.description LIKE ?)';
        $like     = '%' . $q . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    if ($sectionFilter > 0) {
        $where[]  = 'c.academic_section_id = ?';
        $params[] = $sectionFilter;
    }
    if ($instFilter > 0) {
        $where[]  = 'c.institution_id = ?';
        $params[] = $instFilter;
    }
    switch ($statusFilter) {
        case 'active':   $where[] = 'c.is_active = 1 AND c.is_archived = 0'; break;
        case 'inactive': $where[] = 'c.is_active = 0 AND c.is_archived = 0'; break;
        case 'archived': $where[] = 'c.is_archived = 1'; break;
        default:         $where[] = 'c.is_archived = 0'; break;
    }
    $whereSql = 'WHERE ' . implode(' AND ', $where);

    $countStmt = db()->prepare("SELECT COUNT(*) FROM courses c $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare(
        "SELECT c.id, c.name, c.code, c.price, c.duration, c.min_extra_subjects, c.max_extra_subjects,
                c.display_order, c.is_active AS course_active, c.is_archived AS course_archived,
                c.created_at, c.updated_at,
                s.name AS section_name, s.is_active AS section_active, s.is_archived AS section_archived,
                i.name AS institution_name, i.is_active AS institution_active, i.is_archived AS institution_archived,
                (SELECT COUNT(*) FROM subjects WHERE course_id = c.id AND type = 'compulsory') AS compulsory_count,
                (SELECT COUNT(*) FROM subjects WHERE course_id = c.id AND type = 'extra')      AS extra_count
         FROM courses c
         JOIN academic_sections s ON s.id = c.academic_section_id
         JOIN institutions i       ON i.id = c.institution_id
         $whereSql
         ORDER BY s.display_order, s.name, i.display_order, i.name, c.display_order, c.name
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
        <h2 class="font-semibold">Courses</h2>
        <p class="text-xs text-slate-500 mt-0.5">Each course belongs to one institution within one academic section.</p>
      </div>
      <a href="<?= e(absolute_url('/admin/courses.php?action=new')) ?>" class="btn btn-primary">+ New Course</a>
    </div>

    <form method="get" class="p-4 border-b border-slate-200 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
      <input type="text" name="q" value="<?= e($q) ?>" placeholder="Search name / code" class="form-input">
      <select name="section_id" class="form-select">
        <option value="0">All sections</option>
        <?php foreach ($sections as $s): ?>
          <option value="<?= (int)$s['id'] ?>" <?= $sectionFilter === (int)$s['id'] ? 'selected' : '' ?>>
            <?= e($s['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="institution_id" class="form-select">
        <option value="0">All institutions</option>
        <?php foreach ($allInstitutionsForJs as $inst): ?>
          <option value="<?= (int)$inst['id'] ?>" <?= $instFilter === (int)$inst['id'] ? 'selected' : '' ?>>
            <?= e($inst['name']) ?>
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
        <a href="<?= e(absolute_url('/admin/courses.php')) ?>" class="btn btn-secondary">Reset</a>
      </div>
    </form>

    <?php if (empty($list)): ?>
      <div class="p-8 text-center text-slate-500">
        <div class="text-lg font-medium text-slate-700 mb-1">No courses found</div>
        <p class="text-sm">Create a course or adjust your filters.</p>
      </div>
    <?php else: ?>
      <div class="overflow-x-auto">
        <table class="data-table">
          <thead>
            <tr>
              <th>Course</th>
              <th>Institution &amp; Section</th>
              <th class="w-24">Price</th>
              <th class="w-24">Duration</th>
              <th class="w-32">Subjects</th>
              <th class="w-28">Status</th>
              <th class="w-64">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($list as $row): ?>
              <tr>
                <td>
                  <div class="font-medium"><?= e($row['name']) ?></div>
                  <?php if (!empty($row['code'])): ?>
                    <div class="text-xs text-slate-500 font-mono mt-0.5"><?= e($row['code']) ?></div>
                  <?php endif; ?>
                </td>
                <td class="text-sm">
                  <div><?= e($row['institution_name']) ?></div>
                  <div class="text-xs text-slate-500 mt-0.5"><?= e($row['section_name']) ?></div>
                  <?php if ((int)$row['institution_archived'] === 1): ?>
                    <span class="badge badge-danger mt-1">Institution archived</span>
                  <?php elseif ((int)$row['institution_active'] === 0): ?>
                    <span class="badge badge-warning mt-1">Institution inactive</span>
                  <?php endif; ?>
                </td>
                <td class="text-sm">
                  <?php $price = (float)$row['price']; ?>
                  <?php if ($price > 0): ?>
                    <?= e(format_money($price)) ?>
                  <?php else: ?>
                    <span class="text-slate-500">Free</span>
                  <?php endif; ?>
                </td>
                <td class="text-sm"><?= e($row['duration']) ?></td>
                <td class="text-xs text-slate-600">
                  <div><?= (int)$row['compulsory_count'] ?> compulsory</div>
                  <div><?= (int)$row['extra_count'] ?> extra</div>
                </td>
                <td>
                  <?php if ((int)$row['course_archived'] === 1): ?>
                    <span class="badge badge-danger">Archived</span>
                  <?php elseif ((int)$row['course_active'] === 1): ?>
                    <span class="badge badge-success">Active</span>
                  <?php else: ?>
                    <span class="badge badge-warning">Inactive</span>
                  <?php endif; ?>
                </td>
                <td>
                  <div class="flex flex-wrap items-center gap-2">
                    <?php if ((int)$row['course_archived'] === 0): ?>
                      <a href="<?= e(absolute_url('/admin/courses.php?action=edit&id=' . (int)$row['id'])) ?>"
                         class="btn btn-sm btn-secondary">Edit</a>
                      <a href="<?= e(absolute_url('/admin/subjects.php?course_id=' . (int)$row['id'])) ?>"
                         class="btn btn-sm btn-secondary">Subjects</a>
                      <form method="post" class="inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="do" value="toggle">
                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                        <button type="submit" class="btn btn-sm btn-secondary">
                          <?= (int)$row['course_active'] === 1 ? 'Deactivate' : 'Activate' ?>
                        </button>
                      </form>
                      <form method="post" class="inline"
                            data-confirm="Archive this course? It will be hidden from new applications.">
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
      <h2 class="font-semibold"><?= $form['id'] ? 'Edit Course' : 'New Course' ?></h2>
      <a href="<?= e(absolute_url('/admin/courses.php')) ?>" class="btn btn-sm btn-secondary">Back</a>
    </div>

    <form method="post" class="card-body space-y-4" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="do" value="save">
      <input type="hidden" name="id" value="<?= (int)$form['id'] ?>">

      <?php if (!empty($errors['general'])): ?>
        <div class="alert alert-error"><?= e($errors['general']) ?></div>
      <?php endif; ?>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <div>
          <label class="form-label" for="academic_section_id">Academic Section <span class="text-red-600">*</span></label>
          <select id="academic_section_id" name="academic_section_id" required class="form-select">
            <option value="">Choose section…</option>
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
          <label class="form-label" for="institution_id">Institution <span class="text-red-600">*</span></label>
          <select id="institution_id" name="institution_id" required
                  data-current="<?= (int)$form['institution_id'] ?: '' ?>"
                  class="form-select">
            <option value="">Choose institution…</option>
          </select>
          <?php if (!empty($errors['institution_id'])): ?><p class="form-error"><?= e($errors['institution_id']) ?></p><?php endif; ?>
          <p id="no-institutions-warning" class="text-xs text-amber-900 bg-amber-50 border border-amber-200 rounded px-2 py-1 mt-2 hidden">
            No active institutions exist under the selected academic section.
            <a class="underline" href="<?= e(absolute_url('/admin/institutions.php?action=new')) ?>">Create one</a> first.
          </p>
        </div>
      </div>

      <div>
        <label class="form-label" for="name">Course Name <span class="text-red-600">*</span></label>
        <input type="text" id="name" name="name" required minlength="2" maxlength="200"
               value="<?= e($form['name']) ?>" class="form-input">
        <?php if (!empty($errors['name'])): ?><p class="form-error"><?= e($errors['name']) ?></p><?php endif; ?>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <div>
          <label class="form-label" for="code">Course Code</label>
          <input type="text" id="code" name="code" maxlength="80"
                 value="<?= e($form['code']) ?>" class="form-input">
        </div>
        <div>
          <label class="form-label" for="price">Course Price (INR)</label>
          <input type="number" id="price" name="price" min="0" step="0.01"
                 value="<?= e($form['price']) ?>" class="form-input">
          <p class="form-help">Leave as 0 for a free course.</p>
          <?php if (!empty($errors['price'])): ?><p class="form-error"><?= e($errors['price']) ?></p><?php endif; ?>
        </div>
        <div>
          <label class="form-label" for="duration">Course Duration <span class="text-red-600">*</span></label>
          <input type="text" id="duration" name="duration" required maxlength="80"
                 value="<?= e($form['duration']) ?>" class="form-input" placeholder="e.g. 2 Years">
          <?php if (!empty($errors['duration'])): ?><p class="form-error"><?= e($errors['duration']) ?></p><?php endif; ?>
        </div>
      </div>

      <div>
        <label class="form-label" for="description">Description</label>
        <textarea id="description" name="description" rows="3" maxlength="2000"
                  class="form-textarea"><?= e($form['description']) ?></textarea>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
          <label class="form-label" for="min_extra_subjects">Minimum Extra Subjects</label>
          <input type="number" id="min_extra_subjects" name="min_extra_subjects" min="0" max="100"
                 value="<?= (int)$form['min_extra_subjects'] ?>" class="form-input">
          <?php if (!empty($errors['min_extra_subjects'])): ?><p class="form-error"><?= e($errors['min_extra_subjects']) ?></p><?php endif; ?>
        </div>
        <div>
          <label class="form-label" for="max_extra_subjects">Maximum Extra Subjects</label>
          <input type="number" id="max_extra_subjects" name="max_extra_subjects" min="0" max="100"
                 value="<?= (int)$form['max_extra_subjects'] ?>" class="form-input">
          <?php if (!empty($errors['max_extra_subjects'])): ?><p class="form-error"><?= e($errors['max_extra_subjects']) ?></p><?php endif; ?>
        </div>
        <div>
          <label class="form-label" for="display_order">Display Order</label>
          <input type="number" id="display_order" name="display_order" min="0" max="9999"
                 value="<?= (int)$form['display_order'] ?>" class="form-input">
        </div>
      </div>

      <div class="flex items-center gap-2">
        <input type="checkbox" id="is_active" name="is_active" value="1"
               <?= (int)$form['is_active'] === 1 ? 'checked' : '' ?>
               class="rounded border-slate-300">
        <label for="is_active" class="text-sm">Active (available for new applications)</label>
      </div>

      <div class="flex gap-2 pt-3 border-t border-slate-200">
        <button type="submit" class="btn btn-primary">
          <?= $form['id'] ? 'Save Changes' : 'Create Course' ?>
        </button>
        <a href="<?= e(absolute_url('/admin/courses.php')) ?>" class="btn btn-secondary">Cancel</a>
        <?php if ($form['id']): ?>
          <a href="<?= e(absolute_url('/admin/subjects.php?course_id=' . (int)$form['id'])) ?>"
             class="btn btn-secondary ml-auto">Configure Subjects →</a>
        <?php endif; ?>
      </div>
    </form>
  </div>

  <script>
  (function () {
    'use strict';
    var institutions = <?= json_encode($allInstitutionsForJs, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var sectionSel = document.getElementById('academic_section_id');
    var instSel    = document.getElementById('institution_id');
    var warn       = document.getElementById('no-institutions-warning');
    if (!sectionSel || !instSel) return;

    function rebuild() {
      var sid     = sectionSel.value;
      var current = instSel.getAttribute('data-current') || '';
      instSel.innerHTML = '<option value="">Choose institution…</option>';
      if (!sid) {
        instSel.disabled = true;
        if (warn) warn.classList.add('hidden');
        return;
      }
      var matches = institutions.filter(function (i) {
        return String(i.academic_section_id) === String(sid);
      });
      instSel.disabled = matches.length === 0;
      matches.forEach(function (i) {
        var opt = document.createElement('option');
        opt.value = i.id;
        opt.textContent = i.name + (i.is_active == 0 ? ' (inactive)' : '');
        if (current && String(i.id) === String(current)) opt.selected = true;
        instSel.appendChild(opt);
      });
      if (warn) {
        if (matches.length === 0) warn.classList.remove('hidden');
        else warn.classList.add('hidden');
      }
    }

    sectionSel.addEventListener('change', function () {
      instSel.setAttribute('data-current', '');
      rebuild();
    });
    rebuild();
  })();
  </script>

<?php endif; ?>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>