<?php

function inspection_evidence_table_exists(mysqli $conn): bool
{
    $result = $conn->query("SHOW TABLES LIKE 'site_inspection_evidence'");
    return $result instanceof mysqli_result && $result->num_rows > 0;
}

function inspection_evidence_storage_directory(): string
{
    return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'inspection_evidence';
}

function inspection_evidence_public_path(string $fileName): string
{
    return '/codesamplecaps/storage/inspection_evidence/' . rawurlencode(basename($fileName));
}

function inspection_evidence_drive_url(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (!filter_var($value, FILTER_VALIDATE_URL)) {
        return null;
    }

    $parts = parse_url($value);
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host = strtolower((string)($parts['host'] ?? ''));
    if ($scheme !== 'https' || $host !== 'drive.google.com') {
        return null;
    }

    $path = (string)($parts['path'] ?? '');
    parse_str((string)($parts['query'] ?? ''), $query);
    $hasSharedFile = preg_match('#^/file/d/[A-Za-z0-9_-]+(?:/|$)#', $path) === 1;
    $hasSharedFolder = preg_match('#^/drive/(?:u/\d+/)?folders/[A-Za-z0-9_-]+(?:/|$)#', $path) === 1;
    $hasSharedId = in_array($path, ['/open', '/uc'], true)
        && preg_match('/^[A-Za-z0-9_-]+$/', (string)($query['id'] ?? '')) === 1;
    if (!$hasSharedFile && !$hasSharedFolder && !$hasSharedId) {
        return null;
    }

    return $value;
}

function inspection_evidence_uploaded_files(array $files): array
{
    $normalized = [];
    $names = is_array($files['name'] ?? null) ? $files['name'] : [];
    foreach ($names as $index => $name) {
        $normalized[] = [
            'name' => (string)$name,
            'type' => (string)($files['type'][$index] ?? ''),
            'tmp_name' => (string)($files['tmp_name'][$index] ?? ''),
            'error' => (int)($files['error'][$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($files['size'][$index] ?? 0),
        ];
    }
    return $normalized;
}

function inspection_evidence_validate_photo(array $file): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['error' => 'One photo could not be uploaded. Please try again.'];
    }

    $tmpName = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmpName === '' || !is_uploaded_file($tmpName)) {
        return ['error' => 'Invalid inspection photo upload.'];
    }
    if ($size < 1 || $size > 2 * 1024 * 1024) {
        return ['error' => 'Each inspection photo must be 2 MB or smaller.'];
    }

    $imageInfo = @getimagesize($tmpName);
    $mime = strtolower((string)($imageInfo['mime'] ?? ''));
    $extensions = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];
    if (!isset($extensions[$mime])) {
        return ['error' => 'Use JPG, PNG, or WEBP inspection photos only.'];
    }

    return [
        'error' => '',
        'tmp_name' => $tmpName,
        'original_name' => mb_substr(basename((string)($file['name'] ?? 'photo')), 0, 255),
        'mime_type' => $mime,
        'file_size' => $size,
        'extension' => $extensions[$mime],
    ];
}

function inspection_evidence_store_photo(array $photo, int $inspectionId): array
{
    $directory = inspection_evidence_storage_directory();
    if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
        return ['error' => 'Could not prepare inspection photo storage.'];
    }

    $fileName = 'inspection-' . $inspectionId . '-' . bin2hex(random_bytes(16)) . '.' . $photo['extension'];
    $destination = $directory . DIRECTORY_SEPARATOR . $fileName;
    if (!move_uploaded_file($photo['tmp_name'], $destination)) {
        return ['error' => 'Could not save an inspection photo.'];
    }

    return ['error' => '', 'file_name' => $fileName, 'absolute_path' => $destination];
}

function inspection_evidence_delete_photo_file(string $fileName): bool
{
    $safeName = basename($fileName);
    if ($safeName === '' || $safeName !== $fileName) {
        return false;
    }

    $path = inspection_evidence_storage_directory() . DIRECTORY_SEPARATOR . $safeName;
    return !is_file($path) || unlink($path);
}
