# Database Setup

## Fresh local installation

1. Start MySQL in XAMPP.
2. Create an empty database named `edge_project_asset_inventory_db` in phpMyAdmin.
3. Import `DB/schema.sql`.
4. Optional: import `DB/seeds/development_users.sql` for fake local accounts.
5. Run `C:\xampp\php\php.exe scripts/check_luzon_barangays.php` to load the local Luzon barangay reference data.
6. Copy `.env.example` to `.env`, then set local database and mail values. Never commit `.env`.

`DB/schema.sql` is the current canonical schema snapshot. It already contains the final structure represented by the older migrations. Do not run the historical migrations again after importing this file.

## Optional development accounts

These accounts use reserved `example.test` email addresses and fake phone numbers. They are for local development only.

| Role | Email |
| --- | --- |
| Super Admin | `superadmin@example.test` |
| Admin | `admin@example.test` |
| Engineer | `engineer@example.test` |
| Foreman | `foreman@example.test` |
| Inventory Clerk | `inventory@example.test` |
| Client | `client@example.test` |

Shared local password: `CapstoneDev!2026`

Change or remove all seeded accounts before any real deployment. The SQL seed stores only a password hash and leaves reset-token fields empty.

## Existing database upgrade

1. Make a real database backup outside the public repository.
2. Compare the existing schema with `DB/schema.sql`.
3. Apply only migrations that are missing from that database, in filename/date order.
4. Apply `DB/migrations/2026_08_30_asset_status_alignment.sql` only after reviewing current `assets.asset_status` and `assets.status` values.
5. Test login, projects, assets, inventory, procurement, inquiries, quotations, and password reset.

Do not run migrations from normal PHP page requests. The remaining runtime schema helpers are temporary compatibility code and must be removed only after an existing-database upgrade is tested.

## Asset status ownership

`assets.asset_status` is the canonical business status. The older `assets.status` column remains for compatibility. The alignment migration widens both ENUMs and copies the canonical value into the legacy column; it does not remove either column.
