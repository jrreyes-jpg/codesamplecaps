<?php

// Common Asset Master checks. Hindi ito gumagawa ng QR o physical units.
if (!function_exists('asset_master_normalize_text')) {
    function asset_master_normalize_text(string $value): string
    {
        return preg_replace('/\s+/', ' ', trim($value)) ?? '';
    }
}

if (!function_exists('asset_master_text_length')) {
    function asset_master_text_length(string $value): int
    {
        return function_exists('mb_strlen') ? (int)mb_strlen($value) : strlen($value);
    }
}

if (!function_exists('asset_master_lowercase')) {
    function asset_master_lowercase(string $value): string
    {
        return function_exists('mb_strtolower') ? mb_strtolower($value) : strtolower($value);
    }
}

if (!function_exists('asset_master_criticality_options')) {
    function asset_master_criticality_options(): array
    {
        return [
            'low' => 'Low',
            'medium' => 'Medium',
            'high' => 'High',
        ];
    }
}

if (!function_exists('asset_master_fetch_active_categories')) {
    function asset_master_fetch_active_categories(mysqli $conn): array
    {
        $result = $conn->query(
            "SELECT category_key, category_label
             FROM asset_category_defaults
             WHERE is_active = 1
             ORDER BY sort_order ASC, category_label ASC"
        );

        return $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }
}

if (!function_exists('asset_master_active_name_exists')) {
    function asset_master_active_name_exists(mysqli $conn, string $assetName): bool
    {
        $result = $conn->query('SELECT asset_name FROM assets WHERE deleted_at IS NULL');
        if (!$result instanceof mysqli_result) {
            return false;
        }

        $normalizedName = asset_master_lowercase(asset_master_normalize_text($assetName));
        while ($row = $result->fetch_assoc()) {
            $existingName = asset_master_lowercase(asset_master_normalize_text((string)($row['asset_name'] ?? '')));
            if ($existingName === $normalizedName) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('asset_master_validate_inventory_clerk_input')) {
    function asset_master_validate_inventory_clerk_input(array $input, array $categories): array
    {
        $values = [
            'asset_name' => asset_master_normalize_text((string)($input['asset_name'] ?? '')),
            'asset_category' => trim((string)($input['asset_category'] ?? '')),
            'criticality' => trim((string)($input['criticality'] ?? '')),
            'min_stock' => trim((string)($input['min_stock'] ?? '')),
            'description' => asset_master_normalize_text((string)($input['description'] ?? '')),
        ];
        $errors = [];
        $categoryKeys = array_column($categories, 'category_key');

        if ($values['asset_name'] === '') {
            $errors['asset_name'] = 'Asset Name is required.';
        } elseif (!preg_match('/\p{L}/u', $values['asset_name'])) {
            $errors['asset_name'] = 'Enter a valid asset name.';
        } elseif (asset_master_text_length($values['asset_name']) > 255) {
            $errors['asset_name'] = 'Asset Name is too long.';
        }

        if (!in_array($values['asset_category'], $categoryKeys, true)) {
            $errors['asset_category'] = 'Select a valid category.';
        }

        if (!array_key_exists($values['criticality'], asset_master_criticality_options())) {
            $errors['criticality'] = 'Select criticality.';
        }

        if ($values['min_stock'] !== '') {
            if (!preg_match('/^\d+$/', $values['min_stock'])) {
                $errors['min_stock'] = 'Use a whole number of 0 or more.';
            }
        }

        if (asset_master_text_length($values['description']) > 255) {
            $errors['description'] = 'Description must be 255 characters or less.';
        }

        $values['min_stock'] = $values['min_stock'] === '' ? null : (int)$values['min_stock'];

        return [$values, $errors];
    }
}

if (!function_exists('asset_master_create_zero_quantity_inventory')) {
    function asset_master_create_zero_quantity_inventory(mysqli $conn, array $values): array
    {
        if (asset_master_active_name_exists($conn, (string)$values['asset_name'])) {
            throw new RuntimeException('An active asset with this name already exists.');
        }

        $conn->begin_transaction();

        try {
            if (asset_master_active_name_exists($conn, (string)$values['asset_name'])) {
                throw new RuntimeException('An active asset with this name already exists.');
            }

            $assetStatement = $conn->prepare(
                "INSERT INTO assets (
                    asset_name, description, asset_category, asset_type,
                    serial_number, asset_status, criticality, status
                 ) VALUES (?, ?, ?, NULL, NULL, 'available', ?, 'available')"
            );
            if (!$assetStatement || !$assetStatement->bind_param(
                'ssss',
                $values['asset_name'],
                $values['description'],
                $values['asset_category'],
                $values['criticality']
            ) || !$assetStatement->execute()) {
                throw new RuntimeException('Failed to create Asset Master.');
            }

            $assetId = (int)$assetStatement->insert_id;
            $inventoryStatement = $conn->prepare(
                "INSERT INTO inventory (asset_id, quantity, min_stock, status)
                 VALUES (?, 0, ?, 'out-of-stock')"
            );
            if (!$inventoryStatement || !$inventoryStatement->bind_param('ii', $assetId, $values['min_stock']) || !$inventoryStatement->execute()) {
                throw new RuntimeException('Failed to create the asset inventory record.');
            }

            $inventoryId = (int)$inventoryStatement->insert_id;
            $conn->commit();

            return [
                'asset_id' => $assetId,
                'inventory_id' => $inventoryId,
            ];
        } catch (Throwable $exception) {
            $conn->rollback();
            throw $exception;
        }
    }
}
