<?php
require_once __DIR__ . '/../../config/audit_log.php';

// User Management logic lang ito para hindi na nakaasa sa dashboard file.
$allowedRoles = ['admin', 'engineer', 'foreman', 'inventory_clerk', 'client'];
$allowedStatuses = ['active', 'inactive'];

function normalizeRole(string $role): string {
    $role = strtolower(trim($role));
    return $role === 'foremen' ? 'foreman' : $role;
}

function normalizePhMobile(?string $phone): string {
    $digits = preg_replace('/\D+/', '', (string)$phone);

    if ($digits === '') {
        return '';
    }

    if (strpos($digits, '639') === 0) {
        return substr('09' . substr($digits, 3), 0, 11);
    }

    if (strpos($digits, '9') === 0) {
        return substr('0' . $digits, 0, 11);
    }

    if (strpos($digits, '09') !== 0) {
        return substr('09' . ltrim($digits, '0'), 0, 11);
    }

    return substr($digits, 0, 11);
}

function isValidPhMobile(?string $phone): bool {
    if ($phone === null || $phone === '') {
        return false;
    }

    return (bool)preg_match('/^09\d{9}$/', normalizePhMobile($phone));
}

function isValidPersonName(string $name): bool {
    return (bool)preg_match('/^[\p{L} .\'-]+$/u', $name)
        && (bool)preg_match('/[\p{L}]{2,}/u', $name);
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

function superadmin_user_flash(string $type, string $text, array $old = []): void {
    $_SESSION['superadmin_user_management_flash'] = [
        'type' => $type,
        'text' => $text,
        'old' => $old,
    ];
}

function superadmin_user_consume_flash(): array {
    $flash = $_SESSION['superadmin_user_management_flash'] ?? [];
    unset($_SESSION['superadmin_user_management_flash']);

    return [
        'type' => (string)($flash['type'] ?? ''),
        'text' => (string)($flash['text'] ?? ''),
        'old' => is_array($flash['old'] ?? null) ? $flash['old'] : [],
    ];
}

function superadmin_user_redirect(string $tab = 'users', string $status = '', string $role = '', bool $trashView = false): void {
    $url = '/codesamplecaps/SUPERADMIN/sidebar/user_management.php';
    if ($tab === 'create') {
        $url .= '?create=1';
    } else {
        $query = [];
        if ($trashView) {
            $query['view'] = 'trash';
        }
        if ($status !== '') {
            $query['status'] = $status;
        }
        if ($role !== '') {
            $query['role'] = $role;
        }
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }
    }

    header('Location: ' . $url);
    exit;
}

