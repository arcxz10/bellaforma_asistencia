<?php
/**
 * Acciones POST del módulo de vendedores / productos / pedidos.
 * Se incluye desde admin.php dentro del bloque POST (usa $conexion, $accion y redireccionar()).
 */

$accionesPropias = [
    "vend_crear", "vend_editar", "vend_estado",
    "prod_crear", "prod_editar", "prod_estado",
    "ped_estado",
];

if (!in_array($accion, $accionesPropias, true)) {
    return;
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
        redireccionar("Complete nombre, identificación y usuario del vendedor.", "error", "vendedores");
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
            redireccionar("Vendedor creado. Usuario: " . $usuario, "exito", "vendedores");
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
        redireccionar("Vendedor actualizado correctamente.", "exito", "vendedores");
    } catch (mysqli_sql_exception $ex) {
        if ((int) $ex->getCode() === 1062) {
            redireccionar("Ese usuario ya existe. Elija otro.", "error", "vendedores");
        }
        redireccionar("No se pudo guardar el vendedor.", "error", "vendedores");
    }
}

if ($accion === "vend_estado") {
    $id = (int) ($_POST["id"] ?? 0);
    $activo = ((int) ($_POST["activo"] ?? 0)) === 1 ? 1 : 0;
    $stmt = $conexion->prepare("UPDATE vendedores SET activo = ? WHERE id = ?");
    $stmt->bind_param("ii", $activo, $id);
    $stmt->execute();
    $stmt->close();
    redireccionar($activo ? "Vendedor activado." : "Vendedor desactivado.", "exito", "vendedores");
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
            $stmt->close();
            redireccionar("Producto agregado: " . $referencia, "exito", "productos_mayoristas");
        }

        $stmt = $conexion->prepare("UPDATE productos_mayoristas SET referencia = ?, nombre = ?, precio = ? WHERE id = ?");
        $stmt->bind_param("ssdi", $referencia, $nombre, $precio, $id);
        $stmt->execute();
        $stmt->close();
        redireccionar("Producto actualizado.", "exito", "productos_mayoristas");
    } catch (mysqli_sql_exception $ex) {
        if ((int) $ex->getCode() === 1062) {
            redireccionar("Ya existe un producto con esa referencia.", "error", "productos_mayoristas");
        }
        redireccionar("No se pudo guardar el producto.", "error", "productos_mayoristas");
    }
}

if ($accion === "prod_estado") {
    $id = (int) ($_POST["id"] ?? 0);
    $activo = ((int) ($_POST["activo"] ?? 0)) === 1 ? 1 : 0;
    $stmt = $conexion->prepare("UPDATE productos_mayoristas SET activo = ? WHERE id = ?");
    $stmt->bind_param("ii", $activo, $id);
    $stmt->execute();
    $stmt->close();
    redireccionar($activo ? "Producto activado." : "Producto desactivado (ya no aparece a los vendedores).", "exito", "productos_mayoristas");
}

/* ---------------- PEDIDOS ---------------- */

if ($accion === "ped_estado") {
    $id = (int) ($_POST["id"] ?? 0);
    $estado = $_POST["estado"] ?? "";
    if (!in_array($estado, ["pendiente", "procesado", "anulado"], true)) {
        redireccionar("Estado de pedido no válido.", "error", "pedidos");
    }
    $stmt = $conexion->prepare("UPDATE pedidos_vendedores SET estado = ? WHERE id = ?");
    $stmt->bind_param("si", $estado, $id);
    $stmt->execute();
    $stmt->close();
    redireccionar("Pedido #" . numeroPedido($id) . " marcado como: " . etiquetaEstadoPedido($estado), "exito", "pedidos");
}
