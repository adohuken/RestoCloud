<?php
require_once __DIR__ . '/config/db.php';
try {
    // Add created_at if it doesn't exist
    $pdo->exec("ALTER TABLE order_details ADD COLUMN IF NOT EXISTS created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP");
    echo "Migration successful: created_at added to order_details\n";
} catch (Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
}
?>
