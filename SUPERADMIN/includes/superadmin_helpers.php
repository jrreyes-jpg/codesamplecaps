<?php
require_once __DIR__ . '/../../config/profile_photo_storage.php';

if (!function_exists('build_default_profile_avatar_data_uri')) {
function build_default_profile_avatar_data_uri(): string {
    $relativePath = '/codesamplecaps/IMAGES/nodp.jpg';
    $absoluteFile = __DIR__ . '/../../IMAGES/nodp.jpg';

    if (is_file($absoluteFile) && is_readable($absoluteFile)) {
        return $relativePath;
    }

    $svg = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200">
  <defs>
    <linearGradient id="avatarBg" x1="0%" y1="0%" x2="100%" y2="100%">
      <stop offset="0%" style="stop-color:#ffffff;stop-opacity:1" />
      <stop offset="100%" style="stop-color:#f0f2f5;stop-opacity:1" />
    </linearGradient>
  </defs>
  <rect width="200" height="200" fill="url(#avatarBg)"/>
  <circle cx="100" cy="70" r="35" fill="#ccc"/>
  <path d="M 30 180 Q 30 140 100 140 Q 170 140 170 180 L 170 200 L 30 200 Z" fill="#ccc"/>
</svg>
SVG;

    return 'data:image/svg+xml;utf8,' . rawurlencode($svg);
}
}

function super_admin_sidebar_table_exists(mysqli $conn, string $tableName): bool {
    static $tableCache = [];
    $cacheKey = $conn->thread_id . ':' . $tableName;

    if (array_key_exists($cacheKey, $tableCache)) {
        return $tableCache[$cacheKey];
    }

    $stmt = $conn->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $tableName);
    $stmt->execute();
    $result = $stmt->get_result();

    $tableCache[$cacheKey] = (bool)($result && $result->fetch_assoc());
    return $tableCache[$cacheKey];
}

function super_admin_sidebar_column_exists(mysqli $conn, string $tableName, string $columnName): bool {
    static $columnCache = [];
    $cacheKey = $conn->thread_id . ':' . $tableName . ':' . $columnName;

    if (array_key_exists($cacheKey, $columnCache)) {
        return $columnCache[$cacheKey];
    }

    $stmt = $conn->prepare(
        'SELECT 1
         FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
         AND TABLE_NAME = ?
         AND COLUMN_NAME = ?
         LIMIT 1'
    );

    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $tableName, $columnName);
    $stmt->execute();
    $result = $stmt->get_result();

    $columnCache[$cacheKey] = (bool)($result && $result->fetch_assoc());
    return $columnCache[$cacheKey];
}

function super_admin_profile_initials(string $name): string {
    $name = trim($name);
    if ($name === '') {
        return 'SA';
    }

    $parts = preg_split('/\s+/', $name) ?: [];
    $initials = '';

    foreach ($parts as $part) {
        if ($part === '') {
            continue;
        }

        $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) {
            break;
        }
    }

    return $initials !== '' ? $initials : 'SA';
}
