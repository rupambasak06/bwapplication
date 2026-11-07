<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';

auth_require();

$currentPage = 'students.php';
$page_title  = 'Students';

$q         = trim((string)($_GET['q'] ?? ''));
$sectionId = (int)($_GET['section_id'] ?? 0);
$instId    = (int)($_GET['institution_id'] ?? 0);
$courseId  = (int)($_GET['course_id'] ?? 0);
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = max(1, min(200, (int)app_setting('pagination_size', '20')));

$where  = [];
$params = [];

if ($q !== '') {
    $where[]  = '(s.name LIKE ? OR s.mobile LIKE ? OR s.email LIKE ?)';
    $like     = '%' . $q . '%';
    $params[] = $like; $params[] = $like; $params[] = $like;
}

$sections     = [];
$institutions = [];
$courses      = [];
try {
    $sections     = db()->query('SELECT id, name FROM academic_sections WHERE is_archived = 0 ORDER BY display_order, name')->fetchAll();
    $institutions = db()->query('SELECT id, name FROM institutions WHERE is_archived = 0 ORDER BY name')->fetchAll();
    $courses      = db()->query('SELECT id, name FROM courses WHERE is_archived = 0 ORDER BY name')->fetchAll();
} catch (Throwable $e) { /* ignore */ }

$students   = [];
$total      = 0;
$totalPages = 1;
$offset     = 0;

try {
    if ($sectionId > 0 || $instId > 0 || $courseId > 0) {
        $appWhere  = [];
        $appParams = [];
        if ($sectionId > 0) { $appWhere[] = 'academic_section_id = ?'; $appParams[] = $sectionId; }
        if ($instId > 0)    { $appWhere[] = 'institution_id = ?';      $appParams[] = $instId; }
        if ($courseId > 0)  { $appWhere[] = 'course_id = ?';           $appParams[] = $courseId; }
        $where[]  = 's.id IN (SELECT student_id FROM applications WHERE ' . implode(' AND ', $appWhere) . ')';
        $params   = array_merge($params, $appParams);
    }

    $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

    $countStmt = db()->prepare("SELECT COUNT(*) FROM students s $whereSql");
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int)ceil($total / $perPage) : 1;
    if ($page > $totalPages) $page = $totalPages;
    $offset = ($page - 1) * $perPage;

    $stmt = db()->prepare(
        "SELECT s.*,
                (SELECT COUNT(*) FROM applications WHERE student_id = s.id) AS app_count,
                (SELECT MAX(submission_date) FROM applications WHERE student_id = s.id) AS last_application
         FROM students s
         $whereSql
         ORDER BY s.id DESC
         LIMIT $perPage OFFSET $offset"
    );
    $stmt->execute($params);
    $students = $stmt->fetchAll();
} catch (Throwable $e) {
    error_log('[students list] ' . $e->getMessage());
}

require __DIR__ . '/../../includes/admin-header.php';
?>

<div class="card">
  <div class="card-header">
    <div>
      <h2 class="font-semibold">Students</h2>
      <p class="text-xs text-slate-500 mt-0.5">Total: <?= (int)$total ?> student record(s).</p>
    </div>
  </div>

  <form method="get" class="p-4 border-b border-slate-200 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
    <input type="text" name="q" value="<?= e($q) ?>" placeholder="Name / mobile / email" class="form-input">
    <select name="section_id" class="form-select">
      <option value="0">All sections</option>
      <?php foreach ($sections as $s): ?>
        <option value="<?= (int)$s['id'] ?>" <?= $sectionId === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="institution_id" class="form-select">
      <option value="0">All institutions</option>
      <?php foreach ($institutions as $i): ?>
        <option value="<?= (int)$i['id'] ?>" <?= $instId === (int)$i['id'] ? 'selected' : '' ?>><?= e($i['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="course_id" class="form-select">
      <option value="0">All courses</option>
      <?php foreach ($courses as $c): ?>
        <option value="<?= (int)$c['id'] ?>" <?= $courseId === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <div class="flex gap-2 sm:col-span-2 lg:col-span-4">
      <button type="submit" class="btn btn-primary">Search</button>
      <a href="<?= e(absolute_url('/admin/students.php')) ?>" class="btn btn-secondary">Reset</a>
    </div>
  </form>

  <?php if (empty($students)): ?>
    <div class="p-8 text-center text-slate-500">
      <div class="text-lg font-medium text-slate-700 mb-1">No students found</div>
      <p class="text-sm">Students appear here once they submit an application.</p>
    </div>
  <?php else: ?>
    <div class="overflow-x-auto">
      <table class="data-table">
        <thead>
          <tr>
            <th>Name</th>
            <th>Mobile</th>
            <th>Email</th>
            <th>Applications</th>
            <th>Last Submission</th>
            <th class="w-24">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($students as $s): ?>
            <tr>
              <td class="font-medium"><?= e($s['name']) ?></td>
              <td class="text-sm"><?= e($s['mobile']) ?></td>
              <td class="text-sm break-all"><?= e($s['email'] ?: '—') ?></td>
              <td class="text-sm"><?= (int)$s['app_count'] ?></td>
              <td class="text-xs"><?= e(format_datetime($s['last_application'])) ?></td>
              <td>
                <a href="<?= e(absolute_url('/admin/student-view.php?id=' . (int)$s['id'])) ?>" class="btn btn-sm btn-primary">View</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if ($totalPages > 1): ?>
      <div class="p-4 border-t border-slate-200 flex flex-wrap items-center justify-between gap-2">
        <div class="text-sm text-slate-500">Page <?= $page ?> of <?= $totalPages ?> · <?= $total ?> total</div>
        <div class="flex gap-2">
          <?php if ($page > 1): ?>
            <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page - 1]))) ?>" class="btn btn-sm btn-secondary">Previous</a>
          <?php endif; ?>
          <?php if ($page < $totalPages): ?>
            <a href="?<?= e(http_build_query(array_merge($_GET, ['page' => $page + 1]))) ?>" class="btn btn-sm btn-secondary">Next</a>
          <?php endif; ?>
        </div>
      </div>
    <?php endif; ?>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/admin-footer.php'; ?>