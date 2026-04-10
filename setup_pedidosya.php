<?php
require_once __DIR__ . '/config/db.php';

echo "<h2>Configurando tablas para PedidosYa...</h2>";

try {
    // Create pedidosya_orders table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pedidosya_orders (
            id INT AUTO_INCREMENT PRIMARY KEY,
            external_order_id VARCHAR(100) NOT NULL COMMENT 'Número de pedido de PedidosYa',
            customer_name VARCHAR(150) DEFAULT NULL COMMENT 'Nombre del cliente',
            customer_phone VARCHAR(50) DEFAULT NULL COMMENT 'Teléfono del cliente',
            customer_address TEXT DEFAULT NULL COMMENT 'Dirección de entrega',
            subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
            iva_percentage DECIMAL(5,2) DEFAULT 0,
            iva_amount DECIMAL(10,2) DEFAULT 0,
            total DECIMAL(10,2) NOT NULL DEFAULT 0,
            notes TEXT COMMENT 'Notas adicionales del pedido',
            status ENUM('pending','completed','cancelled') DEFAULT 'completed',
            created_by INT NOT NULL,
            date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<p style='color: green;'>✅ Tabla 'pedidosya_orders' creada correctamente.</p>";

    // Create pedidosya_order_details table
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pedidosya_order_details (
            id INT AUTO_INCREMENT PRIMARY KEY,
            pedidosya_order_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(150) NOT NULL COMMENT 'Nombre del producto al momento de la venta',
            quantity INT NOT NULL DEFAULT 1,
            price DECIMAL(10,2) NOT NULL COMMENT 'Precio unitario al momento de la venta',
            FOREIGN KEY (pedidosya_order_id) REFERENCES pedidosya_orders(id) ON DELETE CASCADE,
            FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "<p style='color: green;'>✅ Tabla 'pedidosya_order_details' creada correctamente.</p>";

    echo "<h3 style='color: green;'>🎉 Configuración completada exitosamente!</h3>";
    echo "<p><a href='pedidosya.php'>Ir al módulo de PedidosYa →</a></p>";

} catch (PDOException $e) {
    echo "<p style='color: red;'>❌ Error: " . $e->getMessage() . "</p>";
}
?>
