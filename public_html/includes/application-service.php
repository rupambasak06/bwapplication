<?php
declare(strict_types=1);
if (!defined('BRAINWAVERS_BOOTSTRAPPED')) { exit('Direct access is not allowed.'); }

/** Generate a unique Application ID inside the caller's transaction. */
function bw_generate_application_id(PDO $pdo): string
{
    $pdo->prepare("INSERT INTO id_sequences (name, current_value) VALUES ('application', 0)
                   ON DUPLICATE KEY UPDATE name = name")->execute();

    $stmt = $pdo->prepare("SELECT current_value FROM id_sequences WHERE name = 'application' FOR UPDATE");
    $stmt->execute();
    $next = ((int)$stmt->fetchColumn()) + 1;

    $pdo->prepare("UPDATE id_sequences SET current_value = ? WHERE name = 'application'")
        ->execute([$next]);

    $prefix = (string)app_setting('application_number_prefix', 'BW');
    if ($prefix === '') $prefix = 'BW';

    return sprintf('%s-%s-%06d', $prefix, gmdate('Y'), $next);
}

/** Find or create a student record. Merges only when name + mobile + dob all match. */
function bw_find_or_create_student(PDO $pdo, array $data): int
{
    $name   = trim((string)($data['name']   ?? ''));
    $mobile = trim((string)($data['mobile'] ?? ''));
    $dob    = $data['dob'] ?? null;
    $father = $data['father_name'] ?? null;
    $mother = $data['mother_name'] ?? null;
    $email  = $data['email'] ?? null;

    if ($name !== '' && $mobile !== '') {
        if ($dob) {
            $stmt = $pdo->prepare(
                'SELECT id FROM students WHERE name = ? AND mobile = ? AND dob = ? LIMIT 1'
            );
            $stmt->execute([$name, $mobile, $dob]);
        } else {
            $stmt = $pdo->prepare(
                'SELECT id FROM students WHERE name = ? AND mobile = ? AND dob IS NULL LIMIT 1'
            );
            $stmt->execute([$name, $mobile]);
        }
        $existing = $stmt->fetchColumn();
        if ($existing) {
            $pdo->prepare(
                'UPDATE students
                 SET father_name = COALESCE(?, father_name),
                     mother_name = COALESCE(?, mother_name),
                     email       = COALESCE(?, email),
                     updated_at  = NOW()
                 WHERE id = ?'
            )->execute([$father, $mother, $email, (int)$existing]);
            return (int)$existing;
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO students (name, father_name, mother_name, dob, mobile, email, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([$name, $father, $mother, $dob ?: null, $mobile, $email]);
    return (int)$pdo->lastInsertId();
}

function bw_save_application_subjects(PDO $pdo, int $appId, array $compulsory, array $extra): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO application_subject_selections
            (application_id, subject_id, subject_name_snapshot, subject_type, created_at)
         VALUES (?, ?, ?, ?, NOW())'
    );
    foreach ($compulsory as $s) {
        $stmt->execute([$appId, (int)$s['id'], (string)$s['name'], 'compulsory']);
    }
    foreach ($extra as $s) {
        $stmt->execute([$appId, (int)$s['id'], (string)$s['name'], 'extra']);
    }
}

function bw_save_application_answers(PDO $pdo, int $appId, array $answers): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO application_answers
            (application_id, form_field_id, field_key, field_label_snap, field_type_snap, field_value, created_at)
         VALUES (?, ?, ?, ?, ?, ?, NOW())'
    );
    foreach ($answers as $a) {
        $stmt->execute([
            $appId,
            $a['field_id'] ?? null,
            (string)$a['field_key'],
            (string)$a['field_label'],
            (string)$a['field_type'],
            (string)$a['field_value'],
        ]);
    }
}

function bw_save_application_documents(PDO $pdo, int $appId, array $docs): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO application_documents
            (application_id, form_field_id, document_name, original_filename, stored_filename,
             storage_path, mime_type, file_size, compression_applied, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())'
    );
    foreach ($docs as $d) {
        $stmt->execute([
            $appId,
            $d['form_field_id'] ?? null,
            (string)$d['document_name'],
            (string)$d['original_filename'],
            (string)$d['stored_filename'],
            (string)$d['storage_path'],
            (string)$d['mime_type'],
            (int)$d['file_size'],
            (int)$d['compression_applied'],
        ]);
    }
}




