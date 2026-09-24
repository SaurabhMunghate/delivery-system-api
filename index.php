<?php
// ==================================================================
//  Delivery Management System - REST API (PHP + SQLite + log file)
//  Single entry point / router.
//  Dev run:  php -S 0.0.0.0:8000 index.php
// ==================================================================

declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');

require __DIR__ . '/src/helpers.php';
require __DIR__ . '/src/db.php';
require __DIR__ . '/src/orders.php';

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ---------- Static files for PHP built-in server (uploads only) ----------
if (PHP_SAPI === 'cli-server' && str_starts_with($path, '/uploads/')) {
    $file = realpath(__DIR__ . $path);
    if ($file && str_starts_with($file, realpath(config()['upload_dir'])) && is_file($file)) {
        header('Content-Type: ' . mime_content_type($file));
        readfile($file);
        exit;
    }
    http_response_code(404);
    exit;
}

// Strip sub-folder prefix when hosted as e.g. http://host/backend/index.php
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
if ($base && str_starts_with($path, $base)) $path = substr($path, strlen($base));
$path = '/' . trim($path, '/');

// ---------- CORS (web dashboard / Expo web) ----------
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: GET, POST, PUT, PATCH, DELETE, OPTIONS');
$method = $_SERVER['REQUEST_METHOD'];
if ($method === 'OPTIONS') { http_response_code(204); exit; }

// ---------- API docs (Swagger UI) ----------
if ($method === 'GET' && $path === '/openapi.yaml') {
    header('Content-Type: application/yaml; charset=utf-8');
    readfile(__DIR__ . '/openapi.yaml');
    exit;
}
if ($method === 'GET' && ($path === '/docs' || $path === '/')) {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/docs.html');
    exit;
}

// ---------- Routes ----------
$routes = [
    // Auth
    ['POST',  '/api/login',                                   'login'],
    ['POST',  '/api/logout',                                  'logout'],
    ['GET',   '/api/me',                                      'me'],
    ['POST',  '/api/me/password',                             'change_password'],

    // Admin: delivery boys
    ['GET',   '/api/admin/delivery-boys',                     'admin_list_boys'],
    ['POST',  '/api/admin/delivery-boys',                     'admin_create_boy'],
    ['PATCH', '/api/admin/delivery-boys/(\d+)',               'admin_update_boy'],

    // Admin: orders
    ['GET',   '/api/admin/orders',                            'admin_list_orders'],
    ['POST',  '/api/admin/orders',                            'admin_create_order'],
    ['GET',   '/api/admin/orders/(\d+)',                      'admin_get_order'],
    ['POST',  '/api/admin/orders/(\d+)/validate',             'admin_validate_order'],
    ['POST',  '/api/admin/orders/(\d+)/assign',               'admin_assign_order'],
    ['POST',  '/api/admin/orders/(\d+)/billing',              'admin_billing'],
    ['POST',  '/api/admin/orders/(\d+)/cancel',               'admin_cancel_order'],
    ['GET',   '/api/admin/stats',                             'admin_stats'],

    // Delivery boy (Android app)
    ['GET',   '/api/my/orders',                               'my_orders'],
    ['GET',   '/api/my/orders/(\d+)',                         'my_order'],
    ['POST',  '/api/my/orders/(\d+)/start',                   'my_start'],
    ['POST',  '/api/my/orders/(\d+)/cancel',                  'my_cancel'],
    ['POST',  '/api/my/orders/(\d+)/deliver',                 'my_deliver'],

    // Shopify
    ['POST',  '/api/webhooks/shopify/orders-create',          'shopify_order_created'],

    ['GET',   '/api/health',                                  'health'],
];

