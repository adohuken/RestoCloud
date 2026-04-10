<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Check module access (will redirect if not authorized)
// Check module access (will redirect if not authorized)
checkModuleAccess($pdo, $_SESSION['role_id'], 'kitchen');

// Auto-fix schema to support 'preparing' status
try {
    $pdo->query("SELECT status FROM orders WHERE status = 'preparing' LIMIT 1");
} catch (Exception $e) {
    // Should catch if 'preparing' is invalid for ENUM? No, SELECT doesn't error on value mismatch usually, INSERT/UPDATE does.
    // But we can just try to ALTER it.
}

try {
    // Ensure the order status ENUM has all required values
    $stmt = $pdo->query("SHOW COLUMNS FROM orders LIKE 'status'");
    $column = $stmt->fetch();
    if (strpos($column['Type'], 'enum') !== false) {
        // Extract existing values
        preg_match_all("/'([^']+)'/", $column['Type'], $matches);
        $values = $matches[1];

        // Required statuses for the complete order flow
        $requiredStatuses = ['draft', 'pending', 'preparing', 'ready', 'picked_up', 'delivered', 'completed', 'cancelled'];
        $needsUpdate = false;

        foreach ($requiredStatuses as $status) {
            if (!in_array($status, $values)) {
                $values[] = $status;
                $needsUpdate = true;
            }
        }

        if ($needsUpdate) {
            $enumList = "'" . implode("','", $values) . "'";
            $pdo->exec("ALTER TABLE orders MODIFY COLUMN status ENUM($enumList) DEFAULT 'draft'");
        }
    }
} catch (Exception $e) {
    // Silently fail or log
}

// Handle order status updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['start_preparation'])) {
        $order_id = $_POST['order_id'];
        $stmt = $pdo->prepare('UPDATE orders SET status = "preparing" WHERE id = ?');
        $stmt->execute([$order_id]);
        
        // Mark all items as preparing as well
        $stmt = $pdo->prepare('UPDATE order_details SET item_status = "preparing" WHERE order_id = ? AND item_status = "pending"');
        $stmt->execute([$order_id]);
        header('Location: cocina.php?success=preparing');
        exit();
    }

    if (isset($_POST['complete_order'])) {
        $order_id = $_POST['order_id'];

        // Mark all items for this order as ready
        $stmt = $pdo->prepare('UPDATE order_details SET item_status = "ready" WHERE order_id = ? AND item_status IN ("pending", "preparing")');
        $stmt->execute([$order_id]);

        // Mark order as ready
        $stmt = $pdo->prepare('UPDATE orders SET status = "ready" WHERE id = ?');
        $stmt->execute([$order_id]);

        header('Location: cocina.php?success=ready');
        exit();
    }
}

// Get orders with PENDING or PREPARING items
// We fetch raw rows to grouping in PHP for better item rendering (icons, notes, etc.)
$sql = '
    SELECT o.id as order_id, o.status as order_status, o.date_created, o.total,
           t.name as table_name, u.name as waiter_name,
           od.id as detail_id, od.quantity, od.item_status, od.notes,
           p.name as product_name, p.icon as product_icon, p.image_url
    FROM orders o
    JOIN tables t ON o.table_id = t.id
    JOIN users u ON o.user_id = u.id
    JOIN order_details od ON o.id = od.order_id AND od.item_status = "pending"
    JOIN products p ON od.product_id = p.id
    WHERE o.status IN ("pending", "preparing")
    ORDER BY FIELD(o.status, "preparing", "pending"), o.date_created ASC
';

$stmt = $pdo->query($sql);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by Order ID
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
            'total' => $row['total'], // This is order total, simplified
            'items' => []
        ];
    }
    $pending_orders[$oid]['items'][] = [
        'qty' => $row['quantity'],
        'name' => $row['product_name'],
        'icon' => $row['product_icon'],
        'notes' => $row['notes'] ?? null
    ];
}

// Get completed orders for history (Today)
$sql_history = '
    SELECT o.id as order_id, o.status as order_status, o.date_created, o.total,
           t.name as table_name, u.name as waiter_name,
           od.quantity, p.name as product_name, p.icon as product_icon
    FROM orders o
    JOIN tables t ON o.table_id = t.id
    JOIN users u ON o.user_id = u.id
    JOIN order_details od ON o.id = od.order_id
    JOIN products p ON od.product_id = p.id
    WHERE o.status IN ("ready", "picked_up", "delivered")
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
            'status' => $row['order_status'],
            'date_created' => $row['date_created'],
            'table_name' => $row['table_name'],
            'waiter_name' => $row['waiter_name'],
            'items' => []
        ];
    }
    $history_orders[$oid]['items'][] = [
        'qty' => $row['quantity'],
        'name' => $row['product_name'],
        'icon' => $row['product_icon']
    ];
}

