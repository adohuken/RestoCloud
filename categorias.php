<?php
require_once 'config/db.php';
require_once 'includes/auth.php';
require_once 'includes/permissions.php';

// Check access
if (!hasModuleAccess($pdo, $_SESSION['role_id'], 'inventory_view')) {
    header("Location: sin_acceso.php");
    exit;
}

$success = '';
$error = '';

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        if ($_POST['action'] === 'add' && hasModuleAccess($pdo, $_SESSION['role_id'], 'inventory_edit')) {
            $name = trim($_POST['name'] ?? '');
            if ($name) {
                $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
                if ($stmt->execute([$name])) {
                    $success = "Categoría creada correctamente.";
                } else {
                    $error = "Error al crear la categoría.";
                }
            }
        } elseif ($_POST['action'] === 'edit' && hasModuleAccess($pdo, $_SESSION['role_id'], 'inventory_edit')) {
            $id = $_POST['id'] ?? null;
            $name = trim($_POST['name'] ?? '');
            if ($id && $name) {
                $stmt = $pdo->prepare("UPDATE categories SET name = ? WHERE id = ?");
                if ($stmt->execute([$name, $id])) {
                    $success = "Categoría actualizada correctamente.";
                } else {
                    $error = "Error al actualizar la categoría.";
                }
            }
        } elseif ($_POST['action'] === 'delete' && hasModuleAccess($pdo, $_SESSION['role_id'], 'inventory_delete')) {
            $id = $_POST['id'] ?? null;
            if ($id) {
                // Check if has products
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
                $stmt->execute([$id]);
                if ($stmt->fetchColumn() > 0) {
                    $error = "No se puede eliminar: Esta categoría tiene productos asociados.";
                } else {
                    $stmt = $pdo->prepare("DELETE FROM categories WHERE id = ?");
                    if ($stmt->execute([$id])) {
                        $success = "Categoría eliminada correctamente.";
                    } else {
                        $error = "Error al eliminar la categoría.";
                    }
                }
            }
        }
    }
}

// Fetch categories
$categories = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();

include __DIR__ . '/includes/header.php';
?>

<div class="fc-dashboard">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">
        <div class="fc-header" style="margin-bottom: 30px;">
            <div class="fc-header-left">
                <h1><i class='bx bx-category'></i> Gestión de Categorías</h1>
                <p>Organiza tu menú por grupos lógicos</p>
            </div>
            <div class="fc-header-right">
                <div class="fc-user-pill">
                    <div class="fc-user-avatar"><?= strtoupper(substr($_SESSION['name'], 0, 1)) ?></div>
                    <div class="fc-user-info">
                        <span class="name"><?= htmlspecialchars($_SESSION['name']) ?></span>
                        <span class="role"><?= htmlspecialchars($_SESSION['role_name'] ?? 'Usuario') ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($success): ?>
            <script>Swal.fire({ icon: 'success', title: '¡Éxito!', text: '<?= $success ?>', background: 'var(--fc-bg-dark)', color: 'var(--fc-text-main)' });</script>
        <?php endif; ?>
        <?php if ($error): ?>
            <script>Swal.fire({ icon: 'error', title: 'Error', text: '<?= $error ?>', background: 'var(--fc-bg-dark)', color: 'var(--fc-text-main)' });</script>
        <?php endif; ?>

        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; gap: 15px; flex-wrap: wrap;">
            <div class="fc-tabs">
                 <button class="fc-tab" onclick="window.location.href='productos.php'">
                    <i class='bx bx-list-ul'></i> <span>Productos</span>
                </button>
                <button class="fc-tab active" onclick="window.location.href='categorias.php'">
                    <i class='bx bx-category'></i> <span>Categorías</span>
                </button>
            </div>
            
            <button class="fc-btn fc-btn-primary" onclick="showAddModal()" style="height: 48px;">
                <i class='bx bx-plus'></i> Nueva Categoría
            </button>
        </div>

        <div class="fc-card" style="padding: 0;">
            <div class="fc-table-container">
                <table class="fc-table">
                    <thead>
                        <tr>
                            <th style="width: 80px;">ID</th>
                            <th>NOMBRE</th>
                            <th style="text-align: right;">ACCIONES</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($categories as $cat): ?>
                            <tr>
                                <td><span class="fc-badge fc-badge-outline"><?= $cat['id'] ?></span></td>
                                <td><div style="font-weight: 600; color: var(--fc-text-main);"><?= htmlspecialchars($cat['name']) ?></div></td>
                                <td style="text-align: right;">
                                    <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                        <button class="fc-btn fc-btn-outline" style="width: 40px; height: 40px; padding: 0;"
                                            onclick="showEditModal(<?= $cat['id'] ?>, '<?= htmlspecialchars($cat['name']) ?>')"
                                            title="Editar">
                                            <i class='bx bx-edit-alt'></i>
                                        </button>

                                        <form method="POST" style="display:inline;" onsubmit="confirmDelete(event, this)">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= $cat['id'] ?>">
                                            <button type="submit" class="fc-btn fc-btn-outline" style="width: 40px; height: 40px; padding: 0; color: var(--fc-rose);"
                                                title="Eliminar">
                                                <i class='bx bx-trash'></i>
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</div>

