<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

// Check module access for users (will redirect if not authorized)
checkModuleAccess($pdo, $_SESSION['role_id'], 'users');

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                $name = $_POST['name'];
                $email = $_POST['email'];
                $username = $_POST['username'];
                $password = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $role_id = $_POST['role_id'];

                try {
                    $stmt = $pdo->prepare('INSERT INTO users (name, email, username, password, role_id, status) VALUES (?, ?, ?, ?, ?, "active")');
                    $stmt->execute([$name, $email, $username, $password, $role_id]);
                    $success = 'Usuario creado exitosamente';
                } catch (PDOException $e) {
                    if ($e->getCode() == 23000) {
                        $error = 'Error: El email o nombre de usuario ya existe en el sistema.';
                    } else {
                        $error = 'Error al crear usuario: ' . $e->getMessage();
                    }
                }
                break;

            case 'update':
                $user_id = $_POST['user_id'];
                $name = $_POST['name'];
                $email = $_POST['email'];
                $role_id = $_POST['role_id'];
                $status = $_POST['status'];

                $stmt = $pdo->prepare('UPDATE users SET name = ?, email = ?, role_id = ?, status = ? WHERE id = ?');
                $stmt->execute([$name, $email, $role_id, $status, $user_id]);
                $success = 'Usuario actualizado exitosamente';
                break;

            case 'reset_password':
                $user_id = $_POST['user_id'];
                $new_password = password_hash($_POST['new_password'], PASSWORD_DEFAULT);

                $stmt = $pdo->prepare('UPDATE users SET password = ? WHERE id = ?');
                $stmt->execute([$new_password, $user_id]);
                $success = 'Contraseña actualizada exitosamente';
                break;

            case 'delete':
                $user_id = $_POST['user_id'];

                // Protect Superadmin (ID 1)
                if ($user_id == 1) {
                    $error = 'No se puede eliminar al Superadmin.';
                    break;
                }

                // Permanently delete the user
                $stmt = $pdo->prepare('DELETE FROM users WHERE id = ?');
                $stmt->execute([$user_id]);
                $success = 'Usuario eliminado exitosamente';
                break;

            case 'create_role':
                $role_name = $_POST['role_name'];
                $stmt = $pdo->prepare('INSERT INTO roles (name) VALUES (?)');
                $stmt->execute([$role_name]);
                header('Location: usuarios.php?tab=roles&success=role_created');
                exit();

            case 'update_role':
                $role_id = $_POST['role_id'];
                $role_name = $_POST['role_name'];

                // Protect Superadmin Role (ID 1)
                if ($role_id == 1) {
                    header('Location: usuarios.php?tab=roles&error=cannot_edit_superadmin');
                    exit();
                }

                $stmt = $pdo->prepare('UPDATE roles SET name = ? WHERE id = ?');
                $stmt->execute([$role_name, $role_id]);
                header('Location: usuarios.php?tab=roles&success=role_updated');
                exit();

            case 'delete_role':
                $role_id = $_POST['role_id'];

                // Protect Superadmin Role (ID 1)
                if ($role_id == 1) {
                    header('Location: usuarios.php?tab=roles&error=cannot_delete_superadmin');
                    exit();
                }

                // Check if role is in use
                $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM users WHERE role_id = ?');
                $stmt->execute([$role_id]);
                $count = $stmt->fetch()['count'];

                if ($count > 0) {
                    header('Location: usuarios.php?tab=roles&error=role_in_use&count=' . $count);
                    exit();
                } else {
                    $stmt = $pdo->prepare('DELETE FROM roles WHERE id = ?');
                    $stmt->execute([$role_id]);
                    header('Location: usuarios.php?tab=roles&success=role_deleted');
                    exit();
                }
                break;
        }
    }
}

