<?php
/**
 * Database Seeder for RestoCloud Test Data
 * Run this script from CLI or browser to generate realistic reports data.
 */

// Only allow execution from CLI or local environments for safety
if (php_sapi_name() !== 'cli' && $_SERVER['REMOTE_ADDR'] !== '127.0.0.1' && $_SERVER['REMOTE_ADDR'] !== '::1') {
    die("Access denied. This script can only be run locally.");
}

require_once __DIR__ . '/config/db.php';

echo "Starting database seeding...\n";

try {
    // 1. Clean existing transactional data to start fresh (DDL statements implicitly commit any active transaction, so clear first)
    echo "Cleaning old transactional data...\n";
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0;");
    $pdo->exec("TRUNCATE TABLE order_details;");
    $pdo->exec("TRUNCATE TABLE payments;");
    $pdo->exec("TRUNCATE TABLE invoices;");
    $pdo->exec("TRUNCATE TABLE cash_register;");
    $pdo->exec("TRUNCATE TABLE orders;");
    $pdo->exec("TRUNCATE TABLE pedidosya_order_details;");
    $pdo->exec("TRUNCATE TABLE pedidosya_orders;");
    $pdo->exec("TRUNCATE TABLE deleted_invoices_log;");
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1;");

    $pdo->beginTransaction();
    echo "Old transactional data cleared.\n";

    // 2. Fetch existing reference data
    $products = $pdo->query("SELECT id, name, price FROM products WHERE status = 'active'")->fetchAll();
    if (empty($products)) {
        throw new Exception("No active products found in the database. Please add some products first.");
    }
    
    $tables = $pdo->query("SELECT id, name FROM tables")->fetchAll();
    if (empty($tables)) {
        throw new Exception("No tables found. Please add some tables first.");
    }

    // 3. Insert or retrieve additional test users (Waiters and Cashiers)
    echo "Setting up test users...\n";
    $waiters = [];
    
    // Check if the default Mesero (ID 2) exists
    $stmt = $pdo->prepare("SELECT id, name FROM users WHERE id = 2");
    $stmt->execute();
    $default_waiter = $stmt->fetch();
    if ($default_waiter) {
        $waiters[] = $default_waiter;
    }

    // Extra waiters to seed
    $waiters_to_seed = [
        ['name' => 'Juan Pérez', 'username' => 'juan', 'email' => 'juan@restocloud.com'],
        ['name' => 'María López', 'username' => 'maria', 'email' => 'maria@restocloud.com'],
        ['name' => 'Carlos Gómez', 'username' => 'carlos', 'email' => 'carlos@restocloud.com'],
    ];

    $hashed_password = password_hash('123456', PASSWORD_DEFAULT);

    foreach ($waiters_to_seed as $w) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$w['username']]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            $waiters[] = ['id' => $existing, 'name' => $w['name']];
        } else {
            $stmt = $pdo->prepare("INSERT INTO users (name, email, username, password, role_id, status) VALUES (?, ?, ?, ?, 2, 'active')");
            $stmt->execute([$w['name'], $w['email'], $w['username'], $hashed_password]);
            $waiters[] = ['id' => $pdo->lastInsertId(), 'name' => $w['name']];
        }
    }

    // Cashier
    $stmt = $pdo->prepare("SELECT id FROM users WHERE username = 'ana'");
    $stmt->execute();
    $cashier_id = $stmt->fetchColumn();
    if (!$cashier_id) {
        $stmt = $pdo->prepare("INSERT INTO users (name, email, username, password, role_id, status) VALUES ('Ana Silva', 'ana@restocloud.com', 'ana', ?, 3, 'active')");
        $stmt->execute([$hashed_password]);
        $cashier_id = $pdo->lastInsertId();
    }

    echo "Test users configured. Total waiters: " . count($waiters) . "\n";

    // 4. Generate transaction data day by day: August 1, 2026 to August 22, 2026
    $start_date = new DateTime('2026-08-01');
    $end_date = new DateTime('2026-08-22');
    $interval = new DateInterval('P1D');
    $daterange = new DatePeriod($start_date, $interval, $end_date->modify('+1 day')); // Include the last day

    $payment_methods = ['cash', 'card', 'transfer'];
    $py_customers = [
        ['name' => 'Sofía Rodríguez', 'phone' => '88994433', 'address' => 'Altamira, del restaurante La Marseillaise 2c al sur'],
        ['name' => 'Eduardo Chamorro', 'phone' => '77665544', 'address' => 'Los Robles, de Plaza El Sol 3c abajo'],
        ['name' => 'Valeria Zelaya', 'phone' => '84321098', 'address' => 'Bello Horizonte, Rotonda 1c al oeste, casa #45'],
        ['name' => 'Roberto Castillo', 'phone' => '87654321', 'address' => 'Villa Fontana, Semáforos Club Terraza 1c arriba'],
        ['name' => 'Camila Blandón', 'phone' => '58349281', 'address' => 'Reparto San Juan, Gimnasio Hércules 70 vrs al lago'],
        ['name' => 'Francisco Pérez', 'phone' => '78453212', 'address' => 'Carretera Masaya km 9.5, Condominio Portal de las Colinas'],
        ['name' => 'Gabriela Morales', 'phone' => '89324576', 'address' => 'Linda Vista, del parque 1c abajo, casa #12'],
        ['name' => 'Oscar Jarquín', 'phone' => '82124567', 'address' => 'Ciudad Jardín, de la Óptica Nicaragüense 75 vrs arriba']
    ];

    echo "Generating daily transactions...\n";

    foreach ($daterange as $dateObj) {
        $dateStr = $dateObj->format('Y-m-d');
        echo " -> Processing $dateStr...\n";

        // A. Cash register opening (Caja)
        // Opening amount: C$ 1,000.00
        $stmt = $pdo->prepare("INSERT INTO cash_register (user_id, amount, type, date_created, status, expected_amount) VALUES (?, 1000.00, 'open', ?, 'active', 1000.00)");
        $stmt->execute([$cashier_id, "$dateStr 08:00:00"]);
        $register_id = $pdo->lastInsertId();

        $cash_sales_total = 0;
        
        // B. Generate standard dining/in-house orders
        // Random number of orders between 5 and 15
        $num_orders = rand(6, 14);

        for ($i = 0; $i < $num_orders; $i++) {
            $waiter = $waiters[array_rand($waiters)];
            $table = $tables[array_rand($tables)];
            
            // Random hour between 08:30 and 21:45
            $hour = sprintf("%02d", rand(8, 21));
            $minute = sprintf("%02d", rand(0, 59));
            $second = sprintf("%02d", rand(0, 59));
            $order_time = "$dateStr $hour:$minute:$second";

            // Insert draft order
            $stmt = $pdo->prepare("INSERT INTO orders (table_id, user_id, total, status, date_created) VALUES (?, ?, 0.00, 'draft', ?)");
            $stmt->execute([$table['id'], $waiter['id'], $order_time]);
            $order_id = $pdo->lastInsertId();

            // Add 1 to 4 random products
            $num_items = rand(1, 4);
            $order_total = 0;
            $chosen_products = array_rand($products, min($num_items, count($products)));
            if (!is_array($chosen_products)) {
                $chosen_products = [$chosen_products];
            }

            foreach ($chosen_products as $prod_idx) {
                $prod = $products[$prod_idx];
                $qty = rand(1, 3);
                $price = $prod['price'];
                $item_total = $qty * $price;
                $order_total += $item_total;

                $stmt = $pdo->prepare("INSERT INTO order_details (order_id, product_id, quantity, item_status, price, created_at) VALUES (?, ?, ?, 'served', ?, ?)");
                $stmt->execute([$order_id, $prod['id'], $qty, $price, $order_time]);
            }

            // Decide status of the order (completed, cancelled, or deleted_invoice)
            $rand_status = rand(1, 100);
            
            if ($rand_status <= 85) {
                // Completed successfully
                $stmt = $pdo->prepare("UPDATE orders SET total = ?, status = 'completed' WHERE id = ?");
                $stmt->execute([$order_total, $order_id]);

                // Payment
                $method = $payment_methods[array_rand($payment_methods)];
                $stmt = $pdo->prepare("INSERT INTO payments (order_id, method, amount, cash_register_id, date_created) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$order_id, $method, $order_total, $register_id, $order_time]);

                if ($method === 'cash') {
                    $cash_sales_total += $order_total;
                }

                // Invoice
                $iva_pct = 15.00;
                $subtotal = $order_total / (1 + ($iva_pct / 100));
                $iva_amount = $order_total - $subtotal;
                $tip = (rand(1, 100) <= 20) ? floatval(rand(10, 50)) : 0.00; // 20% chance of tip

                $stmt = $pdo->prepare("INSERT INTO invoices (order_id, table_name, subtotal, iva_amount, iva_percentage, total, payment_method, status, date_created, tip_amount) VALUES (?, ?, ?, ?, ?, ?, ?, 'paid', ?, ?)");
                $stmt->execute([$order_id, $table['name'], $subtotal, $iva_amount, $iva_pct, $order_total, $method, $order_time, $tip]);

            } elseif ($rand_status <= 93) {
                // Deleted Invoice Simulation (will show up in Deleted Invoices Log!)
                $stmt = $pdo->prepare("UPDATE orders SET total = ?, status = 'cancelled' WHERE id = ?");
                $stmt->execute([$order_total, $order_id]);

                // Create a temporary invoice first to get an invoice ID, then "delete" it
                $iva_pct = 15.00;
                $subtotal = $order_total / (1 + ($iva_pct / 100));
                $iva_amount = $order_total - $subtotal;
                $method = $payment_methods[array_rand($payment_methods)];

                $stmt = $pdo->prepare("INSERT INTO invoices (order_id, table_name, subtotal, iva_amount, iva_percentage, total, payment_method, status, date_created) VALUES (?, ?, ?, ?, ?, ?, ?, 'deleted', ?)");
                $stmt->execute([$order_id, $table['name'], $subtotal, $iva_amount, $iva_pct, $order_total, $method, $order_time]);
                $invoice_id = $pdo->lastInsertId();

                // Delete it (simulated deletion from UI usually deletes invoice or updates it)
                $stmt = $pdo->prepare("DELETE FROM invoices WHERE id = ?");
                $stmt->execute([$invoice_id]);

                // Log it in deleted_invoices_log
                $reasons = [
                    'Error de digitación por el mesero',
                    'Cliente canceló el pedido a último momento',
                    'Se registró método de pago incorrecto',
                    'Mesa duplicada por error en la app',
                    'Cliente se retiró molesto por demora'
                ];
                $reason = $reasons[array_rand($reasons)];
                
                $delete_time = date('Y-m-d H:i:s', strtotime($order_time) + rand(300, 1800)); // 5-30 min later
                $stmt = $pdo->prepare("INSERT INTO deleted_invoices_log (original_invoice_id, order_id, amount, payment_method, deleted_by, deleted_at, reason) VALUES (?, ?, ?, ?, 1, ?, ?)");
                $stmt->execute([$invoice_id, $order_id, $order_total, $method, $delete_time, $reason]);

            } else {
                // Just cancelled order (no invoice was ever created)
                $stmt = $pdo->prepare("UPDATE orders SET total = ?, status = 'cancelled' WHERE id = ?");
                $stmt->execute([$order_total, $order_id]);
            }
        }

        // C. Close cash register (Caja)
        // Closing time is 22:30:00
        $expected_closing = 1000.00 + $cash_sales_total;
        // Minor cashier difference (-30 to +30)
        $difference = rand(-25, 25);
        // Let's make differences occur 30% of the time, 70% is perfect match
        if (rand(1, 10) > 3) {
            $difference = 0.00;
        }
        $actual_amount = $expected_closing + $difference;

        $stmt = $pdo->prepare("UPDATE cash_register SET status = 'closed' WHERE id = ?");
        $stmt->execute([$register_id]);

        $stmt = $pdo->prepare("INSERT INTO cash_register (user_id, amount, type, date_created, status, expected_amount, difference) VALUES (?, ?, 'close', ?, 'closed', ?, ?)");
        $stmt->execute([$cashier_id, $actual_amount, "$dateStr 22:30:00", $expected_closing, $difference]);

        // D. Generate PedidosYa Delivery Orders (if table exists)
        // 1 to 4 orders per day
        $num_py = rand(1, 4);
        for ($py = 0; $py < $num_py; $py++) {
            $cust = $py_customers[array_rand($py_customers)];
            $ext_id = "PY-" . rand(100000000, 999999999);
            
            $hour = sprintf("%02d", rand(11, 21));
            $minute = sprintf("%02d", rand(0, 59));
            $second = sprintf("%02d", rand(0, 59));
            $py_time = "$dateStr $hour:$minute:$second";

            // Create main PY order
            $stmt = $pdo->prepare("INSERT INTO pedidosya_orders (external_order_id, customer_name, customer_phone, customer_address, subtotal, iva_percentage, iva_amount, total, status, created_by, date_created) VALUES (?, ?, ?, ?, 0.00, 15.00, 0.00, 0.00, 'completed', 1, ?)");
            $stmt->execute([$ext_id, $cust['name'], $cust['phone'], $cust['address'], $py_time]);
            $py_order_id = $pdo->lastInsertId();

            $num_py_items = rand(1, 3);
            $py_total = 0;
            $chosen_prods = array_rand($products, min($num_py_items, count($products)));
            if (!is_array($chosen_prods)) {
                $chosen_prods = [$chosen_prods];
            }

            foreach ($chosen_prods as $prod_idx) {
                $prod = $products[$prod_idx];
                $qty = rand(1, 2);
                $price = $prod['price'];
                $item_total = $qty * $price;
                $py_total += $item_total;

                $stmt = $pdo->prepare("INSERT INTO pedidosya_order_details (pedidosya_order_id, product_id, product_name, quantity, price) VALUES (?, ?, ?, ?, ?)");
                $stmt->execute([$py_order_id, $prod['id'], $prod['name'], $qty, $price]);
            }

            $py_subtotal = $py_total / 1.15;
            $py_iva = $py_total - $py_subtotal;

            $stmt = $pdo->prepare("UPDATE pedidosya_orders SET subtotal = ?, iva_amount = ?, total = ? WHERE id = ?");
            $stmt->execute([$py_subtotal, $py_iva, $py_total, $py_order_id]);
        }
    }

    $pdo->commit();
    echo "\nDatabase seeding completed successfully!\n";
    echo "Added test data from 2026-08-01 to 2026-08-22.\n";

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo "\nError during database seeding: " . $e->getMessage() . "\n";
}
