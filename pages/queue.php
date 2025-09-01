<?php
require_once __DIR__ . '/../lib/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$db = get_db();
$currentSessionId = isset($_SESSION['current_session_id']) ? (int)$_SESSION['current_session_id'] : 0;
$currentSessionName = '';

if ($currentSessionId > 0) {
    $stmt = $db->prepare('SELECT name FROM sessions WHERE id = ?');
    $stmt->execute([$currentSessionId]);
    $currentSessionName = $stmt->fetchColumn() ?: '';
}

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'queue_next') {
    try {
        $db = get_db();
        $db->beginTransaction();
        // Only queue graduates from current session
        $row = $db->prepare("SELECT id FROM graduates WHERE session_id = ? AND face_verified_at IS NOT NULL AND queued_at IS NULL ORDER BY face_verified_at ASC LIMIT 1")
                  ->execute([$currentSessionId])->fetch(PDO::FETCH_ASSOC);
        if (!$row) { throw new RuntimeException('No verified graduates waiting in current session.'); }
        $db->prepare("UPDATE graduates SET queued_at = datetime('now') WHERE id = ?")
           ->execute([$row['id']]);
        $db->commit();
        $message = 'Queued graduate ID ' . $row['id'];
    } catch (Throwable $t) {
        if (get_db()->inTransaction()) { get_db()->rollBack(); }
        $error = $t->getMessage();
    }
}
?>

<div class="card">
    <h3>Graduate Identification & Queue System</h3>
    
    <?php if ($currentSessionId === 0): ?>
        <div style="background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
            <strong>Warning:</strong> No session selected. Please select a session from the <a href="?page=sessions">Sessions page</a> before proceeding with identification.
        </div>
    <?php else: ?>
        <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
            <strong>Current Session:</strong> <?php echo h($currentSessionName); ?> (ID: <?php echo $currentSessionId; ?>)
        </div>
    <?php endif; ?>
    
    <!-- Verification Mode Selection -->
    <div style="margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 6px; background: #f8f9fa;">
        <h4 style="margin-top: 0;">Verification Mode</h4>
        <div style="margin-bottom: 10px;">
            <label style="margin-right: 20px;">
                <input type="radio" name="verificationMode" value="face" id="modeFace" checked style="margin-right: 5px;">
                Face Verification
            </label>
            <label>
                <input type="radio" name="verificationMode" value="fingerprint" id="modeFingerprint" style="margin-right: 5px;">
                Fingerprint Verification
            </label>
        </div>
    </div>
    
    <!-- Camera Selection (for Face Mode) -->
    <div id="cameraSelection" class="camera-selection" style="margin-bottom: 20px;">
        <label for="cameraSelect" style="font-weight: bold; margin-right: 10px;">Select Camera:</label>
        <select id="cameraSelect" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-right: 10px;">
            <option value="">Loading cameras...</option>
        </select>
        <button id="refreshCamerasBtn" type="button" style="padding: 8px 12px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Refresh Cameras</button>
        <div id="cameraInfo" style="margin-top: 10px; font-style: italic; color: #666;"></div>
    </div>
    
    <!-- Fingerprint Upload Section -->
    <div id="fingerprintUploadSection" style="display: none; margin-bottom: 20px; padding: 15px; border: 1px solid #ddd; border-radius: 6px; background: #f8f9fa;">
        <h4 style="margin-top: 0;">Fingerprint Verification</h4>
        <div style="margin-bottom: 15px;">
            <label for="fingerprintFile" style="font-weight: bold; margin-right: 10px;">Upload Fingerprint Image:</label>
            <input type="file" id="fingerprintFile" accept="image/*" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />
        </div>
        <div style="margin-bottom: 15px;">
            <label style="font-weight: bold; margin-right: 10px;">Student ID:</label>
            <span id="detectedStudentIdDisplay" style="padding: 8px; background: #e9ecef; border: 1px solid #ddd; border-radius: 4px; display: inline-block; min-width: 200px;">Upload fingerprint to detect student</span>
        </div>
        <button id="verifyFingerprintBtn" type="button" style="padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;" disabled>Verify & Queue Fingerprint</button>
        <div id="fingerprintResult" style="margin-top: 15px; padding: 10px; border-radius: 4px; display: none;"></div>
    </div>
    
    <div class="row">
        <div class="col">
            <div style="position:relative;display:inline-block;">
                <video id="camVideo" autoplay playsinline style="max-width:100%;border:1px solid #ddd;border-radius:6px"></video>
                <canvas id="camCanvas" style="display:none"></canvas>
                <div id="overlay" style="position:absolute;left:0;top:0;right:0;bottom:0;pointer-events:none;color:#fff;"></div>
            </div>
        </div>
        <div class="col">
            <div id="statusBox" class="card" style="background:#f7f9fc;">
                <div id="statusLine"><strong>Status:</strong> <?php echo $currentSessionId === 0 ? 'No session selected' : 'Camera idle'; ?></div>
                <div id="matchLine"><strong>Match:</strong> -</div>
                <div id="confLine"><strong>Confidence:</strong> -</div>
                <div id="queueLine"><strong>Queue:</strong> -</div>
                <div id="verificationLine"><strong>Verification:</strong> -</div>
            </div>
            <button id="stopCamBtn" type="button">Stop Camera</button>
        </div>
    </div>
