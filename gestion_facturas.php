<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Check module access
checkModuleAccess($pdo, $_SESSION['role_id'], 'config_invoices_manage');

// Handle Deletion (Cancel Invoice)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_invoice'])) {
    $invoice_id = $_POST['invoice_id'];
    $order_id = $_POST['order_id'];
    $deletion_reason = trim($_POST['deletion_reason'] ?? '');

    if (empty($deletion_reason)) {
        $error_msg = 'Debe ingresar un motivo para eliminar la factura.';
    } else {
        try {
            $pdo->beginTransaction();

            // 0. Log Deletion with reason
            $stmt = $pdo->prepare('SELECT * FROM invoices WHERE id = ?');
            $stmt->execute([$invoice_id]);
            $inv_data = $stmt->fetch();

            if ($inv_data) {
                $logStmt = $pdo->prepare('INSERT INTO deleted_invoices_log (original_invoice_id, order_id, amount, payment_method, deleted_by, reason) VALUES (?, ?, ?, ?, ?, ?)');
                $logStmt->execute([
                    $inv_data['id'],
                    $inv_data['order_id'],
                    $inv_data['total'],
                    $inv_data['payment_method'],
                    $_SESSION['user_id'],
                    $deletion_reason
                ]);
            }

        // 1. Delete Invoice
        $stmt = $pdo->prepare('DELETE FROM invoices WHERE id = ?');
        $stmt->execute([$invoice_id]);

        // 2. Delete Payment (money out)
        $stmt = $pdo->prepare('DELETE FROM payments WHERE order_id = ?');
        $stmt->execute([$order_id]);

        // 3. Set order status to 'cancelled' (or 'pending' if we want to allow re-billing, 
        // but 'cancelled' is safer to stop it from floating around. User said "eliminar", 
        // usually implies voiding the transaction).
        // Let's set it to 'cancelled' to keep record of the attempt, or 'pending' if they want to fix it.
        // Given the requirement "editar o eliminar", often "eliminar" means "it was a mistake, remove it".
        // If I delete the payment, the order is effectively unpaid. 
        // If I set it to 'pending', it goes back to the cashier logic.
        // Let's set it to 'pending' so it can be re-billed or cancelled from there.
        $stmt = $pdo->prepare('UPDATE orders SET status = "pending" WHERE id = ?');
        $stmt->execute([$order_id]);

        // 4. Update table status? If order is pending, table should be 'occupied' again?
        // Check if table exists
        $stmt = $pdo->prepare('UPDATE tables t JOIN orders o ON t.id = o.table_id SET t.status = "occupied" WHERE o.id = ?');
        $stmt->execute([$order_id]);

        $pdo->commit();
        $success_msg = 'Factura eliminada y pedido revertido a pendiente.';
        } catch (Exception $e) {
            $pdo->rollBack();
            $error_msg = 'Error al eliminar: ' . $e->getMessage();
        }
    }
}

// Get invoices with filters
$start_date = $_GET['start_date'] ?? date('Y-m-d');
$end_date = $_GET['end_date'] ?? date('Y-m-d');
$search_id = $_GET['search_id'] ?? '';

$sql = "SELECT i.*, o.id as order_id FROM invoices i JOIN orders o ON i.order_id = o.id WHERE 1=1";
$params = [];

if ($start_date && $end_date) {
    $sql .= " AND DATE(i.date_created) BETWEEN ? AND ?";
    $params[] = $start_date;
    $params[] = $end_date;
}

if ($search_id) {
    $sql .= " AND (i.id = ? OR i.order_id = ?)";
    $params[] = $search_id;
    $params[] = $search_id;
}

$sql .= " ORDER BY i.date_created DESC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$invoices = $stmt->fetchAll();

