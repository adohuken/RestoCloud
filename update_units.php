<?php
require_once __DIR__ . '/config/db.php';

try {
    $stmt = $pdo->prepare("UPDATE ingredients SET unit = 'lt' WHERE unit = 'l'");
    $stmt->execute();
    $count = $stmt->rowCount();
    echo "Updated $count ingredients from 'l' to 'lt'.";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>