try {
    foreach ($routes as [$m, $pattern, $handler]) {
        if ($m === $method && preg_match('#^' . $pattern . '$#', $path, $mm)) {
            array_shift($mm);
            if ($handler !== 'shopify_order_created') {
                app_log('request', ['method' => $method, 'path' => $path]);
            }
            $handler(...array_map('intval', $mm));
        }
    }
    fail(404, "Route not found: $method $path");
} catch (HttpError $e) {
    json_out(['success' => false, 'message' => $e->getMessage()], $e->status);
} catch (Throwable $e) {
    app_log('error', ['message' => $e->getMessage(), 'file' => $e->getFile(), 'line' => $e->getLine()]);
    json_out(['success' => false, 'message' => 'Server error'], 500);
}

// ==================================================================
//  Handlers
// ==================================================================

function health(): never
{
    json_out(['success' => true, 'time' => now()]);
}

// ---------------- Auth ----------------
function login(): never
{
    $in = input();
    require_fields($in, ['email', 'password']);
    $st = db()->prepare('SELECT * FROM table1 WHERE lower(emai) = lower(?)');
    $st->execute([trim($in['email'])]);
    $u = $st->fetch();

    if (!$u || !check_password($u, (string)$in['password'])) {
        app_log('login.failed', ['email' => $in['email']]);
        fail(401, 'Wrong email or password');
    }
    if (!$u['is_active']) fail(403, 'Account is inactive. Contact admin.');

    $token = bin2hex(random_bytes(32));
    $exp = date('Y-m-d H:i:s', strtotime('+' . config()['token_ttl_days'] . ' days'));
    db()->prepare('INSERT INTO tokens (token,user_id,expires_at) VALUES (?,?,?)')->execute([$token, $u['Id'], $exp]);
    db()->prepare('DELETE FROM tokens WHERE expires_at < ?')->execute([now()]);
    app_log('login', ['user_id' => (int)$u['Id'], 'role' => $u['role']]);

    json_out(['success' => true, 'token' => $token, 'expires_at' => $exp, 'user' => public_user($u)]);
}

function logout(): never
{
    current_user();
    db()->prepare('DELETE FROM tokens WHERE token = ?')->execute([bearer_token()]);
    json_out(['success' => true]);
}

function me(): never
{
    json_out(['success' => true, 'user' => public_user(current_user())]);
}

function change_password(): never
{
    $u = current_user();
    $in = input();
    require_fields($in, ['old_password', 'new_password']);
    if (!check_password($u, (string)$in['old_password'])) fail(400, 'Old password is wrong');
    if (strlen((string)$in['new_password']) < 6) fail(422, 'New password must be at least 6 characters');
    db()->prepare('UPDATE table1 SET password = ? WHERE Id = ?')
        ->execute([password_hash((string)$in['new_password'], PASSWORD_DEFAULT), $u['Id']]);
    json_out(['success' => true, 'message' => 'Password changed']);
}

// ---------------- Admin: delivery boys ----------------
function admin_list_boys(): never
{
    require_role('admin');
    $onlyActive = ($_GET['active'] ?? '') === '1';
    $sql = "SELECT u.*,
              (SELECT COUNT(*) FROM orders o WHERE o.assigned_to = u.Id AND o.status IN ('Pending','OutForDelivery')) AS pending_count,
              (SELECT COUNT(*) FROM orders o WHERE o.assigned_to = u.Id AND o.status = 'Delivered') AS delivered_count,
              (SELECT COUNT(*) FROM orders o WHERE o.assigned_to = u.Id AND o.status = 'Cancelled') AS cancelled_count
            FROM table1 u WHERE u.role = 'delivery'" . ($onlyActive ? ' AND u.is_active = 1' : '') .
           ' ORDER BY pending_count ASC, u.fname';
    $rows = array_map(fn($u) => public_user($u) + [
        'pending_count'   => (int)$u['pending_count'],
        'delivered_count' => (int)$u['delivered_count'],
        'cancelled_count' => (int)$u['cancelled_count'],
    ], db()->query($sql)->fetchAll());
    json_out(['success' => true, 'data' => $rows]);
}

