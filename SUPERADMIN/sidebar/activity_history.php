<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';

require_role('super_admin');

function activity_history_relative_time(?string $dateTime): string {
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

function activity_history_decode_payload(?string $payload): array {
    if (!$payload) {
        return [];
    }

    $decoded = json_decode($payload, true);

    return is_array($decoded) ? $decoded : [];
}

function activity_history_action_label(string $action): string {
    return ucwords(str_replace('_', ' ', $action));
}

function activity_history_build_details(array $entry): string {
    $action = (string)($entry['action'] ?? 'activity');
    $entityType = (string)($entry['entity_type'] ?? 'record');
    $actorName = (string)($entry['actor_name'] ?? 'System');
    $oldValues = activity_history_decode_payload($entry['old_values'] ?? null);
    $newValues = activity_history_decode_payload($entry['new_values'] ?? null);

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
$allowedEntities = ['user', 'project', 'inventory', 'asset', 'scan'];
if (!in_array($entityFilter, $allowedEntities, true)) {
    $entityFilter = '';
}

$activityRows = [];

if (function_exists('audit_log_table_exists') ? audit_log_table_exists($conn) : true) {
    $sql = "SELECT
                l.id,
                l.created_at,
                l.action,
                l.entity_type,
                l.entity_id,
                l.old_values,
                l.new_values,
                actor.full_name AS actor_name
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
            OR CAST(l.entity_id AS CHAR) LIKE ?
        )";
        $searchLike = '%' . $search . '%';
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $params[] = $searchLike;
        $types .= 'ssss';
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
        }
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Activity History - Edge Automation</title>
    <link rel="stylesheet" href="../css/super_admin_dashboard.css">
</head>
<body>
<div class="container">
    <?php include __DIR__ . '/../super_admin_sidebar.php'; ?>

    <main class="main-content scan-history-content">
        <div class="header page-header-card">
            <div class="header-copy">
                <h1>Activity History</h1>
            </div>
        </div>

        <section class="dashboard-panel activity-history-panel">
            <form method="GET" class="activity-history-toolbar">
                <div class="activity-history-toolbar__search">
                    <label class="activity-history-smart-field">
                        <span class="activity-history-smart-field__icon" aria-hidden="true">&#128269;</span>
                        <span class="sr-only">Search activity history</span>
                        <input type="search" name="q" value="<?php echo htmlspecialchars($search); ?>" placeholder="Smart search actor, action, type, details, or ID" autocomplete="off">
                    </label>
                </div>
                <div class="activity-history-toolbar__filters">
                    <label class="activity-history-smart-field activity-history-smart-field--select">
                        <span class="activity-history-smart-field__icon" aria-hidden="true">&#9881;</span>
                        <span class="sr-only">Filter activity type</span>
                        <select name="entity">
                            <option value="">All types</option>
                            <option value="user" <?php echo $entityFilter === 'user' ? 'selected' : ''; ?>>Users</option>
                            <option value="project" <?php echo $entityFilter === 'project' ? 'selected' : ''; ?>>Projects</option>
                            <option value="inventory" <?php echo $entityFilter === 'inventory' ? 'selected' : ''; ?>>Inventory</option>
                            <option value="asset" <?php echo $entityFilter === 'asset' ? 'selected' : ''; ?>>Assets</option>
                            <option value="scan" <?php echo $entityFilter === 'scan' ? 'selected' : ''; ?>>Scans</option>
                        </select>
                    </label>
                    <button type="submit" class="btn-primary">Filter</button>
                    <a href="/codesamplecaps/SUPERADMIN/sidebar/activity_history.php" class="btn-secondary">Reset</a>
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
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (count($activityRows) === 0): ?>
                            <tr>
                                <td colspan="5" class="table-empty-cell">No activity history matched your filters.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($activityRows as $row): ?>
                                <tr>
                                    <td data-label="Time">
                                        <strong><?php echo htmlspecialchars(activity_history_relative_time((string)($row['created_at'] ?? ''))); ?></strong><br>
                                        <small><?php echo htmlspecialchars((string)($row['created_at'] ?? '')); ?></small>
                                    </td>
                                    <td data-label="Actor"><?php echo htmlspecialchars((string)($row['actor_name'] ?: 'System')); ?></td>
                                    <td data-label="Action">
                                        <strong><?php echo htmlspecialchars(activity_history_action_label((string)($row['action'] ?? 'activity'))); ?></strong>
                                    </td>
                                    <td data-label="Target">
                                        <span><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', (string)($row['entity_type'] ?? 'Record')))); ?></span><br>
                                        <small>
                                            <?php echo !empty($row['entity_id']) ? 'ID #' . (int)$row['entity_id'] : 'No linked ID'; ?>
                                        </small>
                                    </td>
                                    <td data-label="Details"><?php echo htmlspecialchars(activity_history_build_details($row)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</div>
<script src="../js/super_admin_dashboard.js"></script>
</body>
</html>
