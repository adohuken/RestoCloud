#!/usr/bin/env python3
"""
RestoCloud - Cliente de Impresión Local
=======================================
Este script corre en la computadora que tiene la impresora instalada.
Se conecta al sistema en el hosting y detecta cuando llegan pedidos nuevos.
Los imprime automáticamente en la impresora que elijas.

Requisitos:
    pip install requests pywin32

Uso:
    python printer_client.py
"""

import sys
import time
import json
import os
import socket

try:
    import requests
except ImportError:
    print("=" * 50)
    print("ERROR: Falta la librería 'requests'")
    print("Ejecuta: pip install requests pywin32 pycryptodome")
    print("=" * 50)
    input("Presiona Enter para salir...")
    sys.exit(1)

try:
    from Crypto.Cipher import AES
except ImportError:
    try:
        from Cryptodome.Cipher import AES
    except ImportError:
        print("=" * 50)
        print("ERROR: Falta la librería 'pycryptodome'")
        print("Ejecuta: pip install pycryptodome")
        print("=" * 50)
        input("Presiona Enter para salir...")
        sys.exit(1)

import re

try:
    import win32print
except ImportError:
    print("=" * 50)
    print("ERROR: Falta la librería 'pywin32'")
    print("Ejecuta: pip install pywin32")
    print("=" * 50)
    input("Presiona Enter para salir...")
    sys.exit(1)


# ============================================================
# CONFIGURACIÓN - Edita estos valores
# ============================================================
CONFIG_FILE = os.path.join(os.path.dirname(os.path.abspath(__file__)), "printer_config.json")


def load_config():
    if os.path.exists(CONFIG_FILE):
        with open(CONFIG_FILE, 'r') as f:
            return json.load(f)
    return {}


def save_config(config):
    with open(CONFIG_FILE, 'w') as f:
        json.dump(config, f, indent=2)


def solve_infinityfree_challenge(session, url):
    """
    InfinityFree usa un desafío JavaScript con AES para verificar que
    el cliente no es un bot. Esta función resuelve ese desafío.
    """
    resp = session.get(url, timeout=15)
    html = resp.text

    # Check if this is the AES challenge page
    if '__test' not in html or 'slowAES' not in html:
        return True  # No challenge, we're good

    # Extract the three hex values: a (key), b (IV), c (ciphertext)
    matches = re.findall(r'toNumbers\("([a-f0-9]+)"\)', html)
    if len(matches) < 3:
        print("  ⚠ No se pudo resolver el desafío de seguridad")
        return False

    key = bytes.fromhex(matches[0])
    iv = bytes.fromhex(matches[1])
    ciphertext = bytes.fromhex(matches[2])

    # Decrypt using AES-CBC
    cipher = AES.new(key, AES.MODE_CBC, iv)
    decrypted = cipher.decrypt(ciphertext)
    cookie_value = decrypted.hex()

    # Set the cookie
    session.cookies.set('__test', cookie_value, domain=url.split('//')[1].split('/')[0])
    print("  ✓ Desafío de seguridad resuelto")
    return True


def list_printers():
    """Lista todas las impresoras instaladas en Windows."""
    printers = []
    for flags, desc, name, comment in win32print.EnumPrinters(
        win32print.PRINTER_ENUM_LOCAL | win32print.PRINTER_ENUM_CONNECTIONS
    ):
        printers.append(name)
    return printers


def select_printer():
    """Permite al usuario seleccionar una impresora."""
    printers = list_printers()
    default = win32print.GetDefaultPrinter()

    print("\n" + "=" * 50)
    print("  IMPRESORAS DISPONIBLES")
    print("=" * 50)

    for i, p in enumerate(printers):
        marker = " ★ (Predeterminada)" if p == default else ""
        print(f"  [{i + 1}] {p}{marker}")

    print()
    choice = input(f"Selecciona impresora (1-{len(printers)}) [Enter = predeterminada]: ").strip()

    if choice == "" or not choice.isdigit():
        selected = default
    else:
        idx = int(choice) - 1
        if 0 <= idx < len(printers):
            selected = printers[idx]
        else:
            selected = default

    print(f"\n  ✓ Impresora seleccionada: {selected}\n")
    return selected


