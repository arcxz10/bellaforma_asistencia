<?php
/**
 * Descarga de pedidos en formato Syscafe (.XLS) para importarlos en el pedido de Syscafe.
 *   pedido_syscafe.php?id=12   -> un archivo .XLS
 *   pedido_syscafe.php?vendedor_p=..&estado_p=..&desde_p=..&hasta_p=..&buscar_p=..  -> un .ZIP con un .XLS por pedido
 */
session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.html");
    exit;
}

ini_set("display_errors", 0);
ob_start();

require_once "conexion.php";
require_once "vendedores_db.php";
require_once "pedidos_lib.php";
require_once "xls_syscafe.php";
date_default_timezone_set("America/Bogota");
asegurarTablasVendedores($conexion);

$pedidos = array_values(array_filter(pedidosParaExportar($conexion), fn($p) => $p["estado"] !== "borrador" && $p["items"]));
if (!$pedidos) {
    ob_end_clean();
    exit("No hay pedidos con productos para descargar.");
}

$idUnico = (int) ($_GET["id"] ?? 0);

if ($idUnico > 0 || count($pedidos) === 1) {
    $p = $pedidos[0];
    $binario = syscafe_xls(syscafe_filas_pedido($p));
    ob_end_clean();
    header("Content-Type: application/vnd.ms-excel");
    header("Content-Disposition: attachment; filename=\"" . syscafe_nombre_archivo($p) . "\"");
    header("Content-Length: " . strlen($binario));
    header("Cache-Control: private, no-store");
    echo $binario;
    exit;
}

$archivos = [];
foreach (array_reverse($pedidos) as $p) {
    $archivos[syscafe_nombre_archivo($p)] = syscafe_xls(syscafe_filas_pedido($p));
}
$zip = xlsx_zip($archivos);

ob_end_clean();
header("Content-Type: application/zip");
header("Content-Disposition: attachment; filename=\"pedidos_syscafe_" . date("Ymd_His") . ".zip\"");
header("Content-Length: " . strlen($zip));
header("Cache-Control: private, no-store");
echo $zip;