function admin_create_boy(): never
{
    $admin = require_role('admin');
    $in = input();
    require_fields($in, ['fname', 'lname', 'email', 'password']);
    $st = db()->prepare('SELECT COUNT(*) FROM table1 WHERE lower(emai) = lower(?)');
    $st->execute([$in['email']]);
    if ($st->fetchColumn()) fail(409, 'Email already exists');

    $id = next_user_id(db());
    db()->prepare("INSERT INTO table1 (Id,fname,lname,emai,phoneno,city,password,role,is_active)
                   VALUES (?,?,?,?,?,?,?,'delivery',1)")
        ->execute([$id, $in['fname'], $in['lname'], $in['email'], $in['phone'] ?? null, $in['city'] ?? null,
                   password_hash((string)$in['password'], PASSWORD_DEFAULT)]);
    app_log('user.created', ['id' => $id, 'by' => (int)$admin['Id']]);
    $st = db()->prepare('SELECT * FROM table1 WHERE Id = ?');
    $st->execute([$id]);
    json_out(['success' => true, 'data' => public_user($st->fetch())], 201);
}

function admin_update_boy(int $id): never
{
    require_role('admin');
    $in = input();
    $map = ['fname' => 'fname', 'lname' => 'lname', 'email' => 'emai', 'phone' => 'phoneno', 'city' => 'city'];
    $sets = [];
    $vals = [];
    foreach ($map as $k => $col) if (array_key_exists($k, $in)) { $sets[] = "$col = ?"; $vals[] = $in[$k]; }
    if (array_key_exists('is_active', $in)) { $sets[] = 'is_active = ?'; $vals[] = $in['is_active'] ? 1 : 0; }
    if (!empty($in['password'])) { $sets[] = 'password = ?'; $vals[] = password_hash((string)$in['password'], PASSWORD_DEFAULT); }
    if (!$sets) fail(422, 'Nothing to update');
    $vals[] = $id;
    $st = db()->prepare('UPDATE table1 SET ' . implode(',', $sets) . " WHERE Id = ? AND role = 'delivery'");
    $st->execute($vals);
    if (!$st->rowCount()) fail(404, 'Delivery boy not found');
    if (isset($in['is_active']) && !$in['is_active']) db()->prepare('DELETE FROM tokens WHERE user_id = ?')->execute([$id]);
    json_out(['success' => true]);
}

// ---------------- Admin: orders ----------------
function admin_list_orders(): never
{
    require_role('admin');
    $where = [];
    $args = [];
    if (!empty($_GET['status']))  { $where[] = 'o.status = ?'; $args[] = $_GET['status']; }
    if (!empty($_GET['boy_id']))  { $where[] = 'o.assigned_to = ?'; $args[] = (int)$_GET['boy_id']; }
    if (!empty($_GET['date']))    { $where[] = 'date(o.created_at) = ?'; $args[] = $_GET['date']; }
    if (!empty($_GET['q'])) {
        $where[] = '(CAST(o.id AS TEXT) = ? OR o.shopify_id LIKE ? OR o.customer_name LIKE ?)';
        array_push($args, $_GET['q'], '%' . $_GET['q'] . '%', '%' . $_GET['q'] . '%');
    }
    $limit = min(200, max(1, (int)($_GET['limit'] ?? 50)));
    $offset = max(0, (int)($_GET['offset'] ?? 0));
    $sql = ORDER_LIST_SQL . ($where ? ' WHERE ' . implode(' AND ', $where) : '') .
           " ORDER BY o.id DESC LIMIT $limit OFFSET $offset";
    $st = db()->prepare($sql);
    $st->execute($args);
    json_out(['success' => true, 'data' => array_map('order_row', $st->fetchAll())]);
}

function admin_create_order(): never
{
    $admin = require_role('admin');
    $id = create_order(input(), (int)$admin['Id'], 'manual');
    json_out(['success' => true, 'data' => order_details($id, true)], 201);
}

function admin_get_order(int $id): never
{
    require_role('admin');
    json_out(['success' => true, 'data' => order_details($id, true)]);
}

/** Body: { "item_ids": [1,2] }  -> only these items will be delivered. Status New -> Pending */
function admin_validate_order(int $id): never
{
    $admin = require_role('admin');
    $o = find_order($id);
    if ($o['status'] !== 'New') fail(409, 'Only New orders can be validated');
    $ids = array_map('intval', input()['item_ids'] ?? []);
    if (!$ids) fail(422, 'Select at least one item (item_ids)');

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('UPDATE order_items SET selected = 0 WHERE order_id = ?')->execute([$id]);
    $in = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare("UPDATE order_items SET selected = 1 WHERE order_id = ? AND id IN ($in)");
    $st->execute([$id, ...$ids]);
    if (!$st->rowCount()) { $pdo->rollBack(); fail(422, 'None of the item_ids belong to this order'); }

    // Recalculate total from selected items
    $total = $pdo->prepare('SELECT COALESCE(SUM(qty*price),0) FROM order_items WHERE order_id = ? AND selected = 1');
    $total->execute([$id]);
    set_status($o, 'Pending', 'Order validated', (int)$admin['Id'], ['total_amount' => (float)$total->fetchColumn()]);
    $pdo->commit();
    json_out(['success' => true, 'data' => order_details($id, true)]);
}

/** Body: { delivery_boy_id, address?, drop_instructions?, expected_at? } */
function admin_assign_order(int $id): never
{
    $admin = require_role('admin');
    $o = find_order($id);
    if (!in_array($o['status'], ['Pending', 'OutForDelivery'])) fail(409, 'Validate the order before assigning');
    $in = input();
    require_fields($in, ['delivery_boy_id']);

    $st = db()->prepare("SELECT * FROM table1 WHERE Id = ? AND role = 'delivery' AND is_active = 1");
    $st->execute([(int)$in['delivery_boy_id']]);
    $boy = $st->fetch() ?: fail(422, 'Delivery boy not found or inactive');

    $extra = [
        'assigned_to'       => (int)$boy['Id'],
        'address'           => $in['address'] ?? $o['address'],
        'drop_instructions' => $in['drop_instructions'] ?? $o['drop_instructions'],
        'expected_at'       => $in['expected_at'] ?? $o['expected_at'],
        'delivery_otp'      => $o['delivery_otp'] ?: (string)random_int(1000, 9999),
    ];
    set_status($o, $o['status'], "Assigned to {$boy['fname']} {$boy['lname']}", (int)$admin['Id'], $extra);
    json_out(['success' => true, 'data' => order_details($id, true)]);
}

/** multipart/form-data or JSON: amount_type, total_amount, payment_mode, bill_photo (file) */
function admin_billing(int $id): never
{
    $admin = require_role('admin');
    $o = find_order($id);
    if (in_array($o['status'], ['Delivered', 'Cancelled'])) fail(409, 'Order is already closed');
    $in = input();
    $fields = [];
    if (isset($in['amount_type'])) {
        if (!in_array($in['amount_type'], ['Prepaid', 'COD', 'Partial'])) fail(422, 'amount_type must be Prepaid, COD or Partial');
        $fields['amount_type'] = $in['amount_type'];
    }
    if (isset($in['total_amount'])) $fields['total_amount'] = (float)$in['total_amount'];
    if (isset($in['payment_mode'])) {
        if (!in_array($in['payment_mode'], ['Cash', 'UPI', 'Card'])) fail(422, 'payment_mode must be Cash, UPI or Card');
        $fields['payment_mode'] = $in['payment_mode'];
    }
    if ($photo = save_upload('bill_photo')) $fields['admin_bill_photo'] = $photo;
    if (!$fields) fail(422, 'Nothing to update');

    $fields['updated_at'] = now();
    $sets = implode(',', array_map(fn($k) => "$k = ?", array_keys($fields)));
    db()->prepare("UPDATE orders SET $sets WHERE id = ?")->execute([...array_values($fields), $id]);
    app_log('order.billing', ['order_id' => $id, 'by' => (int)$admin['Id'], 'fields' => array_keys($fields)]);
    json_out(['success' => true, 'data' => order_details($id, true)]);
}

function admin_cancel_order(int $id): never
{
    $admin = require_role('admin');
    $o = find_order($id);
    if (in_array($o['status'], ['Delivered', 'Cancelled'])) fail(409, 'Order is already closed');
    $reason = trim((string)(input()['reason'] ?? '')) ?: 'Cancelled by admin';
    set_status($o, 'Cancelled', $reason, (int)$admin['Id'], ['cancel_reason' => $reason]);
    json_out(['success' => true, 'data' => order_details($id, true)]);
}

function admin_stats(): never
{
    require_role('admin');
    $counts = db()->query('SELECT status, COUNT(*) c FROM orders GROUP BY status')->fetchAll(PDO::FETCH_KEY_PAIR);
    $out = array_fill_keys(STATUSES, 0);
    foreach ($counts as $s => $c) $out[$s] = (int)$c;
    $today = db()->prepare("SELECT COUNT(*), COALESCE(SUM(amount_collected),0) FROM bills WHERE date(submitted_at) = date(?)");
    $today->execute([now()]);
    [$cnt, $amt] = $today->fetch(PDO::FETCH_NUM);
    json_out(['success' => true, 'data' => ['by_status' => $out, 'delivered_today' => (int)$cnt, 'collected_today' => (float)$amt]]);
}

// ---------------- Delivery boy ----------------
function my_order_or_fail(int $id, array $boy): array
{
    $o = find_order($id);
    if ((int)$o['assigned_to'] !== (int)$boy['Id']) fail(404, 'Order not found');
    return $o;
}

/** ?status=active (default: Pending + OutForDelivery) | done | all */
function my_orders(): never
{
    $boy = require_role('delivery');
    $filter = $_GET['status'] ?? 'active';
    $statusSql = match ($filter) {
        'done'  => "AND o.status IN ('Delivered','Cancelled')",
        'all'   => '',
        default => "AND o.status IN ('Pending','OutForDelivery')",
    };
    $order = $filter === 'done' ? 'o.updated_at DESC'
        : "CASE o.status WHEN 'OutForDelivery' THEN 0 ELSE 1 END, COALESCE(o.expected_at,'9999') ASC, o.id ASC";
    $st = db()->prepare(ORDER_LIST_SQL . " WHERE o.assigned_to = ? $statusSql ORDER BY $order LIMIT 200");
    $st->execute([$boy['Id']]);
    json_out(['success' => true, 'data' => array_map('order_row', $st->fetchAll())]);
}

function my_order(int $id): never
{
    $boy = require_role('delivery');
    my_order_or_fail($id, $boy);
    json_out(['success' => true, 'data' => order_details($id, false) + ['otp_required' => config()['require_otp']]]);
}

function my_start(int $id): never
{
    $boy = require_role('delivery');
    $o = my_order_or_fail($id, $boy);
    if ($o['status'] !== 'Pending') fail(409, "Cannot start delivery from status {$o['status']}");
    set_status($o, 'OutForDelivery', 'Delivery started', (int)$boy['Id']);
    json_out(['success' => true, 'data' => order_details($id, false)]);
}

/** Body: { reason } - mandatory */
function my_cancel(int $id): never
{
    $boy = require_role('delivery');
    $o = my_order_or_fail($id, $boy);
    if (!in_array($o['status'], ['Pending', 'OutForDelivery'])) fail(409, 'Order is already closed');
    $reason = trim((string)(input()['reason'] ?? ''));
    if ($reason === '') fail(422, 'Cancel reason is required');
    set_status($o, 'Cancelled', $reason, (int)$boy['Id'], ['cancel_reason' => $reason]);
    json_out(['success' => true, 'data' => order_details($id, false)]);
}

/** multipart/form-data: amount_collected, payment_mode, bill_photo (file, required), signature (file, optional), otp */
function my_deliver(int $id): never
{
    $boy = require_role('delivery');
    $o = my_order_or_fail($id, $boy);
    if (!in_array($o['status'], ['Pending', 'OutForDelivery'])) fail(409, 'Order is already closed');

    $in = input();
    require_fields($in, ['amount_collected', 'payment_mode']);
    if (!is_numeric($in['amount_collected']) || $in['amount_collected'] < 0) fail(422, 'Invalid amount');
    if (!in_array($in['payment_mode'], ['Cash', 'UPI', 'Card'])) fail(422, 'payment_mode must be Cash, UPI or Card');

    $otpOk = !empty($in['otp']) && $o['delivery_otp'] && hash_equals($o['delivery_otp'], (string)$in['otp']);
    if (!empty($in['otp']) && !$otpOk) fail(422, 'Wrong OTP');
    if (config()['require_otp'] && !$otpOk) fail(422, 'Customer OTP is required');

    $photo = save_upload('bill_photo', true);
    $sign = save_upload('signature');

    $pdo = db();
    $pdo->beginTransaction();
    $pdo->prepare('INSERT INTO bills (order_id,amount_collected,payment_mode,photo_path,signature_path,otp_verified,submitted_by,submitted_at)
                   VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$id, (float)$in['amount_collected'], $in['payment_mode'], $photo, $sign, $otpOk ? 1 : 0, $boy['Id'], now()]);
    set_status($o, 'Delivered',
        "Collected ₹{$in['amount_collected']} via {$in['payment_mode']}" . ($otpOk ? ' (OTP verified)' : ''),
        (int)$boy['Id'], ['payment_mode' => $in['payment_mode']]);
    $pdo->commit();
    json_out(['success' => true, 'data' => order_details($id, false)]);
}

