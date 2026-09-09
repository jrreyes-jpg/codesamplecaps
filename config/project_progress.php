<?php

if (!function_exists('project_progress_clamp')) {
    function project_progress_clamp(float $value): int
    {
        return (int)max(0, min(100, round($value)));
    }
}

if (!function_exists('project_progress_status_label')) {
    function project_progress_status_label(string $status): string
    {
        $labels = [
            'pending' => 'Pending',
            'ongoing' => 'In Progress',
            'completed' => 'Completed',
            'on-hold' => 'On Hold',
            'delayed' => 'Delayed',
        ];

        return $labels[$status] ?? ucfirst(str_replace('-', ' ', $status));
    }
}

if (!function_exists('build_role_project_progress')) {
    function build_role_project_progress(array $project, string $role): array
    {
        $totalTasks = max(0, (int)($project['total_tasks'] ?? 0));
        $completedTasks = max(0, (int)($project['completed_tasks'] ?? 0));
        $taskCompletion = $totalTasks > 0 ? ($completedTasks / $totalTasks) * 100 : 0;
        $labels = [
            'super_admin' => 'Portfolio Progress',
            'engineer' => 'Engineering Progress',
            'foreman' => 'Field Progress',
            'client' => 'Delivery Progress',
        ];

        return [
            'percent' => project_progress_clamp($taskCompletion),
            'label' => $labels[$role] ?? 'Project Progress',
            'summary' => $completedTasks . '/' . $totalTasks . ' tasks complete',
            'hint' => 'Task-based execution progress.',
        ];
    }
}
