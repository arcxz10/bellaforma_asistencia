<?php
/**
 * Descarga de pedidos en formato Syscafe (.xlsx) para importarlos en Syscafe.
 * Usa la plantilla de pedidos de Syscafe (PEDIDOS.xlsx, 60 columnas): ver syscafe_pedidos_xlsx.php
 *   pedido_syscafe.php?id=12   -> un archivo .xlsx con ese pedido
 *   pedido_syscafe.php?vendedor_p=..&estado_p=..&desde_p=..&hasta_p=..&buscar_p=..  -> un solo .xlsx con todos los pedidos filtrados
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
require_once "syscafe_pedidos_xlsx.php";
date_default_timezone_set("America/Bogota");
asegurarTablasVendedores($conexion);

$pedidos = array_values(array_filter(pedidosParaExportar($conexion), fn($p) => $p["estado"] !== "borrador" && $p["items"]));
if (!$pedidos) {
    ob_end_clean();
    exit("No hay pedidos con productos para descargar.");
}

$idUnico = (int) ($_GET["id"] ?? 0);
$tipoMime = "application/vnd.openxmlformats-officedocument.spreadsheetml.sheet";

if ($idUnico > 0 || count($pedidos) === 1) {
    $p = $pedidos[0];
    $binario = syscafe_pedidos_xlsx([$p]);
    $nombre = syscafe_pe_nombre_archivo($p);
} else {
    // varios pedidos: un solo archivo, del más antiguo al más reciente
    $binario = syscafe_pedidos_xlsx(array_reverse($pedidos));
    $nombre = "PEDIDOS_SYSCAFE_" . date("Ymd_His") . ".xlsx";
}

ob_end_clean();
header("Content-Type: " . $tipoMime);
header("Content-Disposition: attachment; filename=\"" . $nombre . "\"");
header("Content-Length: " . strlen($binario));
header("Cache-Control: private, no-store");
echo $binario;
