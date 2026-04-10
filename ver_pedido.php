<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/inventory_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Allow access for Admin (1), Waiter (2), Cashier (3), and SuperAdmin (5)
if ($_SESSION['role_id'] != 1 && $_SESSION['role_id'] != 2 && $_SESSION['role_id'] != 3 && $_SESSION['role_id'] != 5) {
    header('Location: inicio.php');
    exit();
}

$table_id = $_GET['table'] ?? null;
$view_mode = $_GET['view'] ?? 'payment'; // Default to payment for backward compatibility, but links now send 'bill'

if (!$table_id) {
    header('Location: mesas.php');
    exit();
}

// Get table info
$stmt = $pdo->prepare('SELECT * FROM tables WHERE id = ?');
$stmt->execute([$table_id]);
$table = $stmt->fetch();

if (!$table) {
    header('Location: mesas.php');
    exit();
}

// Get active order for this table
$stmt = $pdo->prepare('SELECT * FROM orders WHERE table_id = ? AND status IN ("draft", "pending", "ready", "preparing", "picked_up", "delivered") LIMIT 1');
$stmt->execute([$table_id]);
$order = $stmt->fetch();

if (!$order) {
    header('Location: mesas.php');
    exit();
}

// Get order details
$stmt = $pdo->prepare('
    SELECT od.*, p.name as product_name
    FROM order_details od
    JOIN products p ON od.product_id = p.id
    WHERE od.order_id = ?
');
$stmt->execute([$order['id']]);
$order_items = $stmt->fetchAll();

// Check if there's ANY active cash register (not just user's own)
$stmt = $pdo->query('SELECT * FROM cash_register WHERE type = "open" AND status = "active" ORDER BY date_created DESC LIMIT 1');
$active_register = $stmt->fetch();

// Check if order is already split
$stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE order_id = ? AND split_number IS NOT NULL');
$stmt->execute([$order['id']]);
$has_splits = $stmt->fetchColumn() > 0;

if ($has_splits) {
    header('Location: split_invoices.php?order_id=' . $order['id']);
    exit();
}

// Process payment
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$active_register) {
        $error = 'Debe abrir la caja antes de procesar pagos';
    } else {
        // Get VAT percentage and Tips config
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('iva_percentage', 'enable_tips')");
        $stmt->execute();
        $settings_data = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        $iva_percentage = $settings_data['iva_percentage'] ?? 0;
        $enable_tips = $settings_data['enable_tips'] ?? '0';

        $tip_amount = isset($_POST['tip_amount']) ? floatval($_POST['tip_amount']) : 0;

        // Calculate totals
        $subtotal = $order['total'];
        $iva_amount = $subtotal * ($iva_percentage / 100);
        $total_with_iva = $subtotal + $iva_amount;

        // Ensure tables exist (Schema check skipped for brevity as we ran the update script)

        $pdo->beginTransaction();

        try {
            // Update stock (Using New Enterprise Inventory Manager)
            if (!isset($_POST['process_split_bill'])) {
                InventoryManager::processOrderStock($order['id'], $_SESSION['user_id']);
            }

            if (isset($_POST['process_payment'])) {
                // === SINGLE PAYMENT ===
                $payment_method = $_POST['payment_method'];

                // Insert into payments
                $stmt = $pdo->prepare('INSERT INTO payments (order_id, amount, method, cash_register_id) VALUES (?, ?, ?, ?)');
                $stmt->execute([$order['id'], $total_with_iva + $tip_amount, $payment_method, $active_register['id']]);

                // Create Invoice
                $stmt = $pdo->prepare('INSERT INTO invoices (order_id, table_name, subtotal, iva_amount, iva_percentage, tip_amount, total, payment_method) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([$order['id'], $table['name'], $subtotal, $iva_amount, $iva_percentage, $tip_amount, $total_with_iva + $tip_amount, $payment_method]);
                $invoice_id = $pdo->lastInsertId();

                // Insert into invoice_payments
                $stmt = $pdo->prepare('INSERT INTO invoice_payments (invoice_id, payment_method, amount) VALUES (?, ?, ?)');
                $stmt->execute([$invoice_id, $payment_method, $total_with_iva + $tip_amount]);

            } elseif (isset($_POST['process_mixed_payment'])) {
                // === MIXED PAYMENT ===
                $methods = $_POST['mixed_methods'] ?? [];
                $amounts = $_POST['mixed_amounts'] ?? [];

                // Filter out empty entries
                $valid_payments = [];
                $total_paid = 0;

                foreach ($methods as $index => $method) {
                    $amount = floatval($amounts[$index]);
                    if ($amount > 0) {
                        $valid_payments[] = ['method' => $method, 'amount' => $amount];
                        $total_paid += $amount;
                    }
                }

                // Validate total (allow small float diff)
                if (abs($total_paid - ($total_with_iva + $tip_amount)) > 0.05) {
                    throw new Exception("El total pagado (C$ " . number_format($total_paid, 2) . ") no coincide con el total de la factura (C$ " . number_format($total_with_iva + $tip_amount, 2) . ")");
                }

                // Create Invoice (marked as mixed)
                $stmt = $pdo->prepare('INSERT INTO invoices (order_id, table_name, subtotal, iva_amount, iva_percentage, tip_amount, total, payment_method, has_mixed_payments) VALUES (?, ?, ?, ?, ?, ?, ?, "mixed", 1)');
                $stmt->execute([$order['id'], $table['name'], $subtotal, $iva_amount, $iva_percentage, $tip_amount, $total_with_iva + $tip_amount]);
                $invoice_id = $pdo->lastInsertId();

                // Process each payment
                foreach ($valid_payments as $payment) {
                    // Insert into payments (for cash register)
                    $stmt = $pdo->prepare('INSERT INTO payments (order_id, amount, method, cash_register_id) VALUES (?, ?, ?, ?)');
                    $stmt->execute([$order['id'], $payment['amount'], $payment['method'], $active_register['id']]);

                    // Insert into invoice_payments
                    $stmt = $pdo->prepare('INSERT INTO invoice_payments (invoice_id, payment_method, amount) VALUES (?, ?, ?)');
                    $stmt->execute([$invoice_id, $payment['method'], $payment['amount']]);
                }

            } elseif (isset($_POST['process_split_bill'])) {
                // === SPLIT BILL (EQUAL PARTS) ===
                $num_splits = intval($_POST['split_count']);

                if ($num_splits < 2)
                    throw new Exception("Debe dividir entre al menos 2 personas");

                // Calculate equal split
                $base_amount = floor(($total_with_iva / $num_splits) * 100) / 100;
                $remainder = $total_with_iva - ($base_amount * $num_splits);

                // Generate Pending Invoices
                for ($i = 0; $i < $num_splits; $i++) {
                    $amount = $base_amount;
                    if ($i === 0)
                        $amount += $remainder; // Add remainder to first split

                    $split_num = $i + 1;

                    // Proportional values
                    $ratio = $amount / $total_with_iva;
                    $split_subtotal = $subtotal * $ratio;
                    $split_iva = $iva_amount * $ratio;

                    $stmt = $pdo->prepare('INSERT INTO invoices (order_id, table_name, subtotal, iva_amount, iva_percentage, total, payment_method, split_number, total_splits, status) VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?, "pending")');
                    $stmt->execute([$order['id'], $table['name'], $split_subtotal, $split_iva, $iva_percentage, $amount, $split_num, $num_splits]);
                }

                $pdo->commit();
                header('Location: split_invoices.php?order_id=' . $order['id']);
                exit();

            } elseif (isset($_POST['process_individual_bill'])) {
                // === INDIVIDUAL BILL (BY CONSUMPTION) ===
                $splits_data = json_decode($_POST['individual_splits_data'], true);

                if (!$splits_data || !is_array($splits_data)) {
                    throw new Exception("Datos de división inválidos");
                }

                $num_splits = count($splits_data);

                foreach ($splits_data as $index => $person_items) {
                    $person_subtotal = 0;

                    // Calculate person total based on items
                    foreach ($person_items as $item) {
                        $price = floatval($item['price']);
                        $qty = intval($item['quantity']);
                        $person_subtotal += $price * $qty;
                    }

                    $person_iva = $person_subtotal * ($iva_percentage / 100);
                    $person_total = $person_subtotal + $person_iva;

                    $split_num = $index + 1;

                    // Create invoice for this person
                    // Note: We are not storing the specific items per invoice in the DB yet (schema limitation), 
                    // but the total amount is correct. The detailed view might show generic "Consumo" or we'd need a new table `invoice_items`.
                    // For now, we'll rely on the total amount.

                    $stmt = $pdo->prepare('INSERT INTO invoices (order_id, table_name, subtotal, iva_amount, iva_percentage, total, payment_method, split_number, total_splits, status) VALUES (?, ?, ?, ?, ?, ?, "pending", ?, ?, "pending")');
                    $stmt->execute([$order['id'], $table['name'], $person_subtotal, $person_iva, $iva_percentage, $person_total, $split_num, $num_splits]);
                }

                $pdo->commit();
                header('Location: split_invoices.php?order_id=' . $order['id']);
                exit();
            }

            // Common closure for Paid actions (Single and Mixed)
            if (isset($_POST['process_payment']) || isset($_POST['process_mixed_payment'])) {
                // Close order
                $stmt = $pdo->prepare('UPDATE orders SET status = "completed", total = ? WHERE id = ?');
                $stmt->execute([$total_with_iva + $tip_amount, $order['id']]);

                // Free table
                $stmt = $pdo->prepare('UPDATE tables SET status = "available" WHERE id = ?');
                $stmt->execute([$table_id]);

                $pdo->commit();
                header('Location: factura.php?invoice_id=' . $invoice_id);
                exit();
            }

        } catch (Exception $e) {
            $pdo->rollBack();
            $error = 'Error al procesar: ' . $e->getMessage();
        }
    }
}
?>
<?php
// Get user's role name
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';

