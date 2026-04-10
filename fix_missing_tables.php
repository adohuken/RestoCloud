<?php
require_once __DIR__ . '/config/db.php';

try {
    echo "<h2>🛠️ Reparación del Sistema de Pagos</h2>";
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // 1. Create INVOICES table if not exists
    echo "<p>Verificando tabla 'invoices'...</p>";
    $sql = "CREATE TABLE IF NOT EXISTS invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        table_name VARCHAR(50),
        subtotal DECIMAL(10,2) NOT NULL DEFAULT 0,
        iva_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
        iva_percentage DECIMAL(5,2) NOT NULL DEFAULT 0,
        total DECIMAL(10,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(50) DEFAULT NULL,
        status VARCHAR(20) DEFAULT 'paid',
        split_number INT DEFAULT NULL,
        total_splits INT DEFAULT NULL,
        has_mixed_payments TINYINT(1) DEFAULT 0,
        parent_invoice_id INT DEFAULT NULL,
        date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql);
    echo "<p style='color:green'>✅ Tabla 'invoices' verificada.</p>";

    // 2. Ensure all columns exist (in case table existed but was incomplete)
    $columns = [
        'payment_method' => "VARCHAR(50) DEFAULT NULL",
        'status' => "VARCHAR(20) DEFAULT 'paid'",
        'split_number' => "INT DEFAULT NULL",
        'total_splits' => "INT DEFAULT NULL",
        'has_mixed_payments' => "TINYINT(1) DEFAULT 0",
        'parent_invoice_id' => "INT DEFAULT NULL",
        'table_name' => "VARCHAR(50)",
        'subtotal' => "DECIMAL(10,2) NOT NULL DEFAULT 0",
        'iva_amount' => "DECIMAL(10,2) NOT NULL DEFAULT 0",
        'iva_percentage' => "DECIMAL(5,2) NOT NULL DEFAULT 0"
    ];

    foreach ($columns as $col => $def) {
        try {
            $pdo->query("SELECT $col FROM invoices LIMIT 1");
        } catch (Exception $e) {
            echo "<p>⚠️ Columna '$col' faltante. Agregando...</p>";
            try {
                $pdo->exec("ALTER TABLE invoices ADD COLUMN $col $def");
                echo "<p style='color:green'>  ✅ Columna '$col' agregada.</p>";
            } catch (Exception $e2) {
                echo "<p style='color:red'>  ❌ Error al agregar '$col': " . $e2->getMessage() . "</p>";
            }
        }
    }

    // 3. Create INVOICE_PAYMENTS table if not exists
    echo "<p>Verificando tabla 'invoice_payments'...</p>";
    $sql = "CREATE TABLE IF NOT EXISTS invoice_payments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        invoice_id INT NOT NULL,
        payment_method VARCHAR(50) NOT NULL,
        amount DECIMAL(10,2) NOT NULL,
        date_created TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";
    $pdo->exec($sql);
    echo "<p style='color:green'>✅ Tabla 'invoice_payments' verificada.</p>";

    // 4. Update PAYMENTS table (ensure cash_register_id exists)
    try {
        $pdo->query("SELECT cash_register_id FROM payments LIMIT 1");
    } catch (Exception $e) {
        echo "<p>⚠️ Columna 'cash_register_id' faltante en 'payments'. Agregando...</p>";
        $pdo->exec("ALTER TABLE payments ADD COLUMN cash_register_id INT NULL");
        $pdo->exec("ALTER TABLE payments ADD CONSTRAINT fk_payments_cash_register FOREIGN KEY (cash_register_id) REFERENCES cash_register(id)");
        echo "<p style='color:green'>  ✅ Columna 'cash_register_id' agregada.</p>";
    }

    // 5. Initialize Settings for IVA if missing
    try {
         // Create settings table if not exists (it should, but just in case)
        $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
            id INT AUTO_INCREMENT PRIMARY KEY,
            setting_key VARCHAR(50) UNIQUE NOT NULL,
            setting_value TEXT,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )");

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key = 'iva_percentage'");
        $stmt->execute();
        if ($stmt->fetchColumn() == 0) {
            $pdo->exec("INSERT INTO settings (setting_key, setting_value) VALUES ('iva_percentage', '15')");
            echo "<p style='color:green'>✅ Configuración de IVA inicializada a 15%.</p>";
        }
    } catch (Exception $e) {
        echo "<p style='color:orange'>⚠️ Nota sobre settings: " . $e->getMessage() . "</p>";
    }

    echo "<hr>";
    echo "<h3>🎉 Reparación finalizada. Intente procesar el pago nuevamente.</h3>";

} catch (PDOException $e) {
    echo "<h2>❌ Error Crítico</h2>";
    echo "<p>" . $e->getMessage() . "</p>";
}
?>
