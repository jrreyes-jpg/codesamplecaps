<?php

// Pareho ang bilang at status sa cards at Inventory Clerk alerts.
if (!function_exists('inventory_clerk_asset_display_data')) {
    function inventory_clerk_asset_display_data(array $item): array
    {
        $physicalUnitCount = (int)($item['total_unit_instances'] ?? 0);
        $hasPhysicalUnits = $physicalUnitCount > 0;
        $available = $hasPhysicalUnits
            ? (int)($item['available_unit_instances'] ?? 0)
            : (int)($item['quantity'] ?? 0);

        return [
            'has_physical_units' => $hasPhysicalUnits,
            'total' => $hasPhysicalUnits ? $physicalUnitCount : (int)($item['quantity'] ?? 0),
            'available' => $available,
            'deployed' => $hasPhysicalUnits ? (int)($item['deployed_unit_instances'] ?? 0) : 0,
            'maintenance' => $hasPhysicalUnits ? (int)($item['maintenance_unit_instances'] ?? 0) : 0,
            'lost' => $hasPhysicalUnits ? (int)($item['lost_unit_instances'] ?? 0) : 0,
        ];
    }
}

if (!function_exists('inventory_clerk_asset_display_status')) {
    function inventory_clerk_asset_display_status(array $item, array $counts): array
    {
        if (!$counts['has_physical_units'] && $counts['total'] === 0 && $counts['available'] === 0) {
            return ['key' => 'awaiting-stock-in', 'label' => 'Awaiting Stock In'];
        }

        if ($counts['lost'] > 0) {
            return ['key' => 'attention', 'label' => 'Attention'];
        }

        if ($counts['maintenance'] > 0) {
            return ['key' => 'maintenance', 'label' => 'Maintenance'];
        }

        if ($counts['deployed'] > 0 && $counts['available'] === 0) {
            return ['key' => 'deployed', 'label' => 'Deployed / In Use'];
        }

        $minimum = $item['min_stock'] !== null ? (int)$item['min_stock'] : null;
        if ($counts['available'] > 0 && $minimum !== null && $counts['available'] <= $minimum) {
            return ['key' => 'low-availability', 'label' => 'Low Availability'];
        }

        if ($counts['available'] > 0) {
            return ['key' => 'available', 'label' => 'Available'];
        }

        return ['key' => 'attention', 'label' => 'Attention'];
    }
}

if (!function_exists('inventory_clerk_asset_alert_data')) {
    function inventory_clerk_asset_alert_data(array $item, array $counts): ?array
    {
        $minimum = $item['min_stock'] !== null ? (int)$item['min_stock'] : null;
        $available = $counts['available'];
        $isCritical = strtolower((string)($item['criticality'] ?? '')) === 'high';

        if (!$counts['has_physical_units'] && $counts['total'] === 0 && $available === 0) {
            return [
                'priority' => 3,
                'class' => 'warning',
                'status' => 'Awaiting Stock In',
                'detail' => '0 available' . ($minimum !== null ? ' • Minimum ' . $minimum : ''),
            ];
        }

        if ($counts['lost'] > 0) {
            return [
                'priority' => 1,
                'class' => 'danger',
                'status' => 'Lost Asset',
                'detail' => $counts['lost'] . ' lost unit' . ($counts['lost'] === 1 ? '' : 's'),
            ];
        }

        if ($counts['maintenance'] > 0) {
            return [
                'priority' => 3,
                'class' => 'warning',
                'status' => 'Maintenance',
                'detail' => $counts['maintenance'] . ' unit' . ($counts['maintenance'] === 1 ? '' : 's') . ' in maintenance' . ($available > 0 ? ' • ' . $available . ' available' : ''),
            ];
        }

        if ($available === 0 && $counts['has_physical_units'] && $counts['deployed'] === 0) {
            return [
                'priority' => 1,
                'class' => 'danger',
                'status' => $isCritical ? 'Critical Asset Unavailable' : 'Unavailable',
                'detail' => '0 available',
            ];
        }

        if ($available > 0 && $minimum !== null && $available <= $minimum) {
            return [
                'priority' => 3,
                'class' => 'warning',
                'status' => 'Low Availability',
                'detail' => $available . ' available • Minimum ' . $minimum,
            ];
        }

        return null;
    }
}
