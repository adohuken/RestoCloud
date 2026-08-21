<?php
require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/modules_helper.php';
session_start();

if (isset($_SESSION['user_id'])) {
    header('Location: inicio.php');
    exit();
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
        $stmt = $pdo->prepare('SELECT id, password, name, role_id, is_super_admin FROM users WHERE username = :username AND status = "active"');
        $stmt->execute(['username' => $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $username;
            $_SESSION['name'] = $user['name'];
            $_SESSION['role_id'] = $user['role_id'];
            $_SESSION['is_super_admin'] = $user['is_super_admin'];

            // Intelligent redirect based on assigned modules
            if (isRoleAdmin($pdo, $user['role_id'])) {
                header('Location: inicio.php');
            } else {
                $user_modules = getUserModules($pdo, $user['role_id'], true);
                if (!empty($user_modules)) {
                    header('Location: ' . $user_modules[0]['file_path']);
                } else {
                    header('Location: sin_acceso.php');
                }
            }
            exit();
        } else {
            $error = 'Usuario o contraseña incorrectos.';
        }
    } else {
        $error = 'Por favor ingrese usuario y contraseña.';
    }
}


// Fetch company settings
$company_name = 'RestoCloud System';
$company_logo = '';

try {
    $stmt = $pdo->query("SHOW TABLES LIKE 'settings'");
    if ($stmt->rowCount() > 0) {
        $stmt = $pdo->prepare("SELECT setting_key, setting_value FROM settings WHERE setting_key IN ('company_name', 'company_logo')");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        if (!empty($settings['company_name'])) {
            $company_name = $settings['company_name'];
        }
        if (!empty($settings['company_logo'])) {
            $company_logo = $settings['company_logo'];
        }
    }
} catch (Exception $e) {
}
?>
<?php include __DIR__ . '/includes/header.php'; ?>

<div class="login-wrapper">
    <div class="login-split-card">
        <!-- Left: Illustration Panel -->
        <div class="login-illustration">
            <img src="assets/img/login-bg.jpg?v=3" alt="Restaurant" class="login-bg-img">
            <div class="login-illustration-overlay"></div>
        </div>
        
        <!-- Right: Form Panel -->
        <div class="login-form-panel">
            <div class="login-header">
                <?php if ($company_logo): ?>
                    <img src="<?= htmlspecialchars($company_logo) ?>" alt="Logo" class="login-logo">
                <?php endif; ?>
                <h1>¡Bienvenido!</h1>
                <p class="login-subtitle">Ingresa tus credenciales para acceder al sistema</p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger login-alert">
                    <i class='bx bx-error-circle'></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <div class="login-input-wrapper">
                    <i class='bx bx-user'></i>
                    <input type="text" id="username" name="username" class="fc-input" placeholder="Usuario"
                        required autocomplete="off">
                </div>
 
                <div class="login-input-wrapper">
                    <i class='bx bx-lock-alt'></i>
                    <input type="password" id="password" name="password" class="fc-input"
                        placeholder="Contraseña" required>
                </div>
 
                <button type="submit" class="fc-login-btn">
                    Iniciar Sesión <i class='bx bx-right-arrow-alt'></i>
                </button>
            </form>

            <div class="login-footer">
                <p>Powered by <strong>RestoCloud</strong></p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
