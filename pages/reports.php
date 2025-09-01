<?php 
require_once __DIR__ . '/../lib/db.php'; 
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }

$db = get_db();
$currentSessionId = isset($_SESSION['current_session_id']) ? (int)$_SESSION['current_session_id'] : 0;

// Get all sessions for filtering
$sessions = $db->query('SELECT id, name FROM sessions ORDER BY created_at DESC')->fetchAll(PDO::FETCH_ASSOC);

// Get current session name
$currentSessionName = '';
if ($currentSessionId > 0) {
    $stmt = $db->prepare('SELECT name FROM sessions WHERE id = ?');
    $stmt->execute([$currentSessionId]);
    $currentSessionName = $stmt->fetchColumn() ?: '';
}

// Initialize metrics tables if they don't exist
$db->exec('
    CREATE TABLE IF NOT EXISTS verification_metrics (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        student_id TEXT NOT NULL,
        verification_type TEXT NOT NULL,
        verification_method TEXT NOT NULL,
        is_successful BOOLEAN NOT NULL,
        confidence_score REAL,
        processing_time_ms INTEGER,
        timestamp DATETIME DEFAULT CURRENT_TIMESTAMP,
        session_id INTEGER,
        error_message TEXT,
        FOREIGN KEY (session_id) REFERENCES sessions(id)
    )
');

$db->exec('
    CREATE TABLE IF NOT EXISTS attendance_summary (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        session_id INTEGER NOT NULL,
        total_students INTEGER DEFAULT 0,
        face_verified_count INTEGER DEFAULT 0,
        fingerprint_verified_count INTEGER DEFAULT 0,
        total_verification_time_ms INTEGER DEFAULT 0,
        average_verification_time_ms REAL DEFAULT 0,
        success_rate REAL DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (session_id) REFERENCES sessions(id)
    )
');

// Get basic statistics for current session
$currentSessionStats = null;
if ($currentSessionId > 0) {
    $stmt = $db->prepare('
        SELECT 
            COUNT(*) as total_verifications,
            SUM(CASE WHEN is_successful = 1 THEN 1 ELSE 0 END) as successful_verifications,
            AVG(CASE WHEN is_successful = 1 THEN confidence_score ELSE NULL END) as avg_confidence,
            AVG(CASE WHEN is_successful = 1 THEN processing_time_ms ELSE NULL END) as avg_processing_time,
            SUM(CASE WHEN verification_method = "face" THEN 1 ELSE 0 END) as face_verifications,
            SUM(CASE WHEN verification_method = "fingerprint" THEN 1 ELSE 0 END) as fingerprint_verifications
        FROM verification_metrics 
        WHERE session_id = ?
    ');
    $stmt->execute([$currentSessionId]);
    $currentSessionStats = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<div class="card">
    <h2>📊 Verification & Attendance Reports</h2>
    
    <!-- Session Selection -->
    <div class="session-selection" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
        <label for="sessionSelect" style="font-weight: bold; margin-right: 10px;">Select Session:</label>
        <select id="sessionSelect" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-right: 10px;">
            <option value="">All Sessions</option>
            <?php foreach ($sessions as $session): ?>
                <option value="<?php echo $session['id']; ?>" <?php echo $session['id'] == $currentSessionId ? 'selected' : ''; ?>>
                    <?php echo htmlspecialchars($session['name']); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button id="refreshReportsBtn" type="button" style="padding: 8px 16px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Refresh Reports</button>
    </div>

    <!-- Current Session Summary -->
    <?php if ($currentSessionId > 0 && $currentSessionStats): ?>
    <div class="current-session-summary" style="margin-bottom: 20px; padding: 15px; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 6px;">
        <h4 style="margin-top: 0; color: #0056b3;">Current Session: <?php echo htmlspecialchars($currentSessionName); ?></h4>
        <div class="stats-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
            <div class="stat-item" style="text-align: center; padding: 10px; background: white; border-radius: 4px;">
                <div class="stat-number" style="font-size: 24px; font-weight: bold; color: #28a745;"><?php echo $currentSessionStats['total_verifications'] ?? 0; ?></div>
                <div class="stat-label" style="color: #666; font-size: 14px;">Total Verifications</div>
            </div>
            <div class="stat-item" style="text-align: center; padding: 10px; background: white; border-radius: 4px;">
                <div class="stat-number" style="font-size: 24px; font-weight: bold; color: #007bff;"><?php echo $currentSessionStats['successful_verifications'] ?? 0; ?></div>
                <div class="stat-label" style="color: #666; font-size: 14px;">Successful</div>
            </div>
            <div class="stat-item" style="text-align: center; padding: 10px; background: white; border-radius: 4px;">
                <div class="stat-number" style="font-size: 24px; font-weight: bold; color: #ffc107;"><?php echo round(($currentSessionStats['avg_confidence'] ?? 0) * 100, 1); ?>%</div>
                <div class="stat-label" style="color: #666; font-size: 14px;">Avg Confidence</div>
            </div>
            <div class="stat-item" style="text-align: center; padding: 10px; background: white; border-radius: 4px;">
                <div class="stat-number" style="font-size: 24px; font-weight: bold; color: #17a2b8;"><?php echo round(($currentSessionStats['avg_processing_time'] ?? 0) / 1000, 2); ?>s</div>
                <div class="stat-label" style="color: #666; font-size: 14px;">Avg Time</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Report Tabs -->
    <div class="report-tabs" style="margin-bottom: 20px;">
        <button class="tab-btn active" data-tab="accuracy" style="padding: 10px 20px; margin-right: 5px; border: 1px solid #ddd; background: #007bff; color: white; border-radius: 4px 4px 0 0; cursor: pointer;">Accuracy Report</button>
        <button class="tab-btn" data-tab="timing" style="padding: 10px 20px; margin-right: 5px; border: 1px solid #ddd; background: #f8f9fa; color: #333; border-radius: 4px 4px 0 0; cursor: pointer;">Verification Time</button>
        <button class="tab-btn" data-tab="attendance" style="padding: 10px 20px; margin-right: 5px; border: 1px solid #ddd; background: #f8f9fa; color: #333; border-radius: 4px 4px 0 0; cursor: pointer;">Attendance Report</button>
        <button class="tab-btn" data-tab="detailed" style="padding: 10px 20px; border: 1px solid #ddd; background: #f8f9fa; color: #333; border-radius: 4px 4px 0 0; cursor: pointer;">Detailed Metrics</button>
    </div>

    <!-- Date Range Filter -->
    <div class="date-filter" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 6px;">
        <label style="font-weight: bold; margin-right: 10px;">Date Range:</label>
        <input type="date" id="dateFrom" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-right: 10px;">
        <span style="margin-right: 10px;">to</span>
        <input type="date" id="dateTo" style="padding: 8px; border: 1px solid #ddd; border-radius: 4px; margin-right: 10px;">
        <button id="applyDateFilter" type="button" style="padding: 8px 16px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer;">Apply Filter</button>
        <button id="resetDateFilter" type="button" style="padding: 8px 16px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer; margin-left: 10px;">Reset</button>
    </div>

    <!-- Tab Content -->
    <div id="accuracyTab" class="tab-content active">
        <div class="card">
            <h3>🎯 Accuracy Report</h3>
            <div class="accuracy-metrics">
                <div class="metric-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                    <div class="metric-card" style="padding: 20px; background: white; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
                        <h4 style="margin-top: 0; color: #28a745;">Overall Success Rate</h4>
                        <div id="overallSuccessRate" class="metric-value" style="font-size: 36px; font-weight: bold; color: #28a745;">-</div>
                        <div class="metric-description" style="color: #666; margin-top: 10px;">All verification methods combined</div>
                    </div>
                    <div class="metric-card" style="padding: 20px; background: white; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
                        <h4 style="margin-top: 0; color: #007bff;">Face Verification</h4>
                        <div id="faceSuccessRate" class="metric-value" style="font-size: 36px; font-weight: bold; color: #007bff;">-</div>
                        <div class="metric-description" style="color: #666; margin-top: 10px;">Face recognition accuracy</div>
                    </div>
                    <div class="metric-card" style="padding: 20px; background: white; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
                        <h4 style="margin-top: 0; color: #ffc107;">Fingerprint Verification</h4>
                        <div id="fingerprintSuccessRate" class="metric-value" style="font-size: 36px; font-weight: bold; color: #ffc107;">-</div>
                        <div class="metric-description" style="color: #666; margin-top: 10px;">Fingerprint matching accuracy</div>
                    </div>
                </div>
                
                <div class="accuracy-chart" style="margin-top: 30px;">
                    <canvas id="accuracyChart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div id="timingTab" class="tab-content" style="display: none;">
        <div class="card">
            <h3>⏱️ Verification Time Analysis</h3>
            <div class="timing-metrics">
                <div class="metric-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                    <div class="metric-card" style="padding: 20px; background: white; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
                        <h4 style="margin-top: 0; color: #17a2b8;">Average Time</h4>
                        <div id="avgVerificationTime" class="metric-value" style="font-size: 36px; font-weight: bold; color: #17a2b8;">-</div>
                        <div class="metric-description" style="color: #666; margin-top: 10px;">Seconds per verification</div>
                    </div>
                    <div class="metric-card" style="padding: 20px; background: white; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
                        <h4 style="margin-top: 0; color: #6f42c1;">Fastest Time</h4>
                        <div id="fastestTime" class="metric-value" style="font-size: 36px; font-weight: bold; color: #6f42c1;">-</div>
                        <div class="metric-description" style="color: #666; margin-top: 10px;">Quickest verification</div>
                    </div>
                    <div class="metric-card" style="padding: 20px; background: white; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
                        <h4 style="margin-top: 0; color: #e83e8c;">Total Time</h4>
                        <div id="totalVerificationTime" class="metric-value" style="font-size: 36px; font-weight: bold; color: #e83e8c;">-</div>
                        <div class="metric-description" style="color: #666; margin-top: 10px;">Cumulative time spent</div>
                    </div>
                </div>
                
                <div class="timing-chart" style="margin-top: 30px;">
                    <canvas id="timingChart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div id="attendanceTab" class="tab-content" style="display: none;">
        <div class="card">
            <h3>👥 Attendance Summary</h3>
            <div class="attendance-metrics">
                <div class="metric-row" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px;">
                    <div class="metric-card" style="padding: 20px; background: white; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
                        <h4 style="margin-top: 0; color: #28a745;">Total Students</h4>
                        <div id="totalStudents" class="metric-value" style="font-size: 36px; font-weight: bold; color: #28a745;">-</div>
                        <div class="metric-description" style="color: #666; margin-top: 10px;">Registered for session</div>
                    </div>
                    <div class="metric-card" style="padding: 20px; background: white; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
                        <h4 style="margin-top: 0; color: #007bff;">Face Verified</h4>
                        <div id="faceVerifiedCount" class="metric-value" style="font-size: 36px; font-weight: bold; color: #007bff;">-</div>
                        <div class="metric-description" style="color: #666; margin-top: 10px;">Students verified by face</div>
                    </div>
                    <div class="metric-card" style="padding: 20px; background: white; border: 1px solid #ddd; border-radius: 6px; text-align: center;">
                        <h4 style="margin-top: 0; color: #ffc107;">Fingerprint Verified</h4>
                        <div id="fingerprintVerifiedCount" class="metric-value" style="font-size: 36px; font-weight: bold; color: #ffc107;">-</div>
                        <div class="metric-description" style="color: #666; margin-top: 10px;">Students verified by fingerprint</div>
                    </div>
                </div>
                
                <div class="attendance-chart" style="margin-top: 30px;">
                    <canvas id="attendanceChart" width="400" height="200"></canvas>
                </div>
            </div>
        </div>
    </div>

    <div id="detailedTab" class="tab-content" style="display: none;">
        <div class="card">
            <h3>📋 Detailed Verification Metrics</h3>
            <div class="detailed-metrics">
                <div class="filters" style="margin-bottom: 20px;">
                    <label for="verificationTypeFilter" style="margin-right: 15px;">
                        <strong>Verification Type:</strong>
                        <select id="verificationTypeFilter" style="margin-left: 5px; padding: 5px;">
                            <option value="">All</option>
                            <option value="face">Face</option>
                            <option value="fingerprint">Fingerprint</option>
                        </select>
                    </label>
                    <label for="successFilter" style="margin-right: 15px;">
                        <strong>Status:</strong>
                        <select id="successFilter" style="margin-left: 5px; padding: 5px;">
                            <option value="">All</option>
                            <option value="1">Successful</option>
                            <option value="0">Failed</option>
                        </select>
                    </label>
                </div>
                
                <div class="metrics-table-container" style="overflow-x: auto;">
                    <table id="metricsTable" style="width: 100%; border-collapse: collapse; margin-top: 20px;">
                        <thead>
                            <tr style="background: #f8f9fa;">
                                <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Student ID</th>
                                <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Name</th>
                                <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Method</th>
                                <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Status</th>
                                <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Confidence</th>
                                <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Time (ms)</th>
                                <th style="padding: 12px; border: 1px solid #ddd; text-align: left;">Timestamp</th>
                            </tr>
                        </thead>
                        <tbody id="metricsTableBody">
                            <!-- Data will be populated by JavaScript -->
                        </tbody>
                    </table>
                </div>
                
                <div class="pagination" style="margin-top: 20px; text-align: center;">
                    <button id="prevPage" style="padding: 8px 16px; margin-right: 10px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Previous</button>
                    <span id="pageInfo" style="margin: 0 15px;">Page 1 of 1</span>
                    <button id="nextPage" style="padding: 8px 16px; margin-left: 10px; background: #6c757d; color: white; border: none; border-radius: 4px; cursor: pointer;">Next</button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Export Section -->
<div class="card" style="margin-top: 20px;">
    <h3>📤 Export Reports</h3>
    <div class="export-options" style="display: flex; gap: 15px; flex-wrap: wrap;">
        <button id="exportCSV" type="button" style="padding: 10px 20px; background: #28a745; color: white; border: none; border-radius: 4px; cursor: pointer;">Export to CSV</button>
        <button id="exportJSON" type="button" style="padding: 10px 20px; background: #007bff; color: white; border: none; border-radius: 4px; cursor: pointer;">Export to JSON</button>
        <button id="exportPDF" type="button" style="padding: 10px 20px; background: #dc3545; color: white; border: none; border-radius: 4px; cursor: pointer;">Export to PDF</button>
    </div>
</div>

<script>
// Global variables
let currentPage = 1;
let itemsPerPage = 20;
let allMetrics = [];
let filteredMetrics = [];

// Initialize page
document.addEventListener('DOMContentLoaded', function() {
    initializePage();
    setupEventListeners();
    loadReports();
});

function initializePage() {
    // Set default date range (last 30 days)
    const today = new Date();
    const thirtyDaysAgo = new Date(today.getTime() - (30 * 24 * 60 * 60 * 1000));
    
    document.getElementById('dateFrom').value = thirtyDaysAgo.toISOString().split('T')[0];
    document.getElementById('dateTo').value = today.toISOString().split('T')[0];
}

function setupEventListeners() {
    // Tab switching
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            switchTab(this.dataset.tab);
        });
    });
    
    // Session selection
    document.getElementById('sessionSelect').addEventListener('change', function() {
        loadReports();
    });
    
    // Date filter
    document.getElementById('applyDateFilter').addEventListener('click', loadReports);
    document.getElementById('resetDateFilter').addEventListener('click', resetDateFilter);
    
    // Refresh button
    document.getElementById('refreshReportsBtn').addEventListener('click', loadReports);
    
    // Pagination
    document.getElementById('prevPage').addEventListener('click', () => changePage(-1));
    document.getElementById('nextPage').addEventListener('click', () => changePage(1));
    
    // Export buttons
    document.getElementById('exportCSV').addEventListener('click', () => exportData('csv'));
    document.getElementById('exportJSON').addEventListener('click', () => exportData('json'));
    document.getElementById('exportPDF').addEventListener('click', () => exportData('pdf'));
    
    // Detailed metrics filters
    document.getElementById('verificationTypeFilter').addEventListener('change', filterMetrics);
    document.getElementById('successFilter').addEventListener('click', filterMetrics);
}

