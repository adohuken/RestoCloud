<?php
require_once __DIR__ . '/config/db.php';

$new_pass = '12345';
$hash = password_hash($new_pass, PASSWORD_DEFAULT);

try {
    $stmt = $pdo->prepare("UPDATE users SET password = ? WHERE username = 'admin'");
    $stmt->execute([$hash]);

    echo "✅ Password updated to: $new_pass\n";
    echo "New Hash: $hash\n";

    // Verify immediately
    if (password_verify($new_pass, $hash)) {
        echo "✅ Verification SUCCESS in script.\n";
    } else {
        echo "❌ Verification FAILED in script.\n";
    }

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>