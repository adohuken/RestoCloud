<?php
require_once __DIR__ . '/config/db.php';
session_start();

// Access control
if (!isset($_SESSION['user_id']) || (!in_array($_SESSION['role_id'], [1, 2, 5]))) {
    header('Location: index.php');
    exit();
}

$order_id = $_GET['order_id'] ?? null;
if (!$order_id) {
    header('Location: cuentas.php');
    exit();
}

// Fetch order details
$stmt = $pdo->prepare('SELECT o.*, t.name as table_name FROM orders o JOIN tables t ON o.table_id = t.id WHERE o.id = ?');
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    die("Pedido no encontrado");
}

// Update state to indicate prefactura has been printed (state 2)
if ($order['payment_requested'] == 1) {
    $pdo->prepare("UPDATE orders SET payment_requested = 2 WHERE id = ?")->execute([$order_id]);
}

// Fetch order items (grouping identical products with same price)
$stmt = $pdo->prepare('
    SELECT MIN(od.id) as id, p.name as product_name, od.price, SUM(od.quantity) as quantity
    FROM order_details od
    JOIN products p ON od.product_id = p.id
    WHERE od.order_id = ?
    GROUP BY p.id, p.name, od.price
');
$stmt->execute([$order_id]);
$order_items = $stmt->fetchAll();

// Get VAT percentage
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'iva_percentage'");
$stmt->execute();
$iva_percentage = $stmt->fetchColumn() ?: 0;

$subtotal = $order['total'];
$iva_amount = $subtotal * ($iva_percentage / 100);
$total = $subtotal + $iva_amount;

// Get company name
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'company_name'");
$stmt->execute();
$company_name = $stmt->fetchColumn() ?: 'RestoCloud System';

?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Prefactura #<?= $order['id'] ?></title>
    <?php include __DIR__ . '/includes/header.php'; ?>
    <style>
        .invoice-wrapper { max-width: 800px; margin: 40px auto; padding: 40px; background: white; border-radius: 16px; box-shadow: 0 10px 30px rgba(0,0,0,0.1); }
        .invoice-header { text-align: center; margin-bottom: 20px; padding-bottom: 20px; border-bottom: 2px solid #f3f4f6; }
        .invoice-header h1 { color: var(--primary); font-size: 32px; margin: 0 0 5px 0; }
        .invoice-header p { margin: 0 0 5px 0; color: var(--text-secondary); }
        .prefactura-badge { display: inline-block; background: rgba(225, 29, 72, 0.1); color: var(--fc-rose); padding: 8px 16px; border-radius: 20px; font-weight: 700; font-size: 14px; margin-bottom: 15px; border: 1px solid rgba(225, 29, 72, 0.2); }
        .invoice-info { display: grid; grid-template-columns: repeat(2, 1fr); gap: 20px; margin-bottom: 30px; background: #f8fafc; padding: 20px; border-radius: 12px; }
        .info-group label { display: block; color: #64748b; font-size: 14px; margin-bottom: 5px; }
        .info-group div { font-weight: 600; color: #1e293b; font-size: 16px; }
        .invoice-table { width: 100%; border-collapse: collapse; margin-bottom: 30px; }
        .invoice-table th { background: #f1f5f9; color: #475569; font-weight: 600; padding: 15px; text-align: left; border-radius: 8px 8px 0 0; }
        .invoice-table td { padding: 15px; border-bottom: 1px solid #e2e8f0; color: #334155; }
        .total-row td { background: var(--fc-rose); color: white; font-weight: 700; font-size: 18px; }
        .actions-bar { display: flex; gap: 15px; margin-top: 30px; padding-top: 20px; border-top: 2px solid #f3f4f6; }
        
        @media print {
            @page { size: 80mm auto; margin: 0; }
            body { width: 80mm; margin: 0; padding: 5px; background: white; font-family: 'Courier New', monospace; font-size: 12px; color: black; }
            .dashboard-wrapper { display: block; width: 100%; margin: 0; padding: 0; }
            .sidebar, .actions-bar, header, footer { display: none !important; }
            .invoice-wrapper { box-shadow: none; margin: 0; padding: 0; width: 100%; border-radius: 0; }
            .invoice-header { margin-bottom: 10px; padding-bottom: 10px; border-bottom: 1px dashed black; }
            .invoice-header h1 { font-size: 18px; color: black; margin-bottom: 5px; }
            .invoice-header p { font-size: 12px; }
            .prefactura-badge { border: 1px dashed black !important; background: transparent !important; color: black !important; padding: 2px 5px !important; font-size: 12px !important; }
            .invoice-info { display: block; padding: 0; background: white; border: none; margin-bottom: 10px; border-bottom: 1px dashed black; border-radius: 0; }
            .info-group { display: flex; justify-content: space-between; margin-bottom: 5px; }
            .info-group label { display: inline; color: black; font-size: 12px; margin: 0; }
            .info-group div { display: inline; color: black; font-weight: normal; font-size: 12px; }
            .invoice-table { font-size: 12px; margin-bottom: 10px; }
            .invoice-table th { padding: 5px 0; background: white; color: black; border-bottom: 1px dashed black; border-radius: 0; }
            .invoice-table td { padding: 5px 0; border-bottom: 1px dashed #ccc; color: black; }
            .total-row td { background: white; color: black; border-top: 2px dashed black; padding: 10px 0; font-size: 14px; }
            .thermal-footer { text-align: center; font-size: 12px; margin-top: 15px; margin-bottom: 30px; }
        }
    </style>
</head>
<body class="light-mode" onload="window.print()">
    <div class="dashboard-wrapper">
        <div class="sidebar">
            <?php include __DIR__ . '/includes/sidebar.php'; ?>
        </div>
        
        <main class="main-content">
            <header>
                <h1><i class='bx bx-receipt'></i> Visualizar Prefactura</h1>
            </header>

            <div class="invoice-wrapper">
                <div class="invoice-header">
                    <h1><?= htmlspecialchars($company_name) ?></h1>
                    <div class="prefactura-badge">PREFACTURA (NO FISCAL)</div>
                    <p>Pedido #<?= $order['id'] ?></p>
                    <p>Fecha de Impresión: <?= date('d/m/Y h:i A') ?></p>
                </div>

                <div class="invoice-info">
                    <div class="info-group">
                        <label>Mesa/Referencia</label>
                        <div><?= htmlspecialchars($order['table_name']) ?></div>
                    </div>
                    <div class="info-group">
                        <label>Estado</label>
                        <div>Por Cobrar</div>
                    </div>
                </div>

                <table class="invoice-table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th>Cant.</th>
                            <th style="text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order_items as $item): 
                            $item_total = $item['price'] * $item['quantity'];
                        ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($item['product_name']) ?><br>
                                    <small style="color: #64748b;">C$ <?= number_format($item['price'], 2) ?> c/u</small>
                                </td>
                                <td><?= $item['quantity'] ?></td>
                                <td style="text-align: right; font-weight: 600;">C$ <?= number_format($item_total, 2) ?></td>
                            </tr>
                        <?php endforeach; ?>

                        <tr>
                            <td colspan="2" style="text-align: right; color: #64748b;">Subtotal:</td>
                            <td style="text-align: right; font-weight: 600;">C$ <?= number_format($subtotal, 2) ?></td>
                        </tr>

                        <?php if ($iva_amount > 0): ?>
                        <tr>
                            <td colspan="2" style="text-align: right; color: #64748b;">IVA (<?= $iva_percentage ?>%):</td>
                            <td style="text-align: right; font-weight: 600;">C$ <?= number_format($iva_amount, 2) ?></td>
                        </tr>
                        <?php endif; ?>

                        <tr class="total-row">
                            <td colspan="2" style="text-align: right; border-radius: 0 0 0 8px;">TOTAL A PAGAR:</td>
                            <td style="text-align: right; border-radius: 0 0 8px 0;">C$ <?= number_format($total, 2) ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="thermal-footer" style="text-align:center; color:#64748b;">
                    <p style="margin-bottom:5px;"><strong>ESTE DOCUMENTO NO ES COMPROBANTE DE PAGO</strong></p>
                    <p>Revise su cuenta y pague en caja.</p>
                </div>

                <div class="actions-bar">
                    <button onclick="window.print()" class="fc-btn fc-btn-primary">
                        <i class='bx bx-printer'></i> Imprimir Prefactura
                    </button>
                    <a href="cuentas.php" class="fc-btn fc-btn-outline" style="text-decoration: none; display: flex; align-items: center; justify-content: center; padding: 0 20px;">
                        <i class='bx bx-arrow-back'></i> Volver
                    </a>
                </div>
            </div>
        </main>
    </div>
</body>
</html>
