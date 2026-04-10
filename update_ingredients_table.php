<?php
require_once __DIR__ . '/config/db.php';

try {
    // Check if column exists
    $stmt = $pdo->query("SHOW COLUMNS FROM ingredients LIKE 'image_url'");
    $exists = $stmt->fetch();

    if (!$exists) {
        $pdo->exec("ALTER TABLE ingredients ADD COLUMN image_url VARCHAR(255) DEFAULT NULL AFTER icon");
        echo "Column 'image_url' added successfully.";
    } else {
        echo "Column 'image_url' already exists.";
    }

    // Create uploads directory if it doesn't exist
    $uploadDir = __DIR__ . '/uploads/ingredients/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0777, true);
        echo "\nDirectory 'uploads/ingredients/' created.";
    } else {
        echo "\nDirectory 'uploads/ingredients/' already exists.";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>