// Get all users
$stmt = $pdo->query('
    SELECT u.*, r.name as role_name 
    FROM users u 
    JOIN roles r ON u.role_id = r.id 
    ORDER BY u.id DESC
');
$users = $stmt->fetchAll();


// Get all roles
$roles = $pdo->query('SELECT * FROM roles ORDER BY name')->fetchAll();

// Get user's role name
$stmt = $pdo->prepare('SELECT name FROM roles WHERE id = ?');
$stmt->execute([$_SESSION['role_id']]);
$user_role_name = $stmt->fetchColumn() ?: 'Usuario';
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="dashboard-wrapper">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="main-content">        <div class="fc-header" style="margin-bottom: 30px;">
            <div class="fc-header-left">
                <h1><i class='bx bx-group'></i> Gestión de Personal</h1>
                <p>Control de acceso, roles y permisos del sistema</p>
            </div>
            <div class="fc-header-right no-print">
                <div class="fc-user-pill">
                    <div class="fc-user-avatar"><?= strtoupper(substr($_SESSION['name'], 0, 1)) ?></div>
                    <div class="fc-user-info">
                        <span class="name"><?= htmlspecialchars($_SESSION['name']) ?></span>
                        <span class="role"><?= htmlspecialchars($user_role_name) ?></span>
                    </div>
                </div>
            </div>
        </div>

        <?php if (isset($success)): ?>
            <script>
                Swal.fire({
                    icon: 'success',
                    title: '¡Logrado!',
                    text: '<?= $success ?>',
                    background: '#0f172a',
                    color: '#f8fafc',
                    timer: 2000
                });
            </script>
        <?php endif; ?>

        <?php if (isset($error)): ?>
            <script>
                Swal.fire({
                    icon: 'error',
                    title: 'Error de Validación',
                    text: '<?= $error ?>',
                    background: '#0f172a',
                    color: '#f8fafc'
                });
            </script>
        <?php endif; ?>

        <div class="fc-tabs">
            <button class="fc-tab <?= !isset($_GET['tab']) || $_GET['tab'] == 'users' ? 'active' : '' ?>"
                onclick="switchTab('users')"><i class='bx bx-user'></i> Usuarios</button>
            <button class="fc-tab <?= isset($_GET['tab']) && $_GET['tab'] == 'roles' ? 'active' : '' ?>"
                onclick="switchTab('roles')"><i class='bx bx-shield-quarter'></i> Jerarquía de Roles</button>
        </div>

        <!-- Users Tab -->
        <div id="users-tab" class="tab-content <?= !isset($_GET['tab']) || $_GET['tab'] == 'users' ? 'active' : '' ?>">
            <div style="display: grid; grid-template-columns: 350px 1fr; gap: 30px; align-items: start;">
                <!-- Create User Sidebar -->
                <div class="fc-card" style="margin: 0;">
                    <div class="fc-modal-header">
                        <h3><i class='bx bx-user-plus'></i> Nuevo Colaborador</h3>
                    </div>
                    <div class="fc-modal-body">
                        <form method="POST" class="fc-form">
                            <input type="hidden" name="action" value="create">
                            
                            <div class="fc-form-group">
                                <label class="fc-label">Nombre Completo</label>
                                <input type="text" name="name" class="fc-input" placeholder="Nombre completo" required>
                            </div>

                            <div class="fc-form-group">
                                <label class="fc-label">Correo Electrónico</label>
                                <input type="email" name="email" class="fc-input" placeholder="correo@empresa.com" required>
                            </div>

                            <div class="fc-form-group">
                                <label class="fc-label">Nombre de Usuario</label>
                                <input type="text" name="username" class="fc-input" placeholder="usuario.acceso" required>
                            </div>

                            <div class="fc-form-group">
                                <label class="fc-label">Contraseña</label>
                                <input type="password" name="password" class="fc-input" placeholder="••••••••" required>
                            </div>

                            <div class="fc-form-group">
                                <label class="fc-label">Asignar Rol</label>
                                <select name="role_id" class="fc-input" required>
                                    <?php foreach ($roles as $role): ?>
                                        <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['name']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <button type="submit" class="fc-btn fc-btn-primary fc-w100" style="margin-top: 10px;">
                                <i class='bx bx-save'></i> Registrar Usuario
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Users List -->
                <div class="fc-card" style="margin: 0;">
                    <div class="fc-modal-header">
                        <h3><i class='bx bx-list-ul'></i> Directorio de Usuarios</h3>
                    </div>
                    <div class="fc-table-responsive">
                        <table class="fc-table">
                            <thead>
                                <tr>
                                    <th>Colaborador</th>
                                    <th>Acceso / Email</th>
                                    <th style="text-align: center;">Rol</th>
                                    <th style="text-align: center;">Estado</th>
                                    <th style="text-align: right;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($users as $user): ?>
                                    <tr>
                                        <td>
                                            <div style="display:flex; align-items:center; gap:12px;">
                                                <div class="fc-user-avatar" style="width:36px; height:36px; font-size:15px; border-radius: 10px;">
                                                    <?= strtoupper(substr($user['name'], 0, 1)) ?>
                                                </div>
                                                <div>
                                                    <div style="color: var(--fc-text-main); font-weight: 600;"><?= htmlspecialchars($user['name']) ?></div>
                                                    <div style="font-size: 11px; color: var(--fc-text-sec);">ID: #<?= $user['id'] ?></div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-size: 13px; color: var(--fc-text-main);">@<?= htmlspecialchars($user['username']) ?></div>
                                            <div style="font-size: 11px; color: var(--fc-text-sec);"><?= htmlspecialchars($user['email']) ?></div>
                                        </td>
                                        <td style="text-align: center;">
                                            <span class="fc-badge <?= $user['role_id'] == 1 ? 'fc-badge-primary' : 'fc-badge-outline' ?>" style="font-size: 10px;">
                                                <?= strtoupper(htmlspecialchars($user['role_name'])) ?>
                                            </span>
                                        </td>
                                        <td style="text-align: center;">
                                            <span style="display: inline-flex; align-items: center; gap: 5px; font-size: 11px; color: <?= $user['status'] == 'active' ? '#10b981' : '#ef4444' ?>;">
                                                <i class='bx bxs-circle' style="font-size: 8px;"></i>
                                                <?= $user['status'] == 'active' ? 'ACTIVO' : 'INACTIVO' ?>
                                            </span>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                                <?php if (isset($user['is_super_admin']) && $user['is_super_admin'] == 1): ?>
                                                    <i class='bx bxs-lock-alt' style="color: var(--fc-text-sec); padding: 10px;"></i>
                                                <?php else: ?>
                                                    <button onclick='editUser(<?= json_encode($user) ?>)' class="fc-btn fc-btn-outline" style="padding: 6px; width: 32px; height: 32px; min-width: auto;">
                                                        <i class='bx bx-edit-alt'></i>
                                                    </button>
                                                    <button onclick="resetPassword(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')" class="fc-btn fc-btn-outline" style="padding: 6px; width: 32px; height: 32px; min-width: auto; border-color: #8b5cf6; color: #8b5cf6;">
                                                        <i class='bx bx-key'></i>
                                                    </button>
                                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                        <button onclick="deleteUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['username']) ?>')" class="fc-btn fc-btn-outline" style="padding: 6px; width: 32px; height: 32px; min-width: auto; border-color: var(--fc-primary); color: var(--fc-primary);">
                                                            <i class='bx bx-trash'></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Roles Tab -->
        <div id="roles-tab" class="tab-content <?= isset($_GET['tab']) && $_GET['tab'] == 'roles' ? 'active' : '' ?>">
            <div style="display: grid; grid-template-columns: 350px 1fr; gap: 30px; align-items: start;">
                <!-- Create Role Sidebar -->
                <div class="fc-card" style="margin: 0;">
                    <div class="fc-modal-header">
                        <h3><i class='bx bx-shield-plus'></i> Nuevo Rol</h3>
                    </div>
                    <div class="fc-modal-body">
                        <form method="POST" class="fc-form">
                            <input type="hidden" name="action" value="create_role">
                            <div class="fc-form-group">
                                <label class="fc-label">Nombre del Rol</label>
                                <input type="text" name="role_name" class="fc-input" placeholder="Ej: Cajero, Mesero" required>
                            </div>
                            <button type="submit" class="fc-btn fc-btn-primary fc-w100">
                                <i class='bx bx-save'></i> Guardar Rol
                            </button>
                        </form>
                    </div>
                </div>

                <!-- Roles List -->
                <div class="fc-card" style="margin: 0;">
                    <div class="fc-modal-header">
                        <h3><i class='bx bx-shield-quarter'></i> Niveles de Autorización</h3>
                    </div>
                    <div class="fc-table-responsive">
                        <table class="fc-table">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Denominación del Rol</th>
                                    <th style="text-align: center;">Densidad de Usuarios</th>
                                    <th style="text-align: right;">Acciones</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($roles as $role): ?>
                                    <?php
                                    $stmt = $pdo->prepare('SELECT COUNT(*) as count FROM users WHERE role_id = ?');
                                    $stmt->execute([$role['id']]);
                                    $u_count = $stmt->fetchColumn();
                                    ?>
                                    <tr>
                                        <td><span class="fc-badge fc-badge-outline" style="font-family: monospace;">#<?= $role['id'] ?></span></td>
                                        <td><strong style="color: var(--fc-text-main);"><?= htmlspecialchars($role['name']) ?></strong></td>
                                        <td style="text-align: center;">
                                            <span class="fc-badge" style="background: rgba(255,255,255,0.05); color: var(--fc-text-sec);">
                                                <i class='bx bx-user' style="margin-right: 5px;"></i> <?= $u_count ?> registrados
                                            </span>
                                        </td>
                                        <td style="text-align: right;">
                                            <div style="display: flex; gap: 8px; justify-content: flex-end;">
                                                <?php if ($role['id'] == 1): ?>
                                                    <i class='bx bxs-lock-alt' style="color: var(--fc-text-sec); padding: 10px;"></i>
                                                <?php else: ?>
                                                    <button onclick='editRole(<?= json_encode($role) ?>)' class="fc-btn fc-btn-outline" style="padding: 6px; width: 32px; height: 32px; min-width: auto;">
                                                        <i class='bx bx-edit-alt'></i>
                                                    </button>
                                                    <?php if ($u_count == 0): ?>
                                                        <button onclick="deleteRole(<?= $role['id'] ?>, '<?= htmlspecialchars($role['name']) ?>')" class="fc-btn fc-btn-outline" style="padding: 6px; width: 32px; height: 32px; min-width: auto; border-color: var(--fc-primary); color: var(--fc-primary);">
                                                            <i class='bx bx-trash'></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Edit User Modal -->
<div id="editModal" class="fc-modal-overlay">
    <div class="fc-modal" style="max-width: 450px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-edit-user'></i> Editar Perfil</h3>
            <span class="close" onclick="closeModal('editModal')">&times;</span>
        </div>
        <form method="POST" class="fc-form" style="padding: 25px;">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="user_id" id="edit_user_id">
 
            <div class="fc-form-group">
                <label class="fc-label">Nombre Completo</label>
                <input type="text" name="name" id="edit_name" class="fc-input" required>
            </div>
 
            <div class="fc-form-group">
                <label class="fc-label">Correo Electrónico</label>
                <input type="email" name="email" id="edit_email" class="fc-input" required>
            </div>
 
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="fc-form-group">
                    <label class="fc-label">Rol</label>
                    <select name="role_id" id="edit_role_id" class="fc-input" required>
                        <?php foreach ($roles as $role): ?>
                            <option value="<?= $role['id'] ?>"><?= htmlspecialchars($role['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="fc-form-group">
                    <label class="fc-label">Estado</label>
                    <select name="status" id="edit_status" class="fc-input" required>
                        <option value="active">Activo</option>
                        <option value="inactive">Inactivo</option>
                    </select>
                </div>
            </div>
 
            <button type="submit" class="fc-btn fc-btn-primary fc-w100" style="height: 48px;">
                <i class='bx bx-check-circle'></i> Aplicar Cambios
            </button>
        </form>
    </div>
</div>
 
<!-- Reset Password Modal -->
<div id="resetModal" class="fc-modal-overlay">
    <div class="fc-modal" style="max-width: 400px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-key'></i> Nueva Credencial</h3>
            <span class="close" onclick="closeModal('resetModal')">&times;</span>
        </div>
        <form method="POST" class="fc-form" style="padding: 25px;">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="user_id" id="reset_user_id">
 
            <div style="text-align: center; margin-bottom: 20px;">
                <div id="reset_username" style="font-size: 18px; font-weight: 700; color: var(--fc-primary); margin-bottom: 5px;"></div>
                <p style="color: var(--fc-text-sec); font-size: 12px;">Defina la nueva contraseña de acceso</p>
            </div>
 
            <div class="fc-form-group">
                <label class="fc-label">Contraseña Nueva</label>
                <input type="password" name="new_password" class="fc-input" placeholder="Min. 8 caracteres" required autofocus>
            </div>
 
            <button type="submit" class="fc-btn fc-btn-primary fc-w100" style="height: 48px;">
                <i class='bx bx-lock-open-alt'></i> Actualizar Contraseña
            </button>
        </form>
    </div>
</div>
 
<!-- Edit Role Modal -->
<div id="editRoleModal" class="fc-modal-overlay">
    <div class="fc-modal" style="max-width: 400px;">
        <div class="fc-modal-header">
            <h3><i class='bx bx-shield-quarter'></i> Modificar Rol</h3>
            <span class="close" onclick="closeModal('editRoleModal')">&times;</span>
        </div>
        <form method="POST" class="fc-form" style="padding: 25px;">
            <input type="hidden" name="action" value="update_role">
            <input type="hidden" name="role_id" id="modal_edit_role_id">
 
            <div class="fc-form-group">
                <label class="fc-label">Nombre del Rol</label>
                <input type="text" name="role_name" id="modal_edit_role_name" class="fc-input" required>
            </div>
 
            <button type="submit" class="fc-btn fc-btn-primary fc-w100" style="height: 48px;">Actualizar Nivel</button>
        </form>
    </div>
</div>

<script>
    function editUser(user) {
        document.getElementById('edit_user_id').value = user.id;
        document.getElementById('edit_name').value = user.name;
        document.getElementById('edit_email').value = user.email;
        document.getElementById('edit_role_id').value = user.role_id;
        document.getElementById('edit_status').value = user.status;
        document.getElementById('editModal').classList.add('show');
    }

    function resetPassword(userId, username) {
        document.getElementById('reset_user_id').value = userId;
        document.getElementById('reset_username').textContent = '@' + username;
        document.getElementById('resetModal').classList.add('show');
    }

    function deleteUser(userId, username) {
        Swal.fire({
            title: '¿Eliminar usuario?',
            html: `Está a punto de remover a <b>${username}</b> del sistema de forma permanente.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: 'rgba(255,255,255,0.1)',
            confirmButtonText: 'Sí, Eliminar',
            cancelButtonText: 'Cancelar',
            background: '#0f172a',
            color: '#f8fafc'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete"><input type="hidden" name="user_id" value="${userId}">`;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    function editRole(role) {
        document.getElementById('modal_edit_role_id').value = role.id;
        document.getElementById('modal_edit_role_name').value = role.name;
        document.getElementById('editRoleModal').classList.add('show');
    }

    function deleteRole(roleId, roleName) {
        Swal.fire({
            title: '¿Remover este Rol?',
            text: `El rol "${roleName}" será eliminado. Esta acción no afectará a usuarios que ya tengan otros roles.`,
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#e11d48',
            cancelButtonColor: 'rgba(255,255,255,0.1)',
            confirmButtonText: 'Eliminar Rol',
            cancelButtonText: 'Cancelar',
            background: '#0f172a',
            color: '#f8fafc'
        }).then((result) => {
            if (result.isConfirmed) {
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `<input type="hidden" name="action" value="delete_role"><input type="hidden" name="role_id" value="${roleId}">`;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }

    function switchTab(tabName) {
        document.querySelectorAll('.tab-content').forEach(tab => tab.classList.remove('active'));
        document.querySelectorAll('.fc-tab').forEach(btn => btn.classList.remove('active'));
        document.getElementById(tabName + '-tab').classList.add('active');
        event.currentTarget.classList.add('active');
        
        // Update URL without reload
        const url = new URL(window.location);
        url.searchParams.set('tab', tabName);
        window.history.pushState({}, '', url);
    }

    function closeModal(modalId) {
        document.getElementById(modalId).classList.remove('show');
    }

    window.onclick = function (event) {
        if (event.target.classList.contains('fc-modal-overlay')) {
            event.target.classList.remove('show');
        }
    }
</script>

<style>
    @media (max-width: 1024px) {
        div[style*="grid-template-columns: 350px 1fr"] {
            grid-template-columns: 1fr !important;
        }
    }
</style>

<?php include __DIR__ . '/includes/footer.php'; ?>