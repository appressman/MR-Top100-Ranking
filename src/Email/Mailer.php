<?php

namespace MastersRadio\Top100\Email;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use MastersRadio\Top100\Logger;

/**
 * Email sender using PHPMailer with Gmail SMTP
 */
class Mailer
{
    private Logger $logger;
    private string $smtpHost;
    private int $smtpPort;
    private string $username;
    private string $password;
    private string $fromEmail;
    private string $fromName;

    public function __construct(
        Logger $logger,
        string $smtpHost,
        int $smtpPort,
        string $username,
        string $password,
        string $fromEmail,
        string $fromName
    ) {
        $this->logger = $logger;
        $this->smtpHost = $smtpHost;
        $this->smtpPort = $smtpPort;
        $this->username = $username;
        $this->password = $password;
        $this->fromEmail = $fromEmail;
        $this->fromName = $fromName;
    }

    /**
     * Send success email with CSV attachment
     */
    public function sendSuccessEmail(
        string $to,
        string $toName,
        string $subject,
        string $htmlBody,
        string $csvPath
    ): bool {
        $this->logger->info("Sending success email to: {$to}");

        try {
            $mail = $this->createMailer();
            
            // Set recipient
            $mail->addAddress($to, $toName);
            
            // Set subject and body
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);
            
            // Attach CSV
            if (file_exists($csvPath)) {
                $mail->addAttachment($csvPath, basename($csvPath));
            } else {
                $this->logger->warning("CSV file not found for attachment: {$csvPath}");
            }
            
            // Send
            $result = $mail->send();
            
            if ($result) {
                $this->logger->info("Email sent successfully to: {$to}");
                return true;
            } else {
                $this->logger->error("Failed to send email: " . $mail->ErrorInfo);
                return false;
            }
            
        } catch (PHPMailerException $e) {
            $this->logger->error("PHPMailer exception: " . $e->getMessage());
            return false;
        } catch (\Exception $e) {
            $this->logger->error("Email sending failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Send failure alert email
     */
    public function sendFailureEmail(
        string $to,
        string $subject,
        string $htmlBody
    ): bool {
        $this->logger->info("Sending failure alert to: {$to}");

        try {
            $mail = $this->createMailer();
            
            // Set priority to high
            $mail->Priority = 1;
            
            // Set recipient
            $mail->addAddress($to);
            
            // Set subject and body
            $mail->Subject = $subject;
            $mail->Body = $htmlBody;
            $mail->AltBody = strip_tags($htmlBody);
            
            // Send
            $result = $mail->send();
            
            if ($result) {
                $this->logger->info("Failure alert sent to: {$to}");
                return true;
            } else {
                $this->logger->error("Failed to send failure alert: " . $mail->ErrorInfo);
                return false;
            }
            
        } catch (\Exception $e) {
            $this->logger->error("Failure alert sending failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create configured PHPMailer instance
     */
    private function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->isSMTP();
        $mail->Host = $this->smtpHost;
        $mail->Port = $this->smtpPort;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->SMTPAuth = true;
        
        // Authentication
        $mail->Username = $this->username;
        $mail->Password = $this->password;
        
        // From address
        $mail->setFrom($this->fromEmail, $this->fromName);
        
        // Email format
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        
        return $mail;
    }
}
