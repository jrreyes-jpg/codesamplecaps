<?php
// Shared sidebar navigation config. Security stays in each protected page.

function shared_navigation_items_for_role(string $role): array
{
    $menus = [
        'admin' => [
            ['module' => 'dashboard', 'label' => 'Dashboard', 'mini' => 'Home', 'href' => '/codesamplecaps/ADMIN/sidebar/dashboard/php/dashboard.php', 'icon' => 'dashboard', 'active' => ['/ADMIN/sidebar/dashboard/php/dashboard.php']],
            ['module' => 'inquiries', 'label' => 'Inquiries', 'mini' => 'Inq', 'href' => '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php', 'icon' => 'inquiries', 'active' => ['/ADMIN/sidebar/inquiries/php/inquiries.php']],
            ['module' => 'projects', 'label' => 'Projects', 'mini' => 'Proj', 'href' => '/codesamplecaps/ADMIN/sidebar/projects/php/projects.php', 'icon' => 'projects', 'active' => ['/ADMIN/sidebar/projects/php/projects.php', '/ADMIN/sidebar/projects/php/project_details.php', '/ADMIN/sidebar/project_details.php']],
            ['module' => 'quotations', 'label' => 'Quotations', 'mini' => 'Quote', 'href' => '/codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php', 'icon' => 'quotations', 'active' => ['/ADMIN/sidebar/quotations/php/quotations.php']],
            ['module' => 'assets', 'label' => 'Assets', 'mini' => 'Asset', 'href' => '/codesamplecaps/ADMIN/sidebar/assets/php/assets.php', 'icon' => 'assets', 'active' => ['/ADMIN/sidebar/assets/php/assets.php', '/ADMIN/sidebar/assets/php/asset_stock_history.php', '/ADMIN/sidebar/inventory/php/inventory.php?view=assets', '/ADMIN/sidebar/inventory/php/inventory.php?view=assets&status=attention', '/ADMIN/sidebar/inventory/php/inventory.php?view=assets&status=low-stock', '/ADMIN/sidebar/inventory/php/inventory.php?view=assets&status=out-of-stock', '/ADMIN/sidebar/inventory/php/inventory.php?view=assets&status=available']],
            ['module' => 'inventory', 'label' => 'Inventory', 'mini' => 'Inv', 'href' => '/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php', 'icon' => 'inventory', 'active' => ['/ADMIN/sidebar/inventory/php/inventory.php', '/ADMIN/sidebar/inventory/php/inventory.php?view=materials', '/ADMIN/sidebar/inventory/php/inventory.php?view=history', '/ADMIN/sidebar/inventory/php/material_stock_in.php'], 'exclude_query' => ['view=assets']],
        ],
        'engineer' => [
            ['module' => 'dashboard', 'label' => 'Dashboard', 'mini' => 'Home', 'href' => '/codesamplecaps/ENGINEER/dashboards/dashboard.php', 'icon' => 'dashboard', 'active' => ['/ENGINEER/dashboards/dashboard.php', '/ENGINEER/dashboards/engineer_dashboard.php']],
            ['module' => 'projects', 'label' => 'My Projects', 'mini' => 'Proj', 'href' => '/codesamplecaps/ENGINEER/dashboards/projects.php', 'icon' => 'projects', 'active' => ['/ENGINEER/dashboards/projects.php']],
            ['module' => 'site_inspections', 'label' => 'Site Inspections', 'mini' => 'Site', 'href' => '/codesamplecaps/ENGINEER/dashboards/site_inspections.php', 'icon' => 'site', 'active' => ['/ENGINEER/dashboards/site_inspections.php']],
        ],
        'super_admin' => [
            ['module' => 'users', 'label' => 'User Management', 'mini' => 'Users', 'href' => '/codesamplecaps/SUPERADMIN/sidebar/user_management.php', 'icon' => 'user', 'active' => ['/SUPERADMIN/sidebar/user_management.php', '/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=users', '/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=create']],
            ['module' => 'audit_logs', 'label' => 'Audit Logs', 'mini' => 'Audit', 'href' => '/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php', 'icon' => 'activity', 'active' => ['/SUPERADMIN/sidebar/audit_logs.php']],
            ['module' => 'roles_permissions', 'label' => 'Roles & Permissions', 'mini' => 'Role', 'href' => '/codesamplecaps/SUPERADMIN/sidebar/roles_permissions.php', 'icon' => 'security', 'active' => ['/SUPERADMIN/sidebar/roles_permissions.php']],
            ['module' => 'security_settings', 'label' => 'Security Settings', 'mini' => 'Sec', 'href' => '/codesamplecaps/SUPERADMIN/sidebar/security_settings.php', 'icon' => 'lock', 'active' => ['/SUPERADMIN/sidebar/security_settings.php']],
            ['module' => 'system_settings', 'label' => 'System Settings', 'mini' => 'Sys', 'href' => '/codesamplecaps/SUPERADMIN/sidebar/system_settings.php', 'icon' => 'settings', 'active' => ['/SUPERADMIN/sidebar/system_settings.php']],
            ['module' => 'backup_restore', 'label' => 'Backup & Restore', 'mini' => 'Bak', 'href' => '/codesamplecaps/SUPERADMIN/sidebar/backup_restore.php', 'icon' => 'backup', 'active' => ['/SUPERADMIN/sidebar/backup_restore.php']],
        ],
        'foreman' => [
            ['module' => 'overview', 'label' => 'Overview', 'mini' => 'Home', 'href' => '/codesamplecaps/FOREMAN/dashboards/foreman_dashboard.php', 'icon' => 'dashboard', 'active' => ['/FOREMAN/dashboards/foreman_dashboard.php']],
            ['module' => 'scan_asset', 'label' => 'Scan Asset', 'mini' => 'Scan', 'href' => '#', 'icon' => 'scan', 'type' => 'button', 'id' => 'qrScannerBtn', 'active' => []],
            ['module' => 'projects', 'label' => 'My Projects', 'mini' => 'Proj', 'href' => '/codesamplecaps/FOREMAN/dashboards/projects.php', 'icon' => 'projects', 'active' => ['/FOREMAN/dashboards/projects.php'], 'exclude_query' => ['view=trash', 'view=archive']],
            ['module' => 'reports', 'label' => 'Reports', 'mini' => 'Rpt', 'href' => '/codesamplecaps/FOREMAN/dashboards/reports.php', 'icon' => 'reports', 'active' => ['/FOREMAN/dashboards/reports.php', '/FOREMAN/dashboards/report_list.php', '/FOREMAN/dashboards/report_detail.php']],
            ['module' => 'asset_status', 'label' => 'Asset Status', 'mini' => 'Asset', 'href' => '/codesamplecaps/FOREMAN/dashboards/asset_status.php', 'icon' => 'assets', 'active' => ['/FOREMAN/dashboards/asset_status.php']],
            ['module' => 'usage_logs', 'label' => 'Usage Logs', 'mini' => 'Logs', 'href' => '/codesamplecaps/FOREMAN/dashboards/usage_logs.php', 'icon' => 'activity', 'active' => ['/FOREMAN/dashboards/usage_logs.php']],
            ['module' => 'worker_summary', 'label' => 'Worker Summary', 'mini' => 'Work', 'href' => '/codesamplecaps/FOREMAN/dashboards/worker_summary.php', 'icon' => 'user', 'active' => ['/FOREMAN/dashboards/worker_summary.php']],
        ],
        'inventory_clerk' => [
            ['module' => 'dashboard', 'label' => 'Dashboard', 'mini' => 'Dash', 'href' => '/codesamplecaps/INVENTORY_CLERK/dashboards/dashboard.php', 'icon' => 'dashboard', 'active' => ['/INVENTORY_CLERK/dashboards/dashboard.php', '/INVENTORY_CLERK/dashboards/dashboard.php']],
            ['module' => 'asset_reference', 'label' => 'Asset Reference', 'mini' => 'Assets', 'href' => '/codesamplecaps/INVENTORY_CLERK/dashboards/inventory.php', 'icon' => 'assets', 'active' => ['/INVENTORY_CLERK/dashboards/inventory.php']],
            [
                'module' => 'materials', 'label' => 'Consumable Materials', 'mini' => 'Mat', 'icon' => 'inventory',
                'collapsed_context_children' => true,
                'children' => [
                    ['module' => 'materials', 'label' => 'Material Quantities', 'mini' => 'Qty', 'href' => '/codesamplecaps/INVENTORY_CLERK/dashboards/materials.php', 'icon' => 'inventory', 'active' => ['/INVENTORY_CLERK/dashboards/materials.php']],
                    ['module' => 'delivery_verification', 'label' => 'Delivery Verification', 'mini' => 'Verify', 'href' => '/codesamplecaps/INVENTORY_CLERK/dashboards/delivery_verification.php', 'icon' => 'check', 'active' => ['/INVENTORY_CLERK/dashboards/delivery_verification.php']],
                    ['module' => 'material_history', 'label' => 'Material History', 'mini' => 'Hist', 'href' => '/codesamplecaps/INVENTORY_CLERK/dashboards/material_stock_history.php', 'icon' => 'activity', 'active' => ['/INVENTORY_CLERK/dashboards/material_stock_history.php']],
                ],
            ],
        ],
        'client' => [
            [
                'module' => 'dashboard',
                'label' => 'Dashboard',
                'mini' => 'Home',
                'href' => '/codesamplecaps/CLIENT/dashboards/client_dashboard.php',
                'icon' => 'dashboard',
                'active' => ['/CLIENT/dashboards/client_dashboard.php'],
            ],

            [
                'module' => 'projects',
                'label' => 'My Projects',
                'mini' => 'Proj',
                'href' => '/codesamplecaps/CLIENT/dashboards/projects.php',
                'icon' => 'projects',
                'active' => ['/CLIENT/dashboards/projects.php'],
            ],

            [
                'module' => 'quotations',
                'label' => 'My Quotations',
                'mini' => 'Quote',
                'href' => '/codesamplecaps/CLIENT/dashboards/quotations.php',
                'icon' => 'quotations',
                'active' => ['/CLIENT/dashboards/quotations.php'],
            ],
        ],

    ];

    return $menus[$role] ?? [];
}

function shared_navigation_role_home(string $role): string
{
    return [
        'admin' => '/codesamplecaps/ADMIN/sidebar/dashboard/php/dashboard.php',
        'engineer' => '/codesamplecaps/ENGINEER/dashboards/dashboard.php',
        'super_admin' => '/codesamplecaps/SUPERADMIN/sidebar/user_management.php',
        'foreman' => '/codesamplecaps/FOREMAN/dashboards/foreman_dashboard.php',
        'inventory_clerk' => '/codesamplecaps/INVENTORY_CLERK/dashboards/dashboard.php',
        'client' => '/codesamplecaps/CLIENT/dashboards/client_dashboard.php',
    ][$role] ?? '/codesamplecaps/LOGIN/php/login.php';
}