def print_ticket_raw(config, ticket_data):
    """
    Envía el ticket a la impresora usando RAW mode (ESC/POS).
    Compatible con impresoras térmicas de recibos.
    Soporta conexión USB (win32print) y Red (TCP socket).
    """
    try:
        items = json.loads(ticket_data.get('items_json', '[]'))
    except (json.JSONDecodeError, TypeError):
        items = []

    # ESC/POS Commands
    ESC = b'\x1b'
    GS = b'\x1d'
    LF = b'\x0a'

    init = ESC + b'@'                    # Initialize
    center = ESC + b'a' + b'\x01'        # Center align
    left = ESC + b'a' + b'\x00'          # Left align
    bold_on = ESC + b'E' + b'\x01'       # Bold ON
    bold_off = ESC + b'E' + b'\x00'      # Bold OFF
    double_size = ESC + b'!' + b'\x30'   # Double width+height
    normal_size = ESC + b'!' + b'\x00'   # Normal
    cut = LF + LF + LF + LF + GS + b'V' + bytes([66, 0])  # Cut paper

    # Build ticket
    data = init
    data += center
    data += double_size + b'COMANDA COCINA' + normal_size + LF
    data += left
    data += encode_safe(f"Mesa:   {ticket_data.get('table_name', 'N/A')}") + LF
    data += encode_safe(f"Mesero: {ticket_data.get('waiter_name', 'N/A')}") + LF
    data += encode_safe(f"Fecha:  {ticket_data.get('created_at', '')}") + LF
    data += b'-' * 32 + LF

    for item in items:
        qty = item.get('quantity', 1)
        name = item.get('product_name', '???')
        notes = item.get('notes', '')

        data += bold_on + double_size
        data += encode_safe(f"[{qty}] {name}") + LF
        data += bold_off + normal_size

        if notes:
            data += encode_safe(f"  * {notes} *") + LF

    data += b'-' * 32 + LF
    data += center + bold_on + b'FIN DE ORDEN' + bold_off + LF
    data += cut

    connection_type = config.get('connection_type', 'usb')

    if connection_type == 'network':
        # Send via TCP socket (port 9100)
        try:
            printer_ip = config['printer_ip']
            printer_port = int(config.get('printer_port', 9100))
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(5)
            sock.connect((printer_ip, printer_port))
            sock.sendall(data)
            sock.close()
            return True
        except Exception as e:
            print(f"  ✗ Error de red al imprimir: {e}")
            return False
    else:
        # Send via Windows RAW mode (USB)
        try:
            printer_name = config['printer']
            hprinter = win32print.OpenPrinter(printer_name)
            try:
                win32print.StartDocPrinter(hprinter, 1, ("Comanda Cocina", None, "RAW"))
                win32print.StartPagePrinter(hprinter)
                win32print.WritePrinter(hprinter, data)
                win32print.EndPagePrinter(hprinter)
                win32print.EndDocPrinter(hprinter)
                return True
            finally:
                win32print.ClosePrinter(hprinter)
        except Exception as e:
            print(f"  ✗ Error al imprimir: {e}")
            return False


def encode_safe(text):
    """Convierte texto a bytes, reemplazando caracteres especiales."""
    replacements = {
        'á': 'a', 'é': 'e', 'í': 'i', 'ó': 'o', 'ú': 'u',
        'Á': 'A', 'É': 'E', 'Í': 'I', 'Ó': 'O', 'Ú': 'U',
        'ñ': 'n', 'Ñ': 'N', 'ü': 'u', 'Ü': 'U',
    }
    for k, v in replacements.items():
        text = text.replace(k, v)
    try:
        return text.encode('cp437', errors='replace')
    except Exception:
        return text.encode('ascii', errors='replace')


def setup_wizard():
    """Asistente de configuración inicial."""
    print("\n" + "=" * 50)
    print("  RESTOCLOUD - CONFIGURACIÓN INICIAL")
    print("=" * 50)

    # URL del sistema
    url = input("\n  URL de tu sistema (ej: https://mirestaurante.com): ").strip().rstrip('/')
    if not url.startswith('http'):
        url = 'https://' + url

    # API Key
    print("\n  El Token de Impresora lo encuentras en:")
    print("  Configuración → Operativa de Cocina → Token de Impresora")
    print()
    api_key = input("  Pega tu Token aquí: ").strip()

    # Connection type
    print("\n" + "=" * 50)
    print("  TIPO DE CONEXIÓN")
    print("=" * 50)
    print("  [1] 🔌 USB (impresora conectada por cable)")
    print("  [2] 🌐 Red (impresora conectada por WiFi/Ethernet)")
    print()
    conn_choice = input("  Selecciona (1-2) [Enter = USB]: ").strip()

    config = {
        'url': url,
        'api_key': api_key,
        'poll_interval': 3
    }

    if conn_choice == '2':
        # Network printer
        config['connection_type'] = 'network'
        printer_ip = input("\n  IP de la impresora (ej: 192.168.1.100): ").strip()
        printer_port = input("  Puerto [Enter = 9100]: ").strip()
        config['printer_ip'] = printer_ip
        config['printer_port'] = int(printer_port) if printer_port else 9100
        config['printer'] = f"{printer_ip}:{config['printer_port']}"

        print(f"\n  Probando conexión a {printer_ip}:{config['printer_port']}...")
        try:
            sock = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
            sock.settimeout(3)
            sock.connect((printer_ip, config['printer_port']))
            sock.close()
            print("  ✓ ¡Impresora encontrada!")
        except Exception:
            print("  ⚠ No se pudo conectar. Verifica la IP y que esté encendida.")
            print("  (Se guardará la config de todas formas, podrás cambiarla después)")
    else:
        # USB printer
        config['connection_type'] = 'usb'
        config['printer'] = select_printer()

    save_config(config)

    print("\n  ✓ Configuración guardada!")
    print(f"  ✓ URL: {url}")
    print(f"  ✓ Conexión: {'Red' if config['connection_type'] == 'network' else 'USB'}")
    print(f"  ✓ Impresora: {config['printer']}")
    print()
    return config


