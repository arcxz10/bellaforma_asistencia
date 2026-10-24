<?php
/**
 * Acciones POST de «Pedidos contraentrega».
 * Se incluye desde admin.php dentro del bloque POST (usa $conexion y $accion).
 *   coe_cobrado  -> marca el pedido como cobrado (sale de la lista de pendientes)
 *   coe_reabrir  -> lo devuelve a «por cobrar»
 */

if (!in_array($accion, ["coe_cobrado", "coe_reabrir"], true)) {
    return;
}

require_once __DIR__ . "/vendedores_db.php";
require_once __DIR__ . "/contraentrega_lib.php";

if (!adminPuede("contraentrega")) {
    redireccionar("No tiene permiso para realizar esta acción.", "error");
}

/** Vuelve a la sección con el mismo filtro que se estaba viendo. */
function coeIrASeccion(string $mensaje, string $tipo): void
{
    $_SESSION["mensaje"] = $mensaje;
    $_SESSION["tipo_mensaje"] = $tipo;
    $qs = (string) ($_POST["qs"] ?? "");
    $qs = preg_match('/^[A-Za-z0-9_=&%.\-+]*$/', $qs) ? $qs : "";
    header("Location: admin.php" . ($qs !== "" ? "?" . $qs : "") . "#contraentrega");
    exit;
}

asegurarTablaCobros($conexion);

$idCoe = (int) ($_POST["id"] ?? 0);
$pedCoe = null;
if ($idCoe > 0) {
    $pedCoe = obtenerPedidos($conexion, ["con_borradores" => true], null, $idCoe)[0] ?? null;
}
if (!$pedCoe) {
    coeIrASeccion("El pedido ya no existe.", "error");
}

if ($accion === "coe_cobrado") {
    $st = $conexion->prepare(
        "INSERT INTO pedido_cobros (pedido_id, visto, cobrado, cobrado_en) VALUES (?, 1, 1, NOW())
         ON DUPLICATE KEY UPDATE visto = 1, cobrado = 1, cobrado_en = NOW()"
    );
    $st->bind_param("i", $idCoe);
    $st->execute();
    $st->close();
    coeIrASeccion(tituloDocumento($pedCoe) . " marcado como cobrado.", "exito");
}

// coe_reabrir
$st = $conexion->prepare("UPDATE pedido_cobros SET cobrado = 0, cobrado_en = NULL WHERE pedido_id = ?");
$st->bind_param("i", $idCoe);
$st->execute();
$st->close();
coeIrASeccion(tituloDocumento($pedCoe) . " volvió a «por cobrar».", "exito");
