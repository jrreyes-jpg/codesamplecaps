<?php
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/audit_log.php';

$loginSecurityNotice = $_SESSION['superadmin_login_security_notice'] ?? null;
unset($_SESSION['superadmin_login_security_notice']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['login_security_action'] ?? '') === 'unlock_ip') {
    $targetIp = trim((string)($_POST['ip_address'] ?? ''));
    $csrfToken = (string)($_POST['csrf_token'] ?? '');
    $notice = ['type' => 'error', 'message' => 'Unable to unlock this login.'];

    if (!auth_is_valid_csrf($csrfToken, 'superadmin_login_security')) {
        $notice['message'] = 'Your session expired. Please try again.';
    } elseif (filter_var($targetIp, FILTER_VALIDATE_IP) === false) {
        $notice['message'] = 'Invalid IP address.';
    } else {
        $summaryStmt = $conn->prepare('SELECT COALESCE(SUM(attempts), 0) AS attempts FROM login_attempts WHERE ip_address = ?');
        $deleteStmt = $conn->prepare('DELETE FROM login_attempts WHERE ip_address = ?');
        if ($summaryStmt && $deleteStmt) {
            $summaryStmt->bind_param('s', $targetIp);
            $summaryStmt->execute();
            $attemptCount = (int)(($summaryStmt->get_result()->fetch_assoc()['attempts'] ?? 0));

            $deleteStmt->bind_param('s', $targetIp);
            $deleteStmt->execute();
            audit_log_event(
                $conn,
                (int)($_SESSION['user_id'] ?? 0),
                'unlock_login_ip',
                'login_security',
                null,
                ['ip_address' => $targetIp, 'failed_attempts' => $attemptCount],
                ['ip_address' => $targetIp, 'status' => 'unlocked']
            );
            $notice = ['type' => 'success', 'message' => 'Login unlocked for ' . $targetIp . '.'];
        }
    }

    $_SESSION['superadmin_login_security_notice'] = $notice;
    header('Location: /codesamplecaps/SUPERADMIN/sidebar/audit_logs.php?view=login_security');
    exit;
}

function audit_logs_relative_time(?string $dateTime): string {
    if (!$dateTime) {
        return 'Unknown time';
    }

    try {
        $date = new DateTimeImmutable($dateTime);
        $now = new DateTimeImmutable();
        $diff = $now->getTimestamp() - $date->getTimestamp();

        if ($diff < 60) {
            return 'Just now';
        }

        if ($diff < 3600) {
            return floor($diff / 60) . ' min ago';
        }

        if ($diff < 86400) {
            return floor($diff / 3600) . ' hr ago';
        }

        if ($diff < 604800) {
            return floor($diff / 86400) . ' day(s) ago';
        }

        return $date->format('M d, Y g:i A');
    } catch (Throwable $exception) {
        return (string)$dateTime;
    }
}

function audit_logs_decode_payload(?string $payload): array {
    if (!$payload) {
        return [];
    }

    $decoded = json_decode($payload, true);

    return is_array($decoded) ? $decoded : [];
}

function audit_logs_action_label(string $action): string {
    return ucwords(str_replace('_', ' ', $action));
}

function audit_logs_format_datetime(?string $dateTime): string {
    if (!$dateTime) {
        return 'Unknown time';
    }

    try {
        return (new DateTimeImmutable($dateTime))->format('M. j, Y, g:ia');
    } catch (Throwable $exception) {
        return (string)$dateTime;
    }
}

function audit_logs_action_class(string $action): string {
    if ($action === 'login') {
        return 'audit-action-info';
    }

    if (strpos($action, 'fail') !== false || strpos($action, 'delete') !== false || strpos($action, 'trash') !== false) {
        return 'audit-action-danger';
    }

    if (strpos($action, 'restore') !== false || strpos($action, 'create') !== false || strpos($action, 'approve') !== false) {
        return 'audit-action-success';
    }

    if (strpos($action, 'password') !== false || strpos($action, 'login') !== false || strpos($action, 'status') !== false) {
        return 'audit-action-warning';
    }

    return 'audit-action-info';
}

function audit_logs_format_json(?string $payload): string {
    $decoded = audit_logs_decode_payload($payload);
    if ($decoded === []) {
        return 'No data';
    }

    return json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'No data';
}

function audit_logs_value_label(string $key): string {
    return ucwords(str_replace('_', ' ', $key));
}

function audit_logs_value_text($value): string {
    if ($value === null || $value === '') {
        return 'None';
    }

    if (is_bool($value)) {
        return $value ? 'Yes' : 'No';
    }

    if (is_array($value)) {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: 'Data';
    }

    $text = trim((string)$value);
    return $text === '' ? 'None' : ucfirst(str_replace('_', ' ', $text));
}

function audit_logs_format_values(?string $payload): string {
    $decoded = audit_logs_decode_payload($payload);
    if ($decoded === []) {
        return 'No data';
    }

    $lines = [];
    foreach ($decoded as $key => $value) {
        $lines[] = audit_logs_value_label((string)$key) . ': ' . audit_logs_value_text($value);
    }

    return implode("\n", $lines);
}