// User role name
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="fc-header" style="margin-bottom: 30px;">
            <div class="fc-header-left">
                <a href="configuracion.php?tab=invoicing" class="fc-btn" style="display: inline-flex; align-items: center; gap: 8px; padding: 10px 20px; font-size: 13px; font-weight: 700; border-radius: 10px; margin-bottom: 20px; height: auto; text-decoration: none; background: #6366f1; color: white !important; box-shadow: 0 4px 12px rgba(99, 102, 241, 0.3); transition: all 0.2s ease;"
                   onmouseover="this.style.background='#4f46e5'; this.style.transform='translateY(-1px)';"
                   onmouseout="this.style.background='#6366f1'; this.style.transform='translateY(0)';">
                    <i class='bx bx-left-arrow-alt' style="font-size: 20px; color: white !important;"></i> Volver a Configuración
                </a>
                <h1 style="display: flex; align-items: center; gap: 10px; margin: 0 0 5px 0;"><i class='bx bx-receipt' style="color: var(--fc-primary);"></i> Gestión de Facturas</h1>
                <p style="margin: 0;">Administrar historial de facturación y anulaciones</p>
            </div>
            <div class="fc-header-right">
                <div class="user-profile-header">
                    <div class="user-avatar"><?= strtoupper(substr($_SESSION['name'], 0, 1)) ?></div>
                    <div class="user-details">
                        <span class="user-name"><?= htmlspecialchars($_SESSION['name']) ?></span>
                        <span class="user-role"><?= htmlspecialchars($user_role_name) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($success_msg)): ?>
            <div class="fc-alert fc-alert-success" style="margin-bottom: 20px;">
                <i class='bx bx-check-circle'></i> <?= $success_msg ?>
            </div>
        <?php endif; ?>
        <?php if (isset($error_msg)): ?>
            <div class="fc-alert fc-alert-danger" style="margin-bottom: 20px;">
                <i class='bx bx-error-circle'></i> <?= $error_msg ?>
            </div>
        <?php endif; ?>

        <div class="fc-card" style="margin-bottom: 30px;">
            <div style="padding: 20px; border-bottom: 1px solid var(--fc-border);">
                <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
                    <div class="fc-form-group" style="margin: 0; flex: 1; min-width: 150px;">
                        <label class="fc-label" style="font-size: 11px;">DESDE</label>
                        <input type="date" name="start_date" class="fc-input" value="<?= htmlspecialchars($start_date) ?>">
                    </div>
                    <div class="fc-form-group" style="margin: 0; flex: 1; min-width: 150px;">
                        <label class="fc-label" style="font-size: 11px;">HASTA</label>
                        <input type="date" name="end_date" class="fc-input" value="<?= htmlspecialchars($end_date) ?>">
                    </div>
                    <div class="fc-form-group" style="margin: 0; flex: 1.5; min-width: 200px;">
                        <label class="fc-label" style="font-size: 11px;">BUSCAR ID FACTURA O PEDIDO</label>
                        <input type="text" name="search_id" class="fc-input" placeholder="Ej: 105" value="<?= htmlspecialchars($search_id) ?>">
                    </div>
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="fc-btn fc-btn-primary" style="height: 48px; padding: 0 25px;">
                            <i class='bx bx-search'></i> Filtrar
                        </button>
                        <?php if ($start_date != date('Y-m-d') || $end_date != date('Y-m-d') || $search_id): ?>
                            <a href="gestion_facturas.php" class="fc-btn fc-btn-outline" style="height: 48px; display: flex; align-items: center; justify-content: center; width: 48px; padding: 0;" title="Limpiar Filtros">
                                <i class='bx bx-refresh' style="font-size: 20px;"></i>
                            </a>
                        <?php endif; ?>
                    </div>
                </form>
            </div>

            <div class="fc-table-responsive" style="max-height: 480px; overflow-y: auto;">
                <table class="fc-table" id="invoices-list-table" style="width: 100%; border-collapse: collapse; min-width: 800px;">
                    <thead>
                        <tr style="background: rgba(255, 255, 255, 0.02); border-bottom: 1px solid var(--fc-border);">
                            <th>Factura</th>
                            <th>Pedido</th>
                            <th>Fecha / Hora</th>
                            <th>Referencia</th>
                            <th>Método Pago</th>
                            <th style="text-align: right;">Total</th>
                            <th style="text-align: center;">Acciones</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($invoices)): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 50px; color: var(--fc-text-sec);">
                                    <i class='bx bx-search-alt' style="font-size: 40px; opacity: 0.2; margin-bottom: 10px; display: block;"></i>
                                    No se encontraron facturas en este periodo.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($invoices as $inv): ?>
                                <tr style="border-bottom: 1px solid var(--fc-border); transition: background 0.2s;" onmouseover="this.style.background='rgba(255,255,255,0.01)'" onmouseout="this.style.background='transparent'">
                                    <td style="padding: 15px 20px; font-weight: 700; color: var(--fc-text-main);">#<?= $inv['id'] ?></td>
                                    <td style="padding: 15px 20px;">
                                        <span class="fc-badge fc-badge-outline" style="font-size: 10px;">ID: <?= $inv['order_id'] ?></span>
                                    </td>
                                    <td style="padding: 15px 20px; font-size: 13px;">
                                        <div style="font-weight: 600;"><?= date('d/m/Y', strtotime($inv['date_created'])) ?></div>
                                        <div style="font-size: 11px; color: var(--fc-text-sec);"><?= date('H:i', strtotime($inv['date_created'])) ?> hs</div>
                                    </td>
                                    <td style="padding: 15px 20px; font-size: 13px;">
                                        <i class='bx bx-table' style="color: var(--fc-primary);"></i> <?= htmlspecialchars($inv['table_name'] ?? 'N/A') ?>
                                    </td>
                                    <td style="padding: 15px 20px;">
                                        <span class="fc-badge <?= $inv['payment_method'] === 'cash' ? 'fc-badge-outline' : 'fc-badge-primary' ?>" style="text-transform: capitalize;">
                                            <i class='bx <?= $inv['payment_method'] === 'cash' ? 'bx-money' : 'bx-credit-card' ?>'></i> <?= $inv['payment_method'] ?>
                                        </span>
                                    </td>
                                    <td style="padding: 15px 20px; text-align: right; font-weight: 800; color: var(--fc-primary); font-size: 16px;">
                                        C$<?= number_format($inv['total'], 2) ?>
                                    </td>
                                    <td style="padding: 15px 20px; text-align: center;">
                                        <button onclick="requestDelete(<?= $inv['id'] ?>, <?= $inv['order_id'] ?>)" class="fc-btn" style="background: rgba(225, 29, 72, 0.1); color: var(--fc-primary); height: 36px; width: 36px; padding: 0; border: 1px solid rgba(225, 29, 72, 0.2);" title="Anular Factura">
                                            <i class='bx bx-trash-alt'></i>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Hidden Deletion Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="delete_invoice" value="1">
    <input type="hidden" name="invoice_id" id="form-invoice-id">
    <input type="hidden" name="order_id" id="form-order-id">
    <input type="hidden" name="deletion_reason" id="form-deletion-reason">
