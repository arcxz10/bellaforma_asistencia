<?php

date_default_timezone_set("America/Bogota");

require_once "conexion.php";

if ($_SERVER["REQUEST_METHOD"] !== "POST") {
    header("Location: registro.html");
    exit;
}

$documento = trim($_POST["documento"] ?? "");
$tipo = $_POST["tipo"] ?? "";
$dispositivoId = trim($_POST["dispositivo_id"] ?? "");
$justificacion = trim($_POST["justificacion"] ?? "");

if ($documento === "") {
    mostrarResultado(
        "error",
        "Documento requerido",
        "Ingrese su número de documento."
    );
}

if (!in_array($tipo, ["entrada", "salida"], true)) {
    mostrarResultado(
        "error",
        "Tipo de registro inválido",
        "Seleccione Entrada o Salida."
    );
}

if ($dispositivoId === "") {
    mostrarResultado(
        "error",
        "Dispositivo no identificado",
        "No se pudo identificar este dispositivo."
    );
}

$sql = "
    SELECT
        id,
        nombre,
        cargo,
        dispositivo_id
    FROM empleados
    WHERE identificacion = ?
      AND activo = 1
    LIMIT 1
";

$stmt = $conexion->prepare($sql);

if (!$stmt) {
    mostrarResultado(
        "error",
        "Error del sistema",
        "No se pudo consultar el empleado."
    );
}

$stmt->bind_param("s", $documento);
$stmt->execute();
$resultado = $stmt->get_result();

if ($resultado->num_rows !== 1) {
    $stmt->close();
    $conexion->close();

    mostrarResultado(
        "error",
        "Empleado no encontrado",
        "La identificación no corresponde a un empleado activo."
    );
}

$empleado = $resultado->fetch_assoc();
$stmt->close();

if (
    !empty($empleado["dispositivo_id"]) &&
    $empleado["dispositivo_id"] !== $dispositivoId
) {
    $conexion->close();

    mostrarResultado(
        "error",
        "Dispositivo no autorizado",
        "Este empleado solo puede registrar su asistencia desde el dispositivo asociado."
    );
}

$empleadoId = (int) $empleado["id"];
$nombre = $empleado["nombre"];
$cargo = $empleado["cargo"];

$fecha = date("Y-m-d");
$horaActual = date("H:i:s");
$diaSemana = (int) date("N");

$sqlFestivo = "
    SELECT descripcion
    FROM festivos
    WHERE fecha = ?
    LIMIT 1
";

$stmtFestivo = $conexion->prepare($sqlFestivo);

if (!$stmtFestivo) {
    mostrarResultado(
        "error",
        "Error del sistema",
        "No se pudo comprobar el calendario."
    );
}

$stmtFestivo->bind_param("s", $fecha);
$stmtFestivo->execute();
$resultadoFestivo = $stmtFestivo->get_result();

if ($resultadoFestivo->num_rows > 0) {
    $festivo = $resultadoFestivo->fetch_assoc();
    $descripcion = $festivo["descripcion"];

    $stmtFestivo->close();
    $conexion->close();

    mostrarResultado(
        "error",
        "Día no laboral",
        "Hoy es día festivo: " .
        htmlspecialchars($descripcion) .
        ". No se puede registrar asistencia."
    );
}

$stmtFestivo->close();

$sqlHorario = "
    SELECT
        hora_entrada,
        hora_salida,
        trabaja
    FROM horarios
    WHERE cargo = ?
      AND dia_semana = ?
    LIMIT 1
";

$stmtHorario = $conexion->prepare($sqlHorario);

if (!$stmtHorario) {
    mostrarResultado(
        "error",
        "Error del sistema",
        "No se pudo consultar el horario."
    );
}

$stmtHorario->bind_param("si", $cargo, $diaSemana);
$stmtHorario->execute();
$resultadoHorario = $stmtHorario->get_result();

if ($resultadoHorario->num_rows !== 1) {
    $stmtHorario->close();
    $conexion->close();

    mostrarResultado(
        "error",
        "Horario no disponible",
        "No existe un horario configurado para este día."
    );
}

$horario = $resultadoHorario->fetch_assoc();
$stmtHorario->close();

if ((int) $horario["trabaja"] !== 1) {
    $conexion->close();

    mostrarResultado(
        "error",
        "Día no laboral",
        "Hoy no corresponde jornada laboral para los empleados de " .
        htmlspecialchars($cargo) .
        "."
    );
}