function switchTab(tabName) {
    // Hide all tab contents
    document.querySelectorAll('.tab-content').forEach(content => {
        content.style.display = 'none';
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.classList.remove('active');
        btn.style.background = '#f8f9fa';
        btn.style.color = '#333';
    });
    
    // Show selected tab content
    document.getElementById(tabName + 'Tab').style.display = 'block';
    
    // Activate selected tab button
    const activeBtn = document.querySelector(`[data-tab="${tabName}"]`);
    activeBtn.classList.add('active');
    activeBtn.style.background = '#007bff';
    activeBtn.style.color = 'white';
    
    // Load specific tab data if needed
    if (tabName === 'detailed') {
        loadDetailedMetrics();
    }
}

function resetDateFilter() {
    const today = new Date();
    const thirtyDaysAgo = new Date(today.getTime() - (30 * 24 * 60 * 60 * 1000));
    
    document.getElementById('dateFrom').value = thirtyDaysAgo.toISOString().split('T')[0];
    document.getElementById('dateTo').value = today.toISOString().split('T')[0];
    
    loadReports();
}

function loadReports() {
    const sessionId = document.getElementById('sessionSelect').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    
    // Show loading state
    showLoading();
    
    // Build API URL
    let url = 'api/track_verification_metrics.php?';
    if (sessionId) url += `session_id=${sessionId}&`;
    if (dateFrom) url += `date_from=${dateFrom}&`;
    if (dateTo) url += `date_to=${dateTo}&`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateReports(data.data);
            } else {
                showError('Failed to load reports: ' + data.message);
            }
        })
        .catch(error => {
            showError('Error loading reports: ' + error.message);
        })
        .finally(() => {
            hideLoading();
        });
}

