<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

checkModuleAccess($pdo, $_SESSION['role_id'], 'kitchen');

// Handle Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['start_preparation'])) {
        $order_id = $_POST['order_id'];
        $pdo->prepare('UPDATE orders SET status = "preparing" WHERE id = ?')->execute([$order_id]);
        $pdo->prepare('UPDATE order_details SET item_status = "preparing" WHERE order_id = ? AND item_status = "pending"')->execute([$order_id]);
        header('Location: cocina.php');
        exit();
    }

    if (isset($_POST['complete_order'])) {
        $order_id = $_POST['order_id'];
        $pdo->prepare('UPDATE order_details SET item_status = "ready" WHERE order_id = ? AND item_status IN ("pending", "preparing")')->execute([$order_id]);
        $pdo->prepare('UPDATE orders SET status = "ready" WHERE id = ?')->execute([$order_id]);
        header('Location: cocina.php?success=ready');
        exit();
    }
}

// Fetch Active Orders
$sql = '
    SELECT o.id as order_id, o.status as order_status, o.date_created,
           t.name as table_name, u.name as waiter_name,
           od.id as detail_id, od.quantity, od.item_status, od.notes,
           p.name as product_name, p.icon as product_icon
    FROM orders o
    JOIN tables t ON o.table_id = t.id
    JOIN users u ON o.user_id = u.id
    JOIN order_details od ON o.id = od.order_id AND od.item_status IN ("pending", "preparing")
    JOIN products p ON od.product_id = p.id
    WHERE o.status IN ("pending", "preparing")
    ORDER BY FIELD(o.status, "preparing", "pending"), o.date_created ASC
';
$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pending_orders = [];
foreach ($rows as $row) {
    $oid = $row['order_id'];
    if (!isset($pending_orders[$oid])) {
        $pending_orders[$oid] = [
            'id' => $oid,
            'status' => $row['order_status'],
            'date_created' => $row['date_created'],
            'table_name' => $row['table_name'],
            'waiter_name' => $row['waiter_name'],
            'items' => []
        ];
    }
    $pending_orders[$oid]['items'][] = [
        'qty' => $row['quantity'],
        'name' => $row['product_name'],
        'icon' => $row['product_icon'],
        'notes' => $row['notes'],
        'status' => $row['item_status']
    ];
}

// Fetch History Orders (Today)
$sql_history = '
    SELECT o.id as order_id, o.status as order_status, o.date_created,
           t.name as table_name, u.name as waiter_name,
           od.quantity, p.name as product_name
    FROM orders o
    JOIN tables t ON o.table_id = t.id
    JOIN users u ON o.user_id = u.id
    JOIN order_details od ON o.id = od.order_id AND od.item_status IN ("ready", "served")
    JOIN products p ON od.product_id = p.id
    WHERE o.status IN ("ready", "picked_up", "delivered", "completed")
    AND DATE(o.date_created) = CURDATE()
    ORDER BY o.date_created DESC
';
$stmt_hist = $pdo->query($sql_history);
$rows_hist = $stmt_hist->fetchAll(PDO::FETCH_ASSOC);

$history_orders = [];
foreach ($rows_hist as $row) {
    $oid = $row['order_id'];
    if (!isset($history_orders[$oid])) {
        $history_orders[$oid] = [
            'id' => $oid,
            'table_name' => $row['table_name'],
            'waiter_name' => $row['waiter_name'],
            'time' => date('h:i A', strtotime($row['date_created'])),
            'items' => []
        ];
    }
    $history_orders[$oid]['items'][] = [
        'qty' => $row['quantity'],
        'name' => $row['product_name']
    ];
}

