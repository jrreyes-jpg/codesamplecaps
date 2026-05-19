<?php

if (!function_exists('profile_photo_storage_prefix')) {
    function profile_photo_storage_prefix(): string
    {
        return 'system-profile:';
    }
}

if (!function_exists('profile_photo_project_root')) {
    function profile_photo_project_root(): string
    {
        return dirname(__DIR__);
    }
}

if (!function_exists('profile_photo_system_directory')) {
    function profile_photo_system_directory(): string
    {
        return dirname(__DIR__, 3)
            . DIRECTORY_SEPARATOR . 'userdata'
            . DIRECTORY_SEPARATOR . 'codesamplecaps'
            . DIRECTORY_SEPARATOR . 'profile_photos';
    }
}

if (!function_exists('profile_photo_legacy_directory')) {
    function profile_photo_legacy_directory(): string
    {
        return profile_photo_project_root()
            . DIRECTORY_SEPARATOR . 'uploads'
            . DIRECTORY_SEPARATOR . 'profile_photos';
    }
}

if (!function_exists('profile_photo_public_url')) {
    function profile_photo_public_url(string $reference): string
    {
        return '/codesamplecaps/profile_photo.php?v=' . rawurlencode(substr(sha1($reference), 0, 16));
    }
}

if (!function_exists('profile_photo_is_system_reference')) {
    function profile_photo_is_system_reference(string $reference): bool
    {
        return str_starts_with($reference, profile_photo_storage_prefix());
    }
}

if (!function_exists('profile_photo_is_legacy_reference')) {
    function profile_photo_is_legacy_reference(string $reference): bool
    {
        $normalized = str_replace('\\', '/', trim($reference));

        return (bool)preg_match('#^uploads/profile_photos/[A-Za-z0-9._-]+$#', $normalized);
    }
}

if (!function_exists('profile_photo_extension_for_mime')) {
    function profile_photo_extension_for_mime(string $mime): ?string
    {
        $allowedExtensions = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
        ];

        return $allowedExtensions[$mime] ?? null;
    }
}

if (!function_exists('profile_photo_resolve_absolute_path')) {
    function profile_photo_resolve_absolute_path(?string $reference): ?string
    {
        $reference = trim((string)$reference);
        if ($reference === '') {
            return null;
        }

        if (profile_photo_is_system_reference($reference)) {
            $fileName = basename(substr($reference, strlen(profile_photo_storage_prefix())));
            if ($fileName === '' || !preg_match('/^[A-Za-z0-9._-]+$/', $fileName)) {
                return null;
            }

            return profile_photo_system_directory() . DIRECTORY_SEPARATOR . $fileName;
        }

        if (!profile_photo_is_legacy_reference($reference)) {
            return null;
        }

        return profile_photo_project_root() . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $reference);
    }
}

if (!function_exists('profile_photo_file_name_from_reference')) {
    function profile_photo_file_name_from_reference(?string $reference): ?string
    {
        $path = profile_photo_resolve_absolute_path($reference);
        if ($path === null) {
            return null;
        }

        return basename($path);
    }
}

if (!function_exists('profile_photo_output_default_image')) {
    function profile_photo_output_default_image(): void
    {
        $defaultPath = profile_photo_project_root() . DIRECTORY_SEPARATOR . 'IMAGES' . DIRECTORY_SEPARATOR . 'nodp.jpg';

        if (is_file($defaultPath) && is_readable($defaultPath)) {
            header('Content-Type: image/jpeg');
            header('Content-Length: ' . (string)filesize($defaultPath));
            readfile($defaultPath);
            exit;
        }

        http_response_code(404);
        exit;
    }
}

if (!function_exists('profile_photo_output_reference')) {
    function profile_photo_output_reference(?string $reference): void
    {
        $path = profile_photo_resolve_absolute_path($reference);

        if ($path === null || !is_file($path) || !is_readable($path)) {
            profile_photo_output_default_image();
        }

        $mime = 'image/jpeg';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detectedMime = finfo_file($finfo, $path);
                if (is_string($detectedMime) && $detectedMime !== '') {
                    $mime = $detectedMime;
                }
            }
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        readfile($path);
        exit;
    }
}

