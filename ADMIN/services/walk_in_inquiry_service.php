<?php

require_once __DIR__ . '/../../config/inquiry_contact_validation.php';
require_once __DIR__ . '/../../config/inquiry_name_validation.php';
require_once __DIR__ . '/../../config/service_areas.php';
require_once __DIR__ . '/../../config/service_barangays.php';
require_once __DIR__ . '/../../config/walk_in_inquiry_schedule.php';
require_once __DIR__ . '/../../config/walk_in_email_verification.php';
require_once __DIR__ . '/../../config/site_inspections.php';
require_once __DIR__ . '/../../config/user_notifications.php';
require_once __DIR__ . '/../../services/EmailService.php';

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

function walk_in_inquiry_table_exists(mysqli $conn, string $table): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('s', $table);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

function walk_in_inquiry_table_has_column(mysqli $conn, string $table, string $column): bool
{
    $stmt = $conn->prepare(
        'SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1'
    );
    if (!$stmt) {
        return false;
    }

    $stmt->bind_param('ss', $table, $column);
    $stmt->execute();

    return (bool)$stmt->get_result()->fetch_assoc();
}

function walk_in_send_confirmed_inspection_notifications(
    mysqli $conn,
    int $inspectionId,
    int $engineerId,
    string $engineerName,
    string $engineerEmail,
    string $clientName,
    string $clientEmail,
    string $clientContactNumber,
    string $service,
    string $siteAddress,
    string $scheduledAt,
    string $siteNotes
): array {
    $result = ['client_sent' => false, 'engineer_sent' => false];
    $schedule = (new DateTimeImmutable($scheduledAt, site_inspection_timezone()))
        ->format('l, F j, Y, g:i A') . ' (PHT)';

    user_notifications_create_if_missing(
        $conn,
        $engineerId,
        'site_inspection_assignment',
        $inspectionId,
        'New Site Inspection Assignment',
        'Client: ' . ($clientName !== '' ? $clientName : 'Client') . ' | Schedule: ' . $schedule,
        site_inspection_engineer_link($inspectionId),
        'site_inspection_assignment:' . $engineerId . ':' . $inspectionId
    );

    if (filter_var($clientEmail, FILTER_VALIDATE_EMAIL)) {
        $clientMailer = new EmailService();
        $result['client_sent'] = $clientMailer->sendInspectionScheduleClientNotification(
            $clientEmail,
            $clientName,
            $service,
            $schedule,
            $engineerName,
            $siteAddress,
            $siteNotes
        );
    }

    if (filter_var($engineerEmail, FILTER_VALIDATE_EMAIL)) {
        $engineerMailer = new EmailService();
        $result['engineer_sent'] = $engineerMailer->sendInspectionScheduleEngineerAssignment(
            $engineerEmail,
            $engineerName,
            $clientName,
            $service,
            $schedule,
            $siteAddress,
            $clientContactNumber,
            $clientEmail,
            $siteNotes,
            false,
            site_inspection_engineer_link($inspectionId)
        );
    }

    if (walk_in_inquiry_table_has_column($conn, 'site_inspections', 'client_schedule_notified_at') && $result['client_sent']) {
        $stmt = $conn->prepare('UPDATE site_inspections SET client_schedule_notified_at = NOW() WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $inspectionId);
            $stmt->execute();
        }
    }
    if (walk_in_inquiry_table_has_column($conn, 'site_inspections', 'engineer_schedule_notified_at') && $result['engineer_sent']) {
        $stmt = $conn->prepare('UPDATE site_inspections SET engineer_schedule_notified_at = NOW() WHERE id = ?');
        if ($stmt) {
            $stmt->bind_param('i', $inspectionId);
            $stmt->execute();
        }
    }

    return $result;
}

function create_walk_in_inquiry(mysqli $conn, array $input, array &$notificationResult = []): int
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
    $emailVerified = walk_in_email_is_verified($email) || !empty($contactValidation['email_verified']);
    if (!$emailVerified) {
        throw new InvalidArgumentException('Verify the client email before saving.');
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
        $engineerStmt = $conn->prepare("SELECT full_name, email FROM users WHERE id = ? AND role = 'engineer' AND status = 'active' AND deleted_at IS NULL LIMIT 1");
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
        $preferredEngineerEmail = (string)($engineer['email'] ?? '');
        if (!walk_in_inquiry_table_exists($conn, 'site_inspections')) {
            throw new RuntimeException('Site inspection table is not ready. Run the site inspection migration first.');
        }
        $candidate = new DateTimeImmutable($preferredDate . ' ' . $preferredTime, walk_in_inquiry_timezone());
        if (site_inspection_engineer_has_schedule_on_date($conn, $preferredEngineerId, $candidate)) {
            throw new InvalidArgumentException('This Engineer already has an inspection on this date. Choose another Engineer or save as Pending.');
        }
    }

    // Kapag may Engineer, ibig sabihin kinumpirma na niya kay Admin ang oras.
    $isConfirmedInspection = $preferredEngineerId > 0;
    $status = $isConfirmedInspection ? 'For Inspection' : 'Pending Review';
    $adminNotes = '[Walk-in email verified]';
    if ($isConfirmedInspection) {
        $adminNotes .= "\n[Inspection confirmed with Engineer: {$preferredEngineerName} (#{$preferredEngineerId})]";
    } else {
        $adminNotes .= "\n[Preferred Engineer: Unassigned]";
    }
    $conn->begin_transaction();
    try {
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

        $inquiryId = (int)$conn->insert_id;
        $inspectionId = 0;
        if ($isConfirmedInspection) {
            $scheduledAt = $preferredDate . ' ' . $preferredTime . ':00';
            $siteNotes = 'Walk-in inspection schedule confirmed by Admin.';
            $createdBy = (int)($_SESSION['user_id'] ?? 0);
            $inspectionStmt = $conn->prepare(
                "INSERT INTO site_inspections (inquiry_id, engineer_id, scheduled_at, site_notes, status, created_by)
                 VALUES (?, ?, ?, ?, 'Assigned', ?)"
            );
            if (!$inspectionStmt) {
                throw new RuntimeException('Unable to create the inspection schedule.');
            }
            $inspectionStmt->bind_param('iissi', $inquiryId, $preferredEngineerId, $scheduledAt, $siteNotes, $createdBy);
            if (!$inspectionStmt->execute()) {
                throw new RuntimeException('Unable to save the inspection schedule.');
            }
            $inspectionId = (int)$conn->insert_id;
        }

        $conn->commit();

        if ($isConfirmedInspection && $inspectionId > 0) {
            try {
                $fullSiteAddress = implode(', ', array_filter([
                    $siteAddress,
                    $barangay,
                    $cityMunicipality,
                    $province,
                ], static fn(string $value): bool => trim($value) !== ''));
                $notificationResult = walk_in_send_confirmed_inspection_notifications(
                    $conn,
                    $inspectionId,
                    $preferredEngineerId,
                    $preferredEngineerName,
                    $preferredEngineerEmail ?? '',
                    $clientName,
                    $email,
                    $contactNo,
                    $serviceCategory,
                    $fullSiteAddress ?: 'Site address not set',
                    $scheduledAt,
                    $siteNotes
                );
            } catch (Throwable $notificationException) {
                // Naka-save na ang schedule kahit may email problem.
                error_log('Walk-in inspection email notification failed: ' . $notificationException->getMessage());
                $notificationResult = ['client_sent' => false, 'engineer_sent' => false];
            }
        }
        return $inquiryId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
