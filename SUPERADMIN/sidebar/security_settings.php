<?php
require_once __DIR__ . '/../includes/page_shell.php';

$config = Config::getInstance();
$mailPassword = trim((string)$config->get('MAIL_PASSWORD', ''));

$securityGroups = [
    'Login Security' => [
        [
            'label' => 'Email failed attempts',
            'value' => (int)$config->get('LOGIN_MAX_ATTEMPTS', 5) . ' attempts',
            'status' => 'Enabled',
            'tone' => 'success',
        ],
        [
            'label' => 'Device/IP failed attempts',
            'value' => (int)$config->get('LOGIN_MAX_IP_ATTEMPTS', 15) . ' attempts',
            'status' => 'Enabled',
            'tone' => 'success',
        ],
        [
            'label' => 'Lockout duration',
            'value' => (int)$config->get('LOGIN_LOCKOUT_MINUTES', 15) . ' minutes',
            'status' => 'Enabled',
            'tone' => 'success',
        ],
        [
            'label' => 'Strong password rule',
            'value' => 'Required for managed accounts',
            'status' => 'Enabled',
            'tone' => 'success',
        ],
    ],
    'Password Reset Security' => [
        [
            'label' => 'Reset code expiry',
            'value' => (int)$config->get('PASSWORD_RESET_EXPIRY_MINUTES', 15) . ' minutes',
            'status' => 'Enabled',
            'tone' => 'success',
        ],
        [
            'label' => 'Wrong code limit',
            'value' => '5 tries',
            'status' => 'Enabled',
            'tone' => 'success',
        ],
        [
            'label' => 'Email delivery',
            'value' => $mailPassword !== '' ? 'SMTP app password is set' : 'SMTP app password missing',
            'status' => $mailPassword !== '' ? 'Configured' : 'Needs Setup',
            'tone' => $mailPassword !== '' ? 'success' : 'warning',
        ],
    ],
    'Session Security' => [
        [
            'label' => 'Auto logout',
            'value' => (int)$config->get('SESSION_TIMEOUT_MINUTES', 15) . ' minutes inactive',
            'status' => 'Enabled',
            'tone' => 'success',
        ],
        [
            'label' => 'No-cache secure pages',
            'value' => 'Prevents stale dashboard pages',
            'status' => 'Enabled',
            'tone' => 'success',
        ],
        [
            'label' => 'Role-based redirect',
            'value' => 'Users go to assigned dashboard',
            'status' => 'Enabled',
            'tone' => 'success',
        ],
    ],
    'Account Protection' => [
        [
            'label' => 'User status control',
            'value' => 'Active, inactive, and trash states',
            'status' => 'Enabled',
            'tone' => 'success',
        ],
        [
            'label' => 'User management access',
            'value' => 'Super Admin only',
            'status' => 'Protected',
            'tone' => 'info',
        ],
        [
            'label' => 'Audit logging',
            'value' => 'Important actions are recorded',
            'status' => 'Enabled',
            'tone' => 'success',
        ],
    ],
];

superadmin_render_page(
    'Security Settings',
    function () use ($securityGroups): void {
        ?>
        <section class="dashboard-panel security-settings-panel">
            <div class="security-settings-header">
                <h1>Security Settings</h1>
                <span>Read-only</span>
            </div>

            <div class="security-settings-grid">
                <?php foreach ($securityGroups as $groupTitle => $items): ?>
                    <article class="security-settings-card">
                        <h2><?php echo htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
                        <div class="security-setting-list">
                            <?php foreach ($items as $item): ?>
                                <div class="security-setting-item">
                                    <div>
                                        <strong><?php echo htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <small><?php echo htmlspecialchars((string)$item['value'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    </div>
                                    <span class="security-status security-status--<?php echo htmlspecialchars((string)$item['tone'], ENT_QUOTES, 'UTF-8'); ?>">
                                        <?php echo htmlspecialchars((string)$item['status'], ENT_QUOTES, 'UTF-8'); ?>
                                    </span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
        <?php
    },
    ['/codesamplecaps/SUPERADMIN/css/security-settings.css'],
    [],
    'security-settings-content'
);