// Get user's role name
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php if ($_SESSION['role_id'] != 4)
        include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content" style="<?= $_SESSION['role_id'] == 4 ? 'margin-left: 0;' : '' ?>">
        <div class="page-header">
            <div>
                <h1>👨‍🍳 KDS - Cocina</h1>
                <p>Monitor de preparación de pedidos</p>
            </div>
            
            <div class="kds-tabs">
                <button class="kds-tab active" onclick="switchTab('pending')">Pendientes (<?= count($pending_orders) ?>)</button>
                <button class="kds-tab" onclick="switchTab('history')">Historial (<?= count($history_orders) ?>)</button>
            </div>

            <div style="display: flex; align-items: center; gap: 20px;">
                <div id="clock" style="font-size: 1.5em; font-weight: 700; color: var(--text-primary);">
                    <?= date('H:i') ?>
                </div>
                <?php if ($_SESSION['role_id'] == 4): ?>
                    <a href="salir.php" class="logout-pill">
                        <span class="logout-icon">🚪</span>
                        <span>Salir</span>
                    </a>
                <?php endif; ?>
            </div>
        </div>

        <?php if (isset($_GET['success']) && $_GET['success'] === 'ready'): ?>
            <div class="alert alert-success">
                ✅ Pedido completado
            </div>
        <?php endif; ?>
        
        <!-- Pending Container -->
        <div id="tab-pending" class="tab-content active">
             <?php if (empty($pending_orders)): ?>
                <div class="card empty-state">
                    <div style="padding: 60px; text-align: center;">
                        <div style="font-size: 4em; margin-bottom: 20px;">✅</div>
                        <h2 style="color: var(--text-primary); margin-bottom: 10px;">Todo al día</h2>
                        <p style="color: var(--text-secondary);">No hay pedidos pendientes en cocina.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="kitchen-grid">
                    <?php foreach ($pending_orders as $order): ?>
                        <?php
                        $isPreparing = ($order['status'] === 'preparing');
                        $startTime = strtotime($order['date_created']);
                        $elapsed = time() - $startTime;
                        $elapsedMins = floor($elapsed / 60);

                        // Urgency logic
                        $isUrgent = $elapsedMins >= 15;
                        $isWarning = $elapsedMins >= 10 && $elapsedMins < 15;
                        ?>
                        <div
                            class="kds-ticket <?= $isPreparing ? 'status-preparing' : 'status-pending' ?> <?= $isWarning ? 'is-warning' : '' ?> <?= $isUrgent ? 'is-urgent' : '' ?>">
                            <!-- Ticket Header -->
                            <div class="ticket-header">
                                <div class="ticket-meta">
                                    <span class="order-id">#<?= $order['id'] ?></span>
                                    <span class="table-name"><?= htmlspecialchars($order['table_name']) ?></span>
                                    <span class="waiter-name">🤵 <?= htmlspecialchars($order['waiter_name']) ?></span>
                                    <span class="order-entry-time">🕒 <?= date('h:i A', strtotime($order['date_created'])) ?></span>
                                    <?php if($order['status'] === 'preparing'): ?>
                                        <span class="status-badge preparing">Cocinando</span>
                                    <?php else: ?>
                                        <span class="status-badge pending">Pendiente</span>
                                    <?php endif; ?>
                                </div>
                                <div style="display: flex; flex-direction: column; align-items: flex-end; gap: 5px;" class="urgent-container">
                                    <div class="timer-badge" data-time="<?= $startTime * 1000 ?>">
                                        ⏱ <span class="min-val"><?= $elapsedMins ?></span> min
                                    </div>
                                    <?php if ($isUrgent): ?>
                                        <div class="urgent-badge">🔥 TARDE</div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <!-- Ticket Body -->
                            <div class="ticket-body">
                                <ul class="item-list">
                                    <?php foreach ($order['items'] as $item): ?>
                                        <li>
                                            <div class="item-qty"><?= $item['qty'] ?></div>
                                            <div class="item-details" style="flex-direction: column; align-items: flex-start;">
                                                <div style="display: flex; align-items: center;">
                                                    <span class="item-icon"><?= $item['icon'] ?: '🍽️' ?></span>
                                                    <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
                                                </div>
                                                <?php if(!empty($item['notes'])): ?>
                                                    <div style="font-size: 0.85em; color: #b45309; background: #fef3c7; padding: 3px 8px; border-radius: 4px; margin-top: 4px; font-weight: 700; border: 1px solid #fde68a; width: 100%;">
                                                        ⚠️ Nota: <?= htmlspecialchars($item['notes']) ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>

                            <!-- Ticket Footer (Action) -->
                            <div class="ticket-footer">
                                <form method="POST">
                                    <input type="hidden" name="order_id" value="<?= $order['id'] ?>">

                                    <?php if ($isPreparing): ?>
                                        <input type="hidden" name="complete_order" value="1">
                                        <button type="submit" class="kds-btn btn-finish">
                                            <span style="font-size: 1.2em;">✅</span> LISTO
                                        </button>
                                    <?php else: ?>
                                        <input type="hidden" name="start_preparation" value="1">
                                        <button type="submit" class="kds-btn btn-start">
                                            👨‍🍳 PREPARAR
                                        </button>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- History Container -->
        <div id="tab-history" class="tab-content" style="display:none;">
            <?php if (empty($history_orders)): ?>
                <div class="card empty-state">
                    <div style="padding: 60px; text-align: center; opacity:0.7;">
                        <div style="font-size: 4em; margin-bottom: 20px;">📜</div>
                        <h2 style="color: var(--text-primary); margin-bottom: 10px;">Sin Historial</h2>
                        <p style="color: var(--text-secondary);">No hay pedidos completados hoy.</p>
                    </div>
                </div>
            <?php else: ?>
                <div class="kitchen-grid" style="opacity: 0.8;">
                    <?php foreach ($history_orders as $order): ?>
                        <div class="kds-ticket" style="border-top-color: #10b981;">
                            <div class="ticket-header" style="background: #f9fafb;">
                                <div class="ticket-meta">
                                    <span class="order-id">#<?= $order['id'] ?></span>
                                    <span class="table-name"><?= htmlspecialchars($order['table_name']) ?></span>
                                    <span class="waiter-name">✅ Completado a las <?= date('h:i A', strtotime($order['date_created'])) ?></span> <!-- approximations since we don't track exact complete time per se without new column, using creation for reference or we'd need updated_at -->
                                </div>
                            </div>
                            <div class="ticket-body">
                                <ul class="item-list">
                                    <?php foreach ($order['items'] as $item): ?>
                                        <li>
                                            <div class="item-qty" style="background:#d1fae5; color:#065f46;"><?= $item['qty'] ?></div>
                                            <div class="item-details">
                                                <span class="item-name" style="color:#4b5563;"><?= htmlspecialchars($item['name']) ?></span>
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
    /* KDS Specific Styles */
    :root {
        --kds-bg: #f3f4f6;
        --ticket-bg: #ffffff;
        --pending-border: #fbbf24;
        --prep-border: #3b82f6;
        --urgent-color: #ef4444;
    }

    body {
        background-color: var(--kds-bg);
    }

    .page-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 25px;
    }

    .kitchen-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
        gap: 20px;
        padding-bottom: 40px;
    }

    /* Ticket Card Styline */
    .kds-ticket {
        background: var(--ticket-bg);
        border-radius: 12px;
        box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        overflow: hidden;
        display: flex;
        flex-direction: column;
        border-top: 6px solid #e5e7eb;
        /* Default Gray */
        transition: transform 0.2s;
        animation: fadeIn 0.3s ease-out;
    }

    @keyframes fadeIn {
        from {
            opacity: 0;
            transform: translateY(10px);
        }

        to {
            opacity: 1;
            transform: translateY(0);
        }
    }

    .kds-ticket:hover {
        transform: translateY(-4px);
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
    }

    /* Status Varients */
    .status-pending {
        border-top-color: var(--pending-border);
        animation: pulsePending 2s infinite ease-in-out;
    }

    @keyframes pulsePending {
        0% {
            border-top-color: #fbbf24;
            box-shadow: 0 4px 6px -1px rgba(251, 191, 36, 0.1);
        }

        50% {
            border-top-color: #f59e0b;
            box-shadow: 0 4px 12px -1px rgba(251, 191, 36, 0.3);
        }

        100% {
            border-top-color: #fbbf24;
            box-shadow: 0 4px 6px -1px rgba(251, 191, 36, 0.1);
        }
    }

    .status-preparing {
        border-top-color: var(--prep-border);
        background-color: #eff6ff;
        background-image: linear-gradient(45deg,
                rgba(59, 130, 246, 0.08) 25%,
                transparent 25%,
                transparent 50%,
                rgba(59, 130, 246, 0.08) 50%,
                rgba(59, 130, 246, 0.08) 75%,
                transparent 75%,
                transparent);
        background-size: 40px 40px;
        animation: preparation-stripes 2s linear infinite;
    }

    @keyframes preparation-stripes {
        0% {
            background-position: 0 0;
        }

        100% {
            background-position: 40px 0;
        }
    }

    .is-warning {
        border-top-color: #f59e0b !important;
        animation: pulseWarning 2s infinite !important;
    }
    
    @keyframes pulseWarning {
        0% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0.4); }
        70% { box-shadow: 0 0 0 6px rgba(245, 158, 11, 0); }
        100% { box-shadow: 0 0 0 0 rgba(245, 158, 11, 0); }
    }

    .is-urgent {
        border-top-color: var(--urgent-color) !important;
        animation: pulseBorder 1s infinite !important;
    }

    @keyframes pulseBorder {
        0% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0.4);
        }

        70% {
            box-shadow: 0 0 0 6px rgba(239, 68, 68, 0);
        }

        100% {
            box-shadow: 0 0 0 0 rgba(239, 68, 68, 0);
        }
    }

    /* Header */
    .ticket-header {
        padding: 15px;
        border-bottom: 1px dashed #e5e7eb;
        display: flex;
        justify-content: space-between;
        align-items: flex-start;
    }

    .ticket-meta {
        display: flex;
        flex-direction: column;
    }

    .order-id {
        font-size: 1.4em;
        font-weight: 800;
        color: #111827;
    }

    .table-name {
        font-size: 1em;
        color: #6b7280;
        font-weight: 600;
    }

    .order-entry-time {
        font-size: 0.85em;
        color: #9ca3af;
        margin-top: 2px;
        font-weight: 500;
    }

    .waiter-name {
        font-size: 0.8em;
        color: #6b7280;
        margin-top: 2px;
        font-style: italic;
    }

    .timer-badge {
        background: #f3f4f6;
        padding: 4px 8px;
        border-radius: 6px;
        font-family: monospace;
        font-weight: 700;
        font-size: 1.1em;
        color: #374151;
    }

    .is-urgent .timer-badge {
        background: #fee2e2;
        color: #ef4444;
    }

    /* Body */
    .ticket-body {
        padding: 0;
        /* Reset padding to let items flush if needed, but we'll add it back */
        padding: 15px;
        flex-grow: 1;
    }

    .item-list {
        list-style: none;
        padding: 0;
        margin: 0 0 15px 0;
    }

    .item-list li {
        display: flex;
        align-items: flex-start;
        padding: 10px 0;
        border-bottom: 1px solid #f3f4f6;
    }

    .item-list li:last-child {
        border-bottom: none;
    }

    .item-qty {
        background: #e5e7eb;
        color: #111827;
        font-weight: 800;
        width: 30px;
        height: 30px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        margin-right: 12px;
        flex-shrink: 0;
        font-size: 1.1em;
    }

    .item-details {
        flex: 1;
        display: flex;
        align-items: center;
        flex-wrap: wrap;
    }

    .item-icon {
        font-size: 1.4em;
        margin-right: 8px;
    }

    .item-name {
        font-size: 1.15em;
        font-weight: 600;
        color: #1f2937;
        line-height: 1.3;
    }

    .urgent-badge {
        background: #ef4444;
        color: white;
        font-size: 0.75em;
        font-weight: 800;
        padding: 2px 6px;
        border-radius: 4px;
        text-transform: uppercase;
        animation: blink 1s infinite;
    }

    @keyframes blink {
        0% {
            opacity: 1;
        }

        50% {
            opacity: 0.6;
        }

        100% {
            opacity: 1;
        }
    }

    .waiter-info {
        font-size: 0.85em;
        color: #9ca3af;
        text-align: right;
        font-style: italic;
        margin-top: 10px;
    }

    /* Footer */
    .ticket-footer {
        padding: 15px;
        background: rgba(255, 255, 255, 0.5);
    }

    .kds-btn {
        width: 100%;
        padding: 12px;
        border: none;
        border-radius: 8px;
        font-weight: 800;
        font-size: 1.1em;
        cursor: pointer;
        transition: background 0.2s;
        text-transform: uppercase;
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 10px;
    }

    .btn-start {
        background: #fef3c7;
        color: #92400e;
    }

    .btn-start:hover {
        background: #fde68a;
    }

    .btn-finish {
        background: #10b981;
        color: white;
    }

    .btn-finish:hover {
        background: #059669;
    }

    /* Misc */
    .logout-pill {
        /* Keep existing style if needed or redefine lightly */
        display: flex;
        align-items: center;
        gap: 8px;
        background: white;
        border: 1px solid #e5e7eb;
        padding: 8px 16px;
        border-radius: 20px;
        text-decoration: none;
        color: #ef4444;
        font-weight: 600;
    }
    
    .kds-tabs {
        display: flex;
        gap: 10px;
        background: white;
        padding: 5px;
        border-radius: 8px;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .kds-tab {
        border: none;
        background: transparent;
        padding: 8px 16px;
        border-radius: 6px;
        font-weight: 600;
        cursor: pointer;
        color: #6b7280;
        transition: all 0.2s;
    }
    .kds-tab.active {
        background: #f3f4f6;
        color: #1f2937;
        box-shadow: 0 1px 2px rgba(0,0,0,0.05);
    }
    .status-badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 4px;
        font-size: 0.75em;
        font-weight: 700;
        text-transform: uppercase;
        margin-top: 4px;
    }
    .status-badge.preparing {
        background: #dbeafe;
        color: #1e40af;
    }
    .status-badge.pending {
        background: #fef3c7;
        color: #92400e;
    }

