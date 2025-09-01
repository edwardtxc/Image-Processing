<?php
require_once __DIR__ . '/../lib/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'delete') {
        try {
            $id = (int)($_POST['id'] ?? 0);
            if ($id <= 0) { throw new RuntimeException('Invalid graduate id'); }
            $db = get_db();
            $stmt = $db->prepare('SELECT photo_path, fingerprint_path FROM graduates WHERE id = ?');
            $stmt->execute([$id]);
            $grad = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$grad) { throw new RuntimeException('Graduate not found'); }
            if (!empty($grad['photo_path'])) {
                $fsPath = __DIR__ . '/../' . $grad['photo_path'];
                if (is_file($fsPath)) { @unlink($fsPath); }
            }
            if (!empty($grad['fingerprint_path'])) {
                $fsPath = __DIR__ . '/../' . $grad['fingerprint_path'];
                if (is_file($fsPath)) { @unlink($fsPath); }
            }
            $db->prepare('DELETE FROM graduates WHERE id = ?')->execute([$id]);
            $message = 'Deleted graduate ID ' . $id;
        } catch (Throwable $t) {
            $error = $t->getMessage();
        }
            } elseif ($action === 'register') {
            try {
                $studentId = trim($_POST['student_id'] ?? '');
                $fullName = trim($_POST['full_name'] ?? '');
                $program = trim($_POST['program'] ?? '');
                $email = trim($_POST['email'] ?? '');
                $cgpaStr = trim($_POST['cgpa'] ?? '');
                $cgpa = ($cgpaStr === '') ? null : (float)$cgpaStr;
                if ($studentId === '' || $fullName === '' || $program === '' || $email === '') {
                    throw new RuntimeException('All fields are required');
                }
                
                // Validate email format
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Please enter a valid email address');
                }

            $photoPath = null;
            // If an uploaded file present, use it; else if captured data URL present, save it
            if (!empty($_FILES['photo']['name'])) {
                $uploads = __DIR__ . '/../uploads';
                if (!is_dir($uploads)) { mkdir($uploads, 0777, true); }
                $base = 'student_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $studentId . '_' . $fullName);
                $destPng = $uploads . '/' . $base . '.png';
                // Move upload directly to destination
                $tmpPath = $_FILES['photo']['tmp_name'];
                if (!move_uploaded_file($tmpPath, $destPng)) {
                    throw new RuntimeException('Failed to save uploaded photo');
                }
                $photoPath = 'uploads/' . basename($destPng);
            } elseif (!empty($_POST['captured_photo'])) {
                $uploads = __DIR__ . '/../uploads';
                if (!is_dir($uploads)) { mkdir($uploads, 0777, true); }
                $safeName = 'student_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $studentId . '_' . $fullName);
                $dest = $uploads . '/' . $safeName . '.png';
                $dataUrl = $_POST['captured_photo'];
                if (preg_match('/^data:image\/(png|jpeg);base64,/', $dataUrl, $m)) {
                    $commaPos = strpos($dataUrl, ',');
                    $meta = substr($dataUrl, 0, $commaPos);
                    $data = substr($dataUrl, $commaPos + 1);
                    $data = base64_decode($data);
                    if ($data === false) { throw new RuntimeException('Failed to decode captured image'); }
                    // Write raw bytes directly to destination
                    $ext = ($m[1] === 'jpeg') ? '.jpg' : '.png';
                    $dest = $uploads . '/' . $safeName . $ext;
                    if (file_put_contents($dest, $data) === false) { 
                        throw new RuntimeException('Failed to save captured image'); 
                    }
                    $photoPath = 'uploads/' . basename($dest);
                } else {
                    throw new RuntimeException('Unsupported captured image format');
                }
            }

            // Enforce photo is required
            if ($photoPath === null) {
                throw new RuntimeException('Photo is required. Please upload or capture a photo.');
            }

            // Handle fingerprint upload
            $fingerprintPath = null;
            if (!empty($_FILES['fingerprint']['name'])) {
                $uploads = __DIR__ . '/../uploads';
                if (!is_dir($uploads)) { mkdir($uploads, 0777, true); }
                $base = 'fingerprint_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $studentId . '_' . $fullName);
                $destPng = $uploads . '/' . $base . '.png';
                // Move upload directly to destination
                $tmpPath = $_FILES['fingerprint']['tmp_name'];
                if (!move_uploaded_file($tmpPath, $destPng)) {
                    throw new RuntimeException('Failed to save uploaded fingerprint');
                }
                $fingerprintPath = 'uploads/' . basename($destPng);
            } elseif (!empty($_POST['captured_fingerprint'])) {
                $uploads = __DIR__ . '/../uploads';
                if (!is_dir($uploads)) { mkdir($uploads, 0777, true); }
                $safeName = 'fingerprint_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $studentId . '_' . $fullName);
                $dest = $uploads . '/' . $safeName . '.png';
                $dataUrl = $_POST['captured_fingerprint'];
                if (preg_match('/^data:image\/(png|jpeg);base64,/', $dataUrl, $m)) {
                    $commaPos = strpos($dataUrl, ',');
                    $meta = substr($dataUrl, 0, $commaPos);
                    $data = substr($dataUrl, $commaPos + 1);
                    $data = base64_decode($data);
                    if ($data === false) { throw new RuntimeException('Failed to decode captured fingerprint'); }
                    // Write raw bytes directly to destination
                    $ext = ($m[1] === 'jpeg') ? '.jpg' : '.png';
                    $dest = $uploads . '/' . $safeName . $ext;
                    if (file_put_contents($dest, $data) === false) { 
                        throw new RuntimeException('Failed to save captured fingerprint'); 
                    }
                    $fingerprintPath = 'uploads/' . basename($dest);
                } else {
                    throw new RuntimeException('Unsupported captured fingerprint format');
                }
            }

            // Enforce fingerprint is required
            if ($fingerprintPath === null) {
                throw new RuntimeException('Fingerprint is required. Please upload or capture a fingerprint.');
            }

            $db = get_db();
            // Create structured QR content with student ID and name
            $qrContent = $studentId . '|' . $fullName . '|' . $program;
            $token = $qrContent;
            // Determine category based on CGPA
            $category = null;
            if ($cgpa !== null) {
                if ($cgpa >= 3.75) { $category = 'Graduate with Distinction'; }
                elseif ($cgpa >= 2.67) { $category = 'Graduate with Merit'; }
                elseif ($cgpa >= 2.0) { $category = 'Graduate with Pass'; }
            }
            // Determine current session
            $currentSessionId = isset($_SESSION['current_session_id']) ? (int)$_SESSION['current_session_id'] : null;
            if (!$currentSessionId) {
                // fallback to latest session
                $tmp = $db->query('SELECT id FROM sessions ORDER BY id DESC LIMIT 1')->fetchColumn();
                $currentSessionId = $tmp ? (int)$tmp : null;
            }
            $stmt = $db->prepare('INSERT INTO graduates (student_id, full_name, program, cgpa, category, photo_path, fingerprint_path, qr_token, registered_at, session_id, email) VALUES (?, ?, ?, ?, ?, ?, ?, ?, datetime(\'now\'), ?, ?)');
            $stmt->execute([$studentId, $fullName, $program, $cgpa, $category, $photoPath, $fingerprintPath, $token, $currentSessionId, $email]);
            // Generate QR via Python script into qrcodes directory
            $qrDir = __DIR__ . '/../qrcodes';
            if (!is_dir($qrDir)) { mkdir($qrDir, 0777, true); }
            $qrFilename = 'qr_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $token) . '.png';
            $qrFsPath = $qrDir . '/' . $qrFilename;
            $pyScript = __DIR__ . '/../integrations/generate_qr.py';
            $python = 'python';
            $cmd = $python . ' ' . escapeshellarg($pyScript)
                . ' --data ' . escapeshellarg((string)$token)
                . ' --out ' . escapeshellarg($qrFsPath);
            @exec($cmd . ' 2>&1', $outLines, $exitCode);
            $qrWebPath = 'qrcodes/' . $qrFilename;
            
            // Send welcome email with QR code
            $emailResult = '';
            try {
                require_once __DIR__ . '/../lib/email_service.php';
                $emailService = new EmailService();
                
                $graduateData = [
                    'student_id' => $studentId,
                    'full_name' => $fullName,
                    'program' => $program,
                    'cgpa' => $cgpa,
                    'category' => $category,
                    'email' => $email
                ];
                
                $emailResult = $emailService->sendWelcomeEmail($graduateData, $qrFsPath);
                
                if ($emailResult['success']) {
                    $message = 'Registered successfully and welcome email sent to ' . $email . '. QR will encode Student ID: ' . h($token);
                } else {
                    $message = 'Registered successfully but failed to send email: ' . $emailResult['message'] . '. QR will encode Student ID: ' . h($token);
                }
            } catch (Exception $e) {
                $message = 'Registered successfully but failed to send email: ' . $e->getMessage() . '. QR will encode Student ID: ' . h($token);
            }
            
            $generatedToken = $token;
            $generatedQrWebPath = $qrWebPath;
        } catch (Throwable $t) {
            $error = $t->getMessage();
        }
    }
}
?>

