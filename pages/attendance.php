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
?>

<div class="card">
    <h2>QR Code + Verification (Attendance)</h2>
    
    <!-- Verification Mode Selection -->
    <div class="verification-mode" style="margin-bottom: 20px;">
        <label style="font-weight: bold; margin-right: 10px;">Verification Mode:</label>
        <label style="display: inline-block; margin-right: 15px;">
            <input type="radio" name="verification_mode" id="mode_face" value="face" checked /> Face Verification
        </label>
        <label style="display: inline-block;">
            <input type="radio" name="verification_mode" id="mode_fingerprint" value="fingerprint" /> Fingerprint Verification
        </label>
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
            <span id="detectedStudentIdDisplay" style="padding: 8px; background: #e9ecef; border: 1px solid #ddd; border-radius: 4px; display: inline-block; min-width: 200px;">Scan QR code first</span>
        </div>
        <button id="verifyFingerprintBtn" type="button" style="padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer; font-weight: bold;" disabled>Verify Fingerprint</button>
        <div id="fingerprintResult" style="margin-top: 15px; padding: 10px; border-radius: 4px; display: none;"></div>
    </div>
    
    <?php if ($currentSessionId === 0): ?>
        <div style="background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
            <strong>Warning:</strong> No session selected. Please select a session from the <a href="?page=sessions">Sessions page</a> before proceeding with attendance.
        </div>
    <?php else: ?>
        <div style="background: #d4edda; border: 1px solid #c3e6cb; color: #155724; padding: 12px; border-radius: 4px; margin-bottom: 20px;">
            <strong>Current Session:</strong> <?php echo h($currentSessionName); ?> (ID: <?php echo $currentSessionId; ?>)
        </div>
    <?php endif; ?>
    
    <!-- Camera Selection -->
    <div class="camera-selection" style="margin-bottom: 20px;">
        <label for="cameraSelect" style="font-weight: bold; margin-right: 10px;">Select Camera:</label>
        <select id="cameraSelect" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-right: 10px;">
            <option value="">Loading cameras...</option>
        </select>
        <button id="refreshCamerasBtn" type="button" style="padding: 8px 12px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Refresh Cameras</button>
        <div id="cameraInfo" style="margin-top: 10px; font-style: italic; color: #666;"></div>
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
                <div id="statusLine"><strong>Status:</strong> <?php echo $currentSessionId === 0 ? 'No session selected' : 'Initializing camera...'; ?></div>
                <div id="qrLine"><strong>QR:</strong> -</div>
                <div id="sidLine"><strong>Student ID:</strong> -</div>
                <div id="verificationLine"><strong>Verification:</strong> -</div>
                <div id="captureLine"><strong>Capture:</strong> -</div>
            </div>
            <button id="stopCamBtn" type="button">Stop Camera</button>
        </div>
    </div>
</div>