// ---------------- Shopify webhook ----------------
function shopify_order_created(): never
{
    $raw = file_get_contents('php://input');
    $secret = config()['shopify_webhook_secret'];
    if ($secret) {
        $hmac = $_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? '';
        $calc = base64_encode(hash_hmac('sha256', $raw, $secret, true));
        if (!hash_equals($calc, $hmac)) fail(401, 'Invalid Shopify signature');
    }
    $s = json_decode($raw, true) ?: fail(400, 'Invalid JSON');
    app_log('shopify.webhook', ['shopify_id' => $s['id'] ?? null, 'name' => $s['name'] ?? null]);

    // Ignore duplicates (Shopify can retry)
    $st = db()->prepare('SELECT id FROM orders WHERE shopify_id = ?');
    $st->execute([(string)($s['id'] ?? '')]);
    if ($existing = $st->fetchColumn()) json_out(['success' => true, 'order_id' => (int)$existing, 'duplicate' => true]);

    $ship = $s['shipping_address'] ?? $s['billing_address'] ?? [];
    $cust = $s['customer'] ?? [];
    $name = trim(($ship['name'] ?? '') ?: (($cust['first_name'] ?? '') . ' ' . ($cust['last_name'] ?? ''))) ?: 'Shopify customer';
    $address = implode(', ', array_filter([$ship['address1'] ?? null, $ship['address2'] ?? null,
        $ship['city'] ?? null, $ship['province'] ?? null, $ship['zip'] ?? null]));

    $id = create_order([
        'shopify_id'    => (string)($s['id'] ?? ''),
        'customer_name' => $name,
        'address'       => $address,
        'phone'         => $ship['phone'] ?? $cust['phone'] ?? $s['phone'] ?? null,
        'order_date'    => isset($s['created_at']) ? date('Y-m-d H:i:s', strtotime($s['created_at'])) : now(),
        'total_amount'  => (float)($s['total_price'] ?? 0),
        'amount_type'   => ($s['financial_status'] ?? '') === 'paid' ? 'Prepaid' : 'COD',
        'items'         => array_map(fn($li) => [
            'sku'   => $li['sku'] ?? null,
            'name'  => $li['name'] ?? $li['title'] ?? 'Item',
            'qty'   => (int)($li['quantity'] ?? 1),
            'price' => (float)($li['price'] ?? 0),
        ], $s['line_items'] ?? []),
    ], null, 'shopify');

    json_out(['success' => true, 'order_id' => $id], 201);
}
