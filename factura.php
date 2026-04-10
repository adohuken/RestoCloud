<?php
require_once __DIR__ . '/config/db.php';
session_start();

// Access control
if (!isset($_SESSION['user_id']) || (!in_array($_SESSION['role_id'], [1, 2, 5]))) {
    header('Location: index.php');
    exit();
}

$invoice_id = $_GET['invoice_id'] ?? null;
if (!$invoice_id) {
    header('Location: mesas.php');
    exit();
}

// Fetch invoice details
$stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();

if (!$invoice) {
    die("Factura no encontrada");
}

// Fetch order items
$stmt = $pdo->prepare('SELECT od.*, p.name as product_name FROM order_details od JOIN products p ON od.product_id = p.id WHERE od.order_id = ?');
$stmt->execute([$invoice['order_id']]);
$order_items = $stmt->fetchAll();

// Fetch mixed payments if applicable
$mixed_payments = [];
if (isset($invoice['has_mixed_payments']) && $invoice['has_mixed_payments']) {
    $stmt = $pdo->prepare('SELECT * FROM invoice_payments WHERE invoice_id = ?');
    $stmt->execute([$invoice_id]);
    $mixed_payments = $stmt->fetchAll();
}

// Handle Email Sending
$email_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_email'])) {
    $to = $_POST['email'];
    $subject = "Factura #" . $invoice['id'] . " - Bar System";
    
    // Construct Email Body
    $message = "
    <html>
    <head>
    <title>Factura #{$invoice['id']}</title>
    </head>
    <body>
    <h2>Gracias por su visita</h2>
    <p>Adjuntamos los detalles de su consumo:</p>
    <table border='1' cellpadding='10' style='border-collapse: collapse; width: 100%;'>
        <tr style='background-color: #f2f2f2;'>
            <th>Producto</th>
            <th>Precio</th>
            <th>Cant.</th>
            <th>Subtotal</th>
        </tr>";
    
    foreach ($order_items as $item) {
        $subtotal = number_format($item['price'] * $item['quantity'], 2);
        $price = number_format($item['price'], 2);
        $message .= "
        <tr>
            <td>{$item['product_name']}</td>
            <td>C$ {$price}</td>
            <td>{$item['quantity']}</td>
            <td>C$ {$subtotal}</td>
        </tr>";
    }
    
    $subtotal_val = number_format($invoice['subtotal'] ?? $invoice['total'], 2);
    $message .= "
        <tr>
            <td colspan='3' align='right'><strong>Subtotal</strong></td>
            <td>C$ {$subtotal_val}</td>
        </tr>";

    if (!empty($invoice['iva_amount']) && $invoice['iva_amount'] > 0) {
        $iva_val = number_format($invoice['iva_amount'], 2);
        $message .= "
        <tr>
            <td colspan='3' align='right'><strong>IVA ({$invoice['iva_percentage']}%)</strong></td>
            <td>C$ {$iva_val}</td>
        </tr>";
    }

    $total = number_format($invoice['total'], 2);
    $message .= "
        <tr style='background-color: #333; color: white;'>
            <td colspan='3' align='right'><strong>TOTAL</strong></td>
            <td><strong>C$ {$total}</strong></td>
        </tr>
    </table>
    <p>Fecha: {$invoice['date_created']}</p>
    </body>
    </html>
    ";
    
    // Headers
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= 'From: <noreply@barsystem.com>' . "\r\n";
    
    // Send (with error suppression)
    $mail_sent = @mail($to, $subject, $message, $headers);
    
    if($mail_sent) {
        $email_message = '<div class="alert alert-success">✅ Factura enviada correctamente a ' . htmlspecialchars($to) . '</div>';
    } else {
        $email_message = '<div class="alert alert-warning" style="background: #fef3c7; color: #92400e; padding: 15px; border-radius: 8px; border-left: 4px solid #f59e0b;">
            <strong>⚠️ Servidor de correo no configurado</strong><br>
            <small>El envío de correos requiere configuración SMTP. Para desarrollo local en XAMPP, puede instalar PHPMailer o configurar un servicio SMTP externo (Gmail, Mailgun, etc.).</small>
        </div>';
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Factura #<?php echo $invoice['id']; ?></title>
    <?php include __DIR__ . '/includes/header.php'; ?>
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
            margin-bottom: 40px;
            padding-bottom: 20px;
            border-bottom: 2px solid #f3f4f6;
        }
        .invoice-header h1 {
            color: var(--primary);
            font-size: 32px;
            margin: 0 0 10px 0;
        }
        .invoice-info {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 20px;
            margin-bottom: 30px;
            background: #f8fafc;
            padding: 20px;
            border-radius: 12px;
        }
        .info-group label {
            display: block;
            color: #64748b;
            font-size: 14px;
            margin-bottom: 5px;
        }
        .info-group div {
            font-weight: 600;
            color: #1e293b;
            font-size: 16px;
        }
        .invoice-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 30px;
        }
        .invoice-table th {
            background: #f1f5f9;
            color: #475569;
            font-weight: 600;
            padding: 15px;
            text-align: left;
            border-radius: 8px 8px 0 0;
        }
        .invoice-table td {
            padding: 15px;
            border-bottom: 1px solid #e2e8f0;
            color: #334155;
        }
        .total-row td {
            background: var(--primary);
            color: white;
            font-weight: 700;
            font-size: 18px;
        }
        .actions-bar {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 2px solid #f3f4f6;
        }
        .email-form {
            margin-top: 30px;
            padding: 20px;
            background: #f8fafc;
            border-radius: 12px;
            display: none;
        }
        .email-form.active {
            display: block;
        }
        
        /* Thermal Printer Styles (80mm) */
        @media print {
            @page {
                size: 80mm auto; /* Width 80mm, Height Auto */
                margin: 0;
            }
            body {
                width: 80mm;
                margin: 0;
                padding: 5px;
                background: white;
                font-family: 'Courier New', monospace; /* Monospace looks better on receipts */
                font-size: 12px;
                color: black;
            }
            .dashboard-wrapper { 
                display: block; 
                width: 100%;
                margin: 0;
                padding: 0;
            }
            .sidebar, .actions-bar, .email-form, .btn-secondary, header, footer { 
                display: none !important; 
            }
            .invoice-wrapper { 
                box-shadow: none; 
                margin: 0; 
                padding: 0; 
                width: 100%;
                border-radius: 0;
            }
            .invoice-header {
                margin-bottom: 10px;
                padding-bottom: 10px;
                border-bottom: 1px dashed black;
            }
            .invoice-header h1 {
                font-size: 18px;
                color: black;
                margin-bottom: 5px;
            }
            .invoice-header p {
                font-size: 12px;
                margin: 0;
            }
            .invoice-info {
                display: block;
                background: none;
                padding: 0;
                margin-bottom: 10px;
                border-radius: 0;
            }
            .info-group {
                margin-bottom: 5px;
                display: flex;
                justify-content: space-between;
            }
            .info-group label {
                display: inline;
                color: black;
                font-size: 12px;
            }
            .info-group div {
                display: inline;
                font-weight: normal;
                color: black;
                font-size: 12px;
            }
            .invoice-table {
                margin-bottom: 10px;
                font-size: 12px;
            }
            .invoice-table th {
                background: none;
                color: black;
                padding: 5px 0;
                border-bottom: 1px dashed black;
                font-size: 12px;
            }
            .invoice-table td {
                padding: 5px 0;
                border-bottom: none;
                color: black;
            }
            .total-row td {
                background: none;
                color: black;
                font-weight: bold;
                font-size: 14px;
                border-top: 1px dashed black;
                padding-top: 5px;
            }
            /* Hide non-essential columns for receipt if needed, but let's keep them small */
        }
    </style>
