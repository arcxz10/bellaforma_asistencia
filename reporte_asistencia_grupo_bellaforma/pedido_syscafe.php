<?php
/**
 * Descarga de documentos en formato Syscafe (.XLS) para importarlos en Syscafe (origen "Archivo estandar (xls)").
 *
 *  - PEDIDOS y COTIZACIONES -> plantilla de pedidos de Syscafe (PEDIDOS.xlsx, 60 columnas): ver pedidos_syscafe_xlsx.php
 *  - REMISIONES             -> plantilla de remisiones de Syscafe (REMISION_EJEMPLO.XLS, 16 columnas): ver remision_syscafe_xls.php
 *                              (una remisión = un archivo)
 *
 *   pedido_syscafe.php?id=12   -> un archivo .XLS con ese documento (si es remisión, sale con la plantilla de remisiones)
 *   pedido_syscafe.php?vendedor_p=..&estado_p=..&desde_p=..&hasta_p=..&buscar_p=..&doc_p=..
 *        -> todos los documentos filtrados. Si el resultado es un solo archivo se descarga tal cual;
 *           si hay remisiones (o varias) se entrega un .ZIP con: un .XLS con los pedidos/cotizaciones + un .XLS por cada remisión.
 */
session_start();

// Entra el administrador, o un ejecutivo (solo a sus propios pedidos y si tiene permiso: ver descargas_vendedor.php)
$esAdmin = isset($_SESSION["admin_id"]);
if (!$esAdmin && empty($_SESSION["vendedor_id"])) {
    header("Location: login.html");
    exit;
}

ini_set("display_errors", 0);
ob_start();

// Si algo falla (archivo faltante, función no encontrada...), se muestra el motivo en texto en vez de un "HTTP 500" en blanco
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e["type"], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header("Content-Type: text/plain; charset=utf-8");
        }
        echo "No se pudo generar el archivo de Syscafe.\n\nMotivo: " . $e["message"] . "\nArchivo: " . basename($e["file"]) . " (línea " . $e["line"] . ")";
    }
});

require_once "conexion.php";
require_once "vendedores_db.php";
require_once "pedidos_lib.php";
require_once "pedidos_syscafe_xlsx.php";
require_once "remision_syscafe_xls.php";
date_default_timezone_set("America/Bogota");
asegurarTablasVendedores($conexion);
if (!$esAdmin) {
    require_once __DIR__ . "/descargas_vendedor.php";
    autorizarDescargaVendedor($conexion, "syscafe");   // un solo documento (?id=), propio y no borrador
}

$pedidos = array_values(array_filter(pedidosParaExportar($conexion), fn($p) => $p["estado"] !== "borrador" && $p["items"]));
if (!$pedidos) {
    ob_end_clean();
    exit("No hay pedidos con productos para descargar.");
}

// formato=xlsx -> Excel moderno, idéntico a la plantilla PEDIDOS.xlsx que entrega Syscafe. Por defecto: .XLS antiguo.
// (Las remisiones siempre salen en .XLS: es el único formato que lee Syscafe.)
$esXlsx = strtolower((string) ($_GET["formato"] ?? "")) === "xlsx";
// encabezado=0 -> el .XLS de pedidos sale SIN la fila de títulos (prueba: Syscafe leyó la fila de títulos como si fuera un pedido)
$conEncabezado = (string) ($_GET["encabezado"] ?? "1") !== "0";

// Se separan: las remisiones usan su propia plantilla (16 columnas); el resto (pedidos y cotizaciones), la de 60 columnas
$remisiones = array_values(array_filter($pedidos, fn($p) => syscafe_es_remision($p)));
$otros = array_values(array_filter($pedidos, fn($p) => !syscafe_es_remision($p)));
// La plantilla de remisiones no tiene columna «Anulado»: una remisión anulada no se debe importar
$remisionesOk = array_values(array_filter($remisiones, fn($p) => $p["estado"] !== "anulado"));

$archivos = [];   // nombre => contenido

if ($otros) {
    if (count($otros) === 1) {
        $p = $otros[0];
        $binario = $esXlsx ? syscafe_pedidos_xlsx([$p]) : syscafe_pedidos_xls([$p], $conEncabezado);
        $nombre = syscafe_pe_nombre_archivo($p);
    } else {
        // varios pedidos: un solo archivo, del más antiguo al más reciente
        $otrosAsc = array_reverse($otros);
        $binario = $esXlsx ? syscafe_pedidos_xlsx($otrosAsc) : syscafe_pedidos_xls($otrosAsc, $conEncabezado);
        $nombre = "PEDIDOS_SYSCAFE_" . date("Ymd_His") . ".XLS";
    }
    if ($esXlsx) {
        $nombre = preg_replace('/\.XLS$/i', ".xlsx", $nombre);
    }
    $archivos[$nombre] = $binario;
}

// Una remisión = un archivo (REMISION_<número>_<CLIENTE>.XLS), del más antiguo al más reciente
foreach (array_reverse($remisionesOk) as $r) {
    $nombre = syscafe_pe_nombre_archivo($r);
    if (isset($archivos[$nombre])) {
        $nombre = preg_replace('/\.XLS$/i', "_" . (int) $r["id"] . ".XLS", $nombre);   // evita pisar archivos con el mismo nombre
    }
    $archivos[$nombre] = syscafe_remision_xls($r);
}

if (!$archivos) {
    ob_end_clean();
    exit("Las remisiones anuladas no se pueden importar a Syscafe.");
}

if (count($archivos) === 1) {
    $nombre = (string) array_key_first($archivos);
    $binario = $archivos[$nombre];
    $tipoMime = preg_match('/\.xlsx$/i', $nombre) ? "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" : "application/vnd.ms-excel";
} else {
    $binario = xlsx_zip($archivos);
    $nombre = "SYSCAFE_" . date("Ymd_His") . ".zip";
    $tipoMime = "application/zip";
}

ob_end_clean();
header("Content-Type: " . $tipoMime);
header("Content-Disposition: attachment; filename=\"" . $nombre . "\"");
header("Content-Length: " . strlen($binario));
header("Cache-Control: private, no-store");
echo $binario;
