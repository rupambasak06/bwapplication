<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
require __DIR__ . '/../includes/upload-handler.php';
require __DIR__ . '/../includes/application-service.php';

if (!is_post()) { redirect('/apply.php'); }
csrf_require();

if ((int)app_setting('website_available', '1') !== 1) {
    flash_set('error', 'Applications are temporarily disabled.');
    redirect('/apply.php');
}

$sectionId   = post_int('section_id', 0);
$formId      = post_int('_form_id', 0);
$postedVerId = post_int('_form_version_id', 0);

if ($sectionId <= 0 || $formId <= 0) {
    flash_set('error', 'Invalid submission.');
    redirect('/apply.php');
}

$pdo = db();

// Reload current published form
$stmt = $pdo->prepare(
    'SELECT f.*, s.name AS section_name
     FROM application_forms f
     JOIN academic_sections s ON s.id = f.academic_section_id
     WHERE f.id = ? AND f.academic_section_id = ? AND f.status = "published"
       AND s.is_active = 1 AND s.is_archived = 0
     LIMIT 1'
);
$stmt->execute([$formId, $sectionId]);
$form = $stmt->fetch();
if (!$form) {
    flash_set('error', 'This form is no longer available. Please start a new application.');
    redirect('/apply.php');
}

if ($postedVerId > 0 && (int)($form['current_version_id'] ?? 0) !== $postedVerId) {
    flash_set('error', 'The application form has been updated while you were filling it. Please review and submit again.');
    redirect('/apply.php?section_id=' . $sectionId);
}

// Load active fields
$stmt = $pdo->prepare('SELECT * FROM form_fields WHERE form_id = ? AND is_active = 1 ORDER BY display_order, id');
$stmt->execute([(int)$form['id']]);
$fields = $stmt->fetchAll();
$fieldByKey = [];
foreach ($fields as $f) $fieldByKey[$f['field_key']] = $f;

$stmt = $pdo->prepare('SELECT id, name FROM academic_sections WHERE id = ?');
$stmt->execute([$sectionId]);
$section = $stmt->fetch();

$errors = [];

// ---------- Hierarchy validation ----------
$institutionId = post_int('institution_id', 0);
$courseId      = post_int('course_id', 0);
$extraRaw      = $_POST['extra_subject_ids'] ?? [];
if (!is_array($extraRaw)) $extraRaw = [];
$extraSubjectIds = array_values(array_unique(array_map('intval', $extraRaw)));

$stmt = $pdo->prepare(
    'SELECT c.id, c.name AS course_name, c.duration, c.price, c.max_extra_subjects,
            i.name AS institution_name
     FROM courses c
     JOIN institutions i ON i.id = c.institution_id
     WHERE c.id = ? AND c.academic_section_id = ? AND c.institution_id = ?
       AND c.is_active = 1 AND c.is_archived = 0
       AND i.is_active = 1 AND i.is_archived = 0
     LIMIT 1'
);
$stmt->execute([$courseId, $sectionId, $institutionId]);
$course = $stmt->fetch();

if (!$course) {
    $errors['general'] = 'The selected course or institution is no longer available.';
}

$compulsorySubjects = [];
$chosenExtras = [];
$maxExtra = 0;

if (empty($errors)) {
    // Compulsory: always from DB
    $stmt = $pdo->prepare(
        'SELECT id, name FROM subjects WHERE course_id = ? AND type = "compulsory" AND is_active = 1
         ORDER BY display_order, id'
    );
    $stmt->execute([$courseId]);
    $compulsorySubjects = $stmt->fetchAll();
    if (empty($compulsorySubjects)) {
        $errors['general'] = 'This course has no subject configuration. Please contact the administration.';
    }

    // Extras: validate IDs against DB
    $stmt = $pdo->prepare(
        'SELECT id, name FROM subjects WHERE course_id = ? AND type = "extra" AND is_active = 1'
    );
    $stmt->execute([$courseId]);
    $validExtras = [];
    foreach ($stmt->fetchAll() as $r) $validExtras[(int)$r['id']] = $r;

    foreach ($extraSubjectIds as $sid) {
        if (!isset($validExtras[$sid])) {
            $errors['extra_subjects'] = 'One or more selected extra subjects are not valid for this course.';
            break;
        }
        $chosenExtras[] = $validExtras[$sid];
    }
    $maxExtra = (int)$course['max_extra_subjects'];
    if (count($chosenExtras) > $maxExtra) {
        $errors['extra_subjects'] = 'You may select at most ' . $maxExtra . ' extra subject(s).';
    }
}

