<?php
session_start();

// Handle the landing page inquiry form and save the quotation request safely.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /codesamplecaps/LOGIN/php/index.php#contact');
    exit();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/service_areas.php';
require_once __DIR__ . '/../../config/service_barangays.php';
require_once __DIR__ . '/../../config/inquiry_contact_validation.php';
require_once __DIR__ . '/../../config/inquiry_name_validation.php';
require_once __DIR__ . '/../../config/inquiry_preferred_date.php';

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

function has_meaningful_letters(string $value, int $minimumLetters = 2): bool
{
    preg_match_all('/\p{L}/u', $value, $matches);
    return count($matches[0]) >= $minimumLetters;
}

function inquiry_column_exists(mysqli $conn, string $columnName): bool
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

    $stmt->bind_param('s', $columnName);
    $stmt->execute();
    return (bool)$stmt->get_result()->fetch_assoc();
}

$clientName = normalize_text($_POST['client_name'] ?? '');
$companyName = normalize_text($_POST['company_name'] ?? '');
$email = normalize_text($_POST['email'] ?? '');
$contactNo = normalize_ph_mobile(normalize_text($_POST['contact_no'] ?? ''));
$region = normalize_text($_POST['region'] ?? '');
$locationArea = normalize_text($_POST['location_area'] ?? '');
$province = service_area_storage_province($region, $locationArea);
$cityMunicipality = normalize_text($_POST['city_municipality'] ?? '');
$barangay = normalize_text($_POST['barangay'] ?? '');
$siteAddress = normalize_text($_POST['site_address'] ?? '');
$serviceCategory = normalize_text($_POST['service_category'] ?? '');
$otherServiceDetails = normalize_text($_POST['other_service_details'] ?? '');
$description = normalize_text($_POST['description'] ?? '');
$preferredInspectionDate = normalize_text($_POST['preferred_inspection_date'] ?? '');
$submittedToken = normalize_text($_POST['inquiry_form_token'] ?? '');

$errors = [];

if (
    $submittedToken === ''
    || empty($_SESSION['inquiry_form_token'])
    || !hash_equals((string)$_SESSION['inquiry_form_token'], $submittedToken)
) {
    redirect_to_form('invalid');
}

// Isang gamit lang ang token para hindi makapagpadala ng duplicate OTP email.
unset($_SESSION['inquiry_form_token']);

if (!inquiry_name_is_valid($clientName)) {
    $errors[] = 'client_name';
}

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'email';
}

if ($contactNo === '' || !is_valid_ph_mobile($contactNo)) {
    $errors[] = 'contact_no';
}

if (!service_area_selection_is_allowed($region, $locationArea, $cityMunicipality)) {
    $errors[] = 'city_municipality';
}

if (!service_barangay_selection_is_allowed(
    $conn,
    $region,
    $locationArea,
    $cityMunicipality,
    $barangay
)) {
    $errors[] = 'barangay';
}

if ($siteAddress === '') {
    $errors[] = 'site_address';
}

if ($serviceCategory === '' || !in_array($serviceCategory, $allowedCategories, true)) {
    $errors[] = 'service_category';
}

if ($description === '' || mb_strlen($description, 'UTF-8') < 3 || !has_meaningful_letters($description, 3)) {
    $errors[] = 'description';
}

if (
    $serviceCategory === 'Other / Not sure yet'
    && ($otherServiceDetails === '' || !has_meaningful_letters($otherServiceDetails, 4))
) {
    $errors[] = 'other_service_details';
}

if ($preferredInspectionDate === '' || !inquiry_preferred_date_is_allowed($preferredInspectionDate)) {
    $errors[] = 'preferred_inspection_date';
}

if ($errors !== []) {
    redirect_to_form('invalid');
}

$contactValidation = inquiry_contact_validation_result($conn, $clientName, $email, $contactNo);
if (!$contactValidation['valid']) {
    redirect_to_form((string)$contactValidation['status']);
}

$verifiedEmail = $_SESSION['public_inquiry_email_verified'] ?? [];
if (
    !is_array($verifiedEmail)
    || strtolower((string)($verifiedEmail['email'] ?? '')) !== strtolower($email)
    || (int)($verifiedEmail['verified_at'] ?? 0) < (time() - 900)
) {
    redirect_to_form('email_not_verified');
}

$companyName = $companyName !== '' ? $companyName : null;
if ($otherServiceDetails !== '') {
    $description .= "\n\nOther service details: " . $otherServiceDetails;
}

$stmt = $conn->prepare(
    'INSERT INTO service_inquiries (
        client_name, company_name, email, contact_no, province,
        city_municipality, barangay, site_address, service_category,
        description, preferred_inspection_date
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
if (!$stmt) {
    redirect_to_form('server_error');
}

$stmt->bind_param(
    'sssssssssss',
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
    $preferredInspectionDate
);
if (!$stmt->execute()) {
    redirect_to_form('server_error');
}
unset($_SESSION['public_inquiry_email_verified'], $_SESSION['public_inquiry_otp_token']);
redirect_to_form('success');
