<?php
require_once __DIR__ . '/../includes/page_shell.php';

$config = Config::getInstance();
$mailPassword = trim((string)$config->get('MAIL_PASSWORD', ''));
$dbConnectionOk = isset($conn) && $conn instanceof mysqli && $conn->ping();

$systemGroups = [
    'Application' => [
        ['label' => 'App name', 'value' => (string)$config->get('APP_NAME', 'Edge Automation'), 'status' => 'Active', 'tone' => 'success'],
        ['label' => 'App URL', 'value' => (string)$config->get('APP_URL', 'Not set'), 'status' => 'Set', 'tone' => 'info'],
        ['label' => 'Timezone', 'value' => (string)$config->get('APP_TIMEZONE', 'Asia/Manila'), 'status' => 'Set', 'tone' => 'info'],
    ],
    'Database' => [
        ['label' => 'Host', 'value' => (string)$config->get('DB_HOST', 'Not set'), 'status' => 'Set', 'tone' => 'info'],
        ['label' => 'Database name', 'value' => (string)$config->get('DB_NAME', 'Not set'), 'status' => 'Set', 'tone' => 'info'],
        ['label' => 'Charset', 'value' => (string)$config->get('DB_CHARSET', 'utf8mb4'), 'status' => 'Set', 'tone' => 'info'],
        ['label' => 'Connection', 'value' => $dbConnectionOk ? 'MySQL is reachable' : 'MySQL needs checking', 'status' => $dbConnectionOk ? 'Connected' : 'Needs Check', 'tone' => $dbConnectionOk ? 'success' : 'warning'],
    ],
    'Email Service' => [
        ['label' => 'SMTP host', 'value' => (string)$config->get('MAIL_HOST', 'Not set'), 'status' => 'Set', 'tone' => 'info'],
        ['label' => 'SMTP port', 'value' => (string)$config->get('MAIL_PORT', 'Not set'), 'status' => 'Set', 'tone' => 'info'],
        ['label' => 'From address', 'value' => (string)$config->get('MAIL_FROM_ADDRESS', 'Not set'), 'status' => 'Set', 'tone' => 'info'],
        ['label' => 'SMTP password', 'value' => $mailPassword !== '' ? 'Stored in environment' : 'Missing in environment', 'status' => $mailPassword !== '' ? 'Configured' : 'Needs Setup', 'tone' => $mailPassword !== '' ? 'success' : 'warning'],
    ],
    'Environment' => [
        ['label' => 'PHP version', 'value' => PHP_VERSION, 'status' => 'Detected', 'tone' => 'success'],
        ['label' => 'Server software', 'value' => (string)($_SERVER['SERVER_SOFTWARE'] ?? 'Local server'), 'status' => 'Detected', 'tone' => 'success'],
        ['label' => 'Runtime mode', 'value' => 'Local XAMPP / Capstone setup', 'status' => 'Local', 'tone' => 'info'],
    ],
    'Maintenance' => [
        ['label' => 'Audit logs', 'value' => 'System actions are tracked', 'status' => 'Enabled', 'tone' => 'success'],
        ['label' => 'Backup reminder', 'value' => 'Create DB backup before risky changes', 'status' => 'Recommended', 'tone' => 'warning'],
        ['label' => 'Secrets display', 'value' => 'Passwords and app keys are hidden', 'status' => 'Protected', 'tone' => 'success'],
    ],
];

superadmin_render_page(
    'System Overview',
    function () use ($systemGroups): void {
        ?>
        <section class="dashboard-panel system-settings-panel">
            <div class="system-settings-header">
                <h1>System Overview</h1>
                <span>Read-only</span>
            </div>

            <div class="system-settings-grid">
                <?php foreach ($systemGroups as $groupTitle => $items): ?>
                    <article class="system-settings-card">
                        <h2><?php echo htmlspecialchars($groupTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
                        <div class="system-setting-list">
                            <?php foreach ($items as $item): ?>
                                <div class="system-setting-item">
                                    <div>
                                        <strong><?php echo htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                        <small><?php echo htmlspecialchars((string)$item['value'], ENT_QUOTES, 'UTF-8'); ?></small>
                                    </div>
                                    <span class="system-status system-status--<?php echo htmlspecialchars((string)$item['tone'], ENT_QUOTES, 'UTF-8'); ?>">
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
    ['/codesamplecaps/SUPERADMIN/css/system-settings.css'],
    [],
    'system-settings-content'
);
