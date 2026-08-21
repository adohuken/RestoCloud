<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}


// Check module access (will redirect if not authorized)
checkModuleAccess($pdo, $_SESSION['role_id'], 'tables');

// Check if there's ANY active cash register (not just user's own)
$stmt = $pdo->query('SELECT * FROM cash_register WHERE type = "open" AND status = "active" ORDER BY date_created DESC LIMIT 1');
$active_register = $stmt->fetch();

// Handle POST requests for order status updates (Form Fallback)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $order_id = $_POST['order_id'] ?? 0;

    if ($_POST['action'] === 'pickup_order') {
        $stmt = $pdo->prepare('UPDATE orders SET status = "picked_up" WHERE id = ? AND status = "ready"');
        $stmt->execute([$order_id]);
        header('Location: mesas.php?success=picked_up');
        exit();
    }

    if ($_POST['action'] === 'deliver_order') {
        $stmt = $pdo->prepare('UPDATE orders SET status = "delivered" WHERE id = ? AND status = "picked_up"');
        $stmt->execute([$order_id]);
        header('Location: mesas.php?success=delivered');
        exit();
    }
}

// Handle AJAX requests for order status updates
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    if ($_GET['ajax'] === 'pickup_order') {
        $order_id = $_POST['order_id'] ?? 0;
        $stmt = $pdo->prepare('UPDATE orders SET status = "picked_up" WHERE id = ? AND status = "ready"');
        $stmt->execute([$order_id]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
        exit();
    }

    if ($_GET['ajax'] === 'deliver_order') {
        $order_id = $_POST['order_id'] ?? 0;
        $stmt = $pdo->prepare('UPDATE orders SET status = "delivered" WHERE id = ? AND status = "picked_up"');
        $stmt->execute([$order_id]);
        echo json_encode(['success' => $stmt->rowCount() > 0]);
        exit();
    }

    if ($_GET['ajax'] === 'get_order_details') {
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

        echo json_encode(['items' => $items, 'total' => $total]);
        exit();
    }


}