function superadmin_user_has_column(mysqli $conn, string $tableName, string $columnName): bool {
    $stmt = $conn->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
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

function superadmin_user_ensure_trash_columns(mysqli $conn): void {
    if (!superadmin_user_has_column($conn, 'users', 'status_changed_at')) {
        $conn->query('ALTER TABLE users ADD COLUMN status_changed_at DATETIME DEFAULT NULL AFTER status');
    }

    if (!superadmin_user_has_column($conn, 'users', 'deleted_at')) {
        $conn->query('ALTER TABLE users ADD COLUMN deleted_at DATETIME DEFAULT NULL AFTER status');
    }

    if (!superadmin_user_has_column($conn, 'users', 'deleted_by')) {
        $conn->query('ALTER TABLE users ADD COLUMN deleted_by INT(11) DEFAULT NULL AFTER deleted_at');
    }

    if (!superadmin_user_has_column($conn, 'users', 'restored_at')) {
        $conn->query('ALTER TABLE users ADD COLUMN restored_at DATETIME DEFAULT NULL AFTER deleted_by');
    }

    if (!superadmin_user_has_column($conn, 'users', 'restored_by')) {
        $conn->query('ALTER TABLE users ADD COLUMN restored_by INT(11) DEFAULT NULL AFTER restored_at');
    }
}

function getUserForStatusChange(mysqli $conn, int $userId): ?array {
    $deletedColumn = superadmin_user_has_column($conn, 'users', 'deleted_at');
    $statusChangedColumn = superadmin_user_has_column($conn, 'users', 'status_changed_at');
    $deletedSelect = $deletedColumn ? ', deleted_at' : ', NULL AS deleted_at';
    $statusChangedSelect = $statusChangedColumn ? ', status_changed_at' : ', NULL AS status_changed_at';
    $stmt = $conn->prepare('SELECT id, full_name, email, phone, role, status' . $statusChangedSelect . $deletedSelect . ' FROM users WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

function superadmin_user_count_by_query(mysqli $conn, string $sql, int $userId): int {
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

function getDeactivationBlockers(mysqli $conn, int $userId, string $role): array {
    $blockers = [];

    if ($role === 'engineer') {
        $projectDeletedFilter = superadmin_user_has_column($conn, 'projects', 'deleted_at') ? ' AND p.deleted_at IS NULL' : '';
        $activeProjects = superadmin_user_count_by_query(
            $conn,
            "SELECT COUNT(*) AS total
             FROM project_assignments pa
             INNER JOIN projects p ON p.id = pa.project_id
             WHERE pa.engineer_id = ?
             AND p.status IN ('pending', 'ongoing', 'on-hold'){$projectDeletedFilter}",
            $userId
        );

        if ($activeProjects > 0) {
            $blockers[] = $activeProjects . ' active project(s)';
        }
    }

    if ($role === 'client') {
        $deletedFilter = superadmin_user_has_column($conn, 'projects', 'deleted_at') ? ' AND deleted_at IS NULL' : '';
        $activeProjects = superadmin_user_count_by_query(
            $conn,
            "SELECT COUNT(*) AS total
             FROM projects
             WHERE client_id = ?
             AND status IN ('pending', 'ongoing', 'on-hold'){$deletedFilter}",
            $userId
        );

        if ($activeProjects > 0) {
            $blockers[] = $activeProjects . ' active project(s)';
        }
    }

    return $blockers;
}

function compareUsersForTable(array $left, array $right): int {
    $statusOrder = ['active' => 0, 'inactive' => 1];
    $roleOrder = ['admin' => 0, 'engineer' => 1, 'foreman' => 2, 'inventory_clerk' => 3, 'client' => 4];

    $statusCompare = ($statusOrder[$left['status'] ?? 'inactive'] ?? 99) <=> ($statusOrder[$right['status'] ?? 'inactive'] ?? 99);
    if ($statusCompare !== 0) {
        return $statusCompare;
    }

    $roleCompare = ($roleOrder[normalizeRole((string)($left['role'] ?? ''))] ?? 99) <=> ($roleOrder[normalizeRole((string)($right['role'] ?? ''))] ?? 99);
    if ($roleCompare !== 0) {
        return $roleCompare;
    }

    return strtolower((string)($left['full_name'] ?? '')) <=> strtolower((string)($right['full_name'] ?? ''));
}

function superadmin_user_format_date(?string $date): string {
    $timestamp = $date ? strtotime($date) : false;
    if ($timestamp === false) {
        return 'Not set';
    }

    return date('M j, Y', $timestamp);
}

function fetchUsersByRoles(mysqli $conn, array $roles, string $statusFilter = '', bool $trashView = false, string $roleFilter = ''): array {
    $roles = array_values(array_map('normalizeRole', $roles));
    if ($roleFilter !== '' && in_array($roleFilter, $roles, true)) {
        $roles = [$roleFilter];
    }
    $placeholders = implode(',', array_fill(0, count($roles), '?'));
    $types = str_repeat('s', count($roles));
    $deletedColumn = superadmin_user_has_column($conn, 'users', 'deleted_at');
    $statusChangedColumn = superadmin_user_has_column($conn, 'users', 'status_changed_at');
    $createdColumn = superadmin_user_has_column($conn, 'users', 'created_at');
    $sql = "SELECT id, full_name, email, phone, status, role"
        . ($createdColumn ? ', created_at' : ', NULL AS created_at')
        . ($statusChangedColumn ? ', status_changed_at' : ', NULL AS status_changed_at')
        . ($deletedColumn ? ', deleted_at' : ', NULL AS deleted_at')
        . " FROM users WHERE role IN ($placeholders)";

    if ($deletedColumn) {
        $sql .= $trashView ? ' AND deleted_at IS NOT NULL' : ' AND deleted_at IS NULL';
    }

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

function superadmin_user_duplicate_exists(mysqli $conn, string $fullName, string $email, string $phone, int $exceptUserId = 0): bool {
    if ($exceptUserId > 0) {
        $stmt = $conn->prepare('SELECT id FROM users WHERE (full_name = ? OR email = ? OR phone = ?) AND id != ? LIMIT 1');
        $stmt->bind_param('sssi', $fullName, $email, $phone, $exceptUserId);
    } else {
        $stmt = $conn->prepare('SELECT id FROM users WHERE full_name = ? OR email = ? OR phone = ? LIMIT 1');
        $stmt->bind_param('sss', $fullName, $email, $phone);
    }

    $stmt->execute();
    $result = $stmt->get_result();

    return (bool)($result && $result->fetch_assoc());
}

function superadmin_user_handle_post(mysqli $conn, array $allowedRoles, array $allowedStatuses): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }

    $action = (string)($_POST['action'] ?? '');
    $old = [
        'full_name' => trim((string)($_POST['full_name'] ?? '')),
        'email' => trim((string)($_POST['email'] ?? '')),
        'phone' => normalizePhMobile($_POST['phone'] ?? ''),
        'role' => normalizeRole((string)($_POST['role'] ?? '')),
    ];

    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        superadmin_user_flash('error', 'Invalid request. Please try again.', $old);
        superadmin_user_redirect($action === 'create_account' ? 'create' : 'users');
    }

    if ($action === 'create_account') {
        $password = trim((string)($_POST['password'] ?? ''));

        if ($old['full_name'] === '' || $old['email'] === '' || $old['phone'] === '' || $password === '' || $old['role'] === '') {
            superadmin_user_flash('error', 'Full name, email, phone, password, and role are required.', $old);
            superadmin_user_redirect('create');
        }

        if (!isValidPersonName($old['full_name']) || !filter_var($old['email'], FILTER_VALIDATE_EMAIL) || !in_array($old['role'], $allowedRoles, true) || !isValidPhMobile($old['phone']) || !isStrongPassword($password)) {
            superadmin_user_flash('error', 'Please check full name, email, role, phone, and password strength.', $old);
            superadmin_user_redirect('create');
        }

        if (superadmin_user_duplicate_exists($conn, $old['full_name'], $old['email'], $old['phone'])) {
            superadmin_user_flash('error', 'Duplicate detected. Full name, email, and phone must all be unique.', $old);
            superadmin_user_redirect('create');
        }

        $passwordHash = password_hash($password, PASSWORD_DEFAULT);
        $createdBy = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare('INSERT INTO users (full_name, email, password, role, phone, status, status_changed_at, created_by) VALUES (?, ?, ?, ?, ?, "active", NOW(), ?)');
        $stmt->bind_param('sssssi', $old['full_name'], $old['email'], $passwordHash, $old['role'], $old['phone'], $createdBy);

        if ($stmt->execute()) {
            audit_log_event($conn, $createdBy, 'create_user', 'user', (int)$stmt->insert_id, null, [
                'full_name' => $old['full_name'],
                'email' => $old['email'],
                'phone' => $old['phone'],
                'role' => $old['role'],
                'status' => 'active',
            ]);
            superadmin_user_flash('success', ucfirst(str_replace('_', ' ', $old['role'])) . ' account created successfully.');
            superadmin_user_redirect('users');
        }

        superadmin_user_flash('error', 'Failed to create account. Please try again.', $old);
        superadmin_user_redirect('create');
    }

    if ($action === 'update_status') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newStatus = trim((string)($_POST['status'] ?? ''));
        $user = $userId > 0 ? getUserForStatusChange($conn, $userId) : null;

        if ($userId <= 0 || !in_array($newStatus, $allowedStatuses, true) || !$user || normalizeRole((string)$user['role']) === 'super_admin') {
            superadmin_user_flash('error', 'Invalid status update request.');
            superadmin_user_redirect('users');
        }

        if ($newStatus === 'inactive') {
            $blockers = getDeactivationBlockers($conn, $userId, normalizeRole((string)$user['role']));
            if (!empty($blockers)) {
                superadmin_user_flash('error', 'Cannot deactivate ' . $user['full_name'] . ' yet. Reassign ' . implode(' and ', $blockers) . ' first.');
                superadmin_user_redirect('users');
            }
        }

        $stmt = $conn->prepare('UPDATE users SET status = ?, status_changed_at = NOW() WHERE id = ?');
        $stmt->bind_param('si', $newStatus, $userId);
        if ($stmt->execute()) {
            audit_log_event($conn, (int)($_SESSION['user_id'] ?? 0), 'update_user_status', 'user', $userId, ['status' => $user['status'] ?? null], ['status' => $newStatus]);
            superadmin_user_flash($newStatus === 'active' ? 'success' : 'warning', $newStatus === 'active' ? 'User reactivated successfully.' : 'User deactivated successfully.');
        } else {
            superadmin_user_flash('error', 'Failed to update user status.');
        }
        superadmin_user_redirect('users');
    }

    if ($action === 'edit_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $fullName = trim((string)($_POST['edit_full_name'] ?? ''));
        $email = trim((string)($_POST['edit_email'] ?? ''));
        $phone = normalizePhMobile($_POST['edit_phone'] ?? '');
        $user = $userId > 0 ? getUserForStatusChange($conn, $userId) : null;

        if ($userId <= 0 || !$user || normalizeRole((string)$user['role']) === 'super_admin' || !isValidPersonName($fullName) || !filter_var($email, FILTER_VALIDATE_EMAIL) || !isValidPhMobile($phone)) {
            superadmin_user_flash('error', 'Invalid edit request. Please check user details.');
            superadmin_user_redirect('users');
        }

        if (superadmin_user_duplicate_exists($conn, $fullName, $email, $phone, $userId)) {
            superadmin_user_flash('error', 'Duplicate detected. Full name, email, and phone must all be unique.');
            superadmin_user_redirect('users');
        }

        $stmt = $conn->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?');
        $stmt->bind_param('sssi', $fullName, $email, $phone, $userId);
        if ($stmt->execute()) {
            audit_log_event($conn, (int)($_SESSION['user_id'] ?? 0), 'update_user_profile', 'user', $userId, [
                'full_name' => $user['full_name'] ?? null,
                'email' => $user['email'] ?? null,
                'phone' => $user['phone'] ?? null,
            ], [
                'full_name' => $fullName,
                'email' => $email,
                'phone' => $phone,
            ]);
            superadmin_user_flash('success', 'User profile updated successfully.');
        } else {
            superadmin_user_flash('error', 'Failed to update user profile.');
        }
        superadmin_user_redirect('users');
    }

    if ($action === 'reset_password') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $newPassword = trim((string)($_POST['new_password'] ?? ''));
        $user = $userId > 0 ? getUserForStatusChange($conn, $userId) : null;

        if ($userId <= 0 || !$user || normalizeRole((string)$user['role']) === 'super_admin' || !isStrongPassword($newPassword)) {
            superadmin_user_flash('error', 'Password reset failed. Use a strong temporary password.');
            superadmin_user_redirect('users');
        }

        $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
        $stmt->bind_param('si', $passwordHash, $userId);
        if ($stmt->execute()) {
            audit_log_event($conn, (int)($_SESSION['user_id'] ?? 0), 'reset_user_password', 'user', $userId, null, [
                'email' => $user['email'] ?? null,
                'role' => normalizeRole((string)($user['role'] ?? '')),
            ]);
            superadmin_user_flash('success', 'Temporary password updated successfully.');
        } else {
            superadmin_user_flash('error', 'Failed to reset password.');
        }
        superadmin_user_redirect('users');
    }

    if ($action === 'delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = $userId > 0 ? getUserForStatusChange($conn, $userId) : null;

        if ($userId <= 0 || !$user || normalizeRole((string)$user['role']) === 'super_admin' || $userId === (int)($_SESSION['user_id'] ?? 0) || ($user['status'] ?? 'active') !== 'inactive') {
            superadmin_user_flash('error', 'Deactivate the user first before moving it to trash.');
            superadmin_user_redirect('users');
        }

        $blockers = getDeactivationBlockers($conn, $userId, normalizeRole((string)$user['role']));
        if (!empty($blockers)) {
            superadmin_user_flash('error', 'Cannot delete ' . $user['full_name'] . ' yet. Reassign ' . implode(' and ', $blockers) . ' first.');
            superadmin_user_redirect('users');
        }

        $deletedBy = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare('UPDATE users SET deleted_at = NOW(), deleted_by = ?, restored_at = NULL, restored_by = NULL WHERE id = ? AND deleted_at IS NULL');
        $stmt->bind_param('ii', $deletedBy, $userId);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            audit_log_event($conn, $deletedBy, 'trash_user', 'user', $userId, $user, ['deleted_at' => date('Y-m-d H:i:s')]);
            superadmin_user_flash('success', 'User moved to trash successfully.');
        } else {
            superadmin_user_flash('error', 'Failed to move user to trash.');
        }
        superadmin_user_redirect('users');
    }

    if ($action === 'restore_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = $userId > 0 ? getUserForStatusChange($conn, $userId) : null;

        if ($userId <= 0 || !$user || normalizeRole((string)$user['role']) === 'super_admin') {
            superadmin_user_flash('error', 'Invalid restore request.');
            superadmin_user_redirect('users', '', '', true);
        }

        $restoredBy = (int)($_SESSION['user_id'] ?? 0);
        $stmt = $conn->prepare('UPDATE users SET deleted_at = NULL, deleted_by = NULL, restored_at = NOW(), restored_by = ? WHERE id = ? AND deleted_at IS NOT NULL');
        $stmt->bind_param('ii', $restoredBy, $userId);
        if ($stmt->execute() && $stmt->affected_rows > 0) {
            audit_log_event($conn, $restoredBy, 'restore_user', 'user', $userId, ['deleted_at' => $user['deleted_at'] ?? null], ['restored_at' => date('Y-m-d H:i:s')]);
            superadmin_user_flash('success', 'User restored successfully.');
        } else {
            superadmin_user_flash('error', 'Failed to restore user.');
        }
        superadmin_user_redirect('users', '', '', true);
    }

    if ($action === 'permanent_delete_user') {
        $userId = (int)($_POST['user_id'] ?? 0);
        $user = $userId > 0 ? getUserForStatusChange($conn, $userId) : null;

        if ($userId <= 0 || !$user || normalizeRole((string)$user['role']) === 'super_admin' || $userId === (int)($_SESSION['user_id'] ?? 0)) {
            superadmin_user_flash('error', 'Invalid permanent delete request.');
            superadmin_user_redirect('users', '', '', true);
        }

        try {
            $stmt = $conn->prepare('DELETE FROM users WHERE id = ? AND deleted_at IS NOT NULL');
            $stmt->bind_param('i', $userId);
            if ($stmt->execute() && $stmt->affected_rows > 0) {
                audit_log_event($conn, (int)($_SESSION['user_id'] ?? 0), 'permanent_delete_user', 'user', $userId, $user, null);
                superadmin_user_flash('success', 'User permanently deleted.');
            } else {
                superadmin_user_flash('error', 'User must be in trash before permanent delete.');
            }
        } catch (mysqli_sql_exception $exception) {
            superadmin_user_flash('error', 'Cannot permanently delete this user because system records still use it.');
        }

        superadmin_user_redirect('users', '', '', true);
    }
}

