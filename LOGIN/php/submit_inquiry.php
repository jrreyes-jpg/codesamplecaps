<?php
// Handle the landing page inquiry form and save the quotation request safely.
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /codesamplecaps/LOGIN/php/index.php#contact');
    exit();
}

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/service_areas.php';
require_once __DIR__ . '/../../config/service_barangays.php';
require_once __DIR__ . '/../../config/inquiry_otp.php';
require_once __DIR__ . '/../../services/EmailService.php';

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
$contactNo = normalize_text($_POST['contact_no'] ?? '');
$province = normalize_text($_POST['province'] ?? '');
$cityMunicipality = normalize_text($_POST['city_municipality'] ?? '');
$barangay = normalize_text($_POST['barangay'] ?? '');
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

if (!service_area_is_allowed($province, $cityMunicipality)) {
    $errors[] = 'city_municipality';
}

if (!service_barangay_city_has_data($conn, $province, $cityMunicipality)) {
    $errors[] = 'barangay';
} elseif (!service_barangay_is_allowed($conn, $province, $cityMunicipality, $barangay)) {
    $errors[] = 'barangay';
}

if ($siteAddress === '') {
    $errors[] = 'site_address';
}

if ($serviceCategory === '' || !in_array($serviceCategory, $allowedCategories, true)) {
    $errors[] = 'service_category';
}

if ($description === '' || mb_strlen($description, 'UTF-8') < 10) {
    $errors[] = 'description';
}

if ($serviceCategory === 'Other / Not sure yet' && $otherServiceDetails === '') {
    $errors[] = 'other_service_details';
}

if ($preferredInspectionDate !== '') {
    $timezone = new DateTimeZone('Asia/Manila');
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $preferredInspectionDate, $timezone);
    $now = new DateTimeImmutable('now', $timezone);
    $minimumDate = ((int)$now->format('H') >= 17)
        ? (new DateTimeImmutable('tomorrow', $timezone))->setTime(0, 0, 0)
        : (new DateTimeImmutable('today', $timezone))->setTime(0, 0, 0);

    if (!$date || $date->format('Y-m-d') !== $preferredInspectionDate || $date < $minimumDate) {
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

inquiry_otp_ensure_table($conn);
$otp = (string)random_int(100000, 999999);
$token = bin2hex(random_bytes(32));
$payload = [
    'client_name' => $clientName,
    'company_name' => $companyName,
    'email' => $email,
    'contact_no' => $contactNo,
    'province' => $province,
    'city_municipality' => $cityMunicipality,
    'barangay' => $barangay,
    'site_address' => $siteAddress,
    'service_category' => $serviceCategory,
    'description' => $description,
    'preferred_inspection_date' => $preferredInspectionDate !== '' ? $preferredInspectionDate : null,
];

$stmt = $conn->prepare(
    'INSERT INTO pending_service_inquiries (token, otp_hash, payload_json, expires_at)
     VALUES (?, ?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))'
);
if (!$stmt) {
    redirect_to_form('server_error');
}

$otpHash = password_hash($otp, PASSWORD_DEFAULT);
$payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES);
$stmt->bind_param('sss', $token, $otpHash, $payloadJson);
if (!$stmt->execute()) {
    redirect_to_form('server_error');
}

$emailService = new EmailService();
if (!$emailService->sendInquiryOtp($email, $clientName, $otp, 10)) {
    redirect_to_form('email_error');
}

inquiry_otp_redirect('verify', $token);
