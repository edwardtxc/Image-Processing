# Graduation Ceremony Management System

A comprehensive web-based system for managing graduation ceremonies with real-time stage detection, student registration, attendance tracking, and audience interaction features.

## 🎓 Overview

This system provides an end-to-end solution for graduation ceremonies, combining computer vision technology with web-based management tools. It features automated stage detection for graduate sequencing, multi-modal verification (face recognition, fingerprint, QR codes), and real-time audience engagement.

## ✨ Key Features

### 🎯 Core Functionality
- **Student Registration**: Comprehensive graduate registration with photo capture and biometric data
- **Session Management**: Multi-session support for different graduation ceremonies
- **Real-time Stage Detection**: Computer vision-powered graduate sequencing and announcement
- **Multi-modal Verification**: Face recognition, fingerprint scanning, and QR code verification
- **Queue Management**: Automated graduate queuing and announcement system
- **Live Display System**: Real-time graduate announcements and ceremony photos

### 🖼️ Advanced Features
- **Audience Reactions**: Real-time emoji reactions with QR code integration
- **Photo Processing**: Automated ceremony photo capture and batch processing
- **Email Notifications**: PHPMailer integration for graduate notifications
- **Metrics Tracking**: Comprehensive verification and attendance analytics
- **Text-to-Speech**: Audio announcements for graduate names

## 🏗️ System Architecture

```
📁 Root Directory
├── 📁 api/              # REST API endpoints
├── 📁 pages/            # Web interface pages
├── 📁 lib/              # Core libraries and database
├── 📁 templates/        # HTML templates
├── 📁 integrations/     # Python integration scripts
├── 📁 data/             # Database and file storage
├── 📁 uploads/          # User uploaded files
├── 📁 css/              # Stylesheets
├── 📁 js/               # JavaScript files
├── 📁 qrcodes/          # Generated QR codes
└── 📁 logs/             # System logs
```

## 🛠️ Technology Stack

### Backend
- **PHP 8+**: Main web application framework
- **SQLite**: Lightweight database for data persistence
- **Python 3.8+**: Computer vision and biometric processing
- **Composer**: PHP dependency management

### Frontend
- **HTML5/CSS3**: Modern web interface
- **JavaScript**: Interactive client-side functionality
- **Bootstrap**: Responsive UI framework

### Computer Vision & AI
- **OpenCV**: Real-time computer vision processing
- **NumPy**: Numerical computations
- **scikit-image**: Advanced image processing
- **Pillow**: Image manipulation

### Additional Libraries
- **PHPMailer**: Email notification system
- **pyzbar**: QR code scanning
- **qrcode**: QR code generation
- **pyttsx3**: Text-to-speech functionality

## 📋 Prerequisites

### System Requirements
- **PHP**: 8.0 or higher with SQLite extension
- **Python**: 3.8 or higher
- **Composer**: PHP dependency manager
- **Web Server**: Apache/Nginx (or built-in PHP server for development)
- **Camera**: USB camera for stage detection (optional)

### Hardware Recommendations
- **Minimum**: 4GB RAM, dual-core processor
- **Recommended**: 8GB RAM, quad-core processor, dedicated GPU for CV processing
- **Camera**: HD USB camera for stage detection
- **Storage**: 2GB+ available space for photos and data


## 🎮 Usage

### 1. Start Web Server
```bash
# Using PHP built-in server (development)
php -S localhost:8080

# Or configure your web server to serve the project root

```

## 📖 User Guide

### For Administrators

#### Session Management
1. Create new graduation sessions
2. Rename or delete existing sessions
3. Switch between active sessions

#### Student Registration
1. Navigate to Registration page
2. Fill in student details (ID, name, program, email, CGPA)
3. Capture or upload student photo
4. Optional: Capture fingerprint biometrics
5. System generates unique QR code for each student

#### Queue Management
1. View registered students
2. Manually queue students for ceremony
3. Monitor announcement status
4. Verify student identities using multiple methods

### For Graduates

#### Verification Process
1. **QR Code**: Scan generated QR code for instant verification
2. **Face Recognition**: Use webcam for facial verification
3. **Fingerprint**: Use fingerprint scanner if available
4. **Manual**: Administrator manual verification

#### Ceremony Process
1. Register before ceremony
2. Verify identity at entrance
3. Join queue when ready
4. Proceed through stage zones for automatic detection
5. Receive ceremony photos via email

### For Audience

#### Reaction System
1. Scan QR code displayed during ceremony
2. Select emoji reactions
3. View real-time reaction feed
4. Participate in live ceremony engagement


## 🔍 API Endpoints

### Core APIs
- `GET /api/current.php` - Get current graduate announcement
- `POST /api/queue_student.php` - Add student to ceremony queue
- `POST /api/scan_and_verify.php` - QR code verification
- `POST /api/identify_and_queue.php` - Face recognition verification
- `POST /api/verify_fingerprint.php` - Fingerprint verification

### Stage Detection
- `GET /api/stage_detection.php` - Stage detection status
- `POST /api/notify_graduation.php` - Manual graduation trigger

### Reactions
- `POST /api/submit_reaction.php` - Submit audience reaction
- `GET /api/get_reactions.php` - Fetch recent reactions

### Photo Management
- `POST /api/save_ceremony_photo.php` - Save ceremony photo
- `POST /api/capture_face.php` - Capture face photo

## 🧪 Testing

### Manual Testing
1. **Registration Flow**: Register test students with photos
2. **Verification**: Test all verification methods (QR, face, fingerprint)
3. **Stage Detection**: Test camera detection with movement
4. **Queue Management**: Verify automatic queuing and announcements
5. **Reactions**: Test audience reaction system


## 📁 File Structure Details

```
├── index.php                    # Main application entry point
├── stage_detection.py          # Real-time stage detection system
├── composer.json               # PHP dependencies
├── requirements.txt            # Python dependencies
│
├── 📁 api/                     # REST API endpoints
│   ├── scan_and_verify.php     # QR code verification
│   ├── verify_fingerprint.php  # Fingerprint verification
│   ├── identify_and_queue.php  # Face recognition
│   ├── stage_detection.php     # Stage detection API
│   ├── submit_reaction.php     # Audience reactions
│   └── tts_server.py           # Text-to-speech service
│
├── 📁 pages/                   # Web interface pages
│   ├── register.php            # Student registration
│   ├── queue.php               # Queue management
│   ├── display.php             # Live ceremony display
│   ├── attendance.php          # Attendance tracking
│   └── reports.php             # Analytics and reports
│
├── 📁 lib/                     # Core system libraries
│   ├── db.php                  # Database functions
│   ├── email_service.php       # Email notifications
│   └── email_config.php        # Email configuration
│
├── 📁 integrations/            # Python integration scripts
│   ├── face_recognition_validator.py
│   ├── fingerprint_verification.py
│   ├── generate_qr.py
│   └── decode_qr.py
│
└── 📁 templates/               # HTML templates
    ├── header.php              # Common header
    └── footer.php              # Common footer
```


## 🎉 Acknowledgments

- OpenCV community for computer vision libraries
- PHPMailer team for email functionality
- Contributors to various open-source dependencies
- TARUMT for project requirements and specifications