// Fetch tips setting for UI
$stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'enable_tips'");
$stmt->execute();
$enable_tips_ui = $stmt->fetchColumn() ?: '0';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php if ($_SESSION['role_id'] == 1 || $_SESSION['role_id'] == 5 || $_SESSION['role_id'] == 2 || $_SESSION['role_id'] == 3): ?>
        <!-- Sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <?php endif; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1>Cuenta de <?= htmlspecialchars($table['name']) ?></h1>
                <p>Pedido #<?= $order['id'] ?></p>
            </div>
            <div class="user-profile-header">
                <div class="user-avatar">
                    <?= strtoupper(substr($_SESSION['name'], 0, 1)) ?>
                </div>
                <div class="user-details">
                    <span class="user-name"><?= htmlspecialchars($_SESSION['name']) ?></span>
                    <span class="user-role"><?= htmlspecialchars($user_role_name) ?></span>
                </div>
            </div>
        </div>

        <?php if (isset($error)): ?>
            <div class="alert alert-danger">
                ❌ <?= $error ?>
            </div>
        <?php endif; ?>

        <?php if ($_SESSION['role_id'] != 2): // Hide for waiters ?>
            <?php if (!$active_register): ?>
                <div class="alert alert-warning">
                    ⚠️ Debe abrir la caja antes de procesar pagos. <a href="caja.php"
                        style="color: var(--primary); font-weight: 600;">Ir a Caja</a>
                </div>
            <?php endif; ?>
        <?php endif; ?>

        <div class="fc-invoice-grid<?= $_SESSION['role_id'] == 2 ? ' waiter-view' : '' ?> <?= $view_mode === 'bill' ? 'view-only-mode' : '' ?>">
            <?php if ($_SESSION['role_id'] != 2): // Hide payment card for waiters ?>
                <?php if ($view_mode === 'bill'): ?>
                    <!-- View Only Mode: Show Button to Start Payment -->
                    <div class="fc-card" style="text-align: center; padding: 50px;">
                        <div style="font-size: 64px; margin-bottom: 25px; filter: drop-shadow(0 0 15px rgba(225, 29, 72, 0.4));">💳</div>
                        <h2 class="fc-text-rose" style="font-weight: 800; margin-bottom: 10px;">LISTO PARA PAGO</h2>
                        <p style="color: var(--fc-text-sec); margin-bottom: 35px; font-size: 1.1em;">
                            Revise el detalle de la cuenta a la derecha.<br>
                            Si todo está correcto, proceda al cobro seguro.
                        </p>
                        <a href="ver_pedido.php?table=<?= $table_id ?>&view=payment"
                            class="fc-btn fc-btn-primary fc-w100" style="font-size: 1.2em; padding: 18px;">
                            <i class='bx bx-check-double'></i> PROCESAR PAGO
                        </a>
                        <div class="fc-mt-20">
                            <a href="venta.php?table=<?= $table_id ?>" class="fc-btn" style="color: var(--fc-text-sec);">
                                <i class='bx bx-arrow-back'></i> Volver al Pedido
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="fc-card">
 
                        <div class="fc-card-header primary">
                            <h3 style="margin:0;"><i class='bx bx-credit-card'></i> Procesar Pago</h3>
                        </div>

                        <?php if($enable_tips_ui == '1'): ?>
                            <div class="fc-alert fc-alert-rose" style="margin: 20px; flex-direction: column; align-items: stretch; gap: 15px;">
                                <label class="fc-label" style="color: var(--fc-primary); display: flex; align-items: center; gap: 8px;">
                                    <i class='bx bx-coin-stack'></i> AGREGAR PROPINA / CARGO DE SERVICIO
                                </label>
                                <div style="display: flex; gap: 10px; align-items: center;">
                                    <button type="button" class="fc-btn fc-badge-outline tip-btn" style="flex:1;" onclick="setGlobalTip(0, this)">0%</button>
                                    <button type="button" class="fc-btn fc-badge-outline tip-btn" style="flex:1;" onclick="setGlobalTip(5, this)">5%</button>
                                    <button type="button" class="fc-btn fc-badge-outline tip-btn" style="flex:1;" onclick="setGlobalTip(10, this)">10%</button>
                                    <button type="button" class="fc-btn fc-badge-outline tip-btn" style="flex:1;" onclick="setGlobalTip(15, this)">15%</button>
                                    <div style="flex: 2; position: relative;">
                                        <span style="position: absolute; left: 15px; top: 50%; transform: translateY(-50%); color: var(--fc-text-sec); font-weight: 700;">C$</span>
                                        <input type="number" id="global-tip-input" class="fc-input" value="0.00" step="0.01" oninput="customGlobalTip(this)" style="padding-left: 45px;">
                                    </div>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="fc-tabs" style="margin: 20px; border-radius: 12px; width: auto;">
                            <button class="fc-tab active" onclick="switchTab('single')">Pago Único</button>
                            <button class="fc-tab" onclick="switchTab('mixed')">Pago Mixto</button>
                            <button class="fc-tab" onclick="switchTab('split')">Dividir Cuenta</button>
                            <button class="fc-tab" onclick="switchTab('individual')">Por Consumo</button>
                        </div>

                        <div class="payment-tab-container">

                            <!-- PAGO ÚNICO -->
                            <div id="tab-single" class="tab-pane active">
                                <form method="POST" action="ver_pedido.php?table=<?= $table_id ?>">
                                    <input type="hidden" name="process_payment" value="1">
                                    <input type="hidden" name="tip_amount" class="hidden-tip-amount" value="0">

                                    <div class="payment-total-modern">
                                        <span>Total a Pagar</span>
                                        <strong class="dynamic-payment-total" data-base="<?= $order['total'] ?>">C$<?= number_format($order['total'], 2) ?></strong>
                                    </div>

                                    <div class="payment-methods-horizontal">
                                        <label class="payment-option-horizontal">
                                            <input type="radio" name="payment_method" value="cash" required <?= !$active_register ? 'disabled' : '' ?>>
                                            <div class="payment-card-horizontal">
                                                <span class="payment-icon-horizontal">💵</span>
                                                <span class="payment-label-horizontal">Efectivo</span>
                                            </div>
                                        </label>

                                        <label class="payment-option-horizontal">
                                            <input type="radio" name="payment_method" value="card" required <?= !$active_register ? 'disabled' : '' ?>>
                                            <div class="payment-card-horizontal">
                                                <span class="payment-icon-horizontal">💳</span>
                                                <span class="payment-label-horizontal">Tarjeta</span>
                                            </div>
                                        </label>

                                        <label class="payment-option-horizontal">
                                            <input type="radio" name="payment_method" value="transfer" required
                                                <?= !$active_register ? 'disabled' : '' ?>>
                                            <div class="payment-card-horizontal">
                                                <span class="payment-icon-horizontal">🏦</span>
                                                <span class="payment-label-horizontal">Transf.</span>
                                            </div>
                                        </label>
                                    </div>

                                    <button type="submit" class="fc-btn fc-btn-primary fc-w100" style="padding: 16px; font-size: 1.1em;" <?= !$active_register ? 'disabled' : '' ?>>
                                        <i class='bx bx-check-shield'></i> Cobrar Total
                                    </button>
                                </form>
                            </div>

                            <!-- PAGO MIXTO -->
                            <div id="tab-mixed" class="tab-pane">
                                <form method="POST" action="ver_pedido.php?table=<?= $table_id ?>"
                                    onsubmit="return validateMixedPayment()">
                                    <input type="hidden" name="process_mixed_payment" value="1">
                                    <input type="hidden" name="tip_amount" class="hidden-tip-amount" value="0">

                                    <div class="payment-total-modern">
                                        <span>Total a Pagar</span>
                                        <strong class="dynamic-payment-total" data-base="<?= $order['total'] ?>">C$<?= number_format($order['total'], 2) ?></strong>
                                    </div>

                                    <div id="mixed-payments-container">
                                        <!-- Dynamic rows -->
                                    </div>

                                    <button type="button" class="btn btn-secondary btn-sm" onclick="addMixedPaymentRow()"
                                        style="margin-bottom: 20px; width: 100%;">
                                        + Agregar Método
                                    </button>

                                    <div class="payment-summary">
                                        <div class="summary-row">
                                            <span>Total Ingresado:</span>
                                            <span id="mixed-total-paid">C$ 0.00</span>
                                        </div>
                                        <div class="summary-row remaining">
                                            <span>Restante:</span>
                                            <span id="mixed-remaining">C$ <?= number_format($order['total'], 2) ?></span>
                                        </div>
                                    </div>

                                    <button type="submit" id="btn-mixed-pay" class="btn btn-primary-gradient btn-block-lg"
                                        disabled>
                                        Procesar Pago Mixto
                                    </button>
                                </form>
                            </div>

                            <!-- PAGO POR CONSUMO INDIVIDUAL -->
                            <div id="tab-individual" class="tab-pane">
                                <form method="POST" action="ver_pedido.php?table=<?= $table_id ?>" id="individual-split-form"
                                    onsubmit="return validateIndividualSplit()">
                                    <input type="hidden" name="process_individual_bill" value="1">
                                    <input type="hidden" name="individual_splits_data" id="individual_splits_data">

                                    <div class="payment-total-modern">
                                        <span>Total del Pedido</span>
                                        <strong>C$<?= number_format($order['total'], 2) ?></strong>
                                    </div>

                                    <div class="individual-split-container">
                                        <div class="persons-list" id="persons-list">
                                            <!-- Persons will be added here -->
                                        </div>

                                        <div class="add-person-section">
                                            <button type="button" class="btn btn-secondary btn-block" onclick="addNewPerson()">
                                                ➕ Agregar Persona
                                            </button>
                                        </div>

                                        <div class="split-summary-box">
                                            <div class="summary-row">
                                                <span>Total Asignado:</span>
                                                <span id="individual-total-assigned">C$ 0.00</span>
                                            </div>
                                            <div class="summary-row remaining">
                                                <span>Restante:</span>
                                                <span id="individual-remaining">C$
                                                    <?= number_format($order['total'], 2) ?></span>
                                            </div>
                                        </div>

                                        <button type="submit" class="btn btn-primary-gradient btn-block-lg"
                                            id="btn-individual-submit" disabled>
                                            Generar Facturas
                                        </button>
                                    </div>
                                </form>
                            </div>

                            <!-- DIVIDIR CUENTA -->
                            <div id="tab-split" class="tab-pane">
                                <form method="POST" action="ver_pedido.php?table=<?= $table_id ?>">
                                    <input type="hidden" name="process_split_bill" value="1">

                                    <div class="payment-total-modern">
                                        <span>Total del Pedido</span>
                                        <strong>C$<?= number_format($order['total'], 2) ?></strong>
                                    </div>

                                    <div class="form-group">
                                        <label
                                            style="color: var(--text-secondary); font-size: 16px; display: block; text-align: center; margin-bottom: 15px;">Dividir
                                            entre cuántas personas?</label>
                                        <div class="split-controls">
                                            <button type="button" onclick="adjustSplit(-1)">-</button>
                                            <input type="number" name="split_count" id="split-count" value="2" min="2" max="20"
                                                oninput="updateSplitDisplay()" onblur="validateSplitInput()">
                                            <button type="button" onclick="adjustSplit(1)">+</button>
                                        </div>
                                    </div>
                                                     <div class="fc-split-card" style="text-align: center;">
                                        <p class="fc-label">Cada persona pagará:</p>
                                        <div class="fc-split-amount" id="split-amount-display">
                                            C$ <?= number_format($order['total'] / 2, 2) ?>
                                        </div>
                                    </div>
 
                                    <div class="fc-alert fc-alert-slate" style="font-size: 14px;">
                                        <i class='bx bx-info-circle'></i> Se generarán facturas separadas para cada persona. Podrás procesar el pago de cada una individualmente.
                                    </div>
 
                                    <button type="submit" class="fc-btn fc-btn-primary fc-w100" <?= !$active_register ? 'disabled' : '' ?>>
                                        <i class='bx bx-git-branch'></i> Generar Facturas Divididas
                                    </button>                         </button>
                                </form>
                            </div>

                        </div>

                        <div style="padding: 25px; text-align: center; border-top: 1px solid var(--fc-border);">
                            <a href="venta.php?table=<?= $table_id ?>" class="fc-btn" style="color: var(--fc-text-sec);">
                                <i class='bx bx-cart-add'></i> Agregar más productos
                            </a>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; // End hide payment card for waiters ?>

        <div class="fc-receipt">
            <div class="fc-card-header">
                <h3 style="margin:0;"><i class='bx bx-list-ol'></i> Detalle de Cuenta</h3>
            </div>
            <div class="fc-table-container">
                <table class="fc-table">
                    <thead>
                        <tr>
                            <th>Producto</th>
                            <th style="text-align: right;">Precio</th>
                            <th style="text-align: center;">Cant.</th>
                            <th style="text-align: right;">Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($order_items as $item): ?>
                            <tr>
                                <td><?= htmlspecialchars($item['product_name']) ?></td>
                                <td style="text-align: right; color: var(--fc-text-sec);">C$<?= number_format($item['price'], 2) ?></td>
                                <td style="text-align: center; font-weight: 700;"><?= $item['quantity'] ?></td>
                                <td style="text-align: right; font-weight: 700; color: var(--fc-text-main);">
                                    C$<?= number_format($item['price'] * $item['quantity'], 2) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="fc-total-row">
                            <td colspan="3">TOTAL FINAL</td>
                            <td style="text-align: right;">C$<?= number_format($order['total'], 2) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

        <?php if ($_SESSION['role_id'] == 2): // Show back to tables button for waiters ?>
            <div class="fc-mt-20">
                <a href="mesas.php" class="fc-btn fc-btn-primary fc-w100" style="padding: 16px;">
                    <i class='bx bx-chair'></i> Volver a Mesas
                </a>
            </div>
        <?php endif; ?>
