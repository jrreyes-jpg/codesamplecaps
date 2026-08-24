<?php
require_once __DIR__ . '/../../../includes/admin_auth.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit_log.php';
require_once __DIR__ . '/../../../../config/project_progress.php';
require_once __DIR__ . '/../../../../config/profile_photo_storage.php';
require_once __DIR__ . '/../../../services/admin_metrics.php';
require_once __DIR__ . '/../../../services/admin_profile.php';
$message = '';
$error = '';
$activeTab = $_GET['tab'] ?? 'dashboard';
if (!in_array($activeTab, ['dashboard', 'profile'], true)) {
    $activeTab = 'dashboard';
}
$allowedRoles = ['engineer', 'foreman', 'client'];
$allowedStatuses = ['active', 'inactive'];
$action = '';
$old = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'role' => ''
];

admin_ensure_user_profile_photo_column($conn);
$dashboardFlash = consumeDashboardFlash();
if ($dashboardFlash['type'] === 'success') {
    $message = $dashboardFlash['text'];
} elseif ($dashboardFlash['type'] === 'error') {
    $error = $dashboardFlash['text'];
}

function normalizeRole(string $role): string
{
    $role = strtolower(trim($role));
    return $role === 'foremen' ? 'foreman' : $role;
}

function isValidPhMobile(?string $phone): bool
{
    if ($phone === null || $phone === '') {
        return true;
    }
    return (bool)preg_match('/^09\d{9}$/', $phone);
}

function isStrongPassword(string $password): bool
{
    return strlen($password) >= 12
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/\d/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

function isValidInquiryStatus(string $status): bool
{
    return in_array($status, ['Pending Review', 'Verified Lead', 'Not Qualified', 'For Inspection'], true);
}

function getCsrfToken(): string
{
    return auth_csrf_token('super_admin');
}

function isValidCsrfToken(?string $token): bool
{
    return auth_is_valid_csrf($token, 'super_admin');
}

function setDashboardFlash(string $type, string $text): void
{
    $_SESSION['super_admin_dashboard_flash'] = [
        'type' => $type,
        'text' => $text,
    ];
}

function consumeDashboardFlash(): array
{
    $flash = $_SESSION['super_admin_dashboard_flash'] ?? null;
    unset($_SESSION['super_admin_dashboard_flash']);

    if (!is_array($flash)) {
        return ['type' => '', 'text' => ''];
    }

    return [
        'type' => (string)($flash['type'] ?? ''),
        'text' => (string)($flash['text'] ?? ''),
    ];
}

function redirectToDashboardTab(string $tab): void
{
    $location = '/codesamplecaps/ADMIN/sidebar/dashboard/php/dashboard.php';

    // Mas malinis na URL para sa sidebar pages ng Admin.
    if ($tab === 'dashboard') {
        $location = '/codesamplecaps/ADMIN/sidebar/dashboard/php/dashboard.php';
    } elseif ($tab === 'users' || $tab === 'create') {
        $location = '/codesamplecaps/ADMIN/sidebar/dashboard/php/dashboard.php';
    } elseif ($tab !== '') {
        $location .= '?tab=' . rawurlencode($tab);
    }

    header('Location: ' . $location);
    exit;
}

function hasColumn(mysqli $conn, string $tableName, string $columnName): bool
{
    $stmt = $conn->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         AND COLUMN_NAME = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $result = $stmt->get_result();

    return (bool)($result && $result->fetch_assoc());
}

if (!function_exists('build_default_profile_avatar_data_uri')) {
    function build_default_profile_avatar_data_uri(): string
    {
        $relativePath = '/codesamplecaps/IMAGES/nodp.jpg';
        $absoluteFile = __DIR__ . '/../../../../IMAGES/nodp.jpg';

        if (is_file($absoluteFile) && is_readable($absoluteFile)) {
            return $relativePath;
        }

        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">
  <defs>
    <linearGradient id="fbAvatarBg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#ffffff;stop-opacity:1" />
      <stop offset="100%" style="stop-color:#f0f2f5;stop-opacity:1" />
    </linearGradient>
  </defs>
  <rect width="200" height="200" fill="url(#fbAvatarBg)"/>
  <circle cx="100" cy="70" r="35" fill="#ccc"/>
  <path d="M 30 180 Q 30 140 100 140 Q 170 140 170 180 L 170 200 L 30 200 Z" fill="#ccc"/>
</svg>
SVG;

        return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    }
}

function getUserForStatusChange(mysqli $conn, int $userId): ?array
{
    $stmt = $conn->prepare('SELECT id, full_name, email, phone, role, status FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_assoc() : null;
}

function getCountByQuery(mysqli $conn, string $sql, int $userId): int
{
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        return 0;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result ? $result->fetch_assoc() : null;

    return (int)($row['total'] ?? 0);
}

function getScalarInt(mysqli $conn, string $sql): int
{
    $result = $conn->query($sql);
    if (!$result) {
        return 0;
    }

    $row = $result->fetch_assoc();
    if (!$row) {
        return 0;
    }

    return (int)array_values($row)[0];
}

function hasTable(mysqli $conn, string $tableName): bool
{
    $stmt = $conn->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $result = $stmt->get_result();

    return (bool)($result && $result->fetch_assoc());
}

function formatRelativeDate(?string $dateTime): string
{
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
        return $dateTime;
    }
}

function getDateRangeTrend(mysqli $conn, string $tableName, string $dateColumn, int $rangeDays, ?string $whereSql = null): array
{
    $days = [];
    for ($offset = $rangeDays - 1; $offset >= 0; $offset--) {
        $date = new DateTimeImmutable("-{$offset} days");
        $key = $date->format('Y-m-d');
        $days[$key] = [
            'date' => $key,
            'label' => $date->format($rangeDays > 14 ? 'M d' : 'D'),
            'value' => 0,
        ];
    }

    $whereClause = $whereSql ? " AND {$whereSql}" : '';
    $result = $conn->query(
        "SELECT DATE({$dateColumn}) AS metric_date, COUNT(*) AS total
         FROM {$tableName}
         WHERE DATE({$dateColumn}) >= DATE_SUB(CURDATE(), INTERVAL " . ($rangeDays - 1) . " DAY)
         {$whereClause}
         GROUP BY DATE({$dateColumn})"
    );

    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $metricDate = (string)($row['metric_date'] ?? '');
            if (isset($days[$metricDate])) {
                $days[$metricDate]['value'] = (int)($row['total'] ?? 0);
            }
        }
    }

    return array_values($days);
}

function getTrendPeak(array $trend): int
{
    $values = array_map(static fn(array $item): int => (int)($item['value'] ?? 0), $trend);
    $peak = max($values ?: [0]);

    return $peak > 0 ? $peak : 1;
}

function buildAuditSummaryClean(array $entry): array
{
    $summary = buildAuditSummary($entry);
    $summary['details'] = str_replace(
        ['â€¢', '•'],
        '|',
        (string)($summary['details'] ?? '')
    );

    return $summary;
}

