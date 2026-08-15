<?php
require_once __DIR__ . '/config/db.php';
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

            // Redirect based on role
            if ($user['role_id'] == 4) {
                // Kitchen user goes directly to kitchen
                header('Location: cocina.php');
            } elseif ($user['role_id'] == 3) {
                // Cashier goes to cashier dashboard
                header('Location: panel_cajero.php');
            } elseif ($user['role_id'] == 2) {
                // Waiter goes to tables
                header('Location: mesas.php');
            } else {
                // Other users go to dashboard
                header('Location: inicio.php');
            }
            exit();
        } else {
            $error = 'Usuario o contraseÃ±a incorrectos.';
        }
    } else {
        $error = 'Por favor ingrese usuario y contraseÃ±a.';
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
    <div class="login-container">
        <div class="login-header">
            <?php if ($company_logo): ?>
                <img src="<?= htmlspecialchars($company_logo) ?>" alt="Logo" class="login-logo">
            <?php else: ?>
                <h1><i class='bx bx-dish' style="color: var(--fc-primary);"></i> <?= htmlspecialchars($company_name) ?></h1>
            <?php endif; ?>
        </div>

        <?php if ($error): ?>
            <div class="alert alert-danger" style="margin-bottom: 30px;">
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
                    placeholder="ContraseÃ±a" required>
            </div>
 
            <button type="submit" class="fc-login-btn">Iniciar SesiÃ³n</button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
