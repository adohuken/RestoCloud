<?php
require_once __DIR__ . '/config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Only Admin (1), SuperAdmin (5), or Cashier (3) can access
if (!in_array($_SESSION['role_id'], [1, 3, 5])) {
    header('Location: inicio.php');
    exit();
}

$order_id = $_GET['id'] ?? null;

if (!$order_id) {
    header('Location: pedidosya.php');
    exit();
}

// Get order info
$stmt = $pdo->prepare('
    SELECT po.*, u.name as created_by_name 
    FROM pedidosya_orders po 
    JOIN users u ON po.created_by = u.id 
    WHERE po.id = ?
');
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: pedidosya.php');
    exit();
}

// Get order details
$stmt = $pdo->prepare('
    SELECT pod.*, p.code as product_code
    FROM pedidosya_order_details pod
    JOIN products p ON pod.product_id = p.id
    WHERE pod.pedidosya_order_id = ?
');
$stmt->execute([$order_id]);
$order_items = $stmt->fetchAll();

// Get company settings
$company_name = 'Sistema Pizzería';
$company_logo = '';
$company_address = '';
$company_phone = '';

try {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM settings");
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    if (!empty($settings['company_name'])) $company_name = $settings['company_name'];
    if (!empty($settings['company_logo'])) $company_logo = $settings['company_logo'];
    if (!empty($settings['company_address'])) $company_address = $settings['company_address'];
    if (!empty($settings['company_phone'])) $company_phone = $settings['company_phone'];
} catch (Exception $e) {}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura PedidosYa #<?= htmlspecialchars($order['external_order_id']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=1.0">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        .invoice-wrapper {
            max-width: 800px;
            margin: 40px auto;
            padding: 40px;
            background: white;
            border-radius: 16px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
        }
        
        .invoice-header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px dashed var(--border-color);
        }
        
        .invoice-header h1 {
            color: var(--primary);
            margin-bottom: 5px;
        }
        
        .pedidosya-badge {
            display: inline-block;
            background: linear-gradient(135deg, #ff4757 0%, #ff6b81 100%);
            color: white;
            padding: 8px 20px;
            border-radius: 20px;
            font-weight: bold;
            font-size: 18px;
            margin: 15px 0;
        }
        
        .invoice-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 30px;
            background: var(--bg-primary);
            padding: 20px;
            border-radius: 12px;
        }
        
        .info-group label {
            font-size: 12px;
            color: var(--text-secondary);
            display: block;
            margin-bottom: 4px;
        }
        
        .info-group div {
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .customer-info {
            background: #fff3cd;
            border: 1px solid #ffc107;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 25px;
        }
        
        .customer-info h4 {
            color: #856404;
            margin-bottom: 10px;
        }
        
        .customer-info p {
            margin: 5px 0;
            color: #856404;
        }
        
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 25px;
        }
        
        .invoice-table th {
            background: #f1f5f9;
            color: #475569;
            font-weight: 600;
            padding: 15px;
            text-align: left;
        }
        
        .invoice-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
        }
        
        .total-section {
            background: var(--bg-primary);
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 25px;
        }
        
        .total-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
        }
        
        .total-row.grand-total {
            font-size: 22px;
            font-weight: bold;
            color: var(--primary);
            padding-top: 15px;
            border-top: 2px solid var(--border-color);
            margin-top: 15px;
        }
        
        .invoice-actions {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-top: 30px;
        }
        
        .notes-section {
            background: #e8f4fd;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 25px;
            border-left: 4px solid var(--primary);
        }
        
        .notes-section h4 {
            color: var(--primary);
            margin-bottom: 8px;
        }
        
        /* Print Styles for 80mm Thermal Printer */
        @media print {
            @page {
                size: 80mm auto;
                margin: 0;
            }
            
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                width: 80mm;
                margin: 0;
                padding: 2mm;
                font-size: 10px;
                background: white;
                font-family: 'Courier New', monospace;
                line-height: 1.2;
            }
            
            .dashboard-wrapper {
                display: block;
                width: 100%;
            }
            
            .sidebar, .invoice-actions, .no-print {
                display: none !important;
            }
            
            .main-content {
                margin: 0 !important;
                padding: 0 !important;
            }
            
            .invoice-wrapper {
                margin: 0;
                padding: 2mm;
                box-shadow: none;
                border-radius: 0;
                max-width: 100%;
            }
            
            .invoice-header {
                margin-bottom: 3mm;
                padding-bottom: 2mm;
                border-bottom: 1px dashed #000;
            }
            
            .invoice-header h1 {
                font-size: 14px;
                margin-bottom: 2px;
                color: black;
            }
            
            .invoice-header p {
                font-size: 9px;
                margin: 1px 0;
            }
            
            .invoice-header img {
                max-height: 35px !important;
                margin-bottom: 2mm;
            }
            
            .pedidosya-badge {
                font-size: 11px;
                padding: 2px 8px;
                margin: 3mm 0;
                background: #333 !important;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            .invoice-info {
                display: block;
                padding: 2mm;
                margin-bottom: 2mm;
                background: none !important;
                border: 1px solid #ccc;
                border-radius: 0;
            }
            
            .info-group {
                display: flex;
                justify-content: space-between;
                margin-bottom: 1mm;
                font-size: 9px;
            }
            
            .info-group label {
                font-size: 9px;
                margin-bottom: 0;
            }
            
            .info-group div {
                font-size: 9px;
                font-weight: normal;
            }
            
            .customer-info {
                padding: 2mm;
                margin-bottom: 2mm;
                border: 1px solid #000;
                background: none !important;
                border-radius: 0;
            }
            
            .customer-info h4 {
                font-size: 10px;
                margin-bottom: 1mm;
                color: black;
            }
            
            .customer-info p {
                font-size: 9px;
                margin: 0.5mm 0;
                color: black;
            }
            
            .invoice-table {
                width: 100%;
                margin-bottom: 2mm;
                border-collapse: collapse;
            }
            
            .invoice-table th {
                background: none !important;
                font-size: 9px;
                padding: 1mm;
                border-bottom: 1px solid #000;
                color: black;
            }
            
            .invoice-table td {
                padding: 1mm;
                font-size: 9px;
                border-bottom: none;
            }
            
            .total-section {
                background: none !important;
                padding: 2mm;
                margin-bottom: 2mm;
                border-radius: 0;
                border-top: 1px dashed #000;
            }
            
            .total-row {
                font-size: 10px;
                margin-bottom: 1mm;
            }
            
            .total-row.grand-total {
                font-size: 12px;
                padding-top: 2mm;
                margin-top: 2mm;
                border-top: 1px solid #000;
                color: black;
            }
            
            .notes-section {
                padding: 2mm;
                margin-bottom: 2mm;
                background: none !important;
                border: 1px dashed #000;
                border-radius: 0;
            }
            
            .notes-section h4 {
                font-size: 9px;
                margin-bottom: 1mm;
                color: black;
            }
            
            .notes-section p {
                font-size: 9px;
            }
            
            /* Footer section */
            .invoice-wrapper > div:last-of-type {
                padding: 2mm !important;
                border-top: 1px dashed #000 !important;
            }
            
            .invoice-wrapper > div:last-of-type p {
                font-size: 8px !important;
                margin: 0.5mm 0 !important;
            }
        }
    </style>
