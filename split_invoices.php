<?php
require_once __DIR__ . '/config/db.php';
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

// Get order info
$stmt = $pdo->prepare('SELECT * FROM orders WHERE id = ?');
$stmt->execute([$order_id]);
$order = $stmt->fetch();

if (!$order) {
    die("Orden no encontrada");
}

// Get split invoices
$stmt = $pdo->prepare('SELECT * FROM invoices WHERE order_id = ? AND split_number IS NOT NULL ORDER BY split_number ASC');
$stmt->execute([$order_id]);
$invoices = $stmt->fetchAll();

if (empty($invoices)) {
    // If no split invoices found, maybe it wasn't split? Redirect to order view
    header('Location: ver_pedido.php?table=' . $order['table_id']);
    exit();
}

// Check if all paid
$all_paid = true;
foreach ($invoices as $inv) {
    if ($inv['status'] !== 'paid') {
        $all_paid = false;
        break;
    }
}

// If all paid, close order if not already closed
if ($all_paid && $order['status'] !== 'completed') {
    $stmt = $pdo->prepare('UPDATE orders SET status = "completed" WHERE id = ?');
    $stmt->execute([$order_id]);
    
    // Free table
    $stmt = $pdo->prepare('UPDATE tables SET status = "available" WHERE id = ?');
    $stmt->execute([$order['table_id']]);
    
    // Refresh order info
    $order['status'] = 'completed';
}

?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php if ($_SESSION['role_id'] == 1 || $_SESSION['role_id'] == 5): ?>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <?php endif; ?>
    
    <main class="main-content" style="<?= $_SESSION['role_id'] == 2 ? 'margin-left: 0;' : '' ?>">
        <div class="page-header">
            <h1>Facturas Divididas</h1>
            <p>Orden #<?= $order_id ?> - Mesa: <?= htmlspecialchars($invoices[0]['table_name']) ?></p>
        </div>
        
        <div class="split-invoices-container">
            <?php foreach ($invoices as $invoice): ?>
                <div class="card invoice-card <?= $invoice['status'] == 'paid' ? 'paid' : 'pending' ?>">
                    <div class="invoice-card-header">
                        <h3>Persona <?= $invoice['split_number'] ?> de <?= $invoice['total_splits'] ?></h3>
                        <span class="badge <?= $invoice['status'] == 'paid' ? 'badge-success' : 'badge-warning' ?>">
                            <?= $invoice['status'] == 'paid' ? 'PAGADO' : 'PENDIENTE' ?>
                        </span>
                    </div>
                    
                    <div class="invoice-amount">
                        C$ <?= number_format($invoice['total'], 2) ?>
                    </div>
                    
                    <div class="invoice-actions">
                        <?php if ($invoice['status'] == 'paid'): ?>
                            <a href="factura.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-secondary btn-block">
                                📄 Ver Factura
                            </a>
                        <?php else: ?>
                            <a href="procesar_pago_split.php?invoice_id=<?= $invoice['id'] ?>" class="btn btn-primary btn-block">
                                💳 Pagar Ahora
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="actions-bar" style="margin-top: 30px;">
            <a href="mesas.php" class="btn btn-secondary">Volver a Mesas</a>
        </div>
    </main>
</div>

<style>
.split-invoices-container {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 20px;
    padding: 20px 0;
}

.invoice-card {
    border: 2px solid #334155;
    transition: all 0.3s ease;
    border-radius: 12px;
    background: #1e293b; /* Dark card background */
    overflow: hidden;
}

.invoice-card.paid {
    border-color: var(--success);
    background: rgba(34, 197, 94, 0.1);
}

.invoice-card.pending {
    border-color: #f59e0b;
    background: rgba(245, 158, 11, 0.1);
}

.invoice-card-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 15px;
    padding: 15px;
    background: rgba(0,0,0,0.2);
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.invoice-card-header h3 {
    margin: 0;
    color: white;
    font-size: 18px;
}

.invoice-amount {
    font-size: 36px;
    font-weight: bold;
    color: white;
    text-align: center;
    margin: 30px 0;
    text-shadow: 0 2px 4px rgba(0,0,0,0.3);
}

.invoice-card.paid .invoice-amount {
    color: #4ade80;
}

.invoice-card.pending .invoice-amount {
    color: #fbbf24;
}

.invoice-actions {
    padding: 20px;
}

.badge {
    padding: 5px 10px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.badge-success {
    background: var(--success);
    color: white;
}

.badge-warning {
    background: var(--warning);
    color: white;
}
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>
