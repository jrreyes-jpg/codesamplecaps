<?php
// Header ng Admin layout. Dito lang ilalagay ang common CSS, title, at opening HTML.
$adminPageTitle = $adminPageTitle ?? 'Admin Dashboard - Edge Automation';
$adminCssFiles = $adminCssFiles ?? [
    '/codesamplecaps/ADMIN/css/super_admin_dashboard.css',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($adminPageTitle, ENT_QUOTES, 'UTF-8'); ?></title>
    <?php foreach ($adminCssFiles as $cssFile): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars($cssFile, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endforeach; ?>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="icon" type="image/x-icon" href="/codesamplecaps/IMAGES/edge.jpg">
</head>
<body>
<div class="container">
