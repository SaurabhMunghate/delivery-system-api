<?php
// Shared helpers: config, JSON I/O, logging, auth, uploads

function config(): array
{
    static $cfg = null;
    return $cfg ??= require __DIR__ . '/../config.php';
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

class HttpError extends Exception
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }
}

function fail(int $status, string $message): never
{
    throw new HttpError($status, $message);
}

function json_out($data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Reads JSON body, or form fields for multipart requests
function input(): array
{
    static $data = null;
    if ($data !== null) return $data;
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (str_contains($ct, 'application/json')) {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
    } else {
        $data = $_POST;
    }
    return $data;
}

function require_fields(array $in, array $fields): void
{
    foreach ($fields as $f) {
        if (!isset($in[$f]) || $in[$f] === '') fail(422, "Field '$f' is required");
    }
}

// ---------------- Log file ----------------
function app_log(string $event, array $data = []): void
{
    $line = json_encode([
        'time'  => now(),
        'event' => $event,
        'ip'    => $_SERVER['REMOTE_ADDR'] ?? null,
        'data'  => $data,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    @file_put_contents(config()['log_file'], $line . PHP_EOL, FILE_APPEND | LOCK_EX);
}

// ---------------- Auth ----------------
function bearer_token(): ?string
{
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$h && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) if (strtolower($k) === 'authorization') $h = $v;
    }
    return preg_match('/Bearer\s+(\S+)/i', $h, $m) ? $m[1] : null;
}

function current_user(): array
{
    static $user = null;
    if ($user) return $user;
    $t = bearer_token() ?? fail(401, 'Missing token');
    $st = db()->prepare('SELECT u.* FROM tokens t JOIN table1 u ON u.Id = t.user_id
                         WHERE t.token = ? AND t.expires_at > ?');
    $st->execute([$t, now()]);
    $user = $st->fetch() ?: fail(401, 'Invalid or expired token');
    if (!$user['is_active']) fail(403, 'Account is inactive');
    return $user;
}

function require_role(string $role): array
{
    $u = current_user();
    if ($u['role'] !== $role) fail(403, 'Not allowed for your role');
    return $u;
}

function public_user(array $u): array
{
    return [
        'id'        => (int)$u['Id'],
        'name'      => trim($u['fname'] . ' ' . $u['lname']),
        'fname'     => $u['fname'],
        'lname'     => $u['lname'],
        'email'     => $u['emai'],
        'phone'     => $u['phoneno'] !== null ? (string)$u['phoneno'] : null,
        'city'      => $u['city'],
        'role'      => $u['role'],
        'is_active' => (bool)$u['is_active'],
    ];
}

// Supports existing plain-text passwords in table1 and upgrades them to a hash on first login
function check_password(array $u, string $plain): bool
{
    $stored = $u['password'];
    $info = password_get_info($stored);
    if ($info['algo'] !== null && $info['algo'] !== 0) {
        return password_verify($plain, $stored);
    }
    if (hash_equals($stored, $plain)) {
        db()->prepare('UPDATE table1 SET password = ? WHERE Id = ?')
            ->execute([password_hash($plain, PASSWORD_DEFAULT), $u['Id']]);
        return true;
    }
    return false;
}

// ---------------- Uploads ----------------
function save_upload(string $field, bool $required = false): ?string
{
    if (empty($_FILES[$field]) || $_FILES[$field]['error'] === UPLOAD_ERR_NO_FILE) {
        if ($required) fail(422, "File '$field' is required");
        return null;
    }
    $f = $_FILES[$field];
    if ($f['error'] !== UPLOAD_ERR_OK) fail(400, "Upload of '$field' failed (code {$f['error']})");
    if ($f['size'] > config()['max_upload_mb'] * 1024 * 1024) fail(413, "File '$field' is too large");

    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'][$mime] ?? null;
    if (!$ext) fail(415, "File '$field' must be JPG, PNG, WEBP or PDF");

    $name = date('Ymd') . '_' . bin2hex(random_bytes(12)) . '.' . $ext;
    $dir = config()['upload_dir'];
    if (!is_dir($dir)) mkdir($dir, 0775, true);
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) fail(500, 'Could not store file');
    return $name;
}

function file_url(?string $name): ?string
{
    if (!$name) return null;
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
    return "$scheme://{$_SERVER['HTTP_HOST']}$base/uploads/$name";
}