/* ---------- Shared application query filter (used by list + CSV export) ---------- */
function bw_application_filters(): array
{
    $where  = [];
    $params = [];

    $q         = trim((string)($_GET['q']              ?? ''));
    $appId     = trim((string)($_GET['application_id'] ?? ''));
    $mobile    = trim((string)($_GET['mobile']         ?? ''));
    $email     = trim((string)($_GET['email']          ?? ''));
    $sectionId = (int)($_GET['section_id']     ?? 0);
    $instId    = (int)($_GET['institution_id'] ?? 0);
    $courseId  = (int)($_GET['course_id']      ?? 0);
    $subjectId = (int)($_GET['subject_id']     ?? 0);
    $status    = (string)($_GET['status']         ?? 'all');
    $payStatus = (string)($_GET['payment_status'] ?? 'all');
    $fromDate  = trim((string)($_GET['from_date'] ?? ''));
    $toDate    = trim((string)($_GET['to_date']   ?? ''));

    if ($q !== '') {
        $where[]  = '(s.name LIKE ? OR s.email LIKE ? OR s.mobile LIKE ?)';
        $like     = '%' . $q . '%';
        $params[] = $like; $params[] = $like; $params[] = $like;
    }
    if ($appId !== '') {
        $where[]  = 'a.application_id LIKE ?';
        $params[] = '%' . $appId . '%';
    }
    if ($mobile !== '') {
        $where[]  = 's.mobile LIKE ?';
        $params[] = '%' . $mobile . '%';
    }
    if ($email !== '') {
        $where[]  = 's.email LIKE ?';
        $params[] = '%' . $email . '%';
    }
    if ($sectionId > 0) { $where[] = 'a.academic_section_id = ?'; $params[] = $sectionId; }
    if ($instId > 0)    { $where[] = 'a.institution_id = ?';      $params[] = $instId; }
    if ($courseId > 0)  { $where[] = 'a.course_id = ?';           $params[] = $courseId; }
    if ($subjectId > 0) {
        $where[]  = 'a.id IN (SELECT application_id FROM application_subject_selections WHERE subject_id = ?)';
        $params[] = $subjectId;
    }
    if (in_array($status, ['draft','submitted','under_review','documents_required','approved','rejected','payment_pending'], true)) {
        $where[]  = 'a.application_status = ?';
        $params[] = $status;
    }
    if (in_array($payStatus, ['not_required','pending','paid','failed','refunded'], true)) {
        $where[]  = 'a.payment_status = ?';
        $params[] = $payStatus;
    }
    if ($fromDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) {
        $where[]  = 'a.submission_date >= ?';
        $params[] = $fromDate . ' 00:00:00';
    }
    if ($toDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) {
        $where[]  = 'a.submission_date <= ?';
        $params[] = $toDate . ' 23:59:59';
    }

    return [
        'where_sql' => $where ? ('WHERE ' . implode(' AND ', $where)) : '',
        'params'    => $params,
    ];
}

