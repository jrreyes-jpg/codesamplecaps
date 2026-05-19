<?php

require_once __DIR__ . '/../../config/project_access.php';
require_once __DIR__ . '/../../config/asset_unit_helpers.php';

ensure_asset_unit_tracking_schema($conn);

function foreman_table_exists(mysqli $conn, string $tableName): bool
{
    static $cache = [];

    if (array_key_exists($tableName, $cache)) {
        return $cache[$tableName];
    }

    $statement = $conn->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
         AND table_name = ?'
    );

    if (!$statement) {
        $cache[$tableName] = false;
        return false;
    }

    $statement->bind_param('s', $tableName);
    $statement->execute();
    $statement->bind_result($count);
    $statement->fetch();
    $statement->close();

    $cache[$tableName] = (int)$count > 0;
    return $cache[$tableName];
}

function foreman_column_exists(mysqli $conn, string $tableName, string $columnName): bool
{
    static $cache = [];
    $key = $tableName . '.' . $columnName;

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $statement = $conn->prepare(
        'SELECT COUNT(*)
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
         AND table_name = ?
         AND column_name = ?'
    );

    if (!$statement) {
        $cache[$key] = false;
        return false;
    }

    $statement->bind_param('ss', $tableName, $columnName);
    $statement->execute();
    $statement->bind_result($count);
    $statement->fetch();
    $statement->close();

    $cache[$key] = (int)$count > 0;
    return $cache[$key];
}

function foreman_format_datetime(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Not recorded';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y g:i A');
    } catch (Throwable $exception) {
        return $value;
    }
}

function foreman_status_label(?string $value): string
{
    $value = trim(str_replace(['-', '_'], ' ', (string)$value));
    return $value === '' ? 'Unknown' : ucwords($value);
}

function foreman_format_date(?string $value): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'Not set';
    }

    try {
        return (new DateTimeImmutable($value))->format('M j, Y');
    } catch (Throwable $exception) {
        return $value;
    }
}

function foreman_build_deadline_meta(?string $deadline, string $status): array
{
    $deadline = trim((string)$deadline);
    if ($deadline === '') {
        return [
            'label' => 'No deadline',
            'class' => 'status-badge--neutral',
        ];
    }

    $deadlineDate = DateTimeImmutable::createFromFormat('Y-m-d', $deadline);
    if (!$deadlineDate) {
        return [
            'label' => $deadline,
            'class' => 'status-badge--neutral',
        ];
    }

    if ($status === 'completed') {
        return [
            'label' => 'Delivered',
            'class' => 'status-badge--ok',
        ];
    }

    $today = new DateTimeImmutable('today');
    $days = (int)$today->diff($deadlineDate)->format('%r%a');

    if ($days < 0) {
        return [
            'label' => 'Overdue by ' . abs($days) . ' day' . (abs($days) === 1 ? '' : 's'),
            'class' => 'status-badge--danger',
        ];
    }

    if ($days <= 2) {
        return [
            'label' => $days === 0 ? 'Due today' : 'Due in ' . $days . ' day' . ($days === 1 ? '' : 's'),
            'class' => 'status-badge--warning',
        ];
    }

    return [
        'label' => 'Due ' . $deadlineDate->format('M j, Y'),
        'class' => 'status-badge--ok',
    ];
}

function foreman_excerpt(?string $value, int $limit = 120): string
{
    $value = trim((string)$value);

    if ($value === '') {
        return 'No notes recorded.';
    }

    if (mb_strlen($value) <= $limit) {
        return $value;
    }

    return rtrim(mb_substr($value, 0, $limit - 3)) . '...';
}

