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
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Acceso Ejecutivos de Negocios | Grupo Bellaforma</title>

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
                <h1 class="titulo-sistema">BForma — Sistema de Gestión de Ventas y Pedidos</h1>
            </div>

            <div class="login-card">
                <h2>Acceso Ejecutivos de Negocios</h2>
                <p class="login-description">
                    Ingrese el usuario y la contraseña que le entregó la empresa.
                </p>

                <?php if ($error !== ""): ?>
                    <div class="mensaje-error" role="alert" aria-live="assertive"><?= e($error) ?></div>
                <?php endif; ?>

                <form method="POST" action="login_vendedor.php" class="form-login">

                    <div class="form-group">
                        <label for="usuario"><svg viewBox="0 0 24 24" width="1.1em" height="1.1em" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-.2em;margin-right:.4em"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>Usuario</label>
                        <input type="text" id="usuario" name="usuario" placeholder="Ingrese su usuario"
                            autocomplete="username" value="<?= e($usuarioIngresado) ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="password"><svg viewBox="0 0 24 24" width="1.1em" height="1.1em" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="vertical-align:-.2em;margin-right:.4em"><rect x="3" y="11" width="18" height="11" rx="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>Contraseña</label>
                        <div class="campo-password">
                        <input type="password" id="password" name="password" placeholder="Ingrese su contraseña" autocomplete="current-password" required>
                        <button type="button" class="btn-ojo" id="btnOjo" aria-label="Mostrar contraseña" aria-pressed="false" title="Mostrar contraseña">
                            <svg class="ojo-abierto" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                            <svg class="ojo-cerrado" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/></svg>
                        </button>
                        </div>
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

    <script>
        (function () {
            var btn = document.getElementById("btnOjo");
            var campo = document.getElementById("password");
            if (btn && campo) {
                btn.addEventListener("click", function () {
                    var ver = campo.type === "password";
                    campo.type = ver ? "text" : "password";
                    btn.classList.toggle("activo", ver);
                    btn.setAttribute("aria-pressed", ver ? "true" : "false");
                    btn.setAttribute("aria-label", ver ? "Ocultar contraseña" : "Mostrar contraseña");
                    btn.title = ver ? "Ocultar contraseña" : "Mostrar contraseña";
                    campo.focus();
                });
            }
        })();
    </script>

    <script src="js/bellaforma-ui.js"></script>
</body>

</html>
