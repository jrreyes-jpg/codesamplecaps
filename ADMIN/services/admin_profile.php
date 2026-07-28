<?php
// Service ng Admin profile. Dito ang reusable profile helpers.
function admin_ensure_user_profile_photo_column(mysqli $conn): void
{
    if (hasColumn($conn, 'users', 'profile_photo_path')) {
        return;
    }

    $conn->query("ALTER TABLE users ADD COLUMN profile_photo_path VARCHAR(255) DEFAULT NULL AFTER token_expiry");
}

function admin_get_user_by_id(mysqli $conn, int $userId): ?array
{
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

function admin_store_profile_photo_upload(array $file, int $userId): array
{
    return profile_photo_store_upload($file, $userId);
}
