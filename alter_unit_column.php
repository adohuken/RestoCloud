<?php
require_once __DIR__ . '/config/db.php';

try {
    // 1. Change column to VARCHAR to allow any unit (like 'lt', 'lb')
    // We retain the old values.
    $pdo->exec("ALTER TABLE ingredients MODIFY COLUMN unit VARCHAR(20) NOT NULL DEFAULT 'unidad'");
    echo "Column 'unit' modified to VARCHAR(20).\n";

    // 2. Now we can update 'l' to 'lt' and empty ones to 'lt' (for oils/sauces)
    // Update 'l' -> 'lt'
    $stmt = $pdo->prepare("UPDATE ingredients SET unit = 'lt' WHERE unit = 'l'");
    $stmt->execute();
    echo "Updated " . $stmt->rowCount() . " items from 'l' to 'lt'.\n";

    // Update specific empty ones (Aceite, Salsas) -> 'lt'
    // Now that the column accepts 'lt', this should work.
    $stmt = $pdo->prepare("UPDATE ingredients SET unit = 'lt' WHERE (unit = '' OR unit IS NULL) AND (name LIKE '%Aceite%' OR name LIKE '%Salsa%')");
    $stmt->execute();
    echo "Fixed " . $stmt->rowCount() . " empty unit items to 'lt'.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>