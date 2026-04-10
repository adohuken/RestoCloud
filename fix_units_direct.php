<?php
require_once __DIR__ . '/config/db.php';

try {
    // Force update by ID to be absolutely sure
    $ids = [6, 7, 9];
    $inQuery = implode(',', $ids);

    $stmt = $pdo->prepare("UPDATE ingredients SET unit = 'lt' WHERE id IN ($inQuery)");
    $stmt->execute();

    echo "Directly updated " . $stmt->rowCount() . " items by ID to 'lt'.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>