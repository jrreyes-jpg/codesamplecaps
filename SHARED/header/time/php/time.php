<?php
/*
|--------------------------------------------------------------------------
| Header Time Partial
|--------------------------------------------------------------------------
| Shared clock markup. The role can pass class names and data attributes.
|--------------------------------------------------------------------------
*/

$operationsHeaderClockClass = trim((string)($operationsHeaderClockClass ?? 'operations-topbar__clock'));
$operationsHeaderTime = (string)($operationsHeaderTime ?? date('g:i A'));
$operationsHeaderDate = (string)($operationsHeaderDate ?? date('F j, Y'));
$operationsHeaderTimeAttr = (string)($operationsHeaderTimeAttr ?? 'data-operations-time');
$operationsHeaderDateAttr = (string)($operationsHeaderDateAttr ?? 'data-operations-date');
?>
<div class="<?php echo htmlspecialchars($operationsHeaderClockClass, ENT_QUOTES, 'UTF-8'); ?> operations-topbar__clock">
    <strong <?php echo $operationsHeaderTimeAttr; ?>><?php echo htmlspecialchars($operationsHeaderTime, ENT_QUOTES, 'UTF-8'); ?></strong>
    <small <?php echo $operationsHeaderDateAttr; ?>><?php echo htmlspecialchars($operationsHeaderDate, ENT_QUOTES, 'UTF-8'); ?></small>
</div>
