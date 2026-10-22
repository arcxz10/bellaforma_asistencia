<?php
/**
 * Acciones POST del módulo de ejecutivos / productos / pedidos / clientes.
 * Se incluye desde admin.php dentro del bloque POST (usa $conexion, $accion y redireccionar()).
 */

$accionesPropias = [
    "vend_crear", "vend_editar", "vend_estado", "vend_descargas",
    "prod_crear", "prod_editar", "prod_estado", "prod_eliminar",
    "ped_estado", "ped_eliminar", "ped_numero",
    "desp_campo", "desp_guardar", "desp_eliminar",
    "cli_guardar", "cli_estado", "cli_importar",
    "prod_importar",
];

if (!in_array($accion, $accionesPropias, true)) {
    return;
}

require_once __DIR__ . "/vendedores_db.php";
require_once __DIR__ . "/pedidos_lib.php";
require_once __DIR__ . "/clientes_lib.php";
require_once __DIR__ . "/productos_lib.php";

/** Guarda (o reemplaza) la foto de un producto. Devuelve "" si todo va bien o el mensaje de error. */
function guardarFotoProducto(mysqli $c, string $lista, int $productoId, array $archivo): string
{
    $err = $archivo["error"] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_NO_FILE) {
        return "";
    }
    if ($err !== UPLOAD_ERR_OK) {
        return "No se pudo subir la foto (puede ser muy pesada).";
    }
    if (($archivo["size"] ?? 0) > 4 * 1024 * 1024) {
        return "La foto supera 4 MB.";
    }
    $datos = file_get_contents($archivo["tmp_name"]);
    $info = $datos !== false ? @getimagesizefromstring($datos) : false;
    if (!$info || !in_array($info["mime"], ["image/jpeg", "image/png", "image/webp", "image/gif"], true)) {
        return "La foto debe ser JPG, PNG o WEBP.";
    }
    $mime = $info["mime"];

    // Si el servidor tiene GD, se reduce el tamaño (además el navegador ya la reduce antes de subirla)
    if (function_exists("imagecreatefromstring") && max($info[0], $info[1]) > 900) {
        $im = @imagecreatefromstring($datos);
        if ($im) {
            $esc = 800 / max($info[0], $info[1]);
            $nw = max(1, (int) round($info[0] * $esc));
            $nh = max(1, (int) round($info[1] * $esc));
            $dst = imagecreatetruecolor($nw, $nh);
            imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
            imagecopyresampled($dst, $im, 0, 0, 0, 0, $nw, $nh, $info[0], $info[1]);
            ob_start();
            imagejpeg($dst, null, 82);
            $datos = ob_get_clean();
            $mime = "image/jpeg";
        }
    }

    $nulo = null;
    $tablaFoto = tablaFotos($lista);
    $st = $c->prepare(
        "INSERT INTO `$tablaFoto` (producto_id, mime, datos) VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE mime = VALUES(mime), datos = VALUES(datos)"
    );
    $st->bind_param("isb", $productoId, $mime, $nulo);
    $st->send_long_data(2, $datos);
    $st->execute();
    $st->close();
    return "";
}

/* ---------------- VENDEDORES ---------------- */