function updateReports(data) {
    updateAccuracyMetrics(data);
    updateTimingMetrics(data);
    updateAttendanceMetrics(data);
    
    // Store metrics for detailed view
    allMetrics = data.metrics || [];
    filteredMetrics = [...allMetrics];
    
    // Update current tab if it's detailed
    if (document.querySelector('#detailedTab').style.display !== 'none') {
        loadDetailedMetrics();
    }
}

function updateAccuracyMetrics(data) {
    const summary = data.summary || {};
    const metrics = data.metrics || [];
    
    // Calculate success rates
    const totalVerifications = summary.total_verifications || 0;
    const successfulVerifications = summary.successful_verifications || 0;
    const overallSuccessRate = totalVerifications > 0 ? (successfulVerifications / totalVerifications * 100).toFixed(1) : 0;
    
    // Calculate method-specific rates
    const faceVerifications = metrics.filter(m => m.verification_method === 'face');
    const fingerprintVerifications = metrics.filter(m => m.verification_method === 'fingerprint');
    
    const faceSuccessRate = faceVerifications.length > 0 ? 
        (faceVerifications.filter(m => m.is_successful == 1).length / faceVerifications.length * 100).toFixed(1) : 0;
    
    const fingerprintSuccessRate = fingerprintVerifications.length > 0 ? 
        (fingerprintVerifications.filter(m => m.is_successful == 1).length / fingerprintVerifications.length * 100).toFixed(1) : 0;
    
    // Update UI
    document.getElementById('overallSuccessRate').textContent = overallSuccessRate + '%';
    document.getElementById('faceSuccessRate').textContent = faceSuccessRate + '%';
    document.getElementById('fingerprintSuccessRate').textContent = fingerprintSuccessRate + '%';
    
    // Update chart if Chart.js is available
    if (typeof Chart !== 'undefined') {
        updateAccuracyChart(overallSuccessRate, faceSuccessRate, fingerprintSuccessRate);
    }
}