<div class="card" style="padding:16px;">
    <h2 style="margin-top:0;">Register Graduate</h2>
    <?php if ($message): ?><p class="success"><?php echo h($message); ?></p><?php endif; ?>
    <?php if ($error): ?><p class="error"><?php echo h($error); ?></p><?php endif; ?>
    <form id="registerForm" method="post" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:16px;">
        <input type="hidden" name="action" value="register" />
        <div class="row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
            <div class="col" style="display:flex; flex-direction:column; gap:6px;">
                <label>Student ID</label><br />
                <input type="text" name="student_id" required style="padding:8px;" />
            </div>
            <div class="col" style="display:flex; flex-direction:column; gap:6px;">
                <label>Full Name</label><br />
                <input type="text" name="full_name" required style="padding:8px;" />
            </div>
            <div class="col" style="display:flex; flex-direction:column; gap:6px;">
                <label>Program</label><br />
                <input type="text" name="program" required style="padding:8px;" />
            </div>
        </div>
        <div class="row" style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:12px;">
            <div class="col" style="display:flex; flex-direction:column; gap:6px;">
                <label>Email Address</label><br />
                <input type="email" name="email" required style="padding:8px;" placeholder="your.email@example.com" />
            </div>
            <div class="col" style="display:flex; flex-direction:column; gap:6px;">
                <label>CGPA</label><br />
                <input type="number" name="cgpa" min="2.0" max="4.0" step="0.0001" placeholder="e.g. 3.6501" style="padding:8px;" required />
                <small style="color: #666;">CGPA must be between 2.0 and 4.0 to graduate</small>
            </div>
            <div class="col" style="display:flex; flex-direction:column; gap:6px;">
                <label>Category (auto)</label><br />
                <input type="text" id="categoryPreview" readonly placeholder="auto" style="padding:8px; background:#f7f7f7;" />
            </div>
        </div>

        <div style="display:flex; flex-direction:column; gap:8px;">
            <label>Photo (required)</label>
            <small>Select one method: capture from camera or upload an image.</small>
            <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
                <label style="display:flex; align-items:center; gap:6px;"><input type="radio" name="photo_mode" id="mode_camera" value="camera" checked /> Use camera</label>
                <label style="display:flex; align-items:center; gap:6px;"><input type="radio" name="photo_mode" id="mode_upload" value="upload" /> Upload photo</label>
            </div>
            <div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap;">
                <div id="cameraSection" style="display:flex; flex-direction:column; gap:8px;">
                    <div style="display:flex; gap:8px;">
                        <button type="button" id="startCameraBtn" style="padding:8px 12px;">Start Camera</button>
                        <button type="button" id="captureBtn" disabled style="padding:8px 12px;">Capture</button>
                        <button type="button" id="stopCameraBtn" disabled style="padding:8px 12px;">Stop</button>
                    </div>
                    <video id="camera" autoplay playsinline style="width:280px; height:210px; background:#000; display:none; border-radius:4px;"></video>
                    <canvas id="snapshot" width="280" height="210" style="display:none; border:1px solid #ccc; border-radius:4px;"></canvas>
                    <input type="hidden" name="captured_photo" id="captured_photo" />
                </div>
                <div id="uploadSection" style="display:none; flex-direction:column; gap:8px;">
                    <input type="file" name="photo" id="photoInput" accept="image/*" style="padding:6px;" />
                    <img id="uploadPreview" alt="Upload Preview" style="display:none; width:280px; height:auto; border:1px solid #ccc; border-radius:4px;" />
                </div>
            </div>
        </div>

        <div style="display:flex; flex-direction:column; gap:8px;">
            <label>Fingerprint (required)</label>
            <small>Select one method: capture from fingerprint scanner or upload an image.</small>
            <div style="display:flex; gap:16px; align-items:center; flex-wrap:wrap;">
                <label style="display:flex; align-items:center; gap:6px;"><input type="radio" name="fingerprint_mode" id="mode_fingerprint_upload" value="upload" checked/> Use Fingerprint Scanner</label>
            </div>
            <div style="display:flex; gap:16px; align-items:flex-start; flex-wrap:wrap;">
                <div id="fingerprintScannerSection" style="display:flex; flex-direction:column; gap:8px;">
                    <div style="display:flex; gap:8px;">
                        <button type="button" id="startFingerprintScannerBtn" style="padding:8px 12px;">Start Fingerprint Scanner</button>
                        <button type="button" id="captureFingerprintBtn" disabled style="padding:8px 12px;">Capture Fingerprint</button>
                        <button type="button" id="stopFingerprintScannerBtn" disabled style="padding:8px 12px;">Stop Scanner</button>
                    </div>
                    <video id="fingerprintScanner" autoplay playsinline style="width:280px; height:210px; background:#000; display:none; border-radius:4px;"></video>
                    <canvas id="fingerprintSnapshot" width="280" height="210" style="display:none; border:1px solid #ccc; border-radius:4px;"></canvas>
                    <input type="hidden" name="captured_fingerprint" id="captured_fingerprint" />
                </div>
                <div id="fingerprintUploadSection" style="display:none; flex-direction:column; gap:8px;">
                    <input type="file" name="fingerprint" id="fingerprintInput" accept="image/*" style="padding:6px;" />
                    <img id="fingerprintUploadPreview" alt="Fingerprint Upload Preview" style="display:none; width:280px; height:auto; border:1px solid #ccc; border-radius:4px;" />
                </div>
            </div>
        </div>
        <div>
            <button type="submit" style="padding:10px 16px; font-weight:600;">Register</button>
        </div>
    </form>
    <?php if (!empty($generatedToken ?? '')): ?>
    <div style="margin-top:16px;">
        <h3>Your QR Code</h3>
        <?php if (!empty($generatedQrWebPath ?? '')): ?>
            <img alt="QR Code" width="200" height="200" src="<?php echo h($generatedQrWebPath); ?>" />
        <?php else: ?>
            <p>QR image not found. Please try again.</p>
        <?php endif; ?>
        <p>Scan this QR during attendance.</p>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3>Recent Graduates</h3>
    <table>
        <thead>
            <tr>
                <th>ID</th><th>Student ID</th><th>Name</th><th>Email</th><th>Program</th><th>CGPA</th><th>Category</th><th>Photo</th><th>Fingerprint</th><th>QR Data</th><th>QR</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php
            $db = get_db();
            $currentSessionId = isset($_SESSION['current_session_id']) ? (int)$_SESSION['current_session_id'] : 0;
            if ($currentSessionId > 0) {
                $stmt = $db->prepare('SELECT id, student_id, full_name, email, program, cgpa, category, photo_path, fingerprint_path, qr_token FROM graduates WHERE session_id = ? ORDER BY id DESC LIMIT 10');
                $stmt->execute([$currentSessionId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $rows = $db->query('SELECT id, student_id, full_name, email, program, cgpa, category, photo_path, fingerprint_path, qr_token FROM graduates ORDER BY id DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
            }
            foreach ($rows as $r): ?>
                <tr>
                    <td><?php echo (int)$r['id']; ?></td>
                    <td><?php echo h($r['student_id']); ?></td>
                    <td><?php echo h($r['full_name']); ?></td>
                    <td><?php echo h($r['email'] ?? ''); ?></td>
                    <td><?php echo h($r['program']); ?></td>
                    <td><?php echo isset($r['cgpa']) ? number_format((float)$r['cgpa'], 4) : ''; ?></td>
                    <td><?php echo h((string)($r['category'] ?? '')); ?></td>
                    <td>
                        <?php if (!empty($r['photo_path'])): ?>
                            <a href="<?php echo h($r['photo_path']); ?>" target="_blank" rel="noopener">
                                <img src="<?php echo h($r['photo_path']); ?>" alt="Photo" width="60" height="60" style="object-fit: cover;" />
                            </a>
                        <?php else: ?>
                            <span style="color:#a00;">No photo</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if (!empty($r['fingerprint_path'])): ?>
                            <a href="<?php echo h($r['fingerprint_path']); ?>" target="_blank" rel="noopener">
                                <img src="<?php echo h($r['fingerprint_path']); ?>" alt="Fingerprint" width="60" height="60" style="object-fit: cover;" />
                            </a>
                        <?php else: ?>
                            <span style="color:#a00;">No fingerprint</span>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo h($r['qr_token']); ?></code></td>
                    <td>
                        <?php 
                        $qrRel = 'qrcodes/qr_' . preg_replace('/[^a-zA-Z0-9_-]/', '_', (string)$r['qr_token']) . '.png';
                        $qrFs = __DIR__ . '/../' . $qrRel;
                        if (!is_file($qrFs)) {
                            // Attempt to generate on the fly using Python
                            $pyScript = __DIR__ . '/../integrations/generate_qr.py';
                            $python = 'python';
                            $cmd = $python . ' ' . escapeshellarg($pyScript)
                                . ' --data ' . escapeshellarg((string)$r['qr_token'])
                                . ' --out ' . escapeshellarg($qrFs);
                            @exec($cmd . ' 2>&1', $tmpOut, $tmpExit);
                        }
                        $qrUrl = is_file($qrFs) ? $qrRel : '';
                        ?>
                        <?php if ($qrUrl !== ''): ?>
                            <a href="<?php echo h($qrUrl); ?>" target="_blank" rel="noopener">
                                <img src="<?php echo h($qrUrl); ?>" alt="QR" width="80" height="80" />
                            </a>
                        <?php else: ?>
                            <span style="color:#a00;">QR not available</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" onsubmit="return confirm('Delete this graduate?');" style="display:inline;">
                            <input type="hidden" name="action" value="delete" />
                            <input type="hidden" name="id" value="<?php echo (int)$r['id']; ?>" />
                            <button type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <p>Use the QR code or Student ID at Attendance to check in.</p>
    <p><a href="?page=attendance">Go to Attendance</a></p>
</div>

<script>
(function(){
    var cgpaInput = document.querySelector('input[name="cgpa"]');
    var categoryPreview = document.getElementById('categoryPreview');
    function updateCategory(){
        var v = parseFloat(cgpaInput.value);
        if (isNaN(v)) { 
            categoryPreview.value = ''; 
            cgpaInput.setCustomValidity('');
            return; 
        }
        
        // Validate CGPA range (2.0-4.0)
        if (v < 2.0) {
            categoryPreview.value = 'Cannot graduate (CGPA < 2.0)';
            cgpaInput.setCustomValidity('CGPA must be at least 2.0 to graduate');
            return;
        }
        if (v > 4.0) {
            categoryPreview.value = 'Invalid CGPA (> 4.0)';
            cgpaInput.setCustomValidity('CGPA cannot exceed 4.0');
            return;
        }
        
        // Clear any previous validation errors
        cgpaInput.setCustomValidity('');
        
        // Set category based on valid CGPA
        if (v >= 3.75) { categoryPreview.value = 'Graduate with Distinction'; }
        else if (v >= 2.67) { categoryPreview.value = 'Graduate with Merit'; }
        else if (v >= 2.0) { categoryPreview.value = 'Graduate with Pass'; }
        else { categoryPreview.value = ''; }
    }
    if (cgpaInput) { cgpaInput.addEventListener('input', updateCategory); }

    var startBtn = document.getElementById('startCameraBtn');
    var stopBtn = document.getElementById('stopCameraBtn');
    var captureBtn = document.getElementById('captureBtn');
    var video = document.getElementById('camera');
    var canvas = document.getElementById('snapshot');
    var capturedField = document.getElementById('captured_photo');
    var photoInput = document.getElementById('photoInput');
    var uploadPreview = document.getElementById('uploadPreview');
    var modeCamera = document.getElementById('mode_camera');
    var modeUpload = document.getElementById('mode_upload');
    var cameraSection = document.getElementById('cameraSection');
    var uploadSection = document.getElementById('uploadSection');
    var streamRef = null;

    // Fingerprint scanner variables
    var modeFingerprintScanner = document.getElementById('mode_fingerprint_scanner');
    var modeFingerprintUpload = document.getElementById('mode_fingerprint_upload');
    var fingerprintScannerSection = document.getElementById('fingerprintScannerSection');
    var fingerprintUploadSection = document.getElementById('fingerprintUploadSection');
    var fingerprintStreamRef = null;

    function stopStream(){
        if (streamRef) {
            streamRef.getTracks().forEach(function(t){ t.stop(); });
            streamRef = null;
        }
        video.style.display = 'none';
        captureBtn.disabled = true;
        stopBtn.disabled = true;
    }

    startBtn.addEventListener('click', function(){
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { alert('Camera not supported'); return; }
        navigator.mediaDevices.getUserMedia({ video: true }).then(function(stream){
            streamRef = stream;
            video.srcObject = stream;
            video.style.display = 'block';
            captureBtn.disabled = false;
            stopBtn.disabled = false;
        }).catch(function(err){ alert('Unable to access camera: ' + err); });
    });

    stopBtn.addEventListener('click', function(){
        stopStream();
    });

    captureBtn.addEventListener('click', function(){
        if (!streamRef) return;
        var ctx = canvas.getContext('2d');
        canvas.style.display = 'block';
        // Downscale to reasonable size to keep POST small
        var w = video.videoWidth || 640;
        var h = video.videoHeight || 480;
        var maxSide = 800;
        var scale = Math.min(1, maxSide / Math.max(w, h));
        canvas.width = Math.round(w * scale);
        canvas.height = Math.round(h * scale);
        ctx.drawImage(video, 0, 0, canvas.width, canvas.height);
        // Use JPEG for smaller size, quality 0.9
        capturedField.value = canvas.toDataURL('image/jpeg', 0.9);
        stopStream();
    });

    // Fingerprint scanner functionality
    var startFingerprintBtn = document.getElementById('startFingerprintScannerBtn');
    var stopFingerprintBtn = document.getElementById('stopFingerprintScannerBtn');
    var captureFingerprintBtn = document.getElementById('captureFingerprintBtn');
    var fingerprintVideo = document.getElementById('fingerprintScanner');
    var fingerprintCanvas = document.getElementById('fingerprintSnapshot');
    var capturedFingerprintField = document.getElementById('captured_fingerprint');
    var fingerprintInput = document.getElementById('fingerprintInput');
    var fingerprintUploadPreview = document.getElementById('fingerprintUploadPreview');

    function stopFingerprintStream(){
        if (fingerprintStreamRef) {
            fingerprintStreamRef.getTracks().forEach(function(t){ t.stop(); });
            fingerprintStreamRef = null;
        }
        fingerprintVideo.style.display = 'none';
        captureFingerprintBtn.disabled = true;
        stopFingerprintBtn.disabled = true;
    }

    startFingerprintBtn.addEventListener('click', function(){
        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { alert('Fingerprint scanner not supported'); return; }
        navigator.mediaDevices.getUserMedia({ video: true }).then(function(stream){
            fingerprintStreamRef = stream;
            fingerprintVideo.srcObject = stream;
            fingerprintVideo.style.display = 'block';
            captureFingerprintBtn.disabled = false;
            stopFingerprintBtn.disabled = false;
        }).catch(function(err){ alert('Unable to access fingerprint scanner: ' + err); });
    });

    stopFingerprintBtn.addEventListener('click', function(){
        stopFingerprintStream();
    });

    captureFingerprintBtn.addEventListener('click', function(){
        if (!fingerprintStreamRef) return;
        var ctx = fingerprintCanvas.getContext('2d');
        fingerprintCanvas.style.display = 'block';
        // Downscale to reasonable size to keep POST small
        var w = fingerprintVideo.videoWidth || 640;
        var h = fingerprintVideo.videoHeight || 480;
        var maxSide = 800;
        var scale = Math.min(1, maxSide / Math.max(w, h));
        fingerprintCanvas.width = Math.round(w * scale);
        fingerprintCanvas.height = Math.round(h * scale);
        ctx.drawImage(fingerprintVideo, 0, 0, fingerprintCanvas.width, fingerprintCanvas.height);
        // Use JPEG for smaller size, quality 0.9
        capturedFingerprintField.value = fingerprintCanvas.toDataURL('image/jpeg', 0.9);
        stopFingerprintStream();
    });

    // Show preview for uploaded file
    if (photoInput) {
        photoInput.addEventListener('change', function(){
            capturedField.value = '';
            if (photoInput.files && photoInput.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e){
                    uploadPreview.src = e.target.result;
                    uploadPreview.style.display = 'block';
                };
                reader.readAsDataURL(photoInput.files[0]);
            } else {
                uploadPreview.src = '';
                uploadPreview.style.display = 'none';
            }
        });
    }

    // Show preview for uploaded fingerprint file
    if (fingerprintInput) {
        fingerprintInput.addEventListener('change', function(){
            capturedFingerprintField.value = '';
            if (fingerprintInput.files && fingerprintInput.files[0]) {
                var reader = new FileReader();
                reader.onload = function(e){
                    fingerprintUploadPreview.src = e.target.result;
                    fingerprintUploadPreview.style.display = 'block';
                };
                reader.readAsDataURL(fingerprintInput.files[0]);
            } else {
                fingerprintUploadPreview.src = '';
                fingerprintUploadPreview.style.display = 'none';
            }
        });
    }

    function updateModeUI(){
        if (modeCamera && modeCamera.checked) {
            cameraSection.style.display = 'flex';
            uploadSection.style.display = 'none';
            // Clear upload when switching to camera
            if (photoInput) { photoInput.value = ''; uploadPreview.src=''; uploadPreview.style.display='none'; }
        } else {
            cameraSection.style.display = 'none';
            uploadSection.style.display = 'flex';
            // Stop camera if switching to upload
            stopStream();
            capturedField.value = '';
            canvas.style.display = 'none';
        }
    }
    if (modeCamera) { modeCamera.addEventListener('change', updateModeUI); }
    if (modeUpload) { modeUpload.addEventListener('change', updateModeUI); }
    updateModeUI();

    function updateFingerprintModeUI(){
        if (modeFingerprintScanner && modeFingerprintScanner.checked) {
            fingerprintScannerSection.style.display = 'flex';
            fingerprintUploadSection.style.display = 'none';
            // Clear upload when switching to scanner
            if (fingerprintInput) { fingerprintInput.value = ''; fingerprintUploadPreview.src=''; fingerprintUploadPreview.style.display='none'; }
        } else {
            fingerprintScannerSection.style.display = 'none';
            fingerprintUploadSection.style.display = 'flex';
            // Stop scanner if switching to upload
            stopFingerprintStream();
            capturedFingerprintField.value = '';
            fingerprintCanvas.style.display = 'none';
        }
    }
    if (modeFingerprintScanner) { modeFingerprintScanner.addEventListener('change', updateFingerprintModeUI); }
    if (modeFingerprintUpload) { modeFingerprintUpload.addEventListener('change', updateFingerprintModeUI); }
    updateFingerprintModeUI();

    // Client-side photo required validation
    var form = document.getElementById('registerForm');
    form.addEventListener('submit', function(e){
        var hasUpload = photoInput && photoInput.files && photoInput.files.length > 0;
        var hasCapture = capturedField && capturedField.value && capturedField.value.indexOf('data:image') === 0;
        // Enforce based on selected mode
        if (modeCamera && modeCamera.checked) {
            if (!hasCapture) {
                e.preventDefault();
                alert('Please capture a photo from the camera.');
                return;
            }
        } else {
            if (!hasUpload) {
                e.preventDefault();
                alert('Please select a photo to upload.');
                return;
            }
        }
        // Prevent both simultaneously
        if (hasUpload && hasCapture) {
            e.preventDefault();
            alert('Choose only one method: upload OR capture.');
        }

        // Fingerprint validation
        var hasFingerprintUpload = fingerprintInput && fingerprintInput.files && fingerprintInput.files.length > 0;
        var hasFingerprintCapture = capturedFingerprintField && capturedFingerprintField.value && capturedFingerprintField.value.indexOf('data:image') === 0;
        // Enforce based on selected mode
        if (modeFingerprintScanner && modeFingerprintScanner.checked) {
            if (!hasFingerprintCapture) {
                e.preventDefault();
                alert('Please capture a fingerprint from the scanner.');
                return;
            }
        } else {
            if (!hasFingerprintUpload) {
                e.preventDefault();
                alert('Please select a fingerprint to upload.');
                return;
            }
        }
        // Prevent both simultaneously
        if (hasFingerprintUpload && hasFingerprintCapture) {
            e.preventDefault();
            alert('Choose only one method for fingerprint: upload OR capture.');
        }
    });

    // Convert student ID to lowercase
    var studentIdInput = document.querySelector('input[name="student_id"]');
    if (studentIdInput) {
        studentIdInput.addEventListener('input', function() {
            this.value = this.value.toLowerCase();
        });
    }
})();
</script>




