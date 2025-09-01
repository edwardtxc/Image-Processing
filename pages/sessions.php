<?php
require_once __DIR__ . '/../lib/db.php';
if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
$db = get_db();

$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['__sess_action'] ?? '';
    try {
        if ($action === 'create_session') {
            $name = trim((string)($_POST['session_name'] ?? ''));
            if ($name === '') { throw new RuntimeException('Session name required'); }
            $ins = $db->prepare("INSERT INTO sessions (name, created_at) VALUES (?, datetime('now'))");
            $ins->execute([$name]);
            $_SESSION['current_session_id'] = (int)$db->lastInsertId();
            $msg = 'Session created';
        } elseif ($action === 'select_session') {
            $sid = (int)($_POST['session_id'] ?? 0);
            if ($sid <= 0) { throw new RuntimeException('Invalid session'); }
            $ok = $db->prepare('SELECT id FROM sessions WHERE id = ?');
            $ok->execute([$sid]);
            if (!$ok->fetchColumn()) { throw new RuntimeException('Session not found'); }
            $_SESSION['current_session_id'] = $sid;
            $msg = 'Session selected';
        } elseif ($action === 'select_and_register') {
            $sid = (int)($_POST['session_id'] ?? 0);
            if ($sid <= 0) { throw new RuntimeException('Invalid session'); }
            $ok = $db->prepare('SELECT id FROM sessions WHERE id = ?');
            $ok->execute([$sid]);
            if (!$ok->fetchColumn()) { throw new RuntimeException('Session not found'); }
            $_SESSION['current_session_id'] = $sid;
            $msg = 'Session selected and ready for registration';
        } elseif ($action === 'rename_session') {
            $sid = (int)($_POST['session_id'] ?? 0);
            $name = trim((string)($_POST['session_name'] ?? ''));
            if ($sid <= 0 || $name === '') { throw new RuntimeException('Provide session and new name'); }
            $stmt = $db->prepare('UPDATE sessions SET name = ? WHERE id = ?');
            $stmt->execute([$name, $sid]);
            $msg = 'Session renamed';
        } elseif ($action === 'delete_session') {
            $sid = (int)($_POST['session_id'] ?? 0);
            if ($sid <= 0) { throw new RuntimeException('Invalid session'); }
            $stmt = $db->prepare('DELETE FROM sessions WHERE id = ?');
            $stmt->execute([$sid]);
            if (!empty($_SESSION['current_session_id']) && (int)$_SESSION['current_session_id'] === $sid) {
                unset($_SESSION['current_session_id']);
            }
            $msg = 'Session deleted';
        }
    } catch (Throwable $t) {
        $err = $t->getMessage();
    }
}

$sessions = $db->query('SELECT id, name, created_at FROM sessions ORDER BY id DESC')->fetchAll(PDO::FETCH_ASSOC);
$currentId = isset($_SESSION['current_session_id']) ? (int)$_SESSION['current_session_id'] : 0;
?>

<div class="card" style="padding:16px;">
    <h2 style="margin-top:0;">Sessions</h2>
    <?php if ($msg): ?><p class="success"><?php echo h($msg); ?></p><?php endif; ?>
    <?php if ($err): ?><p class="error"><?php echo h($err); ?></p><?php endif; ?>

    <form method="post" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
        <input type="hidden" name="__sess_action" value="create_session" />
        <input type="text" name="session_name" placeholder="New session name" />
        <button type="submit">Create</button>
    </form>
</div>

<div class="card" style="padding:16px;">
    <h3>All Sessions</h3>
    <table>
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Created</th>
                <th>Registered Graduates</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sessions as $s): ?>
                <?php
                $sid = (int)$s['id'];
                $count = 0;
                try {
                    // Count only registered graduates (those with registered_at timestamp)
                    $stmt = $db->prepare('SELECT COUNT(*) FROM graduates WHERE session_id = ? AND registered_at IS NOT NULL');
                    $stmt->execute([$sid]);
                    $count = (int)$stmt->fetchColumn();
                } catch (Throwable $t) {}
                ?>
                <tr>
                    <td><?php echo $sid; ?></td>
                    <td><?php echo h($s['name']); ?><?php if ($currentId === $sid): ?> <span class="success">(current)</span><?php endif; ?></td>
                    <td><?php echo h((string)$s['created_at']); ?></td>
                    <td><?php echo $count; ?></td>
                    <td>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="__sess_action" value="select_and_register" />
                            <input type="hidden" name="session_id" value="<?php echo $sid; ?>" />
                            <button type="submit">Select Session</button>
                        </form>
                        <form method="post" style="display:inline; margin-left:6px;">
                            <input type="hidden" name="__sess_action" value="rename_session" />
                            <input type="hidden" name="session_id" value="<?php echo $sid; ?>" />
                            <input type="text" name="session_name" placeholder="Rename to..." />
                            <button type="submit">Rename</button>
                        </form>
                        <form method="post" style="display:inline; margin-left:6px;" onsubmit="return confirm('Delete this session? Graduates will remain but be unassigned.');">
                            <input type="hidden" name="__sess_action" value="delete_session" />
                            <input type="hidden" name="session_id" value="<?php echo $sid; ?>" />
                            <button type="submit">Delete</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>


