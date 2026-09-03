<?php
require 'conexion.php';

if (!isset($_POST['documento']) || !isset($_POST['dispositivo_id'])) {
    header('Location: registro.html');
    exit;
}

$documento = trim($_POST['documento']);
$dispositivo_id = trim($_POST['dispositivo_id']);

// Validar que no estén vacíos
if (empty($documento) || empty($dispositivo_id)) {
    header('Location: registro.html');
    exit;
}

// Buscar empleado
$consulta = "SELECT id, nombre, cargo FROM empleados WHERE identificacion = ? AND activo = 1";
$stmt = $conexion->prepare($consulta);
$stmt->bind_param('s', $documento);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows === 0) {
    $error = "Empleado no encontrado, inactivo o pendiente de aprobación";
} else {
    $empleado = $resultado->fetch_assoc();

    // Validar dispositivo
    $consulta_dispositivo = "SELECT dispositivo_id FROM empleados WHERE id = ?";
    $stmt2 = $conexion->prepare($consulta_dispositivo);
    $stmt2->bind_param('i', $empleado['id']);
    $stmt2->execute();
    $resultado2 = $stmt2->get_result();
    $empleado_dispositivo = $resultado2->fetch_assoc();

    if (empty($empleado_dispositivo['dispositivo_id'])) {
        // Primera vez que este empleado marca asistencia:
        // el dispositivo actual queda vinculado a él para siempre.
        $consulta_vincular = "UPDATE empleados SET dispositivo_id = ? WHERE id = ?";
        $stmt3 = $conexion->prepare($consulta_vincular);
        $stmt3->bind_param('si', $dispositivo_id, $empleado['id']);
        $stmt3->execute();
    } elseif ($empleado_dispositivo['dispositivo_id'] !== $dispositivo_id) {
        $error = "Este documento ya está vinculado a otro dispositivo. Si eres tú y cambiaste de equipo, contacta al administrador.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Asistencia | Grupo Bellaforma</title>
    <link rel="stylesheet" href="css/registro_inicial.css">
</head>
<body>
    <div class="container-inicial">
        <div class="card-inicial">
            <?php if (isset($error)): ?>
                <div class="alert alert-danger">
                    <div class="alert-icon">❌</div>
                    <div class="alert-content">
                        <strong>Error:</strong> <?php echo htmlspecialchars($error); ?>
                    </div>
                </div>
                <a href="registro.html" class="btn btn-back">← Volver a Registro</a>
            <?php else: ?>
                <div class="card-header">
                    <div class="logo-circle">👤</div>
                    <h1>Bienvenido, <?php echo htmlspecialchars($empleado['nombre']); ?></h1>
                    <p><?php echo htmlspecialchars($empleado['cargo']); ?></p>
                </div>

                <div class="alert alert-info">
                    <div class="alert-icon">ℹ️</div>
                    <div class="alert-content">
                        <strong>¿Qué deseas hacer?</strong><br>
                        Selecciona si estás llegando (entrada) o yéndote (salida)
                    </div>
                </div>

                <form method="POST" action="registro.php" id="formAsistencia">
                    <input type="hidden" name="documento" value="<?php echo htmlspecialchars($documento); ?>">
                    <input type="hidden" name="dispositivo_id" value="<?php echo htmlspecialchars($dispositivo_id); ?>">
                    <input type="hidden" name="tipo" id="tipoInput" value="">

                    <div class="btn-group">
                        <button type="button" class="btn btn-primary" onclick="registrarEntrada()">
                            ⏱️ Registrar Entrada
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="registrarSalida()">
                            🚪 Registrar Salida
                        </button>
                    </div>

                    <a href="registro.html" class="btn btn-back" style="display: block; margin-top: 10px;">
                        ← Volver a Registro
                    </a>
                </form>

                <script>
                    function registrarEntrada() {
                        document.getElementById('tipoInput').value = 'entrada';
                        document.getElementById('formAsistencia').submit();
                    }

                    function registrarSalida() {
                        document.getElementById('tipoInput').value = 'salida';
                        document.getElementById('formAsistencia').submit();
                    }
                </script>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
