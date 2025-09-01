/**
 * Stage Detection System JavaScript
 * Handles real-time camera capture and detection system control
 */

class StageDetectionSystem {
    constructor() {
        console.log('StageDetectionSystem constructor called');
        
        this.videoElement = null;
        this.canvasElement = null;
        this.stream = null;
        this.isRunning = false;
        this.detectionRunning = false;
        this.statusInterval = null;
        this.cameraInterval = null;
        this.stageDetectionInterval = null;
        
        console.log('Initializing elements...');
        this.initializeElements();
        
        console.log('Binding events...');
        this.bindEvents();
        
        console.log('Starting status polling...');
        this.startStatusPolling();
        
        // Ensure initial display consistency
        this.refreshDisplay();
        
        console.log('StageDetectionSystem initialization complete');
    }
    
    initializeElements() {
        this.videoElement = document.getElementById('stage-camera');
        this.canvasElement = document.getElementById('stage-canvas');
        this.statusElement = document.getElementById('detection-status');
        this.queueElement = document.getElementById('queue-status');
        this.controlsElement = document.getElementById('detection-controls');
        this.cameraSelect = document.getElementById('camera-select');
        
        console.log('Initializing elements:');
        console.log('  videoElement:', this.videoElement);
        console.log('  canvasElement:', this.canvasElement);
        console.log('  statusElement:', this.statusElement);
        console.log('  queueElement:', this.queueElement);
        console.log('  controlsElement:', this.controlsElement);
        
        if (!this.videoElement || !this.canvasElement) {
            console.error('Required elements not found');
            return;
        }
    }
    
    bindEvents() {
        console.log('Binding events...');
        
        // Start/Stop detection buttons
        const startBtn = document.getElementById('start-detection');
        const stopBtn = document.getElementById('stop-detection');
        const manualBtn = document.getElementById('manual-announce');
        const resetBtn = document.getElementById('reset-queue');
        
        console.log('Found buttons:');
        console.log('  startBtn:', startBtn);
        console.log('  stopBtn:', stopBtn);
        console.log('  manualBtn:', manualBtn);
        console.log('  resetBtn:', resetBtn);
        
        if (startBtn) {
            startBtn.addEventListener('click', () => this.startDetection());
            console.log('Start button event bound');
        } else {
            console.error('Start button not found!');
        }
        
        if (stopBtn) {
            stopBtn.addEventListener('click', () => this.stopDetection());
            console.log('Stop button event bound');
        } else {
            console.error('Stop button not found!');
        }
        
        if (manualBtn) {
            manualBtn.addEventListener('click', () => this.manualAnnounce());
            console.log('Manual button event bound');
        } else {
            console.error('Manual button not found!');
        }
        
        if (resetBtn) {
            resetBtn.addEventListener('click', () => this.resetQueue());
            console.log('Reset button event bound');
        } else {
            console.error('Reset button not found!');
        }
        
        // Camera controls
        const startCameraBtn = document.getElementById('start-camera');
        const stopCameraBtn = document.getElementById('stop-camera');
        const cameraSelect = this.cameraSelect;
        
        if (startCameraBtn) {
            startCameraBtn.addEventListener('click', () => this.startCamera());
            console.log('Start camera button event bound');
        } else {
            console.error('Start camera button not found!');
        }
        
        if (stopCameraBtn) {
            stopCameraBtn.addEventListener('click', () => this.stopCamera());
            console.log('Stop camera button event bound');
        } else {
            console.error('Stop camera button not found!');
        }

        if (cameraSelect) {
            cameraSelect.addEventListener('change', () => {
                // Restart camera with selected device
                this.startCamera();
            });
            // Populate on load
            this.populateCameraList();
        }
    }
    
    async startCamera() {
        try {
            if (this.stream) {
                this.stopCamera();
            }
            
            const constraints = { video: { width: { ideal: 960 }, height: { ideal: 480 } }, audio: false };
            const selectedDeviceId = this.cameraSelect && this.cameraSelect.value ? this.cameraSelect.value : null;
            if (selectedDeviceId) {
                constraints.video.deviceId = { exact: selectedDeviceId };
            } else {
                constraints.video.facingMode = 'environment';
            }

            this.stream = await navigator.mediaDevices.getUserMedia(constraints);
            
            this.videoElement.srcObject = this.stream;
            this.videoElement.play();
            
            // Start canvas capture
            this.startCanvasCapture();
            
            this.updateCameraStatus(true);
            this.showMessage('Camera started successfully', 'success');
            
        } catch (error) {
            console.error('Camera error:', error);
            this.showMessage('Failed to start camera: ' + error.message, 'error');
        }
    }

