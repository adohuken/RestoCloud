<?php
require_once __DIR__ . '/config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$invoice_id = $_GET['invoice_id'] ?? null;

if (!$invoice_id) {
    header('Location: mesas.php');
    exit();
}

// Get invoice info
$stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
$stmt->execute([$invoice_id]);
$invoice = $stmt->fetch();

if (!$invoice || $invoice['status'] == 'paid') {
    header('Location: split_invoices.php?order_id=' . ($invoice['order_id'] ?? ''));
    exit();
}

// Get active register
$stmt = $pdo->query('SELECT * FROM cash_register WHERE type = "open" AND status = "active" ORDER BY date_created DESC LIMIT 1');
$active_register = $stmt->fetch();

// Process Payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$active_register) {
        $error = 'Debe abrir la caja antes de procesar pagos';
    } else {
        try {
            $pdo->beginTransaction();
            
            $total_to_pay = $invoice['total'];
            
            if (isset($_POST['process_payment'])) {
                // === SINGLE PAYMENT ===
                $payment_method = $_POST['payment_method'];
                
                // Insert into payments (Global payment record for the order)
                $stmt = $pdo->prepare('INSERT INTO payments (order_id, amount, method, cash_register_id) VALUES (?, ?, ?, ?)');
                $stmt->execute([$invoice['order_id'], $total_to_pay, $payment_method, $active_register['id']]);
                
                // Update Invoice
                $stmt = $pdo->prepare('UPDATE invoices SET status = "paid", payment_method = ?, has_mixed_payments = 0 WHERE id = ?');
                $stmt->execute([$payment_method, $invoice_id]);
                
                // Record in invoice_payments
                $stmt = $pdo->prepare('INSERT INTO invoice_payments (invoice_id, payment_method, amount) VALUES (?, ?, ?)');
                $stmt->execute([$invoice_id, $payment_method, $total_to_pay]);
                
            } elseif (isset($_POST['process_mixed_payment'])) {
                // === MIXED PAYMENT ===
                $methods = $_POST['mixed_methods'] ?? [];
                $amounts = $_POST['mixed_amounts'] ?? [];
                
                $valid_payments = [];
                $total_paid = 0;
                
                foreach ($methods as $index => $method) {
                    $amount = floatval($amounts[$index]);
                    if ($amount > 0) {
                        $valid_payments[] = ['method' => $method, 'amount' => $amount];
                        $total_paid += $amount;
                    }
                }
                
                if (abs($total_paid - $total_to_pay) > 0.05) {
                    throw new Exception("El total pagado no coincide con el monto de la factura.");
                }
                
                // Update Invoice
                $stmt = $pdo->prepare('UPDATE invoices SET status = "paid", payment_method = "mixed", has_mixed_payments = 1 WHERE id = ?');
                $stmt->execute([$invoice_id]);
                
                foreach ($valid_payments as $payment) {
                    // Insert into payments
                    $stmt = $pdo->prepare('INSERT INTO payments (order_id, amount, method, cash_register_id) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$invoice['order_id'], $payment['amount'], $payment['method'], $active_register['id']]);
                    
                    // Insert into invoice_payments
                    $stmt = $pdo->prepare('INSERT INTO invoice_payments (invoice_id, payment_method, amount) VALUES (?, ?, ?)');
                    $stmt->execute([$invoice_id, $payment['method'], $payment['amount']]);
                }
            }
            
            $pdo->commit();
            header('Location: split_invoices.php?order_id=' . $invoice['order_id']);
            exit();
            
        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error: ' . $e->getMessage();
        }
    }
}
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php if ($_SESSION['role_id'] == 1 || $_SESSION['role_id'] == 5): ?>
    <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <?php endif; ?>
    
    <main class="main-content" style="<?= $_SESSION['role_id'] == 2 ? 'margin-left: 0;' : '' ?>">
        <div class="page-header">
            <h1>Pagar Factura Dividida</h1>
            <p>Factura <?= $invoice['split_number'] ?> de <?= $invoice['total_splits'] ?> - Mesa: <?= htmlspecialchars($invoice['table_name']) ?></p>
        </div>
        
        <?php if (isset($error)): ?>
            <div class="alert alert-danger">❌ <?= $error ?></div>
        <?php endif; ?>
        
        <div class="card" style="max-width: 600px; margin: 0 auto;">
            <div class="card-header">
                <h3>Monto a Pagar: C$ <?= number_format($invoice['total'], 2) ?></h3>
            </div>
            
            <div class="payment-tabs">
                <button class="tab-btn active" onclick="switchTab('single')">Pago Único</button>
                <button class="tab-btn" onclick="switchTab('mixed')">Pago Mixto</button>
            </div>
            
            <div class="tab-content" style="padding: 30px;">
                <!-- SINGLE PAYMENT -->
                <div id="tab-single" class="tab-pane active">
                    <form method="POST">
                        <input type="hidden" name="process_payment" value="1">
                        
                        <div class="payment-methods-horizontal">
                            <label class="payment-option-horizontal">
                                <input type="radio" name="payment_method" value="cash" required <?= !$active_register ? 'disabled' : '' ?>>
                                <div class="payment-card-horizontal">
                                    <div class="payment-icon-horizontal">💵</div>
                                    <div class="payment-label-horizontal">Efectivo</div>
                                </div>
                            </label>
                            <label class="payment-option-horizontal">
                                <input type="radio" name="payment_method" value="card" required <?= !$active_register ? 'disabled' : '' ?>>
                                <div class="payment-card-horizontal">
                                    <div class="payment-icon-horizontal">💳</div>
                                    <div class="payment-label-horizontal">Tarjeta</div>
                                </div>
                            </label>
                            <label class="payment-option-horizontal">
                                <input type="radio" name="payment_method" value="transfer" required <?= !$active_register ? 'disabled' : '' ?>>
                                <div class="payment-card-horizontal">
                                    <div class="payment-icon-horizontal">🏦</div>
                                    <div class="payment-label-horizontal">Transferencia</div>
                                </div>
                            </label>
                        </div>
                        
                        <button type="submit" class="btn btn-primary btn-block btn-lg" <?= !$active_register ? 'disabled' : '' ?>>
                            Confirmar Pago
                        </button>
                    </form>
                </div>
                
                <!-- MIXED PAYMENT -->
                <div id="tab-mixed" class="tab-pane">
                    <form method="POST" onsubmit="return validateMixedPayment()">
                        <input type="hidden" name="process_mixed_payment" value="1">
                        
                        <div id="mixed-payments-container">
                            <!-- Dynamic rows -->
                        </div>
                        
                        <button type="button" class="btn btn-secondary btn-sm" onclick="addMixedPaymentRow()" style="margin-bottom: 20px;">
                            + Agregar Método
                        </button>

                        <div class="payment-summary">
                            <div class="summary-row">
                                <span>Total Factura:</span>
                                <span>C$ <span id="mixed-total-bill"><?= number_format($invoice['total'], 2) ?></span></span>
                            </div>
                            <div class="summary-row">
                                <span>Total Ingresado:</span>
                                <span id="mixed-total-paid">C$ 0.00</span>
                            </div>
                            <div class="summary-row remaining">
                                <span>Restante:</span>
                                <span id="mixed-remaining">C$ <?= number_format($invoice['total'], 2) ?></span>
                            </div>
                        </div>
                        
                        <button type="submit" id="btn-mixed-pay" class="btn btn-primary btn-block btn-lg" disabled>
                            Procesar Pago Mixto
                        </button>
                    </form>
                </div>
            </div>
        </div>
        
        <div style="text-align: center; margin-top: 20px;">
            <a href="split_invoices.php?order_id=<?= $invoice['order_id'] ?>" class="btn btn-secondary">Cancelar</a>
        </div>
    </main>
