<?php
require_once __DIR__ . '/engineer_helpers.php';
require_once __DIR__ . '/../../config/user_notifications.php';

$engineerHeaderName = (string)($_SESSION['name'] ?? 'Engineer');
$engineerHeaderPhotoPath = '';
$engineerHeaderEmail = '';
$engineerHeaderPhone = '';
$engineerHeaderUserId = (int)($_SESSION['user_id'] ?? 0);

if (isset($conn) && $conn instanceof mysqli) {
    engineer_ensure_profile_columns($conn);
    $engineerHeaderStmt = $conn->prepare('SELECT email, phone, profile_photo_path FROM users WHERE id = ? LIMIT 1');
    if ($engineerHeaderStmt) {
        $engineerHeaderStmt->bind_param('i', $engineerHeaderUserId);
        $engineerHeaderStmt->execute();
        $engineerHeaderRow = $engineerHeaderStmt->get_result()->fetch_assoc() ?: [];
        $engineerHeaderEmail = trim((string)($engineerHeaderRow['email'] ?? ''));
        $engineerHeaderPhone = trim((string)($engineerHeaderRow['phone'] ?? ''));
        $engineerHeaderPhotoPath = trim((string)($engineerHeaderRow['profile_photo_path'] ?? ''));
        $engineerHeaderStmt->close();
    }
}

$engineerHeaderInitial = strtoupper(substr(trim($engineerHeaderName) !== '' ? trim($engineerHeaderName) : 'E', 0, 1));
$engineerHeaderPhotoUrl = $engineerHeaderPhotoPath !== '' ? profile_photo_public_url($engineerHeaderPhotoPath) : '';
$engineerHeaderCsrfToken = engineer_get_csrf_token();
$engineerHeaderReturnUrl = $_SERVER['REQUEST_URI'] ?? '/codesamplecaps/ENGINEER/dashboards/dashboard.php';
$engineerHeaderDate = date('F j, Y');
$engineerHeaderTime = date('g:i A');
$engineerHeaderNotificationData = ['unread_count' => 0, 'items' => []];
if (isset($conn) && $conn instanceof mysqli && $engineerHeaderUserId > 0) {
    $engineerHeaderNotificationData = user_notifications_fetch_unread_state($conn, $engineerHeaderUserId);
}
$engineerHeaderNotificationCsrf = auth_csrf_token('engineer_user_notifications');
ob_start();
?>
<?php
$headerProfileRootAttr = 'data-profile-root';
$headerProfileToggleId = 'engineerProfileToggle';
$headerProfileDropdownId = 'engineerProfileDropdown';
$headerProfileToggleAttr = 'data-profile-toggle';
$headerProfileName = $engineerHeaderName;
$headerProfileRole = 'Engineer';
$headerProfilePhotoUrl = $engineerHeaderPhotoUrl;
$headerProfileInitials = $engineerHeaderInitial;
$headerProfileAlt = 'Engineer profile photo';
$headerProfileLinks = [
    ['label' => 'Profile', 'href' => '/codesamplecaps/ENGINEER/dashboards/account_settings.php?section=profile'],
    ['label' => 'Settings', 'href' => '/codesamplecaps/ENGINEER/dashboards/account_settings.php?section=security'],
    ['label' => 'Logout', 'href' => '/codesamplecaps/LOGIN/php/logout.php'],
];
include __DIR__ . '/../../SHARED/header/profile/php/profile.php';
?>
<div
    class="topbar-notifications"
    data-notification-root
    data-engineer-notification-endpoint="/codesamplecaps/ENGINEER/api/notifications.php"
    data-engineer-notification-csrf="<?php echo htmlspecialchars($engineerHeaderNotificationCsrf, ENT_QUOTES, 'UTF-8'); ?>"
>
    <button
        id="topbarNotificationToggle"
        class="topbar-notifications__toggle"
        type="button"
        aria-label="Open notifications"
        aria-controls="topbarNotificationDropdown"
        aria-expanded="false"
    >
        <span class="topbar-notifications__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
                <path d="M12 3a4 4 0 0 0-4 4v1.1a7 7 0 0 1-1.52 4.33L5 14.5V16h14v-1.5l-1.48-2.07A7 7 0 0 1 16 8.1V7a4 4 0 0 0-4-4Zm0 18a3 3 0 0 0 2.83-2H9.17A3 3 0 0 0 12 21Z" fill="currentColor" />
            </svg>
        </span>
        <span class="topbar-notifications__badge" data-engineer-notification-badge<?php echo ($engineerHeaderNotificationData['unread_count'] ?? 0) > 0 ? '' : ' hidden'; ?>>
            <?php echo ($engineerHeaderNotificationData['unread_count'] ?? 0) > 99 ? '99+' : (int)($engineerHeaderNotificationData['unread_count'] ?? 0); ?>
        </span>
    </button>
    <div id="topbarNotificationDropdown" class="topbar-notifications__dropdown" hidden>
        <div class="topbar-notifications__panel-head">
            <div>
                <strong>Notifications</strong>
                <span data-engineer-notification-count><?php echo (int)($engineerHeaderNotificationData['unread_count'] ?? 0); ?> unread</span>
            </div>
        </div>
        <div class="topbar-notifications__section" data-engineer-notification-list>
            <?php if (($engineerHeaderNotificationData['unread_count'] ?? 0) === 0): ?>
                <div class="topbar-notifications__empty" data-engineer-notification-empty>No unread notifications.</div>
            <?php else: ?>
                <?php foreach ($engineerHeaderNotificationData['items'] as $notification): ?>
                    <a
                        href="<?php echo htmlspecialchars((string)$notification['target_url'], ENT_QUOTES, 'UTF-8'); ?>"
                        class="notification-item notification-item--info"
                        data-engineer-notification-id="<?php echo (int)$notification['id']; ?>"
                    >
                        <span class="notification-item__dot"></span>
                        <div class="notification-item__copy">
                            <strong><?php echo htmlspecialchars((string)$notification['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                            <span><?php echo htmlspecialchars((string)$notification['message'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <span class="notification-item__time"><?php echo htmlspecialchars(user_notifications_relative_time((string)($notification['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>
<?php
$operationsHeaderActionsHtml = ob_get_clean();
$operationsHeaderRole = 'engineer';
$operationsHeaderClass = 'global-topbar';
$operationsHeaderBrandClass = 'global-topbar__copy global-topbar__brand-link';
$operationsHeaderActionsClass = 'global-topbar__actions';
$operationsHeaderClockClass = 'global-topbar__clock';
$operationsHeaderHomeHref = '/codesamplecaps/ENGINEER/dashboards/dashboard.php';
$operationsHeaderBrandText = 'EDGE Automation';
$operationsHeaderLogoClass = 'global-topbar__brand-logo operations-topbar__brand-logo';
$operationsHeaderBrandLabel = 'Go to Engineer dashboard';
$operationsHeaderTime = $engineerHeaderTime;
$operationsHeaderDate = $engineerHeaderDate;
$operationsHeaderTimeAttr = 'class="global-topbar__time" data-engineer-time';
$operationsHeaderDateAttr = 'class="global-topbar__date" data-engineer-date';
$operationsHeaderAttrs = 'aria-live="polite"';
include __DIR__ . '/../../SHARED/header/core/operations-header.php';
?>
