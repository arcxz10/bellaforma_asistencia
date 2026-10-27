<?php
/**
 * Plantilla de REMISIONES para importar en Syscafe (archivo .XLS, Excel 2.0 / BIFF2, 16 columnas).
 * Es el mismo formato de REMISION_EJEMPLO.XLS:
 *   referencia | servicio | detalle | cant | pdto | vrunit2 | vrunit | vrtotal | piva | vripocon | tercero3 | ref1 | ref2 | ref3 | ref4 | codretef
 *
 * A diferencia de la plantilla de pedidos (60 columnas), aquí solo van las líneas de producto:
 * el tipo (RM1 / RM2), el número, el cliente, el vendedor y la forma de pago se eligen en Syscafe al importar.
 * Una remisión = un archivo.
 *
 * Reutiliza biff2_rec(), biff2_label(), biff2_numero(), syscafe_xls() y syscafe_mayusculas() de xls_syscafe.php.
 */
require_once __DIR__ . "/xls_syscafe.php";

/* ---- Ajustes (cámbielos aquí si Syscafe lo pide distinto) ---- */

/* false -> la columna «tercero3» sale vacía, igual que en REMISION_EJEMPLO.XLS.
   true  -> se escribe en «tercero3» el NIT / cédula del cliente. */
const SYSCAFE_RM_TERCERO3_CON_NIT = false;

/* Porcentaje que se escribe en la columna «piva». En el ejemplo va en 0 (el IVA lo calcula Syscafe según el documento RM1 / RM2). */
const SYSCAFE_RM_PIVA = 0;

/* Valor de la columna «codretef» (en el ejemplo es «A»). */
const SYSCAFE_RM_CODRETEF = "A";

/** ¿El documento es una remisión? */
function syscafe_es_remision(array $p): bool
{
    return (string) ($p["tipo_pedido"] ?? "pedido") === "remision";
}

/** Corta el texto a $max caracteres sin partir letras con tilde. */
function syscafe_rm_cortar(string $s, int $max): string
{
    return function_exists("mb_substr") ? mb_substr($s, 0, $max, "UTF-8") : substr($s, 0, $max);
}

/**
 * Filas (una por producto, sin encabezado y SIN fila final vacía, igual que el ejemplo) de una remisión.
 * Cada fila tiene los 16 valores en el orden de la plantilla.
 */
function syscafe_remision_filas(array $p): array
{
    $descPct = (float) ($p["descuento_pct"] ?? 0);           // descuento general del documento
    $nit = SYSCAFE_RM_TERCERO3_CON_NIT ? syscafe_nit($p) : "";

    $filas = [];
    foreach ($p["items"] as $it) {
        $cant = (float) $it["cantidad"];
        $precio = (float) $it["precio_unitario"];
        $esBon = !empty($it["bonificado"]);                   // bonificado: 100 % de descuento, no se cobra
        $esServ = !empty($it["es_servicio"]);                 // servicio (ej. FLETE 001): va en «servicio» y «referencia» queda vacía
        $bruto = round($cant * $precio, 2);

        if ($esBon) {
            $pdto = 100.0;
            $total = 0.0;
            $detalle = syscafe_rm_cortar((string) $it["nombre"], 86) . " (BONIFICADO)";
        } else {
            // El descuento general de la remisión se aplica línea por línea (la plantilla no tiene descuento de encabezado)
            $pdto = $descPct > 0 ? $descPct : 0.0;
            $total = $pdto > 0 ? round($bruto * (1 - $pdto / 100), 2) : $bruto;
            $detalle = syscafe_rm_cortar((string) $it["nombre"], 100);
        }

        $filas[] = [
            $esServ ? "" : (string) $it["referencia"],       // referencia
            $esServ ? (string) $it["referencia"] : "",       // servicio
            syscafe_mayusculas($detalle),                    // detalle
            $cant,                                           // cant
            $pdto,                                           // pdto (% de descuento de la línea)
            $precio,                                         // vrunit2
            $precio,                                         // vrunit
            $total,                                          // vrtotal
            (float) SYSCAFE_RM_PIVA,                         // piva
            0.0,                                             // vripocon
            $nit,                                            // tercero3
            "", "", "", "",                                  // ref1..ref4
            SYSCAFE_RM_CODRETEF,                             // codretef
        ];
    }
    return $filas;
}

/** Genera el .XLS de UNA remisión (mismo formato que REMISION_EJEMPLO.XLS). $p: pedido con su arreglo "items". */
function syscafe_remision_xls(array $p): string
{
    return syscafe_xls(syscafe_remision_filas($p));
}