<div class="card">
    <h3>Recently Verified (Current Session)</h3>
    <table>
        <thead>
            <tr><th>ID</th><th>Name</th><th>Face Verified</th><th>Fingerprint Verified</th><th>Queued?</th></tr>
        </thead>
        <tbody>
            <?php
            if ($currentSessionId > 0) {
                $stmt = $db->prepare("SELECT id, full_name, face_verified_at, fingerprint_verified_at, queued_at FROM graduates WHERE session_id = ? AND (face_verified_at IS NOT NULL OR fingerprint_verified_at IS NOT NULL) ORDER BY COALESCE(face_verified_at, fingerprint_verified_at) DESC LIMIT 10");
                $stmt->execute([$currentSessionId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = [];
            }
            
            if (empty($rows)): ?>
                <tr>
                    <td colspan="5" style="text-align: center; color: #666; font-style: italic;">
                        <?php echo $currentSessionId === 0 ? 'No session selected' : 'No verified graduates in this session yet'; ?>
                    </td>
                </tr>
            <?php else:
                foreach ($rows as $r): ?>
                    <tr>
                        <td><?php echo (int)$r['id']; ?></td>
                        <td><?php echo h($r['full_name']); ?></td>
                        <td><?php echo $r['face_verified_at'] ? h((string)$r['face_verified_at']) : '-'; ?></td>
                        <td><?php echo $r['fingerprint_verified_at'] ? h((string)$r['fingerprint_verified_at']) : '-'; ?></td>
                        <td><?php echo $r['queued_at'] ? 'Yes' : 'No'; ?></td>
                    </tr>
                <?php endforeach;
            endif; ?>
        </tbody>
    </table>
    <p><a href="?page=queue">Proceed to Before Stage Queue</a></p>
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
const qrLine = document.getElementById('qrLine');
const sidLine = document.getElementById('sidLine');
const verificationLine = document.getElementById('verificationLine');
const captureLine = document.getElementById('captureLine');

// Verification mode elements
const modeFace = document.getElementById('mode_face');
const modeFingerprint = document.getElementById('mode_fingerprint');

// Fingerprint upload elements
const fingerprintUploadSection = document.getElementById('fingerprintUploadSection');
const fingerprintFile = document.getElementById('fingerprintFile');
const detectedStudentIdDisplay = document.getElementById('detectedStudentIdDisplay');
const verifyFingerprintBtn = document.getElementById('verifyFingerprintBtn');
const fingerprintResult = document.getElementById('fingerprintResult');

// Current session ID from PHP
const currentSessionId = <?php echo $currentSessionId; ?>;

let detectedStudentId = null;
let phase = 'scan_qr'; // scan_qr -> verify_face -> capture
let verifying = false;
let lastVerifyAt = 0;
let smoothConfidence = 0; // EMA smoothing for stability
let running = true;

function drawCountdown(n) {
  overlay.innerHTML = `<div style="position:absolute;inset:0;background:rgba(0,0,0,0.2);display:flex;align-items:center;justify-content:center;font-size:96px;font-weight:bold;">${n}</div>`;
}

function clearOverlay() { overlay.innerHTML = ''; }

function extractStudentId(text) {
  const m = text.match(/\b(\d{6,12})\b/);
  if (m) return m[1];
  const m2 = text.match(/(?:student_id|id|sid)[:=\s]+([A-Za-z0-9_-]{4,})/i);
  if (m2) return m2[1];
  return null;
}

// Function to check if student belongs to current session
async function checkStudentSession(studentId) {
    try {
        const fd = new FormData();
        fd.append('student_id', studentId);
        fd.append('session_id', currentSessionId);
        const resp = await fetch('api/check_student_session.php', { method: 'POST', body: fd });
        const data = await resp.json();
        return data && data.ok === true;
    } catch (e) {
        console.error('Session check error:', e);
        return false;
    }
}

async function verifyFace(studentId) {
  // Capture current frame as base64 PNG
  const w = videoEl.videoWidth, h = videoEl.videoHeight;
  canvasEl.width = w; canvasEl.height = h;
  const ctx = canvasEl.getContext('2d');
  ctx.drawImage(videoEl, 0, 0, w, h);
  // Downscale for faster upload and consistent processing
  const scale = 0.6;
  const w2 = Math.max(320, Math.floor(w * scale));
  const h2 = Math.floor(h * (w2 / w));
  const tmp = document.createElement('canvas');
  tmp.width = w2; tmp.height = h2;
  const tctx = tmp.getContext('2d');
  tctx.drawImage(canvasEl, 0, 0, w, h, 0, 0, w2, h2);
  const dataUrl = tmp.toDataURL('image/jpeg', 0.7);

  const fd = new FormData();
  fd.append('request_type', 'face_verification');
  fd.append('student_id', studentId);
  fd.append('image_data', dataUrl);
  const resp = await fetch('api/scan_and_verify.php', { method: 'POST', body: fd });
  const data = await resp.json();
  if (!data || data.success !== true) throw new Error((data && data.message) || 'verify failed');
  return data;
}

async function verifyFingerprint(studentId) {
  try {
    // Capture current frame as base64 PNG
    const w = videoEl.videoWidth, h = videoEl.videoHeight;
    canvasEl.width = w; canvasEl.height = h;
    const ctx = canvasEl.getContext('2d');
    ctx.drawImage(videoEl, 0, 0, w, h);
    // Downscale for faster upload and consistent processing
    const scale = 0.6;
    const w2 = Math.max(320, Math.floor(w * scale));
    const h2 = Math.floor(h * (w2 / w));
    const tmp = document.createElement('canvas');
    tmp.width = w2; tmp.height = h2;
    const tctx = tmp.getContext('2d');
    tctx.drawImage(canvasEl, 0, 0, w, h, 0, 0, w2, h2);
    const dataUrl = tmp.toDataURL('image/jpeg', 0.7);

    const fd = new FormData();
    fd.append('student_id', studentId);
    fd.append('image_data', dataUrl);
    
    console.log('Sending fingerprint verification request for student:', studentId);
    const resp = await fetch('api/verify_fingerprint.php', { method: 'POST', body: fd });
    
    if (!resp.ok) {
      throw new Error(`HTTP error! status: ${resp.status}`);
    }
    
    const contentType = resp.headers.get('content-type');
    if (!contentType || !contentType.includes('application/json')) {
      const text = await resp.text();
      console.error('Non-JSON response:', text);
      throw new Error('Server returned non-JSON response');
    }
    
    const data = await resp.json();
    console.log('Fingerprint verification response:', data);
    
    if (!data || data.success !== true) {
      throw new Error((data && data.message) || 'Verification failed');
    }
    
    return data;
  } catch (error) {
    console.error('Fingerprint verification error:', error);
    throw error;
  }
}

async function capturePortrait(studentId, blob) {
  const fd = new FormData();
  fd.append('student_id', studentId);
  fd.append('frame', blob, 'portrait.jpg');
  const resp = await fetch('api/capture_face.php', { method: 'POST', body: fd });
  const data = await resp.json();
  if (!data || data.ok === false) throw new Error((data && data.error) || 'capture failed');
  return data;
}

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
        statusLine.textContent = 'Status: Scanning QR...';
        scanLoop();
    } catch (e) {
        statusLine.textContent = 'Status: Camera error - ' + e.message;
        console.error('Camera error:', e);
    }
}

