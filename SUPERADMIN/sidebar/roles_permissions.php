<?php
require_once __DIR__ . '/../includes/page_shell.php';

$rolePermissions = [
    'Super Admin' => [
        'badge' => 'System',
        'permissions' => [
            'User Management',
            'Roles & Permissions',
            'Audit Logs',
            'System Settings',
            'Security Settings',
            'Backup & Restore',
            'View All Modules',
        ],
    ],
    'Admin' => [
        'badge' => 'Business',
        'permissions' => [
            'Overview',
            'Inquiries',
            'Projects',
            'Assets',
            'Quotations',
            'Reports',
            'Archive',
        ],
    ],
    'Engineer' => [
        'badge' => 'Field',
        'permissions' => [
            'Assigned Inspections',
            'Site Inspection Details',
            'Material Costing',
            'Labor Costing',
            'Submit Costing to Admin',
        ],
    ],
    'Foreman' => [
        'badge' => 'Field',
        'permissions' => [
            'Assigned Projects',
            'Daily Progress Updates',
            'Material Requests',
            'Return Assets',
        ],
    ],
    'Inventory Clerk' => [
        'badge' => 'Stock',
        'permissions' => [
            'Inventory Dashboard',
            'Inventory Items',
            'Stock In',
            'Stock Out',
            'Suppliers',
        ],
    ],
    'Client' => [
        'badge' => 'Portal',
        'permissions' => [
            'View Own Projects',
            'View Own Quotations',
            'View Progress Updates',
        ],
    ],
];

$permissionColumns = [
    'Users',
    'Audit',
    'Settings',
    'Inquiries',
    'Projects',
    'Inventory',
    'Costing',
    'Quotations',
    'Reports',
];

$permissionMatrix = [
    'Super Admin' => ['Users', 'Audit', 'Settings', 'Inquiries', 'Projects', 'Inventory', 'Costing', 'Quotations', 'Reports'],
    'Admin' => ['Inquiries', 'Projects', 'Inventory', 'Quotations', 'Reports'],
    'Engineer' => ['Costing'],
    'Foreman' => ['Projects', 'Inventory'],
    'Inventory Clerk' => ['Inventory'],
    'Client' => ['Projects', 'Quotations'],
];

superadmin_render_page(
    'Roles & Permissions',
    function () use ($rolePermissions, $permissionColumns, $permissionMatrix): void {
        ?>
        <section class="dashboard-panel roles-permissions-panel">
            <div class="roles-permissions-header">
                <h1>Roles & Permissions</h1>
                <span>Read-only</span>
            </div>

            <div class="roles-summary-grid">
                <?php foreach ($rolePermissions as $roleName => $roleData): ?>
                    <article class="role-summary-card">
                        <div class="role-summary-card__header">
                            <h2><?php echo htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8'); ?></h2>
                            <span><?php echo htmlspecialchars((string)$roleData['badge'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <ul>
                            <?php foreach ($roleData['permissions'] as $permission): ?>
                                <li><?php echo htmlspecialchars($permission, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                <?php endforeach; ?>
            </div>

            <div class="permission-matrix-card">
                <div class="permission-matrix-card__header">
                    <h2>Permission Matrix</h2>
                </div>
                <div class="permission-table-wrap">
                    <table class="permission-table">
                        <thead>
                            <tr>
                                <th>Role</th>
                                <?php foreach ($permissionColumns as $column): ?>
                                    <th><?php echo htmlspecialchars($column, ENT_QUOTES, 'UTF-8'); ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rolePermissions as $roleName => $roleData): ?>
                                <tr>
                                    <th><?php echo htmlspecialchars($roleName, ENT_QUOTES, 'UTF-8'); ?></th>
                                    <?php foreach ($permissionColumns as $column): ?>
                                        <?php $hasPermission = in_array($column, $permissionMatrix[$roleName] ?? [], true); ?>
                                        <td>
                                            <span class="permission-mark <?php echo $hasPermission ? 'permission-mark--yes' : 'permission-mark--no'; ?>">
                                                <?php echo $hasPermission ? 'Yes' : 'No'; ?>
                                            </span>
                                        </td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
        <?php
    },
    ['/codesamplecaps/SUPERADMIN/css/roles-permissions.css'],
    [],
    'roles-permissions-content'
);
