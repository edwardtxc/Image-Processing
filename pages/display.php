<?php
// Prevent any output before headers
ob_start();

// Set proper content type and prevent caching
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Prevent any default PHP output
error_reporting(0);
ini_set('display_errors', 0);

require_once __DIR__ . '/../lib/db.php';

// Clear any output buffer
ob_clean();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title></title>
    <style>
* {
    margin: 0;
    padding: 0;
    box-sizing: border-box;
}

html, body {
    margin: 0;
    padding: 0;
    height: 100%;
    width: 100%;
    overflow: hidden;
}

body {
    font-family: 'Times New Roman', serif;
    background: linear-gradient(135deg, #0f1419 0%, #1a2332 50%, #2d3748 100%);
    height: 100vh;
    width: 100vw;
    overflow: hidden;
    position: relative;
}

/* Ensure no headers or titles are displayed */
h1, h2, h3, h4, h5, h6, .header, .title, .subtitle {
    display: none !important;
    visibility: hidden !important;
    height: 0 !important;
    width: 0 !important;
    margin: 0 !important;
    padding: 0 !important;
    overflow: hidden !important;
}

.graduation-container {
    position: relative;
    height: 100vh;
    width: 100vw;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 0;
    margin: 0;
    top: 0;
    left: 0;
}

.graduation-bg {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: 
        radial-gradient(circle at 25% 75%, rgba(212, 175, 55, 0.08) 0%, transparent 50%),
        radial-gradient(circle at 75% 25%, rgba(212, 175, 55, 0.08) 0%, transparent 50%),
        radial-gradient(circle at 50% 50%, rgba(255, 255, 255, 0.03) 0%, transparent 50%);
    z-index: 1;
}

.graduation-card {
    background: linear-gradient(145deg, rgba(255, 255, 255, 0.98), rgba(248, 250, 252, 0.95));
    backdrop-filter: blur(20px);
    border-radius: 0;
    padding: 40px;
    text-align: center;
    box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(212, 175, 55, 0.2);
    border: 3px solid #d4af37;
    position: relative;
    z-index: 2;
    max-width: 700px;
    width: 85%;
    max-height: 95vh;
    animation: fadeInUp 1.2s ease-out;
    display: flex;
    flex-direction: column;
    justify-content: center;
    margin: 0;
}

.graduation-card::before {
    content: '';
    position: absolute;
    top: 0;
    left: 0;
    right: 0;
    height: 4px;
    background: linear-gradient(90deg, #d4af37, #f4d03f, #d4af37);
}

.graduate-info {
    margin: 20px 0;
}

.graduate-name {
    font-size: 3.2rem;
    font-weight: bold;
    color: #1a202c;
    margin-bottom: 18px;
    text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
    animation: formalGlow 3s ease-in-out infinite alternate;
    line-height: 1.1;
    letter-spacing: 1px;
    font-family: 'Times New Roman', serif;
}

.graduate-id {
    font-size: 1.6rem;
    color: #2d3748;
    margin-bottom: 15px;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.graduate-program {
    font-size: 1.5rem;
    color: #4a5568;
    margin-bottom: 20px;
    font-weight: 500;
    letter-spacing: 0.3px;
}

.achievement-section {
    margin: 25px 0;
    padding: 20px;
    background: linear-gradient(135deg, rgba(212, 175, 55, 0.1), rgba(212, 175, 55, 0.05));
    border: 2px solid rgba(212, 175, 55, 0.3);
    border-radius: 8px;
    position: relative;
}

.achievement-section::before {
    content: '🏆';
    position: absolute;
    top: -12px;
    left: 50%;
    transform: translateX(-50%);
    background: #fff;
    padding: 4px 12px;
    font-size: 1.2rem;
    border: 2px solid #d4af37;
    border-radius: 20px;
}

.achievement-title {
    font-size: 1.4rem;
    color: #d4af37;
    font-weight: bold;
    margin-bottom: 12px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
}

.achievement-details {
    font-size: 1.2rem;
    color: #2d3748;
    font-weight: 600;
    line-height: 1.4;
}

.graduate-photo {
    margin: 25px 0;
}

.graduate-photo img {
    max-height: 280px;
    border-radius: 0;
    box-shadow: 0 12px 25px rgba(0, 0, 0, 0.3);
    border: 3px solid #d4af37;
    transition: transform 0.4s ease, box-shadow 0.4s ease;
}

.graduate-photo img:hover {
    transform: scale(1.02);
    box-shadow: 0 15px 30px rgba(0, 0, 0, 0.4);
}

.waiting-message {
    font-size: 2.2rem;
    color: #4a5568;
    font-style: italic;
    animation: formalPulse 3s ease-in-out infinite;
    margin: 35px 0;
    font-weight: 500;
}

.graduation-decoration {
    position: absolute;
    width: 120px;
    height: 120px;
    background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect x="20" y="20" width="60" height="60" fill="none" stroke="%23d4af37" stroke-width="2" stroke-dasharray="8,4"/><rect x="30" y="30" width="40" height="40" fill="none" stroke="%23d4af37" stroke-width="1"/></svg>') no-repeat center;
    opacity: 0.2;
    z-index: 1;
}

.decoration-1 { top: 5%; left: 5%; animation: formalRotate 30s linear infinite; }
.decoration-2 { top: 15%; right: 10%; animation: formalRotate 35s linear infinite reverse; }
.decoration-3 { bottom: 10%; left: 15%; animation: formalRotate 40s linear infinite; }
.decoration-4 { bottom: 20%; right: 5%; animation: formalRotate 45s linear infinite reverse; }

@keyframes fadeInUp {
    from {
        opacity: 0;
        transform: translateY(40px);
    }
    to {
        opacity: 1;
        transform: translateY(0);
    }
}

@keyframes formalGlow {
    from {
        text-shadow: 2px 2px 4px rgba(0, 0, 0, 0.1);
    }
    to {
        text-shadow: 2px 2px 15px rgba(26, 32, 44, 0.3), 0 0 25px rgba(26, 32, 44, 0.2);
    }
}

@keyframes formalPulse {
    0%, 100% { opacity: 0.7; }
    50% { opacity: 1; }
}

@keyframes formalRotate {
    from { transform: rotate(0deg); }
    to { transform: rotate(360deg); }
}

/* Entrance animation for new graduates */
.animate-entrance {
    animation: graduateEntrance 1.5s ease-out forwards;
}

@keyframes graduateEntrance {
    0% {
        opacity: 0;
        transform: translateY(50px) scale(0.9);
    }
    50% {
        opacity: 0.7;
        transform: translateY(-10px) scale(1.05);
    }
    100% {
        opacity: 1;
        transform: translateY(0) scale(1);
    }
}

/* Pulse animation for waiting message */
.waiting-message {
    animation: formalPulse 3s ease-in-out infinite;
}

@media (max-width: 768px) {
    .graduation-card {
        padding: 30px 20px;
        width: 90%;
        max-height: 80vh;
    }
    
    .graduate-name {
        font-size: 2.5rem;
    }
    
    .graduate-id {
        font-size: 1.4rem;
    }
    
    .graduate-program {
        font-size: 1.3rem;
    }
    
    .achievement-title {
        font-size: 1.2rem;
    }
    
    .achievement-details {
        font-size: 1.1rem;
    }
    
    .graduate-photo img {
        max-height: 220px;
    }
    
    .waiting-message {
        font-size: 1.8rem;
    }
}

@media (max-height: 600px) {
    .graduation-card {
        padding: 25px;
        max-height: 85vh;
    }
    
    .graduate-name {
        font-size: 2.8rem;
        margin-bottom: 15px;
    }
    
    .graduate-photo img {
        max-height: 180px;
    }
    
    .achievement-section {
        margin: 20px 0;
        padding: 15px;
    }
}

/* QR Code Section Styles */
.qr-section {
    position: absolute;
    top: 20px;
    right: 20px;
    z-index: 10;
    background: rgba(255, 255, 255, 0.95);
    backdrop-filter: blur(10px);
    border-radius: 15px;
    padding: 20px;
    box-shadow: 0 10px 30px rgba(0, 0, 0, 0.2);
    border: 2px solid #d4af37;
    max-width: 200px;
    text-align: center;
}

.qr-title {
    font-size: 1.2rem;
    font-weight: bold;
    color: #d4af37;
    margin-bottom: 15px;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.qr-code {
    margin-bottom: 15px;
}

.qr-code img {
    max-width: 100%;
    height: auto;
    border-radius: 8px;
    border: 2px solid #e9ecef;
}

.qr-instruction {
    font-size: 0.9rem;
    color: #666;
    line-height: 1.3;
}

/* Flying Emoji Styles */
.flying-emoji {
    position: fixed;
    font-size: 2rem;
    z-index: 1000;
    pointer-events: none;
    user-select: none;
    opacity: 0;
    transform: scale(0.5);
    transition: none;
}

.flying-emoji.fly-up {
    animation: flyUpAndFade 3.5s ease-out forwards;
}

@keyframes flyUpAndFade {
    0% {
        opacity: 0;
        transform: translateY(0) scale(0.5) rotate(0deg);
    }
    10% {
        opacity: 1;
        transform: translateY(-20px) scale(1.2) rotate(5deg);
    }
    20% {
        opacity: 1;
        transform: translateY(-50px) scale(1.1) rotate(-3deg);
    }
    40% {
        opacity: 1;
        transform: translateY(-150px) scale(1) rotate(2deg);
    }
    60% {
        opacity: 0.8;
        transform: translateY(-300px) scale(0.9) rotate(-1deg);
    }
    80% {
        opacity: 0.4;
        transform: translateY(-500px) scale(0.7) rotate(3deg);
    }
    100% {
        opacity: 0;
        transform: translateY(-800px) scale(0.5) rotate(0deg);
    }
}

/* Reactions Overlay Styles */
.reactions-overlay {
    position: absolute;
    bottom: 20px;
    left: 20px;
    right: 20px;
    z-index: 10;
    pointer-events: none;
}

.reactions-container {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    justify-content: center;
    align-items: center;
    min-height: 60px;
}

/* Mobile responsive adjustments */
@media (max-width: 768px) {
    .qr-section {
        top: 10px;
        right: 10px;
        padding: 15px;
        max-width: 150px;
    }
    
    .qr-title {
        font-size: 1rem;
    }
    
    .reactions-overlay {
        bottom: 10px;
        left: 10px;
        right: 10px;
    }
    
    .flying-emoji {
        font-size: 1.5rem;
    }
}
</style>
</head>
<body>
    <div class="graduation-container">
        <div class="graduation-bg"></div>
        
        <!-- Decorative elements -->
        <div class="graduation-decoration decoration-1"></div>
        <div class="graduation-decoration decoration-2"></div>
        <div class="graduation-decoration decoration-3"></div>
        <div class="graduation-decoration decoration-4"></div>
        
        <!-- QR Code Section -->
        <div class="qr-section" id="qrSection" style="display: none;">
            <div class="qr-container">
                <div class="qr-title">Scan to React!</div>
                <div class="qr-code" id="qrCode"></div>
                <div class="qr-instruction">Scan this QR code with your phone to send reactions</div>
            </div>
        </div>
        
        <!-- Reactions Display -->
        <div class="reactions-overlay" id="reactionsOverlay">
            <div class="reactions-container" id="reactionsContainer"></div>
        </div>
        
        <div class="graduation-card">
            <div id="content">
                <div class="waiting-message">Preparing for the next graduate...</div>
            </div>
        </div>
    </div>

<script>
// Real-time graduation updates using Server-Sent Events
class GraduationDisplay {
    constructor() {
        this.eventSource = null;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.reconnectDelay = 1000;
        this.contentElement = document.getElementById('content');
        this.qrSection = document.getElementById('qrSection');
        this.qrCodeElement = document.getElementById('qrCode');
        this.reactionsContainer = document.getElementById('reactionsContainer');
        this.currentGraduateId = null;
        this.reactionsInterval = null;
        this.lastAnnouncedId = null;
        this.ttsMode = 'browser';
        
        // Configure URLs based on access method
        this.configureUrls();
        this.configureTtsMode();
        
        this.init();
    }
    
    configureUrls() {
        // Detect if we're accessing via main index.php or direct file access
        const isDirectAccess = window.location.pathname.includes('/pages/');
        const baseUrl = window.location.origin;
        
        if (isDirectAccess) {
            // Direct file access: /pages/display.php
            this.sseUrl = baseUrl + '/api/graduation_updates.php';
            this.apiUrl = baseUrl + '/api/current.php';
            this.qrApiUrl = baseUrl + '/api/generate_reaction_qr.php';
            this.reactionsApiUrl = baseUrl + '/api/get_reactions.php';
            console.log('Direct access detected, using absolute URLs');
        } else {
            // Main index.php access: /?page=stage
            this.sseUrl = baseUrl + '/api/graduation_updates.php';
            this.apiUrl = baseUrl + '/api/current.php';
            this.qrApiUrl = baseUrl + '/api/generate_reaction_qr.php';
            this.reactionsApiUrl = baseUrl + '/api/get_reactions.php';
            console.log('Main index access detected, using absolute URLs');
        }
        
        console.log('SSE URL:', this.sseUrl);
        console.log('API URL:', this.apiUrl);
        console.log('QR API URL:', this.qrApiUrl);
        console.log('Reactions API URL:', this.reactionsApiUrl);
    }
    
    configureTtsMode() {
        try {
            const qs = new URLSearchParams(window.location.search);
            const fromQuery = (qs.get('tts_mode') || '').toLowerCase();
            const fromStorage = (window.localStorage.getItem('ttsMode') || '').toLowerCase();
            const mode = fromQuery || fromStorage || 'browser';
            this.ttsMode = (mode === 'server') ? 'server' : 'browser';
            if (fromQuery) {
                window.localStorage.setItem('ttsMode', this.ttsMode);
            }
            console.log('TTS mode:', this.ttsMode);
        } catch (_) {
            this.ttsMode = 'browser';
        }
    }
    
    init() {
        // Remove any headers if accessed directly
        this.removeHeaders();
        
        this.connectSSE();
        this.setupAudio();
        this.startReactionsPolling();
        
        // Generate QR code immediately when page loads
        this.generateQRCode();
    }
    
    removeHeaders() {
        // If accessed directly (not through main index), remove any visible headers
        if (window.location.pathname.includes('/pages/')) {
            // Hide any header elements that might be visible
            const headers = document.querySelectorAll('header, .header, .navbar, .nav, .top-bar');
            headers.forEach(header => {
                header.style.display = 'none';
            });
            
            // Also hide any page title or breadcrumbs
            const titles = document.querySelectorAll('.page-title, .breadcrumb, .page-header');
            titles.forEach(title => {
                title.style.display = 'none';
            });
            
            console.log('Headers removed for direct access');
        }
    }
    
    connectSSE() {
        try {
            // Close existing connection if any
            if (this.eventSource) {
                this.eventSource.close();
            }
            
            // Create new SSE connection using configured URL
            console.log('Connecting to SSE endpoint:', this.sseUrl);
            this.eventSource = new EventSource(this.sseUrl);
            
            // Connection established
            this.eventSource.onopen = (event) => {
                console.log('SSE connection established');
                this.reconnectAttempts = 0;
            };
            
            // Listen for graduation announcements
            this.eventSource.addEventListener('graduation_announced', (event) => {
                const data = JSON.parse(event.data);
                console.log('Graduation announced:', data);
                this.displayGraduate(data.graduate);
                this.playAnnouncementSound();
            });
            
            // Listen for graduation cleared
            this.eventSource.addEventListener('graduation_cleared', (event) => {
                const data = JSON.parse(event.data);
                console.log('Graduation cleared:', data);
                this.showWaitingMessage();
            });
            
            // Listen for general updates
            this.eventSource.addEventListener('update', (event) => {
                const data = JSON.parse(event.data);
                console.log('Update received:', data);
                if (data.type === 'graduation_update') {
                    this.displayGraduate(data.data);
                }
            });
            
            // Listen for connection events
            this.eventSource.addEventListener('connected', (event) => {
                const data = JSON.parse(event.data);
                console.log('Connected to SSE:', data.message);
            });
            
            // Listen for errors
            this.eventSource.addEventListener('error', (event) => {
                console.error('SSE error:', event);
                this.handleConnectionError();
            });
            
            // Listen for disconnect
            this.eventSource.addEventListener('disconnected', (event) => {
                const data = JSON.parse(event.data);
                console.log('Disconnected from SSE:', data.message);
                this.handleConnectionError();
            });
            
        } catch (error) {
            console.error('Failed to establish SSE connection:', error);
            this.handleConnectionError();
        }
    }
    
    handleConnectionError() {
        if (this.reconnectAttempts < this.maxReconnectAttempts) {
            this.reconnectAttempts++;
            console.log(`SSE connection failed, attempting reconnect ${this.reconnectAttempts}/${this.maxReconnectAttempts} in ${this.reconnectDelay}ms`);
            
            setTimeout(() => {
                this.connectSSE();
            }, this.reconnectDelay);
            
            // Exponential backoff
            this.reconnectDelay = Math.min(this.reconnectDelay * 2, 10000);
        } else {
            console.error('Max SSE reconnection attempts reached, falling back to polling');
            this.fallbackToPolling();
        }
    }
    
    fallbackToPolling() {
        // Fallback to the original polling method if SSE fails
        this.poll();
    }
    
    async poll() {
        try {
            const res = await fetch(this.apiUrl, { cache: 'no-store' });
        const data = await res.json();
            
            if (data && data.graduate) {
                this.displayGraduate(data.graduate);
            } else {
                this.showWaitingMessage();
            }
        } catch (e) {
            console.error('Polling error:', e);
        } finally {
            setTimeout(() => this.poll(), 2000);
        }
    }
    
        displayGraduate(graduate) {
        if (!this.contentElement) return;
        
        const g = graduate;
        
        // Prevent unnecessary updates if the same graduate is already displayed
        if (this.currentGraduateId === g.id) {
            return;
        }
        
        this.currentGraduateId = g.id;
            
        // Determine achievement based on database data
        let achievementTitle = '';
        let achievementDetails = '';
        
        if (g.cgpa && g.category) {
            // Use CGPA and category from database
            if (g.cgpa >= 3.7) {
                achievementTitle = 'First Class Honours';
                achievementDetails = `CGPA: ${g.cgpa.toFixed(2)} - ${g.category}`;
            } else if (g.cgpa >= 3.3) {
                achievementTitle = 'Second Class Upper';
                achievementDetails = `CGPA: ${g.cgpa.toFixed(2)} - ${g.category}`;
            } else if (g.cgpa >= 3.0) {
                achievementTitle = 'Second Class Lower';
                achievementDetails = `CGPA: ${g.cgpa.toFixed(2)} - ${g.category}`;
            } else {
                achievementTitle = 'Pass';
                achievementDetails = `CGPA: ${g.cgpa.toFixed(2)} - ${g.category}`;
            }
        } else if (g.category) {
            // Use category if available
            achievementTitle = g.category;
            achievementDetails = 'Academic Achievement';
        } else {
            // Default achievement
            achievementTitle = 'Graduate';
            achievementDetails = 'Successfully Completed Program';
        }
        
        // Add entrance animation class
        this.contentElement.innerHTML = `
            <div class="graduate-info animate-entrance">
                    <div class="graduate-name">${g.full_name}</div>
                    <div class="graduate-id">Student ID: ${g.student_id}</div>
                    <div class="graduate-program">${g.program}</div>
                    
                    <div class="achievement-section">
                        <div class="achievement-title">${achievementTitle}</div>
                        <div class="achievement-details">${achievementDetails}</div>
                    </div>
                    
                    ${g.photo_path ? `<div class="graduate-photo"><img src="${g.photo_path}" alt="Graduate Photo" onerror="this.style.display='none'"></div>` : ''}
                </div>
            `;
        
        // Trigger entrance animation
        setTimeout(() => {
            const graduateInfo = this.contentElement.querySelector('.graduate-info');
            if (graduateInfo) {
                graduateInfo.classList.add('animate-entrance');
            }
        }, 100);
        
        // Generate QR code for reactions
        this.generateQRCode();
        
        // Start reactions polling for this graduate
        this.startReactionsPolling();

        // Announce only when the graduate actually changes
        if (g && g.id && this.lastAnnouncedId !== g.id) {
            this.announceGraduate(g);
            this.lastAnnouncedId = g.id;
        }
    }
    
    showWaitingMessage() {
        if (!this.contentElement) return;
        
        this.contentElement.innerHTML = '<div class="waiting-message">Preparing for the next graduate...</div>';
        
        // Always show QR code, even when no graduate is displayed
        this.generateQRCode();
        
        // Clear reactions when no graduate is displayed
        this.clearReactions();
    }
    
    // QR Code Generation
    async generateQRCode() {
        try {
            console.log('Generating QR code...');
            const response = await fetch(this.qrApiUrl);
            const data = await response.json();
            console.log('QR API response:', data);
            
            if (data.success && data.qr_code) {
                console.log('QR code generated successfully:', data.qr_code.url);
                this.qrCodeElement.innerHTML = `<img src="${data.qr_code.url}" alt="QR Code for Reactions">`;
                this.qrSection.style.display = 'block';
            } else {
                console.log('No QR code generated:', data.message);
                this.hideQRCode();
            }
        } catch (error) {
            console.error('Failed to generate QR code:', error);
            this.hideQRCode();
        }
    }
    
    hideQRCode() {
        this.qrSection.style.display = 'none';
        this.qrCodeElement.innerHTML = '';
    }
    
    // Reactions Polling
    startReactionsPolling() {
        // Clear existing interval
        if (this.reactionsInterval) {
            clearInterval(this.reactionsInterval);
        }
        
        // Start polling for reactions every 2 seconds
        this.reactionsInterval = setInterval(() => {
            this.fetchReactions();
        }, 2000);
    }
    
    async fetchReactions() {
        try {
            const response = await fetch(this.reactionsApiUrl);
            const data = await response.json();
            
            if (data.success && data.reactions) {
                this.displayReactions(data.reactions);
            } else {
                this.clearReactions();
            }
        } catch (error) {
            console.error('Failed to fetch reactions:', error);
        }
    }
    
    displayReactions(reactions) {
        if (!this.reactionsContainer) return;
        
        // Display each reaction type with flying effect (only one per reaction type)
        reactions.forEach(reaction => {
            // Create only one flying emoji per reaction type
            this.createFlyingEmoji(reaction.emoji);
        });
    }
    
    createFlyingEmoji(emoji) {
        // Create flying emoji element
        const flyingEmoji = document.createElement('div');
        flyingEmoji.className = 'flying-emoji';
        flyingEmoji.textContent = emoji;
        
        // Random starting position at bottom
        const startX = Math.random() * (window.innerWidth - 50);
        const startY = window.innerHeight + 50;
        
        flyingEmoji.style.left = startX + 'px';
        flyingEmoji.style.top = startY + 'px';
        
        // Add to body for full screen coverage
        document.body.appendChild(flyingEmoji);
        
        // Trigger flying animation
        setTimeout(() => {
            flyingEmoji.classList.add('fly-up');
        }, 50);
        
        // Remove element after animation
        setTimeout(() => {
            if (flyingEmoji.parentNode) {
                flyingEmoji.parentNode.removeChild(flyingEmoji);
            }
        }, 4000);
    }
    
    clearReactions() {
        if (this.reactionsContainer) {
            this.reactionsContainer.innerHTML = '';
        }
    }
    
    setupAudio() {
        try {
            this.audioContext = new (window.AudioContext || window.webkitAudioContext)();
            
            // Create a subtle background ambiance
            this.createAmbiance();
            
            // Create ambiance every 15 seconds
            setInterval(() => this.createAmbiance(), 15000);
            
        } catch (error) {
            console.log('Audio not supported or blocked:', error);
        }
    }
    
    createAmbiance() {
        if (!this.audioContext) return;
        
        try {
            const oscillator = this.audioContext.createOscillator();
            const gainNode = this.audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(this.audioContext.destination);
            
            oscillator.frequency.setValueAtTime(220, this.audioContext.currentTime);
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0.008, this.audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.001, this.audioContext.currentTime + 2);
            
            oscillator.start(this.audioContext.currentTime);
            oscillator.stop(this.audioContext.currentTime + 2);
        } catch (error) {
            console.log('Ambiance creation failed:', error);
        }
    }
    
    playAnnouncementSound() {
        if (!this.audioContext) return;
        
        try {
            // Play a celebratory sound for new announcements
            const oscillator = this.audioContext.createOscillator();
            const gainNode = this.audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(this.audioContext.destination);
            
            // Play a pleasant chord
            oscillator.frequency.setValueAtTime(440, this.audioContext.currentTime); // A4
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0.01, this.audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.001, this.audioContext.currentTime + 1);
            
            oscillator.start(this.audioContext.currentTime);
            oscillator.stop(this.audioContext.currentTime + 1);
            
            // Add a second note for harmony
            setTimeout(() => {
                const oscillator2 = this.audioContext.createOscillator();
                const gainNode2 = this.audioContext.createGain();
                
                oscillator2.connect(gainNode2);
                gainNode2.connect(this.audioContext.destination);
                
                oscillator2.frequency.setValueAtTime(554, this.audioContext.currentTime); // C#5
                oscillator2.type = 'sine';
                
                gainNode2.gain.setValueAtTime(0.008, this.audioContext.currentTime);
                gainNode2.gain.exponentialRampToValueAtTime(0.001, this.audioContext.currentTime + 0.8);
                
                oscillator2.start(this.audioContext.currentTime);
                oscillator2.stop(this.audioContext.currentTime + 0.8);
            }, 200);
            
        } catch (error) {
            console.log('Announcement sound failed:', error);
        }
    }
    
    // Text-to-Speech integration (Browser first, then Python service)
    announceGraduate(graduate) {
        if (this.ttsMode === 'browser') {
            this.webSpeechSpeak(graduate);
            return;
        }
        this.callPythonTTS(graduate).catch(err => {
            console.warn('Python TTS failed, falling back to Web Speech:', err);
            this.webSpeechSpeak(graduate);
        });
    }
    
    async callPythonTTS(graduate) {
        // Dynamically determine TTS base URL
        const host = window.location.hostname;
        const configuredBase = (function() {
            const qs = new URLSearchParams(window.location.search);
            return qs.get('tts_base') || window.localStorage.getItem('ttsBaseUrl') || '';
        })();
        const base = configuredBase
            || ((host === 'localhost' || host === '127.0.0.1') ? 'http://127.0.0.1:5111' : `http://${host}:5111`);
        const url = base + '/speak';

        const payload = {
            full_name: graduate.full_name || '',
            program: graduate.program || '',
            student_id: graduate.student_id || ''
        };

        // Add a short timeout so we quickly fall back to Web Speech on failure
        const controller = new AbortController();
        const t = setTimeout(() => controller.abort(), 2000);
        try {
            const res = await fetch(url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                signal: controller.signal
            });
            if (!res.ok) {
                const txt = await res.text().catch(() => '');
                throw new Error('TTS HTTP error: ' + res.status + ' ' + txt);
            }
            const data = await res.json().catch(() => ({}));
            if (!data.success) {
                throw new Error('TTS service responded with failure');
            }
        } finally {
            clearTimeout(t);
        }
    }
    
    async webSpeechSpeak(graduate) {
        try {
            if (!('speechSynthesis' in window)) return;
            const synth = window.speechSynthesis;

            // Ensure voices are loaded
            const voices = await this.ensureVoicesLoaded();

            const compose = () => {
                const name = graduate.full_name || '';
                const program = graduate.program ? ('Program ' + graduate.program) : '';
                const sid = graduate.student_id ? ('ID Pelajar ' + graduate.student_id) : '';
                const parts = [];
                if (name) parts.push(name);
                if (program) parts.push(program);
                if (sid) parts.push(sid);
                parts.push('Tahniah!');
                return 'Sila beri tepukan kepada graduan seterusnya: ' + parts.join(', ');
            };

            const utterance = new SpeechSynthesisUtterance(compose());

            // Prefer Malay voice if available, else female, else default
            let chosen = voices.find(v => (v.lang || '').toLowerCase().startsWith('ms'))
                || voices.find(v => /malay|malaysia/i.test(v.name || ''))
                || voices.find(v => /female|zira|zira desktop/i.test(v.name || ''))
                || null;
            if (chosen) {
                utterance.voice = chosen;
                utterance.lang = chosen.lang || 'ms-MY';
            } else {
                utterance.lang = 'ms-MY';
            }

            utterance.rate = 0.95;
            utterance.volume = 1.0;

            // Avoid queue buildup
            if (synth.speaking || synth.pending) {
                synth.cancel();
            }
            synth.speak(utterance);
        } catch (e) {
            console.warn('Web Speech failed:', e);
        }
    }

    ensureVoicesLoaded() {
        return new Promise(resolve => {
            const synth = window.speechSynthesis;
            let voices = synth.getVoices();
            if (voices && voices.length) {
                resolve(voices);
                return;
            }
            const onChange = () => {
                voices = synth.getVoices();
                if (voices && voices.length) {
                    synth.removeEventListener('voiceschanged', onChange);
                    resolve(voices);
                }
            };
            synth.addEventListener('voiceschanged', onChange);
            // Fallback timeout
            setTimeout(() => {
                synth.removeEventListener('voiceschanged', onChange);
                resolve(synth.getVoices());
            }, 1500);
        });
    }

    // Backwards-compatible name used by legacy call sites
    webSpeechFallback(graduate) {
        this.webSpeechSpeak(graduate);
    }
    
    destroy() {
        if (this.eventSource) {
            this.eventSource.close();
        }
        if (this.reactionsInterval) {
            clearInterval(this.reactionsInterval);
        }
    }
}

// Initialize the graduation ceremony
document.addEventListener('DOMContentLoaded', function() {
    window.graduationDisplay = new GraduationDisplay();
});

// Cleanup on page unload
window.addEventListener('beforeunload', function() {
    if (window.graduationDisplay) {
        window.graduationDisplay.destroy();
    }
});
</script>
</body>
</html>

<?php
// Flush the output buffer
ob_end_flush();
?>


