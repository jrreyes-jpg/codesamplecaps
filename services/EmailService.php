<?php
/**
 * Email Service
 * 
 * Manages all email sending operations
 * Uses PHPMailer library (already installed via Composer)
 * Configuration comes from Config service
 * 
 * Usage:
 *   $emailService = new EmailService();
 *   $emailService->sendPasswordReset('user@email.com', 'reset_link_here');
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/Config.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService {
    private const COMPANY_CONTACT_NUMBER = '09178789571';
    private const COMPANY_CONTACT_EMAIL = 'ejimenez.edge@gmail.com';

    private $config;
    private $mailer;
    private $error = '';

    public function __construct() {
        $this->config = Config::getInstance();
        $this->initializeMailer();
    }

    /**
     * Initialize PHPMailer with SMTP settings
     */
    private function initializeMailer() {
        $this->mailer = new PHPMailer(true);
        
        try {
            $mailConfig = $this->config->getMailConfig();

            if (trim((string)$mailConfig['password']) === '') {
                $this->error = 'Email service is not configured. Add a Gmail app password in .env.';
                return;
            }
            
            $this->mailer->isSMTP();
            $this->mailer->Host = trim((string)$mailConfig['host']);
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = trim((string)$mailConfig['username']);
            $this->mailer->Password = trim((string)$mailConfig['password']);
            // Gamitin ang encryption na nasa config para hindi hardcoded.
            $this->mailer->SMTPSecure = $mailConfig['encryption'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $this->mailer->Port = (int)$mailConfig['port'];
            
            $this->mailer->setFrom(trim((string)$mailConfig['from_address']), trim((string)$mailConfig['from_name']));
        } catch (Exception $e) {
            error_log('SMTP configuration error: ' . $e->getMessage());
            // Huwag ipakita sa user ang SMTP/config details; sa logs lang dapat makita.
            $this->error = 'Email service cannot send right now. Check SMTP username and Gmail app password.';
        }
    }

    /**
     * Send password reset email
     * 
     * @param string $recipientEmail User email
     * @param string $recipientName User full name
     * @param string $resetToken Reset token
     * @param int $expiryMinutes Token expiry in minutes
     * @return bool Success status
     */
    public function sendPasswordReset($recipientEmail, $recipientName, $resetToken, $expiryMinutes = 60) {
        try {
            if ($this->error !== '') {
                return false;
            }

            $this->mailer->clearAddresses();
            $this->mailer->addAddress($recipientEmail);
            
            $appUrl = $this->config->get('APP_URL');
            $resetLink = $appUrl . '/LOGIN/php/reset_password.php?token=' . $resetToken;
            
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Password Reset Request - ' . $this->config->get('APP_NAME');
            
            $htmlBody = $this->getPasswordResetEmailTemplate($recipientName, $resetLink, $expiryMinutes);
            $this->mailer->Body = $htmlBody;
            $this->mailer->AltBody = strip_tags($htmlBody);
            
            $this->mailer->send();
            return true;
            
        } catch (Exception $e) {
            $this->error = 'Email service cannot send right now. Check SMTP username and Gmail app password.';
            return false;
        }
    }

    /**
     * Send account created notification
     * 
     * @param string $recipientEmail User email
     * @param string $recipientName User full name
     * @param string $role User role
     * @return bool Success status
     */
    public function sendAccountCreated($recipientEmail, $recipientName, $role) {
        try {
            if ($this->error !== '') {
                return false;
            }

            $this->mailer->clearAddresses();
            $this->mailer->addAddress($recipientEmail);
            
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Account Created - ' . $this->config->get('APP_NAME');
            
            $htmlBody = $this->getAccountCreatedEmailTemplate($recipientName, $role);
            $this->mailer->Body = $htmlBody;
            $this->mailer->AltBody = strip_tags($htmlBody);
            
            $this->mailer->send();
            return true;
            
        } catch (Exception $e) {
            $this->error = 'Email service cannot send right now. Check SMTP username and Gmail app password.';
            return false;
        }
    }

    /**
     * Send account status change notification
     */
    public function sendStatusChange($recipientEmail, $recipientName, $newStatus) {
        try {
            if ($this->error !== '') {
                return false;
            }

            $this->mailer->clearAddresses();
            $this->mailer->addAddress($recipientEmail);
            
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Account Status Updated - ' . $this->config->get('APP_NAME');
            
            $statusText = ($newStatus === 'active') ? 'activated' : 'deactivated';
            $htmlBody = $this->getStatusChangeEmailTemplate($recipientName, $statusText);
            $this->mailer->Body = $htmlBody;
            $this->mailer->AltBody = strip_tags($htmlBody);
            
            $this->mailer->send();
            return true;
            
        } catch (Exception $e) {
            $this->error = 'Email service cannot send right now. Check SMTP username and Gmail app password.';
            return false;
        }
    }

    /**
     * Send the first password setup link for a pending Client account.
     */
    public function sendAccountActivation($recipientEmail, $recipientName, $activationToken, $expiryMinutes = 60) {
        try {
            if ($this->error !== '') {
                return false;
            }

            $this->mailer->clearAddresses();
            $this->mailer->addAddress($recipientEmail);
            $appUrl = rtrim((string)$this->config->get('APP_URL'), '/');
            $activationLink = $appUrl . '/LOGIN/php/reset_password.php?flow=activate&token=' . urlencode($activationToken);

            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Activate Your Client Account - ' . $this->config->get('APP_NAME');
            $htmlBody = $this->getAccountActivationEmailTemplate($recipientName, $activationLink, $expiryMinutes);
            $this->mailer->Body = $htmlBody;
            $this->mailer->AltBody = strip_tags($htmlBody);
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log('Client activation email failed: ' . $e->getMessage());
            $this->error = 'Email service cannot send right now. Check SMTP username and Gmail app password.';
            return false;
        }
    }

    public function sendInquiryOtp(string $recipientEmail, string $recipientName, string $otp, DateTimeInterface $expiresAt): bool {
        try {
            if ($this->error !== '') {
                return false;
            }

            $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Client', ENT_QUOTES, 'UTF-8');
            $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
            $expiryInPhilippineTime = $expiresAt
                ->setTimezone(new DateTimeZone('Asia/Manila'))
                ->format('g:i A, F j, Y') . ' (PHT)';
            $safeExpiry = htmlspecialchars($expiryInPhilippineTime, ENT_QUOTES, 'UTF-8');
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($recipientEmail);
            $logoPath = __DIR__ . '/../IMAGES/edge.jpg';
            $logoHtml = '';
            if (is_file($logoPath)) {
                $this->mailer->addEmbeddedImage($logoPath, 'edgeLogo');
                $logoHtml = "<img src='cid:edgeLogo' alt='Edge Automation' style='width:56px;height:56px;border-radius:12px;object-fit:cover;margin-bottom:12px'>";
            }
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Inquiry Verification Code - ' . $this->config->get('APP_NAME');
            $this->mailer->Body = "
                <div style='font-family:Arial,sans-serif;max-width:560px;margin:auto;padding:24px;background:#f8fafc'>
                    <div style='background:#0f766e;color:#fff;padding:18px;border-radius:12px 12px 0 0'>
                        {$logoHtml}
                        <h2 style='margin:0'>Verify your inquiry</h2>
                    </div>
                    <div style='background:#fff;padding:24px;border-radius:0 0 12px 12px'>
                        <p>Hello {$safeName},</p>
                        <p>Use this code to confirm your inquiry request:</p>
                        <p style='font-size:30px;font-weight:800;letter-spacing:6px;color:#0f172a'>{$safeOtp}</p>
                        <p>This verification code expires at {$safeExpiry}.</p>
                        <p style='margin-top:20px;color:#475569;font-size:14px;line-height:1.5'><strong>This is an automated email.</strong> Please submit inquiries through our website.</p>
                    </div>
                </div>";
            $this->mailer->AltBody = "Your inquiry verification code is {$otp}. It expires at {$expiryInPhilippineTime}. This is an automated email. Please submit inquiries through our website.";
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log('Password reset email failed: ' . $e->getMessage());
            $this->error = 'Email service cannot send right now. Check SMTP username and Gmail app password.';
            return false;
        }
    }

    public function sendInquiryReceivedConfirmation(string $recipientEmail, string $recipientName): bool {
        try {
            if ($this->error !== '') {
                return false;
            }

            $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Client', ENT_QUOTES, 'UTF-8');
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($recipientEmail);
            $logoPath = __DIR__ . '/../IMAGES/edge.jpg';
            $logoHtml = '';
            if (is_file($logoPath)) {
                $this->mailer->addEmbeddedImage($logoPath, 'edgeLogo');
                $logoHtml = "<img src='cid:edgeLogo' alt='Edge Automation' style='width:56px;height:56px;border-radius:12px;object-fit:cover;margin-bottom:12px'>";
            }
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Inquiry Received - ' . $this->config->get('APP_NAME');
            $this->mailer->Body = "
                <div style='font-family:Arial,sans-serif;max-width:560px;margin:auto;padding:24px;background:#f8fafc'>
                    <div style='background:#0f766e;color:#fff;padding:18px;border-radius:12px 12px 0 0'>
                        {$logoHtml}
                        <h2 style='margin:0'>Inquiry Received</h2>
                    </div>
                    <div style='background:#fff;padding:24px;border-radius:0 0 12px 12px;color:#0f172a;line-height:1.55'>
                        <p>Hello {$safeName},</p>
                        <p>We received your inquiry. Our Admin will contact you to confirm the inspection schedule.</p>
                        <p>Thank you,<br>Edge Automation</p>
                    </div>
                </div>";
            $this->mailer->AltBody = 'Hello ' . ($recipientName !== '' ? $recipientName : 'Client')
                . ".\n\nWe received your inquiry. Our Admin will contact you to confirm the inspection schedule.\n\nThank you,\nEdge Automation";
            $this->mailer->send();
            return true;
        } catch (Exception $exception) {
            error_log('Inquiry received email failed: ' . $exception->getMessage());
            $this->error = 'Email service cannot send right now.';
            return false;
        }
    }

    public function sendEngineerPasswordOtp(string $recipientEmail, string $recipientName, string $otp, int $expiryMinutes = 10): bool {
        try {
            if ($this->error !== '') {
                return false;
            }

            $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Engineer', ENT_QUOTES, 'UTF-8');
            $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($recipientEmail);
            $logoPath = __DIR__ . '/../IMAGES/edge.jpg';
            $logoHtml = '';
            if (is_file($logoPath)) {
                $this->mailer->addEmbeddedImage($logoPath, 'edgeLogo');
                $logoHtml = "<img src='cid:edgeLogo' alt='Edge Automation' style='width:56px;height:56px;border-radius:12px;object-fit:cover;margin-bottom:12px'>";
            }
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Password Change Verification Code - ' . $this->config->get('APP_NAME');
            $this->mailer->Body = "
                <div style='font-family:Arial,sans-serif;max-width:560px;margin:auto;padding:24px;background:#f8fafc'>
                    <div style='background:#166534;color:#fff;padding:18px;border-radius:12px 12px 0 0'>
                        {$logoHtml}
                        <h2 style='margin:0'>Verify password change</h2>
                    </div>
                    <div style='background:#fff;padding:24px;border-radius:0 0 12px 12px'>
                        <p>Hello {$safeName},</p>
                        <p>Use this code to continue changing your password:</p>
                        <p style='font-size:30px;font-weight:800;letter-spacing:6px;color:#0f172a'>{$safeOtp}</p>
                        <p>This code expires in {$expiryMinutes} minutes.</p>
                        <p>If this was not you, contact Super Admin immediately.</p>
                    </div>
                </div>";
            $this->mailer->AltBody = "Your password change code is {$otp}. It expires in {$expiryMinutes} minutes.";
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            $this->error = 'Email service cannot send right now. Check SMTP username and Gmail app password.';
            return false;
        }
    }

    public function sendInquiryQuotationLink(string $recipientEmail, string $recipientName, string $quotationNo, float $grandTotal, string $quotationLink, int $expiryDays = 14): bool {
        try {
            if ($this->error !== '') {
                return false;
            }

            $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Client', ENT_QUOTES, 'UTF-8');
            $safeQuotationNo = htmlspecialchars($quotationNo, ENT_QUOTES, 'UTF-8');
            $safeGrandTotal = htmlspecialchars('PHP ' . number_format($grandTotal, 2), ENT_QUOTES, 'UTF-8');
            $safeQuotationLink = htmlspecialchars($quotationLink, ENT_QUOTES, 'UTF-8');
            $safeExpiryDays = (int)$expiryDays;

            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($recipientEmail);

            $logoPath = __DIR__ . '/../IMAGES/edge.jpg';
            $logoHtml = '';
            if (is_file($logoPath)) {
                $this->mailer->addEmbeddedImage($logoPath, 'edgeLogo');
                $logoHtml = "<img src='cid:edgeLogo' alt='Edge Automation' style='width:56px;height:56px;border-radius:12px;object-fit:cover;margin-bottom:12px'>";
            }

            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Quotation Ready - ' . $this->config->get('APP_NAME');
            $this->mailer->Body = "
                <div style='font-family:Arial,sans-serif;max-width:560px;margin:auto;padding:24px;background:#f8fafc'>
                    <div style='background:#0f766e;color:#fff;padding:18px;border-radius:12px 12px 0 0'>
                        {$logoHtml}
                        <h2 style='margin:0'>Your quotation is ready</h2>
                    </div>
                    <div style='background:#fff;padding:24px;border-radius:0 0 12px 12px'>
                        <p>Hello {$safeName},</p>
                        <p>Your quotation <strong>{$safeQuotationNo}</strong> from Edge Automation is ready for review.</p>
                        <p>Grand Total: <strong>{$safeGrandTotal}</strong></p>
                        <p><a href='{$safeQuotationLink}' style='display:inline-block;background:#0f766e;color:#fff;padding:12px 18px;border-radius:10px;text-decoration:none;font-weight:700'>View Quotation</a></p>
                        <p>This secure link expires in {$safeExpiryDays} days.</p>
                        <p>If you did not request this quotation, please ignore this email.</p>
                    </div>
                </div>";
            $this->mailer->AltBody = "Your quotation {$quotationNo} is ready. Grand Total: PHP " . number_format($grandTotal, 2) . ". Open this secure link: {$quotationLink}. This link expires in {$expiryDays} days.";
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log('Inquiry quotation email failed: ' . $e->getMessage());
            $this->error = 'Email service cannot send right now. Check SMTP username and Gmail app password.';
            return false;
        }
    }

    public function sendInquiryQuotationFinalConfirmation(string $recipientEmail, string $recipientName, string $quotationNo, float $grandTotal, string $engineerName, string $inspectionSchedule, string $finalQuotationLink): bool {
        try {
            if ($this->error !== '') {
                return false;
            }

            $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Client', ENT_QUOTES, 'UTF-8');
            $safeQuotationNo = htmlspecialchars($quotationNo, ENT_QUOTES, 'UTF-8');
            $safeGrandTotal = htmlspecialchars('PHP ' . number_format($grandTotal, 2), ENT_QUOTES, 'UTF-8');
            $safeEngineerName = htmlspecialchars($engineerName, ENT_QUOTES, 'UTF-8');
            $safeInspectionSchedule = htmlspecialchars($inspectionSchedule, ENT_QUOTES, 'UTF-8');
            $safeFinalQuotationLink = htmlspecialchars($finalQuotationLink, ENT_QUOTES, 'UTF-8');

            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($recipientEmail);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Inspection Date Confirmed - Edge Automation';
            $this->mailer->Body = "
                <div style='font-family:Arial,sans-serif;max-width:560px;margin:auto;padding:24px;background:#f8fafc'>
                    <div style='background:#166534;color:#fff;padding:18px;border-radius:12px 12px 0 0'>
                        <h2 style='margin:0'>Your site inspection has been scheduled</h2>
                    </div>
                    <div style='background:#fff;padding:24px;border-radius:0 0 12px 12px'>
                        <p>Hello {$safeName},</p>
                        <p>Your Accepted Quotation <strong>{$safeQuotationNo}</strong> now has a confirmed inspection date.</p>
                        <p>Accepted Quotation Total: <strong>{$safeGrandTotal}</strong></p>
                        <p>Assigned Engineer: <strong>{$safeEngineerName}</strong></p>
                        <p>Inspection Date: <strong>{$safeInspectionSchedule}</strong></p>
                        <p><a href='{$safeFinalQuotationLink}' style='display:inline-block;background:#166534;color:#fff;padding:12px 18px;border-radius:10px;text-decoration:none;font-weight:700'>View Accepted Quotation</a></p>
                        <p>You may open the link and use Print / Save as PDF for your official copy.</p>
                    </div>
                </div>";
            $this->mailer->AltBody = "Your site inspection has been scheduled. Accepted Quotation {$quotationNo}. Accepted Quotation Total: PHP " . number_format($grandTotal, 2) . ". Engineer: {$engineerName}. Inspection: {$inspectionSchedule}. View your Accepted Quotation: {$finalQuotationLink}";
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log('Inquiry final quotation email failed: ' . $e->getMessage());
            $this->error = 'Email service cannot send right now. Check SMTP username and Gmail app password.';
            return false;
        }
    }

    public function sendInspectionReportAdminNotification(
        string $recipientEmail,
        string $adminName,
        string $engineerName,
        string $clientName,
        string $service,
        string $inspectionDate,
        string $reportUrl
    ): bool {
        try {
            if ($this->error !== '') {
                return false;
            }

            $safeAdmin = htmlspecialchars($adminName !== '' ? $adminName : 'Admin', ENT_QUOTES, 'UTF-8');
            $safeEngineer = htmlspecialchars($engineerName !== '' ? $engineerName : 'Engineer', ENT_QUOTES, 'UTF-8');
            $safeClient = htmlspecialchars($clientName !== '' ? $clientName : 'Client', ENT_QUOTES, 'UTF-8');
            $safeService = htmlspecialchars($service !== '' ? $service : 'Service request', ENT_QUOTES, 'UTF-8');
            $safeDate = htmlspecialchars($inspectionDate, ENT_QUOTES, 'UTF-8');
            $safeReportUrl = htmlspecialchars($reportUrl, ENT_QUOTES, 'UTF-8');

            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($recipientEmail);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Inspection Report Submitted - Edge Automation';
            $this->mailer->Body = "
                <div style='font-family:Arial,sans-serif;max-width:560px;margin:auto;padding:24px;background:#f8fafc'>
                    <div style='background:#166534;color:#fff;padding:18px;border-radius:12px 12px 0 0'>
                        <h2 style='margin:0'>Inspection Report Submitted</h2>
                    </div>
                    <div style='background:#fff;padding:24px;border-radius:0 0 12px 12px'>
                        <p>Hello {$safeAdmin},</p>
                        <p>{$safeEngineer} submitted a site inspection report for your review.</p>
                        <p><strong>Client:</strong> {$safeClient}</p>
                        <p><strong>Service:</strong> {$safeService}</p>
                        <p><strong>Inspection Date:</strong> {$safeDate}</p>
                        <p><a href='{$safeReportUrl}' style='display:inline-block;padding:10px 16px;border-radius:8px;background:#166534;color:#fff;text-decoration:none;font-weight:bold'>View Inspection Report</a></p>
                    </div>
                </div>";
            $this->mailer->AltBody = "Hello {$adminName},\n\n{$engineerName} submitted a site inspection report for your review.\nClient: {$clientName}\nService: {$service}\nInspection Date: {$inspectionDate}\nView Inspection Report: {$reportUrl}";
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log('Inspection report Admin notification email failed: ' . $e->getMessage());
            return false;
        }
    }

    public function sendInspectionScheduleClientNotification(
        string $recipientEmail,
        string $recipientName,
        string $service,
        string $inspectionSchedule,
        string $engineerName,
        string $siteAddress,
        string $siteNotes = '',
        string $scheduleLink = '',
        bool $isUpdated = false,
        string $rescheduleReason = ''
    ): bool {
        try {
            if ($this->error !== '') {
                return false;
            }

            $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Client', ENT_QUOTES, 'UTF-8');
            $safeService = htmlspecialchars($service, ENT_QUOTES, 'UTF-8');
            $safeSchedule = htmlspecialchars($inspectionSchedule, ENT_QUOTES, 'UTF-8');
            $safeEngineer = htmlspecialchars($engineerName, ENT_QUOTES, 'UTF-8');
            $safeAddress = htmlspecialchars($siteAddress, ENT_QUOTES, 'UTF-8');
            // Internal Site Notes are for Admin and the assigned Engineer only.
            $rescheduleReason = trim($rescheduleReason);
            $rescheduleReasonHtml = $rescheduleReason !== ''
                ? '<p><strong>Schedule Change Reason:</strong><br>' . nl2br(htmlspecialchars($rescheduleReason, ENT_QUOTES, 'UTF-8')) . '</p>'
                : '';
            $rescheduleReasonText = $rescheduleReason !== '' ? "\nSchedule Change Reason: {$rescheduleReason}" : '';
            $safeScheduleLink = htmlspecialchars($scheduleLink, ENT_QUOTES, 'UTF-8');
            $scheduleCta = $scheduleLink !== ''
                ? "<p><a href='{$safeScheduleLink}' style='display:inline-block;padding:10px 16px;border-radius:8px;background:#166534;color:#fff;text-decoration:none;font-weight:bold'>Review &amp; Respond</a></p>"
                : '';
            $isFinalScheduleNotice = $scheduleLink === '';
            $companyNumber = self::COMPANY_CONTACT_NUMBER;
            $companyEmail = self::COMPANY_CONTACT_EMAIL;
            $clientHeading = $isUpdated ? 'Site Inspection Schedule Updated' : 'Site Inspection Scheduled';
            $scheduleIntro = $isFinalScheduleNotice
                ? ($isUpdated
                    ? 'Your inspection schedule was updated. Please keep the new schedule for your reference.'
                    : 'Your site inspection has been scheduled. Please keep this schedule for your reference.')
                : ($isUpdated
                    ? 'Your inspection schedule has been updated. Please review the details and confirm the schedule or request a change.'
                    : 'Your site inspection has been scheduled. Please review the details and confirm the schedule or request a change.');

            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($recipientEmail);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $clientHeading . ' - Edge Automation';
            $this->mailer->Body = "
                <div style='font-family:Arial,sans-serif;max-width:560px;margin:auto;padding:24px;background:#f8fafc'>
                    <div style='background:#166534;color:#fff;padding:18px;border-radius:12px 12px 0 0'>
                        <h2 style='margin:0'>{$clientHeading}</h2>
                    </div>
                    <div style='background:#fff;padding:24px;border-radius:0 0 12px 12px'>
                        <p>Hello {$safeName},</p>
                        <p>{$scheduleIntro}</p>
                        <p><strong>Service:</strong> {$safeService}</p>
                        <p><strong>Inspection Date:</strong> {$safeSchedule}</p>
                        <p><strong>Assigned Engineer:</strong> {$safeEngineer}</p>
                        <p><strong>Site Address:</strong><br>{$safeAddress}</p>
                        {$rescheduleReasonHtml}
                        {$scheduleCta}
                        <p><strong>Need to change the schedule?</strong><br>
                        Call or text Edge Automation: <a href='tel:+639178789571'>{$companyNumber}</a><br>
                        Email: <a href='mailto:{$companyEmail}'>{$companyEmail}</a></p>
                    </div>
                </div>";
            $this->mailer->AltBody = "Hello {$recipientName},\n\n{$scheduleIntro}\nService: {$service}\nInspection Date: {$inspectionSchedule}\nAssigned Engineer: {$engineerName}\nSite Address: {$siteAddress}{$rescheduleReasonText}\n\nNeed to change the schedule? Call or text Edge Automation: {$companyNumber}. Email: {$companyEmail}.";
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log('Inspection client notification email failed: ' . $e->getMessage());
            $this->error = 'Email service cannot send right now.';
            return false;
        }
    }

    public function sendInspectionScheduleEngineerAssignment(
        string $recipientEmail,
        string $engineerName,
        string $clientName,
        string $service,
        string $inspectionSchedule,
        string $siteAddress,
        string $contactNumber,
        string $clientEmail,
        string $siteNotes = '',
        bool $isUpdated = false,
        string $inspectionUrl = '',
        string $rescheduleReason = '',
        bool $isReassignment = false
    ): bool {
        try {
            if ($this->error !== '') {
                return false;
            }

            $safeEngineer = htmlspecialchars($engineerName !== '' ? $engineerName : 'Engineer', ENT_QUOTES, 'UTF-8');
            $safeClient = htmlspecialchars($clientName, ENT_QUOTES, 'UTF-8');
            $safeService = htmlspecialchars($service, ENT_QUOTES, 'UTF-8');
            $safeSchedule = htmlspecialchars($inspectionSchedule, ENT_QUOTES, 'UTF-8');
            $safeAddress = htmlspecialchars($siteAddress, ENT_QUOTES, 'UTF-8');
            $safeContact = htmlspecialchars($contactNumber, ENT_QUOTES, 'UTF-8');
            $safeInspectionUrl = htmlspecialchars($inspectionUrl, ENT_QUOTES, 'UTF-8');
            $companyNumber = self::COMPANY_CONTACT_NUMBER;
            $companyEmail = self::COMPANY_CONTACT_EMAIL;
            $isNewAssignment = !$isUpdated || $isReassignment;
            $inspectionHeading = $isNewAssignment ? 'New Site Inspection Assignment' : 'Site Inspection Schedule Updated';
            $inspectionIntro = $isNewAssignment
                ? 'You have a new site inspection assignment. Open the inspection details for the full details.'
                : 'Your site inspection schedule has been updated. Open the inspection details to review the changes.';
            $inspectionCta = $inspectionUrl !== '' ? "<p><a href='{$safeInspectionUrl}' style='display:inline-block;padding:10px 16px;border-radius:8px;background:#166534;color:#fff;text-decoration:none;font-weight:bold'>View Inspection Details</a></p>" : '';

            $this->mailer->clearAddresses();
            $this->mailer->clearAttachments();
            $this->mailer->addAddress($recipientEmail);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = $inspectionHeading . ' - Edge Automation';
            $this->mailer->Body = "
                <div style='font-family:Arial,sans-serif;max-width:560px;margin:auto;padding:24px;background:#f8fafc'>
                    <div style='background:#166534;color:#fff;padding:18px;border-radius:12px 12px 0 0'>
                        <h2 style='margin:0'>{$inspectionHeading}</h2>
                    </div>
                    <div style='background:#fff;padding:24px;border-radius:0 0 12px 12px'>
                        <p>Hello {$safeEngineer},</p>
                        <p>{$inspectionIntro}</p>
                        <p><strong>Client:</strong> {$safeClient}</p>
                        <p><strong>Service:</strong> {$safeService}</p>
                        <p><strong>Inspection Date:</strong> {$safeSchedule}</p>
                        <p><strong>Site Address:</strong><br>{$safeAddress}</p>
                        <p><strong>Client Contact Number:</strong> {$safeContact}</p>
                        {$inspectionCta}
                        <p><strong>Need to change the schedule?</strong><br>
                        Contact Admin: <a href='tel:+639178789571'>{$companyNumber}</a><br>
                        Email: <a href='mailto:{$companyEmail}'>{$companyEmail}</a><br>
                        Admin will inform the Client.</p>
                    </div>
                </div>";
            $this->mailer->AltBody = "Hello {$engineerName},\n\n{$inspectionIntro}\nClient: {$clientName}\nService: {$service}\nInspection Date: {$inspectionSchedule}\nSite Address: {$siteAddress}\nClient Contact Number: {$contactNumber}\n\nNeed to change the schedule? Contact Admin: {$companyNumber}. Email: {$companyEmail}. Admin will inform the Client.";
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            error_log('Inspection engineer assignment email failed: ' . $e->getMessage());
            $this->error = 'Email service cannot send right now.';
            return false;
        }
    }

    /**
     * Get password reset email HTML template
     */
    private function getPasswordResetEmailTemplate($name, $resetLink, $expiryMinutes) {
        $appName = $this->config->get('APP_NAME');
        
        return "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; background: #f5f5f5; padding: 20px; }
                .header { background: #2c3e50; color: white; padding: 20px; text-align: center; }
                .content { background: white; padding: 30px; }
                .button { background: #3498db; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #7f8c8d; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>$appName</h1>
                </div>
                <div class='content'>
                    <h2>Password Reset Request</h2>
                    <p>Hello $name,</p>
                    <p>We received a request to reset your password. Click the button below to create a new password:</p>
                    <a href='$resetLink' class='button'>Reset Password</a>
                    <p>Or copy this link:</p>
                    <p><code>$resetLink</code></p>
                    <p><strong>This link expires in $expiryMinutes minutes.</strong></p>
                    <p>If you did not request this password reset, please ignore this email or contact support immediately.</p>
                    <hr>
                    <p style='font-size: 12px; color: #7f8c8d;'>This is an automated message, please do not reply.</p>
                </div>
                <div class='footer'>
                    <p>&copy; 2026 $appName. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    private function getAccountActivationEmailTemplate($name, $activationLink, $expiryMinutes) {
        $appName = htmlspecialchars((string)$this->config->get('APP_NAME'), ENT_QUOTES, 'UTF-8');
        $safeName = htmlspecialchars((string)$name, ENT_QUOTES, 'UTF-8');
        $safeLink = htmlspecialchars((string)$activationLink, ENT_QUOTES, 'UTF-8');

        return "
        <!DOCTYPE html>
        <html lang='en'>
        <body style='font-family:Segoe UI,Arial,sans-serif;background:#f5f5f5;padding:20px'>
            <div style='max-width:600px;margin:auto;background:#fff;padding:30px'>
                <h1 style='color:#166534'>$appName</h1>
                <h2>Activate your Client account</h2>
                <p>Hello $safeName,</p>
                <p>Your Client account is ready. Set your password to activate it.</p>
                <p><a href='$safeLink' style='display:inline-block;background:#16a34a;color:#fff;padding:12px 20px;border-radius:5px;text-decoration:none'>Activate Account</a></p>
                <p>This link expires in $expiryMinutes minutes and can be used once.</p>
                <p>If you did not expect this email, please contact Edge Automation.</p>
            </div>
        </body>
        </html>";
    }

    /**
     * Get account created email HTML template
     */
    private function getAccountCreatedEmailTemplate($name, $role) {
        $appName = $this->config->get('APP_NAME');
        $appUrl = $this->config->get('APP_URL');
        
        return "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; background: #f5f5f5; padding: 20px; }
                .header { background: #27ae60; color: white; padding: 20px; text-align: center; }
                .content { background: white; padding: 30px; }
                .button { background: #27ae60; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; display: inline-block; margin: 20px 0; }
                .footer { text-align: center; padding: 20px; color: #7f8c8d; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Welcome to $appName</h1>
                </div>
                <div class='content'>
                    <h2>Your Account Has Been Created</h2>
                    <p>Hello $name,</p>
                    <p>A Super Admin has created your account on $appName as a <strong>" . ucfirst(str_replace('_', ' ', $role)) . "</strong>.</p>
                    <p>You can now log in to the portal:</p>
                    <a href='$appUrl/LOGIN/php/login.php' class='button'>Go to Login</a>
                    <p>If you have any questions, please contact support.</p>
                    <hr>
                    <p style='font-size: 12px; color: #7f8c8d;'>This is an automated message, please do not reply.</p>
                </div>
                <div class='footer'>
                    <p>&copy; 2026 $appName. All rights reserved.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Get status change email HTML template
     */
    private function getStatusChangeEmailTemplate($name, $action) {
        $appName = $this->config->get('APP_NAME');
        
        return "
        <!DOCTYPE html>
        <html lang='en'>
        <head>
            <meta charset='UTF-8'>
            <meta name='viewport' content='width=device-width, initial-scale=1.0'>
            <style>
                body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; background: #f5f5f5; padding: 20px; }
                .header { background: #e74c3c; color: white; padding: 20px; text-align: center; }
                .content { background: white; padding: 30px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>$appName</h1>
                </div>
                <div class='content'>
                    <h2>Account Status Changed</h2>
                    <p>Hello $name,</p>
                    <p>Your account has been <strong>$action</strong>.</p>
                    <p>If you have any questions, please contact support.</p>
                </div>
            </div>
        </body>
        </html>
        ";
    }

    /**
     * Get last error
     */
    public function getError() {
        return $this->error;
    }
}
