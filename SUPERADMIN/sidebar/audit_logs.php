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
$allowedEntities = ['user', 'project', 'inventory', 'asset', 'scan', 'task', 'quotation'];
if (!in_array($entityFilter, $allowedEntities, true)) {
    $entityFilter = '';
}
if (!preg_match('/^[a-z_]+$/', $actionFilter)) {
    $actionFilter = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFilter)) {
    $dateFilter = '';
}

$activityRows = [];
$actionOptions = [];

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
            l.action LIKE ?
            OR l.entity_type LIKE ?
            OR actor.full_name LIKE ?
            OR actor.role LIKE ?
            OR l.ip_address LIKE ?
            OR CAST(l.entity_id AS CHAR) LIKE ?
        )";
        $searchLike = '%' . $search . '%';
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $types .= 'ssssss';
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
superadmin_render_page(
    'Audit Logs',
    function () use ($search, $entityFilter, $actionFilter, $dateFilter, $actionOptions, $activityRows): void {
        ?>
        <div class="header page-header-card">
            <div class="header-copy">
                <h1>Audit Logs</h1>
            </div>
        </div>

        <section class="dashboard-panel audit-logs-panel">
            <form method="GET" class="audit-logs-toolbar">
                <div class="audit-logs-toolbar__search">
                    <label class="audit-logs-smart-field">
                        <span class="audit-logs-smart-field__icon" aria-hidden="true">&#128269;</span>
                        <span class="sr-only">Search audit logs</span>
                        <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Smart search actor, action, type, details, or ID" autocomplete="off">
                    </label>
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
                    <button type="submit" class="btn-primary">Filter</button>
                    <a href="/codesamplecaps/SUPERADMIN/sidebar/audit_logs.php" class="btn-secondary">Reset</a>
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
                            <th>Details</th>
                            <th>View</th>
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
                                    (string)($row['entity_type'] ?? '') . ' ' .
                                    (string)($row['entity_id'] ?? '') . ' ' .
                                    (string)($row['ip_address'] ?? '') . ' ' .
                                    $auditDetails
                                ));
                                ?>
                                <tr data-audit-row data-audit-search="<?php echo htmlspecialchars($auditSearchText, ENT_QUOTES, 'UTF-8'); ?>">
                                    <td data-label="Time">
                                        <strong><?php echo htmlspecialchars(audit_logs_relative_time((string)($row['created_at'] ?? ''))); ?></strong><br>
                                        <small><?php echo htmlspecialchars((string)($row['created_at'] ?? '')); ?></small>
                                    </td>
                                    <td data-label="Actor"><?php echo htmlspecialchars((string)($row['actor_name'] ?: 'System')); ?></td>
                                    <td data-label="Action">
                                        <span class="audit-action-chip <?php echo htmlspecialchars(audit_logs_action_class((string)($row['action'] ?? 'activity'))); ?>">
                                            <?php echo htmlspecialchars(audit_logs_action_label((string)($row['action'] ?? 'activity'))); ?>
                                        </span>
                                    </td>
                                    <td data-label="Target">
                                        <span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($row['entity_type'] ?? 'Record')))); ?></span><br>
                                        <small>
                                            <?php echo !empty($row['entity_id']) ? 'ID #' . (int)$row['entity_id'] : 'No linked ID'; ?>
                                        </small>
                                    </td>
                                    <td data-label="Details">
                                        <span class="audit-detail-line" title="<?php echo htmlspecialchars($auditDetails, ENT_QUOTES, 'UTF-8'); ?>">
                                            <?php echo htmlspecialchars($auditDetails); ?>
                                        </span>
                                    </td>
                                    <td data-label="View">
                                        <button
                                            type="button"
                                            class="audit-view-btn"
                                            data-audit-open
                                            data-audit-time="<?php echo htmlspecialchars((string)($row['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-actor="<?php echo htmlspecialchars((string)($row['actor_name'] ?: 'System'), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-role="<?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($row['actor_role'] ?: 'System'))), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-action="<?php echo htmlspecialchars(audit_logs_action_label((string)($row['action'] ?? 'activity')), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-target="<?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($row['entity_type'] ?? 'Record'))) . (!empty($row['entity_id']) ? ' #' . (int)$row['entity_id'] : ''), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-ip="<?php echo htmlspecialchars((string)($row['ip_address'] ?? 'N/A'), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-old="<?php echo htmlspecialchars(audit_logs_format_json($row['old_values'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                            data-audit-new="<?php echo htmlspecialchars(audit_logs_format_json($row['new_values'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                        >Details</button>
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


