<?php
// Shared sidebar navigation config. Security stays in each protected page.

function shared_navigation_items_for_role(string $role): array
{
    $menus = [
        'admin' => [
            ['module' => 'dashboard', 'label' => 'Dashboard', 'mini' => 'Home', 'href' => '/codesamplecaps/ADMIN/sidebar/dashboard/php/dashboard.php', 'icon' => 'dashboard', 'active' => ['/ADMIN/sidebar/dashboard/php/dashboard.php']],
            ['module' => 'projects', 'label' => 'Projects', 'mini' => 'Proj', 'href' => '/codesamplecaps/ADMIN/sidebar/projects/php/projects.php', 'icon' => 'projects', 'active' => ['/ADMIN/sidebar/projects/php/projects.php', '/ADMIN/sidebar/projects/php/project_details.php', '/ADMIN/sidebar/project_details.php'], 'exclude_query' => ['view=trash', 'view=archive']],
            [
                'module' => 'client_requests',
                'label' => 'Client Requests',
                'mini' => 'Req',
                'icon' => 'inquiries',
                'children' => [
                    ['module' => 'inquiries', 'label' => 'Inquiries', 'mini' => 'Inq', 'href' => '/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php', 'icon' => 'inquiries', 'active' => ['/ADMIN/sidebar/inquiries/php/inquiries.php']],
                    ['module' => 'quotations', 'label' => 'Quotations', 'mini' => 'Quote', 'href' => '/codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php', 'icon' => 'quotations', 'active' => ['/ADMIN/sidebar/quotations/php/quotations.php']],
                ],
            ],
            [
                'module' => 'resources',
                'label' => 'Resources',
                'mini' => 'Res',
                'icon' => 'assets',
                'children' => [
                    ['module' => 'assets', 'label' => 'Assets', 'mini' => 'Asset', 'href' => '/codesamplecaps/ADMIN/sidebar/assets/php/assets.php', 'icon' => 'assets', 'active' => ['/ADMIN/sidebar/assets/php/assets.php']],
                    ['module' => 'inventory', 'label' => 'Inventory', 'mini' => 'Inv', 'href' => '/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php', 'icon' => 'inventory', 'active' => ['/ADMIN/sidebar/inventory/php/inventory.php']],
                    ['module' => 'procurement', 'label' => 'Procurement', 'mini' => 'Proc', 'href' => '/codesamplecaps/ADMIN/sidebar/procurement/php/procurement.php', 'icon' => 'procurement', 'active' => ['/ADMIN/sidebar/procurement/php/procurement.php']],
                ],
            ],
            ['module' => 'reports', 'label' => 'Reports', 'mini' => 'Rpt', 'href' => '/codesamplecaps/ADMIN/sidebar/reports/php/reports.php', 'icon' => 'reports', 'active' => ['/ADMIN/sidebar/reports/php/reports.php']],
            ['module' => 'activity', 'label' => 'Activity History', 'mini' => 'Audit', 'href' => '/codesamplecaps/ADMIN/sidebar/activity_history/php/activity_history.php', 'icon' => 'activity', 'active' => ['/ADMIN/sidebar/activity_history/php/activity_history.php']],
            ['module' => 'archive', 'label' => 'Archive', 'mini' => 'Arch', 'href' => '/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?view=trash', 'icon' => 'archive', 'active' => ['/ADMIN/sidebar/projects/php/projects.php?view=trash']],
        ],
        'engineer' => [
            ['module' => 'dashboard', 'label' => 'Dashboard', 'mini' => 'Home', 'href' => '/codesamplecaps/ENGINEER/dashboards/dashboard.php', 'icon' => 'dashboard', 'active' => ['/ENGINEER/dashboards/dashboard.php', '/ENGINEER/dashboards/overview.php', '/ENGINEER/dashboards/engineer_dashboard.php']],
            ['module' => 'tasks', 'label' => 'My Tasks', 'mini' => 'Tasks', 'href' => '/codesamplecaps/ENGINEER/dashboards/tasks.php', 'icon' => 'tasks', 'active' => ['/ENGINEER/dashboards/tasks.php']],
            ['module' => 'projects', 'label' => 'My Projects', 'mini' => 'Proj', 'href' => '/codesamplecaps/ENGINEER/dashboards/projects.php', 'icon' => 'projects', 'active' => ['/ENGINEER/dashboards/projects.php'], 'exclude_query' => ['view=trash', 'view=archive']],
            ['module' => 'archive', 'label' => 'Archive', 'mini' => 'Arch', 'href' => '/codesamplecaps/ENGINEER/dashboards/projects.php?view=trash', 'icon' => 'archive', 'active' => ['/ENGINEER/dashboards/projects.php?view=trash', '/ENGINEER/dashboards/projects.php?view=archive']],
            ['module' => 'procurement', 'label' => 'Procurement', 'mini' => 'Proc', 'href' => '/codesamplecaps/ENGINEER/dashboards/procurement.php', 'icon' => 'procurement', 'active' => ['/ENGINEER/dashboards/procurement.php']],
            ['module' => 'site_inspections', 'label' => 'Site Inspections', 'mini' => 'Site', 'href' => '/codesamplecaps/ENGINEER/dashboards/site_inspections.php', 'icon' => 'site', 'active' => ['/ENGINEER/dashboards/site_inspections.php']],
            ['module' => 'quotations', 'label' => 'Quotations', 'mini' => 'Quote', 'href' => '/codesamplecaps/ENGINEER/dashboards/quotations.php', 'icon' => 'quotations', 'active' => ['/ENGINEER/dashboards/quotations.php', '/ENGINEER/dashboards/quotation_form.php']],
            ['module' => 'reports', 'label' => 'Reports', 'mini' => 'Report', 'href' => '/codesamplecaps/ENGINEER/dashboards/reports.php', 'icon' => 'reports', 'active' => ['/ENGINEER/dashboards/reports.php']],
            ['module' => 'progress', 'label' => 'Progress Updates', 'mini' => 'Update', 'href' => '/codesamplecaps/ENGINEER/dashboards/progress_updates.php', 'icon' => 'progress', 'active' => ['/ENGINEER/dashboards/progress_updates.php']],
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
            ['module' => 'archive', 'label' => 'Archive', 'mini' => 'Arch', 'href' => '/codesamplecaps/FOREMAN/dashboards/projects.php?view=trash', 'icon' => 'archive', 'active' => ['/FOREMAN/dashboards/projects.php?view=trash', '/FOREMAN/dashboards/projects.php?view=archive']],
            ['module' => 'reports', 'label' => 'Reports', 'mini' => 'Rpt', 'href' => '/codesamplecaps/FOREMAN/dashboards/reports.php', 'icon' => 'reports', 'active' => ['/FOREMAN/dashboards/reports.php', '/FOREMAN/dashboards/report_list.php', '/FOREMAN/dashboards/report_detail.php']],
            ['module' => 'procurement', 'label' => 'Procurement', 'mini' => 'Proc', 'href' => '/codesamplecaps/FOREMAN/dashboards/procurement.php', 'icon' => 'procurement', 'active' => ['/FOREMAN/dashboards/procurement.php']],
            ['module' => 'quotation_reviews', 'label' => 'Quotation Reviews', 'mini' => 'Quote', 'href' => '/codesamplecaps/FOREMAN/dashboards/quotation_reviews.php', 'icon' => 'quotations', 'active' => ['/FOREMAN/dashboards/quotation_reviews.php']],
            ['module' => 'asset_status', 'label' => 'Asset Status', 'mini' => 'Asset', 'href' => '/codesamplecaps/FOREMAN/dashboards/asset_status.php', 'icon' => 'assets', 'active' => ['/FOREMAN/dashboards/asset_status.php']],
            ['module' => 'usage_logs', 'label' => 'Usage Logs', 'mini' => 'Logs', 'href' => '/codesamplecaps/FOREMAN/dashboards/usage_logs.php', 'icon' => 'activity', 'active' => ['/FOREMAN/dashboards/usage_logs.php']],
            ['module' => 'worker_summary', 'label' => 'Worker Summary', 'mini' => 'Work', 'href' => '/codesamplecaps/FOREMAN/dashboards/worker_summary.php', 'icon' => 'user', 'active' => ['/FOREMAN/dashboards/worker_summary.php']],
        ],
        'inventory_clerk' => [
            ['module' => 'dashboard', 'label' => 'Dashboard', 'mini' => 'Dash', 'href' => '/codesamplecaps/INVENTORY_CLERK/sidebar/dashboard.php', 'icon' => 'dashboard', 'active' => ['/INVENTORY_CLERK/sidebar/dashboard.php', '/INVENTORY_CLERK/dashboards/inventory_clerk_dashboard.php']],
            ['module' => 'inventory', 'label' => 'Inventory', 'mini' => 'Inv', 'href' => '/codesamplecaps/INVENTORY_CLERK/sidebar/inventory.php', 'icon' => 'inventory', 'active' => ['/INVENTORY_CLERK/sidebar/inventory.php']],
            ['module' => 'stock_in', 'label' => 'Stock In', 'mini' => 'In', 'href' => '/codesamplecaps/INVENTORY_CLERK/sidebar/stock_in.php', 'icon' => 'stock-in', 'active' => ['/INVENTORY_CLERK/sidebar/stock_in.php']],
            ['module' => 'stock_out', 'label' => 'Stock Out', 'mini' => 'Out', 'href' => '/codesamplecaps/INVENTORY_CLERK/sidebar/stock_out.php', 'icon' => 'stock-out', 'active' => ['/INVENTORY_CLERK/sidebar/stock_out.php']],
            ['module' => 'stock_history', 'label' => 'Stock History', 'mini' => 'Hist', 'href' => '/codesamplecaps/INVENTORY_CLERK/sidebar/stock_history.php', 'icon' => 'activity', 'active' => ['/INVENTORY_CLERK/sidebar/stock_history.php']],
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
        'inventory_clerk' => '/codesamplecaps/INVENTORY_CLERK/sidebar/dashboard.php',
        'client' => '/codesamplecaps/CLIENT/dashboards/client_dashboard.php',
    ][$role] ?? '/codesamplecaps/LOGIN/php/login.php';
}
