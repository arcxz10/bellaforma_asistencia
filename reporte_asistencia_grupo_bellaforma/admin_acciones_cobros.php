<?php
/**
 * Acciones POST de «Pedidos por cobrar».
 * Se incluye desde admin.php dentro del bloque POST (usa $conexion y $accion).
 *   cob_cobrado  -> marca el pedido como cobrado (sale de la lista de pendientes)
 *   cob_reabrir  -> lo devuelve a «por cobrar»
 */

if (!in_array($accion, ["cob_cobrado", "cob_reabrir"], true)) {
    return;
}

require_once __DIR__ . "/vendedores_db.php";
require_once __DIR__ . "/cobros_lib.php";

if (!adminPuede("por_cobrar")) {
    redireccionar("No tiene permiso para realizar esta acción.", "error");
}

/** Vuelve a la sección con el mismo filtro que se estaba viendo. */
function cobIrASeccion(string $mensaje, string $tipo): void
{
    $_SESSION["mensaje"] = $mensaje;
    $_SESSION["tipo_mensaje"] = $tipo;
    $qs = (string) ($_POST["qs"] ?? "");
    $qs = preg_match('/^[A-Za-z0-9_=&%.\-+]*$/', $qs) ? $qs : "";
    header("Location: admin.php" . ($qs !== "" ? "?" . $qs : "") . "#por_cobrar");
    exit;
}

asegurarTablaCobros($conexion);

$idCob = (int) ($_POST["id"] ?? 0);
$pedCob = null;
if ($idCob > 0) {
    $pedCob = obtenerPedidos($conexion, ["con_borradores" => true], null, $idCob)[0] ?? null;
}
if (!$pedCob) {
    cobIrASeccion("El pedido ya no existe.", "error");
}

if ($accion === "cob_cobrado") {
    $st = $conexion->prepare(
        "INSERT INTO pedido_cobros (pedido_id, visto, cobrado, cobrado_en) VALUES (?, 1, 1, NOW())
         ON DUPLICATE KEY UPDATE visto = 1, cobrado = 1, cobrado_en = NOW()"
    );
    $st->bind_param("i", $idCob);
    $st->execute();
    $st->close();
    cobIrASeccion(tituloDocumento($pedCob) . " marcado como cobrado.", "exito");
}

// cob_reabrir
$st = $conexion->prepare("UPDATE pedido_cobros SET cobrado = 0, cobrado_en = NULL WHERE pedido_id = ?");
$st->bind_param("i", $idCob);
$st->execute();
$st->close();
cobIrASeccion(tituloDocumento($pedCob) . " volvió a «por cobrar».", "exito");
