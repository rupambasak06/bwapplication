<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/application-service.php';

auth_require();

$filters  = bw_application_filters();
$whereSql = $filters['where_sql'];
$params   = $filters['params'];

$stmt = db()->prepare(
    "SELECT a.application_id,
            s.name AS student_name,
            s.mobile AS student_mobile,
            s.email AS student_email,
            a.section_name_snapshot,
            a.institution_name_snapshot,
            a.course_name_snapshot,
            a.course_price_snapshot,
            a.application_status,
            a.payment_status,
            a.submission_date,
            (SELECT GROUP_CONCAT(subject_name_snapshot ORDER BY id SEPARATOR ' | ')
             FROM application_subject_selections
             WHERE application_id = a.id AND subject_type = 'compulsory') AS compulsory_subjects,
            (SELECT GROUP_CONCAT(subject_name_snapshot ORDER BY id SEPARATOR ' | ')
             FROM application_subject_selections
             WHERE application_id = a.id AND subject_type = 'extra') AS extra_subjects
     FROM applications a
     JOIN students s ON s.id = a.student_id
     $whereSql
     ORDER BY a.id DESC"
);
$stmt->execute($params);

function csv_safe($value): string
{
    $v = (string)($value ?? '');
    if ($v === '') return '';
    $first = $v[0];
    if (in_array($first, ['=', '+', '-', '@', "\t", "\r"], true)) {
        return "'" . $v;
    }
    return $v;
}

$filename = 'applications-' . gmdate('Ymd-His') . '.csv';

log_activity('application.exported', null, null, ['filters' => array_intersect_key($_GET, array_flip([
    'q','application_id','mobile','email','section_id','institution_id','course_id','subject_id','status','payment_status','from_date','to_date'
]))]);

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
header('Pragma: no-cache');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel reads special characters correctly
fwrite($out, "\xEF\xBB\xBF");

fputcsv($out, [
    'Application ID', 'Student Name', 'Mobile', 'Email',
    'Academic Section', 'Institution', 'Course', 'Course Price',
    'Compulsory Subjects', 'Extra Subjects',
    'Application Status', 'Payment Status', 'Submitted At',
]);

while ($row = $stmt->fetch()) {
    fputcsv($out, [
        csv_safe($row['application_id']),
        csv_safe($row['student_name']),
        csv_safe($row['student_mobile']),
        csv_safe($row['student_email']),
        csv_safe($row['section_name_snapshot']),
        csv_safe($row['institution_name_snapshot']),
        csv_safe($row['course_name_snapshot']),
        csv_safe($row['course_price_snapshot']),
        csv_safe($row['compulsory_subjects'] ?? ''),
        csv_safe($row['extra_subjects'] ?? ''),
        csv_safe($row['application_status']),
        csv_safe($row['payment_status']),
        csv_safe($row['submission_date']),
    ]);
}

fclose($out);
exit;