<?php
/**
 * Simple Network Printer Helper for ESC/POS thermal printers
 */

function removeAccents($string) {
    $unwanted_array = array(    'Š'=>'S', 'š'=>'s', 'Ž'=>'Z', 'ž'=>'z', 'À'=>'A', 'Á'=>'A', 'Â'=>'A', 'Ã'=>'A', 'Ä'=>'A', 'Å'=>'A', 'Æ'=>'A', 'Ç'=>'C', 'È'=>'E', 'É'=>'E',
                                'Ê'=>'E', 'Ë'=>'E', 'Ì'=>'I', 'Í'=>'I', 'Î'=>'I', 'Ï'=>'I', 'Ñ'=>'N', 'Ò'=>'O', 'Ó'=>'O', 'Ô'=>'O', 'Õ'=>'O', 'Ö'=>'O', 'Ø'=>'O', 'Ù'=>'U',
                                'Ú'=>'U', 'Û'=>'U', 'Ü'=>'U', 'Ý'=>'Y', 'Þ'=>'B', 'ß'=>'Ss', 'à'=>'a', 'á'=>'a', 'â'=>'a', 'ã'=>'a', 'ä'=>'a', 'å'=>'a', 'æ'=>'a', 'ç'=>'c',
                                'è'=>'e', 'é'=>'e', 'ê'=>'e', 'ë'=>'e', 'ì'=>'i', 'í'=>'i', 'î'=>'i', 'ï'=>'i', 'ð'=>'o', 'ñ'=>'n', 'ò'=>'o', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o',
                                'ö'=>'o', 'ø'=>'o', 'ù'=>'u', 'ú'=>'u', 'û'=>'u', 'ý'=>'y', 'þ'=>'b', 'ÿ'=>'y' );
    return strtr( $string, $unwanted_array );
}

function sendToKitchenPrinter($ip, $port, $table_name, $waiter_name, $items) {
    if (empty($items)) return false;

    // ESC/POS Commands Universales (compatibles con Xprinter, Epson, Bixolon)
    $ESC = "\x1b";
    $GS = "\x1d";
    $LF = "\x0a"; // Line Feed

    $init = $ESC . "@"; // Initialize printer
    $center = $ESC . "a" . "\x01"; // Align center
    $left = $ESC . "a" . "\x00"; // Align left
    
    // Character size commands (ESC ! n)
    // 0 = Normal, 16 = Double height, 32 = Double width, 48 = Double size
    $double_size = $ESC . "!" . "\x30"; 
    $normal_size = $ESC . "!" . "\x00"; 
    
    // Feed and Cut (GS V 66 0 is the most widely supported partial/full cut)
    $cut = $LF . $LF . $LF . $LF . $GS . "V" . chr(66) . chr(0);

    $ticket = $init;
    $ticket .= $center;
    $ticket .= $double_size . "COMANDA COCINA" . $normal_size . $LF;
    
    $ticket .= $left;
    $ticket .= "Fecha: " . date('Y-m-d H:i:s') . $LF;
    $ticket .= "Mesa:  " . removeAccents($table_name) . $LF;
    $ticket .= "Mesero: " . removeAccents($waiter_name) . $LF;
    $ticket .= str_repeat("-", 32) . $LF;

    foreach ($items as $item) {
        $qty = $item['quantity'];
        $name = removeAccents($item['product_name']);
        
        $ticket .= $double_size . "[{$qty}] {$name}" . $normal_size . $LF;
        
        if (!empty($item['notes'])) {
            $notes = removeAccents($item['notes']);
            $ticket .= "  * {$notes} *" . $LF;
        }
    }

    $ticket .= str_repeat("-", 32) . $LF;
    $ticket .= $center . "FIN DE ORDEN" . $LF;
    $ticket .= $cut;

    // Send to printer via socket
    $fp = @fsockopen($ip, $port, $errno, $errstr, 3); // 3 seconds timeout
    if (!$fp) {
        error_log("No se pudo conectar a la impresora en $ip:$port - $errstr");
        return false;
    } else {
        fwrite($fp, $ticket);
        fclose($fp);
        return true;
    }
}