</div>

<div class="card">
    <h3>Queued Graduates (Current Session)</h3>
    <table>
        <thead>
            <tr><th>ID</th><th>Name</th><th>Face Verified</th><th>Fingerprint Verified</th><th>Queued At</th></tr>
        </thead>
        <tbody>
            <?php
            if ($currentSessionId > 0) {
                $stmt = $db->prepare("SELECT id, full_name, face_verified_at, fingerprint_verified_at, queued_at FROM graduates WHERE session_id = ? AND queued_at IS NOT NULL AND announced_at IS NULL ORDER BY queued_at ASC");
                $stmt->execute([$currentSessionId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = [];
            }
            
            if (empty($rows)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: #666; font-style: italic;">
                        <?php echo $currentSessionId === 0 ? 'No session selected' : 'No queued graduates in this session yet'; ?>
                    </td>
                </tr>
            <?php else:
                foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo (int)$r['id']; ?></td>
                        <td><?php echo h($r['full_name']); ?></td>
                        <td><?php echo $r['face_verified_at'] ? h((string)$r['face_verified_at']) : '-'; ?></td>
                        <td><?php echo $r['fingerprint_verified_at'] ? h((string)$r['fingerprint_verified_at']) : '-'; ?></td>
                        <td><?php echo h((string)$r['queued_at']); ?></td>
                    </tr>
                <?php endforeach;
            endif; ?>
        </tbody>
    </table>
    <p><a href="?page=stage">Go to Stage Announce</a></p>
</div>

<script>
let stream = null;
let availableCameras = [];
const videoEl = document.getElementById('camVideo');
const canvasEl = document.getElementById('camCanvas');
const overlay = document.getElementById('overlay');
const stopBtn = document.getElementById('stopCamBtn');
const cameraSelect = document.getElementById('cameraSelect');
const refreshCamerasBtn = document.getElementById('refreshCamerasBtn');
const cameraInfo = document.getElementById('cameraInfo');
const statusLine = document.getElementById('statusLine');
const matchLine = document.getElementById('matchLine');
const confLine = document.getElementById('confLine');
const queueLine = document.getElementById('queueLine');
const verificationLine = document.getElementById('verificationLine');

// Fingerprint verification elements
const modeFace = document.getElementById('modeFace');
const modeFingerprint = document.getElementById('modeFingerprint');
const cameraSelection = document.getElementById('cameraSelection');
const fingerprintUploadSection = document.getElementById('fingerprintUploadSection');
const fingerprintFile = document.getElementById('fingerprintFile');
const detectedStudentIdDisplay = document.getElementById('detectedStudentIdDisplay');
const verifyFingerprintBtn = document.getElementById('verifyFingerprintBtn');
const fingerprintResult = document.getElementById('fingerprintResult');

// Global variables for fingerprint verification
let detectedStudentId = null;

// Current session ID from PHP
const currentSessionId = <?php echo $currentSessionId; ?>;

let running = true;
let lastShotAt = 0;
let pending = false;
let lastQueuedStudent = null;
let emaConfidence = 0;

// Function to get available cameras
async function getAvailableCameras() {
    try {
        const devices = await navigator.mediaDevices.enumerateDevices();
        const videoDevices = devices.filter(device => device.kind === 'videoinput');
        return videoDevices;
    } catch (error) {
        console.error('Error getting cameras:', error);
        return [];
    }
}

// Function to populate camera dropdown
async function populateCameraDropdown() {
    availableCameras = await getAvailableCameras();
    cameraSelect.innerHTML = '';
    
    if (availableCameras.length === 0) {
        cameraSelect.innerHTML = '<option value="">No cameras found</option>';
        cameraInfo.textContent = 'No cameras available';
        return;
    }
    
    availableCameras.forEach((camera, index) => {
        const option = document.createElement('option');
        option.value = camera.deviceId;
        option.textContent = camera.label || `Camera ${index + 1}`;
        cameraSelect.appendChild(option);
    });
    
    // Select first camera by default
    if (availableCameras.length > 0) {
        cameraSelect.value = availableCameras[0].deviceId;
        updateCameraInfo();
    }
}

// Function to update camera info display
function updateCameraInfo() {
    const selectedDeviceId = cameraSelect.value;
    const selectedCamera = availableCameras.find(camera => camera.deviceId === selectedDeviceId);
    
    if (selectedCamera) {
        cameraInfo.textContent = `Selected: ${selectedCamera.label || 'Unknown Camera'}`;
    } else {
        cameraInfo.textContent = 'No camera selected';
    }
}

function drawBox(box, ok) {
    const w = videoEl.videoWidth, h = videoEl.videoHeight;
    if (!w || !h) return;
    canvasEl.width = w; canvasEl.height = h;
    const ctx = canvasEl.getContext('2d');
    ctx.clearRect(0,0,w,h);
    if (!box) return;
    ctx.strokeStyle = ok ? '#00cc00' : '#ff3333';
    ctx.lineWidth = 3;
    ctx.strokeRect(box.x, box.y, box.w, box.h);
}

async function identifyFrame() {
    const w = videoEl.videoWidth, h = videoEl.videoHeight;
    if (!w || !h) return null;
    canvasEl.width = w; canvasEl.height = h;
    const ctx = canvasEl.getContext('2d');
    ctx.drawImage(videoEl, 0, 0, w, h);
    const blob = await new Promise(res => canvasEl.toBlob(res, 'image/jpeg', 0.7));
    const fd = new FormData();
    fd.append('frame', blob, 'frame.jpg');
    fd.append('session_id', currentSessionId); // Pass session ID to API
    const resp = await fetch('api/identify_and_queue.php', { method: 'POST', body: fd });
    return await resp.json();
}

// Function to start camera with selected device
async function startCamera() {
    if (currentSessionId === 0) {
        statusLine.textContent = 'Status: No session selected. Please select a session first.';
        return;
    }
    
    try {
        const selectedDeviceId = cameraSelect.value;
        if (!selectedDeviceId) {
            statusLine.textContent = 'Status: Please select a camera first';
            return;
        }
        
        statusLine.textContent = 'Status: Starting camera...';
        
        // Stop existing stream if any
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
        
        // Start new stream with selected camera
        stream = await navigator.mediaDevices.getUserMedia({ 
            video: { 
                deviceId: { exact: selectedDeviceId }
            }, 
            audio: false 
        });
        
        videoEl.srcObject = stream;
        await videoEl.play();
        statusLine.textContent = 'Status: Identifying verified graduates...';
        loop();
    } catch (e) {
        statusLine.textContent = 'Status: Camera error - ' + e.message;
        console.error('Camera error:', e);
    }
}

async function loop() {
    if (!running || currentSessionId === 0) return;
    const now = performance.now();
    if (pending || (now - lastShotAt) < 350) { requestAnimationFrame(loop); return; }
    pending = true; lastShotAt = now;
            try {
            const data = await identifyFrame();
            // Debug: log full response to console
            console.log('Identification response:', data);
            const ok = !!(data && data.success);
            const ident = data && data.data && data.data.identification ? data.data.identification : {};
            const sid = ident.best_student_id || '-';
            const conf = ident.best_confidence || 0;
            emaConfidence = 0.6 * emaConfidence + 0.4 * conf;
            drawBox(ident.box, ok);
            matchLine.textContent = 'Match: ' + sid;
            confLine.textContent = 'Confidence: ' + Math.round(emaConfidence * 100) + '%';
            if (ok) {
                queueLine.textContent = 'Queue: Queued ' + sid;
                if (sid && sid !== '-' && sid !== lastQueuedStudent) {
                    lastQueuedStudent = sid;
                    // Refresh queued list quickly
                    setTimeout(() => location.reload(), 600);
                }
            } else {
                queueLine.textContent = 'Queue: ' + (data && data.message ? data.message : 'Awaiting match...');
            }
        } catch (e) {
            console.error('Identification error:', e);
            queueLine.textContent = 'Queue: error - ' + e.message;
        } finally {
        pending = false;
        setTimeout(() => requestAnimationFrame(loop), 250);
    }
}

// Event listeners
cameraSelect.addEventListener('change', () => {
    updateCameraInfo();
    if (stream) {
        // Restart camera with new selection
        startCamera();
    }
});

refreshCamerasBtn.addEventListener('click', async () => {
    await populateCameraDropdown();
    if (cameraSelect.value) {
        startCamera();
    }
});

stopBtn && stopBtn.addEventListener('click', () => {
    running = false;
    if (stream) { stream.getTracks().forEach(t => t.stop()); stream = null; }
    videoEl.srcObject = null;
    statusLine.textContent = 'Status: Camera stopped.';
});

// Initialize cameras on page load
if (navigator.mediaDevices && navigator.mediaDevices.getUserMedia) {
    populateCameraDropdown().then(() => {
        if (cameraSelect.value && currentSessionId > 0) {
            startCamera();
        }
    });
} else {
    statusLine.textContent = 'Status: getUserMedia not supported.';
}

// Fingerprint verification functions
function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = () => resolve(reader.result);
        reader.onerror = error => reject(error);
    });
}

