<?php
session_start();

// 1. PROCESAR EL FORMULARIO CUANDO SE ENVÍA VÍA POST
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    require_once "conexion.php";

    $tipo = $_POST["tipo"] ?? "";
    if ($tipo !== "admin") {
        $_SESSION["error"] = "Tipo de acceso no válido.";
        header("Location: login.php");
        exit;
    }

    $usuario = trim($_POST["usuario"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($usuario === "" || $password === "") {
        $_SESSION["error"] = "Debe ingresar usuario y contraseña.";
        header("Location: login.php");
        exit;
    }

    $sql = "SELECT id, usuario, contraseña, activo FROM administradores WHERE usuario = ? AND activo = 1 LIMIT 1";
    $stmt = $conexion->prepare($sql);

    if (!$stmt) {
        $_SESSION["error"] = "No se pudo procesar el inicio de sesión.";
        header("Location: login.php");
        exit;
    }

    $stmt->bind_param("s", $usuario);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows !== 1) {
        $stmt->close();
        $_SESSION["error"] = "Usuario o contraseña incorrectos.";
        header("Location: login.php");
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
        $_SESSION["error"] = "Usuario o contraseña incorrectos.";
        header("Location: login.php");
        exit;
    }

    session_regenerate_id(true);
    $_SESSION["admin_id"] = (int) $administrador["id"];
    $_SESSION["admin_usuario"] = $administrador["usuario"];
    $_SESSION["tipo_sesion"] = "admin";

    $conexion->close();
    header("Location: admin.php");
    exit;
}

// 2. OBTENER MENSAJE DE ERROR DE LA SESIÓN SI EXISTE
$mensajeError = $_SESSION["error"] ?? "";
unset($_SESSION["error"]);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso administrativo | Grupo Bellaforma</title>
    <link rel="stylesheet" href="css/login.css">
</head>

<body>

    <div class="container-login">
        <div class="form-container">

            <div class="header-login">
                <h1>🏢 BELLAFORMA</h1>
                <p>Sistema de Asistencia y Control</p>
            </div>

            <div class="login-card">
                <h2>Acceso administrativo</h2>
                <p class="login-description">
                    Ingrese sus credenciales para acceder al panel administrativo.
                </p>

                <?php if (!empty($mensajeError)): ?>
                    <div id="mensaje-error" class="mensaje-error" style="display: block;">
                        <?php echo htmlspecialchars($mensajeError); ?>
                    </div>
                <?php endif; ?>

                <form method="POST" action="login.php" class="form-login">

                    <input type="hidden" name="tipo" value="admin">

                    <div class="form-group">
                        <label for="usuario">👤 Usuario</label>
                        <input type="text" id="usuario" name="usuario" placeholder="Ingrese su usuario" autocomplete="username" required>
                    </div>

                    <div class="form-group">
                        <label for="password">🔒 Contraseña</label>
                        <input type="password" id="password" name="password" placeholder="Ingrese su contraseña" autocomplete="current-password" required>
                    </div>

                    <button type="submit" class="btn-submit">
                        Iniciar sesión
                    </button>

                </form>

                <div class="registro-asistencia">
                    <p>¿Desea registrar su asistencia?</p>
                    <a href="registro.html" class="btn-asistencia">
                        Registrar asistencia
                    </a>
                </div>

                <a href="index.html" class="volver">
                    ← Volver al inicio
                </a>

            </div>
        </div>
    </div>

</body>

</html>