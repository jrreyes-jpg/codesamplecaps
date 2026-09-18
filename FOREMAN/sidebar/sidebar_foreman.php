<?php
require_once __DIR__ . '/../includes/foreman_helpers.php';
require_once __DIR__ . '/../../config/profile_photo_storage.php';

// Shared sidebar ang gamit ng Foreman. Dito lang ang role data ng header.
$foremanUserId = (int)($_SESSION['user_id'] ?? 0);
$foremanProfileName = trim((string)($foremanProfileName ?? ($_SESSION['name'] ?? 'Foreman')));
$foremanProfilePhotoUrl = '';

if ($foremanUserId > 0) {
    $profileStatement = $conn->prepare(
        'SELECT full_name, profile_photo_path FROM users WHERE id = ? LIMIT 1'
    );

    if ($profileStatement) {
        $profileStatement->bind_param('i', $foremanUserId);
        $profileStatement->execute();
        $profile = $profileStatement->get_result()->fetch_assoc() ?: [];
        $profileStatement->close();

        $foremanProfileName = trim((string)($profile['full_name'] ?? $foremanProfileName)) ?: 'Foreman';
        $photoPath = trim((string)($profile['profile_photo_path'] ?? ''));
        if ($photoPath !== '') {
            $foremanProfilePhotoUrl = profile_photo_public_url($photoPath, $foremanUserId);
        }
    }
}

$foremanProfileInitials = foreman_profile_initials($foremanProfileName);
$foremanNotifications = $foremanNotifications ?? [
    'attention_count' => 0,
    'logs_today' => 0,
    'scans_today' => 0,
];
?>
<?php include __DIR__ . '/../../SHARED/sidebar/php/sidebar.php'; ?>
<?php ob_start(); ?>
        <div class="topbar-notifications" data-notification-root>
            <button
                title="Notifications"
                id="topbarNotificationToggle"
                class="topbar-notifications__toggle"
                type="button"
                aria-label="Open notifications"
                aria-controls="topbarNotificationDropdown"
                aria-expanded="false"
            >
                <span class="topbar-notifications__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M12 3a4 4 0 0 0-4 4v1.1a7 7 0 0 1-1.52 4.33L5 14.5V16h14v-1.5l-1.48-2.07A7 7 0 0 1 16 8.1V7a4 4 0 0 0-4-4Zm0 18a3 3 0 0 0 2.83-2H9.17A3 3 0 0 0 12 21Z" fill="currentColor"></path>
                    </svg>
                </span>
                <?php if (($foremanNotifications['attention_count'] ?? 0) > 0): ?>
                    <span class="topbar-notifications__badge"><?php echo (int)$foremanNotifications['attention_count']; ?></span>
                <?php endif; ?>
            </button>

            <div id="topbarNotificationDropdown" class="topbar-notifications__dropdown" hidden>
                <div class="topbar-notifications__panel-head">
                    <div>
                        <strong>Today</strong>
                        <span><?php echo (int)($foremanNotifications['attention_count'] ?? 0); ?> need attention</span>
                    </div>
                </div>
                <div class="topbar-notifications__section">
                    <article class="notification-item notification-item--neutral">
                        <span class="notification-item__dot"></span>
                        <div class="notification-item__copy">
                            <strong><?php echo (int)($foremanNotifications['logs_today'] ?? 0); ?> usage log(s)</strong>
                            <span>Field asset usage recorded today.</span>
                        </div>
                    </article>
                    <article class="notification-item notification-item--neutral">
                        <span class="notification-item__dot"></span>
                        <div class="notification-item__copy">
                            <strong><?php echo (int)($foremanNotifications['scans_today'] ?? 0); ?> scan(s)</strong>
                            <span>QR scans captured by this account today.</span>
                        </div>
                    </article>
                    <article class="notification-item notification-item--warning">
                        <span class="notification-item__dot"></span>
                        <div class="notification-item__copy">
                            <strong><?php echo (int)($foremanNotifications['attention_count'] ?? 0); ?> asset(s) need follow-up</strong>
                            <span>Maintenance, damaged, or lost assets require checking.</span>
                        </div>
                    </article>
                </div>
            </div>
        </div>

        <?php
        $headerProfileRootAttr = 'data-profile-root';
        $headerProfileToggleId = 'topbarProfileToggle';
        $headerProfileDropdownId = 'topbarProfileDropdown';
        $headerProfileToggleAttr = 'data-profile-toggle';
        $headerProfileName = $foremanProfileName;
        $headerProfileRole = 'Foreman';
        $headerProfilePhotoUrl = $foremanProfilePhotoUrl;
        $headerProfileInitials = $foremanProfileInitials;
        $headerProfileAlt = 'Foreman profile photo';
        $headerProfileLinks = [
            ['label' => 'Overview', 'href' => '/codesamplecaps/FOREMAN/dashboards/foreman_dashboard.php'],
            ['label' => 'Reset Password', 'href' => '/codesamplecaps/LOGIN/php/forgot.php'],
            ['label' => 'Logout', 'href' => '/codesamplecaps/LOGIN/php/logout.php'],
        ];
        include __DIR__ . '/../../SHARED/header/profile/php/profile.php';
        ?>
<?php
$operationsHeaderActionsHtml = (string)ob_get_clean();
$operationsHeaderRole = 'foreman';
$operationsHeaderClass = 'global-topbar';
$operationsHeaderBrandClass = 'global-topbar__copy global-topbar__brand-link';
$operationsHeaderActionsClass = 'global-topbar__actions';
$operationsHeaderClockClass = 'global-topbar__clock';
$operationsHeaderHomeHref = '/codesamplecaps/FOREMAN/dashboards/foreman_dashboard.php';
$operationsHeaderBrandText = 'EDGE Automation';
$operationsHeaderLogoClass = 'global-topbar__brand-logo operations-topbar__brand-logo';
$operationsHeaderBrandLabel = 'Go to Foreman overview';
$operationsHeaderTime = '--:--:--';
$operationsHeaderDate = 'Loading date...';
$operationsHeaderTimeAttr = 'class="global-topbar__time" data-ph-time';
$operationsHeaderDateAttr = 'class="global-topbar__date" data-ph-date';
$operationsHeaderAttrs = 'aria-live="polite"';
include __DIR__ . '/../../SHARED/header/core/operations-header.php';
?>
<script src="/codesamplecaps/SHARED/header/core/operations-header.js" defer></script>