function superadmin_user_context(mysqli $conn): array {
    global $allowedRoles, $allowedStatuses;

    superadmin_user_ensure_trash_columns($conn);
    superadmin_user_handle_post($conn, $allowedRoles, $allowedStatuses);

    $flash = superadmin_user_consume_flash();
    $old = array_merge([
        'full_name' => '',
        'email' => '',
        'phone' => '',
        'role' => '',
    ], $flash['old']);

    $userStatusFilter = trim((string)($_GET['status'] ?? ''));
    if (!in_array($userStatusFilter, $allowedStatuses, true)) {
        $userStatusFilter = '';
    }
    $userRoleFilter = normalizeRole((string)($_GET['role'] ?? ''));
    if (!in_array($userRoleFilter, $allowedRoles, true)) {
        $userRoleFilter = '';
    }
    $userTrashView = (string)($_GET['view'] ?? '') === 'trash';

    $managedUsers = fetchUsersByRoles($conn, $allowedRoles, $userStatusFilter, $userTrashView, $userRoleFilter);
    usort($managedUsers, 'compareUsersForTable');

    return [
        'message' => in_array($flash['type'], ['success', 'warning'], true) ? $flash['text'] : '',
        'messageType' => in_array($flash['type'], ['success', 'warning'], true) ? $flash['type'] : 'success',
        'error' => $flash['type'] === 'error' ? $flash['text'] : '',
        'isUserWorkspaceTab' => true,
        'userWorkspaceShouldOpenModal' => isset($_GET['create']) || $flash['type'] === 'error' && ($old['full_name'] !== '' || $old['email'] !== ''),
        'userStatusFilter' => $userStatusFilter,
        'userRoleFilter' => $userRoleFilter,
        'userTrashView' => $userTrashView,
        'allowedRoles' => $allowedRoles,
        'csrfToken' => getCsrfToken(),
        'old' => $old,
        'managedUsers' => $managedUsers,
    ];
}