function decodeAuditPayload(?string $payload): array
{
    if (!$payload) {
        return [];
    }

    $decoded = json_decode($payload, true);

    return is_array($decoded) ? $decoded : [];
}

function formatAuditActionLabel(string $action): string
{
    return ucwords(str_replace('_', ' ', $action));
}

function buildAuditSummary(array $entry): array
{
    $action = (string)($entry['action'] ?? 'activity');
    $entityType = (string)($entry['entity_type'] ?? 'record');
    $actorName = (string)($entry['actor_name'] ?? 'System');
    $oldValues = decodeAuditPayload($entry['old_values'] ?? null);
    $newValues = decodeAuditPayload($entry['new_values'] ?? null);
    $title = formatAuditActionLabel($action);
    $details = 'Actor: ' . $actorName;

    if ($action === 'create_user') {
        $title = 'User created';
        $details = ($newValues['full_name'] ?? 'Unknown user') . ' • ' . ucwords(str_replace('_', ' ', (string)($newValues['role'] ?? 'user')));
    } elseif ($action === 'update_user_status') {
        $title = 'User status updated';
        $details = 'Status: ' . ucfirst((string)($oldValues['status'] ?? 'unknown')) . ' -> ' . ucfirst((string)($newValues['status'] ?? 'unknown'));
    } elseif ($action === 'update_user_profile') {
        $title = 'User profile updated';
        $details = ($newValues['full_name'] ?? 'User record updated') . ' • by ' . $actorName;
    } elseif ($action === 'create_project') {
        $title = 'Project created';
        $details = (string)($newValues['project_name'] ?? 'New project') . ' • ' . ucfirst((string)($newValues['status'] ?? 'pending'));
    } elseif ($action === 'update_project_status') {
        $title = 'Project status changed';
        $details = (string)($newValues['project_name'] ?? 'Project') . ' • ' . ucfirst((string)($oldValues['status'] ?? '')) . ' -> ' . ucfirst((string)($newValues['status'] ?? ''));
    } elseif ($action === 'update_project_details') {
        $title = 'Project details updated';
        $details = (string)($newValues['project_name'] ?? 'Project') . ' • by ' . $actorName;
    } elseif ($action === 'delete_project') {
        $title = 'Project moved to trash';
        $details = (string)($oldValues['project_name'] ?? 'Project') . ' • by ' . $actorName;
    } elseif ($action === 'restore_project') {
        $title = 'Project restored';
        $details = (string)($newValues['project_name'] ?? $oldValues['project_name'] ?? 'Project') . ' • by ' . $actorName;
    } elseif ($action === 'permanently_delete_project') {
        $title = 'Project permanently deleted';
        $details = (string)($oldValues['project_name'] ?? 'Project') . ' • by ' . $actorName;
    } elseif ($action === 'update_project_budget') {
        $title = 'Project budget updated';
        $details = (string)($newValues['project_name'] ?? 'Project') . ' â€¢ ' . number_format((float)($newValues['budget_amount'] ?? 0), 2);
    } elseif ($action === 'add_project_cost') {
        $title = 'Project cost logged';
        $details = (string)($newValues['project_name'] ?? 'Project') . ' â€¢ ' . (string)($newValues['cost_category'] ?? 'Cost');
    } elseif ($action === 'add_task') {
        $title = 'Task added';
        $details = (string)($newValues['task_name'] ?? 'Task') . ' • ' . (string)($newValues['project_name'] ?? 'Project');
    } elseif ($action === 'deploy_inventory_to_project') {
        $title = 'Inventory deployed';
        $details = (string)($newValues['asset_name'] ?? 'Inventory item') . ' • Qty ' . (int)($newValues['quantity'] ?? 0);
    } elseif ($action === 'return_project_inventory') {
        $title = 'Inventory returned';
        $details = (string)($newValues['asset_name'] ?? 'Inventory item') . ' • Qty ' . (int)($newValues['quantity'] ?? 0);
    } elseif ($action === 'create_inventory_item') {
        $title = 'Inventory record created';
        $details = (string)($newValues['asset_name'] ?? 'Inventory item') . ' • Qty ' . (int)($newValues['quantity'] ?? 0);
    } elseif ($action === 'update_inventory_item') {
        $title = 'Inventory updated';
        $details = (string)($newValues['asset_name'] ?? 'Inventory item') . ' • Qty ' . (int)($newValues['quantity'] ?? 0);
    } elseif ($action === 'create_asset') {
        $title = 'Asset created';
        $details = (string)($newValues['asset_name'] ?? 'Asset') . ' • ' . (string)($newValues['asset_type'] ?? 'Unspecified');
    } elseif ($action === 'delete_asset') {
        $title = 'Asset deleted';
        $details = (string)($oldValues['asset_name'] ?? 'Asset') . ' • by ' . $actorName;
    } elseif ($action === 'return_asset') {
        $title = 'Asset returned';
        $details = (string)($newValues['asset_name'] ?? 'Asset') . ' • status available';
    } else {
        $title = formatAuditActionLabel($action);
        $details = ucfirst($entityType) . ' • by ' . $actorName;
    }

    return [
        'title' => $title,
        'details' => $details,
        'badge' => $entityType !== '' ? $entityType : 'audit',
    ];
}

function fetchRecentDashboardActivity(mysqli $conn, int $limit = 5): array
{
    if (!audit_log_table_exists($conn)) {
        return [];
    }

    $limit = max(1, min(10, $limit));
    $result = $conn->query(
        "SELECT
            l.action,
            l.entity_type,
            l.entity_id,
            l.old_values,
            l.new_values,
            l.created_at,
            COALESCE(u.full_name, 'System') AS actor_name
         FROM audit_logs l
         LEFT JOIN users u ON u.id = l.user_id
         ORDER BY l.created_at DESC
         LIMIT {$limit}"
    );

    if (!$result) {
        return [];
    }

    $activities = [];
    while ($entry = $result->fetch_assoc()) {
        $summary = buildAuditSummaryClean($entry);
        $activities[] = [
            'title' => $summary['title'],
            'details' => $summary['details'],
            'badge' => $summary['badge'],
            'created_at' => $entry['created_at'] ?? null,
            'relative_time' => formatRelativeDate($entry['created_at'] ?? null),
        ];
    }

    return $activities;
}

