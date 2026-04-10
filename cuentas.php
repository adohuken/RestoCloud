<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Check module access (using 'mesas' permission or strict 'cuentas' if we were to add it, for now 'mesas' or 'caja' participants)
// Admin, Waiter, Cashier, SuperAdmin
if (!in_array($_SESSION['role_id'], [1, 2, 3, 5])) {
    header('Location: inicio.php');
    exit();
}

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
           u.name as waiter_name
    FROM tables t
    JOIN orders o ON t.id = o.table_id 
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
        'waiter' => $t['waiter_name'],
        'link' => "ver_pedido.php?table={$t['id']}&view=bill"
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
                    <?= strtoupper(substr($_SESSION['name'], 0, 1)) ?>
                </div>
                <div class="user-details">
                    <span class="user-name">
                        <?= htmlspecialchars($_SESSION['name']) ?>
                    </span>
                    <span class="user-role">
                        <?= htmlspecialchars($user_role_name) ?>
                    </span>
                </div>
            </div>
        </div>

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
                            $statusLabel = 'Anotando...';
                            $icon = 'bx-edit';
                        }
                    }
                }
                ?>
                <div class="table-row table-<?= $cardClass ?>">
                    <!-- section: Info -->
                    <div class="row-section-info">
                        <div class="row-icon"><i class='bx <?= $icon ?>'></i></div>
                        <div class="row-details">
                            <div class="row-name">
                                <?= htmlspecialchars($account['name']) ?>
                                <?php if ($hasOrder): ?><span
                                        class="badge">#<?= $account['order_id'] ?></span><?php endif; ?>
                            </div>
                            <div class="row-status"><?= $statusLabel ?></div>
                        </div>
                    </div>

                    <!-- section: Total -->
                    <div class="row-section-total">
                        <?php if ($hasOrder): ?>
                            <span class="total-amount">C$<?= number_format($account['total'], 2) ?></span>
                        <?php else: ?>
                            <span class="total-empty">--</span>
                        <?php endif; ?>
                    </div>

                    <!-- section: Actions -->
                    <div class="row-section-actions">
                        <?php if ($hasOrder): ?>
                            <?php if ($account['type'] !== 'pedidosya'): ?>
                                <button type="button" onclick="showOrderSummary(<?= $account['order_id'] ?>)"
                                    class="btn btn-sm btn-info icon-only">
                                    <i class='bx bx-list-ul'></i>
                                </button>
                            <?php endif; ?>

                            <a href="<?= $account['link'] ?>" class="btn btn-sm btn-primary action-btn">
                                <?php if ($account['type'] === 'pedidosya'): ?>
                                    Ver Factura
                                <?php else: ?>
                                    Cobrar
                                <?php endif; ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

    </main>
</div>

<style>
    /* New List Layout */
    /* New List Layout (Grid) */
    /* New List Layout (Grid) */
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
        padding: 5px 20px;
        /* More horizontal padding */
        display: flex;
        align-items: center;
        justify-content: space-between;
        border: 1px solid #e2e8f0;
        min-height: 55px;
        /* Slightly taller */
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
        flex: 2;
        min-width: 0;
    }

    .row-section-total {
        flex: 1;
        text-align: center;
        display: flex;
        justify-content: center;
        align-items: center;
        white-space: nowrap;
    }

    .row-section-actions {
        flex: 1.5;
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
        /* Larger status text */
        color: #64748b;
        white-space: nowrap;
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
        background: linear-gradient(135deg, #6366f1 0%, #4f46e5 100%);
        border: none;
    }

    .table-occupied .row-name,
    .table-occupied .row-status,
    .table-occupied .total-amount {
        color: white;
    }

    .table-occupied .row-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .table-occupied .badge {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .table-occupied .btn-info {
        background: rgba(255, 255, 255, 0.2);
        color: white;
        border: none;
    }

    .table-occupied .btn-primary {
        background: white;
        color: #4f46e5;
        border: none;
    }

    /* --- Theme: Libre (Orange) --- */
    .table-libre {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        border: none;
    }

    .table-libre .row-name,
    .table-libre .row-status,
    .table-libre .total-amount {
        color: white;
    }

    .table-libre .row-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .table-libre .badge {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .table-libre .btn-info {
        background: rgba(255, 255, 255, 0.2);
        color: white;
        border: none;
    }

    .table-libre .btn-primary {
        background: white;
        color: #d97706;
        border: none;
    }

    /* --- Theme: PedidosYa (Red) --- */
    .table-pedidosya {
        background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%);
        border: none;
    }

    .table-pedidosya .row-name,
    .table-pedidosya .row-status,
    .table-pedidosya .total-amount {
        color: white;
    }

    .table-pedidosya .row-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .table-pedidosya .badge {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .table-pedidosya .btn-primary {
        background: white;
        color: #b91c1c;
        border: none;
    }

    /* --- Theme: Ready (Green) --- */
    .table-ready {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        border: none;
    }

    .table-ready .row-name,
    .table-ready .row-status,
    .table-ready .total-amount {
        color: white;
    }

    .table-ready .row-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .table-ready .badge {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .table-ready .btn-info {
        background: rgba(255, 255, 255, 0.2);
        color: white;
        border: none;
    }

    .table-ready .btn-primary {
        background: white;
        color: #059669;
        border: none;
    }

    /* --- Theme: Available (White) --- */
    }

    .table-pedidosya .table-name,
    .table-pedidosya .table-status,
    .table-pedidosya .table-total {
        color: white;
    }

    .table-pedidosya .table-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .table-pedidosya .badge {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    /* --- Theme: Ready (Green) --- */
    .table-ready {
        background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        border: none;
    }

    .table-ready .table-name,
    .table-ready .table-status,
    .table-ready .table-total {
        color: white;
    }

    .table-ready .table-icon {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    .table-ready .badge {
        background: rgba(255, 255, 255, 0.2);
        color: white;
    }

    /* --- Theme: Available (White) --- */
    .table-available {
        background: white;
        border-left: 5px solid #10b981;
    }

    .table-available .table-icon {
        color: #10b981;
        background: #dcfce7;
    }

    /* Button Colors in Themes */
    .table-occupied .btn-info,
    .table-libre .btn-info,
    .table-ready .btn-info {
        background: rgba(255, 255, 255, 0.2);
        color: white;
        border: none;
    }

    .table-occupied .btn-primary,
    .table-libre .btn-primary,
    .table-ready .btn-primary,
    .table-pedidosya .btn-primary {
        background: white;
        color: #333;
        border: none;
        box-shadow: 0 2px 4px rgba(0, 0, 0, 0.1);
    }

    .table-occupied .btn-primary {
        color: #4f46e5;
    }

    .table-libre .btn-primary {
        color: #d97706;
    }

    .table-pedidosya .btn-primary {
        color: #b91c1c;
    }

    .table-ready .btn-primary {
        color: #059669;
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

<?php include __DIR__ . '/includes/footer.php'; ?>