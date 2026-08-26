<?php
/*
|--------------------------------------------------------------------------
| Shared Operations Header
|--------------------------------------------------------------------------
| Common topbar shell for operational roles. Role-specific pages pass the
| profile/notification actions as HTML so business logic stays outside.
|--------------------------------------------------------------------------
*/

$operationsHeaderRole = preg_replace('/[^a-z0-9_-]/', '', strtolower((string)($operationsHeaderRole ?? 'operations')));
$operationsHeaderClass = trim((string)($operationsHeaderClass ?? $operationsHeaderRole . '-topbar'));
$operationsHeaderBrandClass = trim((string)($operationsHeaderBrandClass ?? $operationsHeaderClass . '__brand'));
$operationsHeaderActionsClass = trim((string)($operationsHeaderActionsClass ?? $operationsHeaderClass . '__actions'));
$operationsHeaderClockClass = trim((string)($operationsHeaderClockClass ?? $operationsHeaderClass . '__clock'));
$operationsHeaderHomeHref = (string)($operationsHeaderHomeHref ?? '/codesamplecaps/LOGIN/php/index.php');
$operationsHeaderBrandText = (string)($operationsHeaderBrandText ?? 'EDGE AUTOMATION');
$operationsHeaderLogo = (string)($operationsHeaderLogo ?? '/codesamplecaps/IMAGES/edge.jpg');
$operationsHeaderLogoClass = trim((string)($operationsHeaderLogoClass ?? 'operations-topbar__brand-logo'));
$operationsHeaderBrandLabel = (string)($operationsHeaderBrandLabel ?? 'Go to dashboard');
$operationsHeaderActionsHtml = (string)($operationsHeaderActionsHtml ?? '');
$operationsHeaderTime = (string)($operationsHeaderTime ?? date('g:i A'));
$operationsHeaderDate = (string)($operationsHeaderDate ?? date('F j, Y'));
$operationsHeaderTimeAttr = (string)($operationsHeaderTimeAttr ?? 'data-operations-time');
$operationsHeaderDateAttr = (string)($operationsHeaderDateAttr ?? 'data-operations-date');
$operationsHeaderAttrs = trim((string)($operationsHeaderAttrs ?? ''));
?>
<header class="<?php echo htmlspecialchars($operationsHeaderClass, ENT_QUOTES, 'UTF-8'); ?> operations-topbar operations-topbar--<?php echo htmlspecialchars($operationsHeaderRole, ENT_QUOTES, 'UTF-8'); ?>"<?php echo $operationsHeaderAttrs !== '' ? ' ' . $operationsHeaderAttrs : ''; ?>>
    <a href="<?php echo htmlspecialchars($operationsHeaderHomeHref, ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo htmlspecialchars($operationsHeaderBrandClass, ENT_QUOTES, 'UTF-8'); ?> operations-topbar__brand" aria-label="<?php echo htmlspecialchars($operationsHeaderBrandLabel, ENT_QUOTES, 'UTF-8'); ?>">
        <img src="<?php echo htmlspecialchars($operationsHeaderLogo, ENT_QUOTES, 'UTF-8'); ?>" alt="Edge Automation logo" class="<?php echo htmlspecialchars($operationsHeaderLogoClass, ENT_QUOTES, 'UTF-8'); ?>">
        <strong><?php echo htmlspecialchars($operationsHeaderBrandText, ENT_QUOTES, 'UTF-8'); ?></strong>
    </a>

    <div class="<?php echo htmlspecialchars($operationsHeaderActionsClass, ENT_QUOTES, 'UTF-8'); ?> operations-topbar__actions">
        <?php echo $operationsHeaderActionsHtml; ?>
        <?php include __DIR__ . '/../time/php/time.php'; ?>
    </div>
</header>