function audit_logs_filter_url(array $overrides = []): string {
    $query = array_filter(array_merge($_GET, $overrides), static fn($value) => $value !== '' && $value !== null);
    return '/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php' . ($query ? '?' . http_build_query($query) : '');
}

function audit_logs_search_alt(string $search): string {
    $search = strtolower(trim($search));
    return strlen($search) > 3 && substr($search, -1) === 's'
        ? substr($search, 0, -1)
        : $search . 's';
}

function audit_logs_target_label(?string $entityType, $entityId): string {
    $type = strtolower(trim((string)$entityType));
    $labels = [
        'user' => 'User Account',
        'users' => 'User Account',
        'project' => 'Project',
        'projects' => 'Project',
        'inventory' => 'Inventory Item',
        'asset' => 'Asset',
        'assets' => 'Asset',
        'task' => 'Task',
        'tasks' => 'Task',
        'quotation' => 'Quotation',
        'quotations' => 'Quotation',
    ];

    $label = $labels[$type] ?? ucwords(str_replace('_', ' ', $type ?: 'Record'));
    return $label . (!empty($entityId) ? ' #' . (int)$entityId : '');
}

function audit_logs_build_details(array $entry): string {
    $action = (string)($entry['action'] ?? 'activity');
    $entityType = (string)($entry['entity_type'] ?? 'record');
    $actorName = (string)($entry['actor_name'] ?? 'System');
    $oldValues = audit_logs_decode_payload($entry['old_values'] ?? null);
    $newValues = audit_logs_decode_payload($entry['new_values'] ?? null);

    if ($action === 'login') {
        return 'Successful login';
    }

    if ($action === 'create_user') {
        return ($newValues['full_name'] ?? 'Unknown user') . ' | ' . ucwords(str_replace('_', ' ', (string)($newValues['role'] ?? 'user')));
    }

    if ($action === 'update_user_status') {
        return 'Status: ' . ucfirst((string)($oldValues['status'] ?? 'unknown')) . ' -> ' . ucfirst((string)($newValues['status'] ?? 'unknown'));
    }

    if ($action === 'update_user_profile') {
        return ($newValues['full_name'] ?? 'User record updated') . ' | by ' . $actorName;
    }

    if ($action === 'create_project') {
        return (string)($newValues['project_name'] ?? 'New project') . ' | ' . ucfirst((string)($newValues['status'] ?? 'pending'));
    }

    if ($action === 'update_project_status') {
        return (string)($newValues['project_name'] ?? 'Project') . ' | ' . ucfirst((string)($oldValues['status'] ?? '')) . ' -> ' . ucfirst((string)($newValues['status'] ?? ''));
    }

    if ($action === 'update_project_details') {
        return (string)($newValues['project_name'] ?? 'Project') . ' | by ' . $actorName;
    }

    if ($action === 'delete_project') {
        return (string)($oldValues['project_name'] ?? 'Project') . ' | moved to trash by ' . $actorName;
    }

    if ($action === 'restore_project') {
        return (string)($newValues['project_name'] ?? $oldValues['project_name'] ?? 'Project') . ' | restored by ' . $actorName;
    }

    if ($action === 'permanently_delete_project') {
        return (string)($oldValues['project_name'] ?? 'Project') . ' | permanently deleted by ' . $actorName;
    }

    if ($action === 'add_task') {
        return (string)($newValues['task_name'] ?? 'Task') . ' | ' . (string)($newValues['project_name'] ?? 'Project');
    }

    if ($action === 'deploy_inventory_to_project' || $action === 'return_project_inventory' || $action === 'create_inventory_item' || $action === 'update_inventory_item') {
        return (string)($newValues['asset_name'] ?? 'Inventory item') . ' | Qty ' . (int)($newValues['quantity'] ?? 0);
    }

    if ($action === 'create_asset') {
        return (string)($newValues['asset_name'] ?? 'Asset') . ' | ' . (string)($newValues['asset_type'] ?? 'Unspecified');
    }

    if ($action === 'trash_asset') {
        return (string)($newValues['asset_name'] ?? $oldValues['asset_name'] ?? 'Asset') . ' | moved to trash by ' . $actorName;
    }

    if ($action === 'restore_asset') {
        return (string)($newValues['asset_name'] ?? $oldValues['asset_name'] ?? 'Asset') . ' | restored by ' . $actorName;
    }

    if ($action === 'permanently_delete_asset') {
        return (string)($oldValues['asset_name'] ?? 'Asset') . ' | permanently deleted by ' . $actorName;
    }

    if ($action === 'return_asset') {
        return (string)($newValues['asset_name'] ?? 'Asset') . ' | status available';
    }

    if ($action === 'mark_asset_maintenance') {
        return (string)($newValues['asset_name'] ?? 'Asset') . ' | status maintenance';
    }

    if ($action === 'mark_asset_lost') {
        return (string)($newValues['asset_name'] ?? 'Asset') . ' | status lost';
    }

    return ucfirst($entityType) . ' | by ' . $actorName;
}

