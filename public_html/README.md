# Brainwavers Admission Management System

A database-driven, mobile-first admission management application for Brainwavers.
Manages multiple academic sections, institutions, courses, and subject
configurations with dynamic application forms, document uploads with 100 KB
image compression, optional Razorpay payments, application status tracking,
and a full administrative review workflow.

Built for **Hostinger shared hosting** on top of PHP 8.1+, MySQL/MariaDB,
and compiled Tailwind CSS. No frameworks, no persistent application server.

---

## Project Status

| Phase | Scope | Status |
|-------|-------|--------|
| 1 | Architecture, schema, README, deps, plan | ✅ delivered |
| 2 | Bootstrap, auth, CSRF, helpers, layout | ⏳ pending |
| 3 | Academic Sections + Institutions CRUD | ⏳ pending |
| 4 | Courses + Subjects (bulk Add Row) | ⏳ pending |
| 5 | Form Builder + public Apply + uploads | ⏳ pending |
| 6 | Razorpay (toggle, orders, verify, webhook) | ⏳ pending |
| 7 | Applications, Students, status check, exports | ⏳ pending |
| 8 | Settings, security pass, Hostinger deploy, tests | ⏳ pending |

Only Phase 1 assets exist at the moment: `database.sql`, `README.md`,
and this architecture document.

---

## Runtime requirements

- PHP **8.1+** with the extensions listed in `REQUIRED_EXTENSIONS`.
- MySQL 5.7.8+ or MariaDB 10.2.7+ (JSON support).
- HTTPS in production (mandatory for Razorpay).
- Writable `storage/` directory (outside web root).

Required PHP extensions:
