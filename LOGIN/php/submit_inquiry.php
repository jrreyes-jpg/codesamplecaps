<?php
// Handler ng inquiry form. Dito sine-save ang quotation request sa database.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /codesamplecaps/LOGIN/php/index.php#contact');
    exit();
}

$conn = new mysqli('localhost', 'root', '', 'edge_project_asset_inventory_db');

if ($conn->connect_error) {
    header('Location: /codesamplecaps/LOGIN/php/index.php?inquiry=server_error#contact');
    exit();
}

$allowedCategories = [
    'New Automation Installation',
    'System Upgrade/Retrofitting',
    'Preventive Maintenance',
    'Emergency Troubleshooting',
    'Other / Not sure yet',
];

$clientName = trim((string)($_POST['client_name'] ?? ''));
$companyName = trim((string)($_POST['company_name'] ?? ''));
$email = trim((string)($_POST['email'] ?? ''));
$contactNo = trim((string)($_POST['contact_no'] ?? ''));
$siteAddress = trim((string)($_POST['site_address'] ?? ''));
$serviceCategory = trim((string)($_POST['service_category'] ?? ''));
$otherServiceDetails = trim((string)($_POST['other_service_details'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$preferredInspectionDate = trim((string)($_POST['preferred_inspection_date'] ?? ''));

// Basic backend validation para hindi lang browser ang nagche-check.
if (
    $clientName === '' ||
    $email === '' ||
    $contactNo === '' ||
    $siteAddress === '' ||
    $serviceCategory === '' ||
    $description === '' ||
    strlen($description) < 20 ||
    !filter_var($email, FILTER_VALIDATE_EMAIL) ||
    !preg_match('/^09\d{9}$/', $contactNo) ||
    !in_array($serviceCategory, $allowedCategories, true) ||
    ($serviceCategory === 'Other / Not sure yet' && $otherServiceDetails === '')
) {
    header('Location: /codesamplecaps/LOGIN/php/index.php?inquiry=invalid#contact');
    exit();
}

if ($preferredInspectionDate !== '') {
    $dateParts = date_parse($preferredInspectionDate);
    $tomorrow = date('Y-m-d', strtotime('+1 day'));
    if (!$dateParts || $dateParts['error_count'] > 0 || $preferredInspectionDate < $tomorrow) {
        header('Location: /codesamplecaps/LOGIN/php/index.php?inquiry=invalid#contact');
        exit();
    }
} else {
    $preferredInspectionDate = null;
}

// Sanitize bago isave. Prepared statement pa rin ang main SQL injection protection.
$clientName = htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8');
$companyName = $companyName !== '' ? htmlspecialchars($companyName, ENT_QUOTES, 'UTF-8') : null;
$email = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$contactNo = htmlspecialchars($contactNo, ENT_QUOTES, 'UTF-8');
$siteAddress = htmlspecialchars($siteAddress, ENT_QUOTES, 'UTF-8');
$serviceCategory = htmlspecialchars($serviceCategory, ENT_QUOTES, 'UTF-8');
$description = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');

if ($otherServiceDetails !== '') {
    $otherServiceDetails = htmlspecialchars($otherServiceDetails, ENT_QUOTES, 'UTF-8');
    $description .= "\n\nOther service details: " . $otherServiceDetails;
}

$stmt = $conn->prepare(
    'INSERT INTO service_inquiries
     (client_name, company_name, email, contact_no, site_address, service_category, description, preferred_inspection_date)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

if (!$stmt) {
    header('Location: /codesamplecaps/LOGIN/php/index.php?inquiry=server_error#contact');
    exit();
}

$stmt->bind_param(
    'ssssssss',
    $clientName,
    $companyName,
    $email,
    $contactNo,
    $siteAddress,
    $serviceCategory,
    $description,
    $preferredInspectionDate
);

if ($stmt->execute()) {
    header('Location: /codesamplecaps/LOGIN/php/index.php?inquiry=success#contact');
    exit();
}

header('Location: /codesamplecaps/LOGIN/php/index.php?inquiry=server_error#contact');
exit();