$search = trim((string)($_GET['q'] ?? ''));
$entityFilter = trim((string)($_GET['entity'] ?? ''));
$actionFilter = trim((string)($_GET['action'] ?? ''));
$dateFilter = trim((string)($_GET['date'] ?? ''));
$quickFilter = trim((string)($_GET['quick'] ?? ''));
$roleFilter = strtolower(trim((string)($_GET['role'] ?? '')));
$actorFilter = (int)($_GET['actor'] ?? 0);
$viewAllActivity = (string)($_GET['view'] ?? '') === 'all';
$viewLoginSecurity = (string)($_GET['view'] ?? '') === 'login_security';
$exportCsv = (string)($_GET['export'] ?? '') === 'csv';
$allowedEntities = ['user', 'project', 'inventory', 'asset', 'scan', 'task', 'quotation'];
$allowedQuickFilters = ['today', 'week', 'month'];
$allowedRoleFilters = ['admin', 'engineer', 'foreman', 'inventory_clerk', 'client'];
if (!in_array($entityFilter, $allowedEntities, true)) {
    $entityFilter = '';
}
if (!preg_match('/^[a-z_]+$/', $actionFilter)) {
    $actionFilter = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $dateFilter = '';
}
if (!in_array($quickFilter, $allowedQuickFilters, true)) {
    $quickFilter = '';
}
if (!in_array($roleFilter, $allowedRoleFilters, true)) {
    $roleFilter = '';
    $actorFilter = 0;
}
if ($actorFilter < 1) {
    $actorFilter = 0;
}

$quickStartDate = '';
$quickEndDate = '';
if ($quickFilter !== '' && $dateFilter === '') {
    $today = new DateTimeImmutable('today');
    if ($quickFilter === 'today') {
        $quickStartDate = $today->format('Y-m-d');
        $quickEndDate = $today->format('Y-m-d');
    } elseif ($quickFilter === 'week') {
        $quickStartDate = $today->modify('monday this week')->format('Y-m-d');
        $quickEndDate = $today->format('Y-m-d');
    } elseif ($quickFilter === 'month') {
        $quickStartDate = $today->modify('first day of this month')->format('Y-m-d');
        $quickEndDate = $today->format('Y-m-d');
    }
}

$activityRows = [];
$recentActivityRows = [];
$actionOptions = [];
$actorOptions = [];
$roleLabels = [
    'admin' => 'Admin',
    'engineer' => 'Engineer',
    'foreman' => 'Foreman',
    'inventory_clerk' => 'Inventory Clerk',
    'client' => 'Client',
];
$selectedActorName = '';
$loginAttemptRows = [];
$loginSecurityCsrfToken = auth_csrf_token('superadmin_login_security');
$loginMaxAccountAttempts = (int)Config::getInstance()->get('LOGIN_MAX_ATTEMPTS', 5);
$loginMaxIpAttempts = 15;
$loginLockSeconds = (int)Config::getInstance()->get('LOGIN_LOCKOUT_MINUTES', 15) * 60;
$shouldLoadActivityRows = !$viewLoginSecurity;

if ($viewLoginSecurity) {
    $loginAttemptResult = $conn->query(
        "SELECT
            ip_address,
            COALESCE(SUM(attempts), 0) AS total_attempts,
            COALESCE(MAX(CASE WHEN email <> '__ip_only__' THEN attempts ELSE 0 END), 0) AS max_account_attempts,
            COALESCE(SUM(CASE WHEN email = '__ip_only__' THEN attempts ELSE 0 END), 0) AS unknown_account_attempts,
            MAX(last_attempt) AS last_attempt
         FROM login_attempts
         WHERE ip_address IS NOT NULL AND ip_address <> ''
         GROUP BY ip_address
         ORDER BY last_attempt DESC
         LIMIT 100"
    );
    if ($loginAttemptResult) {
        $loginAttemptRows = $loginAttemptResult->fetch_all(MYSQLI_ASSOC);
    }
}

if ($roleFilter !== '') {
    $actorStmt = $conn->prepare(
        'SELECT id, full_name
         FROM users
         WHERE role = ?
         ORDER BY full_name ASC'
    );
    if ($actorStmt) {
        $actorStmt->bind_param('s', $roleFilter);
        $actorStmt->execute();
        $actorResult = $actorStmt->get_result();
        if ($actorResult) {
            $actorOptions = $actorResult->fetch_all(MYSQLI_ASSOC);
            foreach ($actorOptions as $actorOption) {
                if ((int)$actorOption['id'] === $actorFilter) {
                    $selectedActorName = (string)$actorOption['full_name'];
                    break;
                }
            }
        }
    }
}

