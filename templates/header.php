<?php
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>Graduation System</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 0; padding: 0; }
        header { background: #0d47a1; color: #fff; padding: 12px 16px; }
        nav a { color: #fff; margin-right: 12px; text-decoration: none; }
        .container { padding: 16px; }
        input, select, button { padding: 8px; margin: 4px 0; }
        .card { border: 1px solid #ddd; padding: 12px; border-radius: 6px; margin-bottom: 16px; }
        .row { display: flex; gap: 16px; flex-wrap: wrap; }
        .col { flex: 1 1 320px; }
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #ddd; padding: 8px; }
        th { background: #f5f5f5; text-align: left; }
        .success { color: #1b5e20; }
        .error { color: #b71c1c; }
    </style>
</head>
<body>
    <header>
        <strong>Graduation Ceremony System</strong>
        <nav style="float:right;">
            <a href="?page=register">Register Graduate</a>
            <a href="?page=attendance">Attendance</a>
            <a href="?page=queue">Before Stage Queue</a>
            <a href="?page=stage">Stage Announce</a>
            <a href="/pages/display.php" target="_blank">Display Slide</a>
            <a href="?page=reports">Reports</a>
            <a href="?page=sessions">Sessions</a>
            <a href="/pages/batch_photos.php" target="blank">Batch Photos Email</a>
        </nav>
        <div style="clear:both"></div>
        <?php
        require_once __DIR__ . '/../lib/db.php';
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $db = get_db();
        $row = $db->query('SELECT id, name FROM sessions ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
        $currentSessionId = isset($_SESSION['current_session_id']) ? (int)$_SESSION['current_session_id'] : 0;
        $currentSessionName = '';
        
        // If no current session is set, automatically set it to the last session
        if ($currentSessionId <= 0 && $row) {
            $_SESSION['current_session_id'] = (int)$row['id'];
            $currentSessionId = (int)$row['id'];
        }
        
        if ($currentSessionId > 0) {
            $stmt = $db->prepare('SELECT name FROM sessions WHERE id = ?');
            $stmt->execute([$currentSessionId]);
            $currentSessionName = (string)$stmt->fetchColumn();
        }
        if ($currentSessionName === '' && $row) { $currentSessionName = $row['name']; }
        ?>
        <div style="margin-top:8px; display:flex; gap:8px; align-items:center; color:#fff;">
            <span>Current session: <strong><?php echo h($currentSessionName ?: 'None'); ?></strong></span>
            <a href="?page=sessions" style="color:#fff; text-decoration:underline;">Manage sessions</a>
        </div>
    </header>
    <div class="container">