function showFingerprintResult(message, isSuccess) {
    fingerprintResult.style.display = 'block';
    fingerprintResult.style.backgroundColor = isSuccess ? '#d4edda' : '#f8d7da';
    fingerprintResult.style.color = isSuccess ? '#155724' : '#721c24';
    fingerprintResult.style.border = `1px solid ${isSuccess ? '#c3e6cb' : '#f5c6cb'}`;
    fingerprintResult.textContent = message;
}

function resetFingerprintInterface() {
    fingerprintFile.value = '';
    detectedStudentId = null;
    detectedStudentIdDisplay.textContent = 'Upload fingerprint to detect student';
    fingerprintResult.style.display = 'none';
    verifyFingerprintBtn.disabled = true;
    verificationLine.textContent = 'Verification: -';
}

// Event listeners for verification mode
modeFace.addEventListener('change', () => {
    cameraSelection.style.display = 'block';
    fingerprintUploadSection.style.display = 'none';
    resetFingerprintInterface();
    if (currentSessionId > 0) {
        startCamera();
    }
});

modeFingerprint.addEventListener('change', () => {
    cameraSelection.style.display = 'none';
    fingerprintUploadSection.style.display = 'block';
    resetFingerprintInterface();
    // Stop camera when switching to fingerprint mode
    if (stream) {
        stream.getTracks().forEach(t => t.stop());
        stream = null;
    }
    videoEl.srcObject = null;
    statusLine.textContent = 'Status: Fingerprint verification mode';
});

