<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Check module access (will redirect if not authorized)
checkModuleAccess($pdo, $_SESSION['role_id'], 'cashier_panel');

$success_msg = '';
$error_msg = '';

// Handle cash register open
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['open_register'])) {
    $initial_amount = $_POST['initial_amount'];

    $stmt = $pdo->prepare('INSERT INTO cash_register (user_id, amount, type, status) VALUES (?, ?, "open", "active")');
    $stmt->execute([$_SESSION['user_id'], $initial_amount]);
    $success_msg = 'Caja abierta exitosamente';
}

// Handle cash register close
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['close_register'])) {
    $register_id = $_POST['register_id'];
    $final_amount = $_POST['final_amount'];

    // Check for occupied tables
    $stmt = $pdo->query("SELECT COUNT(*) FROM tables WHERE status = 'occupied'");
    if ($stmt->fetchColumn() > 0) {
        $error_msg = 'No se puede cerrar la caja: Hay mesas ocupadas que deben ser pagadas primero.';
    } else {
        try {
            $pdo->beginTransaction();

            // Close the register
            $stmt = $pdo->prepare('UPDATE cash_register SET status = "closed" WHERE id = ?');
            $stmt->execute([$register_id]);

            // Insert closing record
            $stmt = $pdo->prepare('INSERT INTO cash_register (user_id, amount, type, status) VALUES (?, ?, "close", "closed")');
            $stmt->execute([$_SESSION['user_id'], $final_amount]);

            $pdo->commit();
            $success_msg = 'Caja cerrada exitosamente';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = 'Error al cerrar caja: ' . $e->getMessage();
        }
    }
}

// Handle payment processing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    $order_id = $_POST['order_id'];
    $payment_method = $_POST['payment_method'];
    $amount = $_POST['amount'];

    // Get active cash register (system-wide)
    $stmt = $pdo->query('SELECT id FROM cash_register WHERE status = "active" ORDER BY id DESC LIMIT 1');
    $active_register = $stmt->fetch();

    if (!$active_register) {
        $error_msg = 'Debe abrir la caja antes de procesar pagos';
    } else {
        try {
            // Auto-fix schema (Self-healing)
            try {
                $pdo->query("SELECT subtotal FROM invoices LIMIT 1");
            } catch (Exception $e) {
                $pdo->exec("ALTER TABLE invoices ADD COLUMN subtotal DECIMAL(10,2) AFTER table_name");
                $pdo->exec("ALTER TABLE invoices ADD COLUMN iva_amount DECIMAL(10,2) AFTER subtotal");
                $pdo->exec("ALTER TABLE invoices ADD COLUMN iva_percentage DECIMAL(5,2) DEFAULT 0 AFTER iva_amount");
            }

            $pdo->beginTransaction();

            // Get VAT percentage
            $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = 'iva_percentage'");
            $stmt->execute();
            $iva_percentage = $stmt->fetchColumn() ?: 0;

            // Calculate totals (Amount passed from form is usually just subtotal if not updated, 
            // but let's assume the amount passed is the subtotal and we add tax on top, 
            // OR we recalculate from order total to be safe)

            // Fetch order total to be sure
            $stmt = $pdo->prepare("SELECT total FROM orders WHERE id = ?");
            $stmt->execute([$order_id]);
            $subtotal = $stmt->fetchColumn();

            $iva_amount = $subtotal * ($iva_percentage / 100);
            $total_with_iva = $subtotal + $iva_amount;

            // Insert payment
            $stmt = $pdo->prepare('INSERT INTO payments (order_id, method, amount, cash_register_id, date_created) VALUES (?, ?, ?, ?, NOW())');
            $stmt->execute([$order_id, $payment_method, $total_with_iva, $active_register['id']]);

            // Update order status to completed
            $stmt = $pdo->prepare('UPDATE orders SET status = "completed", total = ? WHERE id = ?');
            $stmt->execute([$total_with_iva, $order_id]);

            // Update table status to available
            $stmt = $pdo->prepare('UPDATE tables t JOIN orders o ON t.id = o.table_id SET t.status = "available" WHERE o.id = ?');
            $stmt->execute([$order_id]);

            // Get table name for invoice
            $stmt = $pdo->prepare("SELECT t.name FROM tables t JOIN orders o ON t.id = o.table_id WHERE o.id = ?");
            $stmt->execute([$order_id]);
            $table_name = $stmt->fetchColumn();

            // Create Invoice Record
            $stmt = $pdo->prepare('INSERT INTO invoices (order_id, table_name, subtotal, iva_amount, iva_percentage, total, payment_method) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$order_id, $table_name, $subtotal, $iva_amount, $iva_percentage, $total_with_iva, $payment_method]);

            $pdo->commit();
            $success_msg = 'Pago procesado exitosamente';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = 'Error al procesar el pago: ' . $e->getMessage();
        }
    }
}