<!-- Add Modal -->
<div class="fc-modal-overlay" id="addModal">
    <div class="fc-modal" style="max-width: 450px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-plus-circle'></i> Nueva Categoría</h3>
            <button class="fc-modal-close" onclick="closeAddModal()">&times;</button>
        </div>
        <div class="fc-modal-body">
            <form method="POST" class="fc-form">
                <input type="hidden" name="action" value="add">
                <div class="fc-form-group">
                    <label class="fc-label">Nombre de la Categoría</label>
                    <input type="text" name="name" class="fc-input" required placeholder="Ej: Bebidas, Hamburguesas...">
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" class="fc-btn fc-btn-outline fc-w100" onclick="closeAddModal()">Cancelar</button>
                    <button type="submit" class="fc-btn fc-btn-primary fc-w100">Crear Categoría</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Modal -->
<div class="fc-modal-overlay" id="editModal">
    <div class="fc-modal" style="max-width: 450px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-edit-alt'></i> Editar Categoría</h3>
            <button class="fc-modal-close" onclick="closeEditModal()">&times;</button>
        </div>
        <div class="fc-modal-body">
            <form method="POST" class="fc-form">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit_id">
                <div class="fc-form-group">
                    <label class="fc-label">Nombre de la Categoría</label>
                    <input type="text" name="name" id="edit_name" class="fc-input" required>
                </div>
                <div style="display: flex; gap: 10px; margin-top: 20px;">
                    <button type="button" class="fc-btn fc-btn-outline fc-w100" onclick="closeEditModal()">Cancelar</button>
                    <button type="submit" class="fc-btn fc-btn-primary fc-w100">Guardar Cambios</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
    function showAddModal() { document.getElementById('addModal').classList.add('active'); }
    function closeAddModal() { document.getElementById('addModal').classList.remove('active'); }
    function showEditModal(id, name) {
        document.getElementById('edit_id').value = id;
        document.getElementById('edit_name').value = name;
        document.getElementById('editModal').classList.add('active');
    }
    function closeEditModal() { document.getElementById('editModal').classList.remove('active'); }

    function confirmDelete(e, form) {
        e.preventDefault();
        Swal.fire({
            title: '¿Eliminar categoría?',
            text: 'Solo se podrá eliminar si no tiene productos asociados.',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: 'var(--fc-primary)',
            cancelButtonColor: 'var(--fc-bg-dark)',
            confirmButtonText: 'Sí, eliminar',
            cancelButtonText: 'Cancelar',
            background: 'var(--fc-bg-dark)',
            color: 'var(--fc-text-main)'
        }).then((result) => {
            if (result.isConfirmed) form.submit();
        });
    }
</script>

<?php include __DIR__ . '/includes/footer.php'; ?>
