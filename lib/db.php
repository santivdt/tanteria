<?php
/**
 * SQLite-"database" voor bestellingen. Geen MySQL nodig voor een paar
 * tientallen kaarten — één bestandje, buiten public_html net als de
 * EmailOctopus-config. Fallback binnen public_html wordt door
 * data/.htaccess afgeschermd.
 */
function tickets_db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $candidates = [
        __DIR__ . '/../../data/tickets.sqlite', // aanbevolen: buiten public_html
        __DIR__ . '/../data/tickets.sqlite',    // binnen public_html (fallback)
    ];

    $dbPath = null;
    foreach ($candidates as $path) {
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        if (is_dir($dir) && is_writable($dir)) {
            $dbPath = $path;
            break;
        }
    }

    if ($dbPath === null) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Kan database-map niet vinden/aanmaken']);
        exit;
    }

    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('PRAGMA journal_mode = WAL');

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            quantity INTEGER NOT NULL,
            email TEXT NOT NULL,
            name TEXT NOT NULL,
            amount_total INTEGER NOT NULL,
            currency TEXT NOT NULL,
            stripe_session_id TEXT,
            stripe_payment_intent TEXT,
            ticket_code TEXT,
            sheet_synced INTEGER NOT NULL DEFAULT 0
        )
    ");
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_email ON orders(email)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_orders_status ON orders(status)');

    return $pdo;
}