if (function_exists('audit_log_table_exists') ? audit_log_table_exists($conn) : true) {
    $actionResult = $conn->query('SELECT DISTINCT action FROM audit_logs ORDER BY action ASC');
    if ($actionResult) {
        while ($actionRow = $actionResult->fetch_assoc()) {
            $actionValue = (string)($actionRow['action'] ?? '');
            if ($actionValue !== '') {
                $actionOptions[] = $actionValue;
            }
        }
    }

    // Ipakita agad ang pinakahuling galaw ng buong system sa folder screen.
    $recentStmt = $conn->prepare(
        "SELECT
            l.id,
            l.created_at,
            l.action,
            l.entity_type,
            l.entity_id,
            l.old_values,
            l.new_values,
            l.ip_address,
            actor.full_name AS actor_name,
            actor.role AS actor_role
         FROM audit_logs l
         LEFT JOIN users actor ON actor.id = l.user_id
         ORDER BY l.created_at DESC
         LIMIT 5"
    );
    if ($recentStmt) {
        $recentStmt->execute();
        $recentResult = $recentStmt->get_result();
        if ($recentResult) {
            $recentActivityRows = $recentResult->fetch_all(MYSQLI_ASSOC);
        }
    }

    if ($shouldLoadActivityRows) {
        $sql = "SELECT
                l.id,
                l.created_at,
                l.action,
                l.entity_type,
                l.entity_id,
                l.old_values,
                l.new_values,
                l.ip_address,
                actor.full_name AS actor_name,
                actor.role AS actor_role
            FROM audit_logs l
            LEFT JOIN users actor ON actor.id = l.user_id
            WHERE 1 = 1";

        $params = [];
        $types = '';

        if ($search !== '') {
            $sql .= " AND (
            LOWER(l.action) LIKE ?
            OR LOWER(REPLACE(l.action, '_', ' ')) LIKE ?
            OR LOWER(l.entity_type) LIKE ?
            OR LOWER(actor.full_name) LIKE ?
            OR LOWER(actor.role) LIKE ?
            OR LOWER(l.ip_address) LIKE ?
            OR CAST(l.entity_id AS CHAR) LIKE ?
            OR LOWER(l.old_values) LIKE ?
            OR LOWER(l.new_values) LIKE ?
            OR LOWER(REPLACE(l.action, '_', ' ')) LIKE ?
            OR LOWER(l.entity_type) LIKE ?
        )";
            $searchLike = '%' . strtolower($search) . '%';
            $searchActionLike = '%' . str_replace(' ', '_', strtolower($search)) . '%';
            $searchAltLike = '%' . audit_logs_search_alt($search) . '%';
            $params[] = $searchActionLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchLike;
            $params[] = $searchAltLike;
            $params[] = $searchAltLike;
            $types .= 'sssssssssss';
        }

        if ($entityFilter !== '') {
            if ($entityFilter === 'scan') {
                $sql .= " AND l.entity_type IN ('scan', 'scan_history', 'asset_scan_history')";
            } elseif ($entityFilter === 'project') {
                $sql .= " AND l.entity_type IN ('project', 'projects')";
            } elseif ($entityFilter === 'user') {
                $sql .= " AND l.entity_type IN ('user', 'users')";
            } elseif ($entityFilter === 'asset') {
                $sql .= " AND l.entity_type IN ('asset', 'assets')";
            } elseif ($entityFilter === 'inventory') {
                $sql .= " AND l.entity_type = 'inventory'";
            } elseif ($entityFilter === 'task') {
                $sql .= " AND l.entity_type IN ('task', 'tasks')";
            } elseif ($entityFilter === 'quotation') {
                $sql .= " AND l.entity_type IN ('quotation', 'quotations')";
            }
        }

        if ($actionFilter !== '') {
            $sql .= ' AND l.action = ?';
            $params[] = $actionFilter;
            $types .= 's';
        }

        if ($dateFilter !== '') {
            $sql .= ' AND DATE(l.created_at) = ?';
            $params[] = $dateFilter;
            $types .= 's';
        } elseif ($quickStartDate !== '' && $quickEndDate !== '') {
            $sql .= ' AND DATE(l.created_at) BETWEEN ? AND ?';
            $params[] = $quickStartDate;
            $params[] = $quickEndDate;
            $types .= 'ss';
        }

        if ($roleFilter !== '') {
            $sql .= ' AND actor.role = ?';
            $params[] = $roleFilter;
            $types .= 's';
        }

        if ($actorFilter > 0) {
            $sql .= ' AND l.user_id = ?';
            $params[] = $actorFilter;
            $types .= 'i';
        }

        $sql .= ' ORDER BY l.created_at DESC LIMIT 300';
        $stmt = $conn->prepare($sql);

        if ($stmt) {
            if ($types !== '') {
                $stmt->bind_param($types, ...$params);
            }

            $stmt->execute();
            $result = $stmt->get_result();
            if ($result) {
                $activityRows = $result->fetch_all(MYSQLI_ASSOC);
            }
        }
    }
}