    async populateCameraList() {
        try {
            if (!navigator.mediaDevices || !navigator.mediaDevices.enumerateDevices) {
                return;
            }
            // Ensure permissions to get labels on some browsers
            try {
                await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
            } catch (e) {
                // ignore; user might have already granted
            }
            const devices = await navigator.mediaDevices.enumerateDevices();
            const videoInputs = devices.filter(d => d.kind === 'videoinput');
            if (!this.cameraSelect) return;
            // Preserve current selection
            const current = this.cameraSelect.value;
            this.cameraSelect.innerHTML = '';
            videoInputs.forEach((device, idx) => {
                const option = document.createElement('option');
                option.value = device.deviceId;
                option.textContent = device.label || `Camera ${idx + 1}`;
                this.cameraSelect.appendChild(option);
            });
            // Restore selection if possible
            if (current) {
                const found = Array.from(this.cameraSelect.options).some(o => o.value === current);
                if (found) this.cameraSelect.value = current;
            }
        } catch (err) {
            console.error('Failed to populate camera list:', err);
        }
    }
    
    stopCamera() {
        if (this.stream) {
            this.stream.getTracks().forEach(track => track.stop());
            this.stream = null;
        }
        
        if (this.videoElement) {
            this.videoElement.srcObject = null;
        }
        
        if (this.cameraInterval) {
            clearInterval(this.cameraInterval);
            this.cameraInterval = null;
        }
        
        this.updateCameraStatus(false);
        this.showMessage('Camera stopped', 'info');
    }
    
    startCanvasCapture() {
        if (this.cameraInterval) {
            clearInterval(this.cameraInterval);
        }
        
        this.cameraInterval = setInterval(() => {
            if (this.videoElement && this.canvasElement && this.videoElement.readyState === 4) {
                const ctx = this.canvasElement.getContext('2d');
                this.canvasElement.width = this.videoElement.videoWidth;
                this.canvasElement.height = this.videoElement.videoHeight;
                
                // Draw video frame to canvas
                ctx.drawImage(this.videoElement, 0, 0);
                
                // Add detection zone overlays
                this.drawDetectionZones(ctx);
            }
        }, 100); // 10 FPS for canvas updates
    }
    
    startStageDetectionCapture() {
        if (!this.detectionRunning) return;
        
        // Capture frames every 2 seconds for stage detection
        this.stageDetectionInterval = setInterval(async () => {
            if (this.videoElement && this.videoElement.readyState === 4) {
                try {
                    // Capture frame from canvas
                    const canvas = document.createElement('canvas');
                    const ctx = canvas.getContext('2d');
                    canvas.width = this.videoElement.videoWidth;
                    canvas.height = this.videoElement.videoHeight;
                    ctx.drawImage(this.videoElement, 0, 0);
                    
                    // Convert to blob and send to API
                    canvas.toBlob(async (blob) => {
                        const formData = new FormData();
                        formData.append('action', 'process_stage_frame');
                        formData.append('frame', blob, 'stage_frame.jpg');
                        
                        try {
                            const response = await fetch('api/stage_detection.php', {
                                method: 'POST',
                                body: formData
                            });
                            
                            const result = await response.json();
                            if (result.success) {
                                console.log('Stage detection result:', result.data);
                                
                                // Update status based on detection result
                                if (result.data.detection.graduate_announced) {
                                    this.showMessage(`Graduate announced: ${result.data.detection.announced_graduate.full_name}`, 'success');
                                    this.updateQueueStatus(); // Refresh queue display

                                    // Capture current frame and save as ceremony photo
                                    try {
                                        const snapCanvas = document.createElement('canvas');
                                        const sctx = snapCanvas.getContext('2d');
                                        snapCanvas.width = this.videoElement.videoWidth;
                                        snapCanvas.height = this.videoElement.videoHeight;
                                        sctx.drawImage(this.videoElement, 0, 0);
                                        const snapBlob = await new Promise(r => snapCanvas.toBlob(r, 'image/jpeg', 0.9));
                                        const saveForm = new FormData();
                                        saveForm.append('graduate_id', String(result.data.detection.announced_graduate.id));
                                        saveForm.append('frame', snapBlob, 'ceremony.jpg');
                                        const saveResp = await fetch('api/save_ceremony_photo.php', { method: 'POST', body: saveForm });
                                        const saveData = await saveResp.json();
                                        if (!saveData.success) {
                                            console.error('Failed to save ceremony photo:', saveData.message);
                                        } else {
                                            console.log('Ceremony photo saved at:', saveData.photo_path);
                                        }
                                    } catch (e) {
                                        console.error('Error capturing/uploading ceremony photo:', e);
                                    }
                                }
                            }
                        } catch (error) {
                            console.error('Stage detection API error:', error);
                        }
                    }, 'image/jpeg', 0.8);
                    
                } catch (error) {
                    console.error('Frame capture error:', error);
                }
            }
        }, 2000); // Capture every 2 seconds
    }
    