async function scanLoop() {
  if (!running || currentSessionId === 0) return;
  const w = videoEl.videoWidth, h = videoEl.videoHeight;
  if (!w || !h) { requestAnimationFrame(scanLoop); return; }
  canvasEl.width = w; canvasEl.height = h;
  const ctx = canvasEl.getContext('2d');
  ctx.drawImage(videoEl, 0, 0, w, h);

  if (phase === 'scan_qr') {
    // Always use Python server for QR decode
    const blob = await new Promise(res => canvasEl.toBlob(res, 'image/png'));
    const fd = new FormData();
    fd.append('frame', blob, 'qr.png');
    try {
      const resp = await fetch('api/decode_qr.php', { method: 'POST', body: fd });
      const data = await resp.json();
      if (data && data.ok && data.qr_text) {
        qrLine.textContent = 'QR: ' + data.qr_text;
        if (data.student_id) {
          detectedStudentId = data.student_id;
          sidLine.textContent = 'Student ID: ' + detectedStudentId;
          
          // Check if student belongs to current session
          const isInSession = await checkStudentSession(detectedStudentId);
          if (!isInSession) {
            qrLine.textContent = 'QR: ' + data.qr_text + ' (Not in current session)';
            sidLine.textContent = 'Student ID: ' + detectedStudentId + ' - WRONG SESSION';
            statusLine.textContent = 'Status: Student not registered for this session';
            return;
          }
          
          // Handle different verification modes
          if (modeFingerprint && modeFingerprint.checked) {
            // For fingerprint mode, show the student ID and enable fingerprint upload
            detectedStudentIdDisplay.textContent = detectedStudentId;
            detectedStudentIdDisplay.style.backgroundColor = '#d4edda';
            detectedStudentIdDisplay.style.color = '#155724';
            detectedStudentIdDisplay.style.border = '1px solid #c3e6cb';
            verifyFingerprintBtn.disabled = false;
            statusLine.textContent = 'Status: Student detected. Please upload fingerprint image.';
            phase = 'waiting_for_fingerprint';
          } else {
            // For face mode, proceed with face verification
            phase = 'verify_face';
            statusLine.textContent = 'Status: Verifying...';
            try { new Audio('data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBIAAAABAAEAESsAACJWAAACABYAAAABAAgAZGF0YQAAAAA=').play(); } catch (e) {}
          }
        } else {
          // If no student_id extracted, try to extract from QR text
          const extractedId = extractStudentId(data.qr_text);
          if (extractedId) {
            detectedStudentId = extractedId;
            sidLine.textContent = 'Student ID: ' + detectedStudentId;
            
            // Check if student belongs to current session
            const isInSession = await checkStudentSession(detectedStudentId);
            if (!isInSession) {
              qrLine.textContent = 'QR: ' + data.qr_text + ' (Not in current session)';
              sidLine.textContent = 'Student ID: ' + detectedStudentId + ' - WRONG SESSION';
              statusLine.textContent = 'Status: Student not registered for this session';
              return;
            }
            
            // Handle different verification modes
            if (modeFingerprint && modeFingerprint.checked) {
              // For fingerprint mode, show the student ID and enable fingerprint upload
              detectedStudentIdDisplay.textContent = detectedStudentId;
              detectedStudentIdDisplay.style.backgroundColor = '#d4edda';
              detectedStudentIdDisplay.style.color = '#155724';
              detectedStudentIdDisplay.style.border = '1px solid #c3e6cb';
              verifyFingerprintBtn.disabled = false;
              statusLine.textContent = 'Status: Student detected. Please upload fingerprint image.';
              phase = 'waiting_for_fingerprint';
            } else {
              // For face mode, proceed with face verification
              phase = 'verify_face';
              statusLine.textContent = 'Status: Verifying...';
              try { new Audio('data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBIAAAABAAEAESsAACJWAAACABYAAAABAAgAZGF0YQAAAAA=').play(); } catch (e) {}
            }
          } else {
            qrLine.textContent = 'QR: ' + data.qr_text + ' (No student ID found)';
            statusLine.textContent = 'Status: Invalid QR code format';
          }
        }
      }
    } catch (e) {
      // keep looping, maybe next frame
    }
  }

  if (phase === 'verify_face' && detectedStudentId) {
    const now = performance.now();
    if (verifying || (now - lastVerifyAt) < 200) {
      setTimeout(() => requestAnimationFrame(scanLoop), 60);
      return;
    }
    verifying = true;
    lastVerifyAt = now;
    try {
      let data;
      let verificationType = 'Face';
      
      // Check which verification mode is selected
      if (modeFingerprint && modeFingerprint.checked) {
        data = await verifyFingerprint(detectedStudentId);
        verificationType = 'Fingerprint';
      } else {
        data = await verifyFace(detectedStudentId);
        verificationType = 'Face';
      }
      
      // Handle verification results
      if (verificationType === 'Face') {
        const fv = (data && data.data && data.data.face_validation) ? data.data.face_validation : {};
        // Exponential moving average for confidence smoothing
        const raw = fv.confidence || 0;
        smoothConfidence = 0.6 * smoothConfidence + 0.4 * raw;
        const pct = Math.round(smoothConfidence * 100);
        const box = fv.box;
        verificationLine.textContent = 'Face: ' + (fv.is_valid ? 'VERIFIED' : 'NOT VERIFIED') + ' (' + pct + '%)';
        
        // Draw overlays
        const ctx2 = canvasEl.getContext('2d');
        if (data.qr_points && data.qr_points.length === 4) {
          ctx2.strokeStyle = fv.is_valid ? '#00ff00' : '#ffff00';
          ctx2.lineWidth = 3;
          ctx2.beginPath();
          for (let i = 0; i < 4; i++) {
            const a = data.qr_points[i];
            const b = data.qr_points[(i+1)%4];
            ctx2.moveTo(a[0], a[1]);
            ctx2.lineTo(b[0], b[1]);
          }
          ctx2.stroke();
        }
        if (box) {
          ctx2.strokeStyle = fv.is_valid ? '#00ff00' : '#ff0000';
          ctx2.lineWidth = 3;
          ctx2.strokeRect(box.x, box.y, box.w, box.h);
        }
        
        if (fv && fv.is_valid) {
          statusLine.textContent = 'Status: Starting capture countdown...';
          phase = 'capture';
          countdownAndCapture();
          return; // capture handles flow
        } else {
          statusLine.textContent = 'Status: Hold steady for verification...';
        }
      } else {
        // Fingerprint verification
        const fv = (data && data.data && data.data.fingerprint_validation) ? data.data.fingerprint_validation : {};
        const pct = Math.round((fv.confidence || 0) * 100);
        
        // Debug: Log the full response structure
        console.log('Full fingerprint response:', data);
        console.log('Fingerprint validation object:', fv);
        console.log('Is valid:', fv.is_valid);
        console.log('Confidence:', fv.confidence);
        
        verificationLine.textContent = 'Fingerprint: ' + (fv.is_valid ? 'VERIFIED' : 'NOT VERIFIED') + ' (' + pct + '%)';
        
        if (fv && fv.is_valid) {
          statusLine.textContent = 'Status: Starting capture countdown...';
          phase = 'capture';
          countdownAndCapture();
          return; // capture handles flow
        } else {
          statusLine.textContent = 'Status: Hold steady for fingerprint verification...';
        }
      }
    } catch (e) {
      verificationLine.textContent = 'Verification: error - ' + e.message;
    } finally {
      verifying = false;
    }
  }

  // Throttle loop a bit to reduce server load but keep sensitivity
  setTimeout(() => requestAnimationFrame(scanLoop), phase === 'scan_qr' ? 120 : 60);
}

