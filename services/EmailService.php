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
            $this->mailer->Host = $mailConfig['host'];
            $this->mailer->SMTPAuth = true;
            $this->mailer->Username = $mailConfig['username'];
            $this->mailer->Password = $mailConfig['password'];
            // Gamitin ang encryption na nasa config para hindi hardcoded.
            $this->mailer->SMTPSecure = $mailConfig['encryption'] === 'ssl'
                ? PHPMailer::ENCRYPTION_SMTPS
                : PHPMailer::ENCRYPTION_STARTTLS;
            $this->mailer->Port = $mailConfig['port'];
            
            $this->mailer->setFrom($mailConfig['from_address'], $mailConfig['from_name']);
        } catch (Exception $e) {
            $this->error = "SMTP Configuration Error: " . $e->getMessage();
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

    public function sendInquiryOtp(string $recipientEmail, string $recipientName, string $otp, int $expiryMinutes = 10): bool {
        try {
            if ($this->error !== '') {
                return false;
            }

            $safeName = htmlspecialchars($recipientName !== '' ? $recipientName : 'Client', ENT_QUOTES, 'UTF-8');
            $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
            $this->mailer->clearAddresses();
            $this->mailer->addAddress($recipientEmail);
            $this->mailer->isHTML(true);
            $this->mailer->Subject = 'Inquiry Verification Code - ' . $this->config->get('APP_NAME');
            $this->mailer->Body = "
                <div style='font-family:Arial,sans-serif;max-width:560px;margin:auto;padding:24px;background:#f8fafc'>
                    <div style='background:#0f766e;color:#fff;padding:18px;border-radius:12px 12px 0 0'>
                        <h2 style='margin:0'>Verify your inquiry</h2>
                    </div>
                    <div style='background:#fff;padding:24px;border-radius:0 0 12px 12px'>
                        <p>Hello {$safeName},</p>
                        <p>Use this code to confirm your inquiry request:</p>
                        <p style='font-size:30px;font-weight:800;letter-spacing:6px;color:#0f172a'>{$safeOtp}</p>
                        <p>This code expires in {$expiryMinutes} minutes.</p>
                    </div>
                </div>";
            $this->mailer->AltBody = "Your inquiry verification code is {$otp}. It expires in {$expiryMinutes} minutes.";
            $this->mailer->send();
            return true;
        } catch (Exception $e) {
            $this->error = 'Email service cannot send right now. Check SMTP username and Gmail app password.';
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
