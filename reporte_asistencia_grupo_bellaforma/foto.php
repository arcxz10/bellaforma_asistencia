<?php
/** Entrega la foto de un producto (guardada en la base de datos o en img/productos/REFERENCIA.jpg). */
session_cache_limiter("");
session_start();

if (empty($_SESSION["admin_id"]) && empty($_SESSION["vendedor_id"])) {
    http_response_code(403);
    exit;
}

require_once "conexion.php";

$id = (int) ($_GET["id"] ?? 0);
if ($id <= 0) {
    http_response_code(404);
    exit;
}
session_write_close();

require_once "vendedores_db.php";
$tablaFoto = tablaFotos();
$tablaProd = tablaProductos();

$stmt = $conexion->prepare("SELECT mime, datos FROM `$tablaFoto` WHERE producto_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->bind_result($mime, $datos);
$hay = $stmt->fetch();
$stmt->close();

header("Cache-Control: private, max-age=2592000");

if ($hay) {
    header("Content-Type: " . $mime);
    header("Content-Length: " . strlen($datos));
    echo $datos;
    exit;
}

// Respaldo: archivo en img/productos/REFERENCIA.jpg
$r = $conexion->prepare("SELECT referencia FROM `$tablaProd` WHERE id = ?");
$r->bind_param("i", $id);
$r->execute();
$r->bind_result($ref);
$r->fetch();
$r->close();

$ref = preg_replace('/[^A-Za-z0-9_-]/', '', (string) $ref);
$ruta = __DIR__ . "/img/productos/" . $ref . ".jpg";
if ($ref !== "" && is_file($ruta)) {
    header("Content-Type: image/jpeg");
    readfile($ruta);
    exit;
}

http_response_code(404);