// ---------- Dynamic fields validation ----------
$fieldValues = $_POST['fields'] ?? [];
if (!is_array($fieldValues)) $fieldValues = [];

$answerRows = [];
$skipTypes = ['section_heading', 'info_text', 'institution_selector', 'course_selector',
              'compulsory_subjects', 'extra_subjects', 'document_upload'];

foreach ($fields as $f) {
    if (in_array($f['field_type'], $skipTypes, true)) continue;
    $key = $f['field_key'];
    $val = $fieldValues[$key] ?? '';
    if (is_array($val)) $val = implode(', ', array_map('strval', $val));
    $val = trim((string)$val);

    if ((int)$f['is_required'] === 1 && $val === '') {
        $errors[$key] = $f['label'] . ' is required.';
        continue;
    }
    if ($val !== '' && $f['field_type'] === 'email' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
        $errors[$key] = 'Please enter a valid email address.';
        continue;
    }
    if ($val !== '' && !empty($f['min_length']) && mb_strlen($val) < (int)$f['min_length']) {
        $errors[$key] = $f['label'] . ' must be at least ' . (int)$f['min_length'] . ' characters.';
        continue;
    }
    if ($val !== '' && !empty($f['max_length']) && mb_strlen($val) > (int)$f['max_length']) {
        $errors[$key] = $f['label'] . ' must be at most ' . (int)$f['max_length'] . ' characters.';
        continue;
    }

    if ($val !== '') {
        $answerRows[] = [
            'field_id'    => (int)$f['id'],
            'field_key'   => $key,
            'field_label' => $f['label'],
            'field_type'  => $f['field_type'],
            'field_value' => $val,
        ];
    }
}

// ---------- Core student info ----------
$coreVal = function (string $key) use ($fieldValues): string {
    $v = $fieldValues[$key] ?? '';
    if (is_array($v)) $v = implode(', ', $v);
    return trim((string)$v);
};
$studentName   = $coreVal('student_name');
$studentMobile = $coreVal('mobile_number');
$studentEmail  = $coreVal('email_address');

if ($studentName === '')   $errors['student_name']   = 'Student name is required.';
if ($studentMobile === '') $errors['mobile_number']  = 'Mobile number is required.';
else {
    $digits = preg_replace('/\D+/', '', $studentMobile);
    if (strlen($digits) < 10 || strlen($digits) > 15) {
        $errors['mobile_number'] = 'Please enter a valid 10–15 digit mobile number.';
    }
}

// ---------- Document uploads ----------
$documentRows = [];
$documentFields = array_filter($fields, fn($f) => $f['field_type'] === 'document_upload');
$uploadDir = BW_STORAGE_DIR . '/uploads/documents';
$maxImageBytes = (int)app_setting('upload_max_image_kb', '100') * 1024;
$maxPdfBytes   = (int)app_setting('upload_max_file_mb', '5') * 1024 * 1024;
$strictPdf     = (int)app_setting('upload_strict_pdf_enabled', '0') === 1;

foreach ($documentFields as $f) {
    $key = $f['field_key'];
    $errCode = $_FILES['documents']['error'][$key] ?? UPLOAD_ERR_NO_FILE;

    if ($errCode === UPLOAD_ERR_NO_FILE) {
        if ((int)$f['is_required'] === 1) {
            $errors[$key] = $f['label'] . ' is required.';
        }
        continue;
    }

    $file = [
        'name'     => $_FILES['documents']['name'][$key],
        'type'     => $_FILES['documents']['type'][$key],
        'tmp_name' => $_FILES['documents']['tmp_name'][$key],
        'error'    => $_FILES['documents']['error'][$key],
        'size'     => $_FILES['documents']['size'][$key],
    ];

    $exts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
    if (!empty($f['options_json'])) {
        $parsed = json_decode((string)$f['options_json'], true);
        if (is_array($parsed) && !empty($parsed)) $exts = $parsed;
    }

    try {
        $result = process_document_upload($file, $uploadDir, $maxImageBytes, $maxPdfBytes, $exts, $strictPdf);
        $documentRows[] = [
            'form_field_id'       => (int)$f['id'],
            'document_name'       => $f['label'],
            'original_filename'   => $result['original_name'],
            'stored_filename'     => $result['stored_filename'],
            'storage_path'        => $result['stored_path'],
            'mime_type'           => $result['mime'],
            'file_size'           => $result['size'],
            'compression_applied' => $result['compression_applied'],
        ];
    } catch (UploadError $e) {
        $errors[$key] = $e->getMessage();
    }
}