// Get all tables with their current orders (only one order per table - the most recent active one)
// Exclude "Barra" table - it's reserved for libre orders
$stmt = $pdo->query('
    SELECT t.*, 
           o.id as order_id, 
           o.total as order_total,
           o.status as order_status,
           o.user_id as order_user_id,
           u.name as waiter_name
    FROM tables t
    LEFT JOIN (
        SELECT o1.* FROM orders o1
        WHERE o1.status IN ("draft", "pending", "ready", "preparing", "picked_up", "delivered")
        AND o1.id = (
            SELECT MAX(o2.id) FROM orders o2 
            WHERE o2.table_id = o1.table_id 
            AND o2.status IN ("draft", "pending", "ready", "preparing", "picked_up", "delivered")
        )
    ) o ON t.id = o.table_id
    LEFT JOIN users u ON o.user_id = u.id
    WHERE t.name != "Barra" AND t.name NOT LIKE "Barra - %"
    ORDER BY LENGTH(t.name), t.name
');
$tables = $stmt->fetchAll();

// Get bar seats (tables with prefix "Barra - ")
$stmt = $pdo->query('
    SELECT t.*, 
           o.id as order_id, 
           o.total as order_total,
           o.status as order_status,
           o.user_id as order_user_id,
           u.name as waiter_name
    FROM tables t
    LEFT JOIN (
        SELECT o1.* FROM orders o1
        WHERE o1.status IN ("draft", "pending", "ready", "preparing", "picked_up", "delivered")
        AND o1.id = (
            SELECT MAX(o2.id) FROM orders o2 
            WHERE o2.table_id = o1.table_id 
            AND o2.status IN ("draft", "pending", "ready", "preparing", "picked_up", "delivered")
        )
    ) o ON t.id = o.table_id
    LEFT JOIN users u ON o.user_id = u.id
    WHERE t.name LIKE "Barra - %"
    ORDER BY LENGTH(t.name), t.name
');
$barra_seats = $stmt->fetchAll();

// Count ready orders for notification badge
$stmt = $pdo->query('SELECT COUNT(*) FROM orders WHERE status = "ready"');
$ready_orders_count = $stmt->fetchColumn();

// Check if user has access to pedido_libre module
$has_free_orders_access = false;
try {
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM role_modules rm JOIN modules m ON rm.module_id = m.id WHERE rm.role_id = ? AND m.module_key = "pedido_libre"');
    $stmt->execute([$_SESSION['role_id']]);
    $has_free_orders_access = $stmt->fetchColumn() > 0;
} catch (Exception $e) {
    // If modules table doesn't exist, allow access by default for admin roles
    $has_free_orders_access = in_array($_SESSION['role_id'], [1, 2, 3, 5]);
}

// Get free orders (orders from "Barra" table - the virtual table for libre orders)
$free_orders = [];
if ($has_free_orders_access) {
    $stmt = $pdo->query('
        SELECT o.*, u.name as waiter_name
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.id
        JOIN tables t ON o.table_id = t.id
        WHERE t.name = "Barra"
        AND o.status IN ("draft", "pending", "ready", "preparing", "picked_up", "delivered")
        ORDER BY o.date_created DESC
    ');
    $free_orders = $stmt->fetchAll();
}

// Check if user has access to pedidosya module
$has_pedidosya_access = false;
// Always allow for Admin (1) and SuperAdmin (5) and Kitchen (3) if needed
if (in_array($_SESSION['role_id'], [1, 3, 5])) {
    $has_pedidosya_access = true;
} else {
    try {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM role_modules rm JOIN modules m ON rm.module_id = m.id WHERE rm.role_id = ? AND m.module_key = "pedidosya"');
        $stmt->execute([$_SESSION['role_id']]);
        $has_pedidosya_access = $stmt->fetchColumn() > 0;
    } catch (Exception $e) {
        $has_pedidosya_access = false;
    }
}

// Get pending PedidosYa orders
$pedidosya_orders = [];
if ($has_pedidosya_access) {
    $stmt = $pdo->query('
        SELECT * FROM pedidosya_orders 
        WHERE status IN ("pending", "preparing", "ready")
        ORDER BY date_created DESC
    ');
    $pedidosya_orders = $stmt->fetchAll();
}

// Get user's role name
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';
// Intercept mobile mesero sessions
if (isset($_SESSION['device_type']) && $_SESSION['device_type'] === 'mobile' && (isset($_SESSION['role_name']) && $_SESSION['role_name'] === 'mesero')) {
    require_once __DIR__ . '/mesas_mobile.php';
    exit();
}
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php if ($_SESSION['role_id'] == 1 || $_SESSION['role_id'] == 5 || $_SESSION['role_id'] == 2): ?>
        <!-- Admin sidebar -->
        <?php include __DIR__ . '/includes/sidebar.php'; ?>
    <?php endif; ?>

    <main class="main-content">
        <?php $current_tab = $_GET['tab'] ?? 'mesas'; ?>
        <div class="fc-header" style="margin-bottom: 30px;">
            <div class="fc-header-left">
                <?php if ($current_tab === 'barra'): ?>
                    <h1><i class='bx bx-shopping-bag'></i> Gestión de Barra</h1>
                    <p>Atención en mostrador y pedidos rápidos</p>
                <?php else: ?>
                    <h1><i class='bx bx-grid-alt'></i> Gestión de Mesas</h1>
                    <p>Monitoreo y atención en tiempo real</p>
                <?php endif; ?>
            </div>
            <div class="fc-header-right">
                <div class="fc-user-pill">
                    <div class="fc-user-avatar"><?= strtoupper(substr($_SESSION['name'] ?? 'U', 0, 1)) ?></div>
                    <div class="fc-user-info">
                        <span class="name"><?= htmlspecialchars($_SESSION['name'] ?? 'Usuario') ?></span>
                        <span class="role"><?= htmlspecialchars($user_role_name) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($_GET['success']) && $_GET['success'] === 'payment_completed'): ?>
            <div class="alert alert-success">
                ✅ Pago procesado exitosamente
            </div>
        <?php endif; ?>
        
        <?php if (!$active_register): ?>
            <div class="alert alert-warning">
                ⚠️ Debe abrir la caja antes de tomar pedidos.
                <?php if ($_SESSION['role_id'] == 1): ?>
                    <a href="caja.php" style="color: var(--primary); font-weight: 600;">Ir a Caja</a>
                <?php else: ?>
                    <span style="font-weight: 600;">Contacte al Cajero o Superadmin.</span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Tab: Mesas -->
        <div id="tab-mesas" class="orders-tab-content <?= $current_tab === 'mesas' ? 'active' : '' ?>">
            <div class="tables-grid">
                <?php if (empty($tables)): ?>
                    <div style="grid-column: 1 / -1; text-align: center; padding: 60px 20px; color: var(--fc-text-sec); background: #ffffff; border-radius: 16px; border: 1px dashed var(--fc-border);">
                        <i class='bx bx-info-circle' style="font-size: 32px; opacity: 0.5; margin-bottom: 10px;"></i>
                        <p>No hay mesas configuradas en el sistema.</p>
                        <a href="configuracion.php#tables" class="fc-btn fc-btn-outline" style="margin-top: 15px; display: inline-flex;">Ir a configuración para añadirlas</a>
                    </div>
                <?php else: ?>
                    <?php foreach ($tables as $table):
                    $status = $table['order_status'] ?? null;
                    $cardClass = 'available';
                    $icon = 'bx-chair';
                    
                    if ($table['order_id']) {
                        if ($status === 'ready') {
                            $cardClass = 'ready';
                            $icon = 'bx-bell';
                        } elseif ($status === 'draft') {
                            $cardClass = 'draft';
                            $icon = 'bx-edit-alt';
                        } else {
                            $cardClass = 'occupied';
                            $icon = 'bx-restaurant';
                        }
                    }
                    $is_locked_for_me = false;
                    if ($table['order_id'] && $_SESSION['role_id'] == 2) {
                        if ($table['order_user_id'] != $_SESSION['user_id']) {
                            $is_locked_for_me = true;
                            $cardClass = 'occupied'; // Force occupied color
                        }
                    }
                    ?>
                    <div class="table-card table-<?= $cardClass ?>" data-order-id="<?= $table['order_id'] ?? '' ?>" style="<?= $is_locked_for_me ? 'opacity: 0.7;' : '' ?>">
                        <div class="table-icon">
                            <i class='bx <?= $is_locked_for_me ? 'bx-lock' : $icon ?>'></i>
                        </div>
                        <div class="table-name"><?= htmlspecialchars($table['name']) ?></div>
                        <div class="table-status">
                            <?php if ($table['order_id']): ?>
                                <?php 
                                    $status_map = [
                                        'draft' => 'Tomando pedido',
                                        'pending' => 'En cocina',
                                        'preparing' => 'Preparando',
                                        'ready' => '¡Listo p/ Servir!',
                                        'picked_up' => 'Recogido',
                                        'delivered' => 'Servido'
                                    ];
                                    echo $status_map[$status] ?? 'Ocupada';
                                ?>
                                <div style="font-size: 0.75rem; margin-top: 4px; color: var(--fc-text-sec); display: flex; align-items: center; justify-content: center; gap: 4px;">
                                    <i class='bx bxs-user-badge'></i> <?= htmlspecialchars($table['waiter_name'] ?? 'Mesero') ?>
                                </div>
                            <?php else: ?>
                                Disponible
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($table['order_id']): ?>
                            <div class="table-total">
                                C$<?= number_format($table['order_total'], 0) ?>
                            </div>
                        <?php endif; ?>

                        <div class="table-actions">
                            <?php if ($is_locked_for_me): ?>
                                <button class="fc-btn fc-btn-outline fc-w100" style="height: 48px; opacity: 0.6; cursor: not-allowed; border: 1px solid var(--fc-border);" disabled>
                                    <i class='bx bx-lock-alt'></i> EN USO
                                </button>
                            <?php elseif ($table['order_id']): ?>
                                <?php if ($status === 'ready'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="pickup_order">
                                        <input type="hidden" name="order_id" value="<?= $table['order_id'] ?>">
                                        <button type="submit" class="fc-btn fc-btn-primary fc-w100" style="height: 45px;">
                                            <i class='bx bx-check-double'></i> SERVIR
                                        </button>
                                    </form>
                                <?php elseif ($status === 'picked_up'): ?>
                                    <form method="POST">
                                        <input type="hidden" name="action" value="deliver_order">
                                        <input type="hidden" name="order_id" value="<?= $table['order_id'] ?>">
                                        <button type="submit" class="fc-btn fc-btn-primary fc-w100" style="height: 45px;">
                                            <i class='bx bx-check'></i> FINALIZAR
                                        </button>
                                    </form>
                                <?php else: ?>
                                    <a href="venta.php?table=<?= $table['id'] ?>" class="fc-btn fc-btn-primary fc-w100" style="height: 48px; background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border: 1px solid var(--fc-border);">
                                        <i class='bx <?= $status === 'draft' ? 'bx-edit' : 'bx-plus' ?>'></i> 
                                        <?= $status === 'draft' ? 'COMPLETAR' : 'PEDIR MÁS' ?>
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <?php if ($active_register): ?>
                                    <a href="venta.php?table=<?= $table['id'] ?>" class="fc-btn fc-btn-primary fc-w100" style="height: 45px;">
                                        <i class='bx bx-plus-circle'></i> ABRIR MESA
                                    </a>
                                <?php else: ?>
                                    <button class="fc-btn fc-btn-outline fc-w100" style="height: 45px; opacity: 0.5;" disabled>
                                        <i class='bx bx-lock-alt'></i> CERRADO
                                    </button>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div> <!-- End Tab: Mesas -->

        <!-- Tab: Barra -->
        <?php if ($has_free_orders_access): ?>
            <div id="tab-barra" class="orders-tab-content <?= $current_tab === 'barra' ? 'active' : '' ?>">
                
                <!-- Sección de Mostrador (Takeaway / Libre) -->
                <div class="fc-card" style="padding: 25px; margin-bottom: 35px; border: 1px solid var(--fc-border); background: #ffffff; box-shadow: 0 4px 20px rgba(0,0,0,0.03);">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 20px; <?= count($free_orders) > 0 ? 'margin-bottom: 25px; padding-bottom: 20px; border-bottom: 1px solid var(--fc-border);' : '' ?>">
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <div style="width: 48px; height: 48px; background: rgba(79, 70, 229, 0.1); color: var(--fc-primary); border-radius: 14px; display: flex; align-items: center; justify-content: center; font-size: 24px;">
                                <i class='bx bx-shopping-bag'></i>
                            </div>
                            <div>
                                <h4 style="margin: 0; color: var(--fc-text-main); font-size: 18px; font-weight: 700;">Mostrador / Takeaway</h4>
                                <p style="margin: 4px 0 0 0; font-size: 13px; color: var(--fc-text-sec);">Para ventas rápidas y clientes sin asiento.</p>
                            </div>
                        </div>
                        <?php if ($active_register): ?>
                            <a href="venta.php?libre=1" class="fc-btn fc-btn-primary" style="padding: 0 24px; height: 46px; border-radius: 12px; font-weight: 600; box-shadow: 0 4px 12px rgba(79, 70, 229, 0.2);">
                                <i class='bx bx-plus' style="font-size: 20px;"></i> Nueva Venta
                            </a>
                        <?php else: ?>
                            <button class="fc-btn fc-btn-outline" style="padding: 0 24px; height: 46px; border-radius: 12px; font-weight: 600; opacity: 0.5; cursor: not-allowed;" disabled>
                                <i class='bx bx-lock-alt' style="font-size: 20px;"></i> Caja Cerrada
                            </button>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Mostrar ventas libres activas aquí -->
                    <?php if (count($free_orders) > 0): ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 15px;">
                            <?php foreach ($free_orders as $fo): ?>
                                <div style="background: #f8fafc; padding: 16px; border-radius: 16px; border: 1px solid #e2e8f0; display: flex; flex-direction: column; gap: 15px; transition: all 0.2s ease;">
                                    <div style="display: flex; justify-content: space-between; align-items: flex-start;">
                                        <div>
                                            <span style="background: #ffffff; color: var(--fc-primary); padding: 4px 10px; border-radius: 8px; font-size: 12px; font-weight: 800; letter-spacing: 0.5px; border: 1px solid var(--fc-border); display: inline-block; margin-bottom: 8px;">
                                                PEDIDO #<?= $fo['id'] ?>
                                            </span>
                                            <div style="font-size: 12px; color: var(--fc-text-sec); display: flex; align-items: center; gap: 5px;">
                                                <i class='bx bx-user' style="color: #94a3b8;"></i> <?= htmlspecialchars($fo['waiter_name'] ?? 'Usuario') ?>
                                            </div>
                                        </div>
                                        <div style="font-weight: 800; font-size: 18px; color: var(--fc-text-main);">
                                            C$<?= number_format($fo['total'], 0) ?>
                                        </div>
                                    </div>
                                    
                                    <a href="venta.php?libre=<?= $fo['id'] ?>" class="fc-btn fc-btn-outline" style="width: 100%; justify-content: center; height: 38px; font-size: 13px; border-radius: 10px; background: white;">
                                        Ver / Cobrar
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

            <?php if (hasModuleAccess($pdo, $_SESSION['role_id'], 'ver_asientos_barra')): ?>
                <div style="margin-top: 40px;">
                    <h3 style="margin-bottom: 20px; font-size: 18px; display: flex; align-items: center; gap: 8px;">
                        <i class='bx bx-chair' style="color: var(--fc-primary);"></i> Asientos en Barra
                    </h3>

                    <div class="tables-grid">
                        <?php if (empty($barra_seats)): ?>
                            <div style="grid-column: 1 / -1; text-align: center; padding: 40px; background: rgba(255,255,255,0.5); border-radius: 20px; border: 1px dashed var(--fc-border); color: var(--fc-text-sec);">
                                <i class='bx bx-info-circle' style="font-size: 32px; opacity: 0.5; margin-bottom: 10px;"></i>
                                <p>No hay asientos configurados en la barra.</p>
                                <?php if ($_SESSION['role_id'] == 1 || $_SESSION['role_id'] == 5): ?>
                                    <a href="configuracion.php?tab=tables" style="color: var(--fc-primary); text-decoration: none; font-weight: 600;">Ir a configuración para añadirlos</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>

                        <?php foreach ($barra_seats as $table):
                            $status = $table['order_status'] ?? null;
                            $cardClass = 'available';
                            $icon = 'bx-user';
                            
                            if ($table['order_id']) {
                                if ($status === 'ready') {
                                    $cardClass = 'ready';
                                    $icon = 'bx-bell';
                                } elseif ($status === 'draft') {
                                    $cardClass = 'draft';
                                    $icon = 'bx-edit-alt';
                                } else {
                                    $cardClass = 'occupied';
                                    $icon = 'bx-restaurant';
                                }
                            }
                            
                            // Extraer solo la parte "Asiento X" del nombre
                            $display_name = str_replace('Barra - ', '', $table['name']);
                            ?>
                            <div class="table-card table-<?= $cardClass ?>" data-order-id="<?= $table['order_id'] ?? '' ?>">
                                <div class="table-icon">
                                    <i class='bx <?= $icon ?>'></i>
                                </div>
                                <div class="table-name"><?= htmlspecialchars($display_name) ?></div>
                                <div class="table-status">
                                    <?php if ($table['order_id']): ?>
                                        <?php 
                                            $status_map = [
                                                'draft' => 'Tomando pedido',
                                                'pending' => 'En cocina',
                                                'preparing' => 'Preparando',
                                                'ready' => '¡Listo p/ Servir!',
                                                'picked_up' => 'Recogido',
                                                'delivered' => 'Servido'
                                            ];
                                            echo $status_map[$status] ?? 'Ocupado';
                                        ?>
                                    <?php else: ?>
                                        Disponible
                                    <?php endif; ?>
                                </div>
                                
                                <?php if ($table['order_id']): ?>
                                    <div class="table-total">
                                        C$<?= number_format($table['order_total'], 0) ?>
                                    </div>
                                <?php endif; ?>

                                <div class="table-actions">
                                    <?php if ($table['order_id']): ?>
                                        <?php if ($status === 'ready'): ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="pickup_order">
                                                <input type="hidden" name="order_id" value="<?= $table['order_id'] ?>">
                                                <button type="submit" class="fc-btn fc-btn-primary fc-w100" style="height: 45px;">
                                                    <i class='bx bx-check-double'></i> SERVIR
                                                </button>
                                            </form>
                                        <?php elseif ($status === 'picked_up'): ?>
                                            <form method="POST">
                                                <input type="hidden" name="action" value="deliver_order">
                                                <input type="hidden" name="order_id" value="<?= $table['order_id'] ?>">
                                                <button type="submit" class="fc-btn fc-btn-primary fc-w100" style="height: 45px;">
                                                    <i class='bx bx-check'></i> FINALIZAR
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <a href="venta.php?table=<?= $table['id'] ?>" class="fc-btn fc-btn-primary fc-w100" style="height: 48px; background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%); border: 1px solid var(--fc-border);">
                                                <i class='bx <?= $status === 'draft' ? 'bx-edit' : 'bx-plus' ?>'></i> 
                                                <?= $status === 'draft' ? 'COMPLETAR' : 'PEDIR MÁS' ?>
                                            </a>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <?php if ($active_register): ?>
                                            <a href="venta.php?table=<?= $table['id'] ?>" class="fc-btn fc-btn-primary fc-w100" style="height: 45px;">
                                                <i class='bx bx-plus-circle'></i> ASIGNAR
                                            </a>
                                        <?php else: ?>
                                            <button class="fc-btn fc-btn-outline fc-w100" style="height: 45px; opacity: 0.5;" disabled>
                                                <i class='bx bx-lock-alt'></i> CERRADO
                                            </button>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
        </div> <!-- End Tab: Barra -->
    <?php endif; ?>

    <!-- Separated Views: Barra and PedidosYa now load in their own pages instead of iframes -->

    </main>
</div>

<style>
    .form-margin { margin-bottom: 5px; }
    .btn-full { width: 100%; }
    .spacer { flex: 1; }
    .tab-full-height { height: calc(100vh - 140px); margin: -20px; }
    .iframe-full { width: 100%; height: 100%; border: none; }
    .refresh-btn { position: absolute; top: 10px; right: 20px; z-index: 100; background: white; border: 1px solid #ddd; border-radius: 50%; width: 40px; height: 40px; cursor: pointer; display: flex; align-items: center; justify-content: center; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
    .modal-width { max-width: 500px; width: 100%; }
    .close-btn { background:none;border:none;font-size:24px;cursor:pointer; }
    .modal-text-left { text-align: left; }
    .modal-controls { margin-bottom: 20px; display: flex; gap: 10px; }
    .modal-table-container { max-height: 400px; overflow-y: auto; }
    .text-right { text-align: right; }
    .text-muted { font-size: 12px; color: #64748b; }

    /* Orders Navbar Tabs */
    .orders-navbar {
        display: flex;
        gap: 12px;
        margin-bottom: 30px;
        background: rgba(255, 255, 255, 0.03);
        padding: 10px;
        border-radius: 16px;
        border: 1px solid var(--fc-border);
        width: fit-content;
    }

    .orders-tab {
        padding: 12px 24px;
        background: transparent;
        border: none;
        border-radius: 12px;
        font-size: 15px;
        font-weight: 600;
        color: #64748b;
        cursor: pointer;
        transition: all 0.2s ease;
        display: flex;
        align-items: center;
        gap: 8px;
        text-decoration: none;
    }

    .orders-tab:hover {
        background: #f1f5f9;
        color: #334155;
    }

    .orders-tab.active {
        background: #eff6ff;
        color: #2563eb;
    }

    .orders-tab .badge-count {
        background: #ef4444;
        color: white;
        font-size: 12px;
        padding: 2px 8px;
        border-radius: 12px;
        min-width: 20px;
        text-align: center;
        box-shadow: 0 2px 4px rgba(239, 68, 68, 0.2);
    }

    .orders-tab-content {
        display: none;
        animation: fadeIn 0.3s ease;
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

    .orders-tab-content.active {
        display: block;
    }

    .tables-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
        gap: 25px;
        padding: 10px 0;
    }

    .table-card {
        background: var(--fc-card-bg);
        backdrop-filter: var(--fc-glass-blur);
        border-radius: 24px;
        padding: 30px;
        text-align: center;
        border: 1px solid var(--fc-border);
        transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        position: relative;
        overflow: hidden;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
        min-height: 240px;
    }

    .table-card:hover {
        transform: translateY(-8px);
        box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
        border-color: rgba(225, 29, 72, 0.3);
    }

    /* Status Colors */
    .table-available {
        border-top: 5px solid #10b981;
    }

    .table-available .table-icon {
        background: rgba(16, 185, 129, 0.1);
        color: #10b981;
    }

    .table-occupied {
        border-top: 5px solid #f59e0b;
    }

    .table-occupied .table-icon {
        background: rgba(245, 158, 11, 0.1);
        color: #f59e0b;
    }

    .table-draft {
        border-top: 5px solid #3b82f6;
    }

    .table-draft .table-icon {
        background: rgba(59, 130, 246, 0.1);
        color: #3b82f6;
    }

    .table-ready {
        border-top: 5px solid #8b5cf6;
        animation: pulseBorder 2s infinite;
    }

    @keyframes pulseBorder {
        0% {
            box-shadow: 0 0 0 0 rgba(139, 92, 246, 0.4);
        }

        70% {
            box-shadow: 0 0 0 10px rgba(139, 92, 246, 0);
        }

        100% {
            box-shadow: 0 0 0 0 rgba(139, 92, 246, 0);
        }
    }

    .table-ready .table-icon {
        background: #ede9fe;
        color: #7c3aed;
    }

    .table-icon {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 28px;
        margin: 0 auto 15px;
        transition: transform 0.3s;
    }

    .table-card:hover .table-icon {
        transform: scale(1.1) rotate(5deg);
    }

    .table-name {
        font-size: 22px;
        font-weight: 700;
        color: var(--fc-text-main);
        margin-bottom: 8px;
    }

    .table-status {
        font-size: 13px;
        margin-bottom: 25px;
        color: var(--fc-text-sec);
        font-weight: 600;
        background: rgba(255, 255, 255, 0.03);
        padding: 8px 16px;
        border-radius: 25px;
        display: inline-block;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .table-total {
        font-size: 28px;
        font-weight: 800;
        color: var(--fc-primary-hover);
        margin-bottom: 25px;
        font-family: 'Outfit', sans-serif;
        text-shadow: 0 0 20px rgba(225, 29, 72, 0.2);
    }

    .table-actions {
        display: flex;
        flex-direction: column;
        gap: 10px;
        margin-top: auto;
    }

    .btn-sm {
        padding: 10px;
        font-size: 14px;
        border-radius: 10px;
        font-weight: 600;
        letter-spacing: 0.5px;
        text-transform: uppercase;
    }
</style>

<script>



    // Clear legacy tab state if it exists
    if (localStorage.getItem('mesasActiveTab')) {
        localStorage.removeItem('mesasActiveTab');
    }

    function pickupOrder(orderId, btn) {
        if (!btn) btn = event.target;
        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.innerHTML = '⏳...';

        fetch('?ajax=pickup_order', {

            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            }

            ,
            body: 'order_id=' + orderId

        }).then(r => r.json()).then(data => {
            if (data.success) {
                location.reload();
            }

            else {
                btn.disabled = false;
                btn.innerHTML = originalText;
                alert('Error al recoger pedido: ' + (data.message || 'Intente de nuevo'));
            }

        }).catch(err => {
            console.error(err);
            btn.disabled = false;
            btn.innerHTML = originalText;
            alert('Error de conexión');
        });
    }

    function deliverOrder(orderId, btn) {
        if (!btn) btn = event.target;
        btn.disabled = true;
        const originalText = btn.innerHTML;
        btn.innerHTML = '⏳...';

        fetch('?ajax=deliver_order', {

            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded'
            }

            ,
            body: 'order_id=' + orderId

        }).then(r => r.json()).then(data => {
            if (data.success) {
                location.reload();
            }

            else {
                btn.disabled = false;
                btn.innerHTML = originalText;
                alert('Error al marcar como entregado');
            }

        }).catch(err => {
            console.error(err);
            btn.disabled = false;
            btn.innerHTML = originalText;
            alert('Error de conexión');
        });
    }

    // Auto-refresh page every 30 seconds to check for ready orders
    setTimeout(() => location.reload(), 30000);

    function showOrderSummary(orderId) {
        Swal.fire({
            title: 'Resumen de Cuenta',
            html: '<div id="summary-content">Cargando...</div>',
            showCloseButton: true,
            showConfirmButton: false,
            width: '600px'
        });

        fetch(`mesas.php?ajax=get_order_details&order_id=${orderId}`)
            .then(response => response.json())
            .then(data => {
                let html = '<table style="width:100%; text-align:left; border-collapse: collapse;">';
                html += '<tr style="border-bottom:1px solid #eee;"><th style="padding:8px;">Prod</th><th style="padding:8px;">Cant</th><th style="text-align:right;padding:8px;">Total</th></tr>';

                if (data.items) {
                    data.items.forEach(item => {
                        let itemTotal = item.quantity * item.price;
                        html += `<tr>
                            <td style="padding:8px;">${item.name}</td>
                            <td style="padding:8px;">${item.quantity}</td>
                            <td style="text-align:right;padding:8px;">C$ ${parseFloat(itemTotal).toFixed(2)}</td>
                        </tr>`;
                    });
                }

                html += `<tr style="border-top:2px solid #ddd; font-weight:bold;">
                    <td colspan="2" style="padding:10px;">TOTAL</td>
                    <td style="text-align:right;padding:10px;">C$ ${parseFloat(data.total || 0).toFixed(2)}</td>
                </tr>`;
                html += '</table>';

                document.getElementById('summary-content').innerHTML = html;
            })
            .catch(err => {
                document.getElementById('summary-content').innerHTML = '<span style="color:red">Error al cargar detalles</span>';
                console.error(err);
            });
    }
</script><?php include __DIR__ . '/includes/footer.php'; ?>