<script>
    function switchTab(tabName) {
        // Tabs UI
        document.querySelectorAll('.kds-tab').forEach(t => t.classList.remove('active'));
        event.target.classList.add('active');

        // Content
        document.querySelectorAll('.tab-content').forEach(c => c.style.display = 'none');
        document.getElementById('tab-' + tabName).style.display = 'block';

        // Persist tab selection via session storage if desired (optional)
        sessionStorage.setItem('kds_tab', tabName);
    }
    
    // Restore tab on load
    document.addEventListener('DOMContentLoaded', () => {
        const savedTab = sessionStorage.getItem('kds_tab');
        if (savedTab && document.getElementById('tab-'+savedTab)) {
            // Find tab button
            const btn = Array.from(document.querySelectorAll('.kds-tab')).find(b => b.textContent.toLowerCase().includes(savedTab === 'pending' ? 'pendientes' : 'historial'));
            if(btn) btn.click();
        }
    });

    // Live Clock & SLA Tracker
    setInterval(() => {
        const now = new Date();
        const timeString = now.toLocaleTimeString('es-ES', { hour: '2-digit', minute: '2-digit' });
        document.getElementById('clock').textContent = timeString;
        
        // SLA Updater
        document.querySelectorAll('.timer-badge').forEach(badge => {
            const startTime = parseInt(badge.getAttribute('data-time'));
            if (startTime) {
                const elapsedMins = Math.floor((now.getTime() - startTime) / 60000);
                const minSpan = badge.querySelector('.min-val');
                if(minSpan) minSpan.textContent = elapsedMins;
                
                const ticket = badge.closest('.kds-ticket');
                if (elapsedMins >= 15 && !ticket.classList.contains('is-urgent')) {
                    ticket.classList.remove('is-warning');
                    ticket.classList.add('is-urgent');
                    let urgentBadge = ticket.querySelector('.urgent-badge');
                    if (!urgentBadge) {
                        const container = badge.closest('.urgent-container');
                        urgentBadge = document.createElement('div');
                        urgentBadge.className = 'urgent-badge';
                        urgentBadge.innerHTML = '🔥 TARDE';
                        container.appendChild(urgentBadge);
                    }
                } else if (elapsedMins >= 10 && elapsedMins < 15 && !ticket.classList.contains('is-urgent')) {
                    if (!ticket.classList.contains('is-warning')) {
                        ticket.classList.add('is-warning');
                    }
                }
            }
        });
    }, 1000);

    // Auto-refresh every 15 seconds to catch new orders
    // In a real app we'd use WebSockets, but this works for now
    setTimeout(() => location.reload(), 15000);
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>