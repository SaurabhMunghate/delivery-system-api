<?php
// Database connection + automatic migration (safe to run on every request)

function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    $cfg = config();
    $pdo = new PDO('sqlite:' . $cfg['db_path']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA journal_mode = WAL');
    migrate($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    // ---- existing user table "table1" (Id, fname, lname, emai, phoneno, city, password) ----
    $cols = array_column($pdo->query('PRAGMA table_info(table1)')->fetchAll(), 'name');
    if (!$cols) {
        $pdo->exec('CREATE TABLE table1(Id integer, fname text NOT NULL, lname text NOT NULL,
                    emai text, phoneno interger, city text, password text NOT NULL)');
        $cols = ['Id', 'fname', 'lname', 'emai', 'phoneno', 'city', 'password'];
    }
    if (!in_array('role', $cols))      $pdo->exec("ALTER TABLE table1 ADD COLUMN role TEXT NOT NULL DEFAULT 'delivery'");
    if (!in_array('is_active', $cols)) $pdo->exec('ALTER TABLE table1 ADD COLUMN is_active INTEGER NOT NULL DEFAULT 1');

    $pdo->exec("
    CREATE TABLE IF NOT EXISTS tokens (
        token      TEXT PRIMARY KEY,
        user_id    INTEGER NOT NULL,
        expires_at TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS orders (
        id                INTEGER PRIMARY KEY AUTOINCREMENT,
        shopify_id        TEXT UNIQUE,
        customer_name     TEXT NOT NULL,
        address           TEXT,
        phone             TEXT,
        order_date        TEXT,
        status            TEXT NOT NULL DEFAULT 'New',   -- New, Pending, OutForDelivery, Delivered, Cancelled
        assigned_to       INTEGER,                        -- table1.Id of delivery boy
        drop_instructions TEXT,
        expected_at       TEXT,
        amount_type       TEXT,                           -- Prepaid, COD, Partial
        total_amount      REAL DEFAULT 0,
        payment_mode      TEXT,                           -- Cash, UPI, Card
        admin_bill_photo  TEXT,
        cancel_reason     TEXT,
        delivery_otp      TEXT,
        created_at        TEXT NOT NULL,
        updated_at        TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS order_items (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        sku      TEXT,
        name     TEXT NOT NULL,
        qty      INTEGER NOT NULL DEFAULT 1,
        price    REAL NOT NULL DEFAULT 0,
        selected INTEGER NOT NULL DEFAULT 1              -- admin ticks items actually being delivered
    );
    CREATE TABLE IF NOT EXISTS bills (
        id               INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id         INTEGER NOT NULL UNIQUE REFERENCES orders(id) ON DELETE CASCADE,
        amount_collected REAL NOT NULL,
        payment_mode     TEXT NOT NULL,
        photo_path       TEXT NOT NULL,
        signature_path   TEXT,
        otp_verified     INTEGER NOT NULL DEFAULT 0,
        submitted_by     INTEGER NOT NULL,
        submitted_at     TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS order_timeline (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        order_id   INTEGER NOT NULL REFERENCES orders(id) ON DELETE CASCADE,
        status     TEXT NOT NULL,
        note       TEXT,
        changed_by INTEGER,
        changed_at TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_orders_status   ON orders(status);
    CREATE INDEX IF NOT EXISTS idx_orders_assigned ON orders(assigned_to);
    ");

    // Seed one admin if none exists
    $hasAdmin = $pdo->query("SELECT COUNT(*) FROM table1 WHERE role='admin'")->fetchColumn();
    if (!$hasAdmin) {
        $a = config()['default_admin'];
        $st = $pdo->prepare("INSERT INTO table1 (Id,fname,lname,emai,phoneno,city,password,role,is_active)
                             VALUES (?,?,?,?,?,?,?,'admin',1)");
        $st->execute([next_user_id($pdo), 'Admin', 'User', $a['email'], null, null,
                      password_hash($a['password'], PASSWORD_DEFAULT)]);
    }
}

// table1.Id is not auto-increment, so compute the next id manually
function next_user_id(PDO $pdo): int
{
    return (int)$pdo->query('SELECT COALESCE(MAX(Id),0)+1 FROM table1')->fetchColumn();
}
