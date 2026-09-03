<?php
require 'conexion.php';

$exito = false;
$error = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    $nombre = trim($_POST['nombre'] ?? '');
    $identificacion = trim($_POST['identificacion'] ?? '');
    $cargo = trim($_POST['cargo'] ?? '');
    $dispositivo_id = trim($_POST['dispositivo_id'] ?? '');

    $cargosPermitidos = ["Producción", "Ventas", "Administración"];

    if ($nombre === '' || $identificacion === '' || !in_array($cargo, $cargosPermitidos, true)) {
        $error = "Completa todos los campos correctamente.";
    } elseif ($dispositivo_id === '') {
        $error = "No se pudo identificar tu dispositivo. Recarga la página e inténtalo de nuevo.";
    } else {

        // Horario por defecto según el cargo (igual que cuando lo agrega un administrador)
        $horaEntrada = "08:30:00";
        $horaSalida = "17:15:00";

        if ($cargo === "Producción") {
            $horaEntrada = "07:30:00";
            $horaSalida = "17:05:00";
        }

        $sql = "
            INSERT INTO empleados
            (nombre, identificacion, cargo, hora_entrada, hora_salida, dispositivo_id, activo)
            VALUES (?, ?, ?, ?, ?, ?, 0)
        ";

        $stmt = $conexion->prepare($sql);

        if (!$stmt) {
            $error = "No se pudo procesar tu registro. Intenta más tarde.";
        } else {

            $stmt->bind_param(
                "ssssss",
                $nombre,
                $identificacion,
                $cargo,
                $horaEntrada,
                $horaSalida,
                $dispositivo_id
            );

            if ($stmt->execute()) {
                $exito = true;
            } elseif ($stmt->errno === 1062) {
                $error = "Ya existe un registro con esa identificación.";
            } else {
                $error = "No se pudo guardar tu registro. Intenta más tarde.";
            }

            $stmt->close();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Registrarme por primera vez | Grupo Bellaforma</title>
    <link rel="stylesheet" href="css/registro.css">
    <link rel="stylesheet" href="css/registro_inicial.css">
</head>
<body>
    <div class="registro-header">
        <div class="header-title">💅 Bellaforma</div>
    </div>

    <div class="container-registro">
        <div class="registro-card">

            <?php if ($exito): ?>

                <div class="card-header">
                    <h2>✅ ¡Listo!</h2>
                    <p>Tu registro fue enviado correctamente.</p>
                </div>

                <div class="alert alert-info">
                    <div class="alert-icon">ℹ️</div>
                    <div class="alert-content">
                        Un administrador debe <strong>activar tu usuario</strong> antes de
                        que puedas marcar tu asistencia. Avísale que ya te registraste.
                    </div>
                </div>

                <a href="registro.html" class="btn btn-primary" style="display:block; text-align:center; margin-top: 15px;">
                    Ir a Registrar Asistencia
                </a>

            <?php else: ?>

                <div class="card-header">
                    <h2>Registrarme por primera vez</h2>
                    <p>Completa tus datos para crear tu registro en el sistema</p>
                </div>

                <?php if ($error !== ""): ?>
                    <div class="alert alert-danger">
                        <div class="alert-icon">❌</div>
                        <div class="alert-content"><?php echo htmlspecialchars($error); ?></div>
                    </div>
                <?php endif; ?>

                <form method="POST" action="registro_empleado.php" id="formRegistroEmpleado">

                    <div class="form-group">
                        <label for="nombre">Nombre Completo <span class="form-required">*</span></label>
                        <input
                            type="text"
                            id="nombre"
                            name="nombre"
                            placeholder="Ej: Laura Gómez"
                            value="<?php echo htmlspecialchars($_POST['nombre'] ?? ''); ?>"
                            required
                            autofocus
                        >
                    </div>

                    <div class="form-group">
                        <label for="identificacion">Número de Documento <span class="form-required">*</span></label>
                        <input
                            type="text"
                            id="identificacion"
                            name="identificacion"
                            placeholder="Ej: 1234567890"
                            value="<?php echo htmlspecialchars($_POST['identificacion'] ?? ''); ?>"
                            required
                        >
                    </div>

                    <div class="form-group">
                        <label for="cargo">Cargo <span class="form-required">*</span></label>
                        <select id="cargo" name="cargo" required>
                            <option value="">Seleccione...</option>
                            <option value="Producción">Producción</option>
                            <option value="Ventas">Ventas</option>
                            <option value="Administración">Administración</option>
                        </select>
                    </div>

                    <input type="hidden" id="dispositivo_id" name="dispositivo_id" value="">

                    <button type="submit" class="btn btn-primary">Registrarme</button>
                    <a href="registro.html" class="btn btn-back">← Volver a Registro</a>

                </form>

            <?php endif; ?>

        </div>
    </div>

    <script>
        // Mismo dispositivo que usa registro.html: si ya se generó uno
        // para este navegador se reutiliza, si no, se crea una vez.
        function obtenerIdDispositivo() {
            let id = localStorage.getItem('bellaforma_dispositivo_id');

            if (!id) {
                id = 'DISP-' + crypto.randomUUID();
                localStorage.setItem('bellaforma_dispositivo_id', id);
            }

            return id;
        }

        const campoDispositivo = document.getElementById('dispositivo_id');
        if (campoDispositivo) {
            campoDispositivo.value = obtenerIdDispositivo();
        }
    </script>
</body>
</html>