</form>

<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    function requestDelete(invoiceId, orderId) {
        Swal.fire({
            title: '¿Anular Factura?',
            text: "Esta acción revertirá el pedido a 'Pendiente' y eliminará el registro de pago. ¿Está seguro?",
            icon: 'warning',
            input: 'textarea',
            inputPlaceholder: 'Escriba el motivo de la anulación aquí...',
            inputAttributes: {
                'aria-label': 'Motivo de la anulación'
            },
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: 'rgba(255,255,255,0.1)',
            confirmButtonText: 'Sí, anular factura',
            cancelButtonText: 'Cancelar',
            background: '#1e293b',
            color: '#f8fafc',
            inputValidator: (value) => {
                if (!value) {
                    return 'Debe ingresar un motivo para anular la factura'
                }
            }
        }).then((result) => {
            if (result.isConfirmed) {
                document.getElementById('form-invoice-id').value = invoiceId;
                document.getElementById('form-order-id').value = orderId;
                document.getElementById('form-deletion-reason').value = result.value;
                document.getElementById('deleteForm').submit();
            }
        });
    }
</script>

<style>
#invoices-list-table th {
    position: sticky;
    top: 0;
    background: var(--fc-card-bg);
    z-index: 2;
    border-bottom: 1px solid var(--fc-border);
}

</style>

<?php include __DIR__ . '/includes/footer.php'; ?>