<?php
if (!isset($pageTitle)) {
    $pageTitle = 'Super Admin';
}

if (!isset($pageStyles) || !is_array($pageStyles)) {
    $pageStyles = [];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8'); ?> - Super Admin</title>
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/base.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/sidebar/css/sidebar.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/header.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/notifications.css">
    <link rel="stylesheet" href="/codesamplecaps/SUPERADMIN/common/css/admin-common.css">
    <link rel="stylesheet" href="/codesamplecaps/SHARED/admin_ui/css/layout.css">
    <link rel="stylesheet" href="/codesamplecaps/SUPERADMIN/css/footer.css">
    <link rel="stylesheet" href="/codesamplecaps/assets/css/responsive-foundation.css">
    <?php foreach ($pageStyles as $stylePath): ?>
        <link rel="stylesheet" href="<?php echo htmlspecialchars((string)$stylePath, ENT_QUOTES, 'UTF-8'); ?>">
    <?php endforeach; ?>
    <link rel="icon" type="image/x-icon" href="/codesamplecaps/IMAGES/edge.jpg">
</head>
<body>