function getDeactivationBlockers(mysqli $conn, int $userId, string $role): array
{
    $blockers = [];

    if ($role === 'engineer') {
        $activeProjects = getCountByQuery(
            $conn,
            "SELECT COUNT(*) AS total
             FROM project_assignments pa
             INNER JOIN projects p ON p.id = pa.project_id
             WHERE pa.engineer_id = ?
             AND p.status IN ('pending', 'ongoing', 'on-hold')" . (hasColumn($conn, 'projects', 'deleted_at') ? "
             AND p.deleted_at IS NULL" : ''),
            $userId
        );

        $openTasks = getCountByQuery(
            $conn,
            "SELECT COUNT(*) AS total
             FROM tasks
             WHERE assigned_to = ?
             AND status IN ('pending', 'ongoing', 'delayed')",
            $userId
        );

        if ($activeProjects > 0) {
            $blockers[] = $activeProjects . ' active project(s)';
        }

        if ($openTasks > 0) {
            $blockers[] = $openTasks . ' open task(s)';
        }
    }

    if ($role === 'client') {
        $activeProjects = getCountByQuery(
            $conn,
            "SELECT COUNT(*) AS total
             FROM projects
             WHERE client_id = ?
             AND status IN ('pending', 'ongoing', 'on-hold')" . (hasColumn($conn, 'projects', 'deleted_at') ? "
             AND deleted_at IS NULL" : ''),
            $userId
        );

        if ($activeProjects > 0) {
            $blockers[] = $activeProjects . ' active project(s)';
        }
    }

    return $blockers;
}

function ensureDeletedUsersArchiveTable(mysqli $conn): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    $conn->query(
        "CREATE TABLE IF NOT EXISTS deleted_users_archive (
            id INT(11) NOT NULL AUTO_INCREMENT,
            original_user_id INT(11) NOT NULL,
            full_name VARCHAR(150) NOT NULL,
            email VARCHAR(100) NOT NULL,
            phone VARCHAR(20) DEFAULT NULL,
            role VARCHAR(30) NOT NULL,
            status VARCHAR(20) NOT NULL,
            deleted_by INT(11) DEFAULT NULL,
            deleted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            payload_json LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_deleted_users_archive_original (original_user_id),
            KEY idx_deleted_users_archive_deleted_by (deleted_by)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"
    );

    $ensured = true;
}

function ensureUserTrashColumns(mysqli $conn): void
{
    static $ensured = false;

    if ($ensured) {
        return;
    }

    if (!hasColumn($conn, 'users', 'deleted_at')) {
        $conn->query("ALTER TABLE users ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER status");
    }

    if (!hasColumn($conn, 'users', 'deleted_by')) {
        $conn->query("ALTER TABLE users ADD COLUMN deleted_by INT(11) DEFAULT NULL AFTER deleted_at");
    }

    if (!hasColumn($conn, 'users', 'restored_at')) {
        $conn->query("ALTER TABLE users ADD COLUMN restored_at DATETIME DEFAULT NULL AFTER deleted_by");
    }

    if (!hasColumn($conn, 'users', 'restored_by')) {
        $conn->query("ALTER TABLE users ADD COLUMN restored_by INT(11) DEFAULT NULL AFTER restored_at");
    }

    $indexResult = $conn->query("SHOW INDEX FROM users WHERE Key_name = 'idx_users_deleted_at'");
    if ($indexResult && $indexResult->num_rows === 0) {
        $conn->query("ALTER TABLE users ADD INDEX idx_users_deleted_at (deleted_at, role, status)");
    }

    $ensured = true;
}

function compareUsersForTable(array $left, array $right): int
{
    $statusOrder = [
        'active' => 0,
        'inactive' => 1,
    ];
    $roleOrder = [
        'engineer' => 0,
        'foreman' => 1,
        'client' => 2,
    ];

    $leftStatus = strtolower(trim((string)($left['status'] ?? 'inactive')));
    $rightStatus = strtolower(trim((string)($right['status'] ?? 'inactive')));
    $leftRole = normalizeRole((string)($left['role'] ?? ''));
    $rightRole = normalizeRole((string)($right['role'] ?? ''));
    $leftName = strtolower(trim((string)($left['full_name'] ?? '')));
    $rightName = strtolower(trim((string)($right['full_name'] ?? '')));

    $statusComparison = ($statusOrder[$leftStatus] ?? 99) <=> ($statusOrder[$rightStatus] ?? 99);
    if ($statusComparison !== 0) {
        return $statusComparison;
    }

    $roleComparison = ($roleOrder[$leftRole] ?? 99) <=> ($roleOrder[$rightRole] ?? 99);
    if ($roleComparison !== 0) {
        return $roleComparison;
    }

    if ($leftName === $rightName) {
        return ((int)($right['id'] ?? 0)) <=> ((int)($left['id'] ?? 0));
    }

    return $leftName <=> $rightName;
}