if ($accion === "vend_crear" || $accion === "vend_editar") {

    $id = (int) ($_POST["id"] ?? 0);
    $nombre = trim($_POST["nombre"] ?? "");
    $identificacion = trim($_POST["identificacion"] ?? "");
    $ciudad = trim($_POST["ciudad"] ?? "");
    $telefono = trim($_POST["telefono"] ?? "");
    $usuario = trim($_POST["usuario"] ?? "");
    $password = (string) ($_POST["password"] ?? "");
    $esNuevo = ($accion === "vend_crear");

    if ($nombre === "" || $identificacion === "" || $usuario === "") {
        redireccionar("Complete nombre, identificación y usuario.", "error", "vendedores");
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $usuario)) {
        redireccionar("El usuario solo puede tener letras, números, punto, guion y guion bajo (3 a 60 caracteres).", "error", "vendedores");
    }
    if ($esNuevo && strlen($password) < 6) {
        redireccionar("La contraseña debe tener mínimo 6 caracteres.", "error", "vendedores");
    }
    if (!$esNuevo && $password !== "" && strlen($password) < 6) {
        redireccionar("La nueva contraseña debe tener mínimo 6 caracteres.", "error", "vendedores");
    }

    try {
        if ($esNuevo) {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conexion->prepare(
                "INSERT INTO vendedores (nombre, identificacion, ciudad, telefono, usuario, password_hash) VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("ssssss", $nombre, $identificacion, $ciudad, $telefono, $usuario, $hash);
            $stmt->execute();
            $stmt->close();
            redireccionar("Ejecutivo creado. Usuario: " . $usuario, "exito", "vendedores");
        }

        if ($password !== "") {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $conexion->prepare(
                "UPDATE vendedores SET nombre = ?, identificacion = ?, ciudad = ?, telefono = ?, usuario = ?, password_hash = ? WHERE id = ?"
            );
            $stmt->bind_param("ssssssi", $nombre, $identificacion, $ciudad, $telefono, $usuario, $hash, $id);
        } else {
            $stmt = $conexion->prepare(
                "UPDATE vendedores SET nombre = ?, identificacion = ?, ciudad = ?, telefono = ?, usuario = ? WHERE id = ?"
            );
            $stmt->bind_param("sssssi", $nombre, $identificacion, $ciudad, $telefono, $usuario, $id);
        }
        $stmt->execute();
        $stmt->close();
        redireccionar("Ejecutivo actualizado correctamente.", "exito", "vendedores");
    } catch (mysqli_sql_exception $ex) {
        if ((int) $ex->getCode() === 1062) {
            redireccionar("Ese usuario ya existe. Elija otro.", "error", "vendedores");
        }
        redireccionar("No se pudo guardar.", "error", "vendedores");
    }
}

if ($accion === "vend_estado") {
    $id = (int) ($_POST["id"] ?? 0);
    $activo = ((int) ($_POST["activo"] ?? 0)) === 1 ? 1 : 0;
    $stmt = $conexion->prepare("UPDATE vendedores SET activo = ? WHERE id = ?");
    $stmt->bind_param("ii", $activo, $id);
    $stmt->execute();
    $stmt->close();
    redireccionar($activo ? "Ejecutivo activado." : "Ejecutivo desactivado.", "exito", "vendedores");
}

if ($accion === "vend_descargas") {
    $id = (int) ($_POST["id"] ?? 0);
    $permiso = ((int) ($_POST["permiso"] ?? 0)) === 1 ? 1 : 0;
    $stmt = $conexion->prepare("UPDATE vendedores SET puede_descargar = ? WHERE id = ?");
    $stmt->bind_param("ii", $permiso, $id);
    $stmt->execute();
    $stmt->close();
    redireccionar($permiso ? "Ese ejecutivo ahora puede descargar el PDF y el Excel de sus pedidos y editar el número del documento." : "Se quitaron los permisos extra (descargas y número) a ese ejecutivo.", "exito", "vendedores");
}

/* ---------------- PRODUCTOS (un solo catálogo con precio mayorista y precio detal) ---------------- */

$seccionProd = "productos_mayoristas";

