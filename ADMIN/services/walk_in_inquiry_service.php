<?php

require_once __DIR__ . '/../../config/inquiry_contact_validation.php';
require_once __DIR__ . '/../../config/inquiry_name_validation.php';
require_once __DIR__ . '/../../config/service_areas.php';
require_once __DIR__ . '/../../config/service_barangays.php';
require_once __DIR__ . '/../../config/walk_in_inquiry_schedule.php';
require_once __DIR__ . '/../../config/walk_in_email_verification.php';

function walk_in_inquiry_has_column(mysqli $conn, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = "service_inquiries"
           AND COLUMN_NAME = ?
         LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $column);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function create_walk_in_inquiry(mysqli $conn, array $input): int
{
    $allowedServices = [
        'New Automation Installation',
        'System Upgrade/Retrofitting',
        'Preventive Maintenance',
        'Emergency Troubleshooting',
        'Other Service',
    ];

    $clientName = trim((string)($input['client_name'] ?? ''));
    $email = mb_strtolower(trim((string)($input['email'] ?? '')), 'UTF-8');
    $contactNo = normalize_ph_mobile(trim((string)($input['contact_no'] ?? '')));
    $region = trim((string)($input['region'] ?? ''));
    $locationArea = trim((string)($input['location_area'] ?? ''));
    $province = service_area_storage_province($region, $locationArea);
    $cityMunicipality = trim((string)($input['city_municipality'] ?? ''));
    $barangay = trim((string)($input['barangay'] ?? ''));
    $serviceCategory = trim((string)($input['service_category'] ?? ''));
    $otherServiceDetails = trim((string)($input['other_service_details'] ?? ''));
    $siteAddress = trim((string)($input['site_address'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $preferredDate = trim((string)($input['preferred_inspection_date'] ?? ''));
    $preferredTime = trim((string)($input['preferred_inspection_time'] ?? ''));
    $preferredEngineerId = max(0, (int)($input['preferred_engineer_id'] ?? 0));

    if (!inquiry_name_is_valid($clientName)) {
        throw new InvalidArgumentException('Enter a valid full name.');
    }
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('Enter a valid email address.');
    }
    if ($contactNo === '' || !is_valid_ph_mobile($contactNo)) {
        throw new InvalidArgumentException('Enter a valid mobile number.');
    }
    $contactValidation = inquiry_contact_validation_result($conn, $clientName, $email, $contactNo);
    if (!$contactValidation['valid']) {
        if ($contactValidation['status'] === 'contact_not_allowed') {
            throw new InvalidArgumentException('This email or number belongs to a staff account.');
        }
        if (in_array($contactValidation['status'], ['contact_mismatch', 'email_mismatch', 'phone_mismatch', 'name_mismatch'], true)) {
            throw new InvalidArgumentException('Use the same full name, email, and number of the existing client.');
        }
        throw new RuntimeException('Unable to check the client details. Try again.');
    }
    if (!in_array($serviceCategory, $allowedServices, true)) {
        throw new InvalidArgumentException('Select a service type.');
    }
    if (!service_area_selection_is_allowed($region, $locationArea, $cityMunicipality)) {
        throw new InvalidArgumentException('Select a valid Region, Province, and City.');
    }
    if (!service_barangay_selection_is_allowed($conn, $region, $locationArea, $cityMunicipality, $barangay)) {
        throw new InvalidArgumentException('Select a valid Barangay.');
    }
    if (mb_strlen($siteAddress, 'UTF-8') < 5) {
        throw new InvalidArgumentException('Enter the site address details.');
    }
    preg_match_all('/\p{L}/u', $description, $descriptionLetters);
    if (mb_strlen($description, 'UTF-8') < 3 || count($descriptionLetters[0]) < 2) {
        throw new InvalidArgumentException('Enter at least 3 characters.');
    }
    if ($serviceCategory === 'Other Service') {
        preg_match_all('/\p{L}/u', $otherServiceDetails, $otherLetters);
        if (mb_strlen($otherServiceDetails, 'UTF-8') < 3 || count($otherLetters[0]) < 2) {
            throw new InvalidArgumentException('Enter the other service details.');
        }
        $description .= "\n\nOther service details: " . $otherServiceDetails;
    }
    $scheduleValidation = walk_in_inquiry_validate_preferred_schedule($preferredDate, $preferredTime);
    if (!$scheduleValidation['valid']) {
        throw new InvalidArgumentException((string)$scheduleValidation['message']);
    }

    $preferredEngineerName = '';
    if ($preferredEngineerId > 0) {
        $engineerStmt = $conn->prepare("SELECT full_name FROM users WHERE id = ? AND role = 'engineer' AND status = 'active' AND deleted_at IS NULL LIMIT 1");
        if (!$engineerStmt) {
            throw new RuntimeException('Unable to check the Engineer.');
        }
        $engineerStmt->bind_param('i', $preferredEngineerId);
        $engineerStmt->execute();
        $engineer = $engineerStmt->get_result()->fetch_assoc();
        if (!$engineer) {
            throw new InvalidArgumentException('Select an active Engineer or leave it Unassigned.');
        }
        $preferredEngineerName = (string)$engineer['full_name'];
    }

    $status = 'Pending Review';
    $emailVerified = walk_in_email_is_verified($email);
    $adminNotes = $emailVerified ? '[Walk-in email verified by OTP]' : '[Walk-in email unverified]';
    if ($preferredEngineerId > 0) {
        $adminNotes .= "\n[Preferred Engineer confirmed: {$preferredEngineerName} (#{$preferredEngineerId})]";
    } else {
        $adminNotes .= "\n[Preferred Engineer: Unassigned]";
    }
    if (
        walk_in_inquiry_has_column($conn, 'inquiry_source')
        && walk_in_inquiry_has_column($conn, 'contact_person')
        && walk_in_inquiry_has_column($conn, 'preferred_inspection_time')
    ) {
        $source = 'walk_in';
        $stmt = $conn->prepare(
            'INSERT INTO service_inquiries
                (inquiry_source, client_name, contact_person, email, contact_no,
                 province, city_municipality, barangay, site_address, service_category,
                 description, preferred_inspection_date, preferred_inspection_time, status, admin_notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to save the walk-in inquiry.');
        }
        $stmt->bind_param(
            'sssssssssssssss',
            $source,
            $clientName,
            $clientName,
            $email,
            $contactNo,
            $province,
            $cityMunicipality,
            $barangay,
            $siteAddress,
            $serviceCategory,
            $description,
            $preferredDate,
            $preferredTime,
            $status,
            $adminNotes
        );
    } else {
        // Fallback habang hindi pa nalalagay ang migration.
        $sourceNote = '[Walk-in inquiry] Preferred time: ' . $preferredTime . "\n" . $adminNotes;
        $stmt = $conn->prepare(
            'INSERT INTO service_inquiries
                (client_name, email, contact_no, province, city_municipality, barangay,
                 site_address, service_category, description, preferred_inspection_date, status, admin_notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to save the walk-in inquiry.');
        }
        $stmt->bind_param(
            'ssssssssssss',
            $clientName,
            $email,
            $contactNo,
            $province,
            $cityMunicipality,
            $barangay,
            $siteAddress,
            $serviceCategory,
            $description,
            $preferredDate,
            $status,
            $sourceNote
        );
    }

    if (!$stmt->execute()) {
        throw new RuntimeException('Unable to save the walk-in inquiry.');
    }

    return (int)$conn->insert_id;
}
