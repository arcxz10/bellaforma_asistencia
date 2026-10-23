<?php
/**
 * Comprobante de pago de los pedidos a crédito («Pedidos por cobrar»).
 *   GET  cobro_comprobante.php?id=53                  -> el comprobante (imagen o PDF); &descargar=1 lo baja como archivo
 *   GET  cobro_comprobante.php?op=estado              -> JSON {nuevos, por_cobrar} para la campanita del administrador
 *   POST cobro_comprobante.php op=subir pedido_id=53 archivo=<imagen|pdf> csrf=…   (ejecutivo dueño del pedido)
 *   POST cobro_comprobante.php op=visto pedido_id=53                                (administrador: ya lo abrió)
 *
 * El comprobante se guarda en la base de datos (tabla pedido_cobros), así no se pierde cuando Railway reinicia el servidor.
 */
session_start();
$adminId = (int) ($_SESSION["admin_id"] ?? 0);
$vendedorSesion = (int) ($_SESSION["vendedor_id"] ?? 0);
$csrfSesion = (string) ($_SESSION["csrf_pedido"] ?? "");

ini_set("display_errors", 0);
ob_start();

require_once __DIR__ . "/conexion.php";
require_once __DIR__ . "/vendedores_db.php";
require_once __DIR__ . "/admin_permisos.php";
require_once __DIR__ . "/cobros_lib.php";
date_default_timezone_set("America/Bogota");

// El administrador principal siempre puede; un subadministrador, solo con el módulo «Pedidos por cobrar»
$adminPuede = $adminId > 0 && adminPuede("por_cobrar");
session_write_close();

function cobroJson(array $r, int $codigo = 200): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($codigo);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

function cobroTexto(string $t, int $codigo): void
{
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($codigo);
    header("Content-Type: text/plain; charset=utf-8");
    exit($t);
}

try {
    asegurarTablasVendedores($conexion);
    asegurarTablaCobros($conexion);
} catch (Throwable $e) {
    if ($_SERVER["REQUEST_METHOD"] === "POST") {
        cobroJson(["ok" => false, "error" => "No se pudo preparar la base de datos. Intente de nuevo."], 500);
    }
    cobroTexto("Error del servidor.", 500);
}