if ($accion === "prod_crear" || $accion === "prod_editar") {

    $id = (int) ($_POST["id"] ?? 0);
    $referencia = trim($_POST["referencia"] ?? "");
    $nombre = trim($_POST["nombre"] ?? "");
    $precioMay = parsearPrecio((string) ($_POST["precio_mayorista"] ?? ""));
    $precioDet = parsearPrecio((string) ($_POST["precio_detal"] ?? ""));

    if ($referencia === "" || $nombre === "" || ($precioMay <= 0 && $precioDet <= 0)) {
        redireccionar("Complete referencia, nombre y al menos un precio (mayorista o detal) mayor a 0.", "error", $seccionProd);
    }

    try {
        if ($accion === "prod_crear") {
            // la columna "precio" se mantiene igual al precio mayorista (compatibilidad)
            $stmt = $conexion->prepare("INSERT INTO productos_mayoristas (referencia, nombre, precio, precio_mayorista, precio_detal) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("ssddd", $referencia, $nombre, $precioMay, $precioMay, $precioDet);
            $stmt->execute();
            $id = (int) $conexion->insert_id;
            $stmt->close();
            $msg = "Producto agregado: " . $referencia;
        } else {
            $stmt = $conexion->prepare("UPDATE productos_mayoristas SET referencia = ?, nombre = ?, precio = ?, precio_mayorista = ?, precio_detal = ? WHERE id = ?");
            $stmt->bind_param("ssdddi", $referencia, $nombre, $precioMay, $precioMay, $precioDet, $id);
            $stmt->execute();
            $stmt->close();
            $msg = "Producto actualizado.";
        }
    } catch (mysqli_sql_exception $ex) {
        if ((int) $ex->getCode() === 1062) {
            redireccionar("Ya existe un producto con esa referencia.", "error", $seccionProd);
        }
        redireccionar("No se pudo guardar el producto: " . substr($ex->getMessage(), 0, 140), "error", $seccionProd);
    }

    if (!empty($_POST["quitar_foto"])) {
        $conexion->query("DELETE FROM producto_fotos WHERE producto_id = " . (int) $id);
    }
    if (isset($_FILES["foto"])) {
        $errFoto = guardarFotoProducto($conexion, "mayorista", $id, $_FILES["foto"]);
        if ($errFoto !== "") {
            redireccionar($msg . " Pero la foto no se guardó: " . $errFoto, "error", $seccionProd);
        }
    }
    redireccionar($msg, "exito", $seccionProd);
}

if ($accion === "prod_estado") {
    $id = (int) ($_POST["id"] ?? 0);
    $activo = ((int) ($_POST["activo"] ?? 0)) === 1 ? 1 : 0;
    $stmt = $conexion->prepare("UPDATE productos_mayoristas SET activo = ? WHERE id = ?");
    $stmt->bind_param("ii", $activo, $id);
    $stmt->execute();
    $stmt->close();
    redireccionar($activo ? "Producto activado." : "Producto desactivado (ya no aparece a los ejecutivos).", "exito", $seccionProd);
}

if ($accion === "prod_eliminar") {
    $id = (int) ($_POST["id"] ?? 0);
    if ($id <= 0) {
        redireccionar("Producto no válido.", "error", $seccionProd);
    }
    $stmt = $conexion->prepare("SELECT referencia FROM productos_mayoristas WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$fila) {
        redireccionar("El producto ya no existe.", "error", $seccionProd);
    }
    try {
        $conexion->begin_transaction();
        $stmt = $conexion->prepare("DELETE FROM producto_fotos WHERE producto_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $stmt = $conexion->prepare("DELETE FROM productos_mayoristas WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $conexion->commit();
    } catch (Throwable $ex) {
        $conexion->rollback();
        redireccionar("No se pudo eliminar el producto: " . substr($ex->getMessage(), 0, 140), "error", $seccionProd);
    }
    redireccionar("Producto " . $fila["referencia"] . " eliminado definitivamente.", "exito", $seccionProd);
}

if ($accion === "prod_importar") {
    @set_time_limit(300);
    $f = $_FILES["archivo"] ?? null;
    if (!$f || ($f["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        redireccionar("Seleccione el archivo CSV de productos.", "error", $seccionProd);
    }
    $r = importarProductosCSV($conexion, $f["tmp_name"]);
    if (!$r["ok"]) {
        redireccionar($r["error"], "error", $seccionProd);
    }
    $txt = "Productos importados: " . $r["nuevos"] . " nuevos y " . $r["actualizados"] . " actualizados.";
    if ($r["errores"]) {
        $txt .= " Filas omitidas (faltan datos o precio): " . implode(", ", $r["errores"]) . ".";
    }
    redireccionar($txt, "exito", $seccionProd);
}

/* ---------------- PEDIDOS ---------------- */

if ($accion === "ped_estado") {
    $id = (int) ($_POST["id"] ?? 0);
    $estado = $_POST["estado"] ?? "";
    if (!in_array($estado, ["pendiente", "procesado", "anulado"], true)) {
        redireccionar("Estado de pedido no válido.", "error", "pedidos");
    }
    $stmt = $conexion->prepare("UPDATE pedidos_vendedores SET estado = ? WHERE id = ? AND estado <> 'borrador'");
    $stmt->bind_param("si", $estado, $id);
    $stmt->execute();
    $stmt->close();
    $filaDoc = obtenerPedidos($conexion, ["con_borradores" => true], null, $id)[0] ?? ["id" => $id];
    redireccionar(tituloDocumento($filaDoc) . " marcado como: " . etiquetaEstadoPedido($estado), "exito", "pedidos");
}

if ($accion === "ped_numero") {
    // Cambio rápido del tipo de documento y del número desde la lista de pedidos
    $id = (int) ($_POST["id"] ?? 0);
    $filaDoc = $id > 0 ? (obtenerPedidos($conexion, ["con_borradores" => true], null, $id)[0] ?? null) : null;
    if (!$filaDoc) {
        redireccionar("El pedido ya no existe.", "error", "pedidos");
    }
    $tipoNuevo = tipoDocumentoValido($_POST["tipo_documento"] ?? "pedido");
    $numNuevo = trim((string) ($_POST["numero"] ?? ""));
    $nombreNuevo = nombreCortoDocumento($tipoNuevo);
    if ($numNuevo !== "" && (!preg_match('/^\d{1,10}$/', $numNuevo) || (int) $numNuevo < 1)) {
        redireccionar("El número de la " . $nombreNuevo . " solo puede tener dígitos (máximo 10) y debe ser mayor que 0.", "error", "pedidos");
    }
    if ($filaDoc["estado"] !== "borrador") {
        $revisar = $numNuevo !== "" ? (int) $numNuevo : (int) $filaDoc["id"];
        if (numeroDocumentoEnUso($conexion, $tipoNuevo, $revisar, (int) $filaDoc["id"])) {
            redireccionar("El número " . ($numNuevo !== "" ? $numNuevo : numeroPedido($revisar)) . " ya está en uso por otra " . $nombreNuevo . ". Escriba uno distinto.", "error", "pedidos");
        }
    }
    $valorNum = $numNuevo !== "" ? $numNuevo : null;
    $stmt = $conexion->prepare("UPDATE pedidos_vendedores SET tipo_pedido = ?, numero_pedido = ? WHERE id = ?");
    $stmt->bind_param("ssi", $tipoNuevo, $valorNum, $id);
    $stmt->execute();
    $stmt->close();
    $filaDoc["tipo_pedido"] = $tipoNuevo;
    $filaDoc["numero_pedido"] = $valorNum;
    redireccionar(tituloDocumento($filaDoc) . " actualizado.", "exito", "pedidos");
}

if ($accion === "ped_eliminar") {
    $id = (int) ($_POST["id"] ?? 0);
    if ($id <= 0) {
        redireccionar("Pedido no válido.", "error", "pedidos");
    }
    $stmt = $conexion->prepare("SELECT id, tipo_pedido, numero_pedido FROM pedidos_vendedores WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $existe = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$existe) {
        redireccionar("El pedido ya no existe.", "error", "pedidos");
    }
    try {
        $conexion->begin_transaction();
        $stmt = $conexion->prepare("DELETE FROM despachos WHERE pedido_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $stmt = $conexion->prepare("DELETE FROM pedido_vendedor_items WHERE pedido_id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $stmt = $conexion->prepare("DELETE FROM pedidos_vendedores WHERE id = ?");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $stmt->close();
        $conexion->commit();
    } catch (Throwable $ex) {
        $conexion->rollback();
        redireccionar("No se pudo eliminar el pedido: " . substr($ex->getMessage(), 0, 140), "error", "pedidos");
    }
    redireccionar(tituloDocumento($existe) . " eliminado definitivamente.", "exito", "pedidos");
}

/* ---------------- CONTROL DE DESPACHOS ---------------- */

/** Vuelve a la sección del control de despachos, en el mismo día que se estaba viendo. */
function irADespachos(string $mensaje, string $tipo, string $fecha): void
{
    $_SESSION["mensaje"] = $mensaje;
    $_SESSION["tipo_mensaje"] = $tipo;
    $q = fechaDespachoValida($fecha) ? "?fecha_d=" . $fecha : "";
    header("Location: admin.php" . $q . "#despachos");
    exit;
}

if ($accion === "desp_campo") {
    // Edición en línea de una celda de la tabla (responde JSON, sin recargar la página)
    try {
        $r = actualizarCampoDespacho($conexion, (int) ($_POST["id"] ?? 0), (string) ($_POST["campo"] ?? ""), (string) ($_POST["valor"] ?? ""));
    } catch (Throwable $ex) {
        $r = ["ok" => false, "error" => "No se pudo guardar. Intente de nuevo."];
    }
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header("Content-Type: application/json; charset=utf-8");
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

if ($accion === "desp_guardar") {
    // Despacho manual o histórico (sin pedido): crear o editar sus datos
    $id = (int) ($_POST["id"] ?? 0);
    $fechaVista = (string) ($_POST["fecha_vista"] ?? ($_POST["fecha"] ?? ""));
    $datosDesp = $_POST;
    unset($datosDesp["_forma_actual"], $datosDesp["_transp_actual"]);
    if ($id > 0) {
        $st = $conexion->prepare("SELECT pedido_id, forma_pago, transportadora FROM despachos WHERE id = ?");
        $st->bind_param("i", $id);
        $st->execute();
        $actual = $st->get_result()->fetch_assoc();
        $st->close();
        if (!$actual) {
            irADespachos("Ese despacho ya no existe.", "error", $fechaVista);
        }
        if ($actual["pedido_id"] !== null) {
            irADespachos("Ese despacho viene de un pedido: sus datos se cambian en el pedido.", "error", $fechaVista);
        }
        $datosDesp["_forma_actual"] = $actual["forma_pago"];
        $datosDesp["_transp_actual"] = $actual["transportadora"];
    }
    $r = guardarDespachoManual($conexion, $id > 0 ? $id : null, $datosDesp);
    if (!$r["ok"]) {
        irADespachos($r["error"], "error", $fechaVista);
    }
    irADespachos($id > 0 ? "Despacho actualizado." : "Despacho agregado.", "exito", (string) ($datosDesp["fecha"] ?? $fechaVista));
}

if ($accion === "desp_eliminar") {
    $id = (int) ($_POST["id"] ?? 0);
    $fechaVista = (string) ($_POST["fecha_vista"] ?? "");
    $st = $conexion->prepare("SELECT pedido_id FROM despachos WHERE id = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$fila) {
        irADespachos("Ese despacho ya no existe.", "error", $fechaVista);
    }
    $st = $conexion->prepare("DELETE FROM despachos WHERE id = ?");
    $st->bind_param("i", $id);
    $st->execute();
    $st->close();
    irADespachos($fila["pedido_id"] !== null ? "Se quitó del control de despachos (el pedido sigue en Pedidos de ejecutivos)." : "Despacho eliminado.", "exito", $fechaVista);
}

/* ---------------- CLIENTES (una sola base; el campo tipo_cliente indica mayorista o detal) ---------------- */

$seccionCli = "clientes";
$tablaCli = tablaClientes();

if ($accion === "cli_guardar") {
    $id = (int) ($_POST["id"] ?? 0);
    $r = guardarCliente($conexion, $_POST, $id ?: null);
    if (!$r["ok"]) {
        redireccionar($r["error"], "error", $seccionCli);
    }
    redireccionar($id ? "Cliente actualizado." : "Cliente creado.", "exito", $seccionCli);
}

if ($accion === "cli_estado") {
    $id = (int) ($_POST["id"] ?? 0);
    $inactivo = ((int) ($_POST["inactivo"] ?? 0)) === 1 ? 1 : 0;
    $stmt = $conexion->prepare("UPDATE `$tablaCli` SET inactivo = ? WHERE id = ?");
    $stmt->bind_param("ii", $inactivo, $id);
    $stmt->execute();
    $stmt->close();
    redireccionar($inactivo ? "Cliente marcado como inactivo." : "Cliente activado.", "exito", $seccionCli);
}

if ($accion === "cli_importar") {
    require_once __DIR__ . "/clientes_importar.php";
    @set_time_limit(600);
    $f = $_FILES["archivo"] ?? null;
    $err = $f["error"] ?? UPLOAD_ERR_NO_FILE;
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        redireccionar("El archivo es demasiado pesado para el servidor. Divídalo en partes más pequeñas.", "error", $seccionCli);
    }
    if (!$f || $err !== UPLOAD_ERR_OK) {
        redireccionar("Seleccione el archivo de clientes (.xlsx o .csv).", "error", $seccionCli);
    }
    $r = importarClientesArchivo($conexion, (string) ($_POST["tipo_defecto"] ?? "mayorista"), $f["tmp_name"]);
    if (!$r["ok"]) {
        redireccionar($r["error"], "error", $seccionCli);
    }
    $txt = "Importación lista: " . $r["nuevos"] . " clientes nuevos y " . $r["actualizados"] . " actualizados.";
    if (!empty($r["por_hoja"]) && count($r["por_hoja"]) > 1) {
        $partes = [];
        foreach ($r["por_hoja"] as $h) {
            $partes[] = "hoja «" . $h["hoja"] . "» (" . $h["tipo"] . "): " . ($h["nuevos"] + $h["actualizados"]);
        }
        $txt .= " Por hoja → " . implode("; ", $partes) . ".";
    }
    if (!empty($r["total_omitidos"])) {
        $txt .= " Se omitieron " . $r["total_omitidos"] . " por NIT/cédula inválido o sin nombre, por ejemplo: " . implode("; ", $r["omitidos"]) . ".";
    }
    redireccionar($txt, "exito", $seccionCli);
}
