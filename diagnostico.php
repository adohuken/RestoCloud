<?php
// Temporary diagnostic - DELETE after use
require_once __DIR__ . '/config/db.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== DIAGNÓSTICO DE CAJA ===\n\n";

// 1. Check tables
try {
    $result = $pdo->query("SELECT id, name, status FROM tables")->fetchAll();
    echo "MESAS:\n";
    foreach ($result as $r) {
        echo "  ID:{$r['id']} | {$r['name']} | Status: {$r['status']}\n";
    }
} catch (Exception $e) {
    echo "ERROR en tabla 'tables': " . $e->getMessage() . "\n";
}

echo "\n";

// 2. Check active orders
try {
    $result = $pdo->query("SELECT id, table_id, status, total FROM orders WHERE status NOT IN ('completed', 'cancelled') LIMIT 20")->fetchAll();
    echo "ORDENES ACTIVAS (no completadas/canceladas): " . count($result) . "\n";
    foreach ($result as $r) {
        echo "  Order #{$r['id']} | Mesa: {$r['table_id']} | Status: {$r['status']} | Total: {$r['total']}\n";
    }
} catch (Exception $e) {
    echo "ERROR en tabla 'orders': " . $e->getMessage() . "\n";
}

echo "\n";

// 3. Check cash register
try {
    $result = $pdo->query("SELECT id, user_id, amount, type, status, date_created FROM cash_register ORDER BY id DESC LIMIT 5")->fetchAll();
    echo "CASH REGISTER (últimos 5):\n";
    foreach ($result as $r) {
        echo "  ID:{$r['id']} | User:{$r['user_id']} | Amount:{$r['amount']} | Type:{$r['type']} | Status:{$r['status']} | Date:{$r['date_created']}\n";
    }
} catch (Exception $e) {
    echo "ERROR en tabla 'cash_register': " . $e->getMessage() . "\n";
}

echo "\n";

// 4. Check column existence
try {
    $cols = $pdo->query("SHOW COLUMNS FROM cash_register")->fetchAll(PDO::FETCH_COLUMN);
    echo "COLUMNAS en cash_register: " . implode(', ', $cols) . "\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

echo "\n=== FIN DIAGNÓSTICO ===\n";
