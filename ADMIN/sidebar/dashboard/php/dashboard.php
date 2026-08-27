<?php
require_once __DIR__ . '/../../../includes/admin_auth.php';
require_once __DIR__ . '/../../../../config/database.php';
require_once __DIR__ . '/../../../../config/audit_log.php';
require_once __DIR__ . '/../../../../config/project_progress.php';
require_once __DIR__ . '/../../../../config/profile_photo_storage.php';
require_once __DIR__ . '/dashboard_metrics.php';
require_once __DIR__ . '/../../../services/admin_profile.php';
require_once __DIR__ . '/dashboard_profile_actions.php';
$message = '';
$error = '';
$activeTab = $_GET['tab'] ?? 'dashboard';
if (!in_array($activeTab, ['dashboard', 'profile'], true)) {
    $activeTab = 'dashboard';
}
$action = '';

admin_ensure_user_profile_photo_column($conn);
$dashboardFlash = consumeDashboardFlash();
if ($dashboardFlash['type'] === 'success') {
    $message = $dashboardFlash['text'];
} elseif ($dashboardFlash['type'] === 'error') {
    $error = $dashboardFlash['text'];
}

function isValidPhMobile(?string $phone): bool
{
    if ($phone === null || $phone === '') {
        return true;
    }
    return (bool)preg_match('/^09\d{9}$/', $phone);
}

function isStrongPassword(string $password): bool
{
    return strlen($password) >= 12
        && preg_match('/[A-Z]/', $password)
        && preg_match('/[a-z]/', $password)
        && preg_match('/\d/', $password)
        && preg_match('/[^A-Za-z0-9]/', $password);
}

function getCsrfToken(): string
{
    return auth_csrf_token('super_admin');
}

function isValidCsrfToken(?string $token): bool
{
    return auth_is_valid_csrf($token, 'super_admin');
}

function setDashboardFlash(string $type, string $text): void
{
    $_SESSION['super_admin_dashboard_flash'] = [
        'type' => $type,
        'text' => $text,
    ];
}

function consumeDashboardFlash(): array
{
    $flash = $_SESSION['super_admin_dashboard_flash'] ?? null;
    unset($_SESSION['super_admin_dashboard_flash']);

    if (!is_array($flash)) {
        return ['type' => '', 'text' => ''];
    }

    return [
        'type' => (string)($flash['type'] ?? ''),
        'text' => (string)($flash['text'] ?? ''),
    ];
}

function redirectToDashboardTab(string $tab): void
{
    $location = '/codesamplecaps/ADMIN/sidebar/dashboard/php/dashboard.php';

    // Mas malinis na URL para sa sidebar pages ng Admin.
    if ($tab === 'dashboard') {
        $location = '/codesamplecaps/ADMIN/sidebar/dashboard/php/dashboard.php';
    } elseif ($tab === 'users' || $tab === 'create') {
        $location = '/codesamplecaps/ADMIN/sidebar/dashboard/php/dashboard.php';
    } elseif ($tab !== '') {
        $location .= '?tab=' . rawurlencode($tab);
    }

    header('Location: ' . $location);
    exit;
}


if (!function_exists('build_default_profile_avatar_data_uri')) {
    function build_default_profile_avatar_data_uri(): string
    {
        $relativePath = '/codesamplecaps/IMAGES/nodp.jpg';
        $absoluteFile = __DIR__ . '/../../../../IMAGES/nodp.jpg';

        if (is_file($absoluteFile) && is_readable($absoluteFile)) {
            return $relativePath;
        }

        $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">
  <defs>
    <linearGradient id="fbAvatarBg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#ffffff;stop-opacity:1" />
      <stop offset="100%" style="stop-color:#f0f2f5;stop-opacity:1" />
    </linearGradient>
  </defs>
  <rect width="200" height="200" fill="url(#fbAvatarBg)"/>
  <circle cx="100" cy="70" r="35" fill="#ccc"/>
  <path d="M 30 180 Q 30 140 100 140 Q 170 140 170 180 L 170 200 L 30 200 Z" fill="#ccc"/>
</svg>
SVG;

        return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
    }
}