$user_role_name = 'Cocinero';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php if ($_SESSION['role_id'] != 4) include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content" style="<?= $_SESSION['role_id'] == 4 ? 'margin-left: 0;' : '' ?>">
        <div class="page-header">
            <div>
                <h1>👨‍🍳 KDS - Cocina</h1>
                <p>Monitor de preparación de pedidos</p>
            </div>
            
            <div class="kds-tabs">
                <button class="kds-tab active" data-target="pending" onclick="switchTab('pending')">Pendientes (<?= count($pending_orders) ?>)</button>
                <button class="kds-tab" data-target="history" onclick="switchTab('history')">Historial (<?= count($history_orders) ?>)</button>
            </div>

            <div style="display: flex; align-items: center; gap: 20px;">
                <div id="clock" style="font-size: 1.5em; font-weight: 700; color: var(--text-primary);">
                    <?= date('H:i') ?>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['success'])): ?>
            <div class="alert-success">
                <i class="bx bx-check-circle"></i> Acción completada con éxito
            </div>
        <?php endif; ?>
        
        <!-- PENDING TAB -->
        <div id="tab-pending" class="tab-content active">
             <?php if (empty($pending_orders)): ?>
                <div class="empty-state">
                    <div style="font-size: 4em; margin-bottom: 20px;">✅</div>
                    <h2>Todo al día</h2>
                    <p>No hay pedidos pendientes en cocina.</p>
                </div>
            <?php else: ?>
                <div class="kitchen-grid">
                    <?php foreach ($pending_orders as $order): ?>
                        <?php
                        $isPreparing = ($order['status'] === 'preparing');
                        $startTime = strtotime($order['date_created']);
                        $elapsedMins = floor((time() - $startTime) / 60);
                        $isUrgent = $elapsedMins >= 15;
                        $isWarning = $elapsedMins >= 10 && $elapsedMins < 15;
                        ?>
                        <div class="kds-ticket <?= $isPreparing ? 'status-preparing' : 'status-pending' ?> <?= $isWarning ? 'is-warning' : '' ?> <?= $isUrgent ? 'is-urgent' : '' ?>">
                            <div class="ticket-header">
                                <div class="ticket-meta">
                                    <span class="order-id">#<?= $order['id'] ?></span>
                                    <span class="table-name"><?= htmlspecialchars($order['table_name']) ?></span>
                                    <span class="waiter-name">🤵 <?= htmlspecialchars($order['waiter_name']) ?></span>
                                    <span class="order-entry-time">🕒 <?= date('h:i A', strtotime($order['date_created'])) ?></span>
                                    <?php if($isPreparing): ?>
                                        <span class="status-badge badge-prep">Cocinando</span>
                                    <?php else: ?>
                                        <span class="status-badge badge-pend">Pendiente</span>
                                    <?php endif; ?>
                                </div>
                                <div class="urgent-container">
                                    <div class="timer-badge" data-time="<?= $startTime * 1000 ?>">
                                        ⏱ <span class="min-val"><?= $elapsedMins ?></span> min
                                    </div>
                                    <?php if ($isUrgent): ?>
                                        <div class="urgent-badge">🔥 TARDE</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="ticket-body">
                                <ul class="item-list">
                                    <?php foreach ($order['items'] as $item): ?>
                                        <li>
                                            <div class="item-qty"><?= $item['qty'] ?></div>
                                            <div class="item-details">
                                                <div class="item-title">
                                                    <span class="item-icon"><?= (!empty($item['icon']) && str_replace('?', '', $item['icon']) !== '') ? $item['icon'] : '🍽️' ?></span>
                                                    <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                                                    <?php if($item['status'] === 'pending' && $isPreparing): ?>
                                                        <span class="new-item-badge">NUEVO</span>
                                                    <?php endif; ?>
                                                </div>
                                                <?php if(!empty($item['notes'])): ?>
                                                    <div class="item-notes">⚠️ <?= htmlspecialchars($item['notes']) ?></div>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <div class="ticket-footer">
                                <form method="POST">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                    <?php if ($isPreparing): ?>
                                        <input type="hidden" name="complete_order" value="1">
                                        <button type="submit" class="kds-btn btn-finish">✅ LISTO</button>
                                    <?php else: ?>
                                        <input type="hidden" name="start_preparation" value="1">
                                        <button type="submit" class="kds-btn btn-start">👨‍🍳 PREPARAR TODO</button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- HISTORY TAB -->
        <div id="tab-history" class="tab-content" style="display:none;">
            <?php if (empty($history_orders)): ?>
                <div class="empty-state">
                    <div style="font-size: 4em; margin-bottom: 20px;">📜</div>
                    <h2>Sin Historial</h2>
                    <p>No hay pedidos completados hoy.</p>
                </div>
            <?php else: ?>
                <div class="kitchen-grid">
                    <?php foreach ($history_orders as $order): ?>
                        <div class="kds-ticket status-ready">
                            <div class="ticket-header">
                                <div class="ticket-meta">
                                    <span class="order-id">#<?= $order['id'] ?></span>
                                    <span class="table-name"><?= htmlspecialchars($order['table_name']) ?></span>
                                    <span class="waiter-name">✅ Completado a las <?= $order['time'] ?></span>
                                </div>
                            </div>
                            <div class="ticket-body">
                                <ul class="item-list">
                                    <?php foreach ($order['items'] as $item): ?>
                                        <li>
                                            <div class="item-qty history-qty"><?= $item['qty'] ?></div>
                                            <div class="item-details">
                                                <span class="item-icon history-icon"><?= (!empty($item['icon']) && str_replace('?', '', $item['icon']) !== '') ? $item['icon'] : '🍽️' ?></span>
                                                <span class="item-name history-name"><?= htmlspecialchars($item['name']) ?></span>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </main>