$horaEntradaProgramada = $horario["hora_entrada"];
$horaSalidaProgramada = $horario["hora_salida"];

if ($tipo === "entrada") {

    $sqlAsistencia = "
        SELECT
            id,
            hora_entrada
        FROM asistencias
        WHERE empleado_id = ?
          AND fecha = ?
        LIMIT 1
    ";

    $stmt = $conexion->prepare($sqlAsistencia);

    if (!$stmt) {
        mostrarResultado(
            "error",
            "Error del sistema",
            "No se pudo consultar la asistencia."
        );
    }

    $stmt->bind_param("is", $empleadoId, $fecha);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows > 0) {
        $registro = $resultado->fetch_assoc();

        $stmt->close();
        $conexion->close();

        mostrarResultado(
            "error",
            "Entrada ya registrada",
            "Tu entrada ya fue registrada hoy a las " .
            formatoHora($registro["hora_entrada"]) .
            "."
        );
    }

    $stmt->close();

    $minutosActuales = convertirMinutos($horaActual);
    $minutosEntrada = convertirMinutos($horaEntradaProgramada);

    if ($minutosActuales > $minutosEntrada) {
        $estadoEntrada = "tarde";
        $minutosRetraso = $minutosActuales - $minutosEntrada;

        // 🛑 VALIDACIÓN OBLIGATORIA: Si llega tarde, la justificación no puede estar vacía
        if ($justificacion === "") {
            $conexion->close();
            mostrarResultado(
                "error",
                "Justificación requerida",
                "Has llegado " . $minutosRetraso . " minutos tarde. Es obligatorio ingresar una justificación para poder registrar la entrada."
            );
        }

    } else {
        $estadoEntrada = "puntual";
        $minutosRetraso = 0;
        $justificacion = null; // Si llegó puntual, se ignora o limpia la justificación
    }

    $sql = "
        INSERT INTO asistencias
        (
            empleado_id,
            fecha,
            hora_entrada,
            estado_entrada,
            minutos_retraso,
            justificacion
        )
        VALUES (?, ?, ?, ?, ?, ?)
    ";

    $stmt = $conexion->prepare($sql);

    if (!$stmt) {
        mostrarResultado(
            "error",
            "Error del sistema",
            "No se pudo guardar la entrada."
        );
    }

    $stmt->bind_param(
        "isssis",
        $empleadoId,
        $fecha,
        $horaActual,
        $estadoEntrada,
        $minutosRetraso,
        $justificacion
    );

    if (!$stmt->execute()) {
        $stmt->close();
        $conexion->close();

        mostrarResultado(
            "error",
            "No se pudo registrar",
            "Ocurrió un error al guardar la entrada."
        );
    }

    $stmt->close();
    $conexion->close();

    if ($estadoEntrada === "tarde") {
        $mensajeTarde = "Hola, " .
            htmlspecialchars($nombre) .
            ". Tu entrada fue registrada a las " .
            formatoHora($horaActual) .
            ".<br><strong>Retraso: " .
            $minutosRetraso .
            " minutos.</strong>";

        if (!empty($justificacion)) {
            $mensajeTarde .= "<br><small><strong>Justificación:</strong> " . htmlspecialchars($justificacion) . "</small>";
        }

        mostrarResultado(
            "tarde",
            "Entrada registrada",
            $mensajeTarde
        );

    } else {
        mostrarResultado(
            "exito",
            "Entrada registrada",
            "Hola, " .
            htmlspecialchars($nombre) .
            ". Tu entrada fue registrada a las " .
            formatoHora($horaActual) .
            "."
        );
    }
}