</head>
<body>
<div class="dashboard-wrapper">
    <?php if ($_SESSION['role_id'] == 1): ?>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <?php endif; ?>
    
    <main class="main-content" style="<?= $_SESSION['role_id'] == 2 ? 'margin-left: 0;' : '' ?>">
        <div class="invoice-wrapper">
            <?= $email_message ?>
            
            <div class="invoice-header">
                <h1>Factura #<?= str_pad($invoice['id'], 6, '0', STR_PAD_LEFT) ?></h1>
                <p>Comprobante de Consumo</p>
                <?php if (isset($invoice['split_number']) && $invoice['split_number']): ?>
                    <div style="margin-top: 10px; display: inline-block; background: #e0f2fe; color: #0369a1; padding: 5px 15px; border-radius: 20px; font-weight: bold; font-size: 14px;">
                        Factura Dividida: <?= $invoice['split_number'] ?> de <?= $invoice['total_splits'] ?>
                    </div>
                <?php endif; ?>
            </div>
            
            <div class="invoice-info">
                <div class="info-group">
                    <label>Fecha y Hora</label>
                    <div><?= date('d/m/Y h:i A', strtotime($invoice['date_created'])) ?></div>
                </div>
                <div class="info-group">
                    <label>Mesa / Cliente</label>
                    <div><?= htmlspecialchars($invoice['table_name']) ?></div>
                </div>
                <div class="info-group">
                    <label>Método de Pago</label>
                    <div>
                        <?php 
                        if (isset($invoice['has_mixed_payments']) && $invoice['has_mixed_payments']) {
                            echo "Pago Mixto";
                        } else {
                            $methods = ['cash' => 'Efectivo', 'card' => 'Tarjeta', 'transfer' => 'Transferencia', 'mixed' => 'Mixto', 'pending' => 'Pendiente'];
                            echo $methods[$invoice['payment_method']] ?? $invoice['payment_method'];
                        }
                        ?>
                    </div>
                </div>
                <div class="info-group">
                    <label>Atendido por</label>
                    <div><?= $_SESSION['role_id'] == 1 ? 'Superadmin' : 'Mesero' ?></div>
                </div>
            </div>
            
            <table class="invoice-table">
                <thead>
                    <tr>
                        <th>Producto</th>
                        <th>Precio</th>
                        <th>Cant.</th>
                        <th>Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($order_items as $item): ?>
                    <tr>
                        <td><?= htmlspecialchars($item['product_name']) ?></td>
                        <td>C$ <?= number_format($item['price'], 2) ?></td>
                        <td><?= $item['quantity'] ?></td>
                        <td>C$ <?= number_format($item['price'] * $item['quantity'], 2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <tr class="total-row" style="border-top: 2px solid #e2e8f0;">
                        <td colspan="3" style="text-align: right; background: white; color: #64748b; font-weight: 600;">Subtotal</td>
                        <td style="background: white; color: #334155;">C$ <?= number_format($invoice['subtotal'] ?? $invoice['total'], 2) ?></td>
                    </tr>
                    <?php if (!empty($invoice['iva_amount']) && $invoice['iva_amount'] > 0): ?>
                    <tr class="total-row">
                        <td colspan="3" style="text-align: right; background: white; color: #64748b; font-weight: 600;">IVA (<?= $invoice['iva_percentage'] ?>%)</td>
                        <td style="background: white; color: #334155;">C$ <?= number_format($invoice['iva_amount'], 2) ?></td>
                    </tr>
                    <?php endif; ?>
                    <tr class="total-row">
                        <td colspan="3" style="text-align: right; border-radius: 0 0 0 8px;">TOTAL</td>
                        <td style="border-radius: 0 0 8px 0;">C$ <?= number_format($invoice['total'], 2) ?></td>
                    </tr>
                </tbody>
            </table>
            
            <?php if (!empty($mixed_payments)): ?>
            <div style="margin-bottom: 30px; border-top: 2px dashed #e2e8f0; padding-top: 20px;">
                <h4 style="margin: 0 0 15px 0; color: #64748b;">Desglose de Pago</h4>
                <table style="width: 100%; border-collapse: collapse;">
                    <?php foreach ($mixed_payments as $payment): ?>
                    <tr>
                        <td style="padding: 5px 0; color: #334155;">
                            <?php 
                            $methods = ['cash' => '💵 Efectivo', 'card' => '💳 Tarjeta', 'transfer' => '🏦 Transferencia'];
                            echo $methods[$payment['payment_method']] ?? $payment['payment_method'];
                            ?>
                        </td>
                        <td style="padding: 5px 0; text-align: right; font-weight: 600; color: #334155;">
                            C$ <?= number_format($payment['amount'], 2) ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </table>
            </div>
            <?php endif; ?>
            
            <div class="actions-bar">
                <button onclick="window.print()" class="btn btn-primary">
                    🖨️ Imprimir
                </button>
                <?php if (isset($_GET['from']) && $_GET['from'] === 'reports'): ?>
                    <a href="reportes.php?type=sales" class="btn btn-secondary" style="margin-left: auto;">
                        Volver a Reportes
                    </a>
                <?php else: ?>
                    <a href="mesas.php" class="btn btn-secondary" style="margin-left: auto;">
                        Volver a Mesas
                    </a>
                <?php endif; ?>
            </div>
            
        </div>
    </main>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
