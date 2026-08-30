# Phase 1 Database Audit

Date: 2026-08-30

## Canonical setup

- `DB/schema.sql` is the schema-only fresh-install snapshot.
- `DB/seeds/development_users.sql` is optional fake local data.
- `DB/migrations/` contains historical upgrade steps for existing databases.
- The old committed dump was incomplete and contained personal-looking data. It is not kept as the installer.

The old dump plus its documented migrations produced only 29 tables. The current application schema needs 49 tables. Missing areas included asset units, password-reset attempts, procurement, general quotations, project history, inventory movements, roles, and other support tables.

The Inquiry migration chain was also repaired: `2026_07_31_service_inquiry_admin_notes.sql` now creates `viewed_at` before the archive migration needs it, and `2026_08_04_service_inquiry_service_area.sql` now uses `IF NOT EXISTS`.

## Runtime schema mutations still present

These files can run DDL during a normal request. They are intentionally kept for compatibility until the canonical schema and existing-database upgrade are manually tested.

### Admin

- `ADMIN/services/admin_profile.php` - adds `users.profile_photo_path`.
- `ADMIN/sidebar/assets/php/assets.php` - adds asset classification/soft-delete columns, expands `asset_status`, creates category defaults, and adds stock threshold columns.
- `ADMIN/sidebar/procurement/php/procurement.php` - creates procurement tables and purchase-order approval columns.
- `ADMIN/sidebar/projects/php/project_details.php` - creates project inventory, return, budget, cost, and payment tables; adds project detail and soft-delete columns.
- `ADMIN/sidebar/projects/php/project_search_support.php` - adds project, user, and assignment search indexes.
- `ADMIN/sidebar/projects/php/projects.php` - creates deleted-user archive and project support tables; adds user/project soft-delete, detail, and search columns/indexes.

### Shared config and root endpoints

- `config/asset_unit_helpers.php` - creates asset-unit/deployment-unit tables, expands unit status, and adds unit references to scan/usage logs.
- `config/engineer_password_otp.php` - creates `engineer_password_otps`.
- `config/project_history.php` - creates `project_history`.
- `config/quotation_schema.php` - creates general quotation, review, history, and budget-breakdown tables.
- `config/service_barangays.php` - creates `service_barangays`.
- `log_asset_usage.php` - creates usage and scan-history tables.
- `profile_photo.php` - adds `users.profile_photo_path`.

### Engineer, Foreman, Inventory Clerk, Super Admin

- `ENGINEER/dashboards/procurement.php` - creates procurement/supplier/order tables and review/approval columns.
- `ENGINEER/includes/engineer_helpers.php` - adds Engineer profile columns and creates task updates.
- `FOREMAN/dashboards/procurement.php` - creates purchase requests/items and Engineer review columns.
- `INVENTORY_CLERK/includes/stock_helpers.php` - creates inventory stock movements.
- `SUPERADMIN/dashboards/super_admin_dashboard.php` - adds profile/trash columns and index; creates deleted-user archive.
- `SUPERADMIN/includes/user_management_actions.php` - adds user trash/status columns.

### Manual schema scripts, not normal page requests

- `scripts/setup_asset_tables.php`
- `scripts/setup_quotation_tables.php` through `config/quotation_schema.php`
- `scripts/update_user_delete_schema.php`

## Read-only schema compatibility checks

These areas query `INFORMATION_SCHEMA` or `SHOW COLUMNS` but do not directly change schema: `ADMIN/admin_sidebar.php`, `ADMIN/includes/db_helpers.php`, `ADMIN/services/project_service.php`, `ADMIN/sidebar/dashboard/php/dashboard_metrics.php`, `ADMIN/sidebar/inquiries/php/inquiries.php`, `api/live_updates.php`, `CLIENT/dashboards/client_dashboard.php`, `config/audit_log.php`, `config/inquiry_quotation_module.php`, `config/quotation_module.php`, `ENGINEER/dashboards/reports.php`, `FOREMAN/includes/foreman_helpers.php`, `get_asset.php`, `LOGIN/php/submit_inquiry.php`, and `SUPERADMIN/includes/superadmin_helpers.php`.

## Asset status finding

- Canonical field: `assets.asset_status`.
- Legacy field: `assets.status`.
- Admin Assets and asset-loss logic write `asset_status`.
- Usage logging can write both fields when both ENUMs accept `in_use`.
- Foreman previously preferred the legacy field, which could show stale data.
- Phase 1 makes Foreman read `asset_status` first and provides `DB/migrations/2026_08_30_asset_status_alignment.sql` for existing databases.
- Neither column is removed in Phase 1.

## Deferred integrity work

- Inquiry quotation, inspection, and costing tables have useful indexes but incomplete foreign keys.
- `inventory.asset_id` lacks a canonical foreign key/index relationship.
- `users.reset_token` and `users.token_expiry` are legacy fields; the active flow uses `password_reset_tokens`.
- Unused model classes reference `asset_project_links`, `foreman_profiles`, `project_comments`, `project_milestones`, and `time_logs`, which are not in the active schema. No active callers were found, so tables were not added blindly.
- Duplicate runtime DDL exists across Admin Projects, Project Details, Procurement roles, and profile helpers. Remove it only after an existing-database upgrade test.