function updateTimingMetrics(data) {
    const summary = data.summary || {};
    const metrics = data.metrics || [];
    
    // Calculate timing metrics
    const avgTime = summary.avg_processing_time || 0;
    const totalTime = summary.total_verification_time_ms || 0;
    
    // Find fastest time
    const successfulMetrics = metrics.filter(m => m.is_successful == 1 && m.processing_time_ms);
    const fastestTime = successfulMetrics.length > 0 ? Math.min(...successfulMetrics.map(m => m.processing_time_ms)) : 0;
    
    // Update UI
    document.getElementById('avgVerificationTime').textContent = (avgTime / 1000).toFixed(2) + 's';
    document.getElementById('fastestTime').textContent = (fastestTime / 1000).toFixed(2) + 's';
    document.getElementById('totalVerificationTime').textContent = (totalTime / 1000 / 60).toFixed(1) + 'm';
    
    // Update chart if Chart.js is available
    if (typeof Chart !== 'undefined') {
        updateTimingChart(metrics);
    }
}

function updateAttendanceMetrics(data) {
    const attendanceSummary = data.attendance_summary || [];
    const currentSessionId = document.getElementById('sessionSelect').value;
    
    let summary = null;
    if (currentSessionId) {
        summary = attendanceSummary.find(s => s.session_id == currentSessionId);
    } else if (attendanceSummary.length > 0) {
        summary = attendanceSummary[0]; // Most recent
    }
    
    if (summary) {
        document.getElementById('totalStudents').textContent = summary.total_students || 0;
        document.getElementById('faceVerifiedCount').textContent = summary.face_verified_count || 0;
        document.getElementById('fingerprintVerifiedCount').textContent = summary.fingerprint_verified_count || 0;
        
        // Update chart if Chart.js is available
        if (typeof Chart !== 'undefined') {
            updateAttendanceChart(summary);
        }
    } else {
        // No data available
        document.getElementById('totalStudents').textContent = '-';
        document.getElementById('faceVerifiedCount').textContent = '-';
        document.getElementById('fingerprintVerifiedCount').textContent = '-';
    }
}