$supportsProfilePhoto = hasColumn($conn, 'users', 'profile_photo_path');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if (!isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $error = 'Invalid request. Please try again.';
        $activeTab = in_array($action, ['update_my_profile', 'change_my_password'], true)
            ? 'profile'
            : 'dashboard';
    }

    if ($action === 'update_my_profile' && $error === '') {
        $result = admin_dashboard_handle_update_my_profile(
            $conn,
            (int)($_SESSION['user_id'] ?? 0),
            trim((string)($_POST['full_name'] ?? '')),
            trim((string)($_POST['email'] ?? '')),
            trim((string)($_POST['phone'] ?? '')),
            $_FILES['profile_photo'] ?? null,
            $supportsProfilePhoto
        );
        $error = $result['error'];
        $activeTab = $result['activeTab'];

        if ($error === '' && !empty($result['shouldRedirectToProfile'])) {
            setDashboardFlash('success', (string)($result['message'] ?? 'Your admin profile was updated.'));
            redirectToDashboardTab('profile');
        }
    }

    if ($action === 'change_my_password' && $error === '') {
        $result = admin_dashboard_handle_change_my_password(
            $conn,
            (int)($_SESSION['user_id'] ?? 0),
            (string)($_POST['current_password'] ?? ''),
            (string)($_POST['new_password'] ?? ''),
            (string)($_POST['confirm_password'] ?? '')
        );
        $error = $result['error'];
        $message = $result['message'];
        $activeTab = $result['activeTab'];
    }
}


$csrfToken = getCsrfToken();
$currentAdmin = admin_get_user_by_id($conn, (int)($_SESSION['user_id'] ?? 0));
if ($supportsProfilePhoto && $currentAdmin) {
    $currentAdmin['profile_photo_path'] = profile_photo_migrate_legacy_reference(
        $conn,
        (int)($currentAdmin['id'] ?? 0),
        $currentAdmin['profile_photo_path'] ?? null
    );
}
$currentAdminName = (string)($currentAdmin['full_name'] ?? ($_SESSION['name'] ?? 'Admin'));
$currentAdminEmail = (string)($currentAdmin['email'] ?? '');
$currentAdminPhone = (string)($currentAdmin['phone'] ?? '');
$defaultAdminPhotoUrl = build_default_profile_avatar_data_uri();
$currentAdminPhoto = trim((string)($currentAdmin['profile_photo_path'] ?? ''));
$currentAdminPhotoUrl = $currentAdminPhoto !== ''
    ? profile_photo_public_url($currentAdminPhoto)
    : '';
$currentAdminPhotoPreviewUrl = $currentAdminPhotoUrl !== '' ? $currentAdminPhotoUrl : $defaultAdminPhotoUrl;
$adminMetrics = admin_load_dashboard_metrics($conn);
foreach ($adminMetrics as $metricName => $metricValue) {
    ${$metricName} = $metricValue;
}

if (!in_array($activeTab, ['dashboard', 'profile'], true)) {
    // Admin wala nang User Management tab; balik sa dashboard para walang white screen.
    $activeTab = 'dashboard';
}
?>
<?php
$adminPageTitle = 'Admin Dashboard - Edge Automation';

$adminCssFiles = [
    '/codesamplecaps/ADMIN/common/css/admin-common.css',
    '/codesamplecaps/ADMIN/sidebar/dashboard/css/dashboard.css',
];

$adminJsFiles = [
    '/codesamplecaps/ADMIN/common/js/admin-common.js',
    '/codesamplecaps/ADMIN/sidebar/dashboard/js/dashboard.js',
];

include __DIR__ . '/../../../layout/header.php';
include __DIR__ . '/../../../admin_sidebar.php';
?>

