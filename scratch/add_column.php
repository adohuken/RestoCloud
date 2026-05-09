<?php
$_SERVER['HTTP_HOST'] = 'localhost';
include __DIR__ . '/../config/db.php';

try {
    $pdo->exec("ALTER TABLE orders ADD COLUMN payment_requested TINYINT(1) DEFAULT 0");
    echo "Column added successfully.";
} catch (Exception $e) {
    if (strpos($e->getMessage(), 'Duplicate column name') !== false) {
        echo "Column already exists.";
    } else {
        echo "Error: " . $e->getMessage();
    }
}
