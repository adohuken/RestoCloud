<?php
require_once __DIR__ . '/config/db.php';

try {
    echo "Starting Schema Update for Images...<br>";

    // 1. Add image_url to ingredients
    $stmt = $pdo->query("SHOW COLUMNS FROM ingredients LIKE 'image_url'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE ingredients ADD COLUMN image_url VARCHAR(255) DEFAULT NULL");
        echo "Column 'image_url' added to 'ingredients'.<br>";
    } else {
        echo "Column 'image_url' already exists in 'ingredients'.<br>";
    }

    // 2. Add image_url to products
    $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'image_url'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN image_url VARCHAR(255) DEFAULT NULL");
        echo "Column 'image_url' added to 'products'.<br>";
    } else {
        echo "Column 'image_url' already exists in 'products'.<br>";
    }

    // 3. Ensure icon exists in products (sanity check)
    $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'icon'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN icon VARCHAR(50) DEFAULT '🍽️'");
        echo "Column 'icon' added to 'products'.<br>";
    }

    // 4. Ensure category_id exists in products
    $stmt = $pdo->query("SHOW COLUMNS FROM products LIKE 'category_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE products ADD COLUMN category_id INT DEFAULT NULL");
        echo "Column 'category_id' added to 'products'.<br>";
    }

    echo "Schema Update Complete!";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>