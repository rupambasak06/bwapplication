<?php
declare(strict_types=1);
if (!defined('BRAINWAVERS_BOOTSTRAPPED')) { exit('Direct access is not allowed.'); }

/** Field types shown in the admin "Add Field" dropdown (custom user fields only). */
function bw_field_type_options(): array
{
    return [
        'text'            => 'Text',
        'textarea'        => 'Textarea',
        'email'           => 'Email',
        'tel'             => 'Telephone',
        'number'          => 'Number',
        'date'            => 'Date',
        'select'          => 'Select Dropdown',
        'radio'           => 'Radio Buttons',
        'checkbox'        => 'Checkboxes',
        'section_heading' => 'Section Heading',
        'info_text'       => 'Informational Text',
        'document_upload' => 'Document Upload',
    ];
}

/** Default fields created for every new form. */
function bw_default_form_fields(): array
{
    return [
        ['field_key' => 'section_program',      'label' => 'Program Selection',    'field_type' => 'section_heading'],
        ['field_key' => 'institution',          'label' => 'Institution',          'field_type' => 'institution_selector', 'is_required' => 1, 'is_system' => 1],
        ['field_key' => 'course',               'label' => 'Course',               'field_type' => 'course_selector',      'is_required' => 1, 'is_system' => 1],
        ['field_key' => 'compulsory_subjects',  'label' => 'Compulsory Subjects',  'field_type' => 'compulsory_subjects',  'help_text' => 'These subjects are automatically included with your application.', 'is_system' => 1],
        ['field_key' => 'extra_subjects',       'label' => 'Extra Subjects',       'field_type' => 'extra_subjects',       'help_text' => 'Optionally choose additional subjects within the allowed limit.', 'is_system' => 1],
        ['field_key' => 'section_personal',     'label' => 'Personal Details',     'field_type' => 'section_heading'],
        ['field_key' => 'student_name',         'label' => 'Student Name',         'field_type' => 'text',  'is_required' => 1, 'max_length' => 180, 'is_system' => 1],
        ['field_key' => 'father_name',          'label' => "Father's Name",        'field_type' => 'text',  'max_length' => 180, 'is_system' => 1],
        ['field_key' => 'mother_name',          'label' => "Mother's Name",        'field_type' => 'text',  'max_length' => 180, 'is_system' => 1],
        ['field_key' => 'date_of_birth',        'label' => 'Date of Birth',        'field_type' => 'date',  'is_system' => 1],
        ['field_key' => 'mobile_number',        'label' => 'Mobile Number',        'field_type' => 'tel',   'is_required' => 1, 'max_length' => 30, 'is_system' => 1],
        ['field_key' => 'email_address',        'label' => 'Email Address',        'field_type' => 'email', 'is_required' => 1, 'max_length' => 180, 'is_system' => 1],
    ];
}

/**
 * Publish a form: snapshot all active fields + form metadata into form_versions,
 * update current_version_id, ensure no other form for the same section remains published.
 * Caller must be authenticated.
 */
