<?php
require 'conexion.php';
date_default_timezone_set("America/Bogota");

if (!isset($_POST['documento']) || !isset($_POST['dispositivo_id'])) {
    header('Location: registro.html');
    exit;
}

$documento = trim($_POST['documento']);
$dispositivo_id = trim($_POST['dispositivo_id']);
$justificacion = trim($_POST['justificacion'] ?? '');

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
        $consulta_vincular = "UPDATE empleados SET dispositivo_id = ? WHERE id = ?";
        $stmt3 = $conexion->prepare($consulta_vincular);
        $stmt3->bind_param('si', $dispositivo_id, $empleado['id']);
        $stmt3->execute();
    } elseif ($empleado_dispositivo['dispositivo_id'] !== $dispositivo_id) {
        $error = "Este documento ya está vinculado a otro dispositivo.";
    } else {
        // --- VALIDAR RETRASO ---
        $cargo = $empleado['cargo'];
        $diaSemana = (int)date('N');
        $horaActual = date('H:i:s');

        $sqlH = "SELECT hora_entrada, trabaja FROM horarios WHERE cargo = ? AND dia_semana = ? LIMIT 1";
        $stmtH = $conexion->prepare($sqlH);
        $stmtH->bind_param("si", $cargo, $diaSemana);
        $stmtH->execute();
        $resH = $stmtH->get_result();

        $estaTarde = false;
        $minutosRetraso = 0;

        if ($resH->num_rows === 1) {
            $horario = $resH->fetch_assoc();
            if ((int)$horario["trabaja"] === 1) {
                $minActuales = (int)explode(":", $horaActual)[0] * 60 + (int)explode(":", $horaActual)[1];
                $minProg = (int)explode(":", $horario["hora_entrada"])[0] * 60 + (int)explode(":", $horario["hora_entrada"])[1];
                
                if ($minActuales > $minProg) {
                    $estaTarde = true;
                    $minutosRetraso = $minActuales - $minProg;
                }
            }
        }
        $stmtH->close();
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

                <!-- ALERTA INTEGRADA VISUAL (Reemplaza al alert nativo) -->
                <div id="alerta-justificacion" style="display: none; margin-bottom: 15px; padding: 12px; border-radius: 6px; background-color: #fcf8e3; border: 1px solid #faebcc; color: #8a6d3b; text-align: left; font-size: 0.85rem;">
                    <span style="font-size: 1.1rem; vertical-align: middle; margin-right: 5px;">⚠️</span>
                    <span id="texto-alerta-justificacion">Por favor, ingresa una justificación para continuar debido a tu retraso.</span>
                </div>

                <form method="POST" action="registro.php" id="formAsistencia">
                    <input type="hidden" name="documento" value="<?php echo htmlspecialchars($documento); ?>">
                    <input type="hidden" name="dispositivo_id" value="<?php echo htmlspecialchars($dispositivo_id); ?>">
                    <input type="hidden" name="tipo" id="tipoInput" value="">

                    <!-- CAJA DE JUSTIFICACIÓN (Oculta hasta hacer clic en Registrar Entrada estando tarde) -->
                    <div id="grupo-justificacion" style="display: none; margin-bottom: 15px; text-align: left;">
                        <div style="background-color: #fcf8e3; border: 1px solid #faebcc; color: #8a6d3b; padding: 10px; border-radius: 6px; margin-bottom: 8px; font-size: 0.85rem;">
                            ⚠️ Has llegado <strong id="lblMinutos"></strong> tarde. Justificación obligatoria:
                        </div>
                        <textarea 
                            id="justificacion" 
                            name="justificacion" 
                            rows="2" 
                            placeholder="Escribe el motivo de tu retraso..."
                            style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #d9534f; font-family: inherit; resize: vertical;"
                        ><?php echo htmlspecialchars($justificacion); ?></textarea>
                    </div>

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
                    const estaTarde = <?php echo ($estaTarde && empty($justificacion)) ? 'true' : 'false'; ?>;
                    const minutosRetraso = "<?php echo $minutosRetraso; ?> minutos";

                    function registrarEntrada() {
                        const cajaJustificacion = document.getElementById('grupo-justificacion');
                        const txtJustificacion = document.getElementById('justificacion');
                        const alertaVisual = document.getElementById('alerta-justificacion');

                        if (estaTarde) {
                            if (cajaJustificacion.style.display === 'none') {
                                document.getElementById('lblMinutos').textContent = minutosRetraso;
                                cajaJustificacion.style.display = 'block';
                                txtJustificacion.focus();
                                return; // Detiene el envío para obligar a rellenar la justificación
                            }

                            if (txtJustificacion.value.trim() === '') {
                                // Muestra alerta bonita integrada en vez del alert feo del navegador
                                alertaVisual.style.display = 'block';
                                txtJustificacion.style.borderColor = '#d9534f';
                                txtJustificacion.focus();
                                return;
                            }
                        }

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