</head>
<body>

<div class="dashboard-wrapper">
    <?php if ($_SESSION['role_id'] == 1 || $_SESSION['role_id'] == 5): ?>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <?php endif; ?>
    
    <main class="main-content" style="<?= $_SESSION['role_id'] == 3 ? 'margin-left: 0;' : '' ?>">
        <div class="invoice-wrapper">
            <div class="invoice-header">
                <?php if ($company_logo): ?>
                    <img src="<?= htmlspecialchars($company_logo) ?>" alt="Logo" style="max-height: 60px;">
                <?php endif; ?>
                <h1><?= htmlspecialchars($company_name) ?></h1>
                <?php if ($company_address): ?>
                    <p style="color: var(--text-secondary); font-size: 13px;"><?= htmlspecialchars($company_address) ?></p>
                <?php endif; ?>
                <?php if ($company_phone): ?>
                    <p style="color: var(--text-secondary); font-size: 13px;">Tel: <?= htmlspecialchars($company_phone) ?></p>
                <?php endif; ?>
                
                <div class="pedidosya-badge">
                    🛵 PedidosYa: <?= htmlspecialchars($order['external_order_id']) ?>
                </div>
                
                <p style="color: var(--text-secondary);">Factura Interna #<?= str_pad($order['id'], 6, '0', STR_PAD_LEFT) ?></p>
            </div>
            
            <div class="invoice-info">
                <div class="info-group">
                    <label>Fecha y Hora</label>
                    <div><?= date('d/m/Y h:i A', strtotime($order['date_created'])) ?></div>
                </div>
                <div class="info-group">
                    <label>Registrado por</label>
                    <div><?= htmlspecialchars($order['created_by_name']) ?></div>
                </div>
            </div>
            
            <?php if ($order['customer_name'] || $order['customer_phone'] || $order['customer_address']): ?>
            <div class="customer-info">
                <h4>📍 Información del Cliente</h4>
                <?php if ($order['customer_name']): ?>
                    <p><strong>👤 Cliente:</strong> <?= htmlspecialchars($order['customer_name']) ?></p>
                <?php endif; ?>
                <?php if ($order['customer_phone']): ?>
                    <p><strong>📞 Teléfono:</strong> <?= htmlspecialchars($order['customer_phone']) ?></p>
                <?php endif; ?>
                <?php if ($order['customer_address']): ?>
                    <p><strong>🏠 Dirección:</strong> <?= htmlspecialchars($order['customer_address']) ?></p>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th style="text-align: center;">Cant.</th>
                        <th style="text-align: right;">Precio</th>
                        <th style="text-align: right;">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order_items as $item): ?>
                        <tr>
                            <td><?= htmlspecialchars($item['product_name']) ?></td>
                            <td style="text-align: center;"><?= $item['quantity'] ?></td>
                            <td style="text-align: right;">C$<?= number_format($item['price'], 2) ?></td>
                            <td style="text-align: right;">C$<?= number_format($item['price'] * $item['quantity'], 2) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="total-section">
                <div class="total-row">
                    <span>Subtotal:</span>
                    <span>C$<?= number_format($order['subtotal'], 2) ?></span>
                </div>
                <?php if ($order['iva_amount'] > 0): ?>
                <div class="total-row">
                    <span>IVA (<?= $order['iva_percentage'] ?>%):</span>
                    <span>C$<?= number_format($order['iva_amount'], 2) ?></span>
                </div>
                <?php endif; ?>
                <div class="total-row grand-total">
                    <span>TOTAL:</span>
                    <span>C$<?= number_format($order['total'], 2) ?></span>
                </div>
            </div>
            
            <?php if ($order['notes']): ?>
            <div class="notes-section">
                <h4>📝 Notas</h4>
                <p><?= nl2br(htmlspecialchars($order['notes'])) ?></p>
            </div>
            <?php endif; ?>
            
            <div style="text-align: center; color: var(--text-secondary); padding: 20px; border-top: 2px dashed var(--border-color);">
                <p><strong>*** PEDIDO POR DELIVERY - PEDIDOSYA ***</strong></p>
                <p style="font-size: 12px;">Este documento es para control interno de inventario</p>
                <p style="font-size: 12px;">El cobro fue realizado a través de PedidosYa</p>
            </div>
            
            <div class="invoice-actions no-print">
                <button onclick="window.print()" class="btn btn-primary">
                    🖨️ Imprimir
                </button>
                <a href="pedidosya.php" class="btn btn-secondary">
                    ← Volver
                </a>
            </div>
        </div>
    </main>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
