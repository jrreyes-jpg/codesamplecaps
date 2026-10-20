<?php

require_once __DIR__ . '/../../config/inquiry_contact_validation.php';
require_once __DIR__ . '/../../config/inquiry_name_validation.php';
require_once __DIR__ . '/../../config/inquiry_budget.php';
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
        ->format('l, F j, Y');

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

    if ($result['client_sent'] && $result['engineer_sent']
        && walk_in_inquiry_table_has_column($conn, 'site_inspections', 'schedule_notified_at')
        && walk_in_inquiry_table_has_column($conn, 'site_inspections', 'schedule_notification_hash')) {
        $normalizedNotes = preg_replace('/\r\n?|\n/', "\n", trim($siteNotes)) ?? '';
        $notificationHash = hash('sha256', implode("\n", [$engineerId, $scheduledAt, $normalizedNotes]));
        $stmt = $conn->prepare(
            'UPDATE site_inspections SET schedule_notified_at = NOW(), schedule_notification_hash = ? WHERE id = ?'
        );
        if ($stmt) {
            $stmt->bind_param('si', $notificationHash, $inspectionId);
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
    $companyName = trim((string)($input['company_name'] ?? ''));
    $email = mb_strtolower(trim((string)($input['email'] ?? '')), 'UTF-8');
    $contactNo = normalize_ph_mobile(trim((string)($input['contact_no'] ?? '')));
    $region = trim((string)($input['region'] ?? ''));
    $locationArea = trim((string)($input['location_area'] ?? ''));
    $province = service_area_storage_province($region, $locationArea);
    $cityMunicipality = trim((string)($input['city_municipality'] ?? ''));
    $barangay = trim((string)($input['barangay'] ?? ''));
    $serviceCategory = trim((string)($input['service_category'] ?? ''));
    $estimatedBudget = trim((string)($input['estimated_budget'] ?? ''));
    $otherServiceDetails = trim((string)($input['other_service_details'] ?? ''));
    $siteAddress = trim((string)($input['site_address'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $preferredDate = trim((string)($input['preferred_inspection_date'] ?? ''));
    $preferredEngineerId = max(0, (int)($input['preferred_engineer_id'] ?? 0));
    $nextAction = trim((string)($input['next_action'] ?? 'pending'));

    if (!in_array($nextAction, ['pending', 'confirmed'], true)) {
        throw new InvalidArgumentException('Select a valid save action.');
    }
    if ($nextAction === 'confirmed' && $preferredEngineerId <= 0) {
        throw new InvalidArgumentException('Select the confirmed Engineer.');
    }

    if (!inquiry_name_is_valid($clientName)) {
        throw new InvalidArgumentException('Enter a valid full name.');
    }
    if (mb_strlen($companyName, 'UTF-8') > 150) {
        throw new InvalidArgumentException('Company name must be 150 characters or less.');
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
    if (!inquiry_budget_is_valid($estimatedBudget)) {
        throw new InvalidArgumentException('Select a valid budget range or leave it blank.');
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
    $scheduleValidation = walk_in_inquiry_validate_preferred_date($preferredDate);
    if (!$scheduleValidation['valid']) {
        throw new InvalidArgumentException((string)$scheduleValidation['message']);
    }

    $preferredEngineerName = '';
    $preferredEngineerEmail = '';
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
        $preferredEngineerEmail = trim((string)($engineer['email'] ?? ''));
    }

    $isConfirmed = $nextAction === 'confirmed';
    $status = $isConfirmed ? 'For Inspection' : 'Pending Review';
    $adminNotes = '[Walk-in email verified]';
    if ($preferredEngineerId > 0) {
        $engineerLabel = $isConfirmed ? 'Confirmed Engineer' : 'Preferred Engineer';
        $adminNotes .= "\n[{$engineerLabel}: {$preferredEngineerName} (#{$preferredEngineerId})]";
    } else {
        $adminNotes .= "\n[Preferred Engineer: Unassigned]";
    }
    $conn->begin_transaction();
    try {
    $hasEstimatedBudget = walk_in_inquiry_has_column($conn, 'estimated_budget');
    if (
        walk_in_inquiry_has_column($conn, 'inquiry_source')
        && walk_in_inquiry_has_column($conn, 'contact_person')
        && walk_in_inquiry_has_column($conn, 'preferred_inspection_time')
        && $hasEstimatedBudget
    ) {
        $source = 'walk_in';
        $preferredTime = null;
        $stmt = $conn->prepare(
            'INSERT INTO service_inquiries
                (inquiry_source, client_name, contact_person, company_name, email, contact_no,
                 province, city_municipality, barangay, site_address, service_category,
                 estimated_budget, description, preferred_inspection_date, preferred_inspection_time, status, admin_notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to save the walk-in inquiry.');
        }
        $stmt->bind_param(
            'sssssssssssssssss',
            $source,
            $clientName,
            $clientName,
            $companyName,
            $email,
            $contactNo,
            $province,
            $cityMunicipality,
            $barangay,
            $siteAddress,
            $serviceCategory,
            $estimatedBudget,
            $description,
            $preferredDate,
            $preferredTime,
            $status,
            $adminNotes
        );
    } else {
        // Fallback habang hindi pa nalalagay ang migration.
        $sourceNote = '[Walk-in inquiry]' . "\n" . $adminNotes;
        $stmt = $conn->prepare(
            'INSERT INTO service_inquiries
                (client_name, company_name, email, contact_no, province, city_municipality, barangay,
                 site_address, service_category, description, preferred_inspection_date, status, admin_notes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        if (!$stmt) {
            throw new RuntimeException('Unable to save the walk-in inquiry.');
        }
        $stmt->bind_param(
            'sssssssssssss',
            $clientName,
            $companyName,
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
        $scheduledAt = $preferredDate . ' 00:00:00';
        // Walang default note. Dapat tunay na site note lang ang ilagay dito.
        $siteNotes = '';

        if ($isConfirmed) {
            if (!walk_in_inquiry_table_exists($conn, 'site_inspections')) {
                throw new RuntimeException('Site inspection table is not ready.');
            }

            $scheduleDate = new DateTimeImmutable($scheduledAt, site_inspection_timezone());
            if (site_inspection_engineer_has_schedule_on_date($conn, $preferredEngineerId, $scheduleDate, 0, true)) {
                throw new InvalidArgumentException('This Engineer already has an inspection on this date.');
            }

            $createdBy = (int)($_SESSION['user_id'] ?? 0);
            $clientScheduleTokenHash = '';
            $inspectionStmt = $conn->prepare(
                "INSERT INTO site_inspections
                    (inquiry_id, engineer_id, scheduled_at, site_notes, client_schedule_token_hash,
                     client_schedule_token_expires_at, status, created_by)
                 VALUES (?, ?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL 14 DAY), 'Assigned', ?)"
            );
            if (!$inspectionStmt) {
                throw new RuntimeException('Unable to create the inspection schedule.');
            }
            $inspectionStmt->bind_param(
                'iisssi',
                $inquiryId,
                $preferredEngineerId,
                $scheduledAt,
                $siteNotes,
                $clientScheduleTokenHash,
                $createdBy
            );
            if (!$inspectionStmt->execute()) {
                throw new RuntimeException('Unable to create the inspection schedule.');
            }
            $inspectionId = (int)$conn->insert_id;
        }

        $conn->commit();

        $notificationResult = [
            'action' => $nextAction,
            'inspection_id' => $inspectionId,
            'client_sent' => false,
            'engineer_sent' => false,
        ];
        if ($isConfirmed && $inspectionId > 0) {
            $completeSiteAddress = implode(', ', array_filter([
                $siteAddress,
                $barangay,
                $cityMunicipality,
                $province,
            ], static fn(string $value): bool => trim($value) !== ''));
            try {
                $notificationResult = array_merge(
                    $notificationResult,
                    walk_in_send_confirmed_inspection_notifications(
                        $conn,
                        $inspectionId,
                        $preferredEngineerId,
                        $preferredEngineerName,
                        $preferredEngineerEmail,
                        $clientName,
                        $email,
                        $contactNo,
                        $serviceCategory,
                        $completeSiteAddress,
                        $scheduledAt,
                        $siteNotes
                    )
                );
            } catch (Throwable $notificationException) {
                error_log('Walk-in inspection notification failed: ' . $notificationException->getMessage());
            }
        }

        return $inquiryId;
    } catch (Throwable $exception) {
        $conn->rollback();
        throw $exception;
    }
}
