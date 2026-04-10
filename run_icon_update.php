<?php
require_once __DIR__ . '/config/db.php';

try {
    // Read SQL file
    $sql_file = __DIR__ . '/update_inventory_icons.sql';
    if (!file_exists($sql_file)) {
        die("Error: SQL file not found at $sql_file");
    }

    $sql = file_get_contents($sql_file);

    // Execute SQL
    // We need to execute multiple statements, PDO::exec might only do one if not configured properly, 
    // but usually with simple updates it's fine. However, the prepared statement block in SQL might be tricky via PDO.
    // Let's try executing standard updates directly if the complex SQL fails, or simplify the SQL.
    // Actually, let's just use specific ALTER command in a try-catch for simplicity in PHP.

    try {
        $pdo->exec("ALTER TABLE ingredients ADD COLUMN icon VARCHAR(50) DEFAULT '📦'");
    } catch (PDOException $e) {
        // Ignore if column exists
    }

    // Update icons
    $pdo->exec("UPDATE ingredients SET icon = '🥩' WHERE name LIKE '%Carne%' OR name LIKE '%Tocino%'");
    $pdo->exec("UPDATE ingredients SET icon = '🍔' WHERE name LIKE '%Pan%'");
    $pdo->exec("UPDATE ingredients SET icon = '🧀' WHERE name LIKE '%Queso%'");
    $pdo->exec("UPDATE ingredients SET icon = '🍗' WHERE name LIKE '%Alitas%'");
    $pdo->exec("UPDATE ingredients SET icon = '🥫' WHERE name LIKE '%Salsa%'");
    $pdo->exec("UPDATE ingredients SET icon = '🥔' WHERE name LIKE '%Papa%'");
    $pdo->exec("UPDATE ingredients SET icon = '🛢️' WHERE name LIKE '%Aceite%'");

    echo "¡Éxito! Columna de iconos agregada y datos actualizados.";

} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}
?>