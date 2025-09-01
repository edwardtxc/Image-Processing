<?php
declare(strict_types=1);

function get_db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $dbPath = __DIR__ . '/../data/app.sqlite';
    if (!is_dir(dirname($dbPath))) {
        mkdir(dirname($dbPath), 0777, true);
    }
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA foreign_keys = ON;');
    return $pdo;
}

function initialize_database(): void {
    $db = get_db();
    // Sessions table
    $db->exec(
        'CREATE TABLE IF NOT EXISTS sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL UNIQUE,
            created_at TEXT NOT NULL
        )'
    );
    // Graduates table
    $db->exec(
        'CREATE TABLE IF NOT EXISTS graduates (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            student_id TEXT NOT NULL,
            full_name TEXT NOT NULL,
            program TEXT NOT NULL,
            cgpa REAL,
            category TEXT,
            photo_path TEXT,
            qr_token TEXT NOT NULL UNIQUE,
            registered_at TEXT NOT NULL,
            attended_at TEXT,
            face_verified_at TEXT,
            queued_at TEXT,
            announced_at TEXT
        )'
    );
    // Lightweight migration for older databases: add missing columns if needed
    $cols = $db->query('PRAGMA table_info(graduates)')->fetchAll(PDO::FETCH_ASSOC);
    $colNames = array_map(static function ($c) { return $c['name']; }, $cols);
    if (!in_array('cgpa', $colNames, true)) {
        $db->exec('ALTER TABLE graduates ADD COLUMN cgpa REAL');
    }
    if (!in_array('category', $colNames, true)) {
        $db->exec('ALTER TABLE graduates ADD COLUMN category TEXT');
    }
    if (!in_array('session_id', $colNames, true)) {
        $db->exec('ALTER TABLE graduates ADD COLUMN session_id INTEGER NULL REFERENCES sessions(id) ON DELETE SET NULL');
    }
    if (!in_array('original_photo_path', $colNames, true)) {
        $db->exec('ALTER TABLE graduates ADD COLUMN original_photo_path TEXT');
    }
    if (!in_array('photo_processed_at', $colNames, true)) {
        $db->exec('ALTER TABLE graduates ADD COLUMN photo_processed_at TEXT');
    }
    if (!in_array('photo_processing_status', $colNames, true)) {
        $db->exec('ALTER TABLE graduates ADD COLUMN photo_processing_status TEXT DEFAULT "pending"');
    }
    if (!in_array('email', $colNames, true)) {
        $db->exec('ALTER TABLE graduates ADD COLUMN email TEXT');
    }
    if (!in_array('fingerprint_path', $colNames, true)) {
        $db->exec('ALTER TABLE graduates ADD COLUMN fingerprint_path TEXT');
    }
    if (!in_array('fingerprint_verified_at', $colNames, true)) {
        $db->exec('ALTER TABLE graduates ADD COLUMN fingerprint_verified_at DATETIME');
    }
    
    // Ceremony photos captured at center zone per graduate
    $db->exec(
        'CREATE TABLE IF NOT EXISTS ceremony_photos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            graduate_id INTEGER NOT NULL,
            photo_path TEXT NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY (graduate_id) REFERENCES graduates(id) ON DELETE CASCADE
        )'
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_ceremony_photos_grad ON ceremony_photos(graduate_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_ceremony_photos_created ON ceremony_photos(created_at)');
    
    // Reactions table for audience reactions (session-wide)
    $db->exec(
        'CREATE TABLE IF NOT EXISTS reactions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            graduate_id INTEGER NULL,
            emoji TEXT NOT NULL,
            ip_address TEXT,
            user_agent TEXT,
            created_at TEXT NOT NULL,
            FOREIGN KEY (graduate_id) REFERENCES graduates(id) ON DELETE CASCADE
        )'
    );
    
    // Create index for faster reaction queries
    $db->exec('CREATE INDEX IF NOT EXISTS idx_reactions_graduate_id ON reactions(graduate_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_reactions_created_at ON reactions(created_at)');
    
    $db->exec(
        'CREATE TABLE IF NOT EXISTS current_announcement (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            graduate_id INTEGER,
            updated_at TEXT,
            FOREIGN KEY (graduate_id) REFERENCES graduates(id) ON DELETE SET NULL
        )'
    );
    // Ensure single row exists
    $stmt = $db->query('SELECT COUNT(*) FROM current_announcement');
    $count = (int)$stmt->fetchColumn();
    if ($count === 0) {
        $db->exec("INSERT INTO current_announcement (id, graduate_id, updated_at) VALUES (1, NULL, datetime('now'))");
    }

    // Ensure at least one session exists
    $countSessions = (int)$db->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
    if ($countSessions === 0) {
        $defaultName = 'Default Session';
        $ins = $db->prepare("INSERT INTO sessions (name, created_at) VALUES (?, datetime('now'))");
        $ins->execute([$defaultName]);
    }
}

function generate_token(int $length = 8): string {
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $length; $i++) {
        $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $out;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }


