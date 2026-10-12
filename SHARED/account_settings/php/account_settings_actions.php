<?php
require_once __DIR__ . '/../../../config/audit_log.php';
require_once __DIR__ . '/../../../config/profile_photo_storage.php';
require_once __DIR__ . '/../../../config/phone_normalization.php';

function shared_account_find_user(mysqli $conn, int $userId, bool $supportsProfilePhoto): ?array
{
    $photoColumn = $supportsProfilePhoto ? ', profile_photo_path' : ', NULL AS profile_photo_path';
    $stmt = $conn->prepare(
        "SELECT id, full_name, email, phone, role, status, created_at{$photoColumn}
         FROM users
         WHERE id = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();

    return $result ? $result->fetch_assoc() : null;
}

function shared_account_is_valid_phone(string $phone): bool
{
    return $phone === '' || is_valid_ph_mobile($phone);
}

function shared_account_is_strong_password(string $password): bool
{
    return strlen($password) >= 12
        && (bool)preg_match('/[A-Z]/', $password)
        && (bool)preg_match('/[a-z]/', $password)
        && (bool)preg_match('/\d/', $password)
        && (bool)preg_match('/[^A-Za-z0-9]/', $password);
}

function shared_account_supports_profile_photo(mysqli $conn): bool
{
    $result = $conn->query("SHOW COLUMNS FROM users LIKE 'profile_photo_path'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function shared_account_update_profile(
    mysqli $conn,
    int $userId,
    string $expectedRole,
    string $fullName,
    string $email,
    string $phone,
    ?array $profilePhotoUpload,
    bool $supportsProfilePhoto
): array {
    $phone = normalize_ph_mobile($phone);
    $currentUser = $userId > 0
        ? shared_account_find_user($conn, $userId, $supportsProfilePhoto)
        : null;

    if (!$currentUser || (string)($currentUser['role'] ?? '') !== $expectedRole) {
        return ['error' => 'Unable to load your account.', 'message' => ''];
    }
    if ($fullName === '' || $email === '') {
        return ['error' => 'Full name and email are required.', 'message' => ''];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['error' => 'Please use a valid email address.', 'message' => ''];
    }
    if (!shared_account_is_valid_phone($phone)) {
        return ['error' => 'Phone number must be a valid PH mobile number (09xxxxxxxxx).', 'message' => ''];
    }
    if (user_phone_normalized_exists($conn, $phone, $userId)) {
        return ['error' => 'This phone number is already used by another account.', 'message' => ''];
    }

    $duplicateStmt = $conn->prepare(
        'SELECT id FROM users WHERE (full_name = ? OR email = ?) AND id != ? LIMIT 1'
    );
    if (!$duplicateStmt) {
        return ['error' => 'Failed to update your profile.', 'message' => ''];
    }

    $duplicateStmt->bind_param('ssi', $fullName, $email, $userId);
    $duplicateStmt->execute();
    $duplicateResult = $duplicateStmt->get_result();
    if ($duplicateResult && $duplicateResult->num_rows > 0) {
        return ['error' => 'Full name and email must stay unique.', 'message' => ''];
    }

    $uploadedPhoto = ($supportsProfilePhoto && $profilePhotoUpload)
        ? profile_photo_store_upload($profilePhotoUpload, $userId)
        : ['path' => null, 'error' => null];
    if (($uploadedPhoto['error'] ?? null) !== null) {
        return ['error' => (string)$uploadedPhoto['error'], 'message' => ''];
    }

    $newPhotoPath = $uploadedPhoto['path'] ?? ($currentUser['profile_photo_path'] ?? null);
    $uploadedNewPhoto = $uploadedPhoto['path'] !== null;
    $updateStmt = $supportsProfilePhoto
        ? $conn->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, phone_normalized = ?, profile_photo_path = ? WHERE id = ?')
        : $conn->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, phone_normalized = ? WHERE id = ?');

    if (!$updateStmt) {
        if ($uploadedNewPhoto) {
            profile_photo_delete_reference($newPhotoPath);
        }
        return ['error' => 'Failed to update your profile.', 'message' => ''];
    }

    if ($supportsProfilePhoto) {
        $updateStmt->bind_param('sssssi', $fullName, $email, $phone, $phone, $newPhotoPath, $userId);
    } else {
        $updateStmt->bind_param('ssssi', $fullName, $email, $phone, $phone, $userId);
    }

    if (!$updateStmt->execute()) {
        if ($uploadedNewPhoto) {
            profile_photo_delete_reference($newPhotoPath);
        }
        return ['error' => 'Failed to update your profile.', 'message' => ''];
    }

    $_SESSION['name'] = $fullName;
    if ($uploadedNewPhoto) {
        profile_photo_cleanup_duplicates($userId, profile_photo_file_name_from_reference($newPhotoPath));
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

    return ['error' => '', 'message' => 'Your profile was updated successfully.'];
}

function shared_account_change_password(
    mysqli $conn,
    int $userId,
    string $expectedRole,
    string $currentPassword,
    string $newPassword,
    string $confirmPassword,
    bool $supportsProfilePhoto = false
): array {
    $currentUser = $userId > 0
        ? shared_account_find_user($conn, $userId, $supportsProfilePhoto)
        : null;

    if (!$currentUser || (string)($currentUser['role'] ?? '') !== $expectedRole) {
        return ['error' => 'Unable to load your account.', 'message' => ''];
    }
    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        return ['error' => 'Complete all password fields first.', 'message' => ''];
    }
    if ($newPassword !== $confirmPassword) {
        return ['error' => 'New password and confirmation do not match.', 'message' => ''];
    }
    if (!shared_account_is_strong_password($newPassword)) {
        return ['error' => 'Use a strong password with 12+ chars, uppercase, lowercase, number, and special symbol.', 'message' => ''];
    }

    $passwordStmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    if (!$passwordStmt) {
        return ['error' => 'Failed to change your password.', 'message' => ''];
    }

    $passwordStmt->bind_param('i', $userId);
    $passwordStmt->execute();
    $passwordResult = $passwordStmt->get_result();
    $passwordRow = $passwordResult ? $passwordResult->fetch_assoc() : null;
    if (!$passwordRow || !password_verify($currentPassword, (string)($passwordRow['password'] ?? ''))) {
        return ['error' => 'Current password is incorrect.', 'message' => ''];
    }

    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $updatePasswordStmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
    if (!$updatePasswordStmt) {
        return ['error' => 'Failed to change your password.', 'message' => ''];
    }

    $updatePasswordStmt->bind_param('si', $passwordHash, $userId);
    if (!$updatePasswordStmt->execute()) {
        return ['error' => 'Failed to change your password.', 'message' => ''];
    }

    audit_log_event(
        $conn,
        $userId,
        'change_password',
        'user',
        $userId,
        null,
        ['full_name' => $currentUser['full_name'] ?? null]
    );

    return ['error' => '', 'message' => 'Password updated successfully.'];
}

function shared_account_settings_context(mysqli $conn, array $options): array
{
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $expectedRole = (string)($options['role'] ?? '');
    $csrfScope = (string)($options['csrf_scope'] ?? $expectedRole);
    $redirectPath = (string)($options['redirect_path'] ?? '');
    $flashKey = (string)($options['flash_key'] ?? 'shared_account_flash');
    $defaultName = (string)($options['default_name'] ?? 'User');
    $defaultPhotoUrl = (string)($options['default_photo_url'] ?? '/codesamplecaps/IMAGES/nodp.jpg');
    $supportsProfilePhoto = shared_account_supports_profile_photo($conn);
    $section = (string)($_GET['section'] ?? 'profile');
    $section = $section === 'security' ? 'security' : 'profile';
    $message = '';
    $error = '';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');
        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, $csrfScope)) {
            $error = 'Your form expired. Please try again.';
        } elseif ($action === 'update_my_profile') {
            $profileUpload = isset($_FILES['profile_photo']) && is_array($_FILES['profile_photo'])
                ? $_FILES['profile_photo']
                : null;
            $result = shared_account_update_profile(
                $conn,
                $userId,
                $expectedRole,
                trim((string)($_POST['full_name'] ?? '')),
                trim((string)($_POST['email'] ?? '')),
                trim((string)($_POST['phone'] ?? '')),
                $profileUpload,
                $supportsProfilePhoto
            );
            $error = (string)$result['error'];
            if ($error === '') {
                $_SESSION[$flashKey] = ['type' => 'success', 'text' => (string)$result['message']];
                header('Location: ' . $redirectPath . '?section=profile');
                exit;
            }
        } elseif ($action === 'change_my_password') {
            $result = shared_account_change_password(
                $conn,
                $userId,
                $expectedRole,
                (string)($_POST['current_password'] ?? ''),
                (string)($_POST['new_password'] ?? ''),
                (string)($_POST['confirm_password'] ?? ''),
                $supportsProfilePhoto
            );
            $error = (string)$result['error'];
            if ($error === '') {
                $_SESSION[$flashKey] = ['type' => 'success', 'text' => (string)$result['message']];
                header('Location: ' . $redirectPath . '?section=security');
                exit;
            }
        }
    }

    $flash = $_SESSION[$flashKey] ?? [];
    unset($_SESSION[$flashKey]);
    if (($flash['type'] ?? '') === 'success') {
        $message = (string)($flash['text'] ?? '');
    } elseif (($flash['type'] ?? '') === 'error' && $error === '') {
        $error = (string)($flash['text'] ?? '');
    }

    $currentUser = $userId > 0
        ? shared_account_find_user($conn, $userId, $supportsProfilePhoto)
        : null;
    $photoPath = trim((string)($currentUser['profile_photo_path'] ?? ''));
    if ($supportsProfilePhoto && $currentUser && $photoPath !== '') {
        $photoPath = (string)profile_photo_migrate_legacy_reference($conn, $userId, $photoPath);
    }

    return [
        'message' => $message,
        'error' => $error,
        'csrfToken' => auth_csrf_token($csrfScope),
        'fullName' => (string)($currentUser['full_name'] ?? ($_SESSION['name'] ?? $defaultName)),
        'email' => (string)($currentUser['email'] ?? ''),
        'phone' => (string)($currentUser['phone'] ?? ''),
        'section' => $section,
        'photoUrl' => $photoPath !== '' ? profile_photo_public_url($photoPath, $userId) : '',
        'photoPreviewUrl' => $photoPath !== ''
            ? profile_photo_public_url($photoPath, $userId)
            : $defaultPhotoUrl,
    ];
}
