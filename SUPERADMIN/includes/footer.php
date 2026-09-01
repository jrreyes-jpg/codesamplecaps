<?php
if (!isset($pageScripts) || !is_array($pageScripts)) {
    $pageScripts = [];
}
?>
<script src="/codesamplecaps/assets/js/app-window-guard.js"></script>
<script src="/codesamplecaps/SHARED/header/core/operations-header.js"></script>
<script src="/codesamplecaps/SHARED/toast/js/toast.js"></script>
<script src="/codesamplecaps/SUPERADMIN/common/js/superadmin-common.js"></script>

<?php foreach ($pageScripts as $scriptPath): ?>
    <script src="<?php echo htmlspecialchars((string)$scriptPath, ENT_QUOTES, 'UTF-8'); ?>"></script>
<?php endforeach; ?>
</body>
</html>
