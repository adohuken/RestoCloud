<?php
// Test completo y definitivo del backend
$_GET['action'] = 'send_to_kitchen';
$_GET['table'] = 1;
$_SERVER['HTTP_HOST'] = 'localhost'; // Falsificar esto para que config/db.php funcione
$_SESSION['user_id'] = 1; // Falsificar sesión

ob_start();
chdir(__DIR__ . '/../ajax');
include 'venta_ajax.php';
$output = ob_get_clean();

echo "SALIDA DEL AJAX:\n";
echo $output . "\n\n";

include '../config/db.php';
$stmt = $pdo->query("SELECT d.id, d.item_status FROM order_details d JOIN orders o ON d.order_id = o.id WHERE o.table_id = 1 AND o.status != 'completed' AND o.status != 'cancelled'");
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "ESTADO DE LOS ITEMS AHORA MISMO:\n";
foreach($items as $i) {
    echo "ID: {$i['id']} | Status: {$i['item_status']}\n";
}
