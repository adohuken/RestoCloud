<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$order_id = $_GET['order_id'] ?? null;

if (!$order_id) {
    header('Location: mesas.php');
    exit();
}

// Check module access (Cuentas or Caja usually handles this)
checkModuleAccess($pdo, $_SESSION['role_id'], 'cuentas');

// Get Table Info
$stmt = $pdo->prepare('SELECT t.name as table_name FROM orders o JOIN tables t ON o.table_id = t.id WHERE o.id = ?');
$stmt->execute([$order_id]);
$table_name = $stmt->fetchColumn();

// Get Invoices
$stmt = $pdo->prepare('SELECT * FROM invoices WHERE order_id = ? ORDER BY split_number ASC, id ASC');
$stmt->execute([$order_id]);
$invoices = $stmt->fetchAll();

$pageTitle = "Cuentas Divididas - " . ($table_name ?: "Pedido #" . $order_id);
include __DIR__ . '/includes/header.php';
?>

<div class="dashboard-wrapper">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    
    <main class="main-content">
        <header class="top-bar" style="margin-bottom: 25px;">
            <div class="header-left">
                <a href="cuentas.php" class="fc-btn fc-btn-secondary" style="margin-right: 15px; border-radius: 50%; width: 40px; height: 40px; padding: 0; display: flex; align-items: center; justify-content: center;">
                    <i class='bx bx-arrow-back'></i>
                </a>
                <h1 style="margin: 0; font-size: 1.8rem; color: var(--fc-text-primary); font-weight: 700;">
                    Cuentas Divididas
                    <span style="font-size: 1rem; color: var(--fc-text-sec); font-weight: 500; margin-left: 10px; background: rgba(99, 102, 241, 0.1); padding: 4px 12px; border-radius: 20px;">
                        <?= htmlspecialchars($table_name) ?>
                    </span>
                </h1>
            </div>
        </header>

        <div class="fc-grid fc-grid-3">
            <?php foreach ($invoices as $index => $invoice): ?>
                <div class="fc-card" style="position: relative; <?= $invoice['status'] === 'paid' ? 'opacity: 0.8; background: #f8fafc;' : '' ?>">
                    <?php if ($invoice['status'] === 'paid'): ?>
                        <div style="position: absolute; top: -10px; right: -10px; background: #10b981; color: white; width: 30px; height: 30px; border-radius: 50%; display: flex; align-items: center; justify-content: center; box-shadow: 0 4px 10px rgba(16, 185, 129, 0.3);">
                            <i class='bx bx-check'></i>
                        </div>
                    <?php endif; ?>
                    
                    <div style="padding: 25px;">
                        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                            <h3 style="margin: 0; font-size: 1.2rem; color: var(--fc-text-primary);">
                                <?php if ($invoice['total_splits'] > 1 && $invoice['split_number'] > 0): ?>
                                    Persona <?= $invoice['split_number'] ?> de <?= $invoice['total_splits'] ?>
                                <?php else: ?>
                                    Consumo Individual <?= $index + 1 ?>
                                <?php endif; ?>
                            </h3>
                            <span style="font-size: 0.85rem; padding: 4px 10px; border-radius: 12px; font-weight: 600; <?= $invoice['status'] === 'paid' ? 'background: rgba(16, 185, 129, 0.1); color: #059669;' : 'background: rgba(245, 158, 11, 0.1); color: #d97706;' ?>">
                                <?= $invoice['status'] === 'paid' ? 'PAGADO' : 'PENDIENTE' ?>
                            </span>
                        </div>
                        
                        <div style="font-size: 2rem; font-weight: 800; color: var(--fc-primary); margin-bottom: 25px; text-align: center;">
                            C$<?= number_format($invoice['total'], 2) ?>
                        </div>
                        
                        <?php if ($invoice['status'] === 'paid'): ?>
                            <a href="factura.php?invoice_id=<?= $invoice['id'] ?>" class="fc-btn fc-btn-secondary fc-w100" style="text-align: center;">
                                <i class='bx bx-receipt'></i> Ver Factura
                            </a>
                        <?php else: ?>
                            <a href="procesar_pago_split.php?invoice_id=<?= $invoice['id'] ?>" class="fc-btn fc-btn-primary fc-w100" style="text-align: center;">
                                <i class='bx bx-credit-card'></i> Procesar Pago
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <?php 
        $all_paid = true;
        foreach ($invoices as $invoice) {
            if ($invoice['status'] !== 'paid') {
                $all_paid = false;
                break;
            }
        }
        ?>
        
        <?php if ($all_paid && count($invoices) > 0): ?>
            <div class="fc-alert fc-alert-green" style="margin-top: 30px; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong><i class='bx bx-check-double'></i> ¡Mesa Completada!</strong>
                    <p style="margin: 5px 0 0 0; opacity: 0.9;">Todos los pagos de esta mesa han sido procesados.</p>
                </div>
                <!-- Optional: Force close table if not already closed by the last payment -->
                <a href="cuentas.php" class="fc-btn fc-badge-outline" style="background: white; border-color: transparent;">Volver a Cuentas</a>
            </div>
            
            <?php
            // Just in case, make sure the order status is updated to completed
            $stmt = $pdo->prepare('UPDATE orders SET status = "completed" WHERE id = ? AND status != "completed"');
            $stmt->execute([$order_id]);
            
            // And free the table
            $stmt = $pdo->prepare('UPDATE tables SET status = "available", current_order_id = NULL WHERE current_order_id = ?');
            $stmt->execute([$order_id]);
            ?>
        <?php endif; ?>

    </main>
</div>
</body>
</html>
