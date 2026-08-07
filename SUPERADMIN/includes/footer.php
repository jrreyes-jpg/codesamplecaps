<?php
if (!isset($pageScripts) || !is_array($pageScripts)) {
    $pageScripts = [];
}
?>
<script src="/codesamplecaps/assets/js/app-window-guard.js"></script>
<script src="/codesamplecaps/SUPERADMIN/js/super_admin_dashboard.js"></script>
<?php foreach ($pageScripts as $scriptPath): ?>
    <script src="<?php echo htmlspecialchars((string)$scriptPath, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endforeach; ?>
</body>
</html>