// ---------- On error: clean files, store state in session, redirect ----------
if (!empty($errors)) {
    foreach ($documentRows as $d) delete_stored_file($d['storage_path']);
    $_SESSION['_apply_last'] = [
        'section_id' => $sectionId,
        'values'     => $_POST,
        'errors'     => $errors,
    ];
    flash_set('error', 'Please correct the errors below.');
    redirect('/apply.php?section_id=' . $sectionId);
}

// ---------- Payment path ----------
$paymentEnabled  = (int)app_setting('online_payment_enabled', '0') === 1;
$coursePrice     = (float)$course['price'];
$requiresPayment = $paymentEnabled && $coursePrice > 0;
$formVersionId   = !empty($form['current_version_id']) ? (int)$form['current_version_id'] : null;

$appStatus = $requiresPayment ? 'payment_pending' : 'submitted';
$payStatus = $requiresPayment ? 'pending'         : 'not_required';

try {
    $pdo->beginTransaction();

    $studentId = bw_find_or_create_student($pdo, [
        'name'        => $studentName,
        'father_name' => $coreVal('father_name')   ?: null,
        'mother_name' => $coreVal('mother_name')   ?: null,
        'dob'         => $coreVal('date_of_birth') ?: null,
        'mobile'      => $studentMobile,
        'email'       => $studentEmail             ?: null,
    ]);

    $applicationId = bw_generate_application_id($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO applications
            (application_id, student_id, form_id, form_version_id,
             academic_section_id, institution_id, course_id,
             section_name_snapshot, institution_name_snapshot, course_name_snapshot,
             course_duration_snapshot, course_price_snapshot, max_extra_snapshot,
             application_status, payment_status, submission_date, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW(), NOW())'
    );
    $stmt->execute([
        $applicationId,
        $studentId,
        (int)$form['id'],
        $formVersionId,
        $sectionId,
        $institutionId,
        $courseId,
        (string)$section['name'],
        (string)$course['institution_name'],
        (string)$course['course_name'],
        (string)$course['duration'],
        number_format($coursePrice, 2, '.', ''),
        $maxExtra,
        $appStatus,
        $payStatus,
    ]);
    $newAppId = (int)$pdo->lastInsertId();

    bw_save_application_subjects($pdo, $newAppId, $compulsorySubjects, $chosenExtras);
    bw_save_application_answers($pdo, $newAppId, $answerRows);
    bw_save_application_documents($pdo, $newAppId, $documentRows);

    $pdo->prepare(
        'INSERT INTO application_status_history
            (application_id, previous_status, new_status, notes, changed_by_admin_id, created_at)
         VALUES (?, NULL, ?, ?, NULL, NOW())'
    )->execute([
        $newAppId,
        $appStatus,
        $requiresPayment ? 'Application saved; awaiting online payment.' : 'Application submitted by applicant.',
    ]);

    $pdo->commit();

    $_SESSION['_app_result'] = [
        'id'               => $newAppId,
        'application_id'   => $applicationId,
        'mobile'           => $studentMobile,
        'name'             => $studentName,
        'status'           => $appStatus,
        'payment_status'   => $payStatus,
        'requires_payment' => $requiresPayment,
        'amount'           => $coursePrice,
        'section'          => (string)$section['name'],
        'institution'      => (string)$course['institution_name'],
        'course'           => (string)$course['course_name'],
        'submitted_at'     => gmdate('Y-m-d H:i:s'),
    ];

    log_activity('application.submitted', 'application', $newAppId, ['app_id' => $applicationId]);
    redirect('/application-success.php');

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    foreach ($documentRows as $d) delete_stored_file($d['storage_path']);
    error_log('[submit-application] ' . $e->getMessage());
    $_SESSION['_apply_last'] = [
        'section_id' => $sectionId,
        'values'     => $_POST,
        'errors'     => ['general' => 'Your application could not be saved. Please try again.'],
    ];
    flash_set('error', 'Your application could not be saved. Please try again.');
    redirect('/apply.php?section_id=' . $sectionId);
}