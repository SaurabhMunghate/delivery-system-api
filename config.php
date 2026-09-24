<?php
// ------------------------------------------------------------------
// Delivery Management System - configuration
// ------------------------------------------------------------------
return [
    // SQLite database (your existing info.sqlite; new tables are added automatically)
    'db_path'        => __DIR__ . '/data/info.sqlite',

    // Log file: every API request, login and status change is appended here (JSON lines)
    'log_file'       => __DIR__ . '/logs/app.log',

    // Bill photos / signatures are stored here and served at /uploads/<file>
    'upload_dir'     => __DIR__ . '/uploads',
    'max_upload_mb'  => 5,

    // Login token lifetime
    'token_ttl_days' => 30,

    // If true, delivery boy must enter the customer's OTP to mark an order Delivered
    'require_otp'    => false,

    // Shopify webhook secret (Shopify admin -> Settings -> Notifications -> Webhooks)
    'shopify_webhook_secret' => getenv('SHOPIFY_WEBHOOK_SECRET') ?: '',

    // Default admin created on first run if no admin exists. CHANGE THE PASSWORD after first login.
    'default_admin'  => ['email' => 'admin@delivery.local', 'password' => 'admin@123'],
];
