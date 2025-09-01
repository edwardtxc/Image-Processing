<?php
declare(strict_types=1);

require_once __DIR__ . '/lib/db.php';

// Ensure DB and schema exist
initialize_database();

$db = get_db();
// Handle session selection and creation
session_start();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['__sess_action'] ?? '';
    if ($action === 'select_session') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if ($sid > 0) {
            // verify exists
            $stmt = $db->prepare('SELECT id FROM sessions WHERE id = ?');
            $stmt->execute([$sid]);
            if ($stmt->fetchColumn()) {
                $_SESSION['current_session_id'] = $sid;
            }
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($_GET));
        exit;
    } elseif ($action === 'create_session') {
        $name = trim((string)($_POST['session_name'] ?? ''));
        if ($name !== '') {
            try {
                $ins = $db->prepare("INSERT INTO sessions (name, created_at) VALUES (?, datetime('now'))");
                $ins->execute([$name]);
                $newId = (int)$db->lastInsertId();
                $_SESSION['current_session_id'] = $newId;
            } catch (Throwable $t) {
                // ignore duplicates silently
            }
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($_GET));
        exit;
    } elseif ($action === 'delete_session') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if ($sid > 0) {
            try {
                $stmt = $db->prepare('DELETE FROM sessions WHERE id = ?');
                $stmt->execute([$sid]);
                if (!empty($_SESSION['current_session_id']) && (int)$_SESSION['current_session_id'] === $sid) {
                    unset($_SESSION['current_session_id']);
                }
            } catch (Throwable $t) {
                // ignore
            }
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($_GET));
        exit;
    } elseif ($action === 'rename_session') {
        $sid = (int)($_POST['session_id'] ?? 0);
        $name = trim((string)($_POST['session_name'] ?? ''));
        if ($sid > 0 && $name !== '') {
            try {
                $stmt = $db->prepare('UPDATE sessions SET name = ? WHERE id = ?');
                $stmt->execute([$name, $sid]);
            } catch (Throwable $t) {
                // ignore
            }
        }
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?') . '?' . http_build_query($_GET));
        exit;
    } elseif ($action === 'select_and_register') {
        $sid = (int)($_POST['session_id'] ?? 0);
        if ($sid > 0) {
            $stmt = $db->prepare('SELECT id FROM sessions WHERE id = ?');
            $stmt->execute([$sid]);
            if ($stmt->fetchColumn()) {
                $_SESSION['current_session_id'] = $sid;
            }
        }
        header('Location: ?page=register');
        exit;
    }
}

$page = isset($_GET['page']) ? preg_replace('/[^a-z_]/', '', $_GET['page']) : 'sessions';
$allowedPages = [
    'register',
    'attendance',
    'queue',
    'stage',
    'display',
    'sessions',
    'reports',
];
if (!in_array($page, $allowedPages, true)) {
    $page = 'sessions';
}

include __DIR__ . '/templates/header.php';
include __DIR__ . '/pages/' . $page . '.php';
include __DIR__ . '/templates/footer.php';
?>