</div>

<style>
/* Reusing styles from ver_pedido.php with Dark Theme adjustments */
.payment-tabs { display: flex; border-bottom: 2px solid #334155; margin-bottom: 20px; }
.tab-btn { flex: 1; padding: 15px; background: none; border: none; font-weight: 600; color: #94a3b8; cursor: pointer; border-bottom: 3px solid transparent; font-size: 16px; }
.tab-btn.active { color: var(--primary); border-bottom-color: var(--primary); background: rgba(99, 102, 241, 0.1); }
.tab-pane { display: none; animation: fadeIn 0.3s ease; }
.tab-pane.active { display: block; }

.payment-methods-horizontal { display: grid; grid-template-columns: repeat(3, 1fr); gap: 15px; margin-bottom: 25px; }
.payment-card-horizontal { border: 2px solid #334155; padding: 20px; text-align: center; border-radius: 12px; cursor: pointer; background: #1e293b; transition: all 0.3s; }
.payment-card-horizontal:hover { border-color: var(--primary); transform: translateY(-2px); }
.payment-option-horizontal input[type="radio"] { display: none; }
.payment-option-horizontal input:checked + .payment-card-horizontal { border-color: var(--primary); background: rgba(99, 102, 241, 0.1); }
.payment-icon-horizontal { font-size: 32px; margin-bottom: 10px; }
.payment-label-horizontal { color: white; font-weight: 600; }

.mixed-payment-row { display: flex; gap: 10px; margin-bottom: 15px; }
.mixed-payment-row select, .mixed-payment-row input { padding: 12px; border: 1px solid #475569; border-radius: 8px; background: #1e293b; color: white; }
.mixed-payment-row select { flex: 1; }
.mixed-payment-row input { width: 120px; text-align: right; }
.btn-remove { background: rgba(220, 38, 38, 0.2); color: #f87171; border: 1px solid #dc2626; border-radius: 8px; width: 45px; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 20px; }
.btn-remove:hover { background: #dc2626; color: white; }

.payment-summary { background: #1e293b; padding: 20px; border-radius: 12px; margin-bottom: 25px; border: 1px solid #334155; }
.summary-row { display: flex; justify-content: space-between; margin-bottom: 8px; color: #cbd5e1; }
.summary-row span:last-child { color: white; font-weight: 600; }
.summary-row.remaining { border-top: 1px solid #334155; padding-top: 15px; margin-top: 10px; font-weight: bold; font-size: 18px; color: var(--primary); }

@keyframes fadeIn { from { opacity: 0; transform: translateY(5px); } to { opacity: 1; transform: translateY(0); } }
</style>

<script>
const TOTAL_BILL = <?= $invoice['total'] ?>;

function switchTab(tabName) {
    document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    document.getElementById('tab-' + tabName).classList.add('active');
    
    const index = ['single', 'mixed'].indexOf(tabName);
    document.querySelectorAll('.tab-btn')[index].classList.add('active');
    
    if (tabName === 'mixed' && document.getElementById('mixed-payments-container').children.length === 0) {
        addMixedPaymentRow();
    }
}

function addMixedPaymentRow() {
    const container = document.getElementById('mixed-payments-container');
    const div = document.createElement('div');
    div.className = 'mixed-payment-row';
    div.innerHTML = `
        <select name="mixed_methods[]" onchange="calculateMixedTotal()">
            <option value="cash">💵 Efectivo</option>
            <option value="card">💳 Tarjeta</option>
            <option value="transfer">🏦 Transferencia</option>
        </select>
        <input type="number" name="mixed_amounts[]" step="0.01" placeholder="0.00" oninput="calculateMixedTotal()">
        <button type="button" class="btn-remove" onclick="removeMixedRow(this)">×</button>
    `;
    container.appendChild(div);
}

function removeMixedRow(btn) {
    btn.parentElement.remove();
    calculateMixedTotal();
}

function calculateMixedTotal() {
    const inputs = document.querySelectorAll('input[name="mixed_amounts[]"]');
    let totalPaid = 0;
    inputs.forEach(input => totalPaid += parseFloat(input.value || 0));
    
    const remaining = TOTAL_BILL - totalPaid;
    
    document.getElementById('mixed-total-paid').textContent = 'C$ ' + totalPaid.toFixed(2);
    document.getElementById('mixed-remaining').textContent = 'C$ ' + remaining.toFixed(2);
    
    const btn = document.getElementById('btn-mixed-pay');
    const remainingEl = document.querySelector('.summary-row.remaining');
    
    if (Math.abs(remaining) < 0.05) {
        btn.disabled = false;
        remainingEl.style.color = 'var(--success)';
        document.getElementById('mixed-remaining').textContent = '✅ Completo';
    } else {
        btn.disabled = true;
        remainingEl.style.color = 'var(--danger)';
    }
}

function validateMixedPayment() {
    const inputs = document.querySelectorAll('input[name="mixed_amounts[]"]');
    let totalPaid = 0;
    inputs.forEach(input => totalPaid += parseFloat(input.value || 0));
    
    if (Math.abs(TOTAL_BILL - totalPaid) > 0.05) {
        alert('El monto total pagado debe ser igual al total de la factura.');
        return false;
    }
    return true;
}
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
