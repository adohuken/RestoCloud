<?php
require_once __DIR__ . '/config/db.php';

try {
    // Update empty units to 'lt' for likely liquid items
    $stmt = $pdo->prepare("UPDATE ingredients SET unit = 'lt' WHERE unit = '' AND (name LIKE '%Aceite%' OR name LIKE '%Salsa%')");
    $stmt->execute();
    echo "Fixed units for " . $stmt->rowCount() . " items (Aceite/Salsas) to 'lt'.\n";

    // Also update any other empty units to 'unidad' or a default if needed, 
    // but for now let's just fix the reported ones.
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>