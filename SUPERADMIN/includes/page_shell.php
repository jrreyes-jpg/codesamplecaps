<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';

require_role('super_admin');

function superadmin_render_page(
    string $title,
    callable $contentRenderer,
    array $pageStyles = [],
    array $pageScripts = [],
    string $mainClass = ''
): void
{
    // Isang layout lang para pare-pareho ang header, sidebar, footer, CSS, at JS.
    $pageTitle = $title;
    $mainClassAttr = trim('main-content ' . $mainClass);

    require __DIR__ . '/header.php';
    ?>
<div class="container">
    <?php include __DIR__ . '/../super_admin_sidebar.php'; ?>
    <main class="<?php echo htmlspecialchars($mainClassAttr, ENT_QUOTES, 'UTF-8'); ?>">
        <?php $contentRenderer(); ?>
    </main>
</div>
    <?php
    require __DIR__ . '/footer.php';
}

function superadmin_render_simple_page(string $title, string $copy): void
{
    superadmin_render_page($title, function () use ($title, $copy): void {
        ?>
        <section class="dashboard-panel">
            <div class="panel-heading">
                <div>
                    <h1 class="dashboard-section-title"><?php echo htmlspecialchars($title, ENT_QUOTES, 'UTF-8'); ?></h1>
                    <p class="panel-copy"><?php echo htmlspecialchars($copy, ENT_QUOTES, 'UTF-8'); ?></p>
                </div>
            </div>
        </section>
        <?php
    });
}

