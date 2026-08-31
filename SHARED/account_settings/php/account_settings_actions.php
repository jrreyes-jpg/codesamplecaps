<?php
require_once __DIR__ . '/../../../config/audit_log.php';
require_once __DIR__ . '/../../../config/profile_photo_storage.php';

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
    return $phone === '' || (bool)preg_match('/^09\d{9}$/', $phone);
}

function shared_account_is_strong_password(string $password): bool
{
    return strlen($password) >= 12
        && (bool)preg_match('/[A-Z]/', $password)
        && (bool)preg_match('/[a-z]/', $password)
        && (bool)preg_match('/\d/', $password)
        && (bool)preg_match('/[^A-Za-z0-9]/', $password);
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
    if (!ctype_digit($phone) && $phone !== '') {
        return ['error' => 'Phone number must contain numbers only.', 'message' => ''];
    }
    if (!shared_account_is_valid_phone($phone)) {
        return ['error' => 'Phone number must be a valid PH mobile number (09xxxxxxxxx).', 'message' => ''];
    }

    $duplicateStmt = $conn->prepare(
        'SELECT id FROM users WHERE (full_name = ? OR email = ? OR phone = ?) AND id != ? LIMIT 1'
    );
    if (!$duplicateStmt) {
        return ['error' => 'Failed to update your profile.', 'message' => ''];
    }

    $duplicateStmt->bind_param('sssi', $fullName, $email, $phone, $userId);
    $duplicateStmt->execute();
    $duplicateResult = $duplicateStmt->get_result();
    if ($duplicateResult && $duplicateResult->num_rows > 0) {
        return ['error' => 'Full name, email, and phone must stay unique.', 'message' => ''];
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
        ? $conn->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, profile_photo_path = ? WHERE id = ?')
        : $conn->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?');

    if (!$updateStmt) {
        return ['error' => 'Failed to update your profile.', 'message' => ''];
    }

    if ($supportsProfilePhoto) {
        $updateStmt->bind_param('ssssi', $fullName, $email, $phone, $newPhotoPath, $userId);
    } else {
        $updateStmt->bind_param('sssi', $fullName, $email, $phone, $userId);
    }

    if (!$updateStmt->execute()) {
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

    return ['error' => '', 'message' => 'Your password was changed successfully.'];
}
