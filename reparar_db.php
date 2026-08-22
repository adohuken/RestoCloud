<?php
// Quick fix script for hosting database - DELETE after use
require_once __DIR__ . '/config/db.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== REPARACIÓN DE BASE DE DATOS ===\n\n";

// 1. Add missing 'denominations' column to cash_register
try {
    $cols = $pdo->query("SHOW COLUMNS FROM cash_register LIKE 'denominations'")->fetchAll();
    if (empty($cols)) {
        $pdo->exec("ALTER TABLE cash_register ADD COLUMN denominations TEXT NULL AFTER difference");
        echo "✅ Columna 'denominations' agregada a cash_register.\n";
    } else {
        echo "ℹ️ Columna 'denominations' ya existe en cash_register.\n";
    }
} catch (Exception $e) {
    echo "❌ Error al agregar columna: " . $e->getMessage() . "\n";
}

// 2. Fix empty table statuses
try {
    $pdo->exec("UPDATE tables SET status = 'available' WHERE status IS NULL OR status = ''");
    echo "✅ Estados de mesas corregidos a 'available'.\n";
} catch (Exception $e) {
    echo "❌ Error al actualizar mesas: " . $e->getMessage() . "\n";
}

// 3. Verify
echo "\n--- Verificación ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM cash_register")->fetchAll(PDO::FETCH_COLUMN);
echo "Columnas cash_register: " . implode(', ', $cols) . "\n";

$tables = $pdo->query("SELECT id, name, status FROM tables")->fetchAll();
foreach ($tables as $t) {
    echo "Mesa {$t['id']}: {$t['name']} -> {$t['status']}\n";
}

echo "\n=== REPARACIÓN COMPLETADA ===\n";
echo "IMPORTANTE: Elimina este archivo del hosting después de usarlo.\n";