/** Pedido completo o null si no existe. */
function cobroPedido(mysqli $c, int $id): ?array
{
    $st = $c->prepare("SELECT p.*, (SELECT cb.cobrado FROM pedido_cobros cb WHERE cb.pedido_id = p.id) AS cb_cobrado
                       FROM pedidos_vendedores p WHERE p.id = ? LIMIT 1");
    $st->bind_param("i", $id);
    $st->execute();
    $f = $st->get_result()->fetch_assoc();
    $st->close();
    return $f ?: null;
}

/* ---------------- GET: estado para la campanita ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "GET" && ($_GET["op"] ?? "") === "estado") {
    if (!$adminPuede) {
        cobroJson(["ok" => false, "error" => "Sin permiso."], 403);
    }
    cobroJson(["ok" => true, "nuevos" => cobroContarNuevos($conexion), "por_cobrar" => cobroContarPorCobrar($conexion)]);
}

/* ---------------- POST ---------------- */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $op = (string) ($_POST["op"] ?? "");
    $id = (int) ($_POST["pedido_id"] ?? 0);

    try {
        /* ---- Administrador: marcar el comprobante como visto ---- */
        if ($op === "visto") {
            if (!$adminPuede) {
                cobroJson(["ok" => false, "error" => "Sin permiso."], 403);
            }
            if (($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") !== "fetch") {
                cobroJson(["ok" => false, "error" => "Solicitud no válida."], 400);
            }
            $st = $conexion->prepare("UPDATE pedido_cobros SET visto = 1 WHERE pedido_id = ?");
            $st->bind_param("i", $id);
            $st->execute();
            $st->close();
            cobroJson(["ok" => true, "nuevos" => cobroContarNuevos($conexion)]);
        }

        /* ---- Ejecutivo: subir (o cambiar) el comprobante ---- */
        if ($op !== "subir") {
            cobroJson(["ok" => false, "error" => "Operación no válida."], 400);
        }
        if ($vendedorSesion <= 0) {
            cobroJson(["ok" => false, "error" => "Sesión no válida. Ingrese de nuevo."], 401);
        }
        if ($csrfSesion === "" || !hash_equals($csrfSesion, (string) ($_POST["csrf"] ?? ""))) {
            cobroJson(["ok" => false, "error" => "La sesión expiró. Recargue la página."], 403);
        }

        $ped = $id > 0 ? cobroPedido($conexion, $id) : null;
        if (!$ped) {
            cobroJson(["ok" => false, "error" => "No se encontró el pedido."], 404);
        }
        if ((int) $ped["vendedor_id"] !== $vendedorSesion) {
            cobroJson(["ok" => false, "error" => "Este pedido no te pertenece."], 403);
        }
        if (!cobroEsACredito($ped)) {
            cobroJson(["ok" => false, "error" => "Este pedido no es a crédito o ya no está vigente."], 422);
        }
        if ((int) ($ped["cb_cobrado"] ?? 0) === 1) {
            cobroJson(["ok" => false, "error" => "Este pedido ya fue marcado como cobrado."], 422);
        }

        $f = $_FILES["archivo"] ?? null;
        if (!$f || ($f["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $msg = (($f["error"] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f["error"] ?? 0) === UPLOAD_ERR_FORM_SIZE)
                ? "El archivo es muy pesado."
                : "No llegó el archivo. Intente de nuevo.";
            cobroJson(["ok" => false, "error" => $msg], 422);
        }
        if ((int) $f["size"] > 6 * 1024 * 1024) {
            cobroJson(["ok" => false, "error" => "El archivo es muy pesado (máximo 6 MB)."], 422);
        }

        $bin = file_get_contents($f["tmp_name"]);
        if ($bin === false || $bin === "") {
            cobroJson(["ok" => false, "error" => "No se pudo leer el archivo."], 422);
        }
        $mime = "";
        if (substr($bin, 0, 4) === "%PDF") {
            $mime = "application/pdf";
        } else {
            $info = @getimagesizefromstring($bin);
            $tiposOk = [IMAGETYPE_JPEG => "image/jpeg", IMAGETYPE_PNG => "image/png"];
            if (defined("IMAGETYPE_WEBP")) {
                $tiposOk[IMAGETYPE_WEBP] = "image/webp";
            }
            if ($info && isset($tiposOk[$info[2]])) {
                $mime = $tiposOk[$info[2]];
            }
        }
        if ($mime === "") {
            cobroJson(["ok" => false, "error" => "Solo se aceptan imágenes JPG, PNG, WEBP o un PDF."], 422);
        }

        $st = $conexion->prepare(
            "INSERT INTO pedido_cobros (pedido_id, mime, datos, subido_en, visto, cobrado)
             VALUES (?, ?, ?, NOW(), 0, 0)
             ON DUPLICATE KEY UPDATE mime = VALUES(mime), datos = VALUES(datos), subido_en = NOW(), visto = 0"
        );
        $nulo = null;
        $st->bind_param("isb", $id, $mime, $nulo);
        $st->send_long_data(2, $bin);
        $st->execute();
        $st->close();
        cobroJson(["ok" => true, "mensaje" => "Comprobante enviado. La oficina lo revisará."]);
    } catch (Throwable $e) {
        cobroJson(["ok" => false, "error" => "No se pudo guardar el comprobante. Intente de nuevo."], 500);
    }
}

/* ---------------- GET: el archivo ---------------- */
$id = (int) ($_GET["id"] ?? 0);
if ($id <= 0) {
    cobroTexto("No encontrado.", 404);
}
$ped = cobroPedido($conexion, $id);
if (!$ped) {
    cobroTexto("No encontrado.", 404);
}
if (!($adminPuede || ($vendedorSesion > 0 && (int) $ped["vendedor_id"] === $vendedorSesion))) {
    cobroTexto("Sin permiso.", 403);
}

$st = $conexion->prepare("SELECT mime, datos FROM pedido_cobros WHERE pedido_id = ? AND datos IS NOT NULL");
$st->bind_param("i", $id);
$st->execute();
$st->bind_result($mime, $datos);
$hay = $st->fetch();
$st->close();
if (!$hay) {
    cobroTexto("No encontrado.", 404);
}

while (ob_get_level() > 0) {
    ob_end_clean();
}
header("Content-Type: " . $mime);
header("Content-Length: " . strlen($datos));
header("Cache-Control: private, no-cache");
header("X-Content-Type-Options: nosniff");
$ext = $mime === "application/pdf" ? ".pdf" : ($mime === "image/png" ? ".png" : ($mime === "image/webp" ? ".webp" : ".jpg"));
$disp = !empty($_GET["descargar"]) ? "attachment" : "inline";
header('Content-Disposition: ' . $disp . '; filename="comprobante_cobro_pedido_' . $id . $ext . '"');
echo $datos;