$supportsProfilePhoto = hasColumn($conn, 'users', 'profile_photo_path');
ensureUserTrashColumns($conn);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please try again.';
        $activeTab = $action === 'create_account'
            ? 'create'
            : (($action === 'update_my_profile' || $action === 'change_my_password') ? 'profile' : 'users');
    } elseif ($action === 'create_account') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $password = trim($_POST['password'] ?? '');
        $role = normalizeRole($_POST['role'] ?? '');
        $old['full_name'] = $fullName;
        $old['email'] = $email;
        $old['phone'] = $phone;
        $old['role'] = $role;

        if ($fullName === '' || $email === '' || $password === '' || $role === '') {
            $error = 'Full name, email, password, and role are required.';
            $activeTab = 'create';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format.';
            $activeTab = 'create';
        } elseif (!in_array($role, $allowedRoles, true)) {
            $error = 'Invalid role selected.';
            $activeTab = 'create';
        } elseif (!preg_match('/^09\d{9}$/', $phone)) {
            $error = 'Phone number must be a valid PH mobile number (09xxxxxxxxx).';
            $activeTab = 'create';
        } elseif (!isStrongPassword($password)) {
            $error = 'Temporary password must be STRONG: 12+ chars with uppercase, lowercase, number, special char.';
            $activeTab = 'create';
        } else {
            $dupStmt = $conn->prepare('SELECT id, full_name, email, phone FROM users WHERE full_name = ? OR email = ? OR phone = ? LIMIT 1');
            $dupStmt->bind_param('sss', $fullName, $email, $phone);
            $dupStmt->execute();
            $dup = $dupStmt->get_result();

            if ($dup->num_rows > 0) {
                $error = 'Duplicate detected. Full name, email, and phone must all be unique.';
                $activeTab = 'create';
            } else {
                $passwordHash = password_hash($password, PASSWORD_DEFAULT);
                $createStmt = $conn->prepare('INSERT INTO users (full_name, email, password, role, phone, status, created_by) VALUES (?, ?, ?, ?, ?, "active", ?)');
                $createStmt->bind_param('sssssi', $fullName, $email, $passwordHash, $role, $phone, $_SESSION['user_id']);

                if ($createStmt->execute()) {
                    $createdUserId = (int)$createStmt->insert_id;
                    audit_log_event(
                        $conn,
                        (int)($_SESSION['user_id'] ?? 0),
                        'create_user',
                        'user',
                        $createdUserId,
                        null,
                        [
                            'full_name' => $fullName,
                            'email' => $email,
                            'phone' => $phone,
                            'role' => $role,
                            'status' => 'active',
                        ]
                    );
                    $message = ucfirst($role) . ' account created successfully.';
                    $activeTab = 'users';
                } else {
                    $error = 'Failed to create account. Please check DB columns (role/phone/id) and try again.';
                    $activeTab = 'create';
                }
            }
        }
    }

    if ($action === 'update_status' && $error === '') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newStatus = trim($_POST['status'] ?? '');
        $user = $userId > 0 ? getUserForStatusChange($conn, $userId) : null;

        if ($userId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
            $error = 'Invalid status update request.';
        } elseif (!$user) {
            $error = 'User not found.';
        } elseif (normalizeRole((string)$user['role']) === 'super_admin') {
            $error = 'Super admin accounts cannot be changed from this screen.';
        } elseif ($newStatus === 'inactive' && $userId === (int)$_SESSION['user_id']) {
            $error = 'You cannot deactivate your own super admin account.';
        } elseif ($newStatus === 'inactive') {
            $blockers = getDeactivationBlockers($conn, $userId, normalizeRole((string)$user['role']));

            if (!empty($blockers)) {
                $error = 'Cannot deactivate ' . $user['full_name'] . ' yet. Reassign ' . implode(' and ', $blockers) . ' first.';
            } else {
                $stmt = $conn->prepare('UPDATE users SET status = ? WHERE id = ?');
                $stmt->bind_param('si', $newStatus, $userId);
                if ($stmt->execute()) {
                    audit_log_event(
                        $conn,
                        (int)($_SESSION['user_id'] ?? 0),
                        'update_user_status',
                        'user',
                        $userId,
                        ['status' => $user['status'] ?? null],
                        ['status' => $newStatus]
                    );
                    $message = 'User deactivated successfully.';
                } else {
                    $error = 'Failed to update user status.';
                }
            }
        } else {
            $stmt = $conn->prepare('UPDATE users SET status = ? WHERE id = ?');
            $stmt->bind_param('si', $newStatus, $userId);
            if ($stmt->execute()) {
                audit_log_event(
                    $conn,
                    (int)($_SESSION['user_id'] ?? 0),
                    'update_user_status',
                    'user',
                    $userId,
                    ['status' => $user['status'] ?? null],
                    ['status' => $newStatus]
                );
                $message = 'User reactivated successfully.';
            } else {
                $error = 'Failed to update user status.';
            }
        }
        $activeTab = 'users';
    }

    if ($action === 'update_inquiry_status' && $error === '') {
        $inquiryId = (int)($_POST['inquiry_id'] ?? 0);
        $newStatus = trim((string)($_POST['status'] ?? ''));

        if ($inquiryId <= 0 || !isValidInquiryStatus($newStatus)) {
            $error = 'Invalid inquiry status update request.';
        } else {
            $statusStmt = $conn->prepare('SELECT status FROM service_inquiries WHERE id = ? LIMIT 1');
            if (!$statusStmt) {
                $error = 'Unable to load inquiry for update.';
            } else {
                $statusStmt->bind_param('i', $inquiryId);
                $statusStmt->execute();
                $statusResult = $statusStmt->get_result();
                $existingInquiry = $statusResult ? $statusResult->fetch_assoc() : null;

                if (!$existingInquiry) {
                    $error = 'Inquiry not found.';
                } elseif ((string)($existingInquiry['status'] ?? '') === $newStatus) {
                    $error = 'Inquiry is already marked as ' . $newStatus . '.';
                } else {
                    $updateStmt = $conn->prepare('UPDATE service_inquiries SET status = ? WHERE id = ?');
                    if (!$updateStmt) {
                        $error = 'Failed to prepare inquiry update.';
                    } else {
                        $updateStmt->bind_param('si', $newStatus, $inquiryId);
                        if ($updateStmt->execute()) {
                            audit_log_event(
                                $conn,
                                (int)($_SESSION['user_id'] ?? 0),
                                'update_inquiry_status',
                                'service_inquiry',
                                $inquiryId,
                                ['status' => (string)($existingInquiry['status'] ?? '')],
                                ['status' => $newStatus]
                            );
                            $message = 'Inquiry status updated to ' . $newStatus . '.';
                        } else {
                            $error = 'Failed to update inquiry status.';
                        }
                    }
                }
            }
        }
        $activeTab = 'dashboard';
    }

    if ($action === 'edit_user' && $error === '') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = trim($_POST['edit_full_name'] ?? '');
        $email = trim($_POST['edit_email'] ?? '');
        $phone = trim($_POST['edit_phone'] ?? '');
        $user = $userId > 0 ? getUserForStatusChange($conn, $userId) : null;

        if ($userId <= 0 || $fullName === '' || $email === '') {
            $error = 'Invalid edit request. Full name and email are required.';
        } elseif (!$user) {
            $error = 'User not found.';
        } elseif (normalizeRole((string)$user['role']) === 'super_admin') {
            $error = 'Super admin accounts cannot be edited from this screen.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email format.';
        } elseif (!ctype_digit($phone) && $phone !== '') {
            $error = 'Phone number must contain numbers only.';
        } elseif (!isValidPhMobile($phone)) {
            $error = 'Phone number must be a valid PH mobile number (09xxxxxxxxx).';
        } else {
            $dupStmt = $conn->prepare('SELECT id FROM users WHERE (full_name = ? OR email = ? OR phone = ?) AND id != ? LIMIT 1');
            $dupStmt->bind_param('sssi', $fullName, $email, $phone, $userId);
            $dupStmt->execute();
            $dup = $dupStmt->get_result();

            if ($dup->num_rows > 0) {
                $error = 'Duplicate detected. Full name, email, and phone must all be unique.';
            } else {
                $stmt = $conn->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?');
                $stmt->bind_param('sssi', $fullName, $email, $phone, $userId);
                if ($stmt->execute()) {
                    audit_log_event(
                        $conn,
                        (int)($_SESSION['user_id'] ?? 0),
                        'update_user_profile',
                        'user',
                        $userId,
                        [
                            'full_name' => $user['full_name'] ?? null,
                            'email' => $user['email'] ?? null,
                            'phone' => $user['phone'] ?? null,
                        ],
                        [
                            'full_name' => $fullName,
                            'email' => $email,
                            'phone' => $phone,
                        ]
                    );
                    $message = 'User profile updated successfully.';
                } else {
                    $error = 'Failed to update user profile.';
                }
            }
        }
        $activeTab = 'users';
    }

    if ($action === 'delete_user' && $error === '') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = $userId > 0 ? getUserForStatusChange($conn, $userId) : null;

        if ($userId <= 0) {
            $error = 'Invalid delete request.';
        } elseif (!$user) {
            $error = 'User not found.';
        } elseif (normalizeRole((string)$user['role']) === 'super_admin') {
            $error = 'Super admin accounts cannot be deleted from this screen.';
        } elseif ($userId === (int)($_SESSION['user_id'] ?? 0)) {
            $error = 'You cannot delete your own super admin account.';
        } elseif (($user['status'] ?? 'active') !== 'inactive') {
            $error = 'Deactivate the user first before deleting the account.';
        } else {
            $blockers = getDeactivationBlockers($conn, $userId, normalizeRole((string)$user['role']));

            if (!empty($blockers)) {
                $error = 'Cannot delete ' . $user['full_name'] . ' yet. Reassign ' . implode(' and ', $blockers) . ' first.';
            } else {
                $deletedBy = (int)($_SESSION['user_id'] ?? 0);
                $stmt = $conn->prepare(
                    'UPDATE users
                     SET deleted_at = NOW(),
                         deleted_by = ?,
                         restored_at = NULL,
                         restored_by = NULL
                     WHERE id = ?
                     AND deleted_at IS NULL'
                );
                $stmt->bind_param('ii', $deletedBy, $userId);
                if ($stmt->execute() && $stmt->affected_rows > 0) {
                    audit_log_event(
                        $conn,
                        $deletedBy,
                        'trash_user',
                        'user',
                        $userId,
                        [
                            'full_name' => $user['full_name'] ?? null,
                            'email' => $user['email'] ?? null,
                            'phone' => $user['phone'] ?? null,
                            'role' => $user['role'] ?? null,
                            'status' => $user['status'] ?? null,
                        ],
                        [
                            'deleted_at' => date('Y-m-d H:i:s'),
                        ]
                    );
                    $message = 'User moved to trash successfully.';
                } else {
                    $error = 'Failed to move user to trash.';
                }
            }
        }

        $activeTab = 'users';
    }

    if ($action === 'update_my_profile' && $error === '') {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $profilePhotoUpload = $_FILES['profile_photo'] ?? null;
        $currentUser = $userId > 0 ? admin_get_user_by_id($conn, $userId) : null;

        if ($userId <= 0 || !$currentUser) {
            $error = 'Unable to load your admin account.';
        } elseif ($fullName === '' || $email === '') {
            $error = 'Full name and email are required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please use a valid email address.';
        } elseif (!ctype_digit($phone) && $phone !== '') {
            $error = 'Phone number must contain numbers only.';
        } elseif (!isValidPhMobile($phone)) {
            $error = 'Phone number must be a valid PH mobile number (09xxxxxxxxx).';
        } else {
            $dupStmt = $conn->prepare('SELECT id FROM users WHERE (full_name = ? OR email = ? OR phone = ?) AND id != ? LIMIT 1');
            $dupStmt->bind_param('sssi', $fullName, $email, $phone, $userId);
            $dupStmt->execute();
            $dup = $dupStmt->get_result();

            if ($dup && $dup->num_rows > 0) {
                $error = 'Full name, email, and phone must stay unique.';
            } else {
                $uploadedPhoto = ($supportsProfilePhoto && $profilePhotoUpload)
                    ? admin_store_profile_photo_upload($profilePhotoUpload, $userId)
                    : ['path' => null, 'error' => null];

                if ($uploadedPhoto['error'] !== null) {
                    $error = (string)$uploadedPhoto['error'];
                } else {
                    $newPhotoPath = $uploadedPhoto['path'] ?? ($currentUser['profile_photo_path'] ?? null);
                    $uploadedNewPhoto = $uploadedPhoto['path'] !== null;
                    $stmt = $supportsProfilePhoto
                        ? $conn->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, profile_photo_path = ? WHERE id = ?')
                        : $conn->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?');

                    if ($supportsProfilePhoto) {
                        $stmt->bind_param('ssssi', $fullName, $email, $phone, $newPhotoPath, $userId);
                    } else {
                        $stmt->bind_param('sssi', $fullName, $email, $phone, $userId);
                    }

                    if ($stmt->execute()) {
                        $_SESSION['name'] = $fullName;

                        if ($uploadedNewPhoto) {
                            profile_photo_cleanup_duplicates(
                                $userId,
                                profile_photo_file_name_from_reference($newPhotoPath)
                            );
                        }

                        audit_log_event(
                            $conn,
                            $userId,
                            'update_user_profile',
                            'user',
                            $userId,
                            [
                                'full_name' => $currentUser['full_name'] ?? null,
                                'email' => $currentUser['email'] ?? null,
                                'phone' => $currentUser['phone'] ?? null,
                                'profile_photo_path' => $currentUser['profile_photo_path'] ?? null,
                            ],
                            [
                                'full_name' => $fullName,
                                'email' => $email,
                                'phone' => $phone,
                                'profile_photo_path' => $newPhotoPath,
                            ]
                        );

                        setDashboardFlash('success', 'Your admin profile was updated.');
                        redirectToDashboardTab('profile');
                    } else {
                        $error = 'Failed to update your profile.';
                    }
                }
            }
        }

        $activeTab = 'profile';
    }

    if ($action === 'change_my_password' && $error === '') {
        $userId = (int)($_SESSION['user_id'] ?? 0);
        $currentPassword = (string)($_POST['current_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $confirmPassword = (string)($_POST['confirm_password'] ?? '');
        $currentUser = $userId > 0 ? admin_get_user_by_id($conn, $userId) : null;

        if ($userId <= 0 || !$currentUser) {
            $error = 'Unable to load your admin account.';
        } elseif ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
            $error = 'Complete all password fields first.';
        } elseif ($newPassword !== $confirmPassword) {
            $error = 'New password and confirmation do not match.';
        } elseif (!isStrongPassword($newPassword)) {
            $error = 'Use a strong password with 12+ chars, uppercase, lowercase, number, and special symbol.';
        } else {
            $passwordStmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
            $passwordStmt->bind_param('i', $userId);
            $passwordStmt->execute();
            $passwordResult = $passwordStmt->get_result();
            $passwordRow = $passwordResult ? $passwordResult->fetch_assoc() : null;

            if (!$passwordRow || !password_verify($currentPassword, (string)($passwordRow['password'] ?? ''))) {
                $error = 'Current password is incorrect.';
            } else {
                $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
                $updatePasswordStmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
                $updatePasswordStmt->bind_param('si', $newPasswordHash, $userId);

                if ($updatePasswordStmt->execute()) {
                    audit_log_event(
                        $conn,
                        $userId,
                        'change_password',
                        'user',
                        $userId,
                        null,
                        ['full_name' => $currentUser['full_name'] ?? null]
                    );
                    $message = 'Your password was changed successfully.';
                } else {
                    $error = 'Failed to change your password.';
                }
            }
        }

        $activeTab = 'profile';
    }
}


