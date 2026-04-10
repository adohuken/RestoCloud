<?php
require_once __DIR__ . '/config/db.php';

$username = 'admin';
$password = 'admin123';

echo "Testing login for user: $username\n";

$stmt = $pdo->prepare('SELECT * FROM users WHERE username = :username');
$stmt->execute(['username' => $username]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    echo "❌ User not found.\n";
    exit;
}

echo "✅ User found: " . $user['username'] . " (ID: " . $user['id'] . ")\n";
echo "Stored Hash: " . $user['password'] . "\n";
echo "Hash Length: " . strlen($user['password']) . "\n";
echo "Status: " . $user['status'] . "\n";

if (password_verify($password, $user['password'])) {
    echo "✅ Password verify SUCCESS.\n";
} else {
    echo "❌ Password verify FAILED.\n";
    // Try re-hashing to see difference
    $new_hash = password_hash($password, PASSWORD_DEFAULT);
    echo "New Hash would be: $new_hash\n";
}
?>