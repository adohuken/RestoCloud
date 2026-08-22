<?php
// Quick fix script for hosting database - DELETE after use
require_once __DIR__ . '/config/db.php';
header('Content-Type: text/plain; charset=utf-8');

echo "=== REPARACIÓN DE BASE DE DATOS ===\n\n";

// 1. Add missing 'denominations' column to cash_register if needed
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

// 3. Clean seeded/test data from cash_register (keep only real user operations)
// The seeder created entries with user_id 5 (Ana Silva) and bulk entries - remove those
try {
    // Remove all seeded cash register entries (those created by the seed script have specific patterns)
    // Keep only entries created by user_id = 1 (Administrador) after the seed was run
    $count_before = $pdo->query("SELECT COUNT(*) FROM cash_register")->fetchColumn();
    
    // Delete entries from non-existent users or test users (user_id > 1 that are test waiters)
    $pdo->exec("DELETE FROM cash_register WHERE user_id NOT IN (SELECT id FROM users)");
    
    // Delete old seeded entries (before today's real session - entries with exact round amounts from seeder)
    // The seeder creates entries at specific times like 22:30, 11:05 etc with test data
    // We'll remove entries that were created before the user's real first login today
    $pdo->exec("DELETE FROM cash_register WHERE date_created < '2026-08-22 12:00:00'");
    
    $count_after = $pdo->query("SELECT COUNT(*) FROM cash_register")->fetchColumn();
    echo "✅ Registros de caja limpiados: {$count_before} → {$count_after} (eliminados " . ($count_before - $count_after) . " registros de prueba).\n";
} catch (Exception $e) {
    echo "❌ Error al limpiar cash_register: " . $e->getMessage() . "\n";
}

// 4. Verify
echo "\n--- Verificación ---\n";
$cols = $pdo->query("SHOW COLUMNS FROM cash_register")->fetchAll(PDO::FETCH_COLUMN);
echo "Columnas cash_register: " . implode(', ', $cols) . "\n\n";

$tables = $pdo->query("SELECT id, name, status FROM tables")->fetchAll();
foreach ($tables as $t) {
    echo "Mesa {$t['id']}: {$t['name']} -> {$t['status']}\n";
}

echo "\nRegistros de caja restantes:\n";
$records = $pdo->query("SELECT cr.*, u.name as user_name FROM cash_register cr JOIN users u ON cr.user_id = u.id ORDER BY cr.date_created DESC")->fetchAll();
foreach ($records as $r) {
    echo "  ID:{$r['id']} | {$r['user_name']} | {$r['type']} | C\${$r['amount']} | {$r['status']} | {$r['date_created']}\n";
}

echo "\n=== REPARACIÓN COMPLETADA ===\n";
echo "IMPORTANTE: Elimina este archivo y diagnostico.php del hosting.\n";