def main():
    os.system('title RestoCloud - Cliente de Impresion')
    os.system('cls' if os.name == 'nt' else 'clear')

    if '--reset' in sys.argv:
        if os.path.exists(CONFIG_FILE):
            os.remove(CONFIG_FILE)
            print("\n  ✓ Configuración anterior borrada correctamente.\n")

    print()
    print("  ██████╗ ███████╗███████╗████████╗ ██████╗ ")
    print("  ██╔══██╗██╔════╝██╔════╝╚══██╔══╝██╔═══██╗")
    print("  ██████╔╝█████╗  ███████╗   ██║   ██║   ██║")
    print("  ██╔══██╗██╔══╝  ╚════██║   ██║   ██║   ██║")
    print("  ██║  ██║███████╗███████║   ██║   ╚██████╔╝")
    print("  ╚═╝  ╚═╝╚══════╝╚══════╝   ╚═╝    ╚═════╝ ")
    print("  Cliente de Impresión Local v1.0")
    print()

    # Load or create config
    config = load_config()
    if not config.get('url') or not config.get('api_key') or not config.get('printer'):
        config = setup_wizard()

    url = config['url']
    api_key = config['api_key']
    printer = config['printer']
    interval = config.get('poll_interval', 3)

    print("=" * 50)
    print(f"  Servidor:   {url}")
    conn_type = config.get('connection_type', 'usb')
    if conn_type == 'network':
        print(f"  Impresora:  {config.get('printer_ip')}:{config.get('printer_port', 9100)} (Red)")
    else:
        print(f"  Impresora:  {printer} (USB)")
    print(f"  Intervalo:  {interval}s")
    print("=" * 50)
    print()
    print("  Escuchando pedidos... (Ctrl+C para detener)")
    print()

    # Create session with browser headers
    session = requests.Session()
    session.headers.update({
        'User-Agent': 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
        'Accept': 'application/json, text/plain, */*',
    })

    # Solve InfinityFree's security challenge
    print("  Conectando al servidor...")
    if not solve_infinityfree_challenge(session, f"{url}/api_print_jobs.php?token={api_key}"):
        print("  ✗ No se pudo conectar al servidor")
        input("Presiona Enter para salir...")
        return

    error_count = 0

    while True:
        try:
            # Poll for pending jobs
            resp = session.get(
                f"{url}/api_print_jobs.php",
                params={'token': api_key},
                timeout=10
            )
            resp.raise_for_status()
            data = resp.json()

            if error_count > 0:
                print("  ✓ Conexión restaurada")
                error_count = 0

            jobs = data.get('jobs', [])

            for job in jobs:
                job_id = job['id']
                table = job.get('table_name', '?')
                print(f"  🖨️  Nuevo pedido! Mesa: {table} (ID: {job_id})")

                # Print the ticket
                success = print_ticket_raw(config, job)

                if success:
                    # Mark as printed
                    session.post(
                        f"{url}/api_print_jobs.php",
                        data={'token': api_key, 'action': 'mark_printed', 'job_id': job_id},
                        timeout=10
                    )
                    print(f"  ✓ Ticket impreso correctamente")
                else:
                    print(f"  ✗ Fallo al imprimir, se reintentará...")

        except requests.exceptions.ConnectionError:
            error_count += 1
            if error_count == 1 or error_count % 10 == 0:
                print(f"  ⚠ Sin conexión al servidor... reintentando")
        except requests.exceptions.Timeout:
            error_count += 1
            if error_count == 1:
                print(f"  ⚠ Timeout del servidor")
        except KeyboardInterrupt:
            print("\n\n  Detenido por el usuario. ¡Hasta luego!")
            break
        except Exception as e:
            error_count += 1
            if error_count == 1:
                print(f"  ✗ Error: {e}")

        time.sleep(interval)


if __name__ == '__main__':
    main()
