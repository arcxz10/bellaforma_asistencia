<?php
session_start();
require_once "conexion.php";
require_once "vendedores_db.php";
asegurarTablasVendedores($conexion);

if (!empty($_SESSION["vendedor_id"])) {
    header("Location: vendedor.php");
    exit;
}

function e($v)
{
    return htmlspecialchars((string) $v, ENT_QUOTES, "UTF-8");
}

$error = "";
$usuarioIngresado = "";

if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $usuarioIngresado = trim($_POST["usuario"] ?? "");
    $password = $_POST["password"] ?? "";

    if ($usuarioIngresado === "" || $password === "") {
        $error = "Debe ingresar usuario y contraseña.";
    } else {
        $stmt = $conexion->prepare(
            "SELECT id, nombre, password_hash FROM vendedores WHERE usuario = ? AND activo = 1 LIMIT 1"
        );
        $stmt->bind_param("s", $usuarioIngresado);
        $stmt->execute();
        $res = $stmt->get_result();
        $vendedor = $res ? $res->fetch_assoc() : null;
        $stmt->close();

        if ($vendedor && password_verify($password, $vendedor["password_hash"])) {
            session_regenerate_id(true);
            $_SESSION["vendedor_id"] = (int) $vendedor["id"];
            $_SESSION["vendedor_nombre"] = $vendedor["nombre"];
            header("Location: vendedor.php");
            exit;
        }

        $error = "Usuario o contraseña incorrectos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Acceso vendedores | Grupo Bellaforma</title>

    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="apple-touch-icon" href="img/apple-touch-icon.png">
    <link rel="stylesheet" href="css/login.css">
</head>

<body>

    <div class="container-login">
        <div class="form-container">

            <div class="header-login">
                <img src="img/logo-azul.png" alt="Grupo Bella Forma S.A.S." class="logo-marca">
                <p>Pedidos de vendedores</p>
            </div>

            <div class="login-card">
                <h2>Acceso vendedores</h2>
                <p class="login-description">
                    Ingrese el usuario y la contraseña que le entregó la empresa.
                </p>

                <?php if ($error !== ""): ?>
                    <div class="mensaje-error"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="login_vendedor.php" class="form-login">

                    <div class="form-group">
                        <label for="usuario">👤 Usuario</label>
                        <input type="text" id="usuario" name="usuario" placeholder="Ingrese su usuario"
                            autocomplete="username" value="<?= e($usuarioIngresado) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="password">🔒 Contraseña</label>
                        <input type="password" id="password" name="password" placeholder="Ingrese su contraseña"
                            autocomplete="current-password" required>
                    </div>

                    <button type="submit" class="btn-submit">
                        Ingresar
                    </button>

                </form>

                <a href="index.html" class="volver">
                    ← Volver al inicio
                </a>

            </div>
        </div>
    </div>

</body>

</html>