function fetchUsersByRoles(mysqli $conn, array $roles, string $statusFilter = '', bool $trashView = false): array
{
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $types = str_repeat('s', count($roles));
    $sql = "SELECT id, full_name, email, phone, status, role, deleted_at FROM users WHERE role IN ($placeholders)";

    $sql .= $trashView ? ' AND deleted_at IS NOT NULL' : ' AND deleted_at IS NULL';

    if ($statusFilter !== '') {
        $sql .= ' AND status = ?';
        $types .= 's';
        $roles[] = $statusFilter;
    }

    $sql .= ' ORDER BY id DESC';
    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$roles);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
}

$userStatusFilter = trim((string)($_GET['status'] ?? ''));
if (!in_array($userStatusFilter, $allowedStatuses, true)) {
    $userStatusFilter = '';
}
$isUserWorkspaceTab = in_array($activeTab, ['create', 'users'], true);
$engineers = fetchUsersByRoles($conn, ['engineer'], $isUserWorkspaceTab ? $userStatusFilter : '', false);
$foremen = fetchUsersByRoles($conn, ['foreman', 'foremen'], $isUserWorkspaceTab ? $userStatusFilter : '', false);
$clients = fetchUsersByRoles($conn, ['client'], $isUserWorkspaceTab ? $userStatusFilter : '', false);
$totalUsers = count($engineers) + count($foremen) + count($clients);
$managedUsers = array_merge($engineers, $foremen, $clients);
usort($managedUsers, 'compareUsersForTable');
$activeUsersAll = count(fetchUsersByRoles($conn, ['engineer', 'foreman', 'foremen', 'client'], 'active', false));
$trashedUsersCount = count(fetchUsersByRoles($conn, ['engineer', 'foreman', 'foremen', 'client'], '', true));
$csrfToken = getCsrfToken();
$currentAdmin = admin_get_user_by_id($conn, (int)($_SESSION['user_id'] ?? 0));
if ($supportsProfilePhoto && $currentAdmin) {
    $currentAdmin['profile_photo_path'] = profile_photo_migrate_legacy_reference(
        $conn,
        (int)($currentAdmin['id'] ?? 0),
        $currentAdmin['profile_photo_path'] ?? null
    );
}
$currentAdminName = (string)($currentAdmin['full_name'] ?? ($_SESSION['name'] ?? 'Admin'));
$currentAdminEmail = (string)($currentAdmin['email'] ?? '');
$currentAdminPhone = (string)($currentAdmin['phone'] ?? '');
$currentAdminRole = ucwords(str_replace('_', ' ', (string)($currentAdmin['role'] ?? 'super_admin')));
$currentAdminStatus = ucfirst((string)($currentAdmin['status'] ?? 'active'));
$currentAdminCreatedAt = formatRelativeDate($currentAdmin['created_at'] ?? null);
$defaultAdminPhotoUrl = build_default_profile_avatar_data_uri();
$currentAdminPhoto = trim((string)($currentAdmin['profile_photo_path'] ?? ''));
$currentAdminPhotoUrl = $currentAdminPhoto !== ''
    ? profile_photo_public_url($currentAdminPhoto)
    : '';
