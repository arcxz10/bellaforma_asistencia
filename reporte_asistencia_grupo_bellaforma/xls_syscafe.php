<?php
/**
 * Genera archivos .XLS en el MISMO formato que exporta/importa Syscafe (Excel 2.0 / BIFF2, 16 columnas):
 * referencia, servicio, detalle, cant, pdto, vrunit2, vrunit, vrtotal, piva, vripocon, tercero3, ref1..ref4, codretef
 * (Syscafe no acepta .xlsx: "El formato del archivo Microsoft Excel no es válido").
 */
require_once __DIR__ . "/xlsx_simple.php";   // reutiliza xlsx_zip() para empaquetar varios pedidos

const SYSCAFE_ANCHOS = [0x1600, 0x0800, 0x4600, 0x0e00, 0x0500, 0x1000, 0x1000, 0x1000, 0x0500, 0x0e00, 0x1000, 0x1e00, 0x1e00, 0x1e00, 0x1e00, 0x0100];
const SYSCAFE_ENCABEZADOS = ["referencia", "servicio", "detalle", "cant", "pdto", "vrunit2", "vrunit", "vrtotal", "piva", "vripocon", "tercero3", "ref1", "ref2", "ref3", "ref4", "codretef"];

function biff2_rec(int $tipo, string $datos): string
{
    return pack("vv", $tipo, strlen($datos)) . $datos;
}

function biff2_cp1252(string $s): string
{
    $r = @iconv("UTF-8", "windows-1252//TRANSLIT", $s);
    return $r === false ? preg_replace('/[^\x20-\x7E]/', "?", $s) : $r;
}

function biff2_label(int $fila, int $col, string $s): string
{
    $b = substr(biff2_cp1252($s), 0, 255);
    return biff2_rec(4, pack("vv", $fila, $col) . "\x40\x00\x00" . chr(strlen($b)) . $b);
}

function biff2_numero(int $fila, int $col, float $v): string
{
    return biff2_rec(3, pack("vv", $fila, $col) . "\x40\x00\x00" . pack("e", $v));
}

/** $filas: arreglo de filas de 16 valores (texto o número), SIN la fila de encabezados. */
function syscafe_xls(array $filas): string
{
    $out = biff2_rec(9, pack("vv", 2, 0x10)) . biff2_rec(0x42, pack("v", 1252));
    $out .= biff2_rec(0, pack("vvvv", 0, count($filas) + 1, 0, 16));
    foreach (SYSCAFE_ANCHOS as $c => $w) {
        $out .= biff2_rec(0x24, pack("CCv", $c, $c, $w));
    }
    $todas = array_merge([SYSCAFE_ENCABEZADOS], $filas);
    foreach ($todas as $r => $fila) {
        foreach ($fila as $c => $v) {
            $out .= is_string($v) ? biff2_label($r, $c, $v) : biff2_numero($r, $c, (float) $v);
        }
    }
    return $out . biff2_rec(0x0A, "") . "\x00\x00";
}

function syscafe_mayusculas(string $s): string
{
    return strtr(strtoupper($s), ["á" => "Á", "é" => "É", "í" => "Í", "ó" => "Ó", "ú" => "Ú", "ü" => "Ü", "ñ" => "Ñ"]);
}

/** Filas de un pedido en el formato de Syscafe (más la fila final vacía que Syscafe siempre exporta). */
function syscafe_filas_pedido(array $pedido): array
{
    $filas = [];
    foreach ($pedido["items"] as $it) {
        $precio = (float) $it["precio_unitario"];
        $filas[] = [
            (string) $it["referencia"], "", syscafe_mayusculas((string) $it["nombre"]),
            (float) $it["cantidad"], 0.0, $precio, $precio, (float) $it["subtotal"], 0.0, 0.0,
            "", "", "", "", "", "A",
        ];
    }
    $filas[] = ["", "", "", 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, 0.0, "", "", "", "", "", ""];
    return $filas;
}

function syscafe_nombre_archivo(array $pedido): string
{
    $base = strtr((string) $pedido["cliente_nombre"], ["á" => "a", "é" => "e", "í" => "i", "ó" => "o", "ú" => "u", "ü" => "u", "ñ" => "n",
                                                     "Á" => "A", "É" => "E", "Í" => "I", "Ó" => "O", "Ú" => "U", "Ü" => "U", "Ñ" => "N"]);
    $base = trim(preg_replace('/[^A-Za-z0-9]+/', "_", strtoupper($base)) ?? "", "_");
    $base = substr($base, 0, 24);
    return "PEDIDO_" . numeroPedido($pedido["id"]) . ($base !== "" ? "_" . $base : "") . ".XLS";
}