<main class="main-content admin-dashboard-content">


    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>
    <?php
    $activeProjectCount = $ongoingProjects + $pendingProjects + $onHoldProjects;
    $activeWorkforceCount = $activeEngineerCount + $activeForemanCount + $activeClientCount;
    $pendingInquiries = $pendingInquiries ?? 0;
    $csrfToken = $csrfToken ?? '';
    ?>
    <div id="dashboard" class="tab-content <?php echo $activeTab === 'dashboard' ? 'active' : ''; ?>">
        <section class="dashboard-grid overview-dashboard" data-superadmin-overview>
            <section class="dashboard-panel summary-panel">
                <div class="panel-heading">
                    <div>
                        <h1 class="dashboard-section-title">Overview</h1>
                    </div>
                </div>
                <div class="metric-strip metric-strip-compact overview-summary-grid">
                    <a href="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?status=active" class="metric-tile metric-tile-link metric-tile-projects">
                        <span>Active Projects</span>
                        <strong data-live-metric="active_projects"><?php echo $activeProjectCount; ?></strong>
                        <small><?php echo $ongoingProjects; ?> ongoing, <?php echo $pendingProjects; ?> pending</small>
                    </a>
                    <a href="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?status=active" class="metric-tile metric-tile-link metric-tile-tasks">
                        <span>Open Tasks</span>
                        <strong data-live-metric="open_tasks"><?php echo $openTasks; ?></strong>
                        <small><?php echo $delayedTasks; ?> delayed</small>
                    </a>
                    <a href="/codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php" class="metric-tile metric-tile-link metric-tile-quotations">
                        <span>Pending Quotations</span>
                        <strong data-live-metric="pending_quotations"><?php echo $pendingQuotations; ?></strong>
                        <small>Need approval</small>
                    </a>
                    <a href="/codesamplecaps/ADMIN/sidebar/inquiries/php/inquiries.php" class="metric-tile metric-tile-link metric-tile-alerts">
                        <span>New Inquiries</span>
                        <strong data-live-metric="pending_inquiries"><?php echo $pendingInquiries ?? 0; ?></strong>
                        <small>Pending review</small>
                    </a>
                </div>
            </section>

            <section class="dashboard-panel overview-attention-panel">
                <div class="panel-heading">
                    <div>
                        <h2 class="dashboard-section-title">Needs Attention</h2>
                    </div>
                </div>
                <div class="overview-attention-grid">
                    <a href="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?status=active" class="overview-attention-card overview-attention-card--danger<?php echo $delayedTasks > 0 ? ' is-active' : ' is-clear'; ?>">
                        <span>Delayed Tasks</span>
                        <strong data-live-metric="delayed_tasks"><?php echo $delayedTasks; ?></strong>
                        <small><?php echo $totalTasks; ?> total tasks</small>
                    </a>
                    <a href="/codesamplecaps/ADMIN/sidebar/inventory/php/inventory.php" class="overview-attention-card overview-attention-card--warning<?php echo $inventoryAlertCount > 0 ? ' is-active' : ' is-clear'; ?>">
                        <span>Inventory Alerts</span>
                        <strong data-live-metric="inventory_alerts"><?php echo $inventoryAlertCount; ?></strong>
                        <small><?php echo $lowStockItems; ?> low, <?php echo $outOfStockItems; ?> out</small>
                    </a>
                    <a href="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php?status=on-hold" class="overview-attention-card overview-attention-card--neutral<?php echo $onHoldProjects > 0 ? ' is-active' : ' is-clear'; ?>">
                        <span>On-Hold Projects</span>
                        <strong data-live-metric="on_hold_projects"><?php echo $onHoldProjects; ?></strong>
                        <small>Needs follow-up</small>
                    </a>
                    <a href="/codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php" class="overview-attention-card overview-attention-card--neutral<?php echo $pendingQuotations > 0 ? ' is-active' : ' is-clear'; ?>">
                        <span>Pending Approvals</span>
                        <strong data-live-metric="pending_quotations"><?php echo $pendingQuotations; ?></strong>
                        <small>Quotation review queue</small>
                    </a>
                </div>
            </section>

            <section class="dashboard-panel activity-panel overview-activity-panel">
                <div class="panel-heading">
                    <div>
                        <h2 class="dashboard-section-title">Recent Activity</h2>
                    </div>
                    <a href="/codesamplecaps/ADMIN/sidebar/activity_history/php/activity_history.php" class="action-chip">View all</a>
                </div>
                <div class="activity-feed activity-feed-compact" data-live-activity-feed>
                    <?php if (empty($recentDashboardActivity)): ?>
                        <div class="alert-empty">No recent activity yet.</div>
                    <?php else: ?>
                        <?php foreach ($recentDashboardActivity as $activity): ?>
                            <?php $badge = preg_replace('/[^a-z0-9_-]/i', '', strtolower((string)($activity['badge'] ?? 'audit'))) ?: 'audit'; ?>
                            <article class="activity-item">
                                <span class="activity-badge activity-<?php echo htmlspecialchars($badge, ENT_QUOTES, 'UTF-8'); ?>">
                                    <?php echo htmlspecialchars(strtoupper(substr((string)($activity['badge'] ?? 'A'), 0, 1)), ENT_QUOTES, 'UTF-8'); ?>
                                </span>
                                <span class="activity-copy">
                                    <strong><?php echo htmlspecialchars((string)($activity['title'] ?? 'Activity'), ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span><?php echo htmlspecialchars((string)($activity['details'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                </span>
                                <time datetime="<?php echo htmlspecialchars((string)($activity['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>">
                                    <span class="activity-time-relative"><?php echo htmlspecialchars((string)($activity['relative_time'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
                                </time>
                            </article>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>

            <details class="dashboard-panel analytics-panel overview-analytics-details" data-overview-analytics>
                <summary class="overview-analytics-summary">
                    <span>
                        <strong>Operations Analytics</strong>
                        <small>Progress, task health, asset activity, workforce, and intake</small>
                    </span>
                    <span class="overview-analytics-summary__chevron" aria-hidden="true"></span>
                </summary>
                <div class="panel-heading">
                    <div>
                        <h2 class="dashboard-section-title">Operations Analytics</h2>
                    </div>
                </div>
                <div class="mini-overview">
                    <article class="mini-overview-card">
                        <span>Project Completion</span>
                        <strong><?php echo $projectCompletionRate; ?>%</strong>
                        <small><?php echo $completedProjects; ?> of <?php echo $totalProjects; ?> projects completed</small>
                    </article>
                    <article class="mini-overview-card">
                        <span>Task Health</span>
                        <strong><?php echo $taskDelayRate; ?>%</strong>
                        <small><?php echo $delayedTasks; ?> delayed out of <?php echo $totalTasks; ?> total tasks</small>
                    </article>
                    <article class="mini-overview-card">
                        <span>Asset Activity</span>
                        <strong data-live-metric="scans_today"><?php echo $scansToday; ?></strong>
                        <small>QR scans captured today</small>
                    </article>
                    <article class="mini-overview-card">
                        <span>Active Workforce</span>
                        <strong><?php echo $activeWorkforceCount; ?></strong>
                        <small><?php echo $activeEngineerCount; ?> engineers, <?php echo $activeForemanCount; ?> foremen, <?php echo $activeClientCount; ?> clients</small>
                    </article>
                    <article class="mini-overview-card">
                        <span>7-Day Intake</span>
                        <strong><?php echo $projectsCreatedThisWeek; ?>/<?php echo $tasksCreatedThisWeek; ?></strong>
                        <small><?php echo $projectsCreatedThisWeek; ?> projects and <?php echo $tasksCreatedThisWeek; ?> tasks created this week</small>
                    </article>
                </div>
            </details>

            <section class="overview-quick-actions" aria-label="Quick actions">
                <a href="/codesamplecaps/ADMIN/sidebar/projects/php/projects.php#create-project">Create Project</a>
                <a href="/codesamplecaps/ADMIN/sidebar/assets/php/assets.php">Add Asset</a>
                <a href="/codesamplecaps/ADMIN/sidebar/quotations/php/quotations.php">Review Quotations</a>
            </section>
        </section>
    </div>


    <div id="profile" class="tab-content <?php echo $activeTab === 'profile' ? 'active' : ''; ?>">


        <div class="profile-grid">
            <section id="profile-details" class="form-section profile-form-card">
                <div class="panel-heading">
                    <div>
                        <h2 class="dashboard-section-title">Profile Details</h2>
                        <p class="panel-copy">Update the core details shown across the admin workspace.</p>
                    </div>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="update_my_profile">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="form-row">
                        <div class="form-group profile-photo-field">
                            <label for="profile_photo">Profile Photo</label>
                            <div class="profile-photo-upload">
                                <img
                                    src="<?php echo htmlspecialchars($currentAdminPhotoPreviewUrl); ?>"
                                    alt="Admin profile preview"
                                    class="profile-photo-upload__preview"
                                    data-profile-photo-preview
                                    data-profile-photo-default="<?php echo htmlspecialchars($currentAdminPhotoPreviewUrl); ?>">
                                <div class="profile-photo-upload__meta">
                                    <strong>Upload profile picture</strong>
                                    <span>Preview only while choosing. It will save only after you click Save Profile. JPG, PNG, or WEBP only. Max 3MB.</span>
                                    <input type="file" id="profile_photo" name="profile_photo" accept=".jpg,.jpeg,.png,.webp,image/jpeg,image/png,image/webp">
                                    <small class="profile-photo-upload__state" data-profile-photo-state>
                                        <?php echo $currentAdminPhotoUrl !== '' ? 'Current profile photo ready.' : 'Default profile photo is active.'; ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="admin_full_name">Full Name *</label>
                            <input type="text" id="admin_full_name" name="full_name" value="<?php echo htmlspecialchars($currentAdminName); ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="admin_email">Email *</label>
                            <input type="email" id="admin_email" name="email" value="<?php echo htmlspecialchars($currentAdminEmail); ?>" required>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="admin_phone">Phone Number</label>
                            <input type="tel" id="admin_phone" name="phone" value="<?php echo htmlspecialchars($currentAdminPhone); ?>" pattern="^09[0-9]{9}$" maxlength="11" placeholder="09XXXXXXXXX" inputmode="numeric" data-ph-mobile-input>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary">Save Profile</button>
                </form>
            </section>

            <section id="security-settings" class="form-section profile-form-card profile-form-card--security">
                <div class="panel-heading">
                    <div>
                        <h2 class="dashboard-section-title">Security</h2>
                        <p class="panel-copy">Change your password regularly, especially on shared or office machines.</p>
                    </div>
                </div>
                <form method="POST">
                    <input type="hidden" name="action" value="change_my_password">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
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
                                <input type="password" id="new_password" name="new_password" minlength="12" required>
                                <button type="button" class="togglePassword" data-target="new_password">Show</button>
                            </div>
                            <small class="password-tip">Use 12+ characters with uppercase, lowercase, number, and symbol.</small>
                            <small id="newPasswordStrength" class="pass-indicator">Strength: -</small>
                        </div>
                        <div class="form-group password-field">
                            <label for="confirm_password">Confirm Password *</label>
                            <div class="password-input-wrap">
                                <input type="password" id="confirm_password" name="confirm_password" minlength="12" required>
                                <button type="button" class="togglePassword" data-target="confirm_password">Show</button>
                            </div>
                            <small id="confirmPasswordMatch" class="pass-indicator">Confirmation: -</small>
                        </div>
                    </div>
                    <button type="submit" class="btn-primary btn-primary--dark">Update Password</button>
                </form>
            </section>
        </div>
    </div>
</main>
<?php include __DIR__ . '/../../../layout/footer.php'; ?>
