-- Optional fake accounts for local development only.
-- Shared development password is documented in DB/README.md.
-- Reset-token fields are intentionally left NULL.

INSERT IGNORE INTO roles (code, name, description) VALUES
    ('super_admin', 'Super Admin', 'IT/system administrator only'),
    ('admin', 'Admin', 'Business administrator'),
    ('engineer', 'Engineer', 'Project engineer'),
    ('foreman', 'Foreman', 'Field foreman'),
    ('inventory_clerk', 'Inventory Clerk', 'Inventory staff'),
    ('client', 'Client', 'Client portal user');

INSERT IGNORE INTO users
    (full_name, email, phone, password, role, status, created_by)
VALUES
    ('Dev Super Admin', 'superadmin@example.test', '+63-000-000-0001', '$2y$10$NFcWzwVHKRfm10JkFyffB.9DMcRgFBHb9laSSPExiAmD9tP8LJQN6', 'super_admin', 'active', NULL),
    ('Dev Admin', 'admin@example.test', '+63-000-000-0002', '$2y$10$NFcWzwVHKRfm10JkFyffB.9DMcRgFBHb9laSSPExiAmD9tP8LJQN6', 'admin', 'active', NULL),
    ('Dev Engineer', 'engineer@example.test', '+63-000-000-0003', '$2y$10$NFcWzwVHKRfm10JkFyffB.9DMcRgFBHb9laSSPExiAmD9tP8LJQN6', 'engineer', 'active', NULL),
    ('Dev Foreman', 'foreman@example.test', '+63-000-000-0004', '$2y$10$NFcWzwVHKRfm10JkFyffB.9DMcRgFBHb9laSSPExiAmD9tP8LJQN6', 'foreman', 'active', NULL),
    ('Dev Inventory Clerk', 'inventory@example.test', '+63-000-000-0005', '$2y$10$NFcWzwVHKRfm10JkFyffB.9DMcRgFBHb9laSSPExiAmD9tP8LJQN6', 'inventory_clerk', 'active', NULL),
    ('Dev Client', 'client@example.test', '+63-000-000-0006', '$2y$10$NFcWzwVHKRfm10JkFyffB.9DMcRgFBHb9laSSPExiAmD9tP8LJQN6', 'client', 'active', NULL);
