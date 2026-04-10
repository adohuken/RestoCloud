<?php
require_once __DIR__ . '/config/db.php';

try {
    $stmt = $pdo->query("SELECT id, name, unit FROM ingredients WHERE name LIKE '%Aceite%' OR name LIKE '%Salsa%' OR unit IS NULL OR unit = ''");
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "Found " . count($results) . " ingredients matching check:\n";
    foreach ($results as $row) {
        echo "ID: " . $row['id'] . " | Name: " . $row['name'] . " | Unit: '" . ($row['unit'] === null ? 'NULL' : $row['unit']) . "'\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>