function foreman_profile_initials(string $name): string
{
    $name = trim($name);

    if ($name === '') {
        return 'FO';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'FO';
}

function foreman_asset_status_expression(mysqli $conn, string $tableAlias = 'assets'): string
{
    $qualifiedStatus = $tableAlias . '.status';
    $qualifiedAssetStatus = $tableAlias . '.asset_status';

    return foreman_column_exists($conn, 'assets', 'status')
        ? "COALESCE(NULLIF({$qualifiedStatus}, ''), {$qualifiedAssetStatus})"
        : $qualifiedAssetStatus;
}

function foreman_fetch_profile(mysqli $conn, int $userId): array
{
    $profile = [
        'full_name' => (string)($_SESSION['name'] ?? 'Foreman'),
        'email' => '',
        'phone' => '',
        'status' => 'active',
    ];

    $statement = $conn->prepare(
        'SELECT full_name, email, phone, status
         FROM users
         WHERE id = ?
         LIMIT 1'
    );

    if (!$statement) {
        return $profile;
    }

    $statement->bind_param('i', $userId);
    $statement->execute();
    $result = $statement->get_result();

    if ($result && $result->num_rows === 1) {
        $profile = array_merge($profile, $result->fetch_assoc());
    }

    $statement->close();

    return $profile;
}

function foreman_fetch_dashboard_data(mysqli $conn, int $userId): array
{
    $assetStatusExpression = foreman_asset_status_expression($conn, 'assets');

    $data = [
        'asset_summary' => [
            'total_assets' => 0,
            'available_assets' => 0,
            'in_use_assets' => 0,
            'maintenance_assets' => 0,
            'damaged_assets' => 0,
        ],
        'usage_summary' => [
            'logs_today' => 0,
            'workers_today' => 0,
            'logs_last_7_days' => 0,
        ],
        'scan_summary' => [
            'scans_today' => 0,
            'scans_last_7_days' => 0,
        ],
        'support_summary' => [
            'active_projects' => 0,
            'open_tasks' => 0,
        ],
        'assigned_projects' => [],
        'recent_usage_logs' => [],
        'recent_scan_rows' => [],
        'worker_summary_rows' => [],
    ];

    if (foreman_table_exists($conn, 'assets')) {
        $assetSummaryResult = $conn->query(
            "SELECT
                COUNT(*) AS total_assets,
                SUM(CASE WHEN {$assetStatusExpression} = 'available' THEN 1 ELSE 0 END) AS available_assets,
                SUM(CASE WHEN {$assetStatusExpression} = 'in_use' THEN 1 ELSE 0 END) AS in_use_assets,
                SUM(CASE WHEN {$assetStatusExpression} = 'maintenance' THEN 1 ELSE 0 END) AS maintenance_assets,
                SUM(CASE WHEN {$assetStatusExpression} IN ('damaged', 'lost') THEN 1 ELSE 0 END) AS damaged_assets
             FROM assets"
        );

        if ($assetSummaryResult) {
            $data['asset_summary'] = array_merge($data['asset_summary'], $assetSummaryResult->fetch_assoc() ?: []);
        }
    }

    if (foreman_table_exists($conn, 'asset_usage_logs')) {
        $usageSummaryStatement = $conn->prepare(
            "SELECT
                SUM(CASE WHEN DATE(used_at) = CURDATE() THEN 1 ELSE 0 END) AS logs_today,
                COUNT(DISTINCT CASE WHEN DATE(used_at) = CURDATE() THEN worker_name END) AS workers_today,
                SUM(CASE WHEN used_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS logs_last_7_days
             FROM asset_usage_logs
             WHERE foreman_id = ?"
        );

        if ($usageSummaryStatement) {
            $usageSummaryStatement->bind_param('i', $userId);
            $usageSummaryStatement->execute();
            $usageSummaryResult = $usageSummaryStatement->get_result();

            if ($usageSummaryResult && $usageSummaryResult->num_rows === 1) {
                $data['usage_summary'] = array_merge($data['usage_summary'], $usageSummaryResult->fetch_assoc());
            }

            $usageSummaryStatement->close();
        }

        $workerSummaryStatement = $conn->prepare(
            "SELECT
                worker_name,
                COUNT(*) AS usage_count,
                MAX(used_at) AS last_used_at
             FROM asset_usage_logs
             WHERE foreman_id = ?
             AND used_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             GROUP BY worker_name
             ORDER BY usage_count DESC, last_used_at DESC
             LIMIT 12"
        );

        if ($workerSummaryStatement) {
            $workerSummaryStatement->bind_param('i', $userId);
            $workerSummaryStatement->execute();
            $workerSummaryResult = $workerSummaryStatement->get_result();

            if ($workerSummaryResult) {
                $data['worker_summary_rows'] = $workerSummaryResult->fetch_all(MYSQLI_ASSOC);
            }

            $workerSummaryStatement->close();
        }
    }

    if (foreman_table_exists($conn, 'asset_scan_history')) {
        $scanSummaryStatement = $conn->prepare(
            "SELECT
                SUM(CASE WHEN DATE(scan_time) = CURDATE() THEN 1 ELSE 0 END) AS scans_today,
                SUM(CASE WHEN scan_time >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS scans_last_7_days
             FROM asset_scan_history
             WHERE foreman_id = ?"
        );

        if ($scanSummaryStatement) {
            $scanSummaryStatement->bind_param('i', $userId);
            $scanSummaryStatement->execute();
            $scanSummaryResult = $scanSummaryStatement->get_result();

            if ($scanSummaryResult && $scanSummaryResult->num_rows === 1) {
                $data['scan_summary'] = array_merge($data['scan_summary'], $scanSummaryResult->fetch_assoc());
            }

            $scanSummaryStatement->close();
        }
    }

    if (
        project_user_can('view_assigned_projects', 'foreman') &&
        foreman_table_exists($conn, 'project_assignments') &&
        foreman_table_exists($conn, 'projects')
    ) {
        $supportStatement = $conn->prepare(
            "SELECT
                COUNT(DISTINCT CASE WHEN p.status IN ('pending', 'ongoing', 'on-hold') THEN p.id END) AS active_projects,
                COALESCE(SUM(CASE WHEN task_totals.open_tasks IS NOT NULL THEN task_totals.open_tasks ELSE 0 END), 0) AS open_tasks
             FROM project_assignments pa
             INNER JOIN projects p ON p.id = pa.project_id
             LEFT JOIN (
                 SELECT
                    project_id,
                    SUM(CASE WHEN status IN ('pending', 'ongoing', 'delayed') THEN 1 ELSE 0 END) AS open_tasks
                 FROM tasks
                 GROUP BY project_id
             ) task_totals ON task_totals.project_id = p.id
             WHERE pa.engineer_id = ?
             AND p.status <> 'draft'"
        );

        if ($supportStatement) {
            $supportStatement->bind_param('i', $userId);
            $supportStatement->execute();
            $supportResult = $supportStatement->get_result();

            if ($supportResult && $supportResult->num_rows === 1) {
                $data['support_summary'] = array_merge($data['support_summary'], $supportResult->fetch_assoc());
            }

            $supportStatement->close();
        }

        $assignedProjectsStatement = $conn->prepare(
            "SELECT
                p.id,
                p.project_name,
                p.description,
                p.start_date,
                p.end_date,
                p.status,
                client.full_name AS client_name,
                creator.full_name AS project_owner_name,
                COALESCE(task_totals.total_tasks, 0) AS total_tasks,
                COALESCE(task_totals.completed_tasks, 0) AS completed_tasks,
                COALESCE(task_totals.open_tasks, 0) AS open_tasks,
                task_totals.next_deadline
             FROM project_assignments pa
             INNER JOIN projects p ON p.id = pa.project_id
             LEFT JOIN users client ON client.id = p.client_id
             LEFT JOIN users creator ON creator.id = p.created_by
             LEFT JOIN (
                 SELECT
                    project_id,
                    COUNT(*) AS total_tasks,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
                    SUM(CASE WHEN status IN ('pending', 'ongoing', 'delayed') THEN 1 ELSE 0 END) AS open_tasks,
                    MIN(CASE WHEN status <> 'completed' AND deadline IS NOT NULL THEN deadline END) AS next_deadline
                 FROM tasks
                 GROUP BY project_id
             ) task_totals ON task_totals.project_id = p.id
             WHERE pa.engineer_id = ?
             AND p.status <> 'draft'
             GROUP BY p.id, p.project_name, p.description, p.start_date, p.end_date, p.status, client.full_name, creator.full_name,
                      task_totals.total_tasks, task_totals.completed_tasks, task_totals.open_tasks, task_totals.next_deadline
             ORDER BY
                CASE p.status
                    WHEN 'ongoing' THEN 1
                    WHEN 'pending' THEN 2
                    WHEN 'on-hold' THEN 3
                    WHEN 'completed' THEN 4
                    ELSE 5
                END,
                p.created_at DESC,
                p.id DESC"
        );

        if ($assignedProjectsStatement) {
            $assignedProjectsStatement->bind_param('i', $userId);
            $assignedProjectsStatement->execute();
            $assignedProjectsResult = $assignedProjectsStatement->get_result();

            if ($assignedProjectsResult) {
                $data['assigned_projects'] = $assignedProjectsResult->fetch_all(MYSQLI_ASSOC);
            }

            $assignedProjectsStatement->close();
        }
    }

    if (foreman_table_exists($conn, 'asset_usage_logs') && foreman_table_exists($conn, 'assets')) {
        $usageLogsStatement = $conn->prepare(
            "SELECT
                aul.id,
                aul.worker_name,
                aul.notes,
                aul.used_at,
                a.asset_name,
                a.asset_type,
                au.unit_code,
                " . foreman_asset_status_expression($conn, 'a') . " AS resolved_status
             FROM asset_usage_logs aul
             INNER JOIN assets a ON a.id = aul.asset_id
             LEFT JOIN asset_units au ON au.id = aul.asset_unit_id
             WHERE aul.foreman_id = ?
             ORDER BY aul.used_at DESC, aul.id DESC
             LIMIT 20"
        );

        if ($usageLogsStatement) {
            $usageLogsStatement->bind_param('i', $userId);
            $usageLogsStatement->execute();
            $usageLogsResult = $usageLogsStatement->get_result();

            if ($usageLogsResult) {
                $data['recent_usage_logs'] = $usageLogsResult->fetch_all(MYSQLI_ASSOC);
            }

            $usageLogsStatement->close();
        }
    }

    if (foreman_table_exists($conn, 'asset_scan_history') && foreman_table_exists($conn, 'assets')) {
        $scanRowsStatement = $conn->prepare(
            "SELECT
                ash.id,
                ash.scan_time,
                ash.scan_device,
                a.asset_name,
                a.asset_type,
                au.unit_code
             FROM asset_scan_history ash
             INNER JOIN assets a ON a.id = ash.asset_id
             LEFT JOIN asset_units au ON au.id = ash.asset_unit_id
             WHERE ash.foreman_id = ?
             ORDER BY ash.scan_time DESC, ash.id DESC
             LIMIT 20"
        );

        if ($scanRowsStatement) {
            $scanRowsStatement->bind_param('i', $userId);
            $scanRowsStatement->execute();
            $scanRowsResult = $scanRowsStatement->get_result();

            if ($scanRowsResult) {
                $data['recent_scan_rows'] = $scanRowsResult->fetch_all(MYSQLI_ASSOC);
            }

            $scanRowsStatement->close();
        }
    }

    return $data;
}

function foreman_fetch_reports_data(mysqli $conn, int $userId): array
{
    $data = [
        'task_summary' => [
            'assigned_total' => 0,
            'due_today' => 0,
            'overdue' => 0,
            'blocked' => 0,
            'completed_week' => 0,
        ],
        'engineer_handoffs' => [],
        'project_execution_rows' => [],
        'procurement_rows' => [],
    ];

    if (foreman_table_exists($conn, 'tasks') && foreman_table_exists($conn, 'projects')) {
        $taskSummaryStatement = $conn->prepare(
            "SELECT
                COUNT(*) AS assigned_total,
                SUM(CASE WHEN t.status <> 'completed' AND t.deadline = CURDATE() THEN 1 ELSE 0 END) AS due_today,
                SUM(CASE WHEN t.status <> 'completed' AND t.deadline < CURDATE() THEN 1 ELSE 0 END) AS overdue,
                SUM(CASE WHEN t.status = 'delayed' THEN 1 ELSE 0 END) AS blocked,
                SUM(CASE WHEN t.status = 'completed' AND t.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS completed_week
             FROM tasks t
             INNER JOIN projects p ON p.id = t.project_id
             WHERE t.assigned_to = ?"
        );

        if ($taskSummaryStatement) {
            $taskSummaryStatement->bind_param('i', $userId);
            $taskSummaryStatement->execute();
            $taskSummaryResult = $taskSummaryStatement->get_result();

            if ($taskSummaryResult && $taskSummaryResult->num_rows === 1) {
                $data['task_summary'] = array_merge($data['task_summary'], $taskSummaryResult->fetch_assoc() ?: []);
            }

            $taskSummaryStatement->close();
        }

        $handoffStatement = $conn->prepare(
            "SELECT
                t.id,
                t.task_name,
                t.description,
                t.status,
                t.deadline,
                p.project_name,
                creator.full_name AS assigned_by_name
             FROM tasks t
             INNER JOIN projects p ON p.id = t.project_id
             LEFT JOIN users creator ON creator.id = t.created_by
             WHERE t.assigned_to = ?
             ORDER BY
                CASE
                    WHEN t.status = 'delayed' THEN 1
                    WHEN t.status = 'ongoing' THEN 2
                    WHEN t.status = 'pending' THEN 3
                    WHEN t.status = 'completed' THEN 4
                    ELSE 5
                END,
                t.deadline IS NULL,
                t.deadline ASC,
                t.updated_at DESC
             LIMIT 10"
        );

        if ($handoffStatement) {
            $handoffStatement->bind_param('i', $userId);
            $handoffStatement->execute();
            $handoffResult = $handoffStatement->get_result();

            if ($handoffResult) {
                $data['engineer_handoffs'] = $handoffResult->fetch_all(MYSQLI_ASSOC);
            }

            $handoffStatement->close();
        }

        if (foreman_table_exists($conn, 'project_assignments')) {
            $projectExecutionStatement = $conn->prepare(
                "SELECT
                    p.id,
                    p.project_name,
                    p.status,
                    COALESCE(task_totals.assigned_tasks, 0) AS assigned_tasks,
                    COALESCE(task_totals.completed_tasks, 0) AS completed_tasks,
                    COALESCE(task_totals.blocked_tasks, 0) AS blocked_tasks,
                    task_totals.next_deadline,
                    creator.full_name AS engineer_name
                 FROM project_assignments pa
                 INNER JOIN projects p ON p.id = pa.project_id
                 LEFT JOIN users creator ON creator.id = p.created_by
                 LEFT JOIN (
                     SELECT
                        t.project_id,
                        COUNT(*) AS assigned_tasks,
                        SUM(CASE WHEN t.status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
                        SUM(CASE WHEN t.status = 'delayed' THEN 1 ELSE 0 END) AS blocked_tasks,
                        MIN(CASE WHEN t.status <> 'completed' AND t.deadline IS NOT NULL THEN t.deadline END) AS next_deadline
                     FROM tasks t
                     WHERE t.assigned_to = ?
                     GROUP BY t.project_id
                 ) task_totals ON task_totals.project_id = p.id
                 WHERE pa.engineer_id = ?
                 ORDER BY
                    CASE p.status
                        WHEN 'ongoing' THEN 1
                        WHEN 'pending' THEN 2
                        WHEN 'on-hold' THEN 3
                        WHEN 'completed' THEN 4
                        ELSE 5
                    END,
                    p.created_at DESC
                 LIMIT 8"
            );

            if ($projectExecutionStatement) {
                $projectExecutionStatement->bind_param('ii', $userId, $userId);
                $projectExecutionStatement->execute();
                $projectExecutionResult = $projectExecutionStatement->get_result();

                if ($projectExecutionResult) {
                    $data['project_execution_rows'] = $projectExecutionResult->fetch_all(MYSQLI_ASSOC);
                }

                $projectExecutionStatement->close();
            }
        }
    }

    if (foreman_table_exists($conn, 'purchase_requests') && foreman_table_exists($conn, 'projects')) {
        $procurementStatement = $conn->prepare(
            "SELECT
                pr.id,
                pr.request_no,
                pr.status,
                pr.needed_date,
                pr.request_type,
                p.project_name,
                pri.item_description,
                pri.quantity_requested,
                pri.unit,
                po.admin_approval_status
             FROM purchase_requests pr
             INNER JOIN projects p ON p.id = pr.project_id
             LEFT JOIN purchase_request_items pri ON pri.purchase_request_id = pr.id
             LEFT JOIN purchase_orders po ON po.purchase_request_id = pr.id
             WHERE pr.requested_by = ?
             ORDER BY pr.created_at DESC
             LIMIT 8"
        );

        if ($procurementStatement) {
            $procurementStatement->bind_param('i', $userId);
            $procurementStatement->execute();
            $procurementResult = $procurementStatement->get_result();

            if ($procurementResult) {
                $data['procurement_rows'] = $procurementResult->fetch_all(MYSQLI_ASSOC);
            }

            $procurementStatement->close();
        }
    }

    return $data;
}

function foreman_report_is_completed(string $type, string $status): bool
{
    $status = trim(strtolower($status));

    if ($type === 'procurement') {
        return in_array($status, ['approved', 'cancelled'], true);
    }

    return $status === 'completed';
}

function foreman_report_is_blocked(string $type, string $status): bool
{
    $status = trim(strtolower($status));

    if ($type === 'procurement') {
        return $status === 'engineer_rejected';
    }

    return $status === 'delayed';
}

function foreman_report_priority_rank(array $report): int
{
    $type = (string)($report['type'] ?? '');
    $status = (string)($report['status'] ?? '');
    $dueDate = trim((string)($report['due_date'] ?? ''));
    $today = date('Y-m-d');

    if (!foreman_report_is_completed($type, $status) && $dueDate !== '' && $dueDate < $today) {
        return 1;
    }

    if (foreman_report_is_blocked($type, $status)) {
        return 2;
    }

    if (!foreman_report_is_completed($type, $status) && $dueDate === $today) {
        return 3;
    }

    if (foreman_report_is_completed($type, $status)) {
        return 5;
    }

    return 4;
}

function foreman_report_priority_label(array $report): string
{
    return match (foreman_report_priority_rank($report)) {
        1 => 'Overdue',
        2 => 'Blocked',
        3 => 'Due Today',
        5 => 'Completed',
        default => 'Active',
    };
}

function foreman_report_priority_class(array $report): string
{
    return match (foreman_report_priority_rank($report)) {
        1 => 'report-pill--danger',
        2 => 'report-pill--blocked',
        3 => 'report-pill--warning',
        5 => 'report-pill--success',
        default => 'report-pill--neutral',
    };
}

function foreman_report_due_class(array $report): string
{
    $type = (string)($report['type'] ?? '');
    $status = (string)($report['status'] ?? '');
    $dueDate = trim((string)($report['due_date'] ?? ''));
    $today = date('Y-m-d');

    if ($dueDate === '') {
        return '';
    }

    if (!foreman_report_is_completed($type, $status) && $dueDate < $today) {
        return 'report-text--danger';
    }

    if (!foreman_report_is_completed($type, $status) && $dueDate === $today) {
        return 'report-text--warning';
    }

    return '';
}

function foreman_report_matches_filters(array $report, array $filters): bool
{
    $q = trim((string)($filters['q'] ?? ''));
    $statusFilter = trim((string)($filters['status'] ?? ''));
    $quick = trim((string)($filters['quick'] ?? ''));
    $type = (string)($report['type'] ?? '');
    $status = (string)($report['status'] ?? '');
    $dueDate = trim((string)($report['due_date'] ?? ''));
    $today = date('Y-m-d');

    if ($q !== '') {
        $haystack = mb_strtolower(implode(' ', array_filter([
            (string)($report['title'] ?? ''),
            (string)($report['project_name'] ?? ''),
            (string)($report['status'] ?? ''),
            (string)($report['details'] ?? ''),
            (string)($report['reference_no'] ?? ''),
        ])));

        if (!str_contains($haystack, mb_strtolower($q))) {
            return false;
        }
    }

    if ($statusFilter !== '' && $status !== $statusFilter) {
        return false;
    }

    if ($quick === 'overdue' && !( !foreman_report_is_completed($type, $status) && $dueDate !== '' && $dueDate < $today)) {
        return false;
    }

    if ($quick === 'due_today' && !( !foreman_report_is_completed($type, $status) && $dueDate === $today)) {
        return false;
    }

    if ($quick === 'blocked' && !foreman_report_is_blocked($type, $status)) {
        return false;
    }

    if ($quick === 'completed' && !foreman_report_is_completed($type, $status)) {
        return false;
    }

    return true;
}

function foreman_sort_reports(array &$reports): void
{
    usort(
        $reports,
        static function (array $left, array $right): int {
            $leftPriority = foreman_report_priority_rank($left);
            $rightPriority = foreman_report_priority_rank($right);

            if ($leftPriority !== $rightPriority) {
                return $leftPriority <=> $rightPriority;
            }

            $leftDue = trim((string)($left['due_date'] ?? ''));
            $rightDue = trim((string)($right['due_date'] ?? ''));

            if ($leftDue === '' && $rightDue !== '') {
                return 1;
            }

            if ($leftDue !== '' && $rightDue === '') {
                return -1;
            }

            if ($leftDue !== $rightDue) {
                return strcmp($leftDue, $rightDue);
            }

            return strcmp((string)($right['updated_at'] ?? ''), (string)($left['updated_at'] ?? ''));
        }
    );
}

function foreman_fetch_normalized_reports(mysqli $conn, int $userId, array $filters = []): array
{
    $reports = [];

    if (foreman_table_exists($conn, 'tasks') && foreman_table_exists($conn, 'projects')) {
        $taskStatement = $conn->prepare(
            "SELECT
                t.id,
                t.task_name AS title,
                t.status,
                t.deadline AS due_date,
                t.updated_at,
                t.description AS details,
                p.project_name
             FROM tasks t
             INNER JOIN projects p ON p.id = t.project_id
             WHERE t.assigned_to = ?
             ORDER BY t.updated_at DESC"
        );

        if ($taskStatement) {
            $taskStatement->bind_param('i', $userId);
            $taskStatement->execute();
            $taskResult = $taskStatement->get_result();

            if ($taskResult) {
                while ($row = $taskResult->fetch_assoc()) {
                    $report = [
                        'id' => (int)($row['id'] ?? 0),
                        'title' => (string)($row['title'] ?? 'Untitled task'),
                        'type' => 'task',
                        'project_name' => (string)($row['project_name'] ?? 'Unknown project'),
                        'status' => (string)($row['status'] ?? 'pending'),
                        'due_date' => $row['due_date'] ?? null,
                        'updated_at' => $row['updated_at'] ?? null,
                        'details' => (string)($row['details'] ?? ''),
                        'reference_no' => 'TASK-' . (int)($row['id'] ?? 0),
                    ];

                    if (foreman_report_matches_filters($report, $filters)) {
                        $reports[] = $report;
                    }
                }
            }

            $taskStatement->close();
        }
    }

    if (foreman_table_exists($conn, 'purchase_requests') && foreman_table_exists($conn, 'projects')) {
        $procurementStatement = $conn->prepare(
            "SELECT
                pr.id,
                pr.request_no,
                pr.status,
                pr.needed_date AS due_date,
                pr.updated_at,
                pr.remarks,
                pr.request_type,
                pr.site_location,
                p.project_name,
                items.item_title,
                items.item_details
             FROM purchase_requests pr
             INNER JOIN projects p ON p.id = pr.project_id
             LEFT JOIN (
                 SELECT
                    pri.purchase_request_id,
                    MIN(pri.item_description) AS item_title,
                    GROUP_CONCAT(
                        CONCAT(
                            pri.item_description,
                            ' (',
                            TRIM(TRAILING '.00' FROM pri.quantity_requested),
                            ' ',
                            pri.unit,
                            ')'
                        )
                        ORDER BY pri.id ASC
                        SEPARATOR '\n'
                    ) AS item_details
                 FROM purchase_request_items pri
                 GROUP BY pri.purchase_request_id
             ) items ON items.purchase_request_id = pr.id
             WHERE pr.requested_by = ?
             ORDER BY pr.updated_at DESC"
        );

        if ($procurementStatement) {
            $procurementStatement->bind_param('i', $userId);
            $procurementStatement->execute();
            $procurementResult = $procurementStatement->get_result();

            if ($procurementResult) {
                while ($row = $procurementResult->fetch_assoc()) {
                    $detailParts = array_filter([
                        trim((string)($row['remarks'] ?? '')),
                        trim((string)($row['item_details'] ?? '')),
                        trim((string)($row['site_location'] ?? '')) !== '' ? 'Site location: ' . trim((string)$row['site_location']) : '',
                        trim((string)($row['request_type'] ?? '')) !== '' ? 'Request type: ' . foreman_status_label((string)$row['request_type']) : '',
                    ]);

                    $report = [
                        'id' => (int)($row['id'] ?? 0),
                        'title' => (string)($row['item_title'] ?? $row['request_no'] ?? 'Purchase request'),
                        'type' => 'procurement',
                        'project_name' => (string)($row['project_name'] ?? 'Unknown project'),
                        'status' => (string)($row['status'] ?? 'submitted'),
                        'due_date' => $row['due_date'] ?? null,
                        'updated_at' => $row['updated_at'] ?? null,
                        'details' => implode("\n\n", $detailParts),
                        'reference_no' => (string)($row['request_no'] ?? ('PR-' . (int)($row['id'] ?? 0))),
                    ];

                    if (foreman_report_matches_filters($report, $filters)) {
                        $reports[] = $report;
                    }
                }
            }

            $procurementStatement->close();
        }
    }

    foreman_sort_reports($reports);

    return $reports;
}

function foreman_fetch_report_summary(mysqli $conn, int $userId): array
{
    $allReports = foreman_fetch_normalized_reports($conn, $userId);
    $today = date('Y-m-d');
    $weekAgo = date('Y-m-d H:i:s', strtotime('-7 days'));
    $summary = [
        'overdue' => 0,
        'due_today' => 0,
        'blocked' => 0,
        'completed_this_week' => 0,
        'critical_items' => [],
    ];

    foreach ($allReports as $report) {
        $type = (string)($report['type'] ?? '');
        $status = (string)($report['status'] ?? '');
        $dueDate = trim((string)($report['due_date'] ?? ''));
        $updatedAt = (string)($report['updated_at'] ?? '');

        if (!foreman_report_is_completed($type, $status) && $dueDate !== '' && $dueDate < $today) {
            $summary['overdue']++;
        }

        if (!foreman_report_is_completed($type, $status) && $dueDate === $today) {
            $summary['due_today']++;
        }

        if (foreman_report_is_blocked($type, $status)) {
            $summary['blocked']++;
        }

        if (foreman_report_is_completed($type, $status) && $updatedAt !== '' && $updatedAt >= $weekAgo) {
            $summary['completed_this_week']++;
        }
    }

    $summary['critical_items'] = array_slice(
        array_values(
            array_filter(
                $allReports,
                static fn(array $report): bool => foreman_report_priority_rank($report) <= 3
            )
        ),
        0,
        5
    );

    return $summary;
}

function foreman_fetch_report_detail(mysqli $conn, int $userId, string $type, int $id): ?array
{
    if ($id <= 0) {
        return null;
    }

    if ($type === 'task' && foreman_table_exists($conn, 'tasks') && foreman_table_exists($conn, 'projects')) {
        $statement = $conn->prepare(
            "SELECT
                t.id,
                t.task_name AS title,
                t.status,
                t.deadline AS due_date,
                t.updated_at,
                t.created_at,
                t.description AS details,
                p.project_name
             FROM tasks t
             INNER JOIN projects p ON p.id = t.project_id
             WHERE t.id = ?
             AND t.assigned_to = ?
             LIMIT 1"
        );

        if ($statement) {
            $statement->bind_param('ii', $id, $userId);
            $statement->execute();
            $result = $statement->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $statement->close();

            if ($row) {
                return [
                    'id' => (int)$row['id'],
                    'title' => (string)$row['title'],
                    'type' => 'task',
                    'project_name' => (string)($row['project_name'] ?? 'Unknown project'),
                    'status' => (string)($row['status'] ?? 'pending'),
                    'due_date' => $row['due_date'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                    'created_at' => $row['created_at'] ?? null,
                    'details' => (string)($row['details'] ?? ''),
                    'reference_no' => 'TASK-' . (int)$row['id'],
                ];
            }
        }
    }

    if ($type === 'procurement' && foreman_table_exists($conn, 'purchase_requests') && foreman_table_exists($conn, 'projects')) {
        $statement = $conn->prepare(
            "SELECT
                pr.id,
                pr.request_no,
                pr.status,
                pr.needed_date AS due_date,
                pr.updated_at,
                pr.created_at,
                pr.request_type,
                pr.site_location,
                pr.remarks,
                p.project_name,
                items.item_title,
                items.item_details
             FROM purchase_requests pr
             INNER JOIN projects p ON p.id = pr.project_id
             LEFT JOIN (
                 SELECT
                    pri.purchase_request_id,
                    MIN(pri.item_description) AS item_title,
                    GROUP_CONCAT(
                        CONCAT(
                            pri.item_description,
                            ' (',
                            TRIM(TRAILING '.00' FROM pri.quantity_requested),
                            ' ',
                            pri.unit,
                            ')'
                        )
                        ORDER BY pri.id ASC
                        SEPARATOR '\n'
                    ) AS item_details
                 FROM purchase_request_items pri
                 GROUP BY pri.purchase_request_id
             ) items ON items.purchase_request_id = pr.id
             WHERE pr.id = ?
             AND pr.requested_by = ?
             LIMIT 1"
        );

        if ($statement) {
            $statement->bind_param('ii', $id, $userId);
            $statement->execute();
            $result = $statement->get_result();
            $row = $result ? $result->fetch_assoc() : null;
            $statement->close();

            if ($row) {
                $detailParts = array_filter([
                    trim((string)($row['remarks'] ?? '')),
                    trim((string)($row['item_details'] ?? '')),
                    trim((string)($row['site_location'] ?? '')) !== '' ? 'Site location: ' . trim((string)$row['site_location']) : '',
                    trim((string)($row['request_type'] ?? '')) !== '' ? 'Request type: ' . foreman_status_label((string)$row['request_type']) : '',
                ]);

                return [
                    'id' => (int)$row['id'],
                    'title' => (string)($row['item_title'] ?? $row['request_no'] ?? 'Purchase request'),
                    'type' => 'procurement',
                    'project_name' => (string)($row['project_name'] ?? 'Unknown project'),
                    'status' => (string)($row['status'] ?? 'submitted'),
                    'due_date' => $row['due_date'] ?? null,
                    'updated_at' => $row['updated_at'] ?? null,
                    'created_at' => $row['created_at'] ?? null,
                    'details' => implode("\n\n", $detailParts),
                    'reference_no' => (string)($row['request_no'] ?? ('PR-' . (int)$row['id'])),
                ];
            }
        }
    }

    return null;
}

function foreman_build_report_pdf_filename(array $report): string
{
    $reference = trim((string)($report['reference_no'] ?? 'report'));
    $reference = preg_replace('/[^A-Za-z0-9._-]+/', '-', $reference) ?? 'report';
    $reference = trim($reference, '-._');

    if ($reference === '') {
        $reference = 'report';
    }

    return 'foreman-report-' . strtolower($reference) . '.pdf';
}

function foreman_pdf_encode_text(string $text): string
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $text);
    $encoded = iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $normalized);

    if ($encoded === false) {
        $encoded = $normalized;
    }

    return str_replace(
        ['\\', '(', ')'],
        ['\\\\', '\\(', '\\)'],
        $encoded
    );
}

function foreman_pdf_wrap_text(string $text, int $maxChars): array
{
    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');

    if ($text === '') {
        return [''];
    }

    $words = preg_split('/\s+/u', $text) ?: [];
    $lines = [];
    $currentLine = '';

    foreach ($words as $word) {
        $candidate = $currentLine === '' ? $word : $currentLine . ' ' . $word;

        if (mb_strlen($candidate) <= $maxChars) {
            $currentLine = $candidate;
            continue;
        }

        if ($currentLine !== '') {
            $lines[] = $currentLine;
            $currentLine = '';
        }

        while (mb_strlen($word) > $maxChars) {
            $lines[] = mb_substr($word, 0, $maxChars);
            $word = mb_substr($word, $maxChars);
        }

        $currentLine = $word;
    }

    if ($currentLine !== '') {
        $lines[] = $currentLine;
    }

    return $lines === [] ? [''] : $lines;
}

function foreman_stream_report_pdf(array $report, string $generatedBy, string $generatedAt, bool $download = true): void
{
    $title = trim((string)($report['title'] ?? 'Foreman Report'));
    $reference = trim((string)($report['reference_no'] ?? 'N/A'));
    $projectName = trim((string)($report['project_name'] ?? 'Unknown project'));
    $reportType = ucfirst((string)($report['type'] ?? 'Report'));
    $status = foreman_status_label((string)($report['status'] ?? 'Unknown'));
    $priority = foreman_report_priority_label($report);
    $dueDate = foreman_format_date($report['due_date'] ?? null);
    $createdAt = foreman_format_datetime($report['created_at'] ?? null);
    $updatedAt = foreman_format_datetime($report['updated_at'] ?? null);
    $details = trim((string)($report['details'] ?? ''));

    $lineSpecs = [];
    $addLine = static function (string $text, string $font, int $size, int $height) use (&$lineSpecs): void {
        $lineSpecs[] = [
            'text' => $text,
            'font' => $font,
            'size' => $size,
            'height' => $height,
        ];
    };
    $addWrappedBlock = static function (string $text, string $font, int $size, int $height, int $maxChars) use (&$lineSpecs): void {
        foreach (foreman_pdf_wrap_text($text, $maxChars) as $line) {
            $lineSpecs[] = [
                'text' => $line,
                'font' => $font,
                'size' => $size,
                'height' => $height,
            ];
        }
    };

    $addLine('FOREMAN REPORT', 'F2', 18, 28);
    $addWrappedBlock($title, 'F2', 15, 20, 72);
    $addLine('', 'F1', 12, 8);
    $addWrappedBlock('Reference No.: ' . $reference, 'F1', 11, 16, 88);
    $addWrappedBlock('Project: ' . $projectName, 'F1', 11, 16, 88);
    $addWrappedBlock('Report Type: ' . $reportType, 'F1', 11, 16, 88);
    $addWrappedBlock('Status: ' . $status, 'F1', 11, 16, 88);
    $addWrappedBlock('Priority: ' . $priority, 'F1', 11, 16, 88);
    $addWrappedBlock('Due Date: ' . $dueDate, 'F1', 11, 16, 88);
    $addWrappedBlock('Created At: ' . $createdAt, 'F1', 11, 16, 88);
    $addWrappedBlock('Last Updated: ' . $updatedAt, 'F1', 11, 16, 88);
    $addWrappedBlock('Generated By: ' . trim($generatedBy), 'F1', 11, 16, 88);
    $addWrappedBlock('Generated At: ' . trim($generatedAt), 'F1', 11, 16, 88);
    $addLine('', 'F1', 12, 14);
    $addLine('DETAILS', 'F2', 13, 20);

    if ($details === '') {
        $addWrappedBlock('No details recorded.', 'F1', 11, 16, 88);
    } else {
        foreach (preg_split("/\r\n|\r|\n/", $details) ?: [] as $detailLine) {
            $detailLine = trim((string)$detailLine);

            if ($detailLine === '') {
                $addLine('', 'F1', 11, 10);
                continue;
            }

            $addWrappedBlock($detailLine, 'F1', 11, 16, 88);
        }
    }

    $pageWidth = 612;
    $pageHeight = 792;
    $leftMargin = 54;
    $topMargin = 54;
    $bottomMargin = 54;
    $footerReserve = 24;
    $startY = $pageHeight - $topMargin;
    $minimumY = $bottomMargin + $footerReserve;
    $pages = [];
    $pageLines = [];
    $currentY = $startY;

    foreach ($lineSpecs as $lineSpec) {
        if ($currentY - $lineSpec['height'] < $minimumY) {
            $pages[] = $pageLines;
            $pageLines = [];
            $currentY = $startY;
        }

        $lineSpec['y'] = $currentY;
        $pageLines[] = $lineSpec;
        $currentY -= $lineSpec['height'];
    }

    if ($pageLines !== []) {
        $pages[] = $pageLines;
    }

    if ($pages === []) {
        $pages[] = [];
    }

    $objects = [];
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
    $fontRegularObjectId = count($objects);
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
    $fontBoldObjectId = count($objects);

    $contentObjectIds = [];
    $pageObjectIds = [];
    $pageCount = count($pages);

    foreach ($pages as $pageIndex => $pageLines) {
        $content = "BT\n";
        $content .= "/F2 10 Tf\n";
        $content .= sprintf("1 0 0 1 %d %d Tm\n", $leftMargin, $pageHeight - 34);
        $content .= '(' . foreman_pdf_encode_text('Edge Automation') . ") Tj\n";
        $content .= "ET\n";

        foreach ($pageLines as $lineSpec) {
            $fontObjectName = $lineSpec['font'] === 'F2' ? 'F2' : 'F1';
            $content .= "BT\n";
            $content .= sprintf("/%s %d Tf\n", $fontObjectName, $lineSpec['size']);
            $content .= sprintf("1 0 0 1 %d %d Tm\n", $leftMargin, (int)$lineSpec['y']);
            $content .= '(' . foreman_pdf_encode_text((string)$lineSpec['text']) . ") Tj\n";
            $content .= "ET\n";
        }

        $content .= "BT\n";
        $content .= "/F1 10 Tf\n";
        $content .= sprintf("1 0 0 1 %d %d Tm\n", $leftMargin, 30);
        $content .= '(' . foreman_pdf_encode_text('Page ' . ($pageIndex + 1) . ' of ' . $pageCount) . ") Tj\n";
        $content .= "ET\n";

        $stream = "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream";
        $objects[] = $stream;
        $contentObjectIds[] = count($objects);

        $objects[] = '';
        $pageObjectIds[] = count($objects);
    }

    $kids = [];
    foreach ($pageObjectIds as $pageObjectId) {
        $kids[] = $pageObjectId . ' 0 R';
    }

    $objects[] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . $pageCount . ' >>';
    $pagesObjectId = count($objects);

    foreach ($pageObjectIds as $index => $pageObjectId) {
        $objects[$pageObjectId - 1] = '<< /Type /Page /Parent ' . $pagesObjectId . ' 0 R /MediaBox [0 0 ' . $pageWidth . ' ' . $pageHeight . '] /Resources << /Font << /F1 ' . $fontRegularObjectId . ' 0 R /F2 ' . $fontBoldObjectId . ' 0 R >> >> /Contents ' . $contentObjectIds[$index] . ' 0 R >>';
    }

    $objects[] = '<< /Type /Catalog /Pages ' . $pagesObjectId . ' 0 R >>';
    $catalogObjectId = count($objects);

    $pdf = "%PDF-1.4\n";
    $offsets = [0];

    foreach ($objects as $index => $objectContent) {
        $offsets[] = strlen($pdf);
        $pdf .= ($index + 1) . " 0 obj\n" . $objectContent . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $pdf .= "xref\n";
    $pdf .= '0 ' . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";

    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf("%010d 00000 n \n", $offsets[$i]);
    }

    $pdf .= "trailer\n";
    $pdf .= '<< /Size ' . (count($objects) + 1) . ' /Root ' . $catalogObjectId . " 0 R >>\n";
    $pdf .= "startxref\n";
    $pdf .= $xrefOffset . "\n";
    $pdf .= "%%EOF";

    header('Content-Type: application/pdf');
    header('Content-Length: ' . strlen($pdf));
    header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . foreman_build_report_pdf_filename($report) . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Pragma: public');

    echo $pdf;
}
