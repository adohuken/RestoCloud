<?php
// Complete fix script for hosting database - DELETE after use
require_once __DIR__ . '/config/db.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== REPARACIÓN COMPLETA DE BASE DE DATOS ===\n\n";

// 1. Add missing 'denominations' column to cash_register
try {
    $cols = $pdo->query("SHOW COLUMNS FROM cash_register LIKE 'denominations'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE cash_register ADD COLUMN denominations TEXT NULL AFTER difference");
        echo "✅ Columna 'denominations' agregada a cash_register.\n";
    } else {
        echo "ℹ️ Columna 'denominations' ya existe.\n";
    }
} catch (Exception $e) {
    echo "⚠️ denominations: " . $e->getMessage() . "\n";
}

// 2. Add missing columns to orders table
$orders_columns = [
    ['payment_requested', "ALTER TABLE orders ADD COLUMN payment_requested TINYINT(1) DEFAULT 0"],
    ['payment_method', "ALTER TABLE orders ADD COLUMN payment_method VARCHAR(50) DEFAULT NULL"],
    ['payment_details', "ALTER TABLE orders ADD COLUMN payment_details TEXT DEFAULT NULL"],
];
foreach ($orders_columns as $col) {
    try {
        $exists = $pdo->query("SHOW COLUMNS FROM orders LIKE '{$col[0]}'")->fetchAll();
        if (empty($exists)) {
            $pdo->exec($col[1]);
            echo "✅ Columna '{$col[0]}' agregada a orders.\n";
        } else {
            echo "ℹ️ Columna '{$col[0]}' ya existe en orders.\n";
        }
    } catch (Exception $e) {
        echo "⚠️ {$col[0]}: " . $e->getMessage() . "\n";
    }
}

// 3. Fix empty table statuses
try {
    $pdo->exec("UPDATE tables SET status = 'available' WHERE status IS NULL OR status = ''");
    echo "✅ Estados de mesas corregidos.\n";
} catch (Exception $e) {
    echo "⚠️ Mesas: " . $e->getMessage() . "\n";
}

// 4. Clean seeded test data from cash_register (with FK checks disabled)
try {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Delete ALL seeded data (entries before 12:00 today are from the seeder)
    $pdo->exec("DELETE FROM cash_register WHERE date_created < '2026-08-22 12:00:00'");
    
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    $count = $pdo->query("SELECT COUNT(*) FROM cash_register")->fetchColumn();
    echo "✅ Registros de caja limpiados. Quedan: {$count} registros reales.\n";
} catch (Exception $e) {
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    echo "⚠️ cash_register cleanup: " . $e->getMessage() . "\n";
}

// 5. Verify
echo "\n--- Verificación Final ---\n";

echo "\nColumnas orders: ";
$cols = $pdo->query("SHOW COLUMNS FROM orders")->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', $cols) . "\n";

echo "\nColumnas cash_register: ";
$cols = $pdo->query("SHOW COLUMNS FROM cash_register")->fetchAll(PDO::FETCH_COLUMN);
echo implode(', ', $cols) . "\n";

echo "\nMesas:\n";
$tables = $pdo->query("SELECT id, name, status FROM tables")->fetchAll();
foreach ($tables as $t) {
    echo "  {$t['name']} -> {$t['status']}\n";
}

echo "\nRegistros de caja:\n";
$records = $pdo->query("SELECT cr.id, u.name as user_name, cr.type, cr.amount, cr.status, cr.date_created FROM cash_register cr JOIN users u ON cr.user_id = u.id ORDER BY cr.date_created DESC")->fetchAll();
if (empty($records)) {
    echo "  (vacío - listo para uso real)\n";
} else {
    foreach ($records as $r) {
        echo "  ID:{$r['id']} | {$r['user_name']} | {$r['type']} | C\${$r['amount']} | {$r['status']} | {$r['date_created']}\n";
    }
}

echo "\n=== REPARACIÓN COMPLETADA ===\n";
echo "🔴 ELIMINA este archivo y diagnostico.php del hosting después de verificar.\n";