if ($exportCsv) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="audit_logs_' . date('Ymd_His') . '.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Time', 'Actor', 'Role', 'Action', 'Target', 'IP Address', 'Summary', 'Previous Value', 'New Value']);

    foreach ($activityRows as $row) {
        fputcsv($output, [
            audit_logs_format_datetime((string)($row['created_at'] ?? '')),
            (string)($row['actor_name'] ?: 'System'),
            ucwords(str_replace('_', ' ', (string)($row['actor_role'] ?: 'System'))),
            audit_logs_action_label((string)($row['action'] ?? 'activity')),
            audit_logs_target_label($row['entity_type'] ?? 'Record', $row['entity_id'] ?? null),
            (string)($row['ip_address'] ?? 'N/A'),
            audit_logs_build_details($row),
            audit_logs_format_values($row['old_values'] ?? null),
            audit_logs_format_values($row['new_values'] ?? null),
        ]);
    }

    fclose($output);
    exit;
}
superadmin_render_page(
    'Audit Logs',
    function () use ($search, $entityFilter, $actionFilter, $dateFilter, $quickFilter, $roleFilter, $actorFilter, $viewAllActivity, $viewLoginSecurity, $actorOptions, $actionOptions, $activityRows, $recentActivityRows, $roleLabels, $selectedActorName, $loginAttemptRows, $loginSecurityCsrfToken, $loginMaxAccountAttempts, $loginMaxIpAttempts, $loginLockSeconds, $loginSecurityNotice): void {
        ?>
        <section class="dashboard-panel audit-logs-panel" id="audit-logs-section" data-reset-url="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php">
            <div class="audit-explorer-header">
                <div class="audit-explorer-header__main">
                    <h1 class="audit-logs-title">Audit Logs</h1>
                    <form method="GET" class="audit-header-search" data-audit-global-search>
                        <input type="hidden" name="quick" value="<?php echo htmlspecialchars($quickFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="role" value="<?php echo htmlspecialchars($roleFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="actor" value="<?php echo $actorFilter > 0 ? (int)$actorFilter : ''; ?>">
                        <input type="hidden" name="view" value="<?php echo $viewAllActivity ? 'all' : ''; ?>">
                        <input type="hidden" name="entity" value="<?php echo htmlspecialchars($entityFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="audit-logs-smart-field audit-header-search__query">
                            <span class="audit-logs-smart-field__icon" aria-hidden="true">&#128269;</span>
                            <span class="sr-only">Search audit logs</span>
                            <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search user, action, or details" autocomplete="off">
                        </label>
                        <label class="audit-logs-smart-field audit-logs-smart-field--date">
                            <span class="audit-logs-smart-field__label">Date</span>
                            <input type="date" name="date" value="<?php echo htmlspecialchars($dateFilter); ?>" data-audit-date-filter>
                        </label>
                        <label class="audit-logs-smart-field audit-logs-smart-field--select">
                            <span class="audit-logs-smart-field__label">Action</span>
                            <select name="action" data-audit-action-filter>
                                <option value="">All Actions</option>
                                <option value="login" <?php echo $actionFilter === 'login' ? 'selected' : ''; ?>>Login</option>
                                <?php foreach ($actionOptions as $actionOption): ?>
                                    <?php if ($actionOption === 'login') continue; ?>
                                    <option value="<?php echo htmlspecialchars($actionOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $actionFilter === $actionOption ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(audit_logs_action_label($actionOption), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?php
                        $hasHeaderFilters = $roleFilter !== ''
                            || $actorFilter > 0
                            || $search !== ''
                            || $entityFilter !== ''
                            || $actionFilter !== ''
                            || $dateFilter !== ''
                            || $quickFilter !== ''
                            || $viewAllActivity
                            || $viewLoginSecurity;
                        ?>
                        <a
                            href="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php"
                            class="btn-secondary audit-header-clear<?php echo $hasHeaderFilters ? '' : ' is-disabled'; ?>"
                            <?php echo $hasHeaderFilters ? '' : 'aria-disabled="true" tabindex="-1"'; ?>
                        >Clear Filters</a>
                    </form>
                </div>
            </div>

            <?php if ($search !== ''): ?>
                <nav class="audit-breadcrumb" aria-label="Audit breadcrumb">
                    <a href="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php">Audit Logs</a>
                    <span>&gt;</span>
                    <strong>Search: <?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?></strong>
                </nav>
            <?php elseif ($viewAllActivity): ?>
                <nav class="audit-breadcrumb" aria-label="Audit breadcrumb">
                    <a href="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php">Audit Logs</a>
                    <span>&gt;</span>
                    <strong>All Recent Activity</strong>
                </nav>
            <?php elseif ($viewLoginSecurity): ?>
                <nav class="audit-breadcrumb" aria-label="Audit breadcrumb">
                    <a href="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php">Audit Logs</a>
                    <span>&gt;</span>
                    <strong>Failed Login Attempts</strong>
                </nav>
            <?php elseif ($roleFilter !== ''): ?>
                <nav class="audit-breadcrumb" aria-label="Audit breadcrumb">
                    <a href="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php">Audit Logs</a>
                    <span>&gt;</span>
                    <?php if ($actorFilter > 0): ?>
                        <a href="<?php echo htmlspecialchars(audit_logs_filter_url(['actor' => '', 'q' => '', 'entity' => '', 'action' => '', 'date' => '', 'quick' => '']), ENT_QUOTES, 'UTF-8'); ?>">
                            <?php echo htmlspecialchars($roleLabels[$roleFilter] ?? 'Role', ENT_QUOTES, 'UTF-8'); ?>
                        </a>
                        <span>&gt;</span>
                        <strong><?php echo htmlspecialchars($selectedActorName ?: 'Selected User', ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php else: ?>
                        <strong><?php echo htmlspecialchars($roleLabels[$roleFilter] ?? 'Role', ENT_QUOTES, 'UTF-8'); ?></strong>
                    <?php endif; ?>
                </nav>
            <?php endif; ?>

            <?php if ($search !== '' && $actorFilter === 0): ?>
                <div class="audit-folder-grid audit-search-folder-results">
                    <?php if ($roleFilter === ''): ?>
                        <?php foreach ($roleLabels as $roleValue => $roleLabel): ?>
                            <a class="audit-folder-card" data-audit-folder-card data-audit-folder-search="<?php echo htmlspecialchars(strtolower($roleLabel), ENT_QUOTES, 'UTF-8'); ?>" href="<?php echo htmlspecialchars(audit_logs_filter_url(['role' => $roleValue, 'actor' => '', 'q' => '', 'entity' => '', 'action' => '', 'date' => '', 'quick' => '']), ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="audit-folder-card__icon" aria-hidden="true">&#128193;</span>
                                <strong><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                            </a>
                        <?php endforeach; ?>
                        <div class="audit-empty-card" data-audit-folder-empty hidden>No folder matches your search.</div>
                    <?php elseif (!empty($actorOptions)): ?>
                        <?php foreach ($actorOptions as $actorOption): ?>
                            <a class="audit-folder-card audit-folder-card--user" data-audit-folder-card data-audit-folder-search="<?php echo htmlspecialchars(strtolower((string)$actorOption['full_name'] . ' ' . ($roleLabels[$roleFilter] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" href="<?php echo htmlspecialchars(audit_logs_filter_url(['actor' => (int)$actorOption['id']]), ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="audit-folder-card__icon" aria-hidden="true">&#128100;</span>
                                <strong><?php echo htmlspecialchars((string)$actorOption['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </a>
                        <?php endforeach; ?>
                        <div class="audit-empty-card" data-audit-folder-empty hidden>No user matches your search.</div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if ($viewLoginSecurity): ?>
                <section class="login-security-panel" aria-labelledby="loginSecurityTitle">
                    <div class="login-security-panel__header">
                        <div>
                            <h2 id="loginSecurityTitle">Failed Login Attempts</h2>
                            <p>Fake or unknown accounts are counted using their IP address.</p>
                        </div>
                    </div>

                    <?php if (is_array($loginSecurityNotice) && !empty($loginSecurityNotice['message'])): ?>
                        <div class="login-security-notice login-security-notice--<?php echo ($loginSecurityNotice['type'] ?? '') === 'success' ? 'success' : 'error'; ?>" role="status">
                            <?php echo htmlspecialchars((string)$loginSecurityNotice['message'], ENT_QUOTES, 'UTF-8'); ?>
                        </div>
                    <?php endif; ?>

                    <div class="table-wrapper">
                        <table class="responsive-table mobile-card-table login-security-table">
                            <thead>
                                <tr>
                                    <th>IP Address</th>
                                    <th>Source</th>
                                    <th>Attempts</th>
                                    <th>Last Attempt</th>
                                    <th>Status</th>
                                    <th>Action</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($loginAttemptRows)): ?>
                                    <tr><td colspan="6" class="table-empty-cell">No failed login attempts.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($loginAttemptRows as $loginAttempt): ?>
                                        <?php
                                        $targetIp = (string)($loginAttempt['ip_address'] ?? '');
                                        $totalAttempts = (int)($loginAttempt['total_attempts'] ?? 0);
                                        $unknownAttempts = (int)($loginAttempt['unknown_account_attempts'] ?? 0);
                                        $maxAccountAttempts = (int)($loginAttempt['max_account_attempts'] ?? 0);
                                        $lastAttempt = (string)($loginAttempt['last_attempt'] ?? '');
                                        $lastAttemptTime = $lastAttempt !== '' ? strtotime($lastAttempt) : false;
                                        $remainingSeconds = $lastAttemptTime === false ? 0 : max(0, ($lastAttemptTime + $loginLockSeconds) - time());
                                        $isLocked = $remainingSeconds > 0
                                            && ($totalAttempts >= $loginMaxIpAttempts || $maxAccountAttempts >= $loginMaxAccountAttempts);
                                        $remainingMinutes = max(1, (int)ceil($remainingSeconds / 60));
                                        $sourceLabel = $unknownAttempts > 0
                                            ? 'Unknown account / IP attempt'
                                            : 'Existing account attempt';
                                        ?>
                                        <tr>
                                            <td data-label="IP Address"><strong><?php echo htmlspecialchars($targetIp, ENT_QUOTES, 'UTF-8'); ?></strong></td>
                                            <td data-label="Source"><?php echo htmlspecialchars($sourceLabel, ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="Attempts"><?php echo $totalAttempts; ?></td>
                                            <td data-label="Last Attempt"><?php echo htmlspecialchars(audit_logs_format_datetime($lastAttempt), ENT_QUOTES, 'UTF-8'); ?></td>
                                            <td data-label="Status">
                                                <span class="login-security-status <?php echo $isLocked ? 'is-locked' : 'is-monitoring'; ?>">
                                                    <?php echo $isLocked ? 'Locked · ' . $remainingMinutes . ' min left' : 'Not locked'; ?>
                                                </span>
                                            </td>
                                            <td data-label="Action">
                                                <?php if ($isLocked): ?>
                                                    <button type="button" class="login-security-unlock-btn" data-unlock-login data-ip-address="<?php echo htmlspecialchars($targetIp, ENT_QUOTES, 'UTF-8'); ?>">Unlock Login</button>
                                                <?php else: ?>
                                                    <span class="login-security-no-action">No action needed</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php elseif ($roleFilter !== '' && $search === '' && !$viewAllActivity): ?>
                <div class="audit-folder-grid">
                    <a class="audit-folder-card" data-audit-folder-card data-audit-folder-search="failed login attempts security ip" href="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php?view=login_security">
                        <span class="audit-folder-card__icon" aria-hidden="true">&#128274;</span>
                        <strong>Failed Login Attempts</strong>
                    </a>
                    <?php foreach ($roleLabels as $roleValue => $roleLabel): ?>
                        <a class="audit-folder-card" data-audit-folder-card data-audit-folder-search="<?php echo htmlspecialchars(strtolower($roleLabel), ENT_QUOTES, 'UTF-8'); ?>" href="<?php echo htmlspecialchars(audit_logs_filter_url(['role' => $roleValue, 'actor' => '', 'q' => '', 'entity' => '', 'action' => '', 'date' => '', 'quick' => '']), ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="audit-folder-card__icon" aria-hidden="true">&#128193;</span>
                            <strong><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </a>
                    <?php endforeach; ?>
                    <div class="audit-empty-card" data-audit-folder-empty hidden>No folder matches your search.</div>
                </div>
                <section class="audit-recent-panel" aria-labelledby="auditRecentTitle">
                    <div class="audit-recent-panel__header">
                        <h2 id="auditRecentTitle">Recent Activity</h2>
                        <a href="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php?view=all" class="audit-recent-panel__link">View All</a>
                    </div>
                    <?php if (empty($recentActivityRows)): ?>
                        <div class="audit-empty-card">No recent activity yet.</div>
                    <?php else: ?>
                        <div class="audit-recent-list">
                            <?php foreach ($recentActivityRows as $recentRow): ?>
                                <?php
                                $recentDetails = audit_logs_build_details($recentRow);
                                $recentTime = audit_logs_format_datetime((string)($recentRow['created_at'] ?? ''));
                                $recentTarget = audit_logs_target_label($recentRow['entity_type'] ?? 'Record', $recentRow['entity_id'] ?? null);
                                ?>
                                <button
                                    type="button"
                                    class="audit-recent-item"
                                    data-audit-open
                                    data-audit-time="<?php echo htmlspecialchars($recentTime, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-audit-actor="<?php echo htmlspecialchars((string)($recentRow['actor_name'] ?: 'System'), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-audit-role="<?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($recentRow['actor_role'] ?: 'System'))), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-audit-action="<?php echo htmlspecialchars(audit_logs_action_label((string)($recentRow['action'] ?? 'activity')), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-audit-target="<?php echo htmlspecialchars($recentTarget, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-audit-ip="<?php echo htmlspecialchars((string)($recentRow['ip_address'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-audit-old="<?php echo htmlspecialchars(audit_logs_format_values($recentRow['old_values'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-audit-new="<?php echo htmlspecialchars(audit_logs_format_values($recentRow['new_values'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                >
                                    <span class="audit-action-chip <?php echo htmlspecialchars(audit_logs_action_class((string)($recentRow['action'] ?? 'activity'))); ?>">
                                        <?php echo htmlspecialchars(audit_logs_action_label((string)($recentRow['action'] ?? 'activity'))); ?>
                                    </span>
                                    <strong><?php echo htmlspecialchars((string)($recentRow['actor_name'] ?: 'System')); ?></strong>
                                    <small><?php echo htmlspecialchars($recentDetails); ?></small>
                                    <time><?php echo htmlspecialchars(audit_logs_relative_time((string)($recentRow['created_at'] ?? ''))); ?></time>
                                </button>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </section>
            <?php elseif ($roleFilter !== '' && $actorFilter === 0 && $search === '' && !$viewAllActivity): ?>
                <div class="audit-folder-grid audit-folder-grid--users">
                    <?php if (empty($actorOptions)): ?>
                        <div class="audit-empty-card">No users found for this folder.</div>
                    <?php else: ?>
                        <?php foreach ($actorOptions as $actorOption): ?>
                            <a class="audit-folder-card audit-folder-card--user" data-audit-folder-card data-audit-folder-search="<?php echo htmlspecialchars(strtolower((string)$actorOption['full_name'] . ' ' . ($roleLabels[$roleFilter] ?? '')), ENT_QUOTES, 'UTF-8'); ?>" href="<?php echo htmlspecialchars(audit_logs_filter_url(['actor' => (int)$actorOption['id']]), ENT_QUOTES, 'UTF-8'); ?>">
                                <span class="audit-folder-card__icon" aria-hidden="true">&#128100;</span>
                                <strong><?php echo htmlspecialchars((string)$actorOption['full_name'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            </a>
                        <?php endforeach; ?>
                        <div class="audit-empty-card" data-audit-folder-empty hidden>No user matches your search.</div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                <table class="responsive-table mobile-card-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Summary</th>
                            <th>Full Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($activityRows) === 0): ?>
                            <tr>
                                <td colspan="5" class="table-empty-cell">No audit logs matched your filters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($activityRows as $row): ?>
                                <?php
                                $auditDetails = audit_logs_build_details($row);
                                $auditSearchText = strtolower(trim(
                                    (string)($row['created_at'] ?? '') . ' ' .
                                    (string)($row['actor_name'] ?: 'System') . ' ' .
                                    (string)($row['actor_role'] ?: 'System') . ' ' .
                                    (string)($row['action'] ?? '') . ' ' .
                                    audit_logs_action_label((string)($row['action'] ?? 'activity')) . ' ' .
                                    (string)($row['entity_type'] ?? '') . ' ' .
                                    (string)($row['entity_id'] ?? '') . ' ' .
                                    (string)($row['ip_address'] ?? '') . ' ' .
                                    $auditDetails . ' ' .
                                    audit_logs_format_values($row['old_values'] ?? null) . ' ' .
                                    audit_logs_format_values($row['new_values'] ?? null)
                                ));
                                $auditTime = audit_logs_format_datetime((string)($row['created_at'] ?? ''));
                                $auditTarget = audit_logs_target_label($row['entity_type'] ?? 'Record', $row['entity_id'] ?? null);
                                ?>
                                <tr data-audit-row data-audit-search="<?php echo htmlspecialchars($auditSearchText, ENT_QUOTES, 'UTF-8'); ?>">
                                    <td data-label="Time">
                                        <strong><?php echo htmlspecialchars(audit_logs_relative_time((string)($row['created_at'] ?? ''))); ?></strong><br>
                                        <small><?php echo htmlspecialchars($auditTime); ?></small>
                                    </td>
                                    <td data-label="Actor"><?php echo htmlspecialchars((string)($row['actor_name'] ?: 'System')); ?></td>
                                    <td data-label="Action">
                                        <span class="audit-action-chip <?php echo htmlspecialchars(audit_logs_action_class((string)($row['action'] ?? 'activity'))); ?>">
                                            <?php echo htmlspecialchars(audit_logs_action_label((string)($row['action'] ?? 'activity'))); ?>
                                        </span>
                                    </td>
                                    <td data-label="Summary">
                                        <span class="audit-detail-line" title="<?php echo htmlspecialchars($auditDetails, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($auditDetails); ?>
                                        </span>
                                    </td>
                                    <td data-label="Full Details">
                                        <button
                                            type="button"
                                            class="audit-view-btn"
                                            data-audit-open
                                            data-audit-time="<?php echo htmlspecialchars($auditTime, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-actor="<?php echo htmlspecialchars((string)($row['actor_name'] ?: 'System'), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-role="<?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($row['actor_role'] ?: 'System'))), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-action="<?php echo htmlspecialchars(audit_logs_action_label((string)($row['action'] ?? 'activity')), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-target="<?php echo htmlspecialchars($auditTarget, ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-ip="<?php echo htmlspecialchars((string)($row['ip_address'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-old="<?php echo htmlspecialchars(audit_logs_format_values($row['old_values'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-new="<?php echo htmlspecialchars(audit_logs_format_values($row['new_values'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                        >View</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr data-audit-empty-row hidden>
                                <td colspan="5" class="table-empty-cell">No audit logs match your search.</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>

        <div class="audit-modal" data-audit-modal hidden>
            <section class="audit-modal__panel" role="dialog" aria-modal="true" aria-labelledby="auditModalTitle">
                <header class="audit-modal__header">
                    <div>
                        <p>Audit Detail</p>
                        <h2 id="auditModalTitle" data-audit-modal-action>Activity</h2>
                    </div>
                    <button type="button" class="audit-modal__close" data-audit-close aria-label="Close audit detail">&times;</button>
                </header>
                <div class="audit-modal__grid">
                    <div><span>Time</span><strong data-audit-modal-time></strong></div>
                    <div><span>Actor</span><strong data-audit-modal-actor></strong></div>
                    <div><span>Role</span><strong data-audit-modal-role></strong></div>
                    <div><span>Target</span><strong data-audit-modal-target></strong></div>
                    <div><span>IP Address</span><strong data-audit-modal-ip></strong></div>
                </div>
                <div class="audit-modal__values">
                    <article>
                        <h3>Previous Value</h3>
                        <pre data-audit-modal-old>No data</pre>
                    </article>
                    <article>
                        <h3>New Value</h3>
                        <pre data-audit-modal-new>No data</pre>
                    </article>
                </div>
            </section>
        </div>

        <div class="audit-modal" data-login-security-modal hidden>
            <form method="POST" class="audit-modal__panel login-security-modal__panel" role="dialog" aria-modal="true" aria-labelledby="loginSecurityModalTitle">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($loginSecurityCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="login_security_action" value="unlock_ip">
                <input type="hidden" name="ip_address" value="" data-login-security-ip-input>
                <header class="audit-modal__header">
                    <div>
                        <p>Login Security</p>
                        <h2 id="loginSecurityModalTitle">Unlock this login?</h2>
                    </div>
                    <button type="button" class="audit-modal__close" data-login-security-close aria-label="Close unlock login confirmation">&times;</button>
                </header>
                <p class="login-security-modal__copy">Failed attempts for <strong data-login-security-ip-label></strong> will be cleared. The device can try to log in again.</p>
                <div class="login-security-modal__actions">
                    <button type="button" class="btn-secondary" data-login-security-cancel>Cancel</button>
                    <button type="submit" class="login-security-unlock-btn">Unlock Login</button>
                </div>
            </form>
        </div>
        <?php
    },
    ['/codesamplecaps/SUPERADMIN/css/audit-logs.css?v=20261021-5'],
    ['/codesamplecaps/SUPERADMIN/js/audit-logs.js?v=20261021-2'],
    'audit-logs-content'
);


