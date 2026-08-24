<?php
require_once __DIR__ . '/engineer_helpers.php';

$engineerHeaderName = (string)($_SESSION['name'] ?? 'Engineer');
$engineerHeaderPhotoPath = '';
$engineerHeaderEmail = '';
$engineerHeaderPhone = '';

if (isset($conn) && $conn instanceof mysqli) {
    engineer_ensure_profile_columns($conn);
    $engineerHeaderUserId = (int)($_SESSION['user_id'] ?? 0);
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
$engineerHeaderReturnUrl = $_SERVER['REQUEST_URI'] ?? '/codesamplecaps/ENGINEER/dashboards/overview.php';
$engineerHeaderDate = date('F j, Y');
$engineerHeaderTime = date('g:i A');
ob_start();
?>
<div class="topbar-profile engineer-topbar__profile-menu" data-engineer-profile-root>
    <button
        class="topbar-profile__toggle engineer-topbar__profile"
        type="button"
        title="<?php echo htmlspecialchars($engineerHeaderName, ENT_QUOTES, 'UTF-8'); ?>"
        aria-label="Open profile menu"
        aria-expanded="false"
        data-engineer-profile-toggle
    >
        <span class="topbar-profile__avatar-shell engineer-topbar__profile-frame">
            <?php if ($engineerHeaderPhotoUrl !== ''): ?>
                <img src="<?php echo htmlspecialchars($engineerHeaderPhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile photo" class="topbar-profile__avatar-image engineer-topbar__profile-photo">
            <?php else: ?>
                <span class="topbar-profile__avatar-fallback"><?php echo htmlspecialchars($engineerHeaderInitial, ENT_QUOTES, 'UTF-8'); ?></span>
            <?php endif; ?>
        </span>
        <span class="topbar-profile__chevron-badge engineer-topbar__profile-arrow" aria-hidden="true">
            <span class="topbar-profile__chevron">
            <svg viewBox="0 0 20 20" focusable="false">
                <path d="M5.5 7.5 10 12l4.5-4.5"></path>
            </svg>
            </span>
        </span>
    </button>
    <div class="topbar-profile__dropdown engineer-profile-dropdown" data-engineer-profile-dropdown hidden>
        <div class="topbar-profile__panel-head engineer-profile-dropdown__head">
            <button class="topbar-profile__avatar-shell topbar-profile__avatar-shell--panel engineer-profile-dropdown__avatar" type="button" data-engineer-photo-preview aria-label="View profile photo">
                <?php if ($engineerHeaderPhotoUrl !== ''): ?>
                    <img src="<?php echo htmlspecialchars($engineerHeaderPhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile photo" class="topbar-profile__avatar-image topbar-profile__avatar-image--panel">
                <?php else: ?>
                    <span class="topbar-profile__avatar-fallback topbar-profile__avatar-fallback--panel"><?php echo htmlspecialchars($engineerHeaderInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                <?php endif; ?>
            </button>
            <button class="engineer-profile-dropdown__summary" type="button" data-engineer-profile-modal-open>
                <strong><?php echo htmlspecialchars($engineerHeaderName, ENT_QUOTES, 'UTF-8'); ?></strong>
                <small>View Profile</small>
            </button>
        </div>
        <div class="topbar-profile__links">
            <button type="button" data-engineer-profile-modal-open>Profile</button>
            <a href="/codesamplecaps/LOGIN/php/logout.php" class="engineer-profile-dropdown__logout">Logout</a>
        </div>
    </div>
</div>
<div class="topbar-notifications">
    <button class="topbar-notifications__toggle engineer-topbar__bell" type="button" aria-label="Notifications">
        <span class="topbar-notifications__icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false">
                <path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path>
                <path d="M13.7 21a2 2 0 0 1-3.4 0"></path>
            </svg>
        </span>
    </button>
</div>
<?php
$operationsHeaderActionsHtml = ob_get_clean();
$operationsHeaderRole = 'engineer';
$operationsHeaderClass = 'engineer-topbar';
$operationsHeaderBrandClass = 'engineer-topbar__brand';
$operationsHeaderActionsClass = 'engineer-topbar__actions';
$operationsHeaderClockClass = 'engineer-topbar__clock';
$operationsHeaderHomeHref = '/codesamplecaps/ENGINEER/dashboards/overview.php';
$operationsHeaderBrandText = 'EDGE AUTOMATION';
$operationsHeaderBrandLabel = 'Go to Engineer overview';
$operationsHeaderTime = $engineerHeaderTime;
$operationsHeaderDate = $engineerHeaderDate;
$operationsHeaderTimeAttr = 'data-engineer-time';
$operationsHeaderDateAttr = 'data-engineer-date';
include __DIR__ . '/../../SHARED/layout/operations_header.php';
?>

<div class="engineer-profile-modal" data-engineer-profile-modal hidden>
    <div class="engineer-profile-modal__panel" role="dialog" aria-modal="true" aria-labelledby="engineerProfileModalTitle">
        <button class="engineer-profile-modal__close" type="button" data-engineer-profile-modal-close aria-label="Close profile modal">&times;</button>
        <p class="engineer-profile-modal__kicker">Engineer Profile</p>
        <h2 id="engineerProfileModalTitle"><?php echo htmlspecialchars($engineerHeaderName, ENT_QUOTES, 'UTF-8'); ?></h2>
        <div class="engineer-profile-modal__content">
            <form class="engineer-profile-edit-form" method="POST" action="/codesamplecaps/ENGINEER/actions/update_profile.php" enctype="multipart/form-data" data-engineer-profile-form>
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($engineerHeaderCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="return_url" value="<?php echo htmlspecialchars($engineerHeaderReturnUrl, ENT_QUOTES, 'UTF-8'); ?>">
                <input id="engineerProfilePhotoInput" class="engineer-profile-edit-form__file" type="file" name="profile_photo" accept="image/jpeg,image/png,image/webp" data-profile-photo-input>

                <section class="engineer-profile-settings-card engineer-profile-settings-card--identity">
                    <div class="engineer-profile-settings-card__head">
                        <h3>Profile Details</h3>
                    </div>
                    <div class="engineer-profile-photo-upload">
                        <button class="engineer-profile-modal__avatar" type="button" data-engineer-photo-preview aria-label="View profile photo">
                            <?php if ($engineerHeaderPhotoUrl !== ''): ?>
                                <img src="<?php echo htmlspecialchars($engineerHeaderPhotoUrl, ENT_QUOTES, 'UTF-8'); ?>" alt="Profile photo">
                            <?php else: ?>
                                <span><?php echo htmlspecialchars($engineerHeaderInitial, ENT_QUOTES, 'UTF-8'); ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="engineer-profile-photo-upload__meta">
                            <strong>Profile picture</strong>
                            <span>JPG, PNG, or WEBP only. Max 3MB.</span>
                            <button class="engineer-profile-edit-form__photo-button" type="button" data-engineer-photo-change>Change Photo</button>
                        </div>
                    </div>
                    <div class="engineer-profile-readonly">
                        <div>
                            <span>Full Name</span>
                            <strong><?php echo htmlspecialchars($engineerHeaderName, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <div>
                            <span>Email</span>
                            <strong><?php echo htmlspecialchars($engineerHeaderEmail !== '' ? $engineerHeaderEmail : 'Not set', ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                        <div>
                            <span>Phone</span>
                            <strong><?php echo htmlspecialchars($engineerHeaderPhone !== '' ? $engineerHeaderPhone : 'Not set', ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                    </div>
                    <p class="engineer-profile-readonly__note">Contact Super Admin to update email, phone, name, or official account details.</p>
                    <a
                        class="engineer-profile-contact-link"
                        href="mailto:ejimenez.edge@gmail.com?subject=Account%20Update%20Request&body=Hello%20Super%20Admin,%0A%0AI%20would%20like%20to%20request%20an%20account%20update.%0A%0AName:%20[Your%20name]%0AEmail:%20[Your%20email]%0ARequested%20change:%20[Email%20/%20Phone%20/%20Name%20/%20Other]%0ANew%20details:%20[Type%20the%20correct%20details%20here]%0AReason:%20[Why%20this%20needs%20to%20be%20updated]%0A%0AThank%20you."
                    >
                        Contact Super Admin
                    </a>
                </section>

                <p class="engineer-profile-edit-form__error" data-profile-form-error hidden></p>
            </form>

            <section class="engineer-profile-settings-card engineer-profile-settings-card--security">
                <div class="engineer-profile-settings-card__head">
                    <h3>Account Security</h3>
                </div>
                <div class="engineer-password-panel" data-engineer-password-panel>
                    <button class="engineer-password-panel__start" type="button" data-engineer-password-start>Change Password</button>
                    <div class="engineer-password-panel__form" data-engineer-password-form hidden>
                        <p class="engineer-password-panel__status" data-engineer-password-status hidden></p>
                        <button class="engineer-password-panel__send" type="button" data-engineer-password-send>Send Email Code</button>
                        <label>
                            <span>6-digit Code</span>
                            <input type="text" inputmode="numeric" maxlength="6" autocomplete="one-time-code" data-engineer-password-otp>
                        </label>
                        <label>
                            <span>New Password</span>
                            <input type="password" autocomplete="new-password" data-engineer-new-password>
                        </label>
                        <div class="engineer-password-strength" data-engineer-password-strength>
                            <span data-rule="length">8+ chars</span>
                            <span data-rule="upper">Uppercase</span>
                            <span data-rule="lower">Lowercase</span>
                            <span data-rule="number">Number</span>
                            <span data-rule="symbol">Symbol</span>
                        </div>
                        <label>
                            <span>Confirm Password</span>
                            <input type="password" autocomplete="new-password" data-engineer-confirm-password>
                        </label>
                        <button class="engineer-password-panel__save" type="button" data-engineer-password-save>Save New Password</button>
                    </div>
                </div>
            </section>
        </div>
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
        <h2>Save profile photo?</h2>
        <p>Please confirm before saving your new profile picture.</p>
        <div class="engineer-confirm-modal__actions">
            <button type="button" class="engineer-confirm-modal__yes" data-engineer-confirm-yes>Yes, Save</button>
            <button type="button" class="engineer-confirm-modal__no" data-engineer-confirm-no>No</button>
        </div>
    </div>
</div>