$currentAdminPhotoPreviewUrl = $currentAdminPhotoUrl !== '' ? $currentAdminPhotoUrl : $defaultAdminPhotoUrl;
$adminMetrics = admin_load_dashboard_metrics($conn, $engineers, $foremen, $clients);
foreach ($adminMetrics as $metricName => $metricValue) {
    ${$metricName} = $metricValue;
}

$inquiryRows = [];
if ($conn->ping()) {
    $inquiryResult = $conn->query(
        'SELECT id, client_name, company_name, email, contact_no, site_address, service_category, description, preferred_inspection_date, status, created_at
         FROM service_inquiries
         ORDER BY created_at DESC
         LIMIT 8'
    );

    if ($inquiryResult) {
        while ($row = $inquiryResult->fetch_assoc()) {
            $inquiryRows[] = [
                'id' => (int)($row['id'] ?? 0),
                'client_name' => (string)($row['client_name'] ?? ''),
                'company_name' => (string)($row['company_name'] ?? ''),
                'email' => (string)($row['email'] ?? ''),
                'contact_no' => (string)($row['contact_no'] ?? ''),
                'site_address' => (string)($row['site_address'] ?? ''),
                'service_category' => (string)($row['service_category'] ?? ''),
                'description' => (string)($row['description'] ?? ''),
                'preferred_inspection_date' => (string)($row['preferred_inspection_date'] ?? ''),
                'status' => (string)($row['status'] ?? 'Pending Review'),
                'created_at' => (string)($row['created_at'] ?? ''),
            ];
        }
    }
}

if (!in_array($activeTab, ['dashboard', 'profile'], true)) {
    // Admin wala nang User Management tab; balik sa dashboard para walang white screen.
    $activeTab = 'dashboard';
}
$userWorkspaceShouldOpenModal = false;

?>
<?php
$adminPageTitle = 'Admin Dashboard - Edge Automation';
$adminCssFiles = [
    '/codesamplecaps/ADMIN/css/super_admin_dashboard.css',
    '/codesamplecaps/ADMIN/sidebar/dashboard/css/dashboard.css',
];
include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php'; ?>

