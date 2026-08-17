<?php
require_once __DIR__ . '/../includes/page_shell.php';

$config = Config::getInstance();
$backupItems = [
    'Database' => [
        'value' => (string)$config->get('DB_NAME', 'edge_project_asset_inventory_db'),
        'status' => 'Required',
        'tone' => 'warning',
        'checks' => [
            'Export SQL from phpMyAdmin before migrations',
            'Keep one copy outside htdocs',
            'Confirm restore file before importing',
        ],
    ],
    'Environment File' => [
        'value' => is_file(__DIR__ . '/../../.env') ? '.env file found' : '.env file missing',
        'status' => is_file(__DIR__ . '/../../.env') ? 'Protected' : 'Needs Check',
        'tone' => is_file(__DIR__ . '/../../.env') ? 'success' : 'warning',
        'checks' => [
            'Backup .env safely',
            'Never share app passwords',
            'Do not show secrets in the browser',
        ],
    ],
    'Uploaded Files' => [
        'value' => is_dir(__DIR__ . '/../../storage') ? 'storage folder found' : 'storage folder missing',
        'status' => is_dir(__DIR__ . '/../../storage') ? 'Detected' : 'Needs Check',
        'tone' => is_dir(__DIR__ . '/../../storage') ? 'success' : 'warning',
        'checks' => [
            'Backup profile photos and generated files',
            'Include uploaded attachments if added later',
            'Keep folder permissions intact',
        ],
    ],
    'System Assets' => [
        'value' => is_dir(__DIR__ . '/../../IMAGES') ? 'IMAGES folder found' : 'IMAGES folder missing',
        'status' => is_dir(__DIR__ . '/../../IMAGES') ? 'Detected' : 'Needs Check',
        'tone' => is_dir(__DIR__ . '/../../IMAGES') ? 'success' : 'warning',
        'checks' => [
            'Backup logo and local UI assets',
            'Keep offline social icons',
            'Do not replace images with external-only links',
        ],
    ],
];

$restoreRules = [
    'Restore must be done by Super Admin only',
    'Create a backup before every restore',
    'Check SQL file before importing',
    'Never restore unknown files',
    'Log every backup or restore action later',
];

superadmin_render_page(
    'Backup & Restore',
    function () use ($backupItems, $restoreRules): void {
        ?>
        <section class="dashboard-panel backup-restore-panel">
            <div class="backup-restore-header">
                <h1>Backup & Restore</h1>
                <span>Preparation Mode</span>
            </div>

            <div class="backup-warning-card">
                <strong>Restore is not enabled yet.</strong>
                <span>For capstone safety, this page shows the backup plan first. Real restore controls should be added only after validation and confirmation are ready.</span>
            </div>

            <div class="backup-grid">
                <?php foreach ($backupItems as $itemTitle => $item): ?>
                    <article class="backup-card">
                        <div class="backup-card__header">
                            <h2><?php echo htmlspecialchars($itemTitle, ENT_QUOTES, 'UTF-8'); ?></h2>
                            <span class="backup-status backup-status--<?php echo htmlspecialchars((string)$item['tone'], ENT_QUOTES, 'UTF-8'); ?>">
                                <?php echo htmlspecialchars((string)$item['status'], ENT_QUOTES, 'UTF-8'); ?>
                            </span>
                        </div>
                        <p><?php echo htmlspecialchars((string)$item['value'], ENT_QUOTES, 'UTF-8'); ?></p>
                        <ul>
                            <?php foreach ($item['checks'] as $check): ?>
                                <li><?php echo htmlspecialchars($check, ENT_QUOTES, 'UTF-8'); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </article>
                <?php endforeach; ?>
            </div>

            <article class="restore-rules-card">
                <h2>Restore Safety Rules</h2>
                <div class="restore-rule-list">
                    <?php foreach ($restoreRules as $rule): ?>
                        <div class="restore-rule-item">
                            <span aria-hidden="true">!</span>
                            <strong><?php echo htmlspecialchars($rule, ENT_QUOTES, 'UTF-8'); ?></strong>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
        </section>
        <?php
    },
    ['/codesamplecaps/SUPERADMIN/css/backup-restore.css'],
    [],
    'backup-restore-content'
);
