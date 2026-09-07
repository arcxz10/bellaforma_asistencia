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
$justificacionSalida = trim($_POST['justificacion_salida'] ?? '');

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
        // --- VALIDAR HORARIOS (ENTRADA Y SALIDA) ---
        $cargo = $empleado['cargo'];
        $diaSemana = (int)date('N');
        $horaActual = date('H:i:s');

        $sqlH = "SELECT hora_entrada, hora_salida, trabaja FROM horarios WHERE cargo = ? AND dia_semana = ? LIMIT 1";
        $stmtH = $conexion->prepare($sqlH);
        $stmtH->bind_param("si", $cargo, $diaSemana);
        $stmtH->execute();
        $resH = $stmtH->get_result();

        $estaTarde = false;
        $minutosRetraso = 0;
        $salidaAnticipada = false;
        $minutosFaltantesSalida = 0;

        if ($resH->num_rows === 1) {
            $horario = $resH->fetch_assoc();
            if ((int)$horario["trabaja"] === 1) {
                $minActuales = (int)explode(":", $horaActual)[0] * 60 + (int)explode(":", $horaActual)[1];
                
                // Validación de entrada tarde
                $minProgEntrada = (int)explode(":", $horario["hora_entrada"])[0] * 60 + (int)explode(":", $horario["hora_entrada"])[1];
                if ($minActuales > $minProgEntrada) {
                    $estaTarde = true;
                    $minutosRetraso = $minActuales - $minProgEntrada;
                }

                // Validación de salida anticipada
                $minProgSalida = (int)explode(":", $horario["hora_salida"])[0] * 60 + (int)explode(":", $horario["hora_salida"])[1];
                if ($minActuales < $minProgSalida) {
                    $salidaAnticipada = true;
                    $minutosFaltantesSalida = $minProgSalida - $minActuales;
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

                <div class="alert alert-info" id="info-seleccion">
                    <div class="alert-icon">ℹ️</div>
                    <div class="alert-content">
                        <strong>¿Qué deseas hacer?</strong><br>
                        Selecciona la acción que deseas registrar hoy.
                    </div>
                </div>

                <!-- ALERTA INTEGRADA VISUAL -->
                <div id="alerta-justificacion" style="display: none; margin-bottom: 15px; padding: 12px; border-radius: 6px; background-color: #fcf8e3; border: 1px solid #faebcc; color: #8a6d3b; text-align: left; font-size: 0.85rem;">
                    <span style="font-size: 1.1rem; vertical-align: middle; margin-right: 5px;">⚠️</span>
                    <span id="texto-alerta-justificacion">Por favor, ingresa una justificación para continuar.</span>
                </div>

                <form method="POST" action="registro.php" id="formAsistencia">
                    <input type="hidden" name="documento" value="<?php echo htmlspecialchars($documento); ?>">
                    <input type="hidden" name="dispositivo_id" value="<?php echo htmlspecialchars($dispositivo_id); ?>">
                    <input type="hidden" name="accion" id="tipoInput" value="">

                    <!-- CAJA DE JUSTIFICACIÓN DE ENTRADA -->
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

                    <!-- CAJA DE JUSTIFICACIÓN DE SALIDA ANTICIPADA -->
                    <div id="grupo-justificacion-salida" style="display: none; margin-bottom: 15px; text-align: left;">
                        <div style="background-color: #fcf8e3; border: 1px solid #faebcc; color: #8a6d3b; padding: 10px; border-radius: 6px; margin-bottom: 8px; font-size: 0.85rem;">
                            ⚠️ Estás saliendo <strong id="lblMinutosSalida"></strong> antes de tu hora. Se registrará una deuda de tiempo. Justificación obligatoria:
                        </div>
                        <textarea 
                            id="justificacion_salida" 
                            name="justificacion_salida" 
                            rows="2" 
                            placeholder="Escribe el motivo de tu salida anticipada (ej. Cita médica)..."
                            style="width: 100%; padding: 10px; border-radius: 6px; border: 1px solid #d9534f; font-family: inherit; resize: vertical;"
                        ><?php echo htmlspecialchars($justificacionSalida); ?></textarea>
                    </div>

                    <!-- CONTENEDOR DE LOS 4 BOTONES INICIALES -->
                    <div class="btn-group" id="grupo-botones-opciones" style="display: flex; flex-direction: column; gap: 10px;">
                        <button type="button" class="btn btn-primary" onclick="seleccionarAccion('entrada')" style="width: 100%;">
                            ⏱️ Registrar Entrada
                        </button>
                        <button type="button" class="btn btn-warning" onclick="seleccionarAccion('salida_almuerzo')" style="width: 100%; background-color: #f0ad4e; color: white;">
                            🍽️ Salida a Almuerzo
                        </button>
                        <button type="button" class="btn btn-info" onclick="seleccionarAccion('entrada_almuerzo')" style="width: 100%; background-color: #5bc0de; color: white;">
                            🍛 Entrada de Almuerzo
                        </button>
                        <button type="button" class="btn btn-secondary" onclick="seleccionarAccion('salida')" style="width: 100%;">
                            🚪 Registrar Salida
                        </button>
                    </div>

                    <!-- BOTÓN DE CONFIRMACIÓN (OCULTO INICIALMENTE) -->
                    <div id="grupo-boton-confirmar" style="display: none; margin-top: 10px;">
                        <button type="button" class="btn btn-success" onclick="confirmarRegistro()" style="width: 100%; background-color: #5cb85c; color: white; padding: 12px; font-weight: bold; border-radius: 6px; border: none; cursor: pointer;">
                            ✔️ Confirmar Registro
                        </button>
                    </div>

                    <a href="registro.html" class="btn btn-back" id="btn-volver" style="display: block; margin-top: 15px;">
                        ← Volver a Registro
                    </a>
                </form>

                <script>
                    const estaTarde = <?php echo ($estaTarde && empty($justificacion)) ? 'true' : 'false'; ?>;
                    const minutosRetraso = "<?php echo $minutosRetraso; ?> minutos";

                    const salidaAnticipada = <?php echo ($salidaAnticipada && empty($justificacionSalida)) ? 'true' : 'false'; ?>;
                    const minutosFaltantesSalida = "<?php echo $minutosFaltantesSalida; ?> minutos";

                    let accionSeleccionada = '';

                    function seleccionarAccion(accion) {
                        accionSeleccionada = accion;
                        document.getElementById('tipoInput').value = accion;

                        // Ocultar los 4 botones principales y el mensaje informativo inicial
                        document.getElementById('grupo-botones-opciones').style.display = 'none';
                        document.getElementById('info-seleccion').style.display = 'none';

                        // Mostrar el botón de confirmar
                        document.getElementById('grupo-boton-confirmar').style.display = 'block';

                        // Validaciones específicas si llega tarde o sale temprano
                        const cajaJustificacion = document.getElementById('grupo-justificacion');
                        const cajaJustificacionSalida = document.getElementById('grupo-justificacion-salida');

                        if (accion === 'entrada' && estaTarde) {
                            document.getElementById('lblMinutos').textContent = minutosRetraso;
                            cajaJustificacion.style.display = 'block';
                            document.getElementById('justificacion').focus();
                        }

                        if (accion === 'salida' && salidaAnticipada) {
                            document.getElementById('lblMinutosSalida').textContent = minutosFaltantesSalida;
                            cajaJustificacionSalida.style.display = 'block';
                            document.getElementById('justificacion_salida').focus();
                        }
                    }

                    function confirmarRegistro() {
                        const cajaJustificacion = document.getElementById('grupo-justificacion');
                        const txtJustificacion = document.getElementById('justificacion');
                        
                        const cajaJustificacionSalida = document.getElementById('grupo-justificacion-salida');
                        const txtJustificacionSalida = document.getElementById('justificacion_salida');
                        
                        const alertaVisual = document.getElementById('alerta-justificacion');
                        alertaVisual.style.display = 'none';

                        // Validar si requiere justificación de entrada y está vacía
                        if (accionSeleccionada === 'entrada' && estaTarde && cajaJustificacion.style.display !== 'none') {
                            if (txtJustificacion.value.trim() === '') {
                                document.getElementById('texto-alerta-justificacion').textContent = 'Por favor, ingresa una justificación para continuar debido a tu retraso.';
                                alertaVisual.style.display = 'block';
                                txtJustificacion.style.borderColor = '#d9534f';
                                txtJustificacion.focus();
                                return;
                            }
                        }

                        // Validar si requiere justificación de salida y está vacía
                        if (accionSeleccionada === 'salida' && salidaAnticipada && cajaJustificacionSalida.style.display !== 'none') {
                            if (txtJustificacionSalida.value.trim() === '') {
                                document.getElementById('texto-alerta-justificacion').textContent = 'Por favor, ingresa una justificación para continuar debido a tu salida anticipada.';
                                alertaVisual.style.display = 'block';
                                txtJustificacionSalida.style.borderColor = '#d9534f';
                                txtJustificacionSalida.focus();
                                return;
                            }
                        }

                        // Enviar el formulario una vez confirmado y validado
                        document.getElementById('formAsistencia').submit();
                    }
                </script>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