function loadDetailedMetrics() {
    currentPage = 1;
    filterMetrics();
}

function filterMetrics() {
    const verificationType = document.getElementById('verificationTypeFilter').value;
    const successFilter = document.getElementById('successFilter').value;
    
    filteredMetrics = allMetrics.filter(metric => {
        if (verificationType && metric.verification_method !== verificationType) return false;
        if (successFilter !== '' && metric.is_successful != successFilter) return false;
        return true;
    });
    
    displayMetricsPage();
}

function displayMetricsPage() {
    const startIndex = (currentPage - 1) * itemsPerPage;
    const endIndex = startIndex + itemsPerPage;
    const pageMetrics = filteredMetrics.slice(startIndex, endIndex);
    
    const tbody = document.getElementById('metricsTableBody');
    tbody.innerHTML = '';
    
    pageMetrics.forEach(metric => {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td style="padding: 8px; border: 1px solid #ddd;">${metric.student_id || '-'}</td>
            <td style="padding: 8px; border: 1px solid #ddd;">${metric.full_name || '-'}</td>
            <td style="padding: 8px; border: 1px solid #ddd;">${metric.verification_method || '-'}</td>
            <td style="padding: 8px; border: 1px solid #ddd;">
                <span style="color: ${metric.is_successful == 1 ? '#28a745' : '#dc3545'}; font-weight: bold;">
                    ${metric.is_successful == 1 ? '✓ Success' : '✗ Failed'}
                </span>
            </td>
            <td style="padding: 8px; border: 1px solid #ddd;">${metric.confidence_score ? (metric.confidence_score * 100).toFixed(1) + '%' : '-'}</td>
            <td style="padding: 8px; border: 1px solid #ddd;">${metric.processing_time_ms || '-'}</td>
            <td style="padding: 8px; border: 1px solid #ddd;">${metric.timestamp || '-'}</td>
        `;
        tbody.appendChild(row);
    });
    
    updatePagination();
}

function updatePagination() {
    const totalPages = Math.ceil(filteredMetrics.length / itemsPerPage);
    const pageInfo = document.getElementById('pageInfo');
    const prevBtn = document.getElementById('prevPage');
    const nextBtn = document.getElementById('nextPage');
    
    pageInfo.textContent = `Page ${currentPage} of ${totalPages}`;
    prevBtn.disabled = currentPage <= 1;
    nextBtn.disabled = currentPage >= totalPages;
    
    prevBtn.style.opacity = currentPage <= 1 ? '0.5' : '1';
    nextBtn.style.opacity = currentPage >= totalPages ? '0.5' : '1';
}

function changePage(delta) {
    const newPage = currentPage + delta;
    const totalPages = Math.ceil(filteredMetrics.length / itemsPerPage);
    
    if (newPage >= 1 && newPage <= totalPages) {
        currentPage = newPage;
        displayMetricsPage();
    }
}

function exportData(format) {
    const sessionId = document.getElementById('sessionSelect').value;
    const dateFrom = document.getElementById('dateFrom').value;
    const dateTo = document.getElementById('dateTo').value;
    
    let url = 'api/track_verification_metrics.php?';
    if (sessionId) url += `session_id=${sessionId}&`;
    if (dateFrom) url += `date_from=${dateFrom}&`;
    if (dateTo) url += `date_to=${dateTo}&`;
    
    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                if (format === 'csv') {
                    exportToCSV(data.data.metrics);
                } else if (format === 'json') {
                    exportToJSON(data.data);
                } else if (format === 'pdf') {
                    exportToPDF(data.data);
                }
            }
        })
        .catch(error => {
            showError('Error exporting data: ' + error.message);
        });
}

function exportToCSV(metrics) {
    if (!metrics || metrics.length === 0) {
        showError('No data to export');
        return;
    }
    
    const headers = ['Student ID', 'Name', 'Verification Method', 'Status', 'Confidence', 'Processing Time (ms)', 'Timestamp'];
    const csvContent = [
        headers.join(','),
        ...metrics.map(metric => [
            metric.student_id || '',
            metric.full_name || '',
            metric.verification_method || '',
            metric.is_successful == 1 ? 'Success' : 'Failed',
            metric.confidence_score ? (metric.confidence_score * 100).toFixed(1) : '',
            metric.processing_time_ms || '',
            metric.timestamp || ''
        ].join(','))
    ].join('\n');
    
    const blob = new Blob([csvContent], { type: 'text/csv' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `verification_metrics_${new Date().toISOString().split('T')[0]}.csv`;
    a.click();
    window.URL.revokeObjectURL(url);
}

function exportToJSON(data) {
    const blob = new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' });
    const url = window.URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = `verification_metrics_${new Date().toISOString().split('T')[0]}.json`;
    a.click();
    window.URL.revokeObjectURL(url);
}

function exportToPDF(data) {
    showError('PDF export functionality requires additional libraries. Please use CSV or JSON export instead.');
}

function showLoading() {
    // Add loading indicator to the page
    const loadingDiv = document.createElement('div');
    loadingDiv.id = 'loadingIndicator';
    loadingDiv.innerHTML = '<div style="text-align: center; padding: 20px;"><strong>Loading reports...</strong></div>';
    loadingDiv.style.cssText = 'position: fixed; top: 50%; left: 50%; transform: translate(-50%, -50%); background: white; border: 1px solid #ddd; border-radius: 6px; z-index: 1000; box-shadow: 0 4px 6px rgba(0,0,0,0.1);';
    document.body.appendChild(loadingDiv);
}

function hideLoading() {
    const loadingDiv = document.getElementById('loadingIndicator');
    if (loadingDiv) {
        loadingDiv.remove();
    }
}

function showError(message) {
    alert('Error: ' + message);
}

// Chart.js functions (will be implemented if Chart.js is available)
function updateAccuracyChart(overall, face, fingerprint) {
    // Implementation for accuracy chart
}

function updateTimingChart(metrics) {
    // Implementation for timing chart
}

function updateAttendanceChart(summary) {
    // Implementation for attendance chart
}
</script>

<style>
.card {
    background: white;
    border: 1px solid #ddd;
    border-radius: 6px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.card h2, .card h3, .card h4 {
    margin-top: 0;
    color: #333;
}

.metric-card {
    transition: transform 0.2s ease-in-out;
}

.metric-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.stat-item {
    text-align: center;
    padding: 15px;
    background: white;
    border-radius: 6px;
    border: 1px solid #e9ecef;
}

.stat-number {
    font-size: 28px;
    font-weight: bold;
    margin-bottom: 5px;
}

.stat-label {
    color: #666;
    font-size: 14px;
}

.tab-btn {
    transition: all 0.2s ease-in-out;
}

.tab-btn:hover {
    background: #0056b3 !important;
    color: white !important;
}

.metrics-table-container {
    max-height: 500px;
    overflow-y: auto;
}

table {
    border-collapse: collapse;
    width: 100%;
}

th, td {
    padding: 10px;
    border: 1px solid #ddd;
    text-align: left;
}

th {
    background: #f8f9fa;
    font-weight: bold;
    position: sticky;
    top: 0;
    z-index: 10;
}

tr:nth-child(even) {
    background: #f8f9fa;
}

tr:hover {
    background: #e9ecef;
}

.pagination button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.export-options button {
    transition: background-color 0.2s ease-in-out;
}

.export-options button:hover {
    opacity: 0.9;
}

@media (max-width: 768px) {
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .metric-row {
        grid-template-columns: 1fr;
    }
    
    .export-options {
        flex-direction: column;
    }
}
</style>