<main class="main-content admin-dashboard-content">


    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php
    $activeProjectCount = $ongoingProjects + $pendingProjects + $onHoldProjects;
    $activeWorkforceCount = $activeEngineerCount + $activeForemanCount + $activeClientCount;
    $pendingInquiries = $pendingInquiries ?? 0;
    $csrfToken = $csrfToken ?? '';
    ?>
    <div id="dashboard" class="tab-content <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>">
        <section class="dashboard-grid overview-dashboard" data-superadmin-overview>
            <section class="dashboard-panel summary-panel">
                <div class="panel-heading">
                    <div>
                        <h1 class="dashboard-section-title">Overview</h1>
                    </div>
                </div>
                <div class="metric-strip metric-strip-compact overview-summary-grid">
                    <a href="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?status=active" class="metric-tile metric-tile-link metric-tile-projects">
                        <span>Active Projects</span>
                        <strong data-live-metric="active_projects"><?php echo $activeProjectCount; ?></strong>
                        <small><?php echo $ongoingProjects; ?> ongoing, <?php echo $pendingProjects; ?> pending</small>
                    </a>
                    <a href="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?status=active" class="metric-tile metric-tile-link metric-tile-tasks">
                        <span>Open Tasks</span>
                        <strong data-live-metric="open_tasks"><?php echo $openTasks; ?></strong>
                        <small><?php echo $delayedTasks; ?> delayed</small>
                    </a>
                    <a href="/codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php" class="metric-tile metric-tile-link metric-tile-quotations">
                        <span>Pending Quotations</span>
                        <strong data-live-metric="pending_quotations"><?php echo $pendingQuotations; ?></strong>
                        <small>Need approval</small>
                    </a>
                    <a href="/codesamplecaps/ADMIN/sidebar/dashboard/php/dashboard.php" class="metric-tile metric-tile-link metric-tile-alerts">
                        <span>New Inquiries</span>
                        <strong data-live-metric="pending_inquiries"><?php echo $pendingInquiries ?? 0; ?></strong>
                        <small>Pending review</small>
                    </a>
                    <a href="/codesamplecaps/ADMIN/sidebar/scan_history.php" class="metric-tile metric-tile-link metric-tile-assets">
                        <span>Scans Today</span>
                        <strong data-live-metric="scans_today"><?php echo $scansToday; ?></strong>
                        <small>Asset activity</small>
                    </a>
                </div>
            </section>

            <section class="dashboard-panel overview-attention-panel">
                <div class="panel-heading">
                    <div>
                        <h2 class="dashboard-section-title">Needs Attention</h2>
                    </div>
                </div>
                <div class="overview-attention-grid">
                    <a href="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?status=active" class="overview-attention-card overview-attention-card--danger<?php echo $delayedTasks > 0 ? ' is-active' : ' is-clear'; ?>">
                        <span>Delayed Tasks</span>
                        <strong data-live-metric="delayed_tasks"><?php echo $delayedTasks; ?></strong>
                        <small><?php echo $totalTasks; ?> total tasks</small>
                    </a>
                    <a href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php" class="overview-attention-card overview-attention-card--warning<?php echo $inventoryAlertCount > 0 ? ' is-active' : ' is-clear'; ?>">
                        <span>Inventory Alerts</span>
                        <strong data-live-metric="inventory_alerts"><?php echo $inventoryAlertCount; ?></strong>
                        <small><?php echo $lowStockItems; ?> low, <?php echo $outOfStockItems; ?> out</small>
                    </a>
                    <a href="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?status=on-hold" class="overview-attention-card overview-attention-card--neutral<?php echo $onHoldProjects > 0 ? ' is-active' : ' is-clear'; ?>">
                        <span>On-Hold Projects</span>
                        <strong data-live-metric="on_hold_projects"><?php echo $onHoldProjects; ?></strong>
                        <small>Needs follow-up</small>
                    </a>
                    <a href="/codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php" class="overview-attention-card overview-attention-card--neutral<?php echo $pendingQuotations > 0 ? ' is-active' : ' is-clear'; ?>">
                        <span>Pending Approvals</span>
                        <strong data-live-metric="pending_quotations"><?php echo $pendingQuotations; ?></strong>
                        <small>Quotation review queue</small>
                    </a>
                </div>
            </section>

            <section class="dashboard-panel activity-panel overview-activity-panel">
                <div class="panel-heading">
                    <div>
                        <h2 class="dashboard-section-title">Recent Activity</h2>
                    </div>
                    <a href="/codesamplecaps/ADMIN/sidebar/activity_history/php/activity_history.php" class="action-chip">View all</a>
                </div>
                <div class="activity-feed activity-feed-compact" data-live-activity-feed>
                    <?php if (empty($recentDashboardActivity)): ?>
                        <div class="alert-empty">No recent activity yet.</div>
                    <?php else: ?>
                        <?php foreach ($recentDashboardActivity as $activity): ?>
                            <?php $badge = preg_replace('/[^a-z0-9_-]/i', '', strtolower((string)($activity['badge'] ?? 'audit'))) ?: 'audit'; ?>
                            <article class="activity-item">
                                <span class="activity-badge activity-<?php echo htmlspecialchars($badge, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars(strtoupper(substr((string)($activity['badge'] ?? 'A'), 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="activity-copy">
                                    <strong><?php echo htmlspecialchars((string)($activity['title'] ?? 'Activity'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span><?php echo htmlspecialchars((string)($activity['details'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                </span>
                                <time datetime="<?php echo htmlspecialchars((string)($activity['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <span class="activity-time-relative"><?php echo htmlspecialchars((string)($activity['relative_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                </time>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <section class="dashboard-panel overview-inquiries-panel">
                <div class="panel-heading">
                    <div>
                        <h2 class="dashboard-section-title">Latest Inquiries</h2>
                    </div>
                    <div class="overview-inquiry-summary">
                        <span class="action-chip overview-inquiry-summary__pending">Pending review</span>
                        <?php if (($pendingInquiries ?? 0) > 0): ?>
                            <span class="overview-inquiry-summary__count">
                                <span class="overview-inquiry-summary__dot"></span>
                                <?php echo (int)$pendingInquiries; ?> new
                            </span>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="activity-feed activity-feed-compact">
                    <?php if (empty($inquiryRows)): ?>
                        <div class="alert-empty">No inquiries yet.</div>
                    <?php else: ?>
                        <?php foreach ($inquiryRows as $inquiry): ?>
                            <article class="activity-item">
                                <span class="activity-badge activity-quotations">
                                    <?php echo htmlspecialchars(substr((string)($inquiry['status'] ?? 'Pending Review'), 0, 1), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="activity-copy">
                                    <strong><?php echo htmlspecialchars((string)($inquiry['client_name'] ?? 'Unknown'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span><?php echo htmlspecialchars((string)($inquiry['service_category'] ?? ''), ENT_QUOTES, 'UTF-8'); ?> • <?php echo htmlspecialchars((string)($inquiry['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                </span>
                                <div class="activity-item-actions">
                                    <?php if ((string)($inquiry['status'] ?? 'Pending Review') === 'Pending Review'): ?>
                                        <form method="POST" class="inline-action-form overview-inquiry-action">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="update_inquiry_status">
                                            <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                            <input type="hidden" name="status" value="Verified Lead">
                                            <button type="submit" class="btn-secondary">Verify Lead</button>
                                        </form>
                                        <form method="POST" class="inline-action-form overview-inquiry-action" data-confirm-message="Reject this inquiry?">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                                            <input type="hidden" name="action" value="update_inquiry_status">
                                            <input type="hidden" name="inquiry_id" value="<?php echo (int)$inquiry['id']; ?>">
                                            <input type="hidden" name="status" value="Not Qualified">
                                            <button type="submit" class="btn-danger">Not Qualified</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="activity-status-pill"><?php echo htmlspecialchars((string)($inquiry['status']), ENT_QUOTES, 'UTF-8'); ?></span>
                                    <?php endif; ?>
                                </div>
                                <details class="overview-inquiry-details">
                                    <summary>View details</summary>
                                    <div class="overview-inquiry-details__body">
                                        <div class="overview-inquiry-details__row"><strong>Company:</strong> <?php echo htmlspecialchars((string)($inquiry['company_name'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="overview-inquiry-details__row"><strong>Contact:</strong> <?php echo htmlspecialchars((string)($inquiry['contact_no'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="overview-inquiry-details__row"><strong>Site Address:</strong> <?php echo htmlspecialchars((string)($inquiry['site_address'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div class="overview-inquiry-details__row"><strong>Preferred Inspection Date:</strong> <?php echo htmlspecialchars((string)($inquiry['preferred_inspection_date'] ?? '—'), ENT_QUOTES, 'UTF-8'); ?></div>
                                        <div><strong>Message:</strong><br><?php echo nl2br(htmlspecialchars((string)($inquiry['description'] ?? '—'), ENT_QUOTES, 'UTF-8')); ?></div>
                                    </div>
                                </details>
                                <time datetime="<?php echo htmlspecialchars((string)($inquiry['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <span class="activity-time-relative"><?php echo htmlspecialchars((string)($inquiry['status'] ?? 'Pending Review'), ENT_QUOTES, 'UTF-8'); ?></span>
                                </time>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <details class="dashboard-panel analytics-panel overview-analytics-details" data-overview-analytics>
                <summary class="overview-analytics-summary">
                    <span>
                        <strong>Operations Analytics</strong>
                        <small>Progress, task health, asset activity, workforce, and intake</small>
                    </span>
                    <span class="overview-analytics-summary__chevron" aria-hidden="true"></span>
                </summary>
                <div class="panel-heading">
                    <div>
                        <h2 class="dashboard-section-title">Operations Analytics</h2>
                    </div>
                </div>
                <div class="mini-overview">
                    <article class="mini-overview-card">
                        <span>Project Completion</span>
                        <strong><?php echo $projectCompletionRate; ?>%</strong>
                        <small><?php echo $completedProjects; ?> of <?php echo $totalProjects; ?> projects completed</small>
                    </article>
                    <article class="mini-overview-card">
                        <span>Task Health</span>
                        <strong><?php echo $taskDelayRate; ?>%</strong>
                        <small><?php echo $delayedTasks; ?> delayed out of <?php echo $totalTasks; ?> total tasks</small>
                    </article>
                    <article class="mini-overview-card">
                        <span>Asset Activity</span>
                        <strong data-live-metric="scans_today"><?php echo $scansToday; ?></strong>
                        <small>QR scans captured today</small>
                    </article>
                    <article class="mini-overview-card">
                        <span>Active Workforce</span>
                        <strong><?php echo $activeWorkforceCount; ?></strong>
                        <small><?php echo $activeEngineerCount; ?> engineers, <?php echo $activeForemanCount; ?> foremen, <?php echo $activeClientCount; ?> clients</small>
                    </article>
                    <article class="mini-overview-card">
                        <span>7-Day Intake</span>
                        <strong><?php echo $projectsCreatedThisWeek; ?>/<?php echo $tasksCreatedThisWeek; ?></strong>
                        <small><?php echo $projectsCreatedThisWeek; ?> projects and <?php echo $tasksCreatedThisWeek; ?> tasks created this week</small>
                    </article>
                </div>
            </details>

            <section class="overview-quick-actions" aria-label="Quick actions">
                <a href="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php#create-project">Create Project</a>
                <a href="/codesamplecaps/ADMIN/sidebar/user_management.php?create=1">Add User</a>
                <a href="/codesamplecaps/ADMIN/sidebar/assets/php/assets.php">Add Asset</a>
                <a href="/codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php">Review Quotations</a>
            </section>
        </section>
    </div>


    <div id="profile" class="tab-content <?php echo $activeTab === 'profile' ? 'active' : ''; ?>">


        <div class="profile-grid">
            <section id="profile-details" class="form-section profile-form-card">
                <div class="panel-heading">
                    <div>
                        <h2 class="dashboard-section-title">Profile Details</h2>
                        <p class="panel-copy">Update the core details shown across the admin workspace.</p>
                    </div>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_my_profile">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="form-row">
                        <div class="form-group profile-photo-field">
                            <label for="profile_photo">Profile Photo</label>
                            <div class="profile-photo-upload">
                                <img
                                    src="<?php echo htmlspecialchars($currentAdminPhotoPreviewUrl); ?>"
                                    alt="Admin profile preview"
                                    class="profile-photo-upload__preview"
                                    data-profile-photo-preview
                                    data-profile-photo-default="<?php echo htmlspecialchars($currentAdminPhotoPreviewUrl); ?>">
                                <div class="profile-photo-upload__meta">
                                    <strong>Upload profile picture</strong>
                                    <span>Preview only while choosing. It will save only after you click Save Profile. JPG, PNG, or WEBP only. Max 3MB.</span>
                                    <input type="file" id="profile_photo" name="profile_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                    <small class="profile-photo-upload__state" data-profile-photo-state>
                                        <?php echo $currentAdminPhotoUrl !== '' ? 'Current profile photo ready.' : 'Default profile photo is active.'; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="admin_full_name">Full Name *</label>
                            <input type="text" id="admin_full_name" name="full_name" value="<?php echo htmlspecialchars($currentAdminName); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="admin_email">Email *</label>
                            <input type="email" id="admin_email" name="email" value="<?php echo htmlspecialchars($currentAdminEmail); ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="admin_phone">Phone Number</label>
                            <input type="tel" id="admin_phone" name="phone" value="<?php echo htmlspecialchars($currentAdminPhone); ?>" pattern="^09[0-9]{9}$" maxlength="11" placeholder="09XXXXXXXXX" inputmode="numeric" data-ph-mobile-input>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary">Save Profile</button>
                </form>
            </section>

            <section id="security-settings" class="form-section profile-form-card profile-form-card--security">
                <div class="panel-heading">
                    <div>
                        <h2 class="dashboard-section-title">Security</h2>
                        <p class="panel-copy">Change your password regularly, especially on shared or office machines.</p>
                    </div>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="change_my_password">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="form-row">
                        <div class="form-group password-field">
                            <label for="current_password">Current Password *</label>
                            <div class="password-input-wrap">
                                <input type="password" id="current_password" name="current_password" required>
                                <button type="button" class="togglePassword" data-target="current_password">Show</button>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group password-field">
                            <label for="new_password">New Password *</label>
                            <div class="password-input-wrap">
                                <input type="password" id="new_password" name="new_password" minlength="12" required>
                                <button type="button" class="togglePassword" data-target="new_password">Show</button>
                            </div>
                            <small class="password-tip">Use 12+ characters with uppercase, lowercase, number, and symbol.</small>
                            <small id="newPasswordStrength" class="pass-indicator">Strength: -</small>
                        </div>
                        <div class="form-group password-field">
                            <label for="confirm_password">Confirm Password *</label>
                            <div class="password-input-wrap">
                                <input type="password" id="confirm_password" name="confirm_password" minlength="12" required>
                                <button type="button" class="togglePassword" data-target="confirm_password">Show</button>
                            </div>
                            <small id="confirmPasswordMatch" class="pass-indicator">Confirmation: -</small>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary btn-primary--dark">Update Password</button>
                </form>
            </section>
        </div>
    </div>
</main>
<script src="/codesamplecaps/SHARED/js/operations-sidebar.js"></script>
<script src="/codesamplecaps/ADMIN/js/super_admin_dashboard.js"></script>
<script src="/codesamplecaps/ADMIN/sidebar/dashboard/js/dashboard.js"></script>
