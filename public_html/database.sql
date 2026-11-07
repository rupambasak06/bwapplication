-- =====================================================================
-- Brainwavers Admission Management System
-- Database schema — Phase 1
-- Target: MySQL 5.7+ / MariaDB 10.3+
-- Character set: utf8mb4
-- =====================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- ADMIN USERS
-- ---------------------------------------------------------------------
CREATE TABLE admins (
  id              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username        VARCHAR(80)  NOT NULL,
  email           VARCHAR(180) NOT NULL,
  password_hash   VARCHAR(255) NOT NULL,
  full_name       VARCHAR(120) NOT NULL,
  role            ENUM('super_admin','admin') NOT NULL DEFAULT 'admin',
  is_active       TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at   DATETIME     NULL,
  created_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_admins_username (username),
  UNIQUE KEY uniq_admins_email (email),
  KEY idx_admins_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- LOGIN ATTEMPTS (rate limiting)
-- ---------------------------------------------------------------------
CREATE TABLE login_attempts (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  username    VARCHAR(80)  NOT NULL,
  ip_address  VARCHAR(45)  NOT NULL,
  success     TINYINT(1)   NOT NULL DEFAULT 0,
  attempted_at DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_attempt_user (username, attempted_at),
  KEY idx_attempt_ip   (ip_address, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- GLOBAL SETTINGS (key/value)
-- ---------------------------------------------------------------------
CREATE TABLE settings (
  setting_key   VARCHAR(100) NOT NULL,
  setting_value TEXT NULL,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (setting_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ID SEQUENCES (business IDs that cannot rely on AUTO_INCREMENT)
-- ---------------------------------------------------------------------
CREATE TABLE id_sequences (
  name          VARCHAR(60) NOT NULL,
  current_value BIGINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- ACADEMIC SECTIONS
-- ---------------------------------------------------------------------
CREATE TABLE academic_sections (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name          VARCHAR(150) NOT NULL,
  slug          VARCHAR(180) NOT NULL,
  description   TEXT NULL,
  image_path    VARCHAR(255) NULL,
  display_order INT NOT NULL DEFAULT 0,
  is_active     TINYINT(1) NOT NULL DEFAULT 1,
  is_archived   TINYINT(1) NOT NULL DEFAULT 0,
  created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_section_slug (slug),
  KEY idx_section_active_order (is_active, is_archived, display_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- INSTITUTIONS (belong to an academic section)
-- ---------------------------------------------------------------------
CREATE TABLE institutions (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_section_id INT UNSIGNED NOT NULL,
  name                VARCHAR(180) NOT NULL,
  slug                VARCHAR(200) NOT NULL,
  logo_path           VARCHAR(255) NULL,
  address             TEXT NOT NULL,
  district            VARCHAR(120) NULL,
  state               VARCHAR(120) NULL,
  contact_number      VARCHAR(40)  NULL,
  email               VARCHAR(180) NULL,
  website             VARCHAR(255) NULL,
  description         TEXT NULL,
  display_order       INT NOT NULL DEFAULT 0,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  is_archived         TINYINT(1) NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_inst_slug_per_section (academic_section_id, slug),
  UNIQUE KEY uniq_inst_id_section (id, academic_section_id),
  KEY idx_inst_section_active (academic_section_id, is_active, is_archived, display_order),
  CONSTRAINT fk_inst_section
    FOREIGN KEY (academic_section_id) REFERENCES academic_sections(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- COURSES (belong to section + institution, enforced by composite FK)
-- ---------------------------------------------------------------------
CREATE TABLE courses (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_section_id INT UNSIGNED NOT NULL,
  institution_id      INT UNSIGNED NOT NULL,
  name                VARCHAR(200) NOT NULL,
  code                VARCHAR(80)  NULL,
  description         TEXT NULL,
  price               DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  duration            VARCHAR(80) NOT NULL,
  min_extra_subjects  INT NOT NULL DEFAULT 0,
  max_extra_subjects  INT NOT NULL DEFAULT 0,
  display_order       INT NOT NULL DEFAULT 0,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  is_archived         TINYINT(1) NOT NULL DEFAULT 0,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_course_id_hierarchy (id, institution_id, academic_section_id),
  KEY idx_course_inst_section (institution_id, academic_section_id),
  KEY idx_course_section_active (academic_section_id, is_active, is_archived, display_order),
  KEY idx_course_inst_active (institution_id, is_active, is_archived, display_order),
  CONSTRAINT fk_course_inst_section
    FOREIGN KEY (institution_id, academic_section_id)
    REFERENCES institutions (id, academic_section_id)
    ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_course_section
    FOREIGN KEY (academic_section_id) REFERENCES academic_sections(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- SUBJECTS (belong to course hierarchy, compulsory OR extra)
-- ---------------------------------------------------------------------
CREATE TABLE subjects (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_section_id INT UNSIGNED NOT NULL,
  institution_id      INT UNSIGNED NOT NULL,
  course_id           INT UNSIGNED NOT NULL,
  name                VARCHAR(200) NOT NULL,
  type                ENUM('compulsory','extra') NOT NULL,
  display_order       INT NOT NULL DEFAULT 0,
  is_active           TINYINT(1) NOT NULL DEFAULT 1,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_subject_name_in_course_type (course_id, type, name),
  KEY idx_subject_course_hierarchy (course_id, institution_id, academic_section_id),
  KEY idx_subject_course_type_active (course_id, type, is_active, display_order),
  CONSTRAINT fk_subject_course_hierarchy
    FOREIGN KEY (course_id, institution_id, academic_section_id)
    REFERENCES courses (id, institution_id, academic_section_id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- APPLICATION FORMS (one active form per academic section)
-- ---------------------------------------------------------------------
CREATE TABLE application_forms (
  id                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  academic_section_id INT UNSIGNED NOT NULL,
  title               VARCHAR(200) NOT NULL,
  description         TEXT NULL,
  status              ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
  current_version_id  INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_form_section_status (academic_section_id, status),
  CONSTRAINT fk_form_section
    FOREIGN KEY (academic_section_id) REFERENCES academic_sections(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- FORM VERSIONS (immutable snapshots for historical integrity)
-- ---------------------------------------------------------------------
CREATE TABLE form_versions (
  id             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id        INT UNSIGNED NOT NULL,
  version_number INT NOT NULL,
  snapshot       JSON NULL,
  published_at   DATETIME NULL,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_form_version (form_id, version_number),
  CONSTRAINT fk_version_form
    FOREIGN KEY (form_id) REFERENCES application_forms(id)
    ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- FORM FIELDS (current field definitions — versioned via form_versions)
-- ---------------------------------------------------------------------
CREATE TABLE form_fields (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  form_id          INT UNSIGNED NOT NULL,
  field_key        VARCHAR(80)  NOT NULL,
  label            VARCHAR(200) NOT NULL,
  field_type       ENUM(
                     'text','textarea','email','tel','number','date',
                     'select','radio','checkbox',
                     'institution_selector','course_selector',
                     'compulsory_subjects','extra_subjects',
                     'document_upload','section_heading','info_text'
                   ) NOT NULL,
  placeholder      VARCHAR(255) NULL,
  help_text        VARCHAR(500) NULL,
  is_required      TINYINT(1) NOT NULL DEFAULT 0,
  min_length       INT NULL,
  max_length       INT NULL,
  options_json     JSON NULL,
  validation_regex VARCHAR(255) NULL,
  max_file_kb      INT NULL,
  display_order    INT NOT NULL DEFAULT 0,
  is_active        TINYINT(1) NOT NULL DEFAULT 1,
  is_system        TINYINT(1) NOT NULL DEFAULT 0,
  created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_form_field_key (form_id, field_key),
  KEY idx_form_field_order (form_id, display_order, is_active),
  CONSTRAINT fk_field_form
    FOREIGN KEY (form_id) REFERENCES application_forms(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- STUDENTS
-- ---------------------------------------------------------------------
CREATE TABLE students (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name        VARCHAR(180) NOT NULL,
  father_name VARCHAR(180) NULL,
  mother_name VARCHAR(180) NULL,
  dob         DATE NULL,
  mobile      VARCHAR(30)  NOT NULL,
  email       VARCHAR(180) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_student_mobile (mobile),
  KEY idx_student_email  (email),
  KEY idx_student_name   (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- APPLICATIONS
-- ---------------------------------------------------------------------
CREATE TABLE applications (
  id                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id            VARCHAR(40) NOT NULL,
  student_id                INT UNSIGNED NOT NULL,
  form_id                   INT UNSIGNED NOT NULL,
  form_version_id           INT UNSIGNED NULL,
  academic_section_id       INT UNSIGNED NOT NULL,
  institution_id            INT UNSIGNED NOT NULL,
  course_id                 INT UNSIGNED NOT NULL,
  section_name_snapshot     VARCHAR(150) NOT NULL,
  institution_name_snapshot VARCHAR(180) NOT NULL,
  course_name_snapshot      VARCHAR(200) NOT NULL,
  course_duration_snapshot  VARCHAR(80)  NULL,
  course_price_snapshot     DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  max_extra_snapshot        INT NOT NULL DEFAULT 0,
  application_status        ENUM(
                              'draft','submitted','under_review',
                              'documents_required','approved','rejected','payment_pending'
                            ) NOT NULL DEFAULT 'draft',
  payment_status            ENUM('not_required','pending','paid','failed','refunded')
                            NOT NULL DEFAULT 'not_required',
  submission_date           DATETIME NULL,
  admin_notes               TEXT NULL,
  created_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_application_business_id (application_id),
  KEY idx_app_status (application_status),
  KEY idx_app_payment_status (payment_status),
  KEY idx_app_section (academic_section_id),
  KEY idx_app_institution (institution_id),
  KEY idx_app_course (course_id),
  KEY idx_app_student (student_id),
  KEY idx_app_submission (submission_date),
  KEY idx_app_status_date (application_status, submission_date),
  CONSTRAINT fk_app_student   FOREIGN KEY (student_id)         REFERENCES students(id)            ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_app_form      FOREIGN KEY (form_id)            REFERENCES application_forms(id)   ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_app_version   FOREIGN KEY (form_version_id)    REFERENCES form_versions(id)       ON UPDATE CASCADE ON DELETE SET NULL,
  CONSTRAINT fk_app_section   FOREIGN KEY (academic_section_id) REFERENCES academic_sections(id)  ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_app_inst      FOREIGN KEY (institution_id)     REFERENCES institutions(id)        ON UPDATE CASCADE ON DELETE RESTRICT,
  CONSTRAINT fk_app_course    FOREIGN KEY (course_id)          REFERENCES courses(id)             ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- APPLICATION ANSWERS (dynamic form field values, with snapshots)
-- ---------------------------------------------------------------------
CREATE TABLE application_answers (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id      INT UNSIGNED NOT NULL,
  form_field_id       INT UNSIGNED NULL,
  field_key           VARCHAR(80)  NOT NULL,
  field_label_snap    VARCHAR(200) NOT NULL,
  field_type_snap     VARCHAR(40)  NOT NULL,
  field_value         MEDIUMTEXT NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_answer_app (application_id),
  CONSTRAINT fk_answer_app
    FOREIGN KEY (application_id) REFERENCES applications(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_answer_field
    FOREIGN KEY (form_field_id) REFERENCES form_fields(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- APPLICATION SUBJECT SELECTIONS
-- ---------------------------------------------------------------------
CREATE TABLE application_subject_selections (
  id                    INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id        INT UNSIGNED NOT NULL,
  subject_id            INT UNSIGNED NULL,
  subject_name_snapshot VARCHAR(200) NOT NULL,
  subject_type          ENUM('compulsory','extra') NOT NULL,
  created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_app_subject (application_id, subject_id, subject_type),
  KEY idx_sel_app (application_id),
  CONSTRAINT fk_sel_app
    FOREIGN KEY (application_id) REFERENCES applications(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_sel_subject
    FOREIGN KEY (subject_id) REFERENCES subjects(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- APPLICATION DOCUMENTS (metadata — files live outside web root)
-- ---------------------------------------------------------------------
CREATE TABLE application_documents (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id       INT UNSIGNED NOT NULL,
  form_field_id        INT UNSIGNED NULL,
  document_name        VARCHAR(200) NOT NULL,
  original_filename    VARCHAR(255) NOT NULL,
  stored_filename      VARCHAR(255) NOT NULL,
  storage_path         VARCHAR(500) NOT NULL,
  mime_type            VARCHAR(120) NOT NULL,
  file_size            INT UNSIGNED NOT NULL,
  compression_applied  TINYINT(1) NOT NULL DEFAULT 0,
  created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_doc_app (application_id),
  CONSTRAINT fk_doc_app
    FOREIGN KEY (application_id) REFERENCES applications(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_doc_field
    FOREIGN KEY (form_field_id) REFERENCES form_fields(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- PAYMENT TRANSACTIONS (decoupled from application status)
-- ---------------------------------------------------------------------
CREATE TABLE payment_transactions (
  id                 INT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id     INT UNSIGNED NOT NULL,
  razorpay_order_id  VARCHAR(120) NULL,
  razorpay_payment_id VARCHAR(120) NULL,
  amount             DECIMAL(10,2) NOT NULL,
  currency           VARCHAR(8) NOT NULL DEFAULT 'INR',
  status             ENUM('created','pending','paid','failed','refunded') NOT NULL DEFAULT 'created',
  signature_verified TINYINT(1) NOT NULL DEFAULT 0,
  failure_reason     VARCHAR(500) NULL,
  gateway_response   JSON NULL,
  created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uniq_razorpay_payment (razorpay_payment_id),
  KEY idx_pay_app (application_id),
  KEY idx_pay_order (razorpay_order_id),
  KEY idx_pay_status (status),
  CONSTRAINT fk_pay_app
    FOREIGN KEY (application_id) REFERENCES applications(id)
    ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- APPLICATION STATUS HISTORY
-- ---------------------------------------------------------------------
CREATE TABLE application_status_history (
  id                  BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  application_id      INT UNSIGNED NOT NULL,
  previous_status     VARCHAR(40) NULL,
  new_status          VARCHAR(40) NOT NULL,
  notes               TEXT NULL,
  changed_by_admin_id INT UNSIGNED NULL,
  created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_hist_app (application_id, created_at),
  CONSTRAINT fk_hist_app
    FOREIGN KEY (application_id) REFERENCES applications(id)
    ON UPDATE CASCADE ON DELETE CASCADE,
  CONSTRAINT fk_hist_admin
    FOREIGN KEY (changed_by_admin_id) REFERENCES admins(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- AUDIT LOG
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
  id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  admin_id    INT UNSIGNED NULL,
  action      VARCHAR(120) NOT NULL,
  target_type VARCHAR(80)  NULL,
  target_id   INT UNSIGNED NULL,
  details     JSON NULL,
  ip_address  VARCHAR(45) NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_admin  (admin_id, created_at),
  KEY idx_audit_target (target_type, target_id),
  KEY idx_audit_time   (created_at),
  CONSTRAINT fk_audit_admin
    FOREIGN KEY (admin_id) REFERENCES admins(id)
    ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- SEED DATA
-- No fake academic sections, institutions, courses, subjects, forms,
-- students, applications, or admins are inserted.
-- The initial admin is created securely through /admin/install.php.
-- =====================================================================

INSERT INTO id_sequences (name, current_value) VALUES
  ('application', 0);

INSERT INTO settings (setting_key, setting_value) VALUES
  ('institute_name',            'Brainwavers'),
  ('institute_mobile',          ''),
  ('institute_email',           ''),
  ('institute_logo',            ''),
  ('institute_address',         ''),
  ('institute_website',         ''),
  ('footer_text',               ''),
  ('application_number_prefix', 'BW'),
  ('pagination_size',           '20'),
  ('online_payment_enabled',    '0'),
  ('razorpay_key_id',           ''),
  ('razorpay_key_secret_enc',   ''),
  ('razorpay_mode',             'test'),
  ('razorpay_currency',         'INR'),
  ('razorpay_webhook_secret_enc', ''),
  ('upload_max_image_kb',       '100'),
  ('upload_max_file_mb',        '5'),
  ('website_available',         '1'),
  ('theme_color',               '#1e3a8a'),
  ('app_installed_at',          '');