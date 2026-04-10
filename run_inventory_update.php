<?php
require_once __DIR__ . '/config/db.php';

try {
    // Read SQL file
    $sql_file = __DIR__ . '/update_inventory_schema.sql';
    if (!file_exists($sql_file)) {
        die("Error: SQL file not found at $sql_file");
    }

    $sql = file_get_contents($sql_file);

    // Execute SQL
    $pdo->exec($sql);

    echo "¡Éxito! Tablas de inventario (ingredientes y recetas) creadas correctamente.";

} catch (PDOException $e) {
    die("Error DB: " . $e->getMessage());
}
?>