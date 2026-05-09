<?php
include __DIR__ . '/../includes/db.php';
$stmt = $pdo->query("SELECT d.id, d.item_status, p.name FROM order_details d JOIN products p ON d.product_id = p.id JOIN orders o ON d.order_id = o.id WHERE o.table_id = 1 AND o.status != 'completed' AND o.status != 'cancelled'");
foreach($stmt->fetchAll() as $r) {
    echo $r['name'] . " -> [" . $r['item_status'] . "]\n";
}
