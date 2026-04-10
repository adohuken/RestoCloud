<?php
require_once __DIR__ . '/config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$start_date = $_GET['start_date'] ?? date('Y-m-01');
$end_date = $_GET['end_date'] ?? date('Y-m-d');

// 1. Sales by Date
$stmt = $pdo->prepare('
    SELECT DATE(date_created) as date, COUNT(*) as orders, SUM(total) as total
    FROM orders
    WHERE DATE(date_created) BETWEEN ? AND ? AND status = "completed"
    GROUP BY DATE(date_created)
    ORDER BY date DESC
');
$stmt->execute([$start_date, $end_date]);
$sales_by_date = $stmt->fetchAll();

// 2. Top Products
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
$top_products = $stmt->fetchAll();

// 3. Current Inventory
$stmt = $pdo->query('SELECT name, stock, price FROM products WHERE status = "active" ORDER BY name ASC');
$inventory = $stmt->fetchAll();

$total_sales = 0;
foreach ($sales_by_date as $sale) {
    $total_sales += $sale['total'];
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Reporte de Ventas</title>
    <style>
        body {
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            color: #333;
            line-height: 1.6;
            max-width: 800px;
            margin: 0 auto;
            padding: 40px;
        }
        h1 {
            font-weight: 300;
            font-size: 28px;
            margin-bottom: 10px;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 2px;
        }
        .meta {
            text-align: center;
            color: #666;
            font-size: 14px;
            margin-bottom: 40px;
            border-bottom: 1px solid #eee;
            padding-bottom: 20px;
        }
        h2 {
            font-size: 18px;
            font-weight: 600;
            margin-top: 40px;
            margin-bottom: 15px;
            border-bottom: 2px solid #333;
            padding-bottom: 5px;
            display: inline-block;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
            font-size: 14px;
        }
        th {
            text-align: left;
            font-weight: 600;
            border-bottom: 1px solid #333;
            padding: 8px 0;
        }
        td {
            padding: 8px 0;
            border-bottom: 1px solid #eee;
        }
        .text-right {
            text-align: right;
        }
        .total-row td {
            font-weight: 700;
            border-top: 2px solid #333;
            border-bottom: none;
            padding-top: 15px;
        }
        @media print {
            body { padding: 0; }
            .no-print { display: none; }
        }
        .btn-print {
            position: fixed;
            top: 20px;
            right: 20px;
            background: #333;
            color: white;
            border: none;
            padding: 10px 20px;
            cursor: pointer;
            border-radius: 4px;
            font-size: 14px;
        }
        .btn-print:hover {
            background: #000;
        }
    </style>
</head>
<body>
    <button onclick="window.print()" class="btn-print no-print">Imprimir / Guardar PDF</button>

    <h1>Reporte General</h1>
    <div class="meta">
        Periodo: <?= date('d/m/Y', strtotime($start_date)) ?> - <?= date('d/m/Y', strtotime($end_date)) ?><br>
        Generado el: <?= date('d/m/Y H:i') ?>
    </div>

    <h2>1. Productos Más Vendidos</h2>
    <table>
        <thead>
            <tr>
                <th>Producto</th>
                <th class="text-right">Cantidad</th>
                <th class="text-right">Total Generado</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($top_products as $product): ?>
            <tr>
                <td><?= htmlspecialchars($product['name']) ?></td>
                <td class="text-right"><?= $product['quantity'] ?></td>
                <td class="text-right">C$<?= number_format($product['total'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <h2>2. Ventas por Día</h2>
    <table>
        <thead>
            <tr>
                <th>Fecha</th>
                <th class="text-right">Pedidos</th>
                <th class="text-right">Total</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($sales_by_date as $sale): ?>
            <tr>
                <td><?= date('d/m/Y', strtotime($sale['date'])) ?></td>
                <td class="text-right"><?= $sale['orders'] ?></td>
                <td class="text-right">C$<?= number_format($sale['total'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td>Total Periodo</td>
                <td></td>
                <td class="text-right">C$<?= number_format($total_sales, 2) ?></td>
            </tr>
        </tbody>
    </table>

    <h2>3. Inventario Existente</h2>
    <table>
        <thead>
            <tr>
                <th>Producto</th>
                <th class="text-right">Precio Unit.</th>
                <th class="text-right">Stock Actual</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($inventory as $item): ?>
            <tr>
                <td><?= htmlspecialchars($item['name']) ?></td>
                <td class="text-right">C$<?= number_format($item['price'], 2) ?></td>
                <td class="text-right"><?= $item['stock'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

</body>
</html>