</div>

<style>
    /* Classic Clean Theme CSS */
    :root {
        --kds-bg: #f4f7f6;
        --ticket-bg: #ffffff;
        --ticket-border: #e2e8f0;
        --text-primary: #1e293b;
        --text-secondary: #64748b;
        --color-pending: #f59e0b; /* Amber */
        --color-prep: #3b82f6; /* Blue */
        --color-ready: #10b981; /* Emerald */
    }

    body {
        background-color: var(--kds-bg);
        color: var(--text-primary);
        font-family: 'Inter', system-ui, sans-serif;
    }

    .main-content {
        padding: 30px;
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
    }

    .page-header h1 {
        margin: 0;
        font-size: 1.8rem;
        font-weight: 800;
        letter-spacing: -0.5px;
        color: var(--text-primary);
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .page-header p {
        margin: 5px 0 0 0;
        color: var(--text-secondary);
        font-size: 0.95rem;
    }

    /* Tabs UI */
    .kds-tabs {
        display: flex;
        gap: 5px;
        background: rgba(0, 0, 0, 0.03);
        padding: 5px;
        border-radius: 12px;
    }

    .kds-tab {
        border: none;
        background: transparent;
        padding: 10px 20px;
        border-radius: 8px;
        font-weight: 600;
        cursor: pointer;
        color: var(--text-secondary);
        transition: all 0.3s ease;
        font-size: 0.95rem;
    }

    .kds-tab:hover {
        color: var(--text-primary);
        background: rgba(0, 0, 0, 0.05);
    }

    .kds-tab.active {
        background: var(--fc-primary, #e11d48);
        color: white;
        box-shadow: 0 4px 10px rgba(225, 29, 72, 0.3);
    }

    /* Override aggressive global centering from style.css */
    .tab-content {
        max-width: 100% !important;
        margin: 0 !important;
    }

    /* Grid - Responsive Grid that fills all empty space */
    .kitchen-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
        gap: 20px;
        padding-bottom: 40px;
        width: 100%;
        max-width: 100% !important;
    }

    /* Tickets */
    .kds-ticket {
        background: var(--ticket-bg);
        border-radius: 12px;
        border: 1px solid var(--ticket-border);
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.05);
        display: flex;
        flex-direction: column;
        width: 100%; /* Fill the grid column */
        border-top: 4px solid var(--ticket-border);
        transition: all 0.2s ease;
        text-align: left;
        margin: 0;
    }

    .kds-ticket:hover {
        box-shadow: 0 8px 24px rgba(0, 0, 0, 0.1);
    }
    
    /* Override theme.css aggressive red glow for urgent tickets */
    .kds-ticket.is-urgent {
        border-color: #fca5a5 !important;
        box-shadow: 0 4px 12px rgba(239, 68, 68, 0.15) !important;
        animation: none !important; /* Stop the pulsing red glow */
    }

    /* Top Border Colors */
    .status-pending { border-top-color: var(--color-pending); }
    .status-preparing { border-top-color: var(--color-prep); }
    .status-ready { border-top-color: var(--color-ready); }

    /* Header inside Ticket */
    .ticket-header {
        padding: 15px;
        border-bottom: 1px solid var(--ticket-border);
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
        background: rgba(0,0,0,0.01);
    }

    .ticket-meta {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .ticket-body {
        padding: 0 15px 15px 15px;
        flex: 1;
        max-height: 280px; /* Show approx 5 items before scrolling */
        overflow-y: auto;
    }

    /* Custom Thin Scrollbar for Tickets */
    .ticket-body::-webkit-scrollbar {
        width: 4px;
    }
    .ticket-body::-webkit-scrollbar-track {
        background: transparent;
    }
    .ticket-body::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }

    .order-id {
        font-size: 1.4em;
        font-weight: 800;
        color: var(--text-primary);
        letter-spacing: -0.5px;
    }

    .table-name {
        font-size: 1em;
        font-weight: 700;
        color: var(--text-primary);
    }

    .waiter-name, .order-entry-time {
        font-size: 0.85em;
        color: var(--text-secondary);
    }

    /* Badges */
    .status-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 6px;
        font-size: 0.75em;
        font-weight: 800;
        text-transform: uppercase;
        margin-top: 5px;
        width: fit-content;
    }

    .badge-pend { background: #fef3c7; color: #d97706; border: 1px solid #fde68a; }
    .badge-prep { background: #dbeafe; color: #2563eb; border: 1px solid #bfdbfe; }

    .new-item-badge {
        background: var(--fc-primary, #e11d48);
        color: white;
        font-size: 0.6em;
        padding: 2px 6px;
        border-radius: 4px;
        margin-left: 8px;
        font-weight: bold;
    }

    /* Timers & Alerts */
    .urgent-container {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 8px;
    }

    .timer-badge {
        background: #f1f5f9;
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 0.9em;
        font-weight: 600;
        border: 1px solid var(--ticket-border);
        color: var(--text-secondary);
    }

    .is-warning .timer-badge {
        background: #fef3c7;
        color: #d97706;
        border-color: #fde68a;
    }

    .is-urgent .timer-badge {
        background: #fee2e2;
        color: #dc2626;
        border-color: #fecaca;
    }

    .urgent-badge {
        background: #ef4444;
        color: white;
        font-size: 0.7em;
        font-weight: 800;
        padding: 4px 8px;
        border-radius: 6px;
    }

    /* Items */
    .ticket-body {
        padding: 15px 20px;
        flex-grow: 1;
    }

    .item-list {
        list-style: none;
        padding: 0;
        margin: 0;
        display: flex;
        flex-direction: column;
        gap: 12px;
    }

    .item-list li {
        display: flex;
        gap: 12px;
        align-items: flex-start;
        padding-bottom: 12px;
        border-bottom: 1px dashed var(--ticket-border);
    }

    .item-list li:last-child {
        border-bottom: none;
        padding-bottom: 0;
    }

    .item-qty {
        font-weight: 800;
        font-size: 1.1em;
        color: var(--fc-primary, #3b82f6);
        min-width: 20px;
        padding-top: 1px;
    }

    .item-details {
        display: flex;
        flex-direction: column;
        gap: 4px;
        width: 100%;
    }

    .item-title {
        display: flex;
        align-items: flex-start;
    }

    .item-name {
        font-size: 1.05em;
        font-weight: 600;
        color: var(--text-primary);
        line-height: 1.3;
    }

    .item-notes {
        color: #dc2626;
        font-size: 0.85em;
        font-weight: 600;
        margin-top: 2px;
    }

    /* History Specifics */
    .history-qty {
        color: #10b981;
    }
    .history-name {
        color: var(--text-secondary);
    }

    /* Action Buttons */
    .ticket-footer {
        padding: 15px 20px;
        background: rgba(0, 0, 0, 0.015);
        border-top: 1px solid var(--ticket-border);
    }

    .kds-btn {
        width: 100%;
        padding: 12px;
        border-radius: 8px;
        font-weight: 800;
        font-size: 1em;
        cursor: pointer;
        border: none;
        transition: all 0.2s;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 8px;
    }

    .btn-start {
        background: var(--color-prep);
        color: white;
    }

    .btn-start:hover {
        background: #2563eb;
    }

    .btn-finish {
        background: var(--color-ready);
        color: white;
    }

    .btn-finish:hover {
        background: #059669;
    }

    /* Empty State */
    .empty-state {
        text-align: center;
        padding: 60px 20px;
        background: transparent;
        border: 2px dashed rgba(0, 0, 0, 0.1);
        border-radius: 16px;
        max-width: 600px;
        margin: 40px auto;
    }

    .empty-state h2 {
        margin: 0 0 10px 0;
        color: var(--text-primary);
        font-weight: 800;
    }

    .empty-state p {
        color: var(--text-secondary);
        margin: 0;
    }

    .alert-success {
        background: #ecfdf5;
        border: 1px solid #a7f3d0;
        color: #059669;
        padding: 16px 24px;
        border-radius: 12px;
        margin-bottom: 30px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 12px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.02);
    }
</style>

<script>
    // Tab Switcher Logic - Robusto y sin dependencias de evento global
    function switchTab(tabId) {
        // UI
        document.querySelectorAll('.kds-tab').forEach(t => t.classList.remove('active'));
        const btn = document.querySelector(`.kds-tab[data-target="${tabId}"]`);
        if (btn) btn.classList.add('active');

        // Content
        document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
        const targetContainer = document.getElementById('tab-' + tabId);
        if (targetContainer) targetContainer.style.display = 'block';

        // Session
        sessionStorage.setItem('kds_active_tab', tabId);
    }

    // Init
    document.addEventListener('DOMContentLoaded', () => {
        const savedTab = sessionStorage.getItem('kds_active_tab');
        if (savedTab) {
            switchTab(savedTab);
        }
    });

    // Clock and SLA Tracker
    setInterval(() => {
        const now = new Date();
        document.getElementById('clock').textContent = now.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        
        document.querySelectorAll('.timer-badge').forEach(badge => {
            const startTime = parseInt(badge.getAttribute('data-time'));
            if (startTime) {
                const elapsedMins = Math.floor((now.getTime() - startTime) / 60000);
                badge.querySelector('.min-val').textContent = elapsedMins;
                
                const ticket = badge.closest('.kds-ticket');
                if (elapsedMins >= 15 && !ticket.classList.contains('is-urgent')) {
                    ticket.classList.remove('is-warning');
                    ticket.classList.add('is-urgent');
                    let urgentBadge = ticket.querySelector('.urgent-badge');
                    if (!urgentBadge) {
                        const container = badge.closest('.urgent-container');
                        container.insertAdjacentHTML('beforeend', '<div class="urgent-badge">🔥 TARDE</div>');
                    }
                } else if (elapsedMins >= 10 && elapsedMins < 15 && !ticket.classList.contains('is-urgent') && !ticket.classList.contains('is-warning')) {
                    ticket.classList.add('is-warning');
                }
            }
        });
    }, 1000);

    // Auto-refresh KDS every 15 seconds to fetch new incoming orders
    setInterval(() => {
        location.reload();
    }, 15000);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>