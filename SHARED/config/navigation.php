<?php
// Shared sidebar navigation config. Security stays in each protected page.

function shared_navigation_items_for_role(string $role): array
{
    $menus = [
        'admin' => [
            ['module' => 'dashboard', 'label' => 'Overview', 'mini' => 'Home', 'href' => '/codesamplecaps/ADMIN/sidebar/overview/php/overview.php', 'icon' => 'dashboard', 'active' => ['/ADMIN/sidebar/overview/php/overview.php']],
            ['module' => 'projects', 'label' => 'Projects', 'mini' => 'Proj', 'href' => '/codesamplecaps/ADMIN/sidebar/projects/projects.php', 'icon' => 'projects', 'active' => ['/ADMIN/sidebar/projects/projects.php', '/ADMIN/sidebar/projects/project_details.php', '/ADMIN/sidebar/project_details.php'], 'exclude_query' => ['view=trash', 'view=archive']],
            ['module' => 'assets', 'label' => 'Assets', 'mini' => 'Asset', 'href' => '/codesamplecaps/ADMIN/sidebar/assets.php', 'icon' => 'assets', 'active' => ['/ADMIN/sidebar/assets.php']],
            ['module' => 'quotations', 'label' => 'Quotations', 'mini' => 'Quote', 'href' => '/codesamplecaps/ADMIN/sidebar/quotations.php', 'icon' => 'quotations', 'active' => ['/ADMIN/sidebar/quotations.php']],
            ['module' => 'procurement', 'label' => 'Procurement', 'mini' => 'Proc', 'href' => '/codesamplecaps/ADMIN/sidebar/procurement.php', 'icon' => 'procurement', 'active' => ['/ADMIN/sidebar/procurement.php']],
            ['module' => 'inventory', 'label' => 'Inventory', 'mini' => 'Inv', 'href' => '/codesamplecaps/ADMIN/sidebar/inventory.php', 'icon' => 'inventory', 'active' => ['/ADMIN/sidebar/inventory.php']],
            ['module' => 'inquiries', 'label' => 'Inquiries', 'mini' => 'Inq', 'href' => '/codesamplecaps/ADMIN/sidebar/inquiries.php', 'icon' => 'inquiries', 'active' => ['/ADMIN/sidebar/inquiries.php']],
            ['module' => 'reports', 'label' => 'Reports', 'mini' => 'Rpt', 'href' => '/codesamplecaps/ADMIN/sidebar/reports.php', 'icon' => 'reports', 'active' => ['/ADMIN/sidebar/reports.php']],
            ['module' => 'activity', 'label' => 'Activity History', 'mini' => 'Audit', 'href' => '/codesamplecaps/ADMIN/sidebar/activity_history.php', 'icon' => 'activity', 'active' => ['/ADMIN/sidebar/activity_history.php']],
            ['module' => 'archive', 'label' => 'Archive', 'mini' => 'Arch', 'href' => '/codesamplecaps/ADMIN/sidebar/projects/projects.php?view=trash', 'icon' => 'archive', 'active' => ['/ADMIN/sidebar/projects/projects.php?view=trash']],
        ],
        'engineer' => [
            ['module' => 'overview', 'label' => 'Overview', 'mini' => 'Home', 'href' => '/codesamplecaps/ENGINEER/dashboards/overview.php', 'icon' => 'dashboard', 'active' => ['/ENGINEER/dashboards/overview.php', '/ENGINEER/dashboards/engineer_dashboard.php']],
            ['module' => 'tasks', 'label' => 'My Tasks', 'mini' => 'Tasks', 'href' => '/codesamplecaps/ENGINEER/dashboards/tasks.php', 'icon' => 'tasks', 'active' => ['/ENGINEER/dashboards/tasks.php']],
            ['module' => 'projects', 'label' => 'My Projects', 'mini' => 'Proj', 'href' => '/codesamplecaps/ENGINEER/dashboards/projects.php', 'icon' => 'projects', 'active' => ['/ENGINEER/dashboards/projects.php'], 'exclude_query' => ['view=trash', 'view=archive']],
            ['module' => 'archive', 'label' => 'Archive', 'mini' => 'Arch', 'href' => '/codesamplecaps/ENGINEER/dashboards/projects.php?view=trash', 'icon' => 'archive', 'active' => ['/ENGINEER/dashboards/projects.php?view=trash', '/ENGINEER/dashboards/projects.php?view=archive']],
            ['module' => 'procurement', 'label' => 'Procurement', 'mini' => 'Proc', 'href' => '/codesamplecaps/ENGINEER/dashboards/procurement.php', 'icon' => 'procurement', 'active' => ['/ENGINEER/dashboards/procurement.php']],
            ['module' => 'site_inspections', 'label' => 'Site Inspections', 'mini' => 'Site', 'href' => '/codesamplecaps/ENGINEER/dashboards/site_inspections.php', 'icon' => 'site', 'active' => ['/ENGINEER/dashboards/site_inspections.php']],
            ['module' => 'quotations', 'label' => 'Quotations', 'mini' => 'Quote', 'href' => '/codesamplecaps/ENGINEER/dashboards/quotations.php', 'icon' => 'quotations', 'active' => ['/ENGINEER/dashboards/quotations.php', '/ENGINEER/dashboards/quotation_form.php']],
            ['module' => 'reports', 'label' => 'Reports', 'mini' => 'Report', 'href' => '/codesamplecaps/ENGINEER/dashboards/reports.php', 'icon' => 'reports', 'active' => ['/ENGINEER/dashboards/reports.php']],
            ['module' => 'progress', 'label' => 'Progress Updates', 'mini' => 'Update', 'href' => '/codesamplecaps/ENGINEER/dashboards/progress_updates.php', 'icon' => 'progress', 'active' => ['/ENGINEER/dashboards/progress_updates.php']],
        ],
    ];

    return $menus[$role] ?? [];
}

function shared_navigation_role_home(string $role): string
{
    return [
        'admin' => '/codesamplecaps/ADMIN/sidebar/overview/php/overview.php',
        'engineer' => '/codesamplecaps/ENGINEER/dashboards/overview.php',
    ][$role] ?? '/codesamplecaps/LOGIN/php/login.php';
}
