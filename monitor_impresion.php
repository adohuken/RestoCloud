<?php
require_once __DIR__ . '/config/db.php';
session_start();

if (!isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit();
}

$page_title = "Monitor de Impresión (Cocina)";
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $page_title ?> - RestoCloud</title>
    
    <!-- BoxIcons & Google Fonts -->
    <link href='https://unpkg.com/boxicons@2.1.4/css/boxicons.min.css' rel='stylesheet'>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #f1f5f9;
            margin: 0;
            padding: 0;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            height: 100vh;
        }

        .monitor-card {
            background: white;
            padding: 40px;
            border-radius: 20px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.05);
            text-align: center;
            max-width: 500px;
        }

        .status-icon {
            font-size: 60px;
            color: #4f46e5;
            margin-bottom: 20px;
            animation: pulse 2s infinite;
        }

        @keyframes pulse {
            0% { transform: scale(1); opacity: 1; }
            50% { transform: scale(1.1); opacity: 0.8; }
            100% { transform: scale(1); opacity: 1; }
        }

        .status-text {
            font-size: 20px;
            font-weight: 600;
            color: #1e293b;
            margin-bottom: 10px;
        }

        .status-desc {
            font-size: 15px;
            color: #64748b;
            line-height: 1.5;
        }

        /* --- PRINT STYLES FOR THERMAL PRINTER (80mm) --- */
        @media print {
            body * {
                visibility: hidden;
            }
            #print-area, #print-area * {
                visibility: visible;
            }
            #print-area {
                position: absolute;
                left: 0;
                top: 0;
                width: 75mm; /* Adapt to 80mm paper */
                margin: 0;
                padding: 0;
                font-family: 'Courier New', Courier, monospace; /* Monospace is better for tickets */
                font-size: 14px;
                color: black;
            }
            
            .ticket-header { text-align: center; font-weight: bold; font-size: 18px; margin-bottom: 10px; }
            .ticket-info { margin-bottom: 10px; font-size: 14px; }
            .ticket-divider { border-top: 1px dashed black; margin: 10px 0; }
            .ticket-item { display: flex; justify-content: space-between; font-size: 14px; font-weight: bold; margin-bottom: 5px; }
            .ticket-notes { font-size: 12px; margin-left: 10px; margin-bottom: 8px; }
            .ticket-footer { text-align: center; font-weight: bold; margin-top: 15px; }
        }
    </style>
</head>
<body>

    <div class="monitor-card">
        <i class='bx bx-broadcast status-icon'></i>
        <div class="status-text">Escuchando Servidor...</div>
        <div class="status-desc">
            Esta pantalla está conectada a la cocina. No la cierres.<br>
            Los tickets se imprimirán automáticamente aquí.
        </div>
        <div id="connection-status" style="margin-top: 20px; font-size: 13px; color: #10b981; font-weight: 600;">
            <i class='bx bx-check-circle'></i> Conectado en Tiempo Real
        </div>
    </div>

    <!-- Hidden area for printing -->
    <div id="print-area" style="display: none;"></div>

    <script>
        document.addEventListener("DOMContentLoaded", function() {
            // Check if SSE is supported
            if (typeof(EventSource) !== "undefined") {
                const source = new EventSource("api_stream_impresion.php");
                
                source.onopen = function() {
                    document.getElementById('connection-status').innerHTML = "<i class='bx bx-check-circle'></i> Conectado en Tiempo Real";
                    document.getElementById('connection-status').style.color = "#10b981";
                };

                source.onmessage = function(event) {
                    const data = JSON.parse(event.data);
                    console.log("Nuevo ticket recibido:", data);
                    
                    // Render Ticket HTML
                    renderTicket(data);
                    
                    // Trigger Print
                    setTimeout(() => {
                        window.print();
                    }, 500); // Pequeño delay para que el DOM se actualice
                };

                source.onerror = function(error) {
                    console.error("EventSource failed:", error);
                    document.getElementById('connection-status').innerHTML = "<i class='bx bx-error-circle'></i> Reconectando al servidor...";
                    document.getElementById('connection-status').style.color = "#ef4444";
                };
            } else {
                alert("Tu navegador no soporta Server-Sent Events. Por favor usa una versión reciente de Chrome o Edge.");
            }
        });

        function renderTicket(data) {
            const printArea = document.getElementById('print-area');
            printArea.style.display = 'block';
            
            let items = [];
            try {
                items = JSON.parse(data.items_json);
            } catch(e) {}

            let html = `
                <div class="ticket-header">COMANDA COCINA</div>
                <div class="ticket-info">
                    Mesa: ${data.table_name}<br>
                    Mesero: ${data.waiter_name}<br>
                    Fecha: ${new Date().toLocaleString()}
                </div>
                <div class="ticket-divider"></div>
            `;

            items.forEach(item => {
                html += `
                    <div class="ticket-item">
                        <span>[${item.quantity}] ${item.product_name}</span>
                    </div>
                `;
                if (item.notes) {
                    html += `<div class="ticket-notes">* ${item.notes} *</div>`;
                }
            });

            html += `
                <div class="ticket-divider"></div>
                <div class="ticket-footer">FIN DE ORDEN</div>
            `;

            printArea.innerHTML = html;
        }

        // Hide print area after printing finishes (or is cancelled)
        window.onafterprint = function() {
            document.getElementById('print-area').style.display = 'none';
        };
    </script>
</body>
</html>