async function countdownAndCapture() {
  for (let n = 3; n >= 1; n--) {
    drawCountdown(n);
    await new Promise(r => setTimeout(r, 800));
  }
  clearOverlay();
  const w = videoEl.videoWidth, h = videoEl.videoHeight;
  canvasEl.width = w; canvasEl.height = h;
  const ctx = canvasEl.getContext('2d');
  ctx.drawImage(videoEl, 0, 0, w, h);
  const blob = await new Promise(res => canvasEl.toBlob(res, 'image/jpeg', 0.95));
  try {
    statusLine.textContent = 'Status: Saving captured portrait...';
    const data = await capturePortrait(detectedStudentId, blob);
    captureLine.textContent = 'Capture: Saved ' + (data.path || '');
    statusLine.textContent = 'Status: Attendance completed.';
    // Optionally refresh list
    setTimeout(() => location.reload(), 1200);
  } catch (e) {
    captureLine.textContent = 'Capture: error - ' + e.message;
    statusLine.textContent = 'Status: Capture failed.';
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

// Verification mode change listeners
modeFace.addEventListener('change', () => {
    if (modeFace.checked) {
        fingerprintUploadSection.style.display = 'none';
        verificationLine.textContent = 'Verification: -';
        statusLine.textContent = 'Status: Face verification mode selected';
        // Reset fingerprint interface
        detectedStudentIdDisplay.textContent = 'Scan QR code first';
        detectedStudentIdDisplay.style.backgroundColor = '#e9ecef';
        detectedStudentIdDisplay.style.color = '#6c757d';
        detectedStudentIdDisplay.style.border = '1px solid #ddd';
        verifyFingerprintBtn.disabled = true;
        fingerprintFile.value = '';
        fingerprintResult.style.display = 'none';
        phase = 'scan_qr';
    }
});

modeFingerprint.addEventListener('change', () => {
    if (modeFingerprint.checked) {
        fingerprintUploadSection.style.display = 'block';
        verificationLine.textContent = 'Verification: -';
        statusLine.textContent = 'Status: Fingerprint verification mode selected';
        // Reset fingerprint interface
        detectedStudentIdDisplay.textContent = 'Scan QR code first';
        detectedStudentIdDisplay.style.backgroundColor = '#e9ecef';
        detectedStudentIdDisplay.style.color = '#6c757d';
        detectedStudentIdDisplay.style.border = '1px solid #ddd';
        verifyFingerprintBtn.disabled = true;
        fingerprintFile.value = '';
        fingerprintResult.style.display = 'none';
        phase = 'scan_qr';
    }
});

// Fingerprint upload verification
verifyFingerprintBtn.addEventListener('click', async () => {
    const file = fingerprintFile.files[0];
    
    if (!file) {
        showFingerprintResult('Please select a fingerprint image file.', 'error');
        return;
    }
    
    if (!detectedStudentId) {
        showFingerprintResult('Please scan QR code first to detect student ID.', 'error');
        return;
    }
    
    try {
        verifyFingerprintBtn.disabled = true;
        verifyFingerprintBtn.textContent = 'Verifying...';
        showFingerprintResult('Verifying fingerprint...', 'info');
        
        // Convert file to base64
        const base64Image = await fileToBase64(file);
        
        // Send verification request
        const fd = new FormData();
        fd.append('student_id', detectedStudentId);
        fd.append('image_data', base64Image);
        
        const resp = await fetch('api/verify_fingerprint.php', { method: 'POST', body: fd });
        
        if (!resp.ok) {
            throw new Error(`HTTP error! status: ${resp.status}`);
        }
        
        const data = await resp.json();
        console.log('Fingerprint verification response:', data);
        
        if (data.success) {
            const fv = data.data.fingerprint_validation;
            const pct = Math.round((fv.confidence || 0) * 100);
            
            if (fv.is_valid) {
                showFingerprintResult(`✅ Fingerprint VERIFIED! Confidence: ${pct}%`, 'success');
                verificationLine.textContent = `Fingerprint: VERIFIED (${pct}%)`;
                statusLine.textContent = 'Status: Fingerprint verification successful!';
                
                // Refresh the page after a short delay to show updated verification status
                setTimeout(() => location.reload(), 2000);
            } else {
                showFingerprintResult(`❌ Fingerprint NOT VERIFIED. Confidence: ${pct}% (Threshold: ${Math.round(fv.threshold * 100)}%)`, 'error');
                verificationLine.textContent = `Fingerprint: NOT VERIFIED (${pct}%)`;
                statusLine.textContent = 'Status: Fingerprint verification failed.';
            }
        } else {
            showFingerprintResult(`❌ Verification failed: ${data.message}`, 'error');
            verificationLine.textContent = 'Fingerprint: ERROR';
            statusLine.textContent = 'Status: Fingerprint verification error.';
        }
        
    } catch (error) {
        console.error('Fingerprint verification error:', error);
        showFingerprintResult(`❌ Error: ${error.message}`, 'error');
        verificationLine.textContent = 'Fingerprint: ERROR';
        statusLine.textContent = 'Status: Fingerprint verification error.';
    } finally {
        verifyFingerprintBtn.disabled = false;
        verifyFingerprintBtn.textContent = 'Verify Fingerprint';
    }
});

// Helper function to convert file to base64
function fileToBase64(file) {
    return new Promise((resolve, reject) => {
        const reader = new FileReader();
        reader.readAsDataURL(file);
        reader.onload = () => resolve(reader.result);
        reader.onerror = error => reject(error);
    });
}

// Helper function to show fingerprint verification result
function showFingerprintResult(message, type) {
    fingerprintResult.style.display = 'block';
    fingerprintResult.textContent = message;
    fingerprintResult.className = '';
    
    switch (type) {
        case 'success':
            fingerprintResult.style.backgroundColor = '#d4edda';
            fingerprintResult.style.color = '#155724';
            fingerprintResult.style.border = '1px solid #c3e6cb';
            break;
        case 'error':
            fingerprintResult.style.backgroundColor = '#f8d7da';
            fingerprintResult.style.color = '#721c24';
            fingerprintResult.style.border = '1px solid #f5c6cb';
            break;
        case 'info':
            fingerprintResult.style.backgroundColor = '#d1ecf1';
            fingerprintResult.style.color = '#0c5460';
            fingerprintResult.style.border = '1px solid #bee5eb';
            break;
    }
}

// Helper function to reset fingerprint interface
function resetFingerprintInterface() {
    detectedStudentIdDisplay.textContent = 'Scan QR code first';
    detectedStudentIdDisplay.style.backgroundColor = '#e9ecef';
    detectedStudentIdDisplay.style.color = '#6c757d';
    detectedStudentIdDisplay.style.border = '1px solid #ddd';
    verifyFingerprintBtn.disabled = true;
    fingerprintFile.value = '';
    fingerprintResult.style.display = 'none';
}

refreshCamerasBtn.addEventListener('click', async () => {
    await populateCameraDropdown();
    if (cameraSelect.value) {
        startCamera();
    }
});

stopBtn.addEventListener('click', () => {
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

// Initialize fingerprint upload section (hidden by default)
fingerprintUploadSection.style.display = 'none';
resetFingerprintInterface();
</script>


