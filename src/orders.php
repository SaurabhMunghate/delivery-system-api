<?php
// Order logic shared by admin, delivery-boy and webhook endpoints

const STATUSES = ['New', 'Pending', 'OutForDelivery', 'Delivered', 'Cancelled'];

function find_order(int $id): array
{
    $st = db()->prepare('SELECT * FROM orders WHERE id = ?');
    $st->execute([$id]);
    return $st->fetch() ?: fail(404, 'Order not found');
}

function add_timeline(int $orderId, string $status, ?string $note, ?int $userId): void
{
    db()->prepare('INSERT INTO order_timeline (order_id,status,note,changed_by,changed_at) VALUES (?,?,?,?,?)')
        ->execute([$orderId, $status, $note, $userId, now()]);
    app_log('order.status', ['order_id' => $orderId, 'status' => $status, 'note' => $note, 'by' => $userId]);
}

function set_status(array $order, string $status, ?string $note, ?int $userId, array $extra = []): void
{
    $fields = array_merge(['status' => $status, 'updated_at' => now()], $extra);
    $sets = implode(',', array_map(fn($k) => "$k = ?", array_keys($fields)));
    db()->prepare("UPDATE orders SET $sets WHERE id = ?")
        ->execute([...array_values($fields), $order['id']]);
    add_timeline((int)$order['id'], $status, $note, $userId);
}

/** Creates an order with items. Used by manual admin create and Shopify webhook. */
function create_order(array $in, ?int $userId, string $source): int
{
    require_fields($in, ['customer_name']);
    $items = $in['items'] ?? [];
    if (!is_array($items) || !$items) fail(422, 'At least one item is required');

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $total = $in['total_amount'] ?? array_sum(array_map(
            fn($i) => (float)($i['price'] ?? 0) * (int)($i['qty'] ?? 1), $items));
        $pdo->prepare('INSERT INTO orders (shopify_id,customer_name,address,phone,order_date,status,
                       total_amount,amount_type,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
            ->execute([
                $in['shopify_id'] ?? null, $in['customer_name'], $in['address'] ?? null,
                $in['phone'] ?? null, $in['order_date'] ?? now(), 'New',
                $total, $in['amount_type'] ?? null, now(), now(),
            ]);
        $orderId = (int)$pdo->lastInsertId();

        $st = $pdo->prepare('INSERT INTO order_items (order_id,sku,name,qty,price,selected) VALUES (?,?,?,?,?,1)');
        foreach ($items as $i) {
            if (empty($i['name'])) fail(422, 'Every item needs a name');
            $st->execute([$orderId, $i['sku'] ?? null, $i['name'], (int)($i['qty'] ?? 1), (float)($i['price'] ?? 0)]);
        }
        add_timeline($orderId, 'New', "Order received ($source)", $userId);
        $pdo->commit();
        return $orderId;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/** Full order representation. $forAdmin adds OTP and internal fields. */
function order_details(int $id, bool $forAdmin): array
{
    $o = find_order($id);
    $pdo = db();

    $items = $pdo->prepare('SELECT id,sku,name,qty,price,selected FROM order_items WHERE order_id = ?');
    $items->execute([$id]);
    $items = array_map(fn($i) => [
        'id' => (int)$i['id'], 'sku' => $i['sku'], 'name' => $i['name'], 'qty' => (int)$i['qty'],
        'price' => (float)$i['price'], 'selected' => (bool)$i['selected'],
    ], $items->fetchAll());
    if (!$forAdmin) $items = array_values(array_filter($items, fn($i) => $i['selected']));

    $bill = $pdo->prepare('SELECT b.*, u.fname || \' \' || u.lname AS submitted_by_name
                           FROM bills b LEFT JOIN table1 u ON u.Id = b.submitted_by WHERE order_id = ?');
    $bill->execute([$id]);
    $bill = $bill->fetch() ?: null;
    if ($bill) {
        $bill = [
            'amount_collected' => (float)$bill['amount_collected'],
            'payment_mode'     => $bill['payment_mode'],
            'photo_url'        => file_url($bill['photo_path']),
            'signature_url'    => file_url($bill['signature_path']),
            'otp_verified'     => (bool)$bill['otp_verified'],
            'submitted_by'     => $bill['submitted_by_name'],
            'submitted_at'     => $bill['submitted_at'],
        ];
    }

    $boy = null;
    if ($o['assigned_to']) {
        $st = $pdo->prepare('SELECT Id, fname, lname, phoneno FROM table1 WHERE Id = ?');
        $st->execute([$o['assigned_to']]);
        if ($b = $st->fetch()) $boy = ['id' => (int)$b['Id'], 'name' => "{$b['fname']} {$b['lname']}", 'phone' => (string)$b['phoneno']];
    }

    $out = [
        'id'                => (int)$o['id'],
        'shopify_id'        => $o['shopify_id'],
        'customer_name'     => $o['customer_name'],
        'address'           => $o['address'],
        'phone'             => $o['phone'],
        'order_date'        => $o['order_date'],
        'status'            => $o['status'],
        'assigned_to'       => $boy,
        'drop_instructions' => $o['drop_instructions'],
        'expected_at'       => $o['expected_at'],
        'amount_type'       => $o['amount_type'],
        'total_amount'      => (float)$o['total_amount'],
        'payment_mode'      => $o['payment_mode'],
        'admin_bill_photo'  => file_url($o['admin_bill_photo']),
        'cancel_reason'     => $o['cancel_reason'],
        'items'             => $items,
        'bill'              => $bill,
        'created_at'        => $o['created_at'],
        'updated_at'        => $o['updated_at'],
    ];

    if ($forAdmin) {
        $out['delivery_otp'] = $o['delivery_otp'];
        $tl = $pdo->prepare('SELECT t.status, t.note, t.changed_at, u.fname || \' \' || u.lname AS changed_by
                             FROM order_timeline t LEFT JOIN table1 u ON u.Id = t.changed_by
                             WHERE order_id = ? ORDER BY t.id');
        $tl->execute([$id]);
        $out['timeline'] = $tl->fetchAll();
    }
    return $out;
}

/** Compact list row */
function order_row(array $o): array
{
    return [
        'id'            => (int)$o['id'],
        'shopify_id'    => $o['shopify_id'],
        'customer_name' => $o['customer_name'],
        'address'       => $o['address'],
        'phone'         => $o['phone'],
        'status'        => $o['status'],
        'expected_at'   => $o['expected_at'],
        'amount_type'   => $o['amount_type'],
        'total_amount'  => (float)$o['total_amount'],
        'item_count'    => (int)($o['item_count'] ?? 0),
        'items_summary' => $o['items_summary'] ?? null,
        'delivery_boy'  => $o['boy_name'] ?? null,
        'created_at'    => $o['created_at'],
    ];
}

const ORDER_LIST_SQL = "SELECT o.*, u.fname || ' ' || u.lname AS boy_name,
    (SELECT COUNT(*) FROM order_items i WHERE i.order_id = o.id AND i.selected = 1) AS item_count,
    (SELECT GROUP_CONCAT(i.qty || 'x ' || i.name, ', ') FROM order_items i WHERE i.order_id = o.id AND i.selected = 1) AS items_summary
    FROM orders o LEFT JOIN table1 u ON u.Id = o.assigned_to";
