<?php
require_once __DIR__ . '/config/db.php';

try {
    echo "<h2>Actualizando esquema de base de datos para facturación avanzada...</h2>";
    
    // 1. Create invoice_payments table
    echo "<p>1. Creando tabla 'invoice_payments'...</p>";
    $sql = "CREATE TABLE IF NOT EXISTS invoice_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        payment_method VARCHAR(20) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "<p>✅ Tabla 'invoice_payments' creada o verificada.</p>";
    
    // 2. Add columns to invoices table
    echo "<p>2. Actualizando tabla 'invoices'...</p>";
    
    $columns = [
        "ADD COLUMN parent_invoice_id INT NULL AFTER order_id",
        "ADD COLUMN split_number INT DEFAULT 1 AFTER parent_invoice_id",
        "ADD COLUMN total_splits INT DEFAULT 1 AFTER split_number",
        "ADD COLUMN has_mixed_payments TINYINT(1) DEFAULT 0 AFTER total",
        "ADD COLUMN status VARCHAR(20) DEFAULT 'paid' AFTER total"
    ];

    foreach ($columns as $col_sql) {
        try {
            $pdo->exec("ALTER TABLE invoices " . $col_sql);
            echo "<p>✅ Ejecutado: ALTER TABLE invoices $col_sql</p>";
        } catch (PDOException $e) {
            // Ignore "Duplicate column name" error (Code 42S21)
            if ($e->getCode() == '42S21' || strpos($e->getMessage(), 'Duplicate column') !== false) {
                 echo "<p>ℹ️ Columna ya existe (saltado).</p>";
            } else {
                echo "<p>⚠️ Error al agregar columna: " . $e->getMessage() . "</p>";
            }
        }
    }

    echo "<hr>";
    echo "<h3>✅ Actualización de esquema completada exitosamente</h3>";
    echo "<p>El sistema ahora soporta:</p>";
    echo "<ul>";
    echo "<li>Múltiples métodos de pago por factura</li>";
    echo "<li>División de cuentas (Split bills)</li>";
    echo "<li>Rastreo de facturas divididas</li>";
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<h2>❌ Error General</h2>";
    echo "<p>Error: " . htmlspecialchars($e->getMessage()) . "</p>";
}
?>
