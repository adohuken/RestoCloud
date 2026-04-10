<?php
require_once __DIR__ . '/config/db.php';

try {
    $pdo->exec("ALTER TABLE order_details ADD COLUMN IF NOT EXISTS notes VARCHAR(255) DEFAULT NULL");
} catch(Exception $e) {}

try {
    $pdo->exec("ALTER TABLE cash_register ADD COLUMN IF NOT EXISTS expected_amount DECIMAL(10,2) DEFAULT NULL");
} catch(Exception $e) {}

try {
    $pdo->exec("ALTER TABLE cash_register ADD COLUMN IF NOT EXISTS difference DECIMAL(10,2) DEFAULT NULL");
} catch(Exception $e) {}

try {
    $pdo->exec("ALTER TABLE invoices ADD COLUMN IF NOT EXISTS tip_amount DECIMAL(10,2) DEFAULT 0");
} catch(Exception $e) {}

try {
    $pdo->exec("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES ('enable_tips', '0')");
} catch(Exception $e) {}

echo "Success!";