if ($tipo === "salida") {

    $sql = "
        SELECT
            id,
            hora_salida
        FROM asistencias
        WHERE empleado_id = ?
          AND fecha = ?
        LIMIT 1
    ";

    $stmt = $conexion->prepare($sql);

    if (!$stmt) {
        mostrarResultado(
            "error",
            "Error del sistema",
            "No se pudo consultar la asistencia."
        );
    }

    $stmt->bind_param("is", $empleadoId, $fecha);
    $stmt->execute();
    $resultado = $stmt->get_result();

    if ($resultado->num_rows !== 1) {
        $stmt->close();
        $conexion->close();

        mostrarResultado(
            "error",
            "Entrada no registrada",
            "No puedes registrar la salida porque todavía no tienes una entrada registrada hoy."
        );
    }

    $asistencia = $resultado->fetch_assoc();
    $stmt->close();

    if (!empty($asistencia["hora_salida"])) {
        $conexion->close();

        mostrarResultado(
            "error",
            "Salida ya registrada",
            "Tu salida ya fue registrada hoy a las " .
            formatoHora($asistencia["hora_salida"]) .
            "."
        );
    }

    $minutosActuales = convertirMinutos($horaActual);
    $minutosSalida = convertirMinutos($horaSalidaProgramada);

    if ($minutosActuales > $minutosSalida) {
        $minutosExtra = $minutosActuales - $minutosSalida;
    } else {
        $minutosExtra = 0;
    }

    $sql = "
        UPDATE asistencias
        SET
            hora_salida = ?,
            minutos_extra = ?
        WHERE id = ?
    ";

    $stmt = $conexion->prepare($sql);

    if (!$stmt) {
        mostrarResultado(
            "error",
            "Error del sistema",
            "No se pudo guardar la salida."
        );
    }

    $asistenciaId = (int) $asistencia["id"];

    $stmt->bind_param(
        "sii",
        $horaActual,
        $minutosExtra,
        $asistenciaId
    );

    if (!$stmt->execute()) {
        $stmt->close();
        $conexion->close();

        mostrarResultado(
            "error",
            "No se pudo registrar",
            "Ocurrió un error al guardar la salida."
        );
    }

    $stmt->close();
    $conexion->close();

    if ($minutosExtra > 0) {
        mostrarResultado(
            "extra",
            "Salida registrada",
            "Hola, " .
            htmlspecialchars($nombre) .
            ". Tu salida fue registrada a las " .
            formatoHora($horaActual) .
            ".<br><strong>Horas extra: " .
            convertirMinutosTexto($minutosExtra) .
            ".</strong>"
        );
    } else {
        mostrarResultado(
            "exito",
            "Salida registrada",
            "Hola, " .
            htmlspecialchars($nombre) .
            ". Tu salida fue registrada a las " .
            formatoHora($horaActual) .
            "."
        );
    }
}

function convertirMinutos($hora)
{
    $partes = explode(":", $hora);
    $horas = (int) ($partes[0] ?? 0);
    $minutos = (int) ($partes[1] ?? 0);

    return ($horas * 60) + $minutos;
}

function convertirMinutosTexto($minutos)
{
    $horas = intdiv($minutos, 60);
    $resto = $minutos % 60;

    if ($horas > 0) {
        return $horas . " h " . $resto . " min";
    }

    return $resto . " min";
}

function formatoHora($hora)
{
    $partes = explode(":", $hora);
    $horas = (int) ($partes[0] ?? 0);
    $minutos = (int) ($partes[1] ?? 0);

    $periodo = $horas >= 12 ? "PM" : "AM";
    $horas = $horas % 12;

    if ($horas === 0) {
        $horas = 12;
    }

    return sprintf(
        "%d:%02d %s",
        $horas,
        $minutos,
        $periodo
    );
}

function mostrarResultado(
    $tipo,
    $titulo,
    $mensaje
) {
    $clase = "success";
    $icono = "🎉";

    if ($tipo === "error") {
        $clase = "error";
        $icono = "❌";
    }

    if ($tipo === "tarde") {
        $clase = "warning";
        $icono = "⏰";
    }

    if ($tipo === "extra") {
        $clase = "info";
        $icono = "✨";
    }

    ?>
    <!DOCTYPE html>
    <html lang="es">

    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Registro | Grupo Bellaforma</title>
        <link rel="stylesheet" href="css/registro.css">
    </head>

    <body>

        <div class="registro-header">
            <div class="header-title">💅 Bellaforma</div>
        </div>

        <div class="container-registro">
            <div class="registro-card">

                <div class="resultado <?= $clase ?>">
                    <span class="resultado-icon"><?= $icono ?></span>
                    <div style="display:inline-block; vertical-align:middle;">
                        <h3><?= $titulo ?></h3>
                        <p><?= $mensaje ?></p>
                    </div>
                </div>

                <a
                    href="registro.html"
                    class="btn btn-back"
                    style="display:block; text-align:center; margin-top:20px;"
                >
                    ← Volver al registro
                </a>

            </div>
        </div>

    </body>

    </html>
    <?php
    exit;
}

?>
