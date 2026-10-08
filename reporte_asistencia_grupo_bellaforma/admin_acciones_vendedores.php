<?php
/**
 * Acciones POST del módulo de ejecutivos / productos / pedidos / clientes.
 * Se incluye desde admin.php dentro del bloque POST (usa $conexion, $accion y redireccionar()).
 */

$accionesPropias = [
    "vend_crear", "vend_editar", "vend_estado",
    "prod_crear", "prod_editar", "prod_estado",
    "ped_estado",
    "cli_guardar", "cli_estado", "cli_importar",
];

if (!in_array($accion, $accionesPropias, true)) {
    return;
}

require_once __DIR__ . "/vendedores_db.php";
require_once __DIR__ . "/pedidos_lib.php";
require_once __DIR__ . "/clientes_lib.php";

/** Guarda (o reemplaza) la foto de un producto. Devuelve "" si todo va bien o el mensaje de error. */
function guardarFotoProducto(mysqli $c, int $productoId, array $archivo): string
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
    $st = $c->prepare(
        "INSERT INTO producto_fotos (producto_id, mime, datos) VALUES (?, ?, ?)
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

/* ---------------- PRODUCTOS AL POR MAYOR ---------------- */

if ($accion === "prod_crear" || $accion === "prod_editar") {

    $id = (int) ($_POST["id"] ?? 0);
    $referencia = trim($_POST["referencia"] ?? "");
    $nombre = trim($_POST["nombre"] ?? "");
    $precio = parsearPrecio((string) ($_POST["precio"] ?? ""));

    if ($referencia === "" || $nombre === "" || $precio <= 0) {
        redireccionar("Complete referencia, nombre y un precio mayor a 0.", "error", "productos_mayoristas");
    }

    try {
        if ($accion === "prod_crear") {
            $stmt = $conexion->prepare("INSERT INTO productos_mayoristas (referencia, nombre, precio) VALUES (?, ?, ?)");
            $stmt->bind_param("ssd", $referencia, $nombre, $precio);
            $stmt->execute();
            $id = (int) $conexion->insert_id;
            $stmt->close();
            $msg = "Producto agregado: " . $referencia;
        } else {
            $stmt = $conexion->prepare("UPDATE productos_mayoristas SET referencia = ?, nombre = ?, precio = ? WHERE id = ?");
            $stmt->bind_param("ssdi", $referencia, $nombre, $precio, $id);
            $stmt->execute();
            $stmt->close();
            $msg = "Producto actualizado.";
        }
    } catch (mysqli_sql_exception $ex) {
        if ((int) $ex->getCode() === 1062) {
            redireccionar("Ya existe un producto con esa referencia.", "error", "productos_mayoristas");
        }
        redireccionar("No se pudo guardar el producto.", "error", "productos_mayoristas");
    }

    if (!empty($_POST["quitar_foto"])) {
        $conexion->query("DELETE FROM producto_fotos WHERE producto_id = " . (int) $id);
    }
    if (isset($_FILES["foto"])) {
        $errFoto = guardarFotoProducto($conexion, $id, $_FILES["foto"]);
        if ($errFoto !== "") {
            redireccionar($msg . " Pero la foto no se guardó: " . $errFoto, "error", "productos_mayoristas");
        }
    }
    redireccionar($msg, "exito", "productos_mayoristas");
}

if ($accion === "prod_estado") {
    $id = (int) ($_POST["id"] ?? 0);
    $activo = ((int) ($_POST["activo"] ?? 0)) === 1 ? 1 : 0;
    $stmt = $conexion->prepare("UPDATE productos_mayoristas SET activo = ? WHERE id = ?");
    $stmt->bind_param("ii", $activo, $id);
    $stmt->execute();
    $stmt->close();
    redireccionar($activo ? "Producto activado." : "Producto desactivado (ya no aparece a los ejecutivos).", "exito", "productos_mayoristas");
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
    redireccionar("Pedido #" . numeroPedido($id) . " marcado como: " . etiquetaEstadoPedido($estado), "exito", "pedidos");
}

/* ---------------- CLIENTES ---------------- */

if ($accion === "cli_guardar") {
    $id = (int) ($_POST["id"] ?? 0);
    $r = guardarCliente($conexion, $_POST, $id ?: null);
    if (!$r["ok"]) {
        redireccionar($r["error"], "error", "clientes");
    }
    redireccionar($id ? "Cliente actualizado." : "Cliente creado.", "exito", "clientes");
}

if ($accion === "cli_estado") {
    $id = (int) ($_POST["id"] ?? 0);
    $inactivo = ((int) ($_POST["inactivo"] ?? 0)) === 1 ? 1 : 0;
    $stmt = $conexion->prepare("UPDATE clientes SET inactivo = ? WHERE id = ?");
    $stmt->bind_param("ii", $inactivo, $id);
    $stmt->execute();
    $stmt->close();
    redireccionar($inactivo ? "Cliente marcado como inactivo." : "Cliente activado.", "exito", "clientes");
}

if ($accion === "cli_importar") {
    @set_time_limit(300);
    $f = $_FILES["archivo"] ?? null;
    if (!$f || ($f["error"] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        redireccionar("Seleccione el archivo CSV de clientes (si es muy pesado, divídalo en partes).", "error", "clientes");
    }
    $r = importarClientesCSV($conexion, $f["tmp_name"]);
    if (!$r["ok"]) {
        redireccionar($r["error"], "error", "clientes");
    }
    $txt = "Importación lista: " . $r["nuevos"] . " clientes nuevos y " . $r["actualizados"] . " actualizados.";
    if ($r["errores"]) {
        $txt .= " Filas omitidas por datos incompletos: " . implode(", ", $r["errores"]) . ".";
    }
    redireccionar($txt, "exito", "clientes");
}