function bw_publish_form(int $formId): void
{
    if ($formId <= 0) throw new RuntimeException('Invalid form.');

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM application_forms WHERE id = ?');
    $stmt->execute([$formId]);
    $form = $stmt->fetch();
    if (!$form) throw new RuntimeException('Form not found.');

    $stmt = $pdo->prepare(
        'SELECT * FROM form_fields WHERE form_id = ? AND is_active = 1 ORDER BY display_order, id'
    );
    $stmt->execute([$formId]);
    $fields = $stmt->fetchAll();

    if (empty($fields)) {
        throw new RuntimeException('Cannot publish an empty form. Add at least one field.');
    }

    $stmt = $pdo->prepare(
        'SELECT COALESCE(MAX(version_number), 0) + 1 FROM form_versions WHERE form_id = ?'
    );
    $stmt->execute([$formId]);
    $nextVersion = (int)$stmt->fetchColumn();

    $snapshot = [
        'form'     => $form,
        'fields'   => $fields,
        'taken_at' => gmdate('c'),
    ];

    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'INSERT INTO form_versions (form_id, version_number, snapshot, published_at, created_at)
             VALUES (?, ?, ?, NOW(), NOW())'
        )->execute([$formId, $nextVersion, json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);

        $versionId = (int)$pdo->lastInsertId();

        $pdo->prepare(
            'UPDATE application_forms SET status = "published", current_version_id = ?, updated_at = NOW()
             WHERE id = ?'
        )->execute([$versionId, $formId]);

        // Defensive: unpublish any other form on the same academic section
        $pdo->prepare(
            'UPDATE application_forms SET status = "draft", updated_at = NOW()
             WHERE academic_section_id = (SELECT academic_section_id FROM application_forms WHERE id = ?)
               AND id <> ? AND status = "published"'
        )->execute([$formId, $formId]);

        $pdo->commit();
        log_activity('form.published', 'application_form', $formId, ['version' => $nextVersion]);
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Render one form field as public HTML.
 * $ctx keys: errors (array), field_values (array), institution_id (int),
 *            course_id (int), extra_ids (int[]), institutions (array).
 */
function render_form_field(array $field, array $ctx = []): string
{
    $type   = (string)$field['field_type'];
    $key    = (string)$field['field_key'];
    $label  = (string)$field['label'];
    $req    = (int)$field['is_required'] === 1;
    $help   = (string)($field['help_text'] ?? '');
    $holder = (string)($field['placeholder'] ?? '');
    $errs   = $ctx['errors'] ?? [];
    $vals   = $ctx['field_values'] ?? [];
    $err    = (string)($errs[$key] ?? '');
    $val    = $vals[$key] ?? '';

    if ($type === 'section_heading') {
        return '<div class="mt-6 mb-2 pt-4 border-t border-slate-200">'
            . '<h3 class="text-base font-semibold">' . e($label) . '</h3>'
            . ($help !== '' ? '<p class="text-xs text-slate-500 mt-1">' . e($help) . '</p>' : '')
            . '</div>';
    }
    if ($type === 'info_text') {
        return '<div class="alert alert-info">' . e($help !== '' ? $help : $label) . '</div>';
    }

    $name = 'fields[' . $key . ']';
    $html = '';

    if (in_array($type, ['text', 'email', 'tel', 'number', 'date'], true)) {
        $html = '<input type="' . e($type) . '" name="' . e($name) . '" id="field_' . e($key) . '"'
            . ' value="' . e(is_array($val) ? '' : (string)$val) . '"'
            . ($holder ? ' placeholder="' . e($holder) . '"' : '')
            . ($req ? ' required' : '')
            . ' class="form-input' . ($err ? ' border-red-500' : '') . '">';
    } elseif ($type === 'textarea') {
        $html = '<textarea name="' . e($name) . '" id="field_' . e($key) . '" rows="3"'
            . ($holder ? ' placeholder="' . e($holder) . '"' : '')
            . ($req ? ' required' : '')
            . ' class="form-textarea' . ($err ? ' border-red-500' : '') . '">'
            . e(is_array($val) ? '' : (string)$val) . '</textarea>';
    } elseif ($type === 'select') {
        $options = [];
        if (!empty($field['options_json'])) {
            $decoded = json_decode((string)$field['options_json'], true);
            if (is_array($decoded)) $options = $decoded;
        }
        $html = '<select name="' . e($name) . '" id="field_' . e($key) . '"'
            . ($req ? ' required' : '') . ' class="form-select' . ($err ? ' border-red-500' : '') . '">'
            . '<option value="">' . e($holder ?: 'Choose…') . '</option>';
        foreach ($options as $opt) {
            $sel = ((string)$val === (string)$opt) ? ' selected' : '';
            $html .= '<option value="' . e((string)$opt) . '"' . $sel . '>' . e((string)$opt) . '</option>';
        }
        $html .= '</select>';
    } elseif ($type === 'radio') {
        $options = [];
        if (!empty($field['options_json'])) {
            $decoded = json_decode((string)$field['options_json'], true);
            if (is_array($decoded)) $options = $decoded;
        }
        $html = '<div class="space-y-2">';
        foreach ($options as $i => $opt) {
            $id      = 'f_' . $key . '_' . $i;
            $checked = ((string)$val === (string)$opt) ? ' checked' : '';
            $html   .= '<label class="flex items-center gap-2 text-sm">'
                . '<input type="radio" name="' . e($name) . '" value="' . e((string)$opt) . '" id="' . e($id) . '"'
                . $checked . ($req ? ' required' : '') . ' class="rounded border-slate-300">'
                . '<span>' . e((string)$opt) . '</span></label>';
        }
        $html .= '</div>';
    } elseif ($type === 'checkbox') {
        $options = [];
        if (!empty($field['options_json'])) {
            $decoded = json_decode((string)$field['options_json'], true);
            if (is_array($decoded)) $options = $decoded;
        }
        $valsArr = is_array($val) ? $val : [];
        $html = '<div class="space-y-2">';
        foreach ($options as $opt) {
            $checked = in_array((string)$opt, array_map('strval', $valsArr), true) ? ' checked' : '';
            $html   .= '<label class="flex items-center gap-2 text-sm">'
                . '<input type="checkbox" name="' . e($name) . '[]" value="' . e((string)$opt) . '"'
                . $checked . ' class="rounded border-slate-300">'
                . '<span>' . e((string)$opt) . '</span></label>';
        }
        $html .= '</div>';
    } elseif ($type === 'institution_selector') {
        $current = (int)($ctx['institution_id'] ?? 0);
        $html = '<select name="institution_id" id="institution_id" required'
            . ' class="form-select' . ($err ? ' border-red-500' : '') . '">'
            . '<option value="">Choose institution…</option>';
        foreach (($ctx['institutions'] ?? []) as $inst) {
            $sel = ($current === (int)$inst['id']) ? ' selected' : '';
            $html .= '<option value="' . (int)$inst['id'] . '"' . $sel . '>' . e($inst['name']) . '</option>';
        }
        $html .= '</select>';
    } elseif ($type === 'course_selector') {
        $html = '<select name="course_id" id="course_id" required class="form-select' . ($err ? ' border-red-500' : '') . '">'
            . '<option value="">Select an institution first…</option></select>';
    } elseif ($type === 'compulsory_subjects') {
        $html = '<div id="compulsory_subjects_container" class="rounded border border-slate-200 bg-slate-50 p-3 min-h-[44px]">'
            . '<p class="text-sm text-slate-500">Select a course to see its compulsory subjects.</p></div>';
    } elseif ($type === 'extra_subjects') {
        $chosen = implode(',', array_map('intval', $ctx['extra_ids'] ?? []));
        $html = '<div id="extra_subjects_container" data-chosen="' . e($chosen) . '" class="space-y-2">'
            . '<p class="text-sm text-slate-500">Select a course to see available extra subjects.</p></div>'
            . '<p id="extra_subjects_counter" class="text-xs text-slate-600 mt-2"></p>';
    } elseif ($type === 'document_upload') {
        $exts = ['jpg', 'jpeg', 'png', 'webp', 'pdf'];
        if (!empty($field['options_json'])) {
            $decoded = json_decode((string)$field['options_json'], true);
            if (is_array($decoded) && !empty($decoded)) $exts = $decoded;
        }
        $accept = '.' . implode(',.', array_map('strval', $exts));
        $html = '<input type="file" name="documents[' . e($key) . ']" id="doc_' . e($key) . '"'
            . ' accept="' . e($accept) . '"'
            . ($req ? ' required' : '')
            . ' class="form-input">'
            . '<p class="form-help">' . e($help !== '' ? $help : 'JPEG, PNG, WebP, or PDF. Images are compressed to 100 KB or below.')
            . '</p>';
    }

    return '<div class="field-block">'
        . '<label class="form-label" for="field_' . e($key) . '">' . e($label)
        . ($req ? ' <span class="text-red-600">*</span>' : '')
        . '</label>'
        . $html
        . ($help !== '' && $type !== 'document_upload' ? '<p class="form-help">' . e($help) . '</p>' : '')
        . ($err !== '' ? '<p class="form-error">' . e($err) . '</p>' : '')
        . '</div>';
}