<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();
$_SESSION['user_id'] = 1;
$_SESSION['role_id'] = 1;

// Check module access
checkModuleAccess($pdo, $_SESSION['role_id'], 'cuentas');

// Handle AJAX request for order details
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_order_details') {
    $order_id = $_GET['order_id'] ?? 0;

    $stmt = $pdo->prepare('
        SELECT od.quantity, od.price, p.name 
        FROM order_details od
        JOIN products p ON od.product_id = p.id
        WHERE od.order_id = ?
    ');
    $stmt->execute([$order_id]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare('SELECT total FROM orders WHERE id = ?');
    $stmt->execute([$order_id]);
    $total = $stmt->fetchColumn();

    header('Content-Type: application/json');
    echo json_encode(['items' => $items, 'total' => $total]);
    exit();
}

// Get user's role name
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';

// Fetch tables with their active order status
// Similar logic to mesas.php but we might want to focus on non-empty tables? 
// For now, listing all gives a complete picture, or we could filter properties.
// Let's list ALL tables so they can see which are available vs occupied.
$stmt = $pdo->query('
    SELECT t.*, 
           o.id as order_id, 
           o.total as order_total, 
           o.status as order_status,
           o.payment_requested,
           o.payment_method,
           o.payment_details,
           u.name as waiter_name
    FROM tables t
    LEFT JOIN orders o ON t.id = o.table_id 
        AND o.status IN ("draft", "pending", "preparing", "ready", "picked_up", "delivered")
    LEFT JOIN users u ON o.user_id = u.id
    ORDER BY t.id ASC
');
$tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch active PedidosYa orders
$stmt = $pdo->query('
    SELECT * FROM pedidosya_orders 
    WHERE status IN ("pending", "preparing", "ready")
    ORDER BY date_created DESC
');
$pedidosya = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Merge into a single "Accounts" list with types
$accounts = [];

// 1. Process Tables (Mesas & Barra)
foreach ($tables as $t) {
    $type = 'mesa';
    if (strtolower($t['name']) === 'barra' || strtolower($t['name']) === 'pedido libre') {
        $type = 'libre';
    }

    $accounts[] = [
        'type' => $type,
        'id' => $t['id'], // Table ID
        'name' => $t['name'],
        'order_id' => $t['order_id'],
        'total' => $t['order_total'],
        'status' => $t['order_status'],
        'payment_requested' => $t['payment_requested'],
        'payment_method' => $t['payment_method'],
        'payment_details' => $t['payment_details'],
        'waiter' => $t['waiter_name'],
        'link' => "ver_pedido.php?table={$t['id']}&view=payment"
    ];
}

// 2. Process PedidosYa
foreach ($pedidosya as $py) {
    $accounts[] = [
        'type' => 'pedidosya',
        'id' => $py['id'], // Order ID
        'name' => 'PedidosYa: ' . $py['external_order_id'],
        'order_id' => $py['id'], // Use ID as order ID
        'total' => $py['total'],
        'status' => $py['status'] === 'ready' ? 'ready' : 'pending',
        'payment_requested' => 0,
        'waiter' => 'PedidosYa',
        'link' => "factura_pedidosya.php?id={$py['id']}" // Link directly to invoice/details
    ];
}

$pageTitle = "Cuentas";
include __DIR__ . '/includes/header.php';
?>

<div class="dashboard-wrapper">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="page-header">
            <div>
                <h1>Gestión de Cuentas</h1>
                <p>Seleccione una mesa para procesar su cobro</p>
            </div>
            <div class="user-profile-header">
                <div class="user-avatar">
                    <?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?>
                </div>
                <div class="user-details">
                    <span class="user-name">
                        <?= htmlspecialchars($_SESSION['name'] ?? 'Usuario') ?>
                    </span>
                    <span class="user-role">
                        <?= htmlspecialchars($user_role_name) ?>
                    </span>
                </div>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            Swal.fire({
                title: '¡Cobro Exitoso!',
                html: '<?= addslashes($_SESSION['success_message']) ?>',
                icon: 'success',
                iconColor: '#10b981',
                confirmButtonColor: '#8b5cf6',
                confirmButtonText: 'Entendido',
                customClass: {
                    popup: 'fc-modal',
                    confirmButton: 'fc-btn fc-btn-primary'
                },
                backdrop: 'rgba(15, 23, 42, 0.8)'
            });
        });
        </script>
        <?php unset($_SESSION['success_message']); endif; ?>

        <!-- Accounts List (Compact) -->
        <div class="tables-list">
            <?php foreach ($accounts as $account):
                $status = $account['status'] ?? null;
                $hasOrder = isset($account['has_order']) ? $account['has_order'] : (!empty($account['order_id']));

                // Card Styling Logic
                $cardClass = 'available';
                $statusLabel = 'Disponible';
                $icon = 'bx-store-alt';

                if ($hasOrder) {
                    $cardClass = 'occupied';
                    $statusLabel = 'Ocupada';
                    $icon = 'bx-restaurant';

                    if ($account['type'] === 'libre') {
                        $cardClass = 'libre';
                        $statusLabel = 'Pedido Libre';
                        $icon = 'bx-drink';
                    } elseif ($account['type'] === 'pedidosya') {
                        $cardClass = 'pedidosya';
                        $statusLabel = 'Delivery';
                        $icon = 'bx-cycling';
                    } else {
                        if ($status === 'delivered') {
                            $cardClass = 'ready';
                            $statusLabel = 'Servido (Por Cobrar)';
                            $icon = 'bx-dish';
                        } elseif ($status === 'draft') {
                            $statusLabel = 'Tomando pedido...';
                            $icon = 'bx-edit';
                        }
                    }
                }
                ?>
                <div class="table-row table-<?= $cardClass ?> <?= !empty($account['payment_requested']) ? 'payment-requested' : '' ?>">
                    <!-- section: Info -->
                    <div class="row-section-info" style="flex: 1; min-width: 200px;">
                        <div class="row-icon"><i class='bx <?= $icon ?>'></i></div>
                        <div class="row-details">
                            <div class="row-name">
                                <?= htmlspecialchars($account['name']) ?>
                                <?php if ($hasOrder): ?><span class="badge">Orden #<?= $account['order_id'] ?></span><?php endif; ?>
                            </div>
                            <div class="row-status" style="margin-bottom: 5px;">
                                <span><?= $statusLabel ?></span>
                            </div>
                            <!-- STATUS BADGES MOVED HERE TO AVOID PUSHING PRICE -->
                            <?php if ($account['payment_requested'] == 1): ?>
                                <div style="display: inline-flex; align-items: center; background: rgba(225, 29, 72, 0.1); color: #e11d48; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; animation: pulse 2s infinite; border: 1px solid rgba(225, 29, 72, 0.2); white-space: nowrap; margin-top: 4px;">
                                    <i class='bx bx-bell bx-tada' style="margin-right: 4px; font-size: 0.9rem;"></i> COBRO SOLICITADO
                                </div>
                            <?php elseif ($account['payment_requested'] == 2): ?>
                                <div style="display: inline-flex; align-items: center; background: rgba(245, 158, 11, 0.1); color: #d97706; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; border: 1px solid rgba(245, 158, 11, 0.2); white-space: nowrap; margin-top: 4px;">
                                    <i class='bx bx-printer' style="margin-right: 4px; font-size: 0.9rem;"></i> PREFACTURA IMPRESA
                                </div>
                            <?php elseif ($account['payment_requested'] == 3): ?>
                                <div style="display: inline-flex; align-items: center; background: rgba(16, 185, 129, 0.1); color: #059669; padding: 3px 8px; border-radius: 12px; font-size: 0.7rem; font-weight: 700; border: 1px solid rgba(16, 185, 129, 0.2); white-space: nowrap; margin-top: 4px;">
                                    <i class='bx bx-money' style="margin-right: 4px; font-size: 0.9rem;"></i> PAGO: <?= strtoupper(htmlspecialchars($account['payment_method'] ?? 'EFECTIVO')) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- section: Total -->
                    <div class="row-section-total" style="width: 120px; text-align: right; flex-shrink: 0;">
                        <?php if ($hasOrder): ?>
                            <span class="total-amount">C$<?= number_format($account['total'], 2) ?></span>
                        <?php else: ?>
                            <span class="total-empty">--</span>
                        <?php endif; ?>
                    </div>

                    <!-- section: Actions -->
                    <div class="row-section-actions" style="width: 150px; justify-content: flex-end; flex-shrink: 0; display: flex; gap: 8px;">

                        <?php if ($hasOrder): ?>
                            <?php if ($account['type'] !== 'pedidosya'): ?>
                                <button type="button" onclick="showOrderSummary(<?= $account['order_id'] ?>)"
                                    class="btn btn-sm btn-info icon-only" title="Ver Resumen">
                                    <i class='bx bx-list-ul'></i>
                                </button>
                                <?php if ($account['type'] !== 'libre'): ?>
                                <button type="button" onclick="window.open('prefactura.php?order_id=<?= $account['order_id'] ?>', '_blank')"
                                    class="btn btn-sm icon-only" style="background: #ec4899 !important; color: white !important; border: 1px solid #db2777 !important;" title="Imprimir Prefactura">
                                    <i class='bx bx-printer'></i>
                                </button>
                                <?php endif; ?>
                            <?php endif; ?>

                            <?php if ($account['type'] === 'pedidosya'): ?>
                                <a href="<?= $account['link'] ?>" class="btn btn-sm btn-primary action-btn">Ver Factura</a>
                            <?php else: ?>
                                <?php if ($account['payment_requested'] < 3 && $account['type'] === 'mesa'): ?>
                                    <button class="btn btn-sm btn-primary action-btn" style="opacity: 0.5; cursor: not-allowed;" title="Esperando que el mesero seleccione el método de pago" disabled>Cobrar</button>
                                <?php elseif ($account['payment_requested'] == 3 && $account['type'] === 'mesa'): ?>
                                    <?php if ($account['payment_method'] === 'dividido'): ?>
                                        <button type="button" class="btn btn-sm btn-primary action-btn" onclick="confirmSplitPayment('split_invoices.php?order_id=<?= $account['order_id'] ?>');">Cobrar</button>
                                    <?php else: ?>
                                        <form action="ver_pedido.php?table=<?= $account['id'] ?>" method="POST" style="margin:0;">
                                            <input type="hidden" name="return_to" value="cuentas">
                                            <?php if ($account['payment_method'] === 'mixto'): ?>
                                                <input type="hidden" name="process_mixed_payment" value="1">
                                                <?php 
                                                $details = json_decode($account['payment_details'], true);
                                                if (is_array($details) && isset($details['payments'])) {
                                                    foreach ($details['payments'] as $payment) {
                                                        echo '<input type="hidden" name="mixed_methods[]" value="'.htmlspecialchars($payment['method']).'">';
                                                        echo '<input type="hidden" name="mixed_amounts[]" value="'.htmlspecialchars($payment['amount']).'">';
                                                    }
                                                }
                                                ?>
                                            <?php else: ?>
                                                <input type="hidden" name="process_payment" value="1">
                                                <input type="hidden" name="payment_method" value="<?= htmlspecialchars($account['payment_method']) ?>">
                                            <?php endif; ?>
                                            <input type="hidden" name="tip_amount" value="0">
                                            <button type="button" class="btn btn-sm btn-primary action-btn" onclick="confirmPayment(this, 'C$<?= number_format($account['total'], 2) ?>', '<?= strtoupper(htmlspecialchars($account['payment_method'])) ?>');">Cobrar</button>
                                        </form>
                                    <?php endif; ?>
                                <?php elseif ($account['type'] === 'libre'): ?>
                                    <button type="button" class="btn btn-sm btn-primary action-btn" onclick="fastPayBarra(<?= $account['id'] ?>, <?= $account['total'] ?>)">Cobrar</button>
                                <?php else: ?>
                                    <a href="<?= $account['link'] ?>" class="btn btn-sm btn-primary action-btn">Cobrar</a>
                                <?php endif; ?>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </main>
</div>

<style>
    @keyframes pulse {
        0% { opacity: 1; }
        50% { opacity: 0.7; transform: scale(1.05); }
        100% { opacity: 1; }
    }
    
    .payment-requested {
        box-shadow: 0 0 15px rgba(225, 29, 72, 0.6) !important;
        border: 2px solid #e11d48 !important;
        transform: scale(1.01);
        z-index: 10;
        position: relative;
    }

    /* New List Layout */
    .tables-list {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(500px, 1fr));
        /* Wider cards */
        gap: 15px;
        /* Larger gap */
        padding-bottom: 20px;
    }

    .table-row {
        background: white;
        border-radius: 8px;
        padding: 10px 15px;
        display: flex;
        flex-wrap: wrap;
        justify-content: space-between;
        gap: 10px;
        align-items: center;
        border: 1px solid #e2e8f0;
        min-height: 55px;
        box-shadow: 0 1px 2px rgba(0, 0, 0, 0.05);
        transition: all 0.2s;
    }

    .table-row:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 6px rgba(0, 0, 0, 0.1);
    }

    /* Sections */
    .row-section-info {
        display: flex;
        align-items: center;
        gap: 15px;
        flex: 1 1 200px;
        min-width: 0;
    }

    .row-section-total {
        flex: 0 0 auto;
        text-align: right;
        display: flex;
        justify-content: flex-end;
        align-items: center;
        white-space: nowrap;
        padding: 0 10px;
    }

    .row-section-actions {
        flex: 0 0 auto;
        display: flex;
        justify-content: flex-end;
        gap: 5px;
        align-items: center;
    }

    /* Elements */
    .row-icon {
        width: 35px;
        /* Larger icon */
        height: 35px;
        border-radius: 6px;
        background: #f1f5f9;
        color: #64748b;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.3rem;
        /* Larger icon font */
        flex-shrink: 0;
    }

    .row-details {
        display: flex;
        flex-direction: column;
        line-height: 1.2;
        min-width: 0;
    }

    .row-name {
        font-weight: 600;
        font-size: 1.1rem;
        /* Larger name */
        color: #1e293b;
        display: flex;
        align-items: center;
        gap: 8px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    .row-status {
        font-size: 0.9rem;
        color: #64748b;
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 5px;
        margin-top: 3px;
    }

    .total-amount {
        font-size: 1.3rem;
        /* Larger total */
        font-weight: 700;
        color: #1e293b;
    }

    .total-empty {
        color: #cbd5e1;
        font-size: 1.1rem;
    }

    .badge {
        background: rgba(0, 0, 0, 0.05);
        color: #64748b;
        padding: 3px 8px;
        /* Larger padding */
        border-radius: 4px;
        font-size: 0.85rem;
        /* Larger badge font */
    }

    /* Buttons */
    .icon-only {
        width: 30px;
        height: 30px;
        padding: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 1.1rem;
        border-radius: 5px;
    }

    .action-btn {
        padding: 4px 12px;
        height: 30px;
        font-size: 0.85rem;
        font-weight: 600;
        border-radius: 5px;
        display: flex;
        align-items: center;
        gap: 4px;
        text-decoration: none;
    }

    /* --- Theme: Occupied (Purple) --- */
    .table-occupied {
        background: #ffffff;
        border-left: 4px solid var(--fc-primary, #8b5cf6);
    }
    .table-occupied .row-icon {
        background: rgba(139, 92, 246, 0.1);
        color: var(--fc-primary, #8b5cf6);
    }

    /* --- Theme: Libre (Orange) --- */
    .table-libre {
        background: #ffffff;
        border-left: 4px solid #f59e0b;
    }
    .table-libre .row-icon {
        background: rgba(245, 158, 11, 0.1);
        color: #f59e0b;
    }

    /* --- Theme: PedidosYa (Red) --- */
    .table-pedidosya {
        background: #ffffff;
        border-left: 4px solid #ef4444;
    }
    .table-pedidosya .row-icon {
        background: rgba(239, 68, 68, 0.1);
        color: #ef4444;
    }

    /* --- Theme: Ready (Green) --- */
    .table-ready {
        background: #ffffff;
        border-left: 4px solid #10b981;
    }
    .table-ready .row-icon {
        background: rgba(16, 185, 129, 0.1);
        color: #10b981;
    }

    /* --- Theme: Available (White) --- */
    .table-available {
        background: #ffffff;
        border-left: 4px solid #cbd5e1;
    }
    .table-available .row-icon {
        color: #64748b;
        background: #f1f5f9;
    }

    /* Button Colors in Themes */
    .table-row .btn-info {
        background: #f1f5f9;
        color: #64748b;
        border: 1px solid #e2e8f0;
    }
    .table-row .btn-info:hover {
        background: #e2e8f0;
    }

    .table-occupied .btn-primary {
        background: var(--fc-primary, #8b5cf6);
        color: white;
    }
    .table-libre .btn-primary {
        background: #f59e0b;
        color: white;
    }
    .table-pedidosya .btn-primary {
        background: #ef4444;
        color: white;
    }
    .table-ready .btn-primary {
        background: #10b981;
        color: white;
    }

    /* Animation */
    .table-card {
        animation: fadeSlideUp 0.3s ease-out forwards;
        opacity: 0;
    }

    @keyframes fadeSlideUp {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }
</style>

<script>
function confirmSplitPayment(url) {
    Swal.fire({
        title: '¿Procesar Cobro Dividido?',
        html: `Esta cuenta se configuró como <b>Pago Dividido</b>.<br>Se abrirá el panel para cobrar las facturas por separado.`,
        icon: 'info',
        iconColor: '#3b82f6',
        showCancelButton: true,
        confirmButtonColor: '#3b82f6',
        cancelButtonColor: '#cbd5e1',
        confirmButtonText: '<i class="bx bx-list-ul"></i> Ir al Panel de Cobros',
        cancelButtonText: 'Cancelar',
        customClass: {
            popup: 'fc-modal',
            title: 'fc-text-primary',
            confirmButton: 'fc-btn fc-btn-primary',
            cancelButton: 'fc-btn fc-btn-secondary',
            htmlContainer: 'fc-text-sec'
        },
        backdrop: `rgba(15, 23, 42, 0.8)`
    }).then((result) => {
        if (result.isConfirmed) {
            window.location.href = url;
        }
    });
}

function fastPayBarra(tableId, total) {
    Swal.fire({
        title: 'Cobro Rápido - Barra',
        html: `
            <div style="font-size: 1.5rem; font-weight: bold; margin-bottom: 20px; color: var(--fc-primary);">C$ ${parseFloat(total).toFixed(2)}</div>
            <div style="display: flex; gap: 10px; justify-content: center;">
                <button onclick="submitFastPay(${tableId}, 'cash')" class="fc-btn" style="background: #10b981; color: white; border: none; padding: 12px 20px; border-radius: 12px;"><i class="bx bx-money"></i> Efectivo</button>
                <button onclick="submitFastPay(${tableId}, 'card')" class="fc-btn" style="background: #3b82f6; color: white; border: none; padding: 12px 20px; border-radius: 12px;"><i class="bx bx-credit-card"></i> Tarjeta</button>
                <button onclick="submitFastPay(${tableId}, 'transfer')" class="fc-btn" style="background: #8b5cf6; color: white; border: none; padding: 12px 20px; border-radius: 12px;"><i class="bx bx-building-house"></i> Transf.</button>
            </div>
        `,
        showConfirmButton: false,
        showCancelButton: true,
        cancelButtonText: 'Cancelar',
        customClass: {
            popup: 'fc-modal',
            title: 'fc-text-primary',
            cancelButton: 'fc-btn fc-btn-secondary'
        },
        backdrop: `rgba(15, 23, 42, 0.8)`
    });
}

function submitFastPay(tableId, method) {
    Swal.fire({
        title: 'Procesando...',
        html: 'Generando factura y cerrando barra...',
        allowOutsideClick: false,
        didOpen: () => {
            Swal.showLoading()
        }
    });
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = 'ver_pedido.php?table=' + tableId;
    
    const inputProcess = document.createElement('input');
    inputProcess.type = 'hidden';
    inputProcess.name = 'process_payment';
    inputProcess.value = '1';
    form.appendChild(inputProcess);
    
    const inputMethod = document.createElement('input');
    inputMethod.type = 'hidden';
    inputMethod.name = 'payment_method';
    inputMethod.value = method;
    form.appendChild(inputMethod);
    
    const inputReturn = document.createElement('input');
    inputReturn.type = 'hidden';
    inputReturn.name = 'return_to';
    inputReturn.value = 'cuentas';
    form.appendChild(inputReturn);
    
    document.body.appendChild(form);
    form.submit();
}

function confirmPayment(button, amount, method) {
    Swal.fire({
        title: '¿Procesar Cobro?',
        html: `Se cerrará la cuenta con un pago de <b>${amount}</b> en <b>${method}</b>.`,
        icon: 'warning',
        iconColor: '#8b5cf6',
        showCancelButton: true,
        confirmButtonColor: '#8b5cf6',
        cancelButtonColor: '#cbd5e1',
        confirmButtonText: '<i class="bx bx-check-circle"></i> Confirmar y Cobrar',
        cancelButtonText: 'Cancelar',
        customClass: {
            popup: 'fc-modal',
            title: 'fc-text-primary',
            confirmButton: 'fc-btn fc-btn-primary',
            cancelButton: 'fc-btn fc-btn-secondary',
            htmlContainer: 'fc-text-sec'
        },
        backdrop: `rgba(15, 23, 42, 0.8)`
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'Procesando...',
                html: 'Generando factura y cerrando mesa...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading()
                }
            });
            button.closest('form').submit();
        }
    });
}