// Fingerprint file change handler - automatically detect student
fingerprintFile.addEventListener('change', async () => {
    const file = fingerprintFile.files[0];
    if (!file) {
        resetFingerprintInterface();
        return;
    }
    
    try {
        detectedStudentIdDisplay.textContent = 'Detecting student from fingerprint...';
        verificationLine.textContent = 'Verification: Detecting student...';
        
        // Convert file to base64
        const imageData = await fileToBase64(file);
        
        // Send to fingerprint detection API
        const formData = new FormData();
        formData.append('image_data', imageData);
        formData.append('session_id', currentSessionId);
        
        const response = await fetch('api/detect_student_from_fingerprint.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        console.log('Student detection response:', result);
        
        if (result.success && result.data && result.data.student_id) {
            detectedStudentId = result.data.student_id;
            detectedStudentIdDisplay.textContent = `Detected: ${result.data.student_id} - ${result.data.student_name}`;
            verifyFingerprintBtn.disabled = false;
            verificationLine.textContent = 'Verification: Student detected, ready to verify';
        } else {
            detectedStudentId = null;
            detectedStudentIdDisplay.textContent = 'No student found for this fingerprint';
            verifyFingerprintBtn.disabled = true;
            verificationLine.textContent = 'Verification: No student detected';
            showFingerprintResult(`❌ No student found for this fingerprint: ${result.message}`, false);
        }
    } catch (error) {
        console.error('Student detection error:', error);
        detectedStudentId = null;
        detectedStudentIdDisplay.textContent = 'Error detecting student';
        verifyFingerprintBtn.disabled = true;
        verificationLine.textContent = 'Verification: Detection error';
        showFingerprintResult(`❌ Error detecting student: ${error.message}`, false);
    }
});

// Fingerprint verification button click handler
verifyFingerprintBtn.addEventListener('click', async () => {
    const file = fingerprintFile.files[0];
    
    if (!file || !detectedStudentId) {
        showFingerprintResult('Please select a fingerprint file and wait for student detection', false);
        return;
    }
    
    try {
        verifyFingerprintBtn.disabled = true;
        verificationLine.textContent = 'Verification: Processing...';
        
        // Convert file to base64
        const imageData = await fileToBase64(file);
        
        // Send verification request
        const formData = new FormData();
        formData.append('student_id', detectedStudentId);
        formData.append('image_data', imageData);
        
        const response = await fetch('api/verify_fingerprint.php', {
            method: 'POST',
            body: formData
        });
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        console.log('Fingerprint verification response:', result);
        
        if (result.success && result.data && result.data.fingerprint_validation) {
            const fv = result.data.fingerprint_validation;
            
            if (fv.is_valid) {
                // Fingerprint verified successfully, now queue the student
                const queueResponse = await fetch('api/queue_student.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        student_id: detectedStudentId,
                        session_id: currentSessionId
                    })
                });
                
                const queueResult = await queueResponse.json();
                
                if (queueResult.success) {
                    showFingerprintResult(`✅ Fingerprint VERIFIED! Student ${detectedStudentId} has been queued successfully.`, true);
                    verificationLine.textContent = `Verification: ✅ ${detectedStudentId} queued`;
                    matchLine.textContent = `Match: ${detectedStudentId}`;
                    confLine.textContent = `Confidence: ${Math.round(fv.confidence * 100)}%`;
                    queueLine.textContent = `Queue: Queued ${detectedStudentId}`;
                    
                    // Refresh the page to show updated queue
                    setTimeout(() => location.reload(), 1000);
                } else {
                    showFingerprintResult(`✅ Fingerprint verified but failed to queue: ${queueResult.message}`, false);
                    verificationLine.textContent = `Verification: ✅ Verified but queue failed`;
                }
            } else {
                showFingerprintResult(`❌ Fingerprint verification FAILED. Confidence: ${Math.round(fv.confidence * 100)}% (Threshold: ${Math.round(fv.threshold * 100)}%)`, false);
                verificationLine.textContent = `Verification: ❌ Failed (${Math.round(fv.confidence * 100)}%)`;
                matchLine.textContent = `Match: ${detectedStudentId}`;
                confLine.textContent = `Confidence: ${Math.round(fv.confidence * 100)}%`;
                queueLine.textContent = `Queue: Verification failed`;
            }
        } else {
            showFingerprintResult(`❌ Verification failed: ${result.message}`, false);
            verificationLine.textContent = `Verification: ❌ Error`;
        }
    } catch (error) {
        console.error('Fingerprint verification error:', error);
        showFingerprintResult(`❌ Error: ${error.message}`, false);
        verificationLine.textContent = `Verification: ❌ Error`;
    } finally {
        verifyFingerprintBtn.disabled = false;
    }
});
</script>


