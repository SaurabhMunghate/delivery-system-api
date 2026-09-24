<?php
// ==================================================================
//  Sample data for local testing.
//  Run:  php seed.php
//  Safe to run again: existing sample users are kept, and sample orders
//  are only added once. Do NOT run this on a production database.
// ==================================================================

declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Run from the command line: php seed.php\n"); }

require __DIR__ . '/src/helpers.php';
require __DIR__ . '/src/db.php';
require __DIR__ . '/src/orders.php';

$pdo = db();   // creates tables and the default admin if needed
$adminId = (int)$pdo->query("SELECT Id FROM table1 WHERE role = 'admin' ORDER BY Id LIMIT 1")->fetchColumn();

// ---------------------------------------------------------------- Delivery boys
$boys = [
    // fname, lname, email, password, phone, city, active
    ['Ram',    'Patil',    'ram@gmail.com',    'ram@123',    '9876543210', 'Nagpur', true],
    ['Omm',    'Deshmukh', 'omm@gmail.com',    'omm@123',    '9876501234', 'Nagpur', true],
    ['Suresh', 'Wankhede', 'suresh@gmail.com', 'suresh@123', '9823012345', 'Nagpur', true],
    ['Vikas',  'Bhoyar',   'vikas@gmail.com',  'vikas@123',  '9890123456', 'Wardha', false],
];
$boyId = [];
foreach ($boys as [$f, $l, $email, $pass, $phone, $city, $active]) {
    $st = $pdo->prepare('SELECT Id FROM table1 WHERE lower(emai) = lower(?)');
    $st->execute([$email]);
    if ($id = $st->fetchColumn()) {
        $pdo->prepare("UPDATE table1 SET role = 'delivery' WHERE Id = ? AND role IS NULL")->execute([$id]);
        $boyId[$f] = (int)$id;
        echo "  user exists   $email\n";
        continue;
    }
    $id = next_user_id($pdo);
    $pdo->prepare("INSERT INTO table1 (Id,fname,lname,emai,phoneno,city,password,role,is_active)
                   VALUES (?,?,?,?,?,?,?,'delivery',?)")
        ->execute([$id, $f, $l, $email, $phone, $city, password_hash($pass, PASSWORD_DEFAULT), $active ? 1 : 0]);
    $boyId[$f] = $id;
    echo "  user added    $email / $pass" . ($active ? '' : '  (inactive)') . "\n";
}

// ---------------------------------------------------------------- Orders
$already = $pdo->query("SELECT COUNT(*) FROM order_timeline WHERE note = 'Order received (sample)'")->fetchColumn();
if ($already) {
    echo "\nSample orders already present ($already). Nothing else to do.\n";
    exit(0);
}

// A tiny PNG used as the bill photo for delivered orders
$dir = config()['upload_dir'];
if (!is_dir($dir)) mkdir($dir, 0775, true);
$billPhoto = 'sample_bill.png';
file_put_contents("$dir/$billPhoto", base64_decode(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAIAAACQd1PeAAAADElEQVR4nGP4z8AAAAMBAQDJ/pLvAAAAAElFTkSuQmCC'));

$orders = [
    // [days ago, final status, boy, customer, phone, address, amount_type, items, extra]
    [0, 'New', null, 'Amit Sharma', '9123456780', '12 Dharampeth, Nagpur 440010', 'COD',
        [['MNG-1KG', 'Mango 1kg', 2, 150], ['BAN-12', 'Banana dozen', 1, 60]], ['shopify_id' => '5801001']],
    [0, 'New', null, 'Priya Kulkarni', '9822011223', '45 Ramdaspeth, Nagpur 440012', 'Prepaid',
        [['APL-1KG', 'Apple Shimla 1kg', 1, 220], ['GRP-500', 'Green grapes 500g', 2, 90]], ['shopify_id' => '5801002']],
    [0, 'New', null, 'Rahul Meshram', '9764455667', 'Flat 3B, Laxmi Nagar, Nagpur 440022', 'COD',
        [['ONI-2KG', 'Onion 2kg', 1, 80], ['TOM-1KG', 'Tomato 1kg', 1, 40], ['POT-2KG', 'Potato 2kg', 1, 60]], []],
    [0, 'Pending', 'Ram', 'Sneha Joshi', '9850098765', '8 Civil Lines, Nagpur 440001', 'COD',
        [['ORG-1KG', 'Nagpur orange 1kg', 3, 120], ['PAP-1', 'Papaya (1 pc)', 1, 70]],
        ['drop' => 'Call before arriving', 'expected' => '18:00']],
    [0, 'Pending', 'Omm', 'Kunal Thakre', '9960012345', '221 Manish Nagar, Nagpur 440015', 'Partial',
        [['DRY-MNG', 'Dried mango 250g', 2, 180], ['DRY-TOM', 'Sun-dried tomato 200g', 1, 160]],
        ['drop' => 'Leave with security if not home', 'expected' => '17:30']],
    [0, 'OutForDelivery', 'Ram', 'Neha Gupta', '9011223344', '17 Sadar Bazar, Nagpur 440001', 'COD',
        [['WML-1', 'Watermelon (1 pc)', 1, 90], ['PIN-1', 'Pineapple (1 pc)', 1, 80]],
        ['drop' => 'Third floor, no lift', 'expected' => '13:00']],
    [0, 'OutForDelivery', 'Suresh', 'Arjun Raut', '9423344556', 'Plot 9, Pratap Nagar, Nagpur 440022', 'Prepaid',
        [['MIL-1L', 'Milk 1L', 2, 66], ['PAN-200', 'Paneer 200g', 1, 95]],
        ['expected' => '12:30']],
    [0, 'Delivered', 'Ram', 'Pooja Chavan', '9370011122', '63 Gandhibagh, Nagpur 440002', 'COD',
        [['BAN-12', 'Banana dozen', 2, 60], ['ORG-1KG', 'Nagpur orange 1kg', 1, 120]],
        ['paid' => 'UPI']],
    [1, 'Delivered', 'Omm', 'Vivek Nair', '9881122334', '5 Trimurti Nagar, Nagpur 440022', 'Prepaid',
        [['APL-1KG', 'Apple Shimla 1kg', 2, 220]], ['paid' => 'Card']],
    [2, 'Delivered', 'Suresh', 'Anjali Pande', '9422233445', '30 Wardhaman Nagar, Nagpur 440008', 'COD',
        [['ONI-2KG', 'Onion 2kg', 2, 80], ['GAR-250', 'Garlic 250g', 1, 50]], ['paid' => 'Cash']],
    [1, 'Cancelled', 'Omm', 'Rohit Bhagat', '9765544332', '14 Itwari, Nagpur 440002', 'COD',
        [['MNG-1KG', 'Mango 1kg', 1, 150]], ['cancel' => 'Customer not reachable', 'byBoy' => true]],
    [3, 'Cancelled', null, 'Meera Iyer', '9890099887', '2 Seminary Hills, Nagpur 440006', 'Prepaid',
        [['GRP-500', 'Green grapes 500g', 1, 90]], ['cancel' => 'Duplicate order']],
];

$n = 0;
foreach ($orders as [$daysAgo, $final, $boy, $name, $phone, $address, $amountType, $items, $x]) {
    $source = isset($x['shopify_id']) ? 'shopify' : 'manual';
    $orderId = create_order([
        'shopify_id'    => $x['shopify_id'] ?? null,
        'customer_name' => $name,
        'phone'         => $phone,
        'address'       => $address,
        'amount_type'   => $amountType,
        'items'         => array_map(fn($i) => ['sku' => $i[0], 'name' => $i[1], 'qty' => $i[2], 'price' => $i[3]], $items),
    ], $source === 'shopify' ? null : $adminId, $source);
    // Mark the first timeline entry so re-runs can detect sample data
    $pdo->prepare("UPDATE order_timeline SET note = 'Order received (sample)' WHERE order_id = ? AND status = 'New'")
        ->execute([$orderId]);

    $o = find_order($orderId);
    $bid = $boy ? $boyId[$boy] : null;

    if ($final !== 'New' && !($final === 'Cancelled' && !$bid)) {
        set_status($o, 'Pending', 'Order validated', $adminId);
        $o = find_order($orderId);
    }
    if ($bid) {
        $expected = isset($x['expected']) ? date('Y-m-d') . ' ' . $x['expected'] . ':00' : null;
        $boyName = implode(' ', array_slice(array_values(array_filter($boys, fn($b) => $b[0] === $boy))[0], 0, 2));
        set_status($o, 'Pending', "Assigned to $boyName", $adminId, [
            'assigned_to'       => $bid,
            'drop_instructions' => $x['drop'] ?? null,
            'expected_at'       => $expected,
            'delivery_otp'      => (string)random_int(1000, 9999),
        ]);
        $o = find_order($orderId);
    }
    if (in_array($final, ['OutForDelivery', 'Delivered']) || ($final === 'Cancelled' && !empty($x['byBoy']))) {
        set_status($o, 'OutForDelivery', 'Delivery started', $bid);
        $o = find_order($orderId);
    }
    if ($final === 'Delivered') {
        $total = (float)$o['total_amount'];
        $pdo->prepare('INSERT INTO bills (order_id,amount_collected,payment_mode,photo_path,signature_path,otp_verified,submitted_by,submitted_at)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$orderId, $amountType === 'Prepaid' ? 0 : $total, $x['paid'], $billPhoto, null, 0, $bid, now()]);
        $collected = $amountType === 'Prepaid' ? 0 : $total;
        set_status($o, 'Delivered', "Collected ₹$collected via {$x['paid']}", $bid, ['payment_mode' => $x['paid']]);
    }
    if ($final === 'Cancelled') {
        set_status($o, 'Cancelled', $x['cancel'], !empty($x['byBoy']) ? $bid : $adminId, ['cancel_reason' => $x['cancel']]);
    }

    // Spread older orders over past days so lists and stats look realistic
    if ($daysAgo > 0) {
        $shift = "-$daysAgo days";
        $pdo->prepare("UPDATE orders SET order_date = datetime(order_date, ?), created_at = datetime(created_at, ?),
                       updated_at = datetime(updated_at, ?) WHERE id = ?")->execute([$shift, $shift, $shift, $orderId]);
        $pdo->prepare('UPDATE order_timeline SET changed_at = datetime(changed_at, ?) WHERE order_id = ?')
            ->execute([$shift, $orderId]);
        $pdo->prepare('UPDATE bills SET submitted_at = datetime(submitted_at, ?) WHERE order_id = ?')
            ->execute([$shift, $orderId]);
    }
    $n++;
    printf("  order #%-3d  %-15s %-15s %s\n", $orderId, $final, $boy ?? '-', $name);
}

echo "\nAdded $n sample orders.\n";
echo "Logins:  admin@delivery.local / admin@123   ram@gmail.com / ram@123   omm@gmail.com / omm@123\n";
