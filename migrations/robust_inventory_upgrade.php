<?php
require_once dirname(__DIR__) . '/config/db.php';

try {
    // 1. Create stock_movements table
    $pdo->exec("CREATE TABLE IF NOT EXISTS stock_movements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ingredient_id INT DEFAULT NULL,
        product_id INT DEFAULT NULL,
        type ENUM('Sale', 'Entry', 'Waste', 'Adjustment') NOT NULL,
        quantity DECIMAL(10,3) NOT NULL,
        user_id INT NOT NULL,
        reference_id INT DEFAULT NULL COMMENT 'Order ID or other reference',
        notes TEXT,
        date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (ingredient_id) REFERENCES ingredients(id) ON DELETE CASCADE,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");

    // 2. Add description column to ingredient_categories if not exists (Audit/Robustness)
    $stmt = $pdo->query("SHOW COLUMNS FROM ingredient_categories LIKE 'description'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE ingredient_categories ADD COLUMN description TEXT AFTER name;");
    }

    echo "¡Migración del Inventario Robustecida con Éxito!";
} catch (Exception $e) {
    echo "Error en la migración: " . $e->getMessage();
}
