<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/page_shell.php';
require_once __DIR__ . '/../includes/account_settings_actions.php';
require_once __DIR__ . '/../../SHARED/account_settings/php/account_settings_form.php';

require_role('inventory_clerk');

$account = inventory_clerk_account_context($conn);
$activeSection = (string)($account['section'] ?? 'profile');

inventory_clerk_render_page(
    'My Profile - Inventory Clerk',
    function () use ($account, $activeSection): void {
        ?>
        <section class="inventory-clerk-profile-page" aria-labelledby="inventory-clerk-profile-title">
            <div class="inventory-clerk-profile-page__heading">
                <div>
                    <p class="inventory-clerk-profile-page__eyebrow">MY ACCOUNT</p>
                    <h1 id="inventory-clerk-profile-title">My Profile</h1>
                    <p>Update your own account details and password.</p>
                </div>
                <span class="inventory-clerk-profile-page__role">Inventory Clerk</span>
            </div>

            <nav class="inventory-clerk-profile-page__tabs" aria-label="Profile sections">
                <a class="inventory-clerk-profile-page__tab<?php echo $activeSection === 'profile' ? ' is-active' : ''; ?>" href="/codesamplecaps/INVENTORY_CLERK/dashboards/profile.php?section=profile">Profile Details</a>
                <a class="inventory-clerk-profile-page__tab<?php echo $activeSection === 'security' ? ' is-active' : ''; ?>" href="/codesamplecaps/INVENTORY_CLERK/dashboards/profile.php?section=security">Change Password</a>
            </nav>

            <?php shared_account_settings_render($account); ?>
        </section>
        <?php
    },
    [
        '/codesamplecaps/SHARED/account_settings/css/account-settings.css',
        '/codesamplecaps/INVENTORY_CLERK/css/profile.css',
    ],
    'inventory-clerk-profile-content',
    ['/codesamplecaps/SHARED/account_settings/js/account-settings.js']
);
