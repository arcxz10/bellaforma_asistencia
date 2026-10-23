<?php
session_start();
require_once "conexion.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: login.html");
    exit;
}

$tipo = $_POST["tipo"] ?? "";

if ($tipo !== "admin") {
    header("Location: login.html?error=tipo");
    exit;
}

$usuario = trim($_POST["usuario"] ?? "");
$password = $_POST["password"] ?? "";

if ($usuario === "" || $password === "") {
    header("Location: login.html?error=vacio&u=" . urlencode($usuario));
    exit;
}

require_once __DIR__ . "/admin_permisos.php";
adminAsegurarColumnas($conexion);

// Con rol y permisos (subadministradores). Si por alguna razón esas columnas no existen, se usa la consulta de siempre.
$stmt = false;
try {
    $stmt = $conexion->prepare("SELECT id, usuario, contraseña, activo, rol, permisos FROM administradores WHERE usuario = ? AND activo = 1 LIMIT 1");
} catch (Throwable $e) {
    $stmt = false;
}
if (!$stmt) {
    try {
        $stmt = $conexion->prepare("SELECT id, usuario, contraseña, activo FROM administradores WHERE usuario = ? AND activo = 1 LIMIT 1");
    } catch (Throwable $e) {
        $stmt = false;
    }
}

if (!$stmt) {
    header("Location: login.html?error=sistema");
    exit;
}

$stmt->bind_param("s", $usuario);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows !== 1) {
    $stmt->close();
    header("Location: login.html?error=credenciales&u=" . urlencode($usuario));
    exit;
}

$administrador = $resultado->fetch_assoc();
$stmt->close();

$contraseñaGuardada = $administrador["contraseña"];
$contraseñaCorrecta = false;

if (password_verify($password, $contraseñaGuardada)) {
    $contraseñaCorrecta = true;
} elseif (hash_equals((string) $contraseñaGuardada, (string) $password)) {
    $contraseñaCorrecta = true;
    $nuevaContraseña = password_hash($password, PASSWORD_DEFAULT);

    $sqlActualizar = "UPDATE administradores SET contraseña = ? WHERE id = ?";
    $stmtActualizar = $conexion->prepare($sqlActualizar);

    if ($stmtActualizar) {
        $idAdministrador = (int) $administrador["id"];
        $stmtActualizar->bind_param("si", $nuevaContraseña, $idAdministrador);
        $stmtActualizar->execute();
        $stmtActualizar->close();
    }
}

if (!$contraseñaCorrecta) {
    header("Location: login.html?error=credenciales&u=" . urlencode($usuario));
    exit;
}

session_regenerate_id(true);
$_SESSION["admin_id"] = (int) $administrador["id"];
$_SESSION["admin_usuario"] = $administrador["usuario"];
$_SESSION["tipo_sesion"] = "admin";
$_SESSION["admin_rol"] = (($administrador["rol"] ?? "admin") === "sub") ? "sub" : "admin";
$_SESSION["admin_permisos"] = $_SESSION["admin_rol"] === "sub" ? adminPermisosDesdeTexto($administrador["permisos"] ?? "") : [];

$conexion->close();
header("Location: admin.php");
exit;
?>
