<?php
define('AUTH_REQUIRED_ROLE', 'super_admin');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/audit_log.php';
require_once __DIR__ . '/../../config/project_progress.php';
require_once __DIR__ . '/../../config/profile_photo_storage.php';

$message = '';
$error = '';
$activeTab = $_GET['tab'] ?? 'dashboard';
$allowedRoles = ['engineer', 'foreman', 'client'];
$allowedStatuses = ['active', 'inactive'];
$action = '';
$old = [
    'full_name' => '',
    'email' => '',
    'phone' => '',
    'role' => ''
];

ensureUserProfilePhotoColumn($conn);
$dashboardFlash = consumeDashboardFlash();
if ($dashboardFlash['type'] === 'success') {
    $message = $dashboardFlash['text'];
} elseif ($dashboardFlash['type'] === 'error') {
    $error = $dashboardFlash['text'];
}

function normalizeRole(string $role): string {
    $role = strtolower(trim($role));
    return $role === 'foremen' ? 'foreman' : $role;
}

function isValidPhMobile(?string $phone): bool {
    if ($phone === null || $phone === '') {
        return true;
    }
    return (bool)preg_match('/^09\d{9}$/', $phone);
}

function isStrongPassword(string $password): bool {
    return strlen($password) >= 12
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/\d/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

function getCsrfToken(): string {
    return auth_csrf_token('super_admin');
}

function isValidCsrfToken(?string $token): bool {
    return auth_is_valid_csrf($token, 'super_admin');
}

function setDashboardFlash(string $type, string $text): void {
    $_SESSION['super_admin_dashboard_flash'] = [
        'type' => $type,
        'text' => $text,
    ];
}

function consumeDashboardFlash(): array {
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

function redirectToDashboardTab(string $tab): void {
    $location = '/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php';
    if ($tab !== '') {
        $location .= '?tab=' . rawurlencode($tab);
    }

    header('Location: ' . $location);
    exit;
}

function hasColumn(mysqli $conn, string $tableName, string $columnName): bool {
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

function ensureUserProfilePhotoColumn(mysqli $conn): void {
    if (hasColumn($conn, 'users', 'profile_photo_path')) {
        return;
    }

    $conn->query("ALTER TABLE users ADD COLUMN profile_photo_path VARCHAR(255) DEFAULT NULL AFTER token_expiry");
}

if (!function_exists('build_default_profile_avatar_data_uri')) {
    function build_default_profile_avatar_data_uri(): string {
        $relativePath = '/codesamplecaps/IMAGES/nodp.jpg';
        $absoluteFile = __DIR__ . '/../../IMAGES/nodp.jpg';

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

function getUserById(mysqli $conn, int $userId): ?array {
    $selectPhoto = hasColumn($conn, 'users', 'profile_photo_path')
        ? ', profile_photo_path'
        : ', NULL AS profile_photo_path';
    $stmt = $conn->prepare("SELECT id, full_name, email, phone, role, status, created_at{$selectPhoto} FROM users WHERE id = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

function storeProfilePhotoUpload(array $file, int $userId): array {
    return profile_photo_store_upload($file, $userId);
}

function getUserForStatusChange(mysqli $conn, int $userId): ?array {
    $stmt = $conn->prepare('SELECT id, full_name, email, phone, role, status FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    return $result ? $result->fetch_assoc() : null;
}

function getCountByQuery(mysqli $conn, string $sql, int $userId): int {
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

function getScalarInt(mysqli $conn, string $sql): int {
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

function hasTable(mysqli $conn, string $tableName): bool {
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

function formatRelativeDate(?string $dateTime): string {
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

function getDateRangeTrend(mysqli $conn, string $tableName, string $dateColumn, int $rangeDays, ?string $whereSql = null): array {
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

function getTrendPeak(array $trend): int {
    $values = array_map(static fn(array $item): int => (int)($item['value'] ?? 0), $trend);
    $peak = max($values ?: [0]);

    return $peak > 0 ? $peak : 1;
}

function buildAuditSummaryClean(array $entry): array {
    $summary = buildAuditSummary($entry);
    $summary['details'] = str_replace(
        ['â€¢', '•'],
        '|',
        (string)($summary['details'] ?? '')
    );

    return $summary;
}

function decodeAuditPayload(?string $payload): array {
    if (!$payload) {
        return [];
    }

    $decoded = json_decode($payload, true);

    return is_array($decoded) ? $decoded : [];
}

function formatAuditActionLabel(string $action): string {
    return ucwords(str_replace('_', ' ', $action));
}

function buildAuditSummary(array $entry): array {
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

function getDeactivationBlockers(mysqli $conn, int $userId, string $role): array {
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

function ensureDeletedUsersArchiveTable(mysqli $conn): void {
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

function ensureUserTrashColumns(mysqli $conn): void {
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

function compareUsersForTable(array $left, array $right): int {
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
        $currentUser = $userId > 0 ? getUserById($conn, $userId) : null;

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
                    ? storeProfilePhotoUpload($profilePhotoUpload, $userId)
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
        $currentUser = $userId > 0 ? getUserById($conn, $userId) : null;

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


function fetchUsersByRoles(mysqli $conn, array $roles, string $statusFilter = '', bool $trashView = false): array {
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
$currentAdmin = getUserById($conn, (int)($_SESSION['user_id'] ?? 0));
if ($supportsProfilePhoto && $currentAdmin) {
    $currentAdmin['profile_photo_path'] = profile_photo_migrate_legacy_reference(
        $conn,
        (int)($currentAdmin['id'] ?? 0),
        $currentAdmin['profile_photo_path'] ?? null
    );
}
$currentAdminName = (string)($currentAdmin['full_name'] ?? ($_SESSION['name'] ?? 'Super Admin'));
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
$projectVisibilitySql = hasColumn($conn, 'projects', 'deleted_at') ? ' WHERE deleted_at IS NULL' : '';

$projectMetrics = $conn->query(
    "SELECT
        COUNT(*) AS total_projects,
        SUM(CASE WHEN status = 'ongoing' THEN 1 ELSE 0 END) AS ongoing_projects,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_projects,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_projects,
        SUM(CASE WHEN status = 'on-hold' THEN 1 ELSE 0 END) AS on_hold_projects
     FROM projects" . $projectVisibilitySql
);
$projectMetricRow = $projectMetrics ? $projectMetrics->fetch_assoc() : [];

$taskMetrics = $conn->query(
    "SELECT
        COUNT(*) AS total_tasks,
        SUM(CASE WHEN status IN ('pending', 'ongoing', 'delayed') THEN 1 ELSE 0 END) AS open_tasks,
        SUM(CASE WHEN status = 'delayed' THEN 1 ELSE 0 END) AS delayed_tasks
     FROM tasks"
);
$taskMetricRow = $taskMetrics ? $taskMetrics->fetch_assoc() : [];

$inventoryMetrics = $conn->query(
    "SELECT
        COUNT(*) AS inventory_items,
        COALESCE(SUM(quantity), 0) AS total_units,
        SUM(CASE WHEN status = 'low-stock' THEN 1 ELSE 0 END) AS low_stock_items,
        SUM(CASE WHEN status = 'out-of-stock' THEN 1 ELSE 0 END) AS out_of_stock_items
     FROM inventory"
);
$inventoryMetricRow = $inventoryMetrics ? $inventoryMetrics->fetch_assoc() : [];

$assetMetrics = $conn->query(
    "SELECT
        COUNT(*) AS total_assets,
        SUM(CASE WHEN created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01') THEN 1 ELSE 0 END) AS assets_this_month,
        SUM(CASE WHEN serial_number IS NULL OR TRIM(serial_number) = '' THEN 1 ELSE 0 END) AS assets_missing_serial
     FROM assets"
);
$assetMetricRow = $assetMetrics ? $assetMetrics->fetch_assoc() : [];

$totalProjects = (int)($projectMetricRow['total_projects'] ?? 0);
$ongoingProjects = (int)($projectMetricRow['ongoing_projects'] ?? 0);
$completedProjects = (int)($projectMetricRow['completed_projects'] ?? 0);
$pendingProjects = (int)($projectMetricRow['pending_projects'] ?? 0);
$onHoldProjects = (int)($projectMetricRow['on_hold_projects'] ?? 0);
$totalTasks = (int)($taskMetricRow['total_tasks'] ?? 0);
$openTasks = (int)($taskMetricRow['open_tasks'] ?? 0);
$delayedTasks = (int)($taskMetricRow['delayed_tasks'] ?? 0);
$inventoryItems = (int)($inventoryMetricRow['inventory_items'] ?? 0);
$totalUnits = (int)($inventoryMetricRow['total_units'] ?? 0);
$lowStockItems = (int)($inventoryMetricRow['low_stock_items'] ?? 0);
$outOfStockItems = (int)($inventoryMetricRow['out_of_stock_items'] ?? 0);
$totalAssets = (int)($assetMetricRow['total_assets'] ?? 0);
$assetsThisMonth = (int)($assetMetricRow['assets_this_month'] ?? 0);
$scansToday = getScalarInt($conn, "SELECT COUNT(*) FROM asset_scan_history WHERE scan_time >= CURDATE() AND scan_time < (CURDATE() + INTERVAL 1 DAY)");
$activeDeployments = hasTable($conn, 'project_inventory_deployments')
    ? getScalarInt(
        $conn,
        "SELECT COUNT(*)
         FROM (
             SELECT pid.id
             FROM project_inventory_deployments pid
             LEFT JOIN (
                 SELECT deployment_id, SUM(quantity) AS returned_quantity
                 FROM project_inventory_return_logs
                 GROUP BY deployment_id
             ) returns ON returns.deployment_id = pid.id
             WHERE (pid.quantity - COALESCE(returns.returned_quantity, 0)) > 0
         ) active_deployments"
    )
    : 0;
$activeEngineerCount = count(array_filter($engineers, static fn(array $user): bool => ($user['status'] ?? 'inactive') === 'active'));
$activeForemanCount = count(array_filter($foremen, static fn(array $user): bool => ($user['status'] ?? 'inactive') === 'active'));
$activeClientCount = count(array_filter($clients, static fn(array $user): bool => ($user['status'] ?? 'inactive') === 'active'));
$projectCompletionRate = $totalProjects > 0
    ? project_progress_clamp(
        (($completedProjects / $totalProjects) * 100)
        + (($ongoingProjects / $totalProjects) * 35)
        - (($onHoldProjects / $totalProjects) * 10)
    )
    : 0;
$taskDelayRate = $totalTasks > 0 ? (int)round(($delayedTasks / $totalTasks) * 100) : 0;
$inventoryAlertCount = $lowStockItems + $outOfStockItems;
$inventoryAlertRate = $inventoryItems > 0 ? (int)round(($inventoryAlertCount / $inventoryItems) * 100) : 0;
$projectTrend = getDateRangeTrend($conn, 'projects', 'created_at', 7);
$taskTrend = hasTable($conn, 'tasks') ? getDateRangeTrend($conn, 'tasks', 'created_at', 7) : [];
$scanTrend = hasTable($conn, 'asset_scan_history') ? getDateRangeTrend($conn, 'asset_scan_history', 'scan_time', 7) : [];
$projectsCreatedThisWeek = array_sum(array_map(static fn(array $item): int => (int)($item['value'] ?? 0), $projectTrend));
$tasksCreatedThisWeek = array_sum(array_map(static fn(array $item): int => (int)($item['value'] ?? 0), $taskTrend));
$scansThisWeek = array_sum(array_map(static fn(array $item): int => (int)($item['value'] ?? 0), $scanTrend));
$scanTrendPeak = !empty($scanTrend) ? getTrendPeak($scanTrend) : 0;
$userWorkspaceShouldOpenModal = $activeTab === 'create';

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Super Admin Dashboard - Edge Automation</title>
    <link rel="stylesheet" href="../css/super_admin_dashboard.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
        <link rel="icon" type="image/x-icon" href="../../IMAGES/edge.jpg">

</head>
<body>
<div class="container">
    <?php include __DIR__ . '/../super_admin_sidebar.php'; ?>

    <main class="main-content">
     

        <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

        <?php include __DIR__ . '/partials/overview.php'; ?>
        <?php include __DIR__ . '/partials/user_management.php'; ?>

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
                                            data-profile-photo-default="<?php echo htmlspecialchars($currentAdminPhotoPreviewUrl); ?>"
                                        >
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
                                    <input type="tel" id="admin_phone" name="phone" value="<?php echo htmlspecialchars($currentAdminPhone); ?>" pattern="^09[0-9]{9}$" maxlength="11" placeholder="09XXXXXXXXX" inputmode="numeric" oninput="this.value=this.value.replace(/[^0-9]/g,''); if(this.value && !this.value.startsWith('09')){this.value='09';}">
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
            </section>
        </div>
</div>
    </main>
</div>

<script src="../js/super_admin_dashboard.js"></script>
</body>
</html>
