<?php
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../../config/audit_log.php';

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

    if ($actorFilter > 0 || $search !== '') {
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
    function () use ($search, $entityFilter, $actionFilter, $dateFilter, $quickFilter, $roleFilter, $actorFilter, $actorOptions, $actionOptions, $activityRows, $roleLabels, $selectedActorName): void {
        ?>
        <section class="dashboard-panel audit-logs-panel" id="audit-logs-section" data-reset-url="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php">
            <div class="audit-explorer-header">
                <div class="audit-explorer-header__main">
                    <h1 class="audit-logs-title">Audit Logs</h1>
                    <form method="GET" class="audit-header-search" data-audit-global-search>
                        <input type="hidden" name="quick" value="<?php echo htmlspecialchars($quickFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="role" value="<?php echo htmlspecialchars($roleFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="actor" value="<?php echo $actorFilter > 0 ? (int)$actorFilter : ''; ?>">
                        <input type="hidden" name="entity" value="<?php echo htmlspecialchars($entityFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="action" value="<?php echo htmlspecialchars($actionFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <input type="hidden" name="date" value="<?php echo htmlspecialchars($dateFilter, ENT_QUOTES, 'UTF-8'); ?>">
                        <label class="audit-logs-smart-field">
                            <span class="audit-logs-smart-field__icon" aria-hidden="true">&#128269;</span>
                            <span class="sr-only">Search audit logs</span>
                            <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Search folder, user, action, date, or keyword" autocomplete="off">
                        </label>
                    </form>
                </div>
                <?php if ($roleFilter !== '' || $search !== ''): ?>
                    <a href="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php" class="btn-secondary">Back to Folders</a>
                <?php endif; ?>
            </div>

            <?php if ($search !== ''): ?>
                <nav class="audit-breadcrumb" aria-label="Audit breadcrumb">
                    <a href="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php">Audit Logs</a>
                    <span>&gt;</span>
                    <strong>Search: <?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?></strong>
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

            <?php if ($roleFilter === '' && $search === ''): ?>
                <div class="audit-folder-grid">
                    <?php foreach ($roleLabels as $roleValue => $roleLabel): ?>
                        <a class="audit-folder-card" data-audit-folder-card data-audit-folder-search="<?php echo htmlspecialchars(strtolower($roleLabel), ENT_QUOTES, 'UTF-8'); ?>" href="<?php echo htmlspecialchars(audit_logs_filter_url(['role' => $roleValue, 'actor' => '', 'q' => '', 'entity' => '', 'action' => '', 'date' => '', 'quick' => '']), ENT_QUOTES, 'UTF-8'); ?>">
                            <span class="audit-folder-card__icon" aria-hidden="true">&#128193;</span>
                            <strong><?php echo htmlspecialchars($roleLabel, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </a>
                    <?php endforeach; ?>
                    <div class="audit-empty-card" data-audit-folder-empty hidden>No folder matches your search.</div>
                </div>
            <?php elseif ($actorFilter === 0 && $search === ''): ?>
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
                <form method="GET" class="audit-logs-toolbar">
                    <input type="hidden" name="q" value="<?php echo htmlspecialchars($search, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="quick" value="<?php echo htmlspecialchars($quickFilter, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="role" value="<?php echo htmlspecialchars($roleFilter, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="actor" value="<?php echo (int)$actorFilter; ?>">
                    <div class="audit-logs-quick-filters" aria-label="Quick audit filters">
                        <a class="audit-quick-chip <?php echo $quickFilter === 'today' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(audit_logs_filter_url(['quick' => 'today', 'date' => '']), ENT_QUOTES, 'UTF-8'); ?>">Today</a>
                        <a class="audit-quick-chip <?php echo $quickFilter === 'week' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(audit_logs_filter_url(['quick' => 'week', 'date' => '']), ENT_QUOTES, 'UTF-8'); ?>">This Week</a>
                        <a class="audit-quick-chip <?php echo $quickFilter === 'month' ? 'is-active' : ''; ?>" href="<?php echo htmlspecialchars(audit_logs_filter_url(['quick' => 'month', 'date' => '']), ENT_QUOTES, 'UTF-8'); ?>">This Month</a>
                    </div>
                    <div class="audit-logs-toolbar__filters">
                        <label class="audit-logs-smart-field audit-logs-smart-field--date">
                            <span class="audit-logs-smart-field__icon" aria-hidden="true">&#128197;</span>
                            <span class="sr-only">Filter date</span>
                            <input type="date" name="date" value="<?php echo htmlspecialchars($dateFilter); ?>" data-audit-date-filter>
                        </label>
                        <label class="audit-logs-smart-field audit-logs-smart-field--select">
                            <span class="audit-logs-smart-field__icon" aria-hidden="true">&#9881;</span>
                            <span class="sr-only">Filter activity type</span>
                            <select name="entity" data-audit-entity-filter>
                                <option value="">All types</option>
                                <option value="user" <?php echo $entityFilter === 'user' ? 'selected' : ''; ?>>Users</option>
                                <option value="project" <?php echo $entityFilter === 'project' ? 'selected' : ''; ?>>Projects</option>
                                <option value="inventory" <?php echo $entityFilter === 'inventory' ? 'selected' : ''; ?>>Inventory</option>
                                <option value="asset" <?php echo $entityFilter === 'asset' ? 'selected' : ''; ?>>Assets</option>
                                <option value="scan" <?php echo $entityFilter === 'scan' ? 'selected' : ''; ?>>Scans</option>
                                <option value="task" <?php echo $entityFilter === 'task' ? 'selected' : ''; ?>>Tasks</option>
                                <option value="quotation" <?php echo $entityFilter === 'quotation' ? 'selected' : ''; ?>>Quotations</option>
                            </select>
                        </label>
                        <label class="audit-logs-smart-field audit-logs-smart-field--select">
                            <span class="audit-logs-smart-field__icon" aria-hidden="true">&#9679;</span>
                            <span class="sr-only">Filter action</span>
                            <select name="action" data-audit-action-filter>
                                <option value="">All actions</option>
                                <?php foreach ($actionOptions as $actionOption): ?>
                                    <option value="<?php echo htmlspecialchars($actionOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $actionFilter === $actionOption ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars(audit_logs_action_label($actionOption), ENT_QUOTES, 'UTF-8'); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <?php $hasActiveFilters = $entityFilter !== '' || $actionFilter !== '' || $dateFilter !== '' || $quickFilter !== ''; ?>
                        <a href="<?php echo htmlspecialchars(audit_logs_filter_url(['export' => 'csv']), ENT_QUOTES, 'UTF-8'); ?>" class="btn-secondary audit-export-btn">Export CSV</a>
                        <?php if ($hasActiveFilters): ?>
                            <a href="<?php echo htmlspecialchars(audit_logs_filter_url(['entity' => '', 'action' => '', 'date' => '', 'quick' => '']), ENT_QUOTES, 'UTF-8'); ?>" class="btn-secondary">Clear Filters</a>
                        <?php endif; ?>
                    </div>
                </form>

                <div class="table-wrapper">
                <table class="responsive-table mobile-card-table">
                    <thead>
                        <tr>
                            <th>Time</th>
                            <th>Actor</th>
                            <th>Action</th>
                            <th>Target</th>
                            <th>Summary</th>
                            <th>Full Details</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($activityRows) === 0): ?>
                            <tr>
                                <td colspan="6" class="table-empty-cell">No audit logs matched your filters.</td>
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
                                    <td data-label="Target">
                                        <span class="audit-target-line"><?php echo htmlspecialchars($auditTarget); ?></span>
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
                                <td colspan="6" class="table-empty-cell">No audit logs match your search.</td>
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
        <?php
    },
    ['/codesamplecaps/SUPERADMIN/css/audit-logs.css'],
    ['/codesamplecaps/SUPERADMIN/js/audit-logs.js'],
    'audit-logs-content'
);


