<?php
require_once __DIR__ . '/config/db.php';

try {
    echo "Checking products table schema...\n";

    // 1. Check if image_url column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'image_url'");
    $exists = $stmt->fetch();

    if (!$exists) {
        echo "Adding image_url column...\n";
        $pdo->exec("ALTER TABLE products ADD COLUMN image_url VARCHAR(255) DEFAULT NULL");
        echo "Column image_url added successfully.\n";
    } else {
        echo "Column image_url already exists.\n";
    }

    echo "Done.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
