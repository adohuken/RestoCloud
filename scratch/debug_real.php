<?php
// Script de depuración absoluta para ver qué orden y qué ítems tiene Mesa 1
include __DIR__ . '/../config/db.php';

$table_id = 1;

// 1. Encontrar la orden de Mesa 1 (la misma lógica de get_order)
$stmt = $pdo->prepare("SELECT id, status FROM orders WHERE table_id = ? AND status != 'completed' AND status != 'cancelled' ORDER BY id DESC LIMIT 1");
$stmt->execute([$table_id]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    echo "No hay orden para la Mesa 1.\n";
    exit;
}

echo "ORDEN ENCONTRADA:\n";
print_r($order);
echo "\n";

// 2. Buscar TODOS los items de esta orden
$stmt = $pdo->prepare("SELECT id, product_id, item_status FROM order_details WHERE order_id = ?");
$stmt->execute([$order['id']]);
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "ITEMS DE LA ORDEN ({$order['id']}):\n";
foreach($items as $item) {
    $status = $item['item_status'] === null ? 'NULL' : "'{$item['item_status']}'";
    echo "- Detalle ID {$item['id']} | Prod {$item['product_id']} | Status: {$status}\n";
}

// 3. Evaluar la cláusula WHERE que uso para enviar a cocina
$stmt = $pdo->prepare("SELECT id FROM order_details WHERE order_id = ? AND (item_status IS NULL OR item_status = '' OR item_status NOT IN ('preparing', 'ready', 'served'))");
$stmt->execute([$order['id']]);
$pending = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "\nITEMS QUE CUMPLEN LA CONDICIÓN PARA IR A COCINA:\n";
if (empty($pending)) {
    echo "¡NINGUNO!\n";
} else {
    print_r($pending);
}
