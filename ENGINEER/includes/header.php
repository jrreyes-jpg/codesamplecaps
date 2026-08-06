<?php
require_once __DIR__ . '/engineer_helpers.php';

$engineerHeaderName = (string)($_SESSION['name'] ?? 'Engineer');
$engineerHeaderPhotoPath = '';

if (isset($conn) && $conn instanceof mysqli) {
    engineer_ensure_profile_columns($conn);
    $engineerHeaderUserId = (int)($_SESSION['user_id'] ?? 0);
    $engineerHeaderStmt = $conn->prepare('SELECT profile_photo_path FROM users WHERE id = ? LIMIT 1');
    if ($engineerHeaderStmt) {
        $engineerHeaderStmt->bind_param('i', $engineerHeaderUserId);
        $engineerHeaderStmt->execute();
        $engineerHeaderRow = $engineerHeaderStmt->get_result()->fetch_assoc() ?: [];
        $engineerHeaderPhotoPath = trim((string)($engineerHeaderRow['profile_photo_path'] ?? ''));
        $engineerHeaderStmt->close();
    }
}

$engineerHeaderInitial = strtoupper(substr(trim($engineerHeaderName) !== '' ? trim($engineerHeaderName) : 'E', 0, 1));
$engineerHeaderPhotoUrl = $engineerHeaderPhotoPath !== '' ? profile_photo_public_url($engineerHeaderPhotoPath) : '';
$engineerHeaderCsrfToken = engineer_get_csrf_token();
$engineerHeaderReturnUrl = $_SERVER['REQUEST_URI'] ?? '/codesamplecaps/ENGINEER/dashboards/overview.php';
$engineerHeaderDate = date('F j, Y');
$engineerHeaderTime = date('g:i A');
?>
<header class="engineer-topbar">
    <a href="/codesamplecaps/ENGINEER/dashboards/overview.php" class="engineer-topbar__brand" aria-label="Go to Engineer overview">
        <img src="/codesamplecaps/IMAGES/edge.jpg" alt="Edge Automation logo">
        <strong>EDGE AUTOMATION</strong>
    </a>

    <div class="engineer-topbar__actions">
        <div class="engineer-topbar__profile-menu" data-engineer-profile-root>
            <button
                class="engineer-topbar__profile"
                type="button"
                title="<?php echo htmlspecialchars($engineerHeaderName, ENT_QUOTES, 'UTF-8'); ?>"
                aria-label="Open profile menu"
                aria-expanded="false"
                data-engineer-profile-toggle
            >
                <span class="engineer-topbar__profile-frame">
                    <?php if ($engineerHeaderPhotoUrl !== ''): ?>
                        <img src="<?php echo htmlspecialchars($engineerHeaderPhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile photo" class="engineer-topbar__profile-photo">
                    <?php else: ?>
                        <span><?php echo htmlspecialchars($engineerHeaderInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                    <?php endif; ?>
                </span>
                <span class="engineer-topbar__profile-arrow" aria-hidden="true">
                    <svg viewBox="0 0 20 20" focusable="false">
                        <path d="M5.5 7.5 10 12l4.5-4.5"></path>
                    </svg>
                </span>
            </button>
            <div class="engineer-profile-dropdown" data-engineer-profile-dropdown hidden>
                <div class="engineer-profile-dropdown__head">
                    <button class="engineer-profile-dropdown__avatar" type="button" data-engineer-photo-preview aria-label="View profile photo">
                        <?php if ($engineerHeaderPhotoUrl !== ''): ?>
                            <img src="<?php echo htmlspecialchars($engineerHeaderPhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile photo">
                        <?php else: ?>
                            <?php echo htmlspecialchars($engineerHeaderInitial, ENT_QUOTES, 'UTF-8'); ?>
                        <?php endif; ?>
                    </button>
                    <button class="engineer-profile-dropdown__summary" type="button" data-engineer-profile-modal-open>
                        <strong><?php echo htmlspecialchars($engineerHeaderName, ENT_QUOTES, 'UTF-8'); ?></strong>
                        <small>View Profile</small>
                    </button>
                </div>
                <a href="/codesamplecaps/LOGIN/php/logout.php" class="engineer-profile-dropdown__logout">Logout</a>
            </div>
        </div>
        <button class="engineer-topbar__bell" type="button" aria-label="Notifications">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
                <path d="M13.7 21a2 2 0 0 1-3.4 0"></path>
            </svg>
        </button>
        <div class="engineer-topbar__clock">
            <strong data-engineer-time><?php echo htmlspecialchars($engineerHeaderTime, ENT_QUOTES, 'UTF-8'); ?></strong>
            <small data-engineer-date><?php echo htmlspecialchars($engineerHeaderDate, ENT_QUOTES, 'UTF-8'); ?></small>
        </div>
    </div>
</header>

<div class="engineer-profile-modal" data-engineer-profile-modal hidden>
    <div class="engineer-profile-modal__panel" role="dialog" aria-modal="true" aria-labelledby="engineerProfileModalTitle">
        <button class="engineer-profile-modal__close" type="button" data-engineer-profile-modal-close aria-label="Close profile modal">&times;</button>
        <button class="engineer-profile-modal__avatar" type="button" data-engineer-photo-preview aria-label="View profile photo">
            <?php if ($engineerHeaderPhotoUrl !== ''): ?>
                <img src="<?php echo htmlspecialchars($engineerHeaderPhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile photo">
            <?php else: ?>
                <span><?php echo htmlspecialchars($engineerHeaderInitial, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </button>
        <p class="engineer-profile-modal__kicker">Engineer Profile</p>
        <h2 id="engineerProfileModalTitle"><?php echo htmlspecialchars($engineerHeaderName, ENT_QUOTES, 'UTF-8'); ?></h2>
        <form class="engineer-profile-edit-form" method="POST" action="/codesamplecaps/ENGINEER/actions/update_profile.php" enctype="multipart/form-data" data-engineer-profile-form>
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($engineerHeaderCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($engineerHeaderReturnUrl, ENT_QUOTES, 'UTF-8'); ?>">
            <input id="engineerProfilePhotoInput" class="engineer-profile-edit-form__file" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" data-profile-photo-input>

            <button class="engineer-profile-edit-form__photo-button" type="button" data-engineer-photo-change>Change Profile Photo</button>

            <p class="engineer-profile-edit-form__error" data-profile-form-error hidden></p>

            <button class="engineer-profile-modal__action" type="submit">Save Profile</button>
        </form>
    </div>
</div>

<div class="engineer-photo-modal" data-engineer-photo-modal hidden>
    <div class="engineer-photo-modal__shell" role="dialog" aria-modal="true" aria-label="Profile photo preview">
        <button class="engineer-photo-modal__close" type="button" data-engineer-photo-modal-close aria-label="Close photo preview">&times;</button>
        <div class="engineer-photo-modal__panel">
            <?php if ($engineerHeaderPhotoUrl !== ''): ?>
                <img src="<?php echo htmlspecialchars($engineerHeaderPhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile photo preview">
            <?php else: ?>
                <span><?php echo htmlspecialchars($engineerHeaderInitial, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </div>
        <p class="engineer-photo-modal__status" data-engineer-photo-status hidden></p>
        <button class="engineer-photo-modal__change" type="button" data-engineer-photo-change>Change Photo</button>
        <div class="engineer-photo-modal__actions" data-engineer-photo-actions hidden>
            <button class="engineer-photo-modal__save" type="button" data-engineer-photo-save>Save Photo</button>
            <button class="engineer-photo-modal__cancel" type="button" data-engineer-photo-cancel>Cancel</button>
        </div>
    </div>
</div>

<div class="engineer-confirm-modal" data-engineer-confirm-modal hidden>
    <div class="engineer-confirm-modal__panel" role="dialog" aria-modal="true" aria-label="Confirm profile changes">
        <h2>Save profile changes?</h2>
        <p>Please confirm before saving your new profile details.</p>
        <div class="engineer-confirm-modal__actions">
            <button type="button" class="engineer-confirm-modal__yes" data-engineer-confirm-yes>Yes, Save</button>
            <button type="button" class="engineer-confirm-modal__no" data-engineer-confirm-no>No</button>
        </div>
    </div>
</div>
