<?php

// Dashboard lang ang profile form actions na ito.

function admin_dashboard_handle_update_my_profile(
    mysqli $conn,
    int $userId,
    string $fullName,
    string $email,
    string $phone,
    ?array $profilePhotoUpload,
    bool $supportsProfilePhoto
): array {
    $result = [
        'error' => '',
        'message' => '',
        'activeTab' => 'profile',
        'shouldRedirectToProfile' => false,
    ];

    $currentUser = $userId > 0 ? admin_get_user_by_id($conn, $userId) : null;

    if ($userId <= 0 || !$currentUser) {
        $result['error'] = 'Unable to load your admin account.';
        return $result;
    }

    if ($fullName === '' || $email === '') {
        $result['error'] = 'Full name and email are required.';
        return $result;
    }

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $result['error'] = 'Please use a valid email address.';
        return $result;
    }

    if (!ctype_digit($phone) && $phone !== '') {
        $result['error'] = 'Phone number must contain numbers only.';
        return $result;
    }

    if (!isValidPhMobile($phone)) {
        $result['error'] = 'Phone number must be a valid PH mobile number (09xxxxxxxxx).';
        return $result;
    }

    $dupStmt = $conn->prepare('SELECT id FROM users WHERE (full_name = ? OR email = ? OR phone = ?) AND id != ? LIMIT 1');
    if (!$dupStmt) {
        $result['error'] = 'Failed to update your profile.';
        return $result;
    }

    $dupStmt->bind_param('sssi', $fullName, $email, $phone, $userId);
    $dupStmt->execute();
    $dup = $dupStmt->get_result();

    if ($dup && $dup->num_rows > 0) {
        $result['error'] = 'Full name, email, and phone must stay unique.';
        return $result;
    }

    $uploadedPhoto = ($supportsProfilePhoto && $profilePhotoUpload)
        ? admin_store_profile_photo_upload($profilePhotoUpload, $userId)
        : ['path' => null, 'error' => null];

    if (($uploadedPhoto['error'] ?? null) !== null) {
        $result['error'] = (string)$uploadedPhoto['error'];
        return $result;
    }

    $newPhotoPath = $uploadedPhoto['path'] ?? ($currentUser['profile_photo_path'] ?? null);
    $uploadedNewPhoto = $uploadedPhoto['path'] !== null;
    $stmt = $supportsProfilePhoto
        ? $conn->prepare('UPDATE users SET full_name = ?, email = ?, phone = ?, profile_photo_path = ? WHERE id = ?')
        : $conn->prepare('UPDATE users SET full_name = ?, email = ?, phone = ? WHERE id = ?');

    if (!$stmt) {
        $result['error'] = 'Failed to update your profile.';
        return $result;
    }

    if ($supportsProfilePhoto) {
        $stmt->bind_param('ssssi', $fullName, $email, $phone, $newPhotoPath, $userId);
    } else {
        $stmt->bind_param('sssi', $fullName, $email, $phone, $userId);
    }

    if (!$stmt->execute()) {
        $result['error'] = 'Failed to update your profile.';
        return $result;
    }

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

    $result['message'] = 'Your admin profile was updated.';
    $result['shouldRedirectToProfile'] = true;
    return $result;
}

function admin_dashboard_handle_change_my_password(
    mysqli $conn,
    int $userId,
    string $currentPassword,
    string $newPassword,
    string $confirmPassword
): array {
    $result = [
        'error' => '',
        'message' => '',
        'activeTab' => 'profile',
    ];

    $currentUser = $userId > 0 ? admin_get_user_by_id($conn, $userId) : null;

    if ($userId <= 0 || !$currentUser) {
        $result['error'] = 'Unable to load your admin account.';
        return $result;
    }

    if ($currentPassword === '' || $newPassword === '' || $confirmPassword === '') {
        $result['error'] = 'Complete all password fields first.';
        return $result;
    }

    if ($newPassword !== $confirmPassword) {
        $result['error'] = 'New password and confirmation do not match.';
        return $result;
    }

    if (!isStrongPassword($newPassword)) {
        $result['error'] = 'Use a strong password with 12+ chars, uppercase, lowercase, number, and special symbol.';
        return $result;
    }

    $passwordStmt = $conn->prepare('SELECT password FROM users WHERE id = ? LIMIT 1');
    if (!$passwordStmt) {
        $result['error'] = 'Failed to change your password.';
        return $result;
    }

    $passwordStmt->bind_param('i', $userId);
    $passwordStmt->execute();
    $passwordResult = $passwordStmt->get_result();
    $passwordRow = $passwordResult ? $passwordResult->fetch_assoc() : null;

    if (!$passwordRow || !password_verify($currentPassword, (string)($passwordRow['password'] ?? ''))) {
        $result['error'] = 'Current password is incorrect.';
        return $result;
    }

    $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    $updatePasswordStmt = $conn->prepare('UPDATE users SET password = ? WHERE id = ?');
    if (!$updatePasswordStmt) {
        $result['error'] = 'Failed to change your password.';
        return $result;
    }

    $updatePasswordStmt->bind_param('si', $newPasswordHash, $userId);

    if (!$updatePasswordStmt->execute()) {
        $result['error'] = 'Failed to change your password.';
        return $result;
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

    $result['message'] = 'Your password was changed successfully.';
    return $result;
}
