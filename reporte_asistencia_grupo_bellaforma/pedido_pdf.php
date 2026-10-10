<?php
/**
 * Descarga de pedidos en Excel (una fila por producto).
 *   pedido_excel.php?id=12   -> un pedido
 *   pedido_excel.php?vendedor_p=..&estado_p=..&desde_p=..&hasta_p=..&buscar_p=..  -> varios
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
require_once "xlsx_simple.php";
date_default_timezone_set("America/Bogota");
asegurarTablasVendedores($conexion);

$pedidos = pedidosParaExportar($conexion);
if (!$pedidos) {
    ob_end_clean();
    exit("No hay pedidos para descargar.");
}
$pedidos = array_reverse($pedidos);

$encabezados = [
    "Pedido", "Fecha", "Estado", "Tipo de cliente", "Ejecutivo", "ID ejecutivo", "Tipo de persona", "Tipo documento", "NIT / Cédula",
    "Razón social / Nombre", "Nombre comercial", "Teléfono", "Correo", "Departamento", "Municipio", "Barrio",
    "Dirección de entrega", "Puntos de referencia", "Factura electrónica", "Correo factura electrónica",
    "Condición de pago", "Días de crédito", "Observaciones", "Referencia", "Descripción", "Cantidad",
    "Precio unitario", "Subtotal", "Total del pedido",
];
$filas = [];
foreach ($pedidos as $p) {
    $base = [
        numeroPedido($p["id"]),
        date("d/m/Y H:i", strtotime($p["creado_en"])),
        etiquetaEstadoPedido($p["estado"]),
        etiquetaTipoCliente($p["tipo_cliente"] ?? "mayorista"),
        $p["vendedor_nombre"],
        $p["vendedor_identificacion"],
        ($p["cliente_tipo_persona"] ?? "juridica") === "natural" ? "Natural" : "Jurídica",
        etiquetaDocumento($p["cliente_tipo_persona"] ?? "juridica"),
        $p["cliente_nit"],
        $p["cliente_nombre"],
        $p["cliente_nombre_comercial"],
        $p["cliente_telefono"],
        $p["cliente_email"],
        $p["cliente_departamento"],
        $p["cliente_ciudad"],
        $p["cliente_barrio"],
        $p["cliente_direcciones"],
        $p["cliente_puntos_referencia"],
        !empty($p["factura_electronica"]) ? "Sí" : "No",
        $p["email_fe"],
        ($p["condicion_pago"] ?? "contado") === "credito" ? "Crédito" : "Contado",
        ($p["condicion_pago"] ?? "") === "credito" ? (int) $p["dias_credito"] : "",
        $p["observaciones"],
    ];
    if (!$p["items"]) {
        $filas[] = array_merge($base, ["", "", "", "", "", (float) $p["total"]]);
        continue;
    }
    foreach ($p["items"] as $it) {
        $filas[] = array_merge($base, [
            $it["referencia"], $it["nombre"], (int) $it["cantidad"],
            (float) $it["precio_unitario"], (float) $it["subtotal"], (float) $p["total"],
        ]);
    }
}

$anchos = [9, 17, 18, 14, 24, 14, 12, 12, 16, 34, 28, 16, 28, 20, 20, 20, 36, 36, 12, 28, 14, 10, 36, 14, 44, 10, 14, 14, 16];
$binario = xlsx_generar($encabezados, $filas, $anchos, [21, 25, 26, 27, 28]);

$idUnico = (int) ($_GET["id"] ?? 0);
$nombre = $idUnico > 0 ? "pedido_" . numeroPedido($idUnico) . ".xlsx" : "pedidos_" . date("Ymd_His") . ".xlsx";

ob_end_clean();
header("Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet");
header("Content-Disposition: attachment; filename=\"" . $nombre . "\"");
header("Content-Length: " . strlen($binario));
header("Cache-Control: private, no-store");
echo $binario;
