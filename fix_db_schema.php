<?php
require_once __DIR__ . '/config/db.php';

try {
    // Check if column exists first to avoid error if run multiple times
    $stmt = $pdo->prepare("SHOW COLUMNS FROM order_details LIKE 'item_status'");
    $stmt->execute();
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE order_details ADD COLUMN item_status ENUM('pending', 'preparing', 'ready', 'served') DEFAULT 'pending'");
        echo "✅ Columna 'item_status' agregada correctamente a la tabla 'order_details'.";
    } else {
        echo "ℹ️ La columna 'item_status' ya existe.";
    }
} catch (PDOException $e) {
    echo "❌ Error: " . $e->getMessage();
}
?>