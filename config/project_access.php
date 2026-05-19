<?php

require_once __DIR__ . '/auth_middleware.php';

if (!function_exists('project_permissions_for_role')) {
    function project_permissions_for_role(?string $role = null): array
    {
        $role = $role ?? (string)current_user_role();

        $matrix = [
            'super_admin' => [
                'view_project_portal' => true,
                'view_assigned_projects' => true,
                'view_project_financials' => true,
                'manage_project_governance' => true,
                'manage_project_assignments' => true,
                'update_project_execution' => true,
                'update_field_operations' => true,
                'submit_client_feedback' => false,
            ],
            'engineer' => [
                'view_project_portal' => true,
                'view_assigned_projects' => true,
                'view_project_financials' => false,
                'manage_project_governance' => false,
                'manage_project_assignments' => false,
                'update_project_execution' => true,
                'update_field_operations' => false,
                'submit_client_feedback' => false,
            ],
            'foreman' => [
                'view_project_portal' => true,
                'view_assigned_projects' => true,
                'view_project_financials' => false,
                'manage_project_governance' => false,
                'manage_project_assignments' => false,
                'update_project_execution' => false,
                'update_field_operations' => true,
                'submit_client_feedback' => false,
            ],
            'client' => [
                'view_project_portal' => true,
                'view_assigned_projects' => true,
                'view_project_financials' => false,
                'manage_project_governance' => false,
                'manage_project_assignments' => false,
                'update_project_execution' => false,
                'update_field_operations' => false,
                'submit_client_feedback' => true,
            ],
        ];

        return $matrix[$role] ?? [
            'view_project_portal' => false,
            'view_assigned_projects' => false,
            'view_project_financials' => false,
            'manage_project_governance' => false,
            'manage_project_assignments' => false,
            'update_project_execution' => false,
            'update_field_operations' => false,
            'submit_client_feedback' => false,
        ];
    }
}

if (!function_exists('project_user_can')) {
    function project_user_can(string $permission, ?string $role = null): bool
    {
        $permissions = project_permissions_for_role($role);
        return (bool)($permissions[$permission] ?? false);
    }
}

if (!function_exists('project_role_summary_label')) {
    function project_role_summary_label(?string $role = null): string
    {
        $role = $role ?? (string)current_user_role();

        $labels = [
            'super_admin' => 'Full governance and approval control',
            'engineer' => 'Execution lead for assigned project work',
            'foreman' => 'Field visibility and site coordination only',
            'client' => 'Project visibility, approvals, and feedback only',
        ];

        return $labels[$role] ?? 'No project access';
    }
}
