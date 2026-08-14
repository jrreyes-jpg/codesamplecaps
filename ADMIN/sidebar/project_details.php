<?php
// Lumang path support: ituro sa bagong project details page para hindi masira old links.
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/codesamplecaps/ADMIN/sidebar/projects/project_details.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 302);
exit();