/* ---------- Printable application renderer (used by view + download) ---------- */
function bw_render_application_print_html(
    array $app,
    array $compulsory,
    array $extra,
    array $answers,
    array $documents,
    array $payments,
    array $institute
): string {
    $logoData = '';
    $logoPath = (string)($institute['logo_path'] ?? '');
    if ($logoPath !== '' && is_file($logoPath) && filesize($logoPath) > 0) {
        $mime = mime_content_type($logoPath) ?: 'image/png';
        if (in_array($mime, ['image/jpeg','image/png','image/webp'], true)) {
            $logoData = 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($logoPath));
        }
    }

    ob_start();
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Application <?= e($app['application_id']) ?></title>
<style>
  *{box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;font-size:12px;color:#333;margin:0;padding:20px;line-height:1.4}
  .header{display:flex;gap:15px;border-bottom:2px solid #312e81;padding-bottom:10px;margin-bottom:15px;align-items:flex-start}
  .header img{max-height:60px;max-width:60px;object-fit:contain}
  .header .institute{flex:1}
  .header h1{margin:0;font-size:20px;color:#312e81}
  .header .meta{font-size:10px;color:#666;margin-top:3px}
  .header .app-block{text-align:right;min-width:180px}
  .header .app-block .badge{display:inline-block;padding:2px 8px;border-radius:10px;background:#eef2ff;color:#312e81;font-size:10px;font-weight:600}
  .header .app-block .id{font-size:18px;font-weight:700;color:#312e81;margin-top:5px;font-family:monospace}
  h2{font-size:13px;background:#f1f5f9;padding:5px 8px;margin:15px 0 8px 0;border-left:3px solid #312e81}
  table{width:100%;border-collapse:collapse;margin-bottom:8px}
  table td,table th{padding:4px 8px;border:1px solid #ddd;text-align:left;vertical-align:top;font-size:11px}
  table th{background:#f8fafc;font-weight:600}
  .label{font-weight:600;width:28%;background:#fafafa}
  .footer{border-top:1px solid #ddd;margin-top:20px;padding-top:8px;font-size:10px;color:#666;text-align:center}
</style>
</head>
<body>
  <div class="header">
    <?php if ($logoData): ?><img src="<?= $logoData ?>" alt=""><?php endif; ?>
    <div class="institute">
      <h1><?= e($institute['name'] ?? 'Brainwavers') ?></h1>
      <?php if (!empty($institute['address'])): ?><div class="meta"><?= e($institute['address']) ?></div><?php endif; ?>
      <?php if (!empty($institute['mobile']) || !empty($institute['email'])): ?>
        <div class="meta">
          <?php if (!empty($institute['mobile'])): ?>Phone: <?= e($institute['mobile']) ?><?php endif; ?>
          <?php if (!empty($institute['mobile']) && !empty($institute['email'])): ?> | <?php endif; ?>
          <?php if (!empty($institute['email'])): ?>Email: <?= e($institute['email']) ?><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="app-block">
      <div class="badge">Application</div>
      <div class="id"><?= e($app['application_id']) ?></div>
      <div class="meta">Submitted: <?= e(format_datetime($app['submission_date'])) ?></div>
    </div>
  </div>

  <h2>Applicant Details</h2>
  <table>
    <tr><td class="label">Student Name</td><td><?= e($app['student_name']) ?></td>
        <td class="label">Date of Birth</td><td><?= !empty($app['dob']) ? e(format_date($app['dob'])) : '—' ?></td></tr>
    <tr><td class="label">Father's Name</td><td><?= e($app['father_name'] ?: '—') ?></td>
        <td class="label">Mother's Name</td><td><?= e($app['mother_name'] ?: '—') ?></td></tr>
    <tr><td class="label">Mobile Number</td><td><?= e($app['student_mobile']) ?></td>
        <td class="label">Email Address</td><td><?= e($app['student_email'] ?: '—') ?></td></tr>
  </table>

  <h2>Program Details</h2>
  <table>
    <tr><td class="label">Academic Section</td><td><?= e($app['section_name_snapshot']) ?></td></tr>
    <tr><td class="label">Institution</td><td><?= e($app['institution_name_snapshot']) ?></td></tr>
    <tr><td class="label">Course</td><td><?= e($app['course_name_snapshot']) ?></td></tr>
    <tr><td class="label">Duration</td><td><?= e($app['course_duration_snapshot'] ?: '—') ?></td></tr>
    <tr><td class="label">Course Price</td><td><?= (float)$app['course_price_snapshot'] > 0 ? e(format_money($app['course_price_snapshot'])) : 'Free' ?></td></tr>
  </table>

  <h2>Subjects</h2>
  <table>
    <tr><th style="width:50%">Compulsory Subjects</th><th>Extra Subjects</th></tr>
    <tr>
      <td>
        <?php if (empty($compulsory)): ?>—<?php else: foreach ($compulsory as $s): ?>
          • <?= e($s['subject_name_snapshot']) ?><br>
        <?php endforeach; endif; ?>
      </td>
      <td>
        <?php if (empty($extra)): ?>—<?php else: foreach ($extra as $s): ?>
          • <?= e($s['subject_name_snapshot']) ?><br>
        <?php endforeach; endif; ?>
      </td>
    </tr>
  </table>

  <?php if (!empty($answers)): ?>
    <h2>Additional Information</h2>
    <table>
      <?php foreach ($answers as $a): ?>
        <tr><td class="label"><?= e($a['field_label_snap']) ?></td><td colspan="3"><?= nl2br(e($a['field_value'])) ?></td></tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <?php if (!empty($documents)): ?>
    <h2>Documents Submitted</h2>
    <table>
      <tr><th>Document</th><th>Original Filename</th><th>Size</th></tr>
      <?php foreach ($documents as $d): ?>
        <tr>
          <td><?= e($d['document_name']) ?></td>
          <td><?= e($d['original_filename']) ?></td>
          <td><?= number_format((int)$d['file_size'] / 1024, 1) ?> KB</td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>

  <h2>Status &amp; Payment</h2>
  <table>
    <tr><td class="label">Application Status</td><td><?= e(ucwords(str_replace('_', ' ', $app['application_status']))) ?></td></tr>
    <tr><td class="label">Payment Status</td><td><?= e(ucwords(str_replace('_', ' ', $app['payment_status']))) ?></td></tr>
    <?php foreach ($payments as $p): if ($p['status'] !== 'paid') continue; ?>
      <tr>
        <td class="label">Payment Reference</td>
        <td><?= e($p['razorpay_payment_id'] ?: $p['razorpay_order_id'] ?: '—') ?>
            (<?= e($p['currency'] . ' ' . number_format((float)$p['amount'], 2)) ?>)</td>
      </tr>
    <?php endforeach; ?>
    <?php if (!empty($app['admin_notes'])): ?>
      <tr><td class="label">Administrative Notes</td><td><?= nl2br(e($app['admin_notes'])) ?></td></tr>
    <?php endif; ?>
  </table>

  <div class="footer">
    Printed on <?= date('d M Y, H:i') ?> — <?= e($institute['name'] ?? 'Brainwavers') ?> — Application <?= e($app['application_id']) ?>
  </div>
</body>
</html>
<?php
    return (string)ob_get_clean();
}