<?php
// Lumang path support: ituro sa bagong projects folder para hindi masira old links.
$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/codesamplecaps/ADMIN/sidebar/projects/projects.php' . ($query !== '' ? '?' . $query : '');
header('Location: ' . $target, true, 302);
exit();
