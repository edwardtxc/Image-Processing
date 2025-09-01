<?php
/**
 * Email Configuration for Zoho Mail
 * 
 * This file contains the configuration for sending emails via Zoho SMTP.
 * Please update the email and app password with your actual Zoho credentials.
 */

// Zoho Mail Configuration
define('ZOHO_SMTP_HOST', 'smtp.zoho.com');
define('ZOHO_SMTP_PORT', 587);
define('ZOHO_SMTP_SECURE', 'tls');

// Alternative SMTP configurations to try if the main one fails
define('ZOHO_SMTP_ALT1_HOST', 'smtp.zoho.com');
define('ZOHO_SMTP_ALT1_PORT', 465);
define('ZOHO_SMTP_ALT1_SECURE', 'ssl');

define('ZOHO_SMTP_ALT2_HOST', 'smtp.zoho.com');
define('ZOHO_SMTP_ALT2_PORT', 25);
define('ZOHO_SMTP_ALT2_SECURE', 'tls');

// Your Zoho email credentials (you need to fill these in)
define('ZOHO_EMAIL', 'emailver645@zohomail.com'); // Replace with your actual Zoho email
define('ZOHO_APP_PASSWORD', 'gxta87nUTdHa'); // Replace with your actual app password

// Email settings
define('EMAIL_FROM_NAME', 'Graduation System');
define('EMAIL_SUBJECT_PREFIX', '[Graduation System] ');

// Email templates
define('WELCOME_EMAIL_SUBJECT', 'Welcome to Graduation - Your QR Code is Ready');
define('WELCOME_EMAIL_BODY', '
Dear {full_name},

Welcome to the graduation ceremony! Your registration has been completed successfully.

Student ID: {student_id}
Program: {program}
CGPA: {cgpa}
Category: {category}

Your QR code is attached to this email. Please present this QR code during the ceremony for attendance and verification.

If you have any questions, please contact the graduation committee.

Best regards,
Graduation Committee
');

// Function to get email configuration
function get_email_config() {
    return [
        'host' => ZOHO_SMTP_HOST,
        'port' => ZOHO_SMTP_PORT,
        'secure' => ZOHO_SMTP_SECURE,
        'username' => ZOHO_EMAIL,
        'password' => ZOHO_APP_PASSWORD,
        'from_name' => EMAIL_FROM_NAME,
        'from_email' => ZOHO_EMAIL
    ];
}

// Function to get alternative email configurations
function get_alternative_email_configs() {
    return [
        [
            'name' => 'Primary (TLS 587)',
            'host' => ZOHO_SMTP_HOST,
            'port' => ZOHO_SMTP_PORT,
            'secure' => ZOHO_SMTP_SECURE,
            'username' => ZOHO_EMAIL,
            'password' => ZOHO_APP_PASSWORD,
            'from_name' => EMAIL_FROM_NAME,
            'from_email' => ZOHO_EMAIL
        ],
        [
            'name' => 'Alternative 1 (SSL 465)',
            'host' => ZOHO_SMTP_ALT1_HOST,
            'port' => ZOHO_SMTP_ALT1_PORT,
            'secure' => ZOHO_SMTP_ALT1_SECURE,
            'username' => ZOHO_EMAIL,
            'password' => ZOHO_APP_PASSWORD,
            'from_name' => EMAIL_FROM_NAME,
            'from_email' => ZOHO_EMAIL
        ],
        [
            'name' => 'Alternative 2 (TLS 25)',
            'host' => ZOHO_SMTP_ALT2_HOST,
            'port' => ZOHO_SMTP_ALT2_PORT,
            'secure' => ZOHO_SMTP_ALT2_SECURE,
            'username' => ZOHO_EMAIL,
            'password' => ZOHO_APP_PASSWORD,
            'from_name' => EMAIL_FROM_NAME,
            'from_email' => ZOHO_EMAIL
        ]
    ];
}
