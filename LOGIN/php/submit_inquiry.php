<?php
// Handle the landing page inquiry form and save the quotation request safely.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /codesamplecaps/LOGIN/php/index.php#contact');
    exit();
}

require_once __DIR__ . '/../../config/database.php';

$allowedCategories = [
    'New Automation Installation',
    'System Upgrade/Retrofitting',
    'Preventive Maintenance',
    'Emergency Troubleshooting',
    'Other / Not sure yet',
];

function redirect_to_form(string $status): void
{
    header('Location: /codesamplecaps/LOGIN/php/index.php?inquiry=' . rawurlencode($status) . '#contact');
    exit();
}

function normalize_text(?string $value): string
{
    return trim((string) $value);
}

$clientName = normalize_text($_POST['client_name'] ?? '');
$companyName = normalize_text($_POST['company_name'] ?? '');
$email = normalize_text($_POST['email'] ?? '');
$contactNo = normalize_text($_POST['contact_no'] ?? '');
$siteAddress = normalize_text($_POST['site_address'] ?? '');
$serviceCategory = normalize_text($_POST['service_category'] ?? '');
$otherServiceDetails = normalize_text($_POST['other_service_details'] ?? '');
$description = normalize_text($_POST['description'] ?? '');
$preferredInspectionDate = normalize_text($_POST['preferred_inspection_date'] ?? '');

$errors = [];

if ($clientName === '') {
    $errors[] = 'client_name';
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'email';
}

if ($contactNo === '' || !preg_match('/^09\d{9}$/', $contactNo)) {
    $errors[] = 'contact_no';
}

if ($siteAddress === '') {
    $errors[] = 'site_address';
}

if ($serviceCategory === '' || !in_array($serviceCategory, $allowedCategories, true)) {
    $errors[] = 'service_category';
}

if ($description === '' || mb_strlen($description, 'UTF-8') < 20) {
    $errors[] = 'description';
}

if ($serviceCategory === 'Other / Not sure yet' && $otherServiceDetails === '') {
    $errors[] = 'other_service_details';
}

if ($preferredInspectionDate !== '') {
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $preferredInspectionDate);
    $tomorrow = (new DateTimeImmutable('tomorrow'))->setTime(0, 0, 0);

    if (!$date || $date->format('Y-m-d') !== $preferredInspectionDate || $date < $tomorrow) {
        $errors[] = 'preferred_inspection_date';
    }
}

if ($errors !== []) {
    redirect_to_form('invalid');
}

$companyName = $companyName !== '' ? $companyName : null;
if ($otherServiceDetails !== '') {
    $description .= "\n\nOther service details: " . $otherServiceDetails;
}

$conn->query(
    'CREATE TABLE IF NOT EXISTS service_inquiries (
        id INT AUTO_INCREMENT PRIMARY KEY,
        client_name VARCHAR(150) NOT NULL,
        company_name VARCHAR(150) DEFAULT NULL,
        email VARCHAR(150) NOT NULL,
        contact_no VARCHAR(30) NOT NULL,
        site_address TEXT NOT NULL,
        service_category VARCHAR(80) NOT NULL,
        description TEXT NOT NULL,
        preferred_inspection_date DATE DEFAULT NULL,
        status VARCHAR(50) NOT NULL DEFAULT "Pending Review",
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    )'
);

$stmt = $conn->prepare(
    'INSERT INTO service_inquiries (
        client_name,
        company_name,
        email,
        contact_no,
        site_address,
        service_category,
        description,
        preferred_inspection_date
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);

if (!$stmt) {
    redirect_to_form('server_error');
}

$preferredInspectionDateValue = $preferredInspectionDate !== '' ? $preferredInspectionDate : null;
$stmt->bind_param(
    'ssssssss',
    $clientName,
    $companyName,
    $email,
    $contactNo,
    $siteAddress,
    $serviceCategory,
    $description,
    $preferredInspectionDateValue
);

if ($stmt->execute()) {
    redirect_to_form('success');
}

redirect_to_form('server_error');