function showOrderSummary(orderId) {
        Swal.fire({
            title: 'Resumen de Cuenta',
            html: '<div id="summary-content">Cargando...</div>',
            showCloseButton: true,
            showConfirmButton: false,
            width: '600px'
        });

        fetch(`cuentas.php?ajax=get_order_details&order_id=${orderId}`)
            .then(response => response.json())
            .then(data => {
                let html = '<table style="width:100%; text-align:left; border-collapse: collapse;">';
                html += '<tr style="border-bottom:1px solid #eee;"><th style="padding:8px;">Prod</th><th style="padding:8px;">Cant</th><th style="text-align:right;padding:8px;">Total</th></tr>';

                data.items.forEach(item => {
                    let itemTotal = item.quantity * item.price;
                    html += `<tr>
                    <td style="padding:8px;">${item.name}</td>
                    <td style="padding:8px;">${item.quantity}</td>
                    <td style="text-align:right;padding:8px;">C$ ${parseFloat(itemTotal).toFixed(2)}</td>
                </tr>`;
                });

                html += `<tr style="border-top:2px solid #ddd; font-weight:bold;">
                <td colspan="2" style="padding:10px;">TOTAL</td>
                <td style="text-align:right;padding:10px;">C$ ${parseFloat(data.total).toFixed(2)}</td>
            </tr>`;
                html += '</table>';
                html += '<div style="margin-top:20px; text-align:right; color:#64748b; font-size:0.9em;">* Para cobrar, seleccione "Cobrar / Ver Cuenta"</div>';

                document.getElementById('summary-content').innerHTML = html;
            })
            .catch(err => {
                document.getElementById('summary-content').innerHTML = '<span style="color:red">Error al cargar detalles</span>';
            });
    }
</script>

<script src="assets/js/auto_refresh.js?v=<?= time() ?>"></script>

<?php include __DIR__ . '/includes/footer.php'; ?>