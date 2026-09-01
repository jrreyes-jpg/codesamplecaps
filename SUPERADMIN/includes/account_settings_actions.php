<?php
require_once __DIR__ . '/../../SHARED/account_settings/php/account_settings_actions.php';
require_once __DIR__ . '/superadmin_helpers.php';

function superadmin_account_flash(string $type, string $text): void
{
    $_SESSION['superadmin_account_flash'] = ['type' => $type, 'text' => $text];
}

function superadmin_account_consume_flash(): array
{
    $flash = $_SESSION['superadmin_account_flash'] ?? [];
    unset($_SESSION['superadmin_account_flash']);

    return [
        'type' => (string)($flash['type'] ?? ''),
        'text' => (string)($flash['text'] ?? ''),
    ];
}

function superadmin_account_redirect(string $section = 'profile'): void
{
    $safeSection = $section === 'security' ? 'security' : 'profile';
    $location = '/codesamplecaps/SUPERADMIN/sidebar/account_settings.php?section=' . rawurlencode($safeSection);

    header('Location: ' . $location);
    exit;
}

function superadmin_account_context(mysqli $conn): array
{
    $message = '';
    $error = '';
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $supportsProfilePhoto = super_admin_sidebar_column_exists($conn, 'users', 'profile_photo_path');
    $section = (string)($_GET['section'] ?? 'profile');
    $section = $section === 'security' ? 'security' : 'profile';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'super_admin')) {
            $error = 'Your form expired. Please try again.';
        } elseif ($action === 'update_my_profile') {
            $profileUpload = isset($_FILES['profile_photo']) && is_array($_FILES['profile_photo'])
                ? $_FILES['profile_photo']
                : null;
            $result = shared_account_update_profile(
                $conn,
                $userId,
                'super_admin',
                trim((string)($_POST['full_name'] ?? '')),
                trim((string)($_POST['email'] ?? '')),
                trim((string)($_POST['phone'] ?? '')),
                $profileUpload,
                $supportsProfilePhoto
            );
            $error = $result['error'];

            if ($error === '') {
                $_SESSION['name'] = trim((string)($_POST['full_name'] ?? $_SESSION['name'] ?? 'Super Admin'));
                superadmin_account_flash('success', $result['message']);
                superadmin_account_redirect('profile');
            }
        } elseif ($action === 'change_my_password') {
            $result = shared_account_change_password(
                $conn,
                $userId,
                'super_admin',
                (string)($_POST['current_password'] ?? ''),
                (string)($_POST['new_password'] ?? ''),
                (string)($_POST['confirm_password'] ?? ''),
                $supportsProfilePhoto
            );
            $error = $result['error'];

            if ($error === '') {
                superadmin_account_flash('success', $result['message']);
                superadmin_account_redirect('security');
            }
        }
    }

    $flash = superadmin_account_consume_flash();
    if ($flash['type'] === 'success') {
        $message = $flash['text'];
    } elseif ($flash['type'] === 'error' && $error === '') {
        $error = $flash['text'];
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
        'csrfToken' => auth_csrf_token('super_admin'),
        'fullName' => (string)($currentUser['full_name'] ?? ($_SESSION['name'] ?? 'Super Admin')),
        'email' => (string)($currentUser['email'] ?? ''),
        'phone' => (string)($currentUser['phone'] ?? ''),
        'section' => $section,
        'photoUrl' => $photoPath !== '' ? profile_photo_public_url($photoPath, $userId) : '',
        'photoPreviewUrl' => $photoPath !== ''
            ? profile_photo_public_url($photoPath, $userId)
            : build_default_profile_avatar_data_uri(),
    ];
}