if (!function_exists('profile_photo_cleanup_duplicates')) {
    function profile_photo_cleanup_duplicates(int $userId, ?string $keepFileName = null, bool $includeLegacy = true): void
    {
        $patterns = [
            profile_photo_system_directory() . DIRECTORY_SEPARATOR . 'user-' . $userId . '.*',
            profile_photo_system_directory() . DIRECTORY_SEPARATOR . 'super-admin-' . $userId . '.*',
        ];

        if ($includeLegacy) {
            $patterns[] = profile_photo_legacy_directory() . DIRECTORY_SEPARATOR . 'super-admin-' . $userId . '-*.*';
            $patterns[] = profile_photo_legacy_directory() . DIRECTORY_SEPARATOR . 'super-admin-' . $userId . '.*';
        }

        $seen = [];
        foreach ($patterns as $pattern) {
            $matches = glob($pattern);
            if ($matches === false) {
                continue;
            }

            foreach ($matches as $candidate) {
                $realCandidate = realpath($candidate) ?: $candidate;
                if (isset($seen[$realCandidate])) {
                    continue;
                }
                $seen[$realCandidate] = true;

                if ($keepFileName !== null && basename($candidate) === $keepFileName) {
                    continue;
                }

                if (is_file($candidate)) {
                    @unlink($candidate);
                }
            }
        }
    }
}

if (!function_exists('profile_photo_store_upload')) {
    function profile_photo_store_upload(array $file, int $userId): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            return ['path' => null, 'error' => null];
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            return ['path' => null, 'error' => 'Profile photo upload failed. Please try again.'];
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['path' => null, 'error' => 'Invalid profile photo upload.'];
        }

        $imageInfo = @getimagesize($tmpName);
        if ($imageInfo === false) {
            return ['path' => null, 'error' => 'Profile photo must be a valid image file.'];
        }

        $mime = (string)($imageInfo['mime'] ?? '');
        $extension = profile_photo_extension_for_mime($mime);
        if ($extension === null) {
            return ['path' => null, 'error' => 'Use JPG, PNG, or WEBP for the profile photo.'];
        }

        if ((int)($file['size'] ?? 0) > 3 * 1024 * 1024) {
            return ['path' => null, 'error' => 'Profile photo must be 3MB or smaller.'];
        }

        $storageDirectory = profile_photo_system_directory();
        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0775, true) && !is_dir($storageDirectory)) {
            return ['path' => null, 'error' => 'Unable to prepare the system profile photo folder.'];
        }

        $fileName = 'user-' . $userId . '.' . $extension;
        $destination = $storageDirectory . DIRECTORY_SEPARATOR . $fileName;

        if (is_file($destination) && !@unlink($destination)) {
            return ['path' => null, 'error' => 'Unable to replace the previous profile photo.'];
        }

        if (!move_uploaded_file($tmpName, $destination)) {
            return ['path' => null, 'error' => 'Unable to save the profile photo.'];
        }

        return [
            'path' => profile_photo_storage_prefix() . $fileName,
            'error' => null,
        ];
    }
}

if (!function_exists('profile_photo_migrate_legacy_reference')) {
    function profile_photo_migrate_legacy_reference(mysqli $conn, int $userId, ?string $reference): ?string
    {
        $reference = trim((string)$reference);
        if ($reference === '' || profile_photo_is_system_reference($reference) || !profile_photo_is_legacy_reference($reference)) {
            return $reference;
        }

        $source = profile_photo_resolve_absolute_path($reference);
        if ($source === null || !is_file($source) || !is_readable($source)) {
            return $reference;
        }

        $imageInfo = @getimagesize($source);
        $extension = $imageInfo !== false
            ? profile_photo_extension_for_mime((string)($imageInfo['mime'] ?? ''))
            : null;

        if ($extension === null) {
            $extension = strtolower((string)pathinfo($source, PATHINFO_EXTENSION));
            if (!in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                return $reference;
            }
            if ($extension === 'jpeg') {
                $extension = 'jpg';
            }
        }

        $storageDirectory = profile_photo_system_directory();
        if (!is_dir($storageDirectory) && !mkdir($storageDirectory, 0775, true) && !is_dir($storageDirectory)) {
            return $reference;
        }

        $fileName = 'user-' . $userId . '.' . $extension;
        $destination = $storageDirectory . DIRECTORY_SEPARATOR . $fileName;

        if (is_file($destination) && !@unlink($destination)) {
            return $reference;
        }

        if (!@copy($source, $destination)) {
            return $reference;
        }

        $storedReference = profile_photo_storage_prefix() . $fileName;
        $stmt = $conn->prepare('UPDATE users SET profile_photo_path = ? WHERE id = ?');
        if (!$stmt) {
            return $reference;
        }

        $stmt->bind_param('si', $storedReference, $userId);
        if (!$stmt->execute()) {
            return $reference;
        }

        @unlink($source);
        profile_photo_cleanup_duplicates($userId, $fileName);

        return $storedReference;
    }
}
