<?php
declare(strict_types=1);
require __DIR__ . '/../../includes/bootstrap.php';
require __DIR__ . '/../../includes/application-service.php';

auth_require();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(400); exit('Invalid request.'); }

$stmt = db()->prepare(
    'SELECT a.*, s.name AS student_name, s.father_name, s.mother_name, s.dob,
            s.mobile AS student_mobile, s.email AS student_email
     FROM applications a
     JOIN students s ON s.id = a.student_id
     WHERE a.id = ? LIMIT 1'
);
$stmt->execute([$id]);
$app = $stmt->fetch();
if (!$app) { http_response_code(404); exit('Application not found.'); }

$stmt = db()->prepare('SELECT * FROM application_subject_selections WHERE application_id = ? ORDER BY subject_type, id');
$stmt->execute([$id]);
$allSubjects = $stmt->fetchAll();
$compulsory = array_values(array_filter($allSubjects, fn($s) => $s['subject_type'] === 'compulsory'));
$extra      = array_values(array_filter($allSubjects, fn($s) => $s['subject_type'] === 'extra'));

$stmt = db()->prepare('SELECT * FROM application_answers WHERE application_id = ? ORDER BY id');
$stmt->execute([$id]);
$answers = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM application_documents WHERE application_id = ? ORDER BY id');
$stmt->execute([$id]);
$documents = $stmt->fetchAll();

$stmt = db()->prepare('SELECT * FROM payment_transactions WHERE application_id = ? ORDER BY id DESC');
$stmt->execute([$id]);
$payments = $stmt->fetchAll();

$institute = [
    'name'      => (string)app_setting('institute_name', 'Brainwavers'),
    'mobile'    => (string)app_setting('institute_mobile', ''),
    'email'     => (string)app_setting('institute_email', ''),
    'address'   => (string)app_setting('institute_address', ''),
    'logo_path' => (string)app_setting('institute_logo', ''),
];

$html     = bw_render_application_print_html($app, $compulsory, $extra, $answers, $documents, $payments, $institute);
$filename = 'application-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', (string)$app['application_id']) . '.pdf';

log_activity('application.pdf_requested', 'application', $id);

// Attempt Dompdf if available
if (is_file(BW_VENDOR_DIR . '/autoload.php') && !class_exists('\\Dompdf\\Dompdf')) {
    require BW_VENDOR_DIR . '/autoload.php';
}

if (class_exists('\\Dompdf\\Dompdf')) {
    try {
        $dompdf = new \Dompdf\Dompdf(['isRemoteEnabled' => true]);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();
        $pdf = $dompdf->output();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    } catch (Throwable $e) {
        error_log('[pdf] ' . $e->getMessage());
    }
}

// Fallback: serve HTML with auto-print
header('Content-Type: text/html; charset=utf-8');
header('Content-Disposition: inline; filename="application-' . $id . '.html"');
header('X-BW-PDF-Fallback: browser-print');
echo str_replace('</body>', '<script>window.addEventListener("load",function(){setTimeout(function(){window.print();},300);});</script></body>', $html);
exit;