<?php
require_once __DIR__ . '/config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="reporte_ventas_' . $start_date . '_to_' . $end_date . '.csv"');

$output = fopen('php://output', 'w');

// Add BOM for Excel UTF-8 compatibility
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// Summary
fputcsv($output, ['Reporte de Ventas']);
fputcsv($output, ['Desde', $start_date]);
fputcsv($output, ['Hasta', $end_date]);
fputcsv($output, []);

// Total Sales
$stmt = $pdo->prepare('SELECT SUM(total) as total FROM orders WHERE DATE(date_created) BETWEEN ? AND ? AND status = "completed"');
$stmt->execute([$start_date, $end_date]);
$total_sales = $stmt->fetch()['total'] ?? 0;
fputcsv($output, ['Ventas Totales', number_format($total_sales, 2)]);
fputcsv($output, []);

// Sales by Date
fputcsv($output, ['Ventas por Dia']);
fputcsv($output, ['Fecha', 'Pedidos', 'Total']);

$stmt = $pdo->prepare('
    SELECT DATE(date_created) as date, COUNT(*) as orders, SUM(total) as total
    FROM orders
    WHERE DATE(date_created) BETWEEN ? AND ? AND status = "completed"
    GROUP BY DATE(date_created)
    ORDER BY date DESC
');
$stmt->execute([$start_date, $end_date]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [$row['date'], $row['orders'], number_format($row['total'], 2)]);
}

fputcsv($output, []);

// Top Products
fputcsv($output, ['Productos Mas Vendidos']);
fputcsv($output, ['Producto', 'Cantidad', 'Total']);

$stmt = $pdo->prepare('
    SELECT p.name, SUM(od.quantity) as quantity, SUM(od.quantity * od.price) as total
    FROM order_details od
    JOIN products p ON od.product_id = p.id
    JOIN orders o ON od.order_id = o.id
    WHERE DATE(o.date_created) BETWEEN ? AND ? AND o.status = "completed"
    GROUP BY p.id
    ORDER BY total DESC
    LIMIT 10
');
$stmt->execute([$start_date, $end_date]);
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [$row['name'], $row['quantity'], number_format($row['total'], 2)]);
}

fputcsv($output, []);

// Inventory
fputcsv($output, ['Inventario Existente']);
fputcsv($output, ['Producto', 'Precio', 'Stock']);

$stmt = $pdo->query('SELECT name, price, stock FROM products WHERE status = "active" ORDER BY name ASC');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    fputcsv($output, [$row['name'], number_format($row['price'], 2), $row['stock']]);
}

fclose($output);
