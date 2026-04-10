<?php
require_once __DIR__ . '/config/db.php';

echo "Tables:\n";
$stmt = $pdo->query("SELECT * FROM tables");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($row);
}

echo "\nPedidosYa Orders Schema:\n";
try {
    $stmt = $pdo->query("DESCRIBE pedidosya_orders");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        print_r($row);
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>