<?php
/**
 * Email Service for sending emails via Zoho SMTP
 * 
 * This class handles sending emails with attachments (QR codes) to graduates.
 */

require_once __DIR__ . '/email_config.php';

class EmailService {
    private $config;
    
    public function __construct() {
        $this->config = get_email_config();
    }
    
    /**
     * Send welcome email with QR code to graduate
     */
    public function sendWelcomeEmail($graduateData, $qrCodePath) {
        $configs = get_alternative_email_configs();
        $lastError = '';
        
        // Try each configuration until one works
        foreach ($configs as $config) {
            try {
                $result = $this->sendEmailWithConfig($graduateData, $qrCodePath, $config);
                if ($result['success']) {
                    return $result;
                }
                $lastError = $result['message'];
            } catch (Exception $e) {
                $lastError = $e->getMessage();
            }
        }
        
        // If all configurations failed, return the last error
        return [
            'success' => false,
            'message' => 'All SMTP configurations failed. Last error: ' . $lastError
        ];
    }
    
    /**
     * Send email using a specific configuration
     */
    private function sendEmailWithConfig($graduateData, $qrCodePath, $config) {
        try {
            // Check if PHPMailer is available
            if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                // Try to include PHPMailer if it exists
                $phpmailerPath = __DIR__ . '/../vendor/autoload.php';
                if (file_exists($phpmailerPath)) {
                    require_once $phpmailerPath;
                } else {
                    throw new Exception('PHPMailer not found. Please install it via Composer or download manually.');
                }
            }
            
            $mail = new PHPMailer\PHPMailer\PHPMailer(true);
            
            // Server settings
            $mail->isSMTP();
            $mail->Host = $config['host'];
            $mail->SMTPAuth = true;
            $mail->Username = $config['username'];
            $mail->Password = $config['password'];
            $mail->SMTPSecure = $config['secure'];
            $mail->Port = $config['port'];
            
            // Set timeout to avoid hanging
            $mail->Timeout = 30;
            $mail->SMTPKeepAlive = false;
            
            // Debug mode (set to 0 in production)
            $mail->SMTPDebug = 0;
            
            // Recipients
            $mail->setFrom($config['from_email'], $config['from_name']);
            $mail->addAddress($graduateData['email'], $graduateData['full_name']);
            
            // Content
            $mail->isHTML(true);
            $mail->Subject = WELCOME_EMAIL_SUBJECT;
            
            // Prepare email body with graduate data
            $body = $this->prepareEmailBody($graduateData);
            $mail->Body = $body;
            $mail->AltBody = strip_tags($body);
            
            // Attach QR code
            if (file_exists($qrCodePath)) {
                $mail->addAttachment($qrCodePath, 'qr_code.png');
            }
            
            // Send email
            $mail->send();
            
            return [
                'success' => true,
                'message' => 'Email sent successfully to ' . $graduateData['email'] . ' using ' . $config['name']
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Failed to send email using ' . $config['name'] . ': ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Prepare email body with graduate information
     */
    private function prepareEmailBody($graduateData) {
        $body = WELCOME_EMAIL_BODY;
        
        // Replace placeholders with actual data
        $replacements = [
            '{full_name}' => $graduateData['full_name'],
            '{student_id}' => $graduateData['student_id'],
            '{program}' => $graduateData['program'],
            '{cgpa}' => $graduateData['cgpa'] ?? 'N/A',
            '{category}' => $graduateData['category'] ?? 'N/A'
        ];
        
        foreach ($replacements as $placeholder => $value) {
            $body = str_replace($placeholder, $value, $body);
        }
        
        // Convert to HTML
        $body = nl2br($body);
        
        // Add some basic styling
        $htmlBody = '
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Welcome to Graduation</title>
            <style>
                body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #f8f9fa; padding: 20px; text-align: center; border-radius: 5px; }
                .content { padding: 20px; }
                .info-box { background-color: #e9ecef; padding: 15px; border-radius: 5px; margin: 20px 0; }
                .footer { text-align: center; margin-top: 30px; padding-top: 20px; border-top: 1px solid #dee2e6; color: #6c757d; }
            </style>
        </head>
        <body>
            <div class="container">
                <div class="header">
                    <h1>🎓 Welcome to Graduation!</h1>
                </div>
                <div class="content">
                    ' . $body . '
                    <div class="info-box">
                        <strong>Important:</strong> Your QR code is attached to this email. 
                        Please save it and present it during the ceremony for attendance verification.
                    </div>
                </div>
                <div class="footer">
                    <p>This is an automated message from the Graduation System.</p>
                    <p>If you have any questions, please contact the graduation committee.</p>
                </div>
            </div>
        </body>
        </html>';
        
        return $htmlBody;
    }
    
    /**
     * Test email configuration
     */
    public function testConnection() {
        $configs = get_alternative_email_configs();
        $results = [];
        
        foreach ($configs as $config) {
            try {
                if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                    $results[] = [
                        'config' => $config['name'],
                        'success' => false,
                        'message' => 'PHPMailer not available'
                    ];
                    continue;
                }
                
                $mail = new PHPMailer\PHPMailer\PHPMailer(true);
                
                // Server settings
                $mail->isSMTP();
                $mail->Host = $config['host'];
                $mail->SMTPAuth = true;
                $mail->Username = $config['username'];
                $mail->Password = $config['password'];
                $mail->SMTPSecure = $config['secure'];
                $mail->Port = $config['port'];
                
                // Set timeout
                $mail->Timeout = 15;
                $mail->SMTPKeepAlive = false;
                
                // Test connection
                $mail->smtpConnect();
                $mail->smtpClose();
                
                $results[] = [
                    'config' => $config['name'],
                    'success' => true,
                    'message' => 'SMTP connection successful'
                ];
                
                // If one works, we can return success
                return [
                    'success' => true,
                    'message' => 'SMTP connection successful using ' . $config['name']
                ];
                
            } catch (Exception $e) {
                $results[] = [
                    'config' => $config['name'],
                    'success' => false,
                    'message' => 'SMTP connection failed: ' . $e->getMessage()
                ];
            }
        }
        
        // If all failed, return detailed results
        $errorMessages = [];
        foreach ($results as $result) {
            if (!$result['success']) {
                $errorMessages[] = $result['config'] . ': ' . $result['message'];
            }
        }
        
        return [
            'success' => false,
            'message' => 'All SMTP configurations failed. ' . implode('; ', $errorMessages),
            'details' => $results
        ];
    }
}