    drawDetectionZones(ctx) {
        if (!this.canvasElement) return;
        
        const width = this.canvasElement.width;
        const height = this.canvasElement.height;
        
        // Left zone (green)
        ctx.strokeStyle = '#00FF00';
        ctx.lineWidth = 3;
        ctx.strokeRect(0, 0, width / 3, height);
        ctx.fillStyle = '#00FF00';
        ctx.font = 'bold 24px Arial';
        ctx.fillText('LEFT', 10, 30);
        
        // Center zone (blue)
        ctx.strokeStyle = '#0000FF';
        ctx.strokeRect(width / 3, 0, width / 3, height);
        ctx.fillStyle = '#0000FF';
        ctx.fillText('CENTER', width / 3 + 10, 30);
        
        // Right zone (red)
        ctx.strokeStyle = '#FF0000';
        ctx.strokeRect(2 * width / 3, 0, width / 3, height);
        ctx.fillStyle = '#FF0000';
        ctx.fillText('RIGHT', 2 * width / 3 + 10, 30);
    }
    
    async startDetection() {
        try {
            console.log('Starting detection...');
            const response = await fetch('api/stage_detection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=start_detection'
            });
            
            const data = await response.json();
            console.log('Start detection response:', data);
            
            if (data.success) {
                this.detectionRunning = true;
                console.log('Detection started, updating status...');
                this.updateDetectionStatus();
                this.showMessage(data.message, 'success');
                
                // Force refresh the display to ensure consistency
                this.refreshDisplay();
                
                // Start frame capture for stage detection
                this.startStageDetectionCapture();
            } else {
                this.showMessage(data.message, 'error');
            }
            
        } catch (error) {
            console.error('Start detection error:', error);
            this.showMessage('Failed to start detection system', 'error');
        }
    }
    
    async stopDetection() {
        try {
            const response = await fetch('api/stage_detection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=stop_detection'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.detectionRunning = false;
                this.updateDetectionStatus();
                this.showMessage(data.message, 'success');
                
                // Force refresh the display to ensure consistency
                this.refreshDisplay();
                
                // Stop stage detection capture
                if (this.stageDetectionInterval) {
                    clearInterval(this.stageDetectionInterval);
                    this.stageDetectionInterval = null;
                }
            } else {
                this.showMessage(data.message, 'error');
            }
            
        } catch (error) {
            console.error('Stop detection error:', error);
            this.showMessage('Failed to stop detection system', 'error');
        }
    }
    
    async manualAnnounce() {
        try {
            const response = await fetch('api/stage_detection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=manual_announce'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showMessage(data.message, 'success');
                this.updateQueueStatus(); // Refresh queue display
                
                // The SSE system will automatically detect the database change
                // and send real-time updates to all connected display pages
                console.log('Manual announcement successful - display pages will update automatically');
            } else {
                this.showMessage(data.message, 'error');
            }
            
        } catch (error) {
            console.error('Manual announce error:', error);
            this.showMessage('Failed to manually announce graduate', 'error');
        }
    }
    
    async resetQueue() {
        if (!confirm('Are you sure you want to reset the entire queue? This will clear all queued and announced graduates.')) {
            return;
        }
        
        try {
            const response = await fetch('api/stage_detection.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=reset_queue&confirm=true'
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showMessage(data.message, 'success');
                this.updateQueueStatus(); // Refresh queue display
            } else {
                this.showMessage(data.message, 'error');
            }
            
        } catch (error) {
            console.error('Reset queue error:', error);
            this.showMessage('Failed to reset queue', 'error');
        }
    }
    
    startStatusPolling() {
        this.statusInterval = setInterval(() => {
            this.updateStatus();
        }, 2000); // Update every 2 seconds
    }
    
    async updateStatus() {
        try {
            const response = await fetch('api/stage_detection.php?action=get_status');
            const data = await response.json();
            console.log('Status update response:', data);
            
            if (data.success) {
                // Don't override JavaScript state - only update queue info
                // this.updateDetectionStatus(data.data.detection_running); // REMOVED
                this.updateQueueStatus(data.data);
            }
        } catch (error) {
            console.error('Status update error:', error);
        }
    }
    
    updateDetectionStatus(running = null) {
        if (running !== null) {
            this.detectionRunning = running;
        }
        
        console.log('Updating detection status - current state:', this.detectionRunning);
        
        if (this.statusElement) {
            this.statusElement.textContent = this.detectionRunning ? 'Running' : 'Stopped';
            this.statusElement.className = this.detectionRunning ? 'status-running' : 'status-stopped';
            console.log('Status element updated to:', this.detectionRunning ? 'Running' : 'Stopped');
        }
        
        if (this.controlsElement) {
            const startBtn = document.getElementById('start-detection');
            const stopBtn = document.getElementById('stop-detection');
            
            if (startBtn) {
                startBtn.disabled = this.detectionRunning;
                console.log('Start button disabled:', this.detectionRunning);
            }
            if (stopBtn) {
                stopBtn.disabled = !this.detectionRunning;
                console.log('Stop button disabled:', !this.detectionRunning);
            }
        }
        
        // Update status text to match current state
        const statusText = document.getElementById('detection-status-text');
        if (statusText) {
            if (this.detectionRunning) {
                statusText.textContent = 'Detection system is running. Frames are being processed every 2 seconds.';
            } else {
                statusText.textContent = 'Detection system is currently stopped. Click "Start Detection" to begin automatic sequencing.';
            }
            console.log('Status text updated to match detection state');
        }
    }
    
    updateQueueStatus(data = null) {
        if (!this.queueElement) return;
        
        if (!data) {
            // Fetch queue data if not provided
            fetch('api/stage_detection.php?action=get_queue')
                .then(response => response.json())
                .then(result => {
                    if (result.success) {
                        this.renderQueue(result.data);
                    }
                })
                .catch(error => console.error('Queue fetch error:', error));
            return;
        }
        
        this.renderQueue(data);
    }
    
    renderQueue(data) {
        if (!this.queueElement) return;
        
        let html = `
            <div class="queue-summary">
                <div class="queue-item">
                    <span class="label">In Queue:</span>
                    <span class="value">${data.queue_count || 0}</span>
                </div>
                <div class="queue-item">
                    <span class="label">Announced:</span>
                    <span class="value">${data.announced_count || 0}</span>
                </div>
            </div>
        `;
        
        if (data.next_graduate) {
            html += `
                <div class="next-graduate">
                    <h4>Next in Queue:</h4>
                    <div class="graduate-info">
                        <strong>${data.next_graduate.full_name}</strong><br>
                        <small>${data.next_graduate.student_id} - ${data.next_graduate.program}</small>
                    </div>
                </div>
            `;
        }
        
        if (data.current_announcement && data.current_announcement.id) {
            html += `
                <div class="current-announcement">
                    <h4>Currently Announced:</h4>
                    <div class="graduate-info">
                        <strong>${data.current_announcement.full_name}</strong><br>
                        <small>${data.current_announcement.student_id} - ${data.current_announcement.program}</small>
                    </div>
                </div>
            `;
        }
        
        this.queueElement.innerHTML = html;
    }
    
    updateCameraStatus(running) {
        const startBtn = document.getElementById('start-camera');
        const stopBtn = document.getElementById('stop-camera');
        
        if (startBtn) startBtn.disabled = running;
        if (stopBtn) stopBtn.disabled = !running;
    }
    
    showMessage(message, type = 'info') {
        // Create or update message element
        let messageElement = document.getElementById('message-display');
        if (!messageElement) {
            messageElement = document.createElement('div');
            messageElement.id = 'message-display';
            messageElement.className = 'message-display';
            document.body.appendChild(messageElement);
        }
        
        messageElement.textContent = message;
        messageElement.className = `message-display message-${type}`;
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            if (messageElement) {
                messageElement.remove();
            }
        }, 5000);
    }
    
    refreshDisplay() {
        // Force refresh all display elements to ensure consistency
        console.log('Refreshing display - detection state:', this.detectionRunning);
        
        // Update status display
        if (this.statusElement) {
            this.statusElement.textContent = this.detectionRunning ? 'Running' : 'Stopped';
            this.statusElement.className = this.detectionRunning ? 'status-running' : 'status-stopped';
        }
        
        // Update button states
        if (this.controlsElement) {
            const startBtn = document.getElementById('start-detection');
            const stopBtn = document.getElementById('stop-detection');
            
            if (startBtn) startBtn.disabled = this.detectionRunning;
            if (stopBtn) stopBtn.disabled = !this.detectionRunning;
        }
        
        // Update status text
        const statusText = document.getElementById('detection-status-text');
        if (statusText) {
            if (this.detectionRunning) {
                statusText.textContent = 'Detection system is running. Frames are being processed every 2 seconds.';
            } else {
                statusText.textContent = 'Detection system is currently stopped. Click "Start Detection" to begin automatic sequencing.';
            }
        }
        
        console.log('Display refresh complete');
    }
    
    destroy() {
        if (this.statusInterval) {
            clearInterval(this.statusInterval);
        }
        
        if (this.cameraInterval) {
            clearInterval(this.cameraInterval);
        }
        
        if (this.stageDetectionInterval) {
            clearInterval(this.stageDetectionInterval);
        }
        
        this.stopCamera();
    }
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    window.stageDetectionSystem = new StageDetectionSystem();
});

// Cleanup on page unload
window.addEventListener('beforeunload', () => {
    if (window.stageDetectionSystem) {
        window.stageDetectionSystem.destroy();
    }
});