// Check if there's an active cash register (system-wide, not per user)
$stmt = $pdo->query('SELECT cr.*, u.name as cashier_name FROM cash_register cr JOIN users u ON cr.user_id = u.id WHERE cr.status = "active" ORDER BY cr.id DESC LIMIT 1');
$active_register = $stmt->fetch();

// Get pending orders (not yet paid)
$pending_orders = $pdo->query("
    SELECT o.*, t.name as table_name, u.name as waiter_name,
           (SELECT COUNT(*) FROM payments WHERE order_id = o.id) as has_payment
    FROM orders o
    JOIN tables t ON o.table_id = t.id
    JOIN users u ON o.user_id = u.id
    WHERE (o.status = 'pending' OR o.status = 'preparing' OR o.status = 'ready') OR (o.status = 'completed' AND NOT EXISTS (SELECT 1 FROM payments WHERE order_id = o.id))
    ORDER BY o.date_created DESC
")->fetchAll();

// Get filter dates (default: today for both)
$filter_start = $_GET['filter_start'] ?? date('Y-m-d');
$filter_end = $_GET['filter_end'] ?? date('Y-m-d');
$filter_method = $_GET['filter_method'] ?? '';

// Build date condition for payments (date range)
$date_condition = "DATE(p.date_created) BETWEEN ? AND ?";
$params = [$filter_start, $filter_end];

// Add method filter if specified
$method_condition = "";
if (!empty($filter_method)) {
    $method_condition = " AND p.method = ?";
    $params[] = $filter_method;
}

$recent_payments = $pdo->prepare("
    SELECT p.*, o.id as order_id, t.name as table_name, o.total
    FROM payments p
    JOIN orders o ON p.order_id = o.id
    JOIN tables t ON o.table_id = t.id
    WHERE $date_condition $method_condition
    ORDER BY p.date_created DESC
");
$recent_payments->execute($params);
$recent_payments = $recent_payments->fetchAll();

// Calculate totals for filtered date range
$stats_params = [$filter_start, $filter_end];
$today_stats = $pdo->prepare("
    SELECT 
        COUNT(*) as total_payments,
        SUM(amount) as total_amount,
        SUM(CASE WHEN method = 'cash' THEN amount ELSE 0 END) as cash_total,
        SUM(CASE WHEN method = 'card' THEN amount ELSE 0 END) as card_total,
        SUM(CASE WHEN method = 'transfer' THEN amount ELSE 0 END) as transfer_total
    FROM payments p
    WHERE DATE(p.date_created) BETWEEN ? AND ?
");
$today_stats->execute($stats_params);
$today_stats = $today_stats->fetch();

// Get user's role name
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';

// Fetch company settings
$company_name = 'Sistema Pizzería';
$company_logo = '';

try {
    $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name', 'company_logo')");
    $stmt->execute();
    $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    if (!empty($settings['company_name'])) {
        $company_name = $settings['company_name'];
    }
    if (!empty($settings['company_logo'])) {
        $company_logo = $settings['company_logo'];
    }
} catch (Exception $e) {
}
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>


    <main class="main-content">
        <div class="page-header">
            <div>
                <h1>Historial de Caja</h1>
                <p>Gestión de cobros y facturación</p>
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

        <?php if ($success_msg): ?>
            <div class="fc-alert fc-alert-rose">
                <i class='bx bx-check-circle'></i> <?= $success_msg ?>
            </div>
        <?php endif; ?>
 
        <?php if ($error_msg): ?>
            <div class="fc-alert fc-alert-slate">
                <i class='bx bx-x-circle'></i> <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <!-- Cash Register Status -->
        <?php if ($active_register): ?>
            <div class="fc-card" style="border-left: 5px solid var(--fc-primary); margin-bottom: 35px; background: rgba(225, 29, 72, 0.05);">
                <div style="padding: 30px;">
                    <div class="fc-flex-between" style="align-items: flex-start;">
                        <div>
                            <h3 style="margin: 0 0 10px 0; color: var(--fc-primary); display: flex; align-items: center; gap: 10px;">
                                <i class='bx bxs-lock-open'></i> Caja Activa
                            </h3>
                            <p style="margin: 0 0 20px 0; color: var(--fc-text-sec); font-size: 14px; font-weight: 600; text-transform: uppercase; letter-spacing: 1px;">
                                Cajero: <?= htmlspecialchars($active_register['cashier_name']) ?>
                            </p>
                        </div>
                        <div class="fc-badge fc-badge-rose">● Sesión Activa</div>
                    </div>
                    
                    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 30px; margin-top: 20px;">
                        <div>
                            <div style="color: var(--fc-text-sec); font-size: 13px; font-weight: 600; text-transform: uppercase;">Monto Inicial</div>
                            <div style="font-size: 2em; font-weight: 800; color: var(--fc-text-main); margin-top: 5px;">
                                C$<?= number_format($active_register['amount'], 2) ?>
                            </div>
                        </div>
                        <div>
                            <div style="color: var(--fc-text-sec); font-size: 13px; font-weight: 600; text-transform: uppercase;">Ventas Registradas</div>
                            <div style="font-size: 2em; font-weight: 800; color: var(--fc-primary); margin-top: 5px;">
                                C$<?= number_format($today_stats['total_amount'] ?? 0, 2) ?>
                            </div>
                        </div>
                        <?php if ($active_register['user_id'] == $_SESSION['user_id'] || (isset($_SESSION['is_super_admin']) && $_SESSION['is_super_admin'] == 1) || $_SESSION['role_id'] == 3): ?>
                            <div style="display: flex; align-items: flex-end;">
                                <button class="fc-btn fc-btn-primary fc-w100" onclick="openCloseRegisterModal()" style="height: 55px; font-size: 1.1em;">
                                    <i class='bx bx-lock-alt'></i> Cerrar Caja
                                </button>
                            </div>
                        <?php else: ?>
                            <div style="display: flex; align-items: flex-end;">
                                <div class="fc-badge fc-badge-outline" style="padding: 15px; width: 100%; text-align: center; border-style: dashed; opacity: 0.6;">
                                    🔒 Solo <?= htmlspecialchars($active_register['cashier_name']) ?> puede cerrar
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <div class="fc-card" style="border-left: 5px solid var(--fc-text-sec); margin-bottom: 35px; background: rgba(255,255,255,0.02);">
                <div style="padding: 40px; text-align: center;">
                    <div style="width: 80px; height: 80px; background: rgba(255,255,255,0.05); border-radius: 50%; display: flex; align-items: center; justify-content: center; margin: 0 auto 20px; color: var(--fc-text-sec);">
                        <i class='bx bxs-lock' style="font-size: 40px;"></i>
                    </div>
                    <h3 style="margin: 0 0 10px 0; color: var(--fc-text-main); font-size: 1.5em;">Caja Cerrada</h3>
                    <p style="margin: 0 0 25px 0; color: var(--fc-text-sec);">Debe abrir la caja para poder procesar pagos y facturación.</p>
                    <button class="fc-btn fc-btn-primary" onclick="openRegisterModal()" style="padding: 15px 40px;">
                        <i class='bx bx-plus-circle'></i> Abrir Nueva Caja
                    </button>
                </div>
            </div>
        <?php endif; ?>

        <!-- Today's Stats -->
        <div class="kpi-grid">
            <div class="fc-card kpi-card">
                <div style="display: flex; gap: 15px; align-items: center;">
                    <div style="font-size: 28px; color: var(--fc-primary); background: rgba(225,29,72,0.1); width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                        <i class='bx bx-wallet'></i>
                    </div>
                    <div>
                        <div style="color: var(--fc-text-sec); font-size: 12px; font-weight: 600; text-transform: uppercase;">Total del Día</div>
                        <div style="font-size: 1.5em; font-weight: 800; color: var(--fc-text-main);">C$<?= number_format($today_stats['total_amount'] ?? 0, 2) ?></div>
                    </div>
                </div>
            </div>
            <div class="fc-card kpi-card">
                <div style="display: flex; gap: 15px; align-items: center;">
                    <div style="font-size: 28px; color: var(--fc-text-sec); background: rgba(255,255,255,0.05); width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                        <i class='bx bx-money'></i>
                    </div>
                    <div>
                        <div style="color: var(--fc-text-sec); font-size: 12px; font-weight: 600; text-transform: uppercase;">Efectivo</div>
                        <div style="font-size: 1.5em; font-weight: 800; color: var(--fc-text-main);">C$<?= number_format($today_stats['cash_total'] ?? 0, 2) ?></div>
                    </div>
                </div>
            </div>
            <div class="fc-card kpi-card">
                <div style="display: flex; gap: 15px; align-items: center;">
                    <div style="font-size: 28px; color: var(--fc-text-sec); background: rgba(255,255,255,0.05); width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                        <i class='bx bx-credit-card'></i>
                    </div>
                    <div>
                        <div style="color: var(--fc-text-sec); font-size: 12px; font-weight: 600; text-transform: uppercase;">Tarjeta</div>
                        <div style="font-size: 1.5em; font-weight: 800; color: var(--fc-text-main);">C$<?= number_format($today_stats['card_total'] ?? 0, 2) ?></div>
                    </div>
                </div>
            </div>
            <div class="fc-card kpi-card">
                <div style="display: flex; gap: 15px; align-items: center;">
                    <div style="font-size: 28px; color: var(--fc-text-sec); background: rgba(255,255,255,0.05); width: 50px; height: 50px; border-radius: 12px; display: flex; align-items: center; justify-content: center;">
                        <i class='bx bx-transfer'></i>
                    </div>
                    <div>
                        <div style="color: var(--fc-text-sec); font-size: 12px; font-weight: 600; text-transform: uppercase;">Transferencia</div>
                        <div style="font-size: 1.5em; font-weight: 800; color: var(--fc-text-main);">C$<?= number_format($today_stats['transfer_total'] ?? 0, 2) ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Pending Orders -->
        <div class="fc-card">
            <div class="fc-card-header">
                <h3 style="margin:0;"><i class='bx bx-time-five'></i> Pedidos Pendientes de Pago</h3>
            </div>
            <div class="fc-table-container">
                <table class="fc-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Mesa</th>
                            <th>Mesero</th>
                            <th>Total</th>
                            <th>Fecha</th>
                            <th>Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($pending_orders)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 60px;">
                                    <div style="opacity: 0.3; font-size: 40px; margin-bottom: 10px;"><i class='bx bx-check-double'></i></div>
                                    <p style="color: var(--fc-text-sec);">No hay pedidos pendientes de pago</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($pending_orders as $order): ?>
                                <tr>
                                    <td>#<?= $order['id'] ?></td>
                                    <td><strong><?= htmlspecialchars($order['table_name']) ?></strong></td>
                                    <td style="color: var(--fc-text-sec);"><?= htmlspecialchars($order['waiter_name']) ?></td>
                                    <td><strong style="color: var(--fc-primary);">C$<?= number_format($order['total'], 2) ?></strong></td>
                                    <td style="color: var(--fc-text-sec);"><?= date('d/m/Y H:i', strtotime($order['date_created'])) ?></td>
                                    <td>
                                        <?php if ($active_register): ?>
                                            <button class="fc-btn fc-btn-primary" style="padding: 8px 15px; font-size: 0.9em;"
                                                onclick="openPaymentModal(<?= htmlspecialchars(json_encode($order)) ?>)">
                                                <i class='bx bx-money'></i> Cobrar
                                            </button>
                                        <?php else: ?>
                                            <span class="fc-badge fc-badge-outline" style="opacity: 0.5;">Caja cerrada</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Payment History with Filters -->
        <div class="fc-card">
            <div class="fc-card-header" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px;">
                <h3 style="margin:0;"><i class='bx bx-history'></i> Historial de Pagos</h3>
                <form method="GET" class="fc-flex-between" style="gap: 15px; flex-wrap: wrap; width: auto;">
                    <div class="fc-input-group" style="width: auto;">
                        <input type="date" name="filter_start" class="fc-input" style="height: 40px; padding: 0 12px;" value="<?= htmlspecialchars($filter_start) ?>">
                    </div>
                    <div class="fc-input-group" style="width: auto;">
                        <input type="date" name="filter_end" class="fc-input" style="height: 40px; padding: 0 12px;" value="<?= htmlspecialchars($filter_end) ?>">
                    </div>
                    <div class="fc-input-group" style="width: auto;">
                        <select name="filter_method" class="fc-input" style="height: 40px; padding: 0 12px;">
                            <option value="">Todos</option>
                            <option value="cash" <?= $filter_method == 'cash' ? 'selected' : '' ?>>Efectivo</option>
                            <option value="card" <?= $filter_method == 'card' ? 'selected' : '' ?>>Tarjeta</option>
                            <option value="transfer" <?= $filter_method == 'transfer' ? 'selected' : '' ?>>Transferencia</option>
                        </select>
                    </div>
                    <div style="display: flex; gap: 8px;">
                        <button type="submit" class="fc-btn fc-btn-primary" style="height: 40px; padding: 0 20px;">
                            <i class='bx bx-filter-alt'></i>
                        </button>
                        <?php if ($filter_start != date('Y-m-d') || $filter_end != date('Y-m-d') || !empty($filter_method)): ?>
                            <a href="panel_cajero.php" class="fc-btn fc-btn-outline" style="height: 40px; width: 40px; display: flex; align-items: center; justify-content: center; padding:0;">
                                <i class='bx bx-x'></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
            <div class="fc-table-container">
                <table class="fc-table">
                    <thead>
                        <tr>
                            <th>Fecha/Hora</th>
                            <th>Pedido</th>
                            <th>Mesa</th>
                            <th>Método</th>
                            <th>Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($recent_payments)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; padding: 60px;">
                                    <p style="color: var(--fc-text-sec);">No hay pagos en este rango</p>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($recent_payments as $payment): ?>
                                <tr>
                                    <td style="color: var(--fc-text-sec);"><?= date('d/m/Y H:i', strtotime($payment['date_created'])) ?></td>
                                    <td><strong>#<?= $payment['order_id'] ?></strong></td>
                                    <td><?= htmlspecialchars($payment['table_name']) ?></td>
                                    <td>
                                        <span class="fc-badge fc-badge-outline">
                                            <i class='bx <?php 
                                                echo $payment['method'] == 'cash' ? 'bx-money' : 
                                                    ($payment['method'] == 'card' ? 'bx-credit-card' : 'bx-transfer'); 
                                            ?>'></i>
                                            <?= $payment['method'] == 'cash' ? 'Efectivo' : 
                                                ($payment['method'] == 'card' ? 'Tarjeta' : 'Transferencia') ?>
                                        </span>
                                    </td>
                                    <td><strong style="color: var(--fc-primary);">C$<?= number_format($payment['amount'], 2) ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Payment Modal -->
<div id="paymentModal" class="fc-modal-overlay">
    <div class="fc-modal" style="max-width: 450px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-money'></i> Procesar Pago</h3>
            <button type="button" class="fc-modal-close" onclick="closePaymentModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="process_payment" value="1">
            <input type="hidden" name="order_id" id="payment_order_id">
            <input type="hidden" name="amount" id="payment_amount">

            <div class="fc-modal-body">
                <div style="background: rgba(255,255,255,0.03); padding: 20px; border-radius: 12px; margin-bottom: 25px; border: 1px solid var(--fc-border);">
                    <div style="color: var(--fc-text-sec); font-size: 13px; text-transform: uppercase; font-weight: 600;">Mesa Seleccionada</div>
                    <div id="payment_table" style="font-size: 1.2em; font-weight: 700; margin-bottom: 15px;"></div>
                    
                    <div style="color: var(--fc-text-sec); font-size: 13px; text-transform: uppercase; font-weight: 600;">Total a Cobrar</div>
                    <div id="payment_total" style="font-size: 2.2em; font-weight: 800; color: var(--fc-primary);"></div>
                </div>

                <div class="fc-input-group">
                    <label class="fc-label">Método de Pago</label>
                    <select name="payment_method" class="fc-input" required>
                        <option value="cash">Efectivo</option>
                        <option value="card">Tarjeta de Crédito/Débito</option>
                        <option value="transfer">Transferencia Bancaria</option>
                    </select>
                </div>
            </div>
            <div class="fc-modal-footer">
                <button type="button" class="fc-btn fc-btn-outline" onclick="closePaymentModal()">Cancelar</button>
                <button type="submit" class="fc-btn fc-btn-primary">Confirmar Pago</button>
            </div>
        </form>
    </div>
</div>

<!-- Open Register Modal -->
<div id="openRegisterModal" class="fc-modal-overlay">
    <div class="fc-modal" style="max-width: 400px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-plus-circle'></i> Abrir Caja</h3>
            <button type="button" class="fc-modal-close" onclick="closeRegisterModal()">&times;</button>
        </div>
        <form method="POST">
            <input type="hidden" name="open_register" value="1">
            <div class="fc-modal-body">
                <div class="fc-input-group">
                    <label class="fc-label">Monto Inicial en Efectivo</label>
                    <input type="number" step="0.01" name="initial_amount" class="fc-input" placeholder="0.00" required>
                    <div style="font-size: 12px; color: var(--fc-text-sec); margin-top: 8px;">
                        Ingrese el capital disponible para dar cambio.
                    </div>
                </div>
            </div>
            <div class="fc-modal-footer">
                <button type="button" class="fc-btn fc-btn-outline" onclick="closeRegisterModal()">Cancelar</button>
                <button type="submit" class="fc-btn fc-btn-primary">Abrir Caja</button>
            </div>
        </form>
    </div>
</div>

<!-- Close Register Modal -->
<div id="closeRegisterModal" class="fc-modal-overlay">
    <div class="fc-modal" style="max-width: 400px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-lock-alt'></i> Cerrar Caja</h3>
            <button type="button" class="fc-modal-close" onclick="closeRegisterModal()">&times;</button>
        </div>
        <form method="POST" onsubmit="showCloseRegisterModal(event); return false;">
            <input type="hidden" name="close_register" value="1">
            <input type="hidden" name="register_id" value="<?= $active_register['id'] ?? '' ?>">

            <div class="fc-modal-body">
                <div class="fc-input-group">
                    <label class="fc-label">Monto Final en Caja (Arqueo)</label>
                    <input type="number" step="0.01" name="final_amount" class="fc-input"
                        value="<?= ($active_register['amount'] ?? 0) + ($today_stats['total_amount'] ?? 0) ?>" required>
                    <div style="font-size: 12px; color: var(--fc-text-sec); margin-top: 8px;">
                        Verifique el total acumulado de ventas + monto inicial.
                    </div>
                </div>
            </div>
            <div class="fc-modal-footer">
                <button type="button" class="fc-btn fc-btn-outline" onclick="closeRegisterModal()">Cancelar</button>
                <button type="submit" class="fc-btn fc-btn-primary">Proceder al Cierre</button>
            </div>
        </form>
    </div>
</div>

<style>
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .kpi-card {
        padding: 25px !important;
        transition: transform 0.3s ease;
    }
    
    .kpi-card:hover {
        transform: translateY(-5px);
    }
</style>

<script>
    function openPaymentModal(order) {
        document.getElementById('payment_order_id').value = order.id;
        document.getElementById('payment_amount').value = order.total;
        document.getElementById('payment_table').textContent = order.table_name;
        document.getElementById('payment_total').textContent = 'C$' + parseFloat(order.total).toFixed(2);
        document.getElementById('paymentModal').style.display = 'flex';
    }

    function closePaymentModal() {
        document.getElementById('paymentModal').style.display = 'none';
    }

    function openRegisterModal() {
        document.getElementById('openRegisterModal').style.display = 'flex';
    }

    function openCloseRegisterModal() {
        document.getElementById('closeRegisterModal').style.display = 'flex';
    }

    function closeRegisterModal() {
        document.getElementById('openRegisterModal').style.display = 'none';
        document.getElementById('closeRegisterModal').style.display = 'none';
    }

    // Close modal when clicking outside
    window.onclick = function (event) {
        const paymentModal = document.getElementById('paymentModal');
        const openModal = document.getElementById('openRegisterModal');
        const closeModal = document.getElementById('closeRegisterModal');

        if (event.target == paymentModal) {
            closePaymentModal();
        }
        if (event.target == openModal || event.target == closeModal) {
            closeRegisterModal();
        }
    }
</script>

<!-- Final Confirmation Modal -->
<div class="modern-modal-overlay" id="confirmModal">
    <div class="modern-modal">
        <div class="modal-icon">
            <span class='bx bx-power-off'></span>
        </div>
        <h3 class="modal-title">¿Cerrar Caja?</h3>
        <p class="modal-message">Esta acción finalizará su turno operativo y registrará el arqueo final.</p>
        
        <div class="modal-buttons">
            <button type="button" class="modal-btn modal-btn-cancel" onclick="closeConfirmModal()">Cancelar</button>
            <button type="button" class="modal-btn modal-btn-confirm" id="confirmModalBtn">Sí, cerrar turno</button>
        </div>
    </div>
</div>

<script>
    let formToSubmit = null;

    function showCloseRegisterModal(event) {
        event.preventDefault();
        formToSubmit = event.target;
        const modal = document.getElementById('confirmModal');
        modal.classList.add('show');
    }

    function closeConfirmModal() {
        const modal = document.getElementById('confirmModal');
        modal.classList.remove('show');
        formToSubmit = null;
    }

    document.getElementById('confirmModalBtn').addEventListener('click', function () {
        if (formToSubmit) {
            formToSubmit.submit();
        }
    });

    document.getElementById('confirmModal').addEventListener('click', function (e) {
        if (e.target === this) {
            closeConfirmModal();
        }
    });
</script>

<style>
    .modern-modal-overlay {
        position: fixed;
        top: 0;
        left: 0;
        right: 0;
        bottom: 0;
        background: rgba(0, 0, 0, 0.6);
        backdrop-filter: blur(8px);
        -webkit-backdrop-filter: blur(8px);
        display: none;
        align-items: center;
        justify-content: center;
        z-index: 10000;
        opacity: 0;
        transition: opacity 0.3s ease;
    }

    .modern-modal-overlay.show {
        display: flex;
        opacity: 1;
    }

    .modern-modal {
        background: white;
        border-radius: 24px;
        padding: 30px;
        max-width: 340px;
        width: 90%;
        text-align: center;
        box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
        transform: scale(0.8) translateY(20px);
        transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }

    .modern-modal-overlay.show .modern-modal {
        transform: scale(1) translateY(0);
    }

    .modal-icon {
        width: 70px;
        height: 70px;
        background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin: 0 auto 20px;
        color: #ef4444;
    }

    .modal-icon span {
        font-size: 32px;
    }

    .modal-title {
        margin: 0 0 10px 0;
        font-size: 20px;
        font-weight: 700;
        color: #1e293b;
    }

    .modal-message {
        margin: 0 0 25px 0;
        font-size: 14px;
        color: #64748b;
        line-height: 1.5;
    }

    .modal-buttons {
        display: flex;
        gap: 12px;
    }

    .modal-btn {
        flex: 1;
        padding: 14px 20px;
        border-radius: 14px;
        font-size: 15px;
        font-weight: 600;
        cursor: pointer;
        transition: all 0.2s ease;
        border: none;
    }

    .modal-btn-cancel {
        background: #f1f5f9;
        color: #475569;
    }

    .modal-btn-cancel:hover {
        background: #e2e8f0;
    }

    .modal-btn-confirm {
        background: linear-gradient(135deg, #ef4444 0%, #dc2626 100%);
        color: white;
        box-shadow: 0 4px 15px rgba(239, 68, 68, 0.35);
    }

    .modal-btn-confirm:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(239, 68, 68, 0.45);
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>