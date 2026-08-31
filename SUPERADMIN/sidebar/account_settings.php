<?php
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../includes/account_settings_actions.php';

$account = superadmin_account_context($conn);

superadmin_render_page(
    'Account Settings',
    function () use ($account): void {
        ?>
        <?php if ($account['message'] !== ''): ?>
            <div class="alert alert-success"><?php echo htmlspecialchars($account['message'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($account['error'] !== ''): ?>
            <div class="alert alert-error"><?php echo htmlspecialchars($account['error'], ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="profile-grid">
            <section id="profile-details" class="form-section profile-form-card">
                <div class="panel-heading">
                    <div>
                        <h1 class="dashboard-section-title">Profile Details</h1>
                        <p class="panel-copy">Update your Super Admin account details.</p>
                    </div>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_my_profile">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($account['csrfToken'], ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-row">
                        <div class="form-group profile-photo-field">
                            <label for="profile_photo">Profile Photo</label>
                            <div class="profile-photo-upload">
                                <img
                                    src="<?php echo htmlspecialchars($account['photoPreviewUrl'], ENT_QUOTES, 'UTF-8'); ?>"
                                    alt="Super Admin profile preview"
                                    class="profile-photo-upload__preview"
                                    data-profile-photo-preview
                                >
                                <div class="profile-photo-upload__meta">
                                    <strong>Upload profile picture</strong>
                                    <span>JPG, PNG, or WEBP only. Maximum 3MB.</span>
                                    <input type="file" id="profile_photo" name="profile_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-profile-photo-input>
                                    <small data-profile-photo-state>
                                        <?php echo $account['photoUrl'] !== '' ? 'Current profile photo ready.' : 'Default profile photo is active.'; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="admin_full_name">Full Name *</label>
                            <input type="text" id="admin_full_name" name="full_name" value="<?php echo htmlspecialchars($account['fullName'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="admin_email">Email *</label>
                            <input type="email" id="admin_email" name="email" value="<?php echo htmlspecialchars($account['email'], ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="admin_phone">Phone Number</label>
                            <input type="tel" id="admin_phone" name="phone" value="<?php echo htmlspecialchars($account['phone'], ENT_QUOTES, 'UTF-8'); ?>" pattern="^09[0-9]{9}$" maxlength="11" placeholder="09XXXXXXXXX" inputmode="numeric" data-account-phone>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary">Save Profile</button>
                </form>
            </section>

            <section id="security-settings" class="form-section profile-form-card profile-form-card--security">
                <div class="panel-heading">
                    <div>
                        <h2 class="dashboard-section-title">Security</h2>
                        <p class="panel-copy">Change your password regularly.</p>
                    </div>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="change_my_password">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($account['csrfToken'], ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="form-row">
                        <div class="form-group password-field">
                            <label for="current_password">Current Password *</label>
                            <div class="password-input-wrap">
                                <input type="password" id="current_password" name="current_password" required>
                                <button type="button" class="togglePassword" data-target="current_password">Show</button>
                            </div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group password-field">
                            <label for="new_password">New Password *</label>
                            <div class="password-input-wrap">
                                <input type="password" id="new_password" name="new_password" minlength="12" required data-new-password>
                                <button type="button" class="togglePassword" data-target="new_password">Show</button>
                            </div>
                            <small class="password-tip">Use 12+ characters with uppercase, lowercase, number, and symbol.</small>
                            <small class="pass-indicator" data-password-strength>Strength: -</small>
                        </div>
                        <div class="form-group password-field">
                            <label for="confirm_password">Confirm Password *</label>
                            <div class="password-input-wrap">
                                <input type="password" id="confirm_password" name="confirm_password" minlength="12" required data-confirm-password>
                                <button type="button" class="togglePassword" data-target="confirm_password">Show</button>
                            </div>
                            <small class="pass-indicator" data-password-match>Confirmation: -</small>
                        </div>
                    </div>

                    <button type="submit" class="btn-primary btn-primary--dark">Update Password</button>
                </form>
            </section>
        </div>
        <?php
    },
    [
        '/codesamplecaps/SUPERADMIN/css/account-settings.css',
        '/codesamplecaps/SUPERADMIN/css/security-settings.css',
    ],
    ['/codesamplecaps/SHARED/account_settings/js/account-settings.js'],
    'account-settings-content'
);
