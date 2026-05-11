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
        $status = strtolower(trim((string)($project['status'] ?? 'pending')));
        $totalTasks = max(0, (int)($project['total_tasks'] ?? 0));
        $completedTasks = max(0, (int)($project['completed_tasks'] ?? 0));
        $ongoingTasks = max(0, (int)($project['ongoing_tasks'] ?? 0));
        $delayedTasks = max(0, (int)($project['delayed_tasks'] ?? 0));
        $openTasks = array_key_exists('open_tasks', $project)
            ? max(0, (int)($project['open_tasks'] ?? 0))
            : max(0, $totalTasks - $completedTasks);

        $taskCompletion = $totalTasks > 0 ? ($completedTasks / $totalTasks) * 100 : 0;
        $ongoingPressure = $totalTasks > 0 ? ($ongoingTasks / $totalTasks) * 100 : 0;
        $delayPressure = $totalTasks > 0 ? ($delayedTasks / $totalTasks) * 100 : 0;
        $openPressure = ($completedTasks + $openTasks) > 0 ? ($openTasks / ($completedTasks + $openTasks)) * 100 : 0;

        switch ($role) {
            case 'super_admin':
                $base = match ($status) {
                    'completed' => 100,
                    'ongoing' => 52,
                    'on-hold' => 34,
                    'pending' => 18,
                    default => 22,
                };

                $percent = $status === 'completed'
                    ? 100
                    : $base + ($taskCompletion * 0.45) - ($delayPressure * 0.20);

                return [
                    'percent' => project_progress_clamp($percent),
                    'label' => 'Portfolio Progress',
                    'summary' => $completedTasks . '/' . $totalTasks . ' tasks complete • ' . $delayedTasks . ' delayed',
                    'hint' => 'Admin view blends project phase and delivery risk.',
                ];

            case 'engineer':
                $base = match ($status) {
                    'completed' => 100,
                    'ongoing' => 12,
                    'on-hold' => 8,
                    'pending' => 4,
                    default => 6,
                };

                $percent = $status === 'completed'
                    ? 100
                    : $base + $taskCompletion + ($ongoingPressure * 0.20) - ($delayPressure * 0.35);

                return [
                    'percent' => project_progress_clamp($percent),
                    'label' => 'Engineering Progress',
                    'summary' => $completedTasks . '/' . $totalTasks . ' tasks done • ' . $ongoingTasks . ' active • ' . $delayedTasks . ' blocked',
                    'hint' => 'Engineer progress rewards active execution but penalizes delay.',
                ];

            case 'foreman':
                $base = match ($status) {
                    'completed' => 100,
                    'ongoing' => 26,
                    'on-hold' => 18,
                    'pending' => 10,
                    default => 14,
                };

                $percent = $status === 'completed'
                    ? 100
                    : $base + ($taskCompletion * 0.70) + ($ongoingPressure * 0.10) - ($openPressure * 0.12);

                return [
                    'percent' => project_progress_clamp($percent),
                    'label' => 'Field Progress',
                    'summary' => $completedTasks . '/' . $totalTasks . ' site tasks complete • ' . $openTasks . ' still open',
                    'hint' => 'Foreman progress focuses on field completion and open site work.',
                ];

            case 'client':
                $base = match ($status) {
                    'completed' => 100,
                    'ongoing' => 30,
                    'on-hold' => 24,
                    'pending' => 14,
                    default => 18,
                };

                $percent = $status === 'completed'
                    ? 100
                    : $base + ($taskCompletion * 0.60) - ($delayPressure * 0.15);

                return [
                    'percent' => project_progress_clamp($percent),
                    'label' => 'Delivery Progress',
                    'summary' => project_progress_status_label($status) . ' • ' . $completedTasks . '/' . $totalTasks . ' tracked tasks done',
                    'hint' => 'Client progress emphasizes delivery stage and visible completion.',
                ];
        }

        return [
            'percent' => project_progress_clamp($taskCompletion),
            'label' => 'Project Progress',
            'summary' => $completedTasks . '/' . $totalTasks . ' tasks complete',
            'hint' => 'Task-based progress.',
        ];
    }
}
