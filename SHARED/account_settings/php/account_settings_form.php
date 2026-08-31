<?php
function shared_account_settings_render(array $account): void
{
    $activeSection = (string)($account['section'] ?? 'profile');
    $showSecurity = $activeSection === 'security';
    ?>
    <div class="shared-account-settings" data-account-settings>
        <?php if (($account['message'] ?? '') !== ''): ?>
            <div class="account-alert account-alert--success">
                <?php echo htmlspecialchars((string)$account['message'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>
        <?php if (($account['error'] ?? '') !== ''): ?>
            <div class="account-alert account-alert--error" role="alert">
                <?php echo htmlspecialchars((string)$account['error'], ENT_QUOTES, 'UTF-8'); ?>
            </div>
        <?php endif; ?>

        <div class="account-settings-grid">
            <?php if (!$showSecurity): ?>
            <section id="profile-details" class="account-settings-card">
                <div class="account-settings-heading">
                    <h1>Profile Details</h1>
                    <p>Update your account details.</p>
                </div>

                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_my_profile">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($account['csrfToken'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="account-form-row">
                        <div class="account-form-group account-photo-field">
                            <label for="profile_photo">Profile Photo</label>
                            <div class="account-photo-upload">
                                <img
                                    src="<?php echo htmlspecialchars((string)($account['photoPreviewUrl'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    alt="Profile preview"
                                    class="account-photo-preview"
                                    data-profile-photo-preview
                                >
                                <div class="account-photo-meta">
                                    <strong>Upload profile picture</strong>
                                    <span>JPG, PNG, or WEBP only. Maximum 3MB.</span>
                                    <input type="file" id="profile_photo" name="profile_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp" data-profile-photo-input>
                                    <small data-profile-photo-state>
                                        <?php echo ($account['photoUrl'] ?? '') !== '' ? 'Current profile photo ready.' : 'Default profile photo is active.'; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="account-form-row">
                        <div class="account-form-group">
                            <label for="account_full_name">Full Name *</label>
                            <input type="text" id="account_full_name" name="full_name" value="<?php echo htmlspecialchars((string)($account['fullName'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                        <div class="account-form-group">
                            <label for="account_email">Email *</label>
                            <input type="email" id="account_email" name="email" value="<?php echo htmlspecialchars((string)($account['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" required>
                        </div>
                    </div>

                    <div class="account-form-row">
                        <div class="account-form-group">
                            <label for="account_phone">Phone Number</label>
                            <input type="tel" id="account_phone" name="phone" value="<?php echo htmlspecialchars((string)($account['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" pattern="^09[0-9]{9}$" maxlength="11" placeholder="09XXXXXXXXX" inputmode="numeric" data-account-phone>
                        </div>
                    </div>

                    <button type="submit" class="account-primary-button">Save Profile</button>
                </form>
            </section>
            <?php endif; ?>

            <?php if ($showSecurity): ?>
            <section id="security-settings" class="account-settings-card account-settings-card--security">
                <div class="account-settings-heading">
                    <h2>Security</h2>
                    <p>Change your password regularly.</p>
                </div>

                <form method="POST">
                    <input type="hidden" name="action" value="change_my_password">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars((string)($account['csrfToken'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">

                    <div class="account-form-row">
                        <div class="account-form-group">
                            <label for="current_password">Current Password *</label>
                            <div class="account-password-wrap">
                                <input type="password" id="current_password" name="current_password" required>
                                <button type="button" class="togglePassword" data-target="current_password">Show</button>
                            </div>
                        </div>
                    </div>

                    <div class="account-form-row">
                        <div class="account-form-group">
                            <label for="new_password">New Password *</label>
                            <div class="account-password-wrap">
                                <input type="password" id="new_password" name="new_password" minlength="12" required data-new-password>
                                <button type="button" class="togglePassword" data-target="new_password">Show</button>
                            </div>
                            <small class="account-password-tip">Use 12+ characters with uppercase, lowercase, number, and symbol.</small>
                            <small class="account-pass-indicator" data-password-strength>Strength: -</small>
                        </div>
                        <div class="account-form-group">
                            <label for="confirm_password">Confirm Password *</label>
                            <div class="account-password-wrap">
                                <input type="password" id="confirm_password" name="confirm_password" minlength="12" required data-confirm-password>
                                <button type="button" class="togglePassword" data-target="confirm_password">Show</button>
                            </div>
                            <small class="account-pass-indicator" data-password-match>Confirmation: -</small>
                        </div>
                    </div>

                    <button type="submit" class="account-primary-button account-primary-button--dark">Update Password</button>
                </form>
            </section>
            <?php endif; ?>
        </div>
    </div>
    <?php
}