</div>
</main>
</div>

<style>
    /* Premium Payment Interface Styles */
    :root {
        --glass-bg: rgba(255, 255, 255, 0.95);
        --card-shadow: 0 10px 30px -5px rgba(0, 0, 0, 0.05);
        --primary-gradient: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        --success-gradient: linear-gradient(135deg, #10b981 0%, #059669 100%);
    }

    .invoice-layout {
        display: grid;
        grid-template-columns: 1.8fr 1.2fr;
        gap: 30px;
        align-items: start;
        max-width: 1400px;
        margin: 0 auto;
    }

    /* View Only Mode Adjustments */
    .view-only-mode {
        grid-template-columns: 1fr 1fr; /* Balanced view */
    }
    
    .view-only-mode .payment-start-card {
        display: flex;
        align-items: center;
        justify-content: center;
        height: 100%;
    }

    .card {
        background: var(--glass-bg);
        border: 1px solid rgba(255, 255, 255, 0.2);
        box-shadow: var(--card-shadow);
        border-radius: 24px;
        overflow: hidden;
        transition: transform 0.3s ease;
    }

    .card-header-clean {
        padding: 25px 30px;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .card-header-clean h3 {
        margin: 0;
        font-size: 1.25rem;
        font-weight: 700;
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    /* Tabs Redesign */
    .payment-tabs {
        display: flex;
        padding: 10px 30px 0;
        gap: 10px;
        border-bottom: 1px solid rgba(0, 0, 0, 0.05);
        overflow-x: auto;
    }

    .tab-btn {
        padding: 12px 20px;
        background: transparent;
        border: none;
        font-weight: 600;
        color: #64748b;
        cursor: pointer;
        transition: all 0.3s ease;
        border-bottom: 3px solid transparent;
        border-radius: 8px 8px 0 0;
        white-space: nowrap;
    }

    .tab-btn:hover {
        color: var(--primary);
        background: #f8fafc;
    }

    .tab-btn.active {
        color: var(--primary);
        border-bottom-color: var(--primary);
        background: rgba(99, 102, 241, 0.1);
    }

    /* Restored tab-pane styles */
    .tab-pane {
        display: none;
        padding: 10px;
        animation: fadeIn 0.3s ease;
    }

    .tab-pane.active {
        display: block !important;
        opacity: 1 !important;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(5px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .payment-tab-container {
        padding: 30px;
    }

    /* Payment Methods (Radio Cards) */
    .payment-methods-horizontal {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }

    .payment-option-horizontal {
        cursor: pointer;
        position: relative;
        display: block;
    }

    .payment-option-horizontal input[type="radio"] {
        opacity: 0;
        position: absolute;
    }

    .payment-card-horizontal {
        background: #fff;
        border: 2px solid #e2e8f0;
        border-radius: 16px;
        padding: 25px 15px;
        text-align: center;
        transition: all 0.2s ease;
        height: 100%;
    }

    .payment-option-horizontal input:checked+.payment-card-horizontal {
        border-color: var(--primary);
        background: #eff6ff;
        box-shadow: 0 4px 12px rgba(99, 102, 241, 0.15);
        transform: translateY(-2px);
    }

    .payment-icon-horizontal {
        font-size: 2.5rem;
        margin-bottom: 10px;
        display: block;
    }

    .payment-label-horizontal {
        font-weight: 600;
        color: #334155;
        font-size: 0.95rem;
    }

    /* Total Section */
    .payment-total-modern {
        background: #f8fafc;
        border-radius: 20px;
        padding: 25px;
        text-align: center;
        margin-bottom: 25px;
        border: 1px dashed #cbd5e1;
    }

    .payment-total-modern span {
        display: block;
        color: #64748b;
        font-size: 0.9rem;
        text-transform: uppercase;
        letter-spacing: 1px;
        margin-bottom: 5px;
    }

    .payment-total-modern strong {
        font-size: 2.5rem;
        color: #1e293b;
        font-weight: 800;
        background: linear-gradient(90deg, #1e293b, #475569);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }

    /* Receipt Style Table */
    .receipt-card {
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
    }

    .invoice-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
    }

    .invoice-table th {
        background: #f8fafc;
        color: #64748b;
        font-weight: 600;
        text-transform: uppercase;
        font-size: 0.75rem;
        letter-spacing: 0.5px;
        padding: 12px 10px;
        /* Reduced padding */
        border-bottom: 1px solid #e2e8f0;
    }

    .invoice-table td {
        padding: 12px 10px;
        /* Reduced padding */
        border-bottom: 1px solid #f1f5f9;
        color: #334155;
        font-size: 0.95rem;
    }

    .invoice-table tr:last-child td {
        border-bottom: none;
    }

    .total-row {
        background: #f8fafc !important;
    }

    .total-row td {
        color: #1e293b !important;
        font-weight: 800;
        font-size: 1.1rem;
        border-top: 2px solid #e2e8f0;
    }

    /* Action Buttons */
    .btn-block-lg {
        width: 100%;
        padding: 18px;
        font-size: 1.1rem;
        font-weight: 600;
        border-radius: 12px;
        letter-spacing: 0.5px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
        transition: all 0.2s;
    }

    .btn-primary-gradient {
        background: var(--primary-gradient);
        border: none;
        color: white;
    }

    .btn-primary-gradient:hover:not(:disabled) {
        box-shadow: 0 10px 15px -3px rgba(79, 70, 229, 0.3);
        transform: translateY(-1px);
    }

    .btn-primary-gradient:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        background: #cbd5e1;
    }

    @media (max-width: 900px) {
        .invoice-layout {
            grid-template-columns: 1fr;
        }
    }

    /* === MERGED STYLES FROM OLD BLOCK === */
    /* Mixed Payment Rows */
    .mixed-payment-row {
        display: flex;
        gap: 10px;
        margin-bottom: 15px;
    }

    .mixed-payment-row select,
    .mixed-payment-row input {
        padding: 12px;
        border: 1px solid #475569;
        border-radius: 8px;
        background: #1e293b;
        color: white;
        font-size: 15px;
    }

    .mixed-payment-row select {
        flex: 1;
    }

    .mixed-payment-row input {
        width: 140px;
        text-align: right;
    }

    /* Fix visibility for inputs on white background if theme varies */
    .mixed-payment-row select,
    .mixed-payment-row input {
        color: #000 !important;
        background: #fff !important;
        border: 1px solid #ccc !important;
    }

    .btn-remove {
        background: rgba(220, 38, 38, 0.2);
        color: #f87171;
        border: 1px solid #dc2626;
        border-radius: 8px;
        width: 45px;
        cursor: pointer;
        transition: all 0.2s;
        font-size: 20px;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .btn-remove:hover {
        background: #dc2626;
        color: white;
    }

    .payment-summary {
        background: #1e293b;
        padding: 20px;
        border-radius: 12px;
        margin-bottom: 25px;
        border: 1px solid #334155;
    }

    .summary-row {
        display: flex;
        justify-content: space-between;
        margin-bottom: 8px;
        font-size: 15px;
        color: #cbd5e1;
    }

    .summary-row span:last-child {
        font-weight: 600;
        color: white;
    }

    .summary-row.remaining {
        border-top: 1px solid #334155;
        padding-top: 15px;
        margin-top: 10px;
        font-weight: bold;
        font-size: 18px;
        color: var(--primary);
    }

    /* Split Controls */
    .split-controls {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 20px;
        margin: 30px 0;
    }

    .split-controls button {
        width: 50px;
        height: 50px;
        border-radius: 50%;
        border: 2px solid var(--primary);
        background: transparent;
        color: var(--primary);
        font-size: 24px;
        cursor: pointer;
        transition: all 0.2s;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .split-controls button:hover {
        background: var(--primary);
        color: white;
        transform: scale(1.1);
    }

    .split-controls input {
        width: 80px;
        height: 60px;
        text-align: center;
        font-size: 28px;
        line-height: 60px;
        border: 2px solid var(--primary);
        border-radius: 12px;
        font-weight: bold;
        background: var(--bg-secondary);
        color: var(--text-primary);
        padding: 0;
        box-sizing: border-box;
        vertical-align: middle;
    }

    .split-preview {
        text-align: center;
        padding: 25px;
        background: rgba(34, 197, 94, 0.1);
        border-radius: 16px;
        border: 2px dashed #22c55e;
        margin-bottom: 20px;
    }

    .split-preview p {
        color: #86efac;
        margin: 0 0 10px 0;
        font-size: 16px;
    }

    .split-amount {
        font-size: 42px;
        font-weight: 800;
        color: #22c55e;
        margin-top: 5px;
        text-shadow: 0 2px 10px rgba(34, 197, 94, 0.2);
    }

    .alert-info {
        background: rgba(59, 130, 246, 0.1);
        border-left: 4px solid #3b82f6;
        color: #93c5fd;
    }
</style>




<script>
    // Safely encode variables to prevent syntax errors
    let TOTAL_BILL = <?= floatval($order['total']) ?>;
    const ORDER_ITEMS = <?= json_encode($order_items) ?: '[]' ?>;

    // Ensure DOM is fully loaded before running logic
    document.addEventListener('DOMContentLoaded', function () {
        // Default to single payment if no tab is active
        if (!document.querySelector('.tab-pane.active')) {
            switchTab('single');
        }
    });

    function switchTab(tabName) {
        // Hide all tabs
        document.querySelectorAll('.tab-pane').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));

        // Show selected
        const tab = document.getElementById('tab-' + tabName);
        if (tab) {
            tab.classList.add('active');
            // Ensure display block is forced via style if class fails
            tab.style.display = 'block';
        }

        // Hide others explicitly
        document.querySelectorAll('.tab-pane:not(#tab-' + tabName + ')').forEach(el => {
            el.style.display = 'none';
        });

        // Activate button
        const index = ['single', 'mixed', 'split', 'individual'].indexOf(tabName);
        if (index >= 0) {
            document.querySelectorAll('.tab-btn')[index].classList.add('active');
        }

        if (tabName === 'mixed') {
            const container = document.getElementById('mixed-payments-container');
            if (container && container.children.length === 0) {
                addMixedPaymentRow();
            }
        }

        if (tabName === 'individual') {
            const personContainer = document.getElementById('persons-list');
            if (personContainer && personContainer.children.length === 0) {
                addNewPerson(); // Add first person by default
            }
        }
    }

    // === MIXED PAYMENT LOGIC ===
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

        inputs.forEach(input => {
            totalPaid += parseFloat(input.value || 0);
        });

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

    // === INDIVIDUAL SPLIT LOGIC ===
    let persons = [];

    function addNewPerson() {
        const personId = persons.length + 1;
        const person = {
            id: personId,
            items: [] // Array of {id, quantity, price, name}
        };
        persons.push(person);
        renderPersons();
        updateIndividualTotals();
    }

    function renderPersons() {
        const container = document.getElementById('persons-list');
        container.innerHTML = '';

        persons.forEach((person, index) => {
            const personDiv = document.createElement('div');
            personDiv.className = 'person-card';
            personDiv.innerHTML = `
            <div class="person-header">
                <h4>👤 Persona ${index + 1}</h4>
                ${index > 0 ? `<button type="button" class="btn-remove-person" onclick="removePerson(${index})">✕</button>` : ''}
            </div>
            <div class="person-items">
                ${renderAvailableItems(index)}
            </div>
            <div class="person-total">
                Total: C$ ${calculatePersonTotal(person).toFixed(2)}
            </div>
        `;
            container.appendChild(personDiv);
        });
    }

    function renderAvailableItems(personIndex) {
        let html = '';
        ORDER_ITEMS.forEach(item => {
            // Calculate remaining quantity for this item across all OTHER persons
            let usedQty = 0;
            persons.forEach((p, pIdx) => {
                if (pIdx !== personIndex) {
                    const pItem = p.items.find(i => i.id === item.id);
                    if (pItem) usedQty += pItem.quantity;
                }
            });

            const remainingQty = item.quantity - usedQty;
            const currentPersonItem = persons[personIndex].items.find(i => i.id === item.id);
            const currentQty = currentPersonItem ? currentPersonItem.quantity : 0;

            // Show item if it has remaining quantity OR if the current person has selected it
            if (remainingQty > 0 || currentQty > 0) {
                const maxQty = remainingQty + currentQty; // Max is what's available + what I already have

                html += `
                <div class="item-select-row ${currentQty > 0 ? 'selected' : ''}">
                    <div class="item-info">
                        <span class="item-name">${item.product_name}</span>
                        <span class="item-price">C$${parseFloat(item.price).toFixed(2)}</span>
                    </div>
                    <div class="item-controls">
                        <button type="button" onclick="updatePersonItem(${personIndex}, ${item.id}, -1)" ${currentQty <= 0 ? 'disabled' : ''}>-</button>
                        <span class="qty-display">${currentQty}</span>
                        <button type="button" onclick="updatePersonItem(${personIndex}, ${item.id}, 1)" ${currentQty >= maxQty ? 'disabled' : ''}>+</button>
                    </div>
                </div>
            `;
            }
        });
        return html;
    }

    function updatePersonItem(personIndex, itemId, change) {
        const person = persons[personIndex];
        let itemEntry = person.items.find(i => i.id === itemId);
        const originalItem = ORDER_ITEMS.find(i => i.id === itemId);

        if (!itemEntry) {
            itemEntry = {
                id: itemId,
                quantity: 0,
                price: originalItem.price,
                name: originalItem.product_name
            };
            person.items.push(itemEntry);
        }

        itemEntry.quantity += change;

        if (itemEntry.quantity <= 0) {
            person.items = person.items.filter(i => i.id !== itemId);
        }

        renderPersons(); // Re-render everything to update constraints
        updateIndividualTotals();
    }

    function removePerson(index) {
        persons.splice(index, 1);
        renderPersons();
        updateIndividualTotals();
    }

    function calculatePersonTotal(person) {
        return person.items.reduce((sum, item) => sum + (item.quantity * item.price), 0);
    }

    function updateIndividualTotals() {
        let totalAssigned = 0;
        persons.forEach(p => {
            totalAssigned += calculatePersonTotal(p);
        });

        const remaining = TOTAL_BILL - totalAssigned;

        document.getElementById('individual-total-assigned').textContent = 'C$ ' + totalAssigned.toFixed(2);
        document.getElementById('individual-remaining').textContent = 'C$ ' + remaining.toFixed(2);

        const btn = document.getElementById('btn-individual-submit');
        const remainingEl = document.querySelector('#individual-remaining').parentElement;

        // Allow small float diff
        if (Math.abs(remaining) < 0.05) {
            btn.disabled = false;
            remainingEl.style.color = 'var(--success)';
            document.getElementById('individual-remaining').textContent = '✅ Completo';
        } else {
            btn.disabled = true;
            remainingEl.style.color = 'var(--danger)';
        }

        // Update hidden input
        document.getElementById('individual_splits_data').value = JSON.stringify(persons);
    }

    function validateIndividualSplit() {
        let totalAssigned = 0;
        persons.forEach(p => {
            totalAssigned += calculatePersonTotal(p);
        });

        if (Math.abs(TOTAL_BILL - totalAssigned) > 0.05) {
            alert('Debe asignar todos los productos antes de continuar.');
            return false;
        }
        return true;
    }

    // === SPLIT BILL LOGIC ===
    function adjustSplit(delta) {
        const input = document.getElementById('split-count');
        let val = parseInt(input.value) || 2;
        val = val + delta;
        if (val < 2) val = 2;
        if (val > 20) val = 20;
        input.value = val;
        updateSplitDisplay();
    }

    function updateSplitDisplay() {
        const input = document.getElementById('split-count');
        let val = parseInt(input.value);

        // Only update display if we have a valid number
        if (val && val >= 1) {
            const amountPerPerson = TOTAL_BILL / val;
            document.getElementById('split-amount-display').textContent = 'C$ ' + amountPerPerson.toFixed(2);
        }
    }

    function validateSplitInput() {
        const input = document.getElementById('split-count');
        let val = parseInt(input.value) || 2;
        if (val < 2) val = 2;
        if (val > 20) val = 20;
        input.value = val;
        updateSplitDisplay();
    }
    
    // === TIP LOGIC ===
    const BASE_URL_BILL = <?= floatval($order['total']) ?>;
    
    function setGlobalTip(percent, btnElement) {
        document.querySelectorAll('.tip-btn').forEach(b => { 
            b.classList.remove('btn-primary', 'text-white'); 
            b.classList.add('btn-outline-primary'); 
        });
        if(btnElement) {
            btnElement.classList.remove('btn-outline-primary');
            btnElement.classList.add('btn-primary', 'text-white');
        }
        
        const tipAmount = (BASE_URL_BILL * percent) / 100;
        document.getElementById('global-tip-input').value = tipAmount.toFixed(2);
        applyTip(tipAmount);
    }
    
    function customGlobalTip(inputElement) {
        document.querySelectorAll('.tip-btn').forEach(b => { 
            b.classList.remove('btn-primary', 'text-white'); 
            b.classList.add('btn-outline-primary'); 
        });
        const tipAmount = parseFloat(inputElement.value) || 0;
        applyTip(tipAmount);
    }
    
    function applyTip(tipAmount) {
        document.querySelectorAll('.hidden-tip-amount').forEach(i => i.value = tipAmount.toFixed(2));
        
        TOTAL_BILL = BASE_URL_BILL + tipAmount; // update global total bill
        
        document.querySelectorAll('.dynamic-payment-total').forEach(strong => {
            strong.textContent = 'C$ ' + TOTAL_BILL.toFixed(2);
        });
        
        if (typeof calculateMixedTotal === "function") {
            calculateMixedTotal();
        }
        if (typeof updateIndividualTotals === "function" && document.getElementById('tab-individual').classList.contains('active')) {
            updateIndividualTotals();
        }
        if (typeof updateSplitDisplay === "function") {
            updateSplitDisplay();
        }
    }
</script>

<style>
    .individual-split-container {
        display: flex;
        flex-direction: column;
        gap: 20px;
    }

    .person-card {
        background: var(--bg-secondary);
        border: 1px solid var(--border-color);
        border-radius: 12px;
        padding: 15px;
        margin-bottom: 15px;
    }

    .person-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 15px;
        border-bottom: 1px solid var(--border-color);
        padding-bottom: 10px;
    }

    .person-header h4 {
        margin: 0;
        color: var(--text-primary);
    }

    .btn-remove-person {
        background: none;
        border: none;
        color: var(--danger);
        font-size: 18px;
        cursor: pointer;
    }

    .item-select-row {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 10px;
        border-bottom: 1px solid var(--border-color);
        background: var(--bg-primary);
        margin-bottom: 5px;
        border-radius: 6px;
    }

    .item-select-row.selected {
        border-left: 3px solid var(--primary);
        background: rgba(99, 102, 241, 0.05);
    }

    .item-info {
        display: flex;
        flex-direction: column;
    }

    .item-name {
        font-weight: 500;
        color: var(--text-primary);
    }

    .item-price {
        font-size: 12px;
        color: var(--text-secondary);
    }

    .item-controls {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .item-controls button {
        width: 30px;
        height: 30px;
        border-radius: 50%;
        border: 1px solid var(--border-color);
        background: var(--bg-secondary);
        color: var(--text-primary);
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .item-controls button:disabled {
        opacity: 0.3;
        cursor: not-allowed;
    }

    .item-controls button:hover:not(:disabled) {
        background: var(--primary);
        color: white;
        border-color: var(--primary);
    }

    .qty-display {
        font-weight: bold;
        width: 20px;
        text-align: center;
    }

    .person-total {
        text-align: right;
        font-weight: bold;
        font-size: 16px;
        color: var(--success);
        margin-top: 15px;
        padding-top: 10px;
        border-top: 1px dashed var(--border-color);
    }

    .split-summary-box {
        background: var(--bg-secondary);
        padding: 20px;
        border-radius: 12px;
        margin-top: 20px;
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>