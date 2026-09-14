<?php
// Ito ang maliit na connector ng shared profile actions para sa Inventory Clerk.
require_once __DIR__ . '/../../SHARED/account_settings/php/account_settings_actions.php';

function inventory_clerk_account_flash(string $type, string $text): void
{
    $_SESSION['inventory_clerk_account_flash'] = ['type' => $type, 'text' => $text];
}

function inventory_clerk_account_consume_flash(): array
{
    $flash = $_SESSION['inventory_clerk_account_flash'] ?? [];
    unset($_SESSION['inventory_clerk_account_flash']);

    return [
        'type' => (string)($flash['type'] ?? ''),
        'text' => (string)($flash['text'] ?? ''),
    ];
}

function inventory_clerk_account_redirect(string $section = 'profile'): void
{
    $safeSection = $section === 'security' ? 'security' : 'profile';
    header('Location: /codesamplecaps/INVENTORY_CLERK/dashboards/profile.php?section=' . rawurlencode($safeSection));
    exit;
}

function inventory_clerk_account_context(mysqli $conn): array
{
    $message = '';
    $error = '';
    $userId = (int)($_SESSION['user_id'] ?? 0);
    $section = (string)($_GET['section'] ?? 'profile');
    $section = $section === 'security' ? 'security' : 'profile';

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = (string)($_POST['action'] ?? '');

        if (!auth_is_valid_csrf($_POST['csrf_token'] ?? null, 'inventory_clerk_profile')) {
            $error = 'Your form expired. Please try again.';
        } elseif ($action === 'update_my_profile') {
            $profileUpload = isset($_FILES['profile_photo']) && is_array($_FILES['profile_photo'])
                ? $_FILES['profile_photo']
                : null;
            $result = shared_account_update_profile(
                $conn,
                $userId,
                'inventory_clerk',
                trim((string)($_POST['full_name'] ?? '')),
                trim((string)($_POST['email'] ?? '')),
                trim((string)($_POST['phone'] ?? '')),
                $profileUpload,
                true
            );
            $error = (string)$result['error'];

            if ($error === '') {
                inventory_clerk_account_flash('success', (string)$result['message']);
                inventory_clerk_account_redirect('profile');
            }
        } elseif ($action === 'change_my_password') {
            $result = shared_account_change_password(
                $conn,
                $userId,
                'inventory_clerk',
                (string)($_POST['current_password'] ?? ''),
                (string)($_POST['new_password'] ?? ''),
                (string)($_POST['confirm_password'] ?? ''),
                true
            );
            $error = (string)$result['error'];

            if ($error === '') {
                inventory_clerk_account_flash('success', (string)$result['message']);
                inventory_clerk_account_redirect('security');
            }
        }
    }

    $flash = inventory_clerk_account_consume_flash();
    if ($flash['type'] === 'success') {
        $message = $flash['text'];
    } elseif ($flash['type'] === 'error' && $error === '') {
        $error = $flash['text'];
    }

    $currentUser = $userId > 0 ? shared_account_find_user($conn, $userId, true) : null;
    $photoPath = trim((string)($currentUser['profile_photo_path'] ?? ''));

    if ($currentUser && $photoPath !== '') {
        $photoPath = (string)profile_photo_migrate_legacy_reference($conn, $userId, $photoPath);
    }

    return [
        'message' => $message,
        'error' => $error,
        'csrfToken' => auth_csrf_token('inventory_clerk_profile'),
        'fullName' => (string)($currentUser['full_name'] ?? ($_SESSION['name'] ?? 'Inventory Clerk')),
        'email' => (string)($currentUser['email'] ?? ''),
        'phone' => (string)($currentUser['phone'] ?? ''),
        'section' => $section,
        'photoUrl' => $photoPath !== '' ? profile_photo_public_url($photoPath, $userId) : '',
        'photoPreviewUrl' => $photoPath !== ''
            ? profile_photo_public_url($photoPath, $userId)
            : '/codesamplecaps/IMAGES/nodp.jpg',
    ];
}
