<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.html");
    exit;
}

require_once "conexion.php";
require_once "vendedores_db.php";
asegurarTablasVendedores($conexion);

date_default_timezone_set("America/Bogota");

// --- PROCESAR ACCIONES DE PERMISOS O MATERIALES ---
if (isset($_GET['accion_solicitud'], $_GET['id_solicitud'], $_GET['tipo_solicitud'])) {
    $id_sol = intval($_GET['id_solicitud']);
    $accion_sol = $_GET['accion_solicitud']; // 'aprobado' o 'rechazado'
    $tipo_sol = $_GET['tipo_solicitud'];     // 'permiso' o 'material'
    
    $estado_valido_sol = in_array($accion_sol, ['aprobado', 'rechazado'], true) ? $accion_sol : 'pendiente';
    
    if ($tipo_sol === 'permiso') {
        $stmt_sol = $conexion->prepare("UPDATE permisos SET estado = ? WHERE id = ?");
    } else {
        $stmt_sol = $conexion->prepare("UPDATE solicitud_materiales SET estado = ? WHERE id = ?");
    }
    if ($stmt_sol) {
        $stmt_sol->bind_param("si", $estado_valido_sol, $id_sol);
        $stmt_sol->execute();
        $stmt_sol->close();
    }
    
    $seccion_destino_sol = ($tipo_sol === 'permiso') ? 'permisos' : 'materiales';
    header("Location: admin.php#" . $seccion_destino_sol);
    exit;
}

function escapar($valor)
{
    return htmlspecialchars((string) $valor, ENT_QUOTES, "UTF-8");
}

function formatoHora($hora)
{
    if (empty($hora)) {
        return "—";
    }

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

function minutosAHoras($minutos)
{
    $minutos = (int) $minutos;

    $horas = intdiv($minutos, 60);
    $resto = $minutos % 60;

    if ($horas > 0) {
        return $horas . " h " . $resto . " min";
    }

    return $resto . " min";
}

function redireccionar($mensaje, $tipo = "exito", $seccion = "")
{
    // Envíos hechos con fetch (guardar producto): se responde en JSON para mostrar el resultado real en pantalla
    if (($_SERVER["HTTP_X_REQUESTED_WITH"] ?? "") === "fetch") {
        header("Content-Type: application/json; charset=UTF-8");
        echo json_encode(["ok" => $tipo === "exito", "mensaje" => (string) $mensaje], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION["mensaje"] = $mensaje;
    $_SESSION["tipo_mensaje"] = $tipo;

    $destino = "admin.php";

    if ($seccion !== "") {
        $destino .= "#" . $seccion;
    }

    header("Location: " . $destino);
    exit;
}

if ($_SERVER["REQUEST_METHOD"] === "POST") {

    // Si el envío supera el límite del servidor (post_max_size), PHP descarta TODO el formulario sin avisar
    if (empty($_POST) && empty($_FILES) && (int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > 0) {
        redireccionar(
            "El envío pesa " . round(((int) $_SERVER["CONTENT_LENGTH"]) / 1048576, 1) . " MB y supera el límite del servidor (" . ini_get("post_max_size") . "), por eso no llegó. Use una foto más liviana o guárdelo sin foto.",
            "error"
        );
    }

    $accion = $_POST["accion"] ?? "";

    // Vendedores / productos al por mayor / pedidos
    require __DIR__ . "/admin_acciones_vendedores.php";

    if ($accion === "agregar_empleado") {

        $nombre = trim($_POST["nombre"] ?? "");
        $identificacion = trim($_POST["identificacion"] ?? "");
        $cargo = trim($_POST["cargo"] ?? "");

        $cargosPermitidos = [
            "Producción",
            "Ventas",
            "Administración",
            "Temporales",
            "Jefe de Maquinaria",
            "Directora de Despachos"
        ];

        if (
            $nombre === "" ||
            $identificacion === "" ||
            !in_array($cargo, $cargosPermitidos, true)
        ) {
            redireccionar(
                "Complete correctamente los datos del empleado.",
                "error",
            "empleados"
        );
        }

        $horaEntrada = "08:30:00";
        $horaSalida = "17:15:00";

        if ($cargo === "Producción") {
            $horaEntrada = "07:30:00";
            $horaSalida = "17:05:00";
        } elseif ($cargo === "Temporales") {
            $horaEntrada = "08:00:00";
            $horaSalida = "15:00:00";
        } elseif ($cargo === "Jefe de Maquinaria") {
            $horaEntrada = "07:30:00";
            $horaSalida = "17:00:00";
        } elseif ($cargo === "Directora de Despachos") {
            $horaEntrada = "08:30:00";
            $horaSalida = "18:15:00";
        }

        $sql = "
            INSERT INTO empleados
            (
                nombre,
                identificacion,
                cargo,
                hora_entrada,
                hora_salida,
                activo
            )
            VALUES (?, ?, ?, ?, ?, 1)
        ";

        $stmt = $conexion->prepare($sql);

        if (!$stmt) {
            redireccionar(
                "No se pudo preparar el registro del empleado.",
                "error",
            "empleados"
        );
        }

        $stmt->bind_param(
            "sssss",
            $nombre,
            $identificacion,
            $cargo,
            $horaEntrada,
            $horaSalida
        );

        if (!$stmt->execute()) {

            if ($stmt->errno === 1062) {

                $stmt->close();

                redireccionar(
                    "Ya existe un empleado con esa identificación.",
                    "error",
            "empleados"
        );
            }

            $stmt->close();

            redireccionar(
                "No se pudo guardar el empleado.",
                "error",
            "empleados"
        );
        }

        $stmt->close();

        redireccionar(
            "Empleado agregado correctamente.",
            "exito",
            "empleados"
        );
    }

    if ($accion === "editar_empleado") {

        $id = (int) ($_POST["id"] ?? 0);

        $nombre = trim($_POST["nombre"] ?? "");
        $identificacion = trim($_POST["identificacion"] ?? "");
        $cargo = trim($_POST["cargo"] ?? "");

        $cargosPermitidos = [
            "Producción",
            "Ventas",
            "Administración",
            "Temporales",
            "Jefe de Maquinaria",
            "Directora de Despachos"
        ];

        if (
            $id <= 0 ||
            $nombre === "" ||
            $identificacion === "" ||
            !in_array($cargo, $cargosPermitidos, true)
        ) {
            redireccionar(
                "Los datos del empleado no son válidos.",
                "error",
            "empleados"
        );
        }

        $horaEntrada = "08:30:00";
        $horaSalida = "17:15:00";

        if ($cargo === "Producción") {
            $horaEntrada = "07:30:00";
            $horaSalida = "17:05:00";
        } elseif ($cargo === "Temporales") {
            $horaEntrada = "08:00:00";
            $horaSalida = "15:00:00";
        } elseif ($cargo === "Jefe de Maquinaria") {
            $horaEntrada = "07:30:00";
            $horaSalida = "17:00:00";
        } elseif ($cargo === "Directora de Despachos") {
            $horaEntrada = "08:30:00";
            $horaSalida = "18:15:00";
        }

        $sql = "
            UPDATE empleados
            SET
                nombre = ?,
                identificacion = ?,
                cargo = ?,
                hora_entrada = ?,
                hora_salida = ?
            WHERE id = ?
        ";

        $stmt = $conexion->prepare($sql);

        if (!$stmt) {
            redireccionar(
                "No se pudo preparar la actualización.",
                "error",
            "empleados"
        );
        }

        $stmt->bind_param(
            "sssssi",
            $nombre,
            $identificacion,
            $cargo,
            $horaEntrada,
            $horaSalida,
            $id
        );

        if (!$stmt->execute()) {

            if ($stmt->errno === 1062) {

                $stmt->close();

                redireccionar(
                    "La identificación ya pertenece a otro empleado.",
                    "error",
            "empleados"
        );
            }

            $stmt->close();

            redireccionar(
                "No se pudo actualizar el empleado.",
                "error",
            "empleados"
        );
        }

        $stmt->close();

        redireccionar(
            "Empleado actualizado correctamente.",
            "exito",
            "empleados"
        );
    }

    if ($accion === "cambiar_estado") {

        $id = (int) ($_POST["id"] ?? 0);
        $nuevoEstado = (int) ($_POST["nuevo_estado"] ?? 0);

     if (
    $id <= 0 ||
    !in_array($nuevoEstado, [0, 1], true)
) {
            redireccionar(
                "Estado inválido.",
                "error",
            "empleados"
        );
        }

        $sql = "
            UPDATE empleados
            SET activo = ?
            WHERE id = ?
        ";

        $stmt = $conexion->prepare($sql);

        if (!$stmt) {
            redireccionar(
                "No se pudo preparar el cambio de estado.",
                "error",
            "empleados"
        );
        }

        $stmt->bind_param(
            "ii",
            $nuevoEstado,
            $id
        );

        if (!$stmt->execute()) {

            $stmt->close();

            redireccionar(
                "No se pudo cambiar el estado del empleado.",
                "error",
            "empleados"
        );
        }

        $stmt->close();

        redireccionar(
            $nuevoEstado === 1
                ? "Empleado activado correctamente."
                : "Empleado desactivado correctamente.",
            "exito",
            "empleados"
        );
    }

    if ($accion === "editar_asistencia") {

        $idAsistencia = (int) ($_POST["asistencia_id"] ?? 0);
        $minutosDeuda = (int) ($_POST["minutos_deuda"] ?? 0);
        $motivoDeuda = trim($_POST["motivo_deuda"] ?? "");

        if ($idAsistencia <= 0) {
            redireccionar("Registro de asistencia inválido.", "error", "historial");
        }

        $sql = "
            UPDATE asistencias
            SET minutos_deuda = ?,
                justificacion = IF(? != '', ?, justificacion)
            WHERE id = ?
        ";

        $stmt = $conexion->prepare($sql);

        if (!$stmt) {
            redireccionar("No se pudo preparar la actualización de la deuda.", "error", "historial");
        }

        $stmt->bind_param("iisi", $minutosDeuda, $motivoDeuda, $motivoDeuda, $idAsistencia);

        if (!$stmt->execute()) {
            $stmt->close();
            redireccionar("No se pudo actualizar el registro.", "error", "historial");
        }

        $stmt->close();
        redireccionar("Registro de asistencia actualizado correctamente.", "exito", "historial");
    }
}

$mensaje = $_SESSION["mensaje"] ?? "";
$tipoMensaje = $_SESSION["tipo_mensaje"] ?? "";

unset($_SESSION["mensaje"]);
unset($_SESSION["tipo_mensaje"]);

$fechaDesde = $_GET["desde"] ?? date("Y-m-d");
$fechaHasta = $_GET["hasta"] ?? date("Y-m-d");
$estado = $_GET["estado"] ?? "todos";
$buscar = trim($_GET["buscar"] ?? "");

$estadosPermitidos = [
    "todos",
    "puntual",
    "tarde",
    "extra",
    "sin"
];

if (!in_array($estado, $estadosPermitidos, true)) {
    $estado = "todos";
}

if ($fechaDesde === "") {
    $fechaDesde = date("Y-m-d");
}

if ($fechaHasta === "") {
    $fechaHasta = $fechaDesde;
}

if ($fechaDesde > $fechaHasta) {
    [$fechaDesde, $fechaHasta] = [
        $fechaHasta,
        $fechaDesde
    ];
}

$hoy = date("Y-m-d");
$horaAhora = date("H:i:s");

$esUnSoloDia = $fechaDesde === $fechaHasta;
$esHoy = $esUnSoloDia && $fechaDesde === $hoy;
$diaCerrado = !$esHoy || $horaAhora >= "17:30:00";

$esFestivo = false;
$descripcionFestivo = "";

$estadoDiario = [];

$totalEmpleados = 0;
$registraronDia = 0;
$pendientesDia = 0;
$noRegistraronDia = 0;
$tardanzasDia = 0;
$salidasDia = 0;

$sqlTotal = "
    SELECT COUNT(*) AS total
    FROM empleados
    WHERE activo = 1
";

$resultadoTotal = $conexion->query($sqlTotal);

if ($resultadoTotal) {

    $filaTotal = $resultadoTotal->fetch_assoc();

    $totalEmpleados = (int) $filaTotal["total"];
}

if ($esUnSoloDia) {

    $sqlFestivo = "
        SELECT descripcion
        FROM festivos
        WHERE fecha = ?
        LIMIT 1
    ";

    $stmtFestivo =
        $conexion->prepare($sqlFestivo);

    if ($stmtFestivo) {

        $stmtFestivo->bind_param(
            "s",
            $fechaDesde
        );

        $stmtFestivo->execute();

        $resultadoFestivo =
            $stmtFestivo->get_result();

        if ($resultadoFestivo->num_rows > 0) {

            $esFestivo = true;

            $filaFestivo =
                $resultadoFestivo->fetch_assoc();

            $descripcionFestivo =
                $filaFestivo["descripcion"];
        }

        $stmtFestivo->close();
    }
}

if ($esUnSoloDia) {

    $diaSemana = (int) date(
        "N",
        strtotime($fechaDesde)
    );

    $sqlDiario = "
        SELECT
            e.id,
            e.nombre,
            e.identificacion,
            e.cargo,
            h.hora_entrada AS horario_entrada,
            h.hora_salida AS horario_salida,
            h.trabaja,
            a.id AS asistencia_id,
            a.hora_entrada AS registro_entrada,
            a.minutos_retraso,
            a.hora_salida AS registro_salida,
            a.minutos_extra
        FROM empleados e

        LEFT JOIN horarios h
            ON h.cargo = e.cargo
            AND h.dia_semana = ?

        LEFT JOIN asistencias a
            ON a.empleado_id = e.id
            AND a.fecha = ?

        WHERE e.activo = 1
    ";

    if ($buscar !== "") {

        $sqlDiario .= "
            AND (
                e.nombre LIKE ?
                OR e.identificacion LIKE ?
            )
        ";
    }

    $sqlDiario .= "
        ORDER BY
            e.nombre ASC
    ";

    $stmtDiario =
        $conexion->prepare(
            $sqlDiario
        );

    if (!$stmtDiario) {
        die(
            "Error al consultar el estado diario: " .
            $conexion->error
        );
    }

    if ($buscar !== "") {

        $buscarLike =
            "%" . $buscar . "%";

        $stmtDiario->bind_param(
            "isss",
            $diaSemana,
            $fechaDesde,
            $buscarLike,
            $buscarLike
        );

    } else {

        $stmtDiario->bind_param(
            "is",
            $diaSemana,
            $fechaDesde
        );
    }

    $stmtDiario->execute();

    $resultadoDiario =
        $stmtDiario->get_result();

    while (
        $fila =
        $resultadoDiario->fetch_assoc()
    ) {

        if ($esFestivo) {

            $fila["estado_dia"] =
                "no_laboral";

        } elseif ($fila["cargo"] === "Temporales") {

            // Temporales: sin horario. Si registró se muestra como puntual; si no, no es inasistencia.
            if ($fila["asistencia_id"] !== null) {
                $registraronDia++;
                if (!empty($fila["registro_salida"])) {
                    $salidasDia++;
                }
                $fila["estado_dia"] = "puntual";
            } else {
                $fila["estado_dia"] = "no_laboral";
            }

        } elseif (
            (int) $fila["trabaja"] !== 1
        ) {

            $fila["estado_dia"] =
                "no_laboral";

        } elseif (
            $fila["asistencia_id"] !== null
        ) {

            $registraronDia++;

            if (
                (int)
                $fila["minutos_retraso"] > 0
            ) {

                $tardanzasDia++;

                $fila["estado_dia"] =
                    "tarde";

            } else {

                $fila["estado_dia"] =
                    "puntual";
            }

            if (
                !empty(
                    $fila["registro_salida"]
                )
            ) {
                $salidasDia++;
            }

        } elseif ($diaCerrado) {

            $noRegistraronDia++;

            $fila["estado_dia"] =
                "no_registro";

        } else {

            $pendientesDia++;

            $fila["estado_dia"] =
                "pendiente";
        }

        $estadoDiario[] = $fila;
    }

    $stmtDiario->close();
}

$historial = null;
$sinRegistroHistorial = null;

if ($estado !== "sin") {

    $sqlHistorial = "
        SELECT
            a.id,
            a.fecha,
            e.nombre,
            e.identificacion,
            e.cargo,
            COALESCE(
                h.hora_entrada,
                e.hora_entrada
            ) AS horario_entrada,
            COALESCE(
                h.hora_salida,
                e.hora_salida
            ) AS horario_salida,
            a.hora_entrada,
            a.minutos_retraso,
            a.hora_salida_almuerzo,
            a.hora_entrada_almuerzo,
            a.minutos_retraso_almuerzo,
            a.hora_salida,
            a.minutos_extra,
            a.minutos_deuda,
            a.justificacion,
            a.justificacion_salida
        FROM asistencias a

        INNER JOIN empleados e
            ON e.id = a.empleado_id

        LEFT JOIN horarios h
            ON h.cargo = e.cargo
            AND h.dia_semana =
                WEEKDAY(a.fecha) + 1

        WHERE a.fecha BETWEEN ? AND ?
    ";

    if ($estado === "puntual") {

        $sqlHistorial .= "
            AND a.minutos_retraso = 0
        ";
    }

    if ($estado === "tarde") {

        $sqlHistorial .= "
            AND a.minutos_retraso > 0
        ";
    }

    if ($estado === "extra") {

        $sqlHistorial .= "
            AND a.minutos_extra > 0
        ";
    }

    if ($buscar !== "") {

        $sqlHistorial .= "
            AND (
                e.nombre LIKE ?
                OR e.identificacion LIKE ?
            )
        ";
    }

    $sqlHistorial .= "
        ORDER BY
            a.fecha DESC,
            e.nombre ASC
    ";

    $stmtHistorial =
        $conexion->prepare(
            $sqlHistorial
        );

    if (!$stmtHistorial) {
        die(
            "Error al consultar el historial: " .
            $conexion->error
        );
    }

    if ($buscar !== "") {

        $buscarLike =
            "%" . $buscar . "%";

        $stmtHistorial->bind_param(
            "ssss",
            $fechaDesde,
            $fechaHasta,
            $buscarLike,
            $buscarLike
        );

    } else {

        $stmtHistorial->bind_param(
            "ss",
            $fechaDesde,
            $fechaHasta
        );
    }

    $stmtHistorial->execute();

    $historial =
        $stmtHistorial->get_result();

    $stmtHistorial->close();
}

if ($estado === "sin") {

    $sqlSin = "
        SELECT
            e.id,
            e.nombre,
            e.identificacion,
            e.cargo,
            e.hora_entrada,
            e.hora_salida
        FROM empleados e

        LEFT JOIN asistencias a
            ON a.empleado_id = e.id
            AND a.fecha BETWEEN ? AND ?

        WHERE e.activo = 1
            AND a.id IS NULL
    ";

    if ($buscar !== "") {

        $sqlSin .= "
            AND (
                e.nombre LIKE ?
                OR e.identificacion LIKE ?
            )
        ";
    }

    $sqlSin .= "
        ORDER BY e.nombre ASC
    ";

    $stmtSin =
        $conexion->prepare(
            $sqlSin
        );

    if (!$stmtSin) {
        die(
            "Error al consultar empleados sin registro: " .
            $conexion->error
        );
    }

    if ($buscar !== "") {

        $buscarLike =
            "%" . $buscar . "%";

        $stmtSin->bind_param(
            "ssss",
            $fechaDesde,
            $fechaHasta,
            $buscarLike,
            $buscarLike
        );

    } else {

        $stmtSin->bind_param(
            "ss",
            $fechaDesde,
            $fechaHasta
        );
    }

    $stmtSin->execute();

    $sinRegistroHistorial =
        $stmtSin->get_result();

    $stmtSin->close();
}

$retrasoAcumulado = 0;
$extraAcumulado = 0;
$diasRegistrados = 0;
$diasTarde = 0;

if ($buscar !== "") {

    $sqlAcumulado = "
        SELECT
            COALESCE(
                SUM(a.minutos_retraso),
                0
            ) AS retraso_total,

            COALESCE(
                SUM(a.minutos_extra),
                0
            ) AS extra_total,

            COUNT(*) AS dias_registrados,

            COALESCE(
                SUM(
                    CASE
                        WHEN a.minutos_retraso > 0
                        THEN 1
                        ELSE 0
                    END
                ),
                0
            ) AS dias_tarde

        FROM asistencias a

        INNER JOIN empleados e
            ON e.id = a.empleado_id

        WHERE a.fecha BETWEEN ? AND ?

        AND (
            e.nombre LIKE ?
            OR e.identificacion LIKE ?
        )
    ";

    $stmtAcumulado =
        $conexion->prepare(
            $sqlAcumulado
        );

    if (!$stmtAcumulado) {
        die(
            "Error al consultar los acumulados: " .
            $conexion->error
        );
    }

    $buscarLike =
        "%" . $buscar . "%";

    $stmtAcumulado->bind_param(
        "ssss",
        $fechaDesde,
        $fechaHasta,
        $buscarLike,
        $buscarLike
    );

    $stmtAcumulado->execute();

    $acumulado =
        $stmtAcumulado
            ->get_result()
            ->fetch_assoc();

    $retrasoAcumulado =
        (int)
        ($acumulado["retraso_total"] ?? 0);

    $extraAcumulado =
        (int)
        ($acumulado["extra_total"] ?? 0);

    $diasRegistrados =
        (int)
        ($acumulado["dias_registrados"] ?? 0);

    $diasTarde =
        (int)
        ($acumulado["dias_tarde"] ?? 0);

    $stmtAcumulado->close();
}

$sqlEmpleados = "
    SELECT
        e.id,
        e.nombre,
        e.identificacion,
        e.cargo,
        COALESCE(
            h.hora_entrada,
            e.hora_entrada
        ) AS hora_entrada,
        COALESCE(
            h.hora_salida,
            e.hora_salida
        ) AS hora_salida,
        e.activo,
        (
            SELECT MIN(a.fecha)
            FROM asistencias a
            WHERE a.empleado_id = e.id
        ) AS primer_registro
    FROM empleados e

    LEFT JOIN horarios h
        ON h.cargo = e.cargo
        AND h.dia_semana = 1
        AND h.trabaja = 1

    ORDER BY
        e.activo DESC,
        e.nombre ASC
";

$resultadoEmpleados =
    $conexion->query(
        $sqlEmpleados
    );

?>

<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1.0"
    >

    <title>
        Panel Administrativo - Grupo Bella Forma S.A.S.
    </title>

    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="apple-touch-icon" href="img/apple-touch-icon.png">

    <link
        rel="stylesheet"
        href="css/admin.css"
    >

    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <style>
        .color-retraso, .retraso, .estado.estado-tarde { color: #dc3545 !important; font-weight: bold; }
        .color-extra, .extra { color: #d39e00 !important; font-weight: bold; }
        .color-deuda { color: #007bff !important; font-weight: bold; }
        .estado.estado-puntual { color: #28a745 !important; font-weight: bold; }
        .tarjeta-grafico {
            background: #fff;
            border: 1px solid #e0e0e0;
            border-radius: 8px;
            padding: 18px;
            margin-bottom: 20px;
        }
        .tarjeta-grafico-cabecera {
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 10px;
            margin-bottom: 14px;
        }
        .selector-metrica {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .selector-metrica select {
            padding: 6px 10px;
            border-radius: 4px;
            border: 1px solid #ccc;
        }
        .btn-justificaciones {
            background-color: #6c757d;
            color: #fff;
            border: none;
            padding: 8px 14px;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
        }
        .btn-justificaciones:hover {
            background-color: #5a6268;
        }
    </style>

</head>

<body>

<div class="container-admin">

    <aside class="sidebar">

        <div class="sidebar-header">

            <img src="img/logo-blanco.png" alt="Grupo Bella Forma S.A.S." class="logo-marca-sidebar">

            <p>
                Administrador
            </p>

        </div>


        <nav class="sidebar-nav">

            <a
                href="#dashboard"
                class="nav-item active"
            >
                📊 Dashboard
            </a>

            <a
                href="#asistencias"
                class="nav-item"
            >
                📋 Asistencias
            </a>

            <a
                href="#historial"
                class="nav-item"
            >
                📂 Historial Completo
            </a>

            <a
                href="#empleados"
                class="nav-item"
            >
                👥 Empleados
            </a>

            <a
                href="#festivos"
                class="nav-item"
            >
                🎉 Festivos
            </a>

            <a
                href="codigo_qr.php"
                class="nav-item"
                target="_blank"
            >
                📱 Código QR
            </a>

            <a
                href="liquidacion.php"
                class="nav-item"
            >
                💰 Liquidación
            </a>

            <a
                href="#permisos"
                class="nav-item"
            >
                📝 Permisos
            </a>

            <a
                href="#materiales"
                class="nav-item"
            >
                📦 Materiales
            </a>

            <a
                href="#vendedores"
                class="nav-item"
            >
                🧳 Ejecutivos de negocios
            </a>

            <a
                href="#pedidos"
                class="nav-item"
            >
                🧾 Pedidos de ejecutivos
            </a>

            <a
                href="#despachos"
                class="nav-item"
            >
                🚚 Control de despachos
            </a>

            <a
                href="#productos_mayoristas"
                class="nav-item"
            >
                🏷️ Productos
            </a>

            <a
                href="#clientes"
                class="nav-item"
            >
                👥 Clientes
            </a>

        </nav>


        <div class="sidebar-footer">

            <p>
                <?= escapar(
                    $_SESSION["admin_usuario"]
                    ?? "Administrador"
                ) ?>
            </p>

            <a
                href="logout.php"
                class="btn-logout-admin"
            >
                Cerrar Sesión
            </a>

        </div>

    </aside>


    <main class="main-content">

        <header class="top-header">

            <h1>
                Bienvenido al Panel Administrativo
            </h1>

            <div class="header-info">

                <span>
                    👤
                    <?= escapar(
                        $_SESSION["admin_usuario"]
                        ?? "Administrador"
                    ) ?>
                </span>

                <span>
                    📅
                    <?= date("d/m/Y H:i:s") ?>
                </span>

            </div>

        </header>


        <div class="content">


            <?php if ($mensaje !== ""): ?>

                <div class="mensaje <?= escapar($tipoMensaje) ?>">

                    <?= escapar($mensaje) ?>

                </div>

            <?php endif; ?>


            <section
                id="dashboard"
                class="section active"
            >

                <h2>
                    📊 Dashboard
                </h2>

                <?php
                // Quién falta por registrar (o no registró, si el día ya cerró) y quién llegó tarde
                $estadoFaltan = ($esHoy && !$diaCerrado) ? "pendiente" : "no_registro";
                $listaFaltan = [];
                $listaTarde = [];
                foreach ($estadoDiario as $filaDash) {
                    if ($filaDash["estado_dia"] === $estadoFaltan) {
                        $listaFaltan[] = $filaDash["nombre"];
                    } elseif ($filaDash["estado_dia"] === "tarde") {
                        $listaTarde[] = [$filaDash["nombre"], (int) $filaDash["minutos_retraso"]];
                    }
                }

                // Los que llegaron más tarde aparecen primero
                usort($listaTarde, function ($a, $b) {
                    return $b[1] <=> $a[1];
                });

                $dashPendiente = ($esHoy && !$diaCerrado);
                $dashTotalFaltan = $dashPendiente ? $pendientesDia : $noRegistraronDia;
                $dashPorcentaje = ((int) $totalEmpleados > 0)
                    ? (int) round(((int) $registraronDia / (int) $totalEmpleados) * 100)
                    : 0;
                $dashPorcentaje = max(0, min(100, $dashPorcentaje));

                // Iniciales para el círculo de cada persona (máx. 2 letras)
                $dashIniciales = function ($nombre) {
                    $palabras = preg_split('/\s+/u', trim((string) $nombre), -1, PREG_SPLIT_NO_EMPTY);
                    $ini = "";
                    foreach (array_slice($palabras ?: [], 0, 2) as $palabra) {
                        if (preg_match('/\p{L}/u', $palabra, $m)) {
                            $ini .= $m[0];
                        }
                    }
                    return function_exists("mb_strtoupper") ? mb_strtoupper($ini, "UTF-8") : strtoupper($ini);
                };
                ?>

                <div class="dash-stats">

                    <div class="dash-stat">
                        <div class="dash-icono">👥</div>
                        <div class="dash-dato">
                            <span class="dash-etiqueta">Total Empleados</span>
                            <span class="dash-numero"><?= $totalEmpleados ?></span>
                            <span class="dash-pie">Personal en el sistema</span>
                        </div>
                    </div>

                    <div class="dash-stat dash-stat--verde">
                        <div class="dash-icono">✅</div>
                        <div class="dash-dato">
                            <span class="dash-etiqueta">Registraron Hoy</span>
                            <span class="dash-numero"><?= $registraronDia ?></span>
                            <span class="dash-barra" role="img" aria-label="<?= $dashPorcentaje ?>% del personal registrado">
                                <span style="width: <?= $dashPorcentaje ?>%;"></span>
                            </span>
                            <span class="dash-pie"><?= $dashPorcentaje ?>% del personal</span>
                        </div>
                    </div>

                    <div class="dash-stat dash-stat--aviso">
                        <div class="dash-icono">⏳</div>
                        <div class="dash-dato">
                            <span class="dash-etiqueta"><?= $dashPendiente ? "Pendientes Hoy" : "No registraron" ?></span>
                            <span class="dash-numero"><?= $dashTotalFaltan ?></span>
                            <span class="dash-pie"><?= $dashPendiente ? "Aún sin registrar" : "Sin registro en el día" ?></span>
                        </div>
                    </div>

                    <div class="dash-stat dash-stat--rojo">
                        <div class="dash-icono">⏰</div>
                        <div class="dash-dato">
                            <span class="dash-etiqueta">Retardos Hoy</span>
                            <span class="dash-numero"><?= $tardanzasDia ?></span>
                            <span class="dash-pie">Llegaron tarde</span>
                        </div>
                    </div>

                </div>


                <div class="dash-paneles">

                    <div class="dash-panel dash-panel--aviso">
                        <div class="dash-panel-cab">
                            <h3><?= $dashPendiente ? "Pendientes por registrar" : "No registraron" ?></h3>
                            <span class="dash-chip"><?= count($listaFaltan) ?></span>
                        </div>

                        <?php if ($listaFaltan): ?>
                            <ul class="dash-lista">
                                <?php foreach ($listaFaltan as $nombreFalta): ?>
                                    <li class="dash-fila">
                                        <span class="dash-avatar"><?= escapar($dashIniciales($nombreFalta)) ?></span>
                                        <span class="dash-nombre"><?= escapar($nombreFalta) ?></span>
                                        <span class="dash-badge"><?= $dashPendiente ? "Pendiente" : "Sin registro" ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="dash-vacio">
                                <span class="dash-vacio-icono">🎉</span>
                                Nadie por registrar
                            </div>
                        <?php endif; ?>
                    </div>


                    <div class="dash-panel dash-panel--rojo">
                        <div class="dash-panel-cab">
                            <h3>Retardos del día</h3>
                            <span class="dash-chip"><?= count($listaTarde) ?></span>
                        </div>

                        <?php if ($listaTarde): ?>
                            <ul class="dash-lista">
                                <?php foreach ($listaTarde as [$nombreTarde, $minTarde]): ?>
                                    <li class="dash-fila">
                                        <span class="dash-avatar"><?= escapar($dashIniciales($nombreTarde)) ?></span>
                                        <span class="dash-nombre"><?= escapar($nombreTarde) ?></span>
                                        <span class="dash-badge"><?= escapar(minutosAHoras($minTarde)) ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php else: ?>
                            <div class="dash-vacio">
                                <span class="dash-vacio-icono">👏</span>
                                Nadie llegó tarde
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

            </section>


            <section
                id="asistencias"
                class="section"
            >

                <h2>
                    📋 Registro de Asistencias
                </h2>


                <form
                    method="GET"
                    action="admin.php#asistencias"
                    class="filtros"
                >
                    <input type="hidden" name="seccion" value="asistencias">

                    <div>

                        <label for="desde">
                            Desde
                        </label>

                        <input
                            type="date"
                            id="desde"
                            name="desde"
                            value="<?= escapar(
                                $fechaDesde
                            ) ?>"
                            required
                        >

                    </div>


                    <div>

                        <label for="hasta">
                            Hasta
                        </label>

                        <input
                            type="date"
                            id="hasta"
                            name="hasta"
                            value="<?= escapar(
                                $fechaHasta
                            ) ?>"
                            required
                        >

                    </div>


                    <div>

                        <label for="estado">
                            Estado
                        </label>

                        <select
                            id="estado"
                            name="estado"
                        >

                            <option
                                value="todos"
                                <?= $estado === "todos"
                                    ? "selected"
                                    : "" ?>
                            >
                                Todos
                            </option>

                            <option
                                value="puntual"
                                <?= $estado === "puntual"
                                    ? "selected"
                                    : "" ?>
                            >
                                Puntuales
                            </option>

                            <option
                                value="tarde"
                                <?= $estado === "tarde"
                                    ? "selected"
                                    : "" ?>
                            >
                                Tardanzas
                            </option>

                            <option
                                value="extra"
                                <?= $estado === "extra"
                                    ? "selected"
                                    : "" ?>
                            >
                                Horas extra
                            </option>

                            <option
                                value="sin"
                                <?= $estado === "sin"
                                    ? "selected"
                                    : "" ?>
                            >
                                Sin registro
                            </option>

                        </select>

                    </div>


                    <div>

                        <label for="buscar">
                            Empleado
                        </label>

                        <input
                            type="text"
                            id="buscar"
                            name="buscar"
                            placeholder="Nombre o identificación"
                            value="<?= escapar(
                                $buscar
                            ) ?>"
                        >

                    </div>


                    <button
                        type="submit"
                        class="btn-filtrar"
                    >
                        Buscar
                    </button>

                </form>


                <?php if ($buscar !== ""): ?>

                    <div class="cards-dashboard">

                        <div class="card-stat">

                            <h3>
                                Días registrados
                            </h3>

                            <p>
                                <?= $diasRegistrados ?>
                            </p>

                        </div>


                        <div class="card-stat">

                            <h3>
                                Días tarde
                            </h3>

                            <p>
                                <?= $diasTarde ?>
                            </p>

                        </div>


                        <div class="card-stat">

                            <h3>
                                Retraso acumulado
                            </h3>

                            <p>
                                <?= minutosAHoras(
                                    $retrasoAcumulado
                                ) ?>
                            </p>

                        </div>


                        <div class="card-stat">

                            <h3>
                                Horas extra acumuladas
                            </h3>

                            <p>
                                <?= minutosAHoras(
                                    $extraAcumulado
                                ) ?>
                            </p>

                        </div>

                    </div>

                <?php endif; ?>


                <?php if (
                    $estado === "sin"
                ): ?>

                    <div class="tabla-contenedor">

                        <table class="tabla">

                            <thead>

                                <tr>

                                    <th>
                                        Empleado
                                    </th>

                                    <th>
                                        Identificación
                                    </th>

                                    <th>
                                        Cargo
                                    </th>

                                    <th>
                                        Horario
                                    </th>

                                    <th>
                                        Estado
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                            <?php if (
                                !$sinRegistroHistorial ||
                                $sinRegistroHistorial->num_rows === 0
                            ): ?>

                                <tr>

                                    <td
                                        colspan="5"
                                        class="sin-resultados"
                                    >
                                        No hay empleados sin registro.
                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php while (
                                    $fila =
                                    $sinRegistroHistorial->fetch_assoc()
                                ): ?>

                                    <tr>

                                        <td>
                                            <?= escapar(
                                                $fila["nombre"]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= escapar(
                                                $fila[
                                                    "identificacion"
                                                ]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= escapar(
                                                $fila["cargo"]
                                            ) ?>
                                        </td>

                                        <td>

                                            <?= formatoHora(
                                                $fila[
                                                    "hora_entrada"
                                                ]
                                            ) ?>

                                            -

                                            <?= formatoHora(
                                                $fila[
                                                    "hora_salida"
                                                ]
                                            ) ?>

                                        </td>

                                        <td>

                                            <span
                                                class="estado estado-tarde"
                                            >
                                                Sin registro
                                            </span>

                                        </td>

                                    </tr>

                                <?php endwhile; ?>

                            <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                <?php else: ?>

                    <div class="tabla-contenedor">

                        <table class="tabla">

                            <thead>

                                <tr>

                                    <th>Fecha</th>
                                    <th>Empleado</th>
                                    <th>Cargo</th>
                                    <th>Entrada</th>
                                    <th>Estado</th>
                                    <th>Retraso</th>
                                    <th>S. Almuerzo</th>
                                    <th>E. Almuerzo</th>
                                    <th>Salida</th>
                                    <th>Extra</th>
                                    <th>Deuda</th>
                                    <th>Justificación</th>
                                    <th>Just. Salida</th>

                                </tr>

                            </thead>

                            <tbody>

                            <?php if (
                                !$historial ||
                                $historial->num_rows === 0
                            ): ?>

                                <tr>

                                    <td
                                        colspan="13"
                                        class="sin-resultados"
                                    >
                                        No hay registros para el período seleccionado.
                                    </td>

                                </tr>

                            <?php else: ?>

                                <?php while (
                                    $fila =
                                    $historial->fetch_assoc()
                                ): ?>

                                    <tr>

                                        <td>
                                            <?= escapar(
                                                $fila["fecha"]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= escapar(
                                                $fila["nombre"]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= escapar(
                                                $fila["cargo"]
                                            ) ?>
                                        </td>

                                        <td>
                                            <?= formatoHora(
                                                $fila[
                                                    "hora_entrada"
                                                ]
                                            ) ?>
                                        </td>

                                        <td>

                                            <?php if (
                                                (int)
                                                $fila[
                                                    "minutos_retraso"
                                                ] > 0
                                            ): ?>

                                                <span
                                                    class="estado estado-tarde"
                                                >
                                                    Tarde
                                                </span>

                                            <?php else: ?>

                                                <span
                                                    class="estado estado-puntual"
                                                >
                                                    Puntual
                                                </span>

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <?php if (
                                                (int)
                                                $fila[
                                                    "minutos_retraso"
                                                ] > 0
                                            ): ?>

                                                <span class="retraso">

                                                    <?= (int)
                                                        $fila[
                                                            "minutos_retraso"
                                                        ] ?>

                                                    min

                                                </span>

                                            <?php else: ?>

                                                —

                                            <?php endif; ?>

                                        </td>

                                        <td>
                                            <?= formatoHora($fila["hora_salida_almuerzo"]) ?>
                                        </td>

                                        <td>
                                            <?php 
                                                echo formatoHora($fila["hora_entrada_almuerzo"]);
                                               if (!empty($fila["minutos_retraso_almuerzo"]) && (int)$fila["minutos_retraso_almuerzo"] > 0) {
                                                    echo '<br><small class="retraso">+' . (int)$fila["minutos_retraso_almuerzo"] . ' min</small>';
                                                }
                                            ?>
                                        </td>

                                        <td>
                                            <?= formatoHora(
                                                $fila[
                                                    "hora_salida"
                                                ]
                                            ) ?>
                                        </td>

                                        <td>

                                            <?php if (
                                                (int)
                                                $fila[
                                                    "minutos_extra"
                                                ] > 0
                                            ): ?>

                                                <span class="extra">

                                                    <?= minutosAHoras(
                                                        $fila[
                                                            "minutos_extra"
                                                        ]
                                                    ) ?>

                                                </span>

                                            <?php else: ?>

                                                —

                                            <?php endif; ?>

                                        </td>

                                        <td>

                                            <?php if (
                                                isset($fila["minutos_deuda"]) &&
                                                (int)
                                                $fila[
                                                    "minutos_deuda"
                                                ] > 0
                                            ): ?>

                                                <span class="color-deuda">

                                                    <?= (int)
                                                        $fila[
                                                            "minutos_deuda"
                                                        ] ?>

                                                    min

                                                </span>

                                            <?php else: ?>

                                                —

                                            <?php endif; ?>

                                        </td>

                                        <td>
                                            <?= !empty($fila["justificacion"]) ? escapar($fila["justificacion"]) : "—" ?>
                                        </td>

                                        <td>
                                            <?= !empty($fila["justificacion_salida"]) ? escapar($fila["justificacion_salida"]) : "—" ?>
                                        </td>

                                    </tr>

                                <?php endwhile; ?>

                            <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

            </section>


           <section id="historial" class="section">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
                    <div>
                        <h2>📂 Acumulado General por Empleado</h2>
                        <p style="margin: 0;">Resumen total histórico de retrasos, horas extra y deudas acumuladas por cada empleado.</p>
                    </div>
                    <button type="button" class="btn-justificaciones" onclick="abrirModalTodasJustificaciones()">Ver todas las justificaciones</button>
                </div>

                <form method="GET" action="admin.php#historial" class="filtros">
                    <input type="hidden" name="seccion" value="historial">
                    <div>
                        <label for="desde_historial">Desde</label>
                        <input type="date" id="desde_historial" name="desde_h" value="<?= escapar($_GET["desde_h"] ?? "") ?>">
                    </div>
                    <div>
                        <label for="hasta_historial">Hasta</label>
                        <input type="date" id="hasta_historial" name="hasta_h" value="<?= escapar($_GET["hasta_h"] ?? "") ?>">
                    </div>
                    <div>
                        <label for="buscar_historial">Empleado</label>
                        <input type="text" id="buscar_historial" name="buscar_h" placeholder="Nombre o identificación" value="<?= escapar($_GET["buscar_h"] ?? "") ?>">
                    </div>
                    <button type="submit" class="btn-filtrar">Filtrar Acumulado</button>
                </form>

                <?php
                $desdeH = $_GET["desde_h"] ?? "";
                $hastaH = $_GET["hasta_h"] ?? "";
                $buscarH = trim($_GET["buscar_h"] ?? "");

                $sqlAcumuladoGeneral = "
                    SELECT
                        e.id,
                        e.nombre,
                        e.identificacion,
                        e.cargo,
                        COUNT(a.id) AS total_dias,
                        COALESCE(SUM(a.minutos_retraso), 0) AS total_retraso,
                        COALESCE(SUM(a.minutos_extra), 0) AS total_extra,
                        COALESCE(SUM(a.minutos_deuda), 0) AS total_deuda
                    FROM empleados e
                    LEFT JOIN asistencias a ON a.empleado_id = e.id
                ";

                $paramsAG = [];
                $tiposAG = "";
                $whereAG = ["e.activo = 1"];

                if (!empty($desdeH) && !empty($hastaH)) {
                    $whereAG[] = "a.fecha BETWEEN ? AND ?";
                    $paramsAG[] = $desdeH;
                    $paramsAG[] = $hastaH;
                    $tiposAG .= "ss";
                }

                if (!empty($buscarH)) {
                    $whereAG[] = "(e.nombre LIKE ? OR e.identificacion LIKE ?)";
                    $likeAG = "%" . $buscarH . "%";
                    $paramsAG[] = $likeAG;
                    $paramsAG[] = $likeAG;
                    $tiposAG .= "ss";
                }

                if (count($whereAG) > 0) {
                    $sqlAcumuladoGeneral .= " WHERE " . implode(" AND ", $whereAG);
                }

                $sqlAcumuladoGeneral .= " GROUP BY e.id, e.nombre, e.identificacion, e.cargo ORDER BY e.nombre ASC";

                $stmtAG = $conexion->prepare($sqlAcumuladoGeneral);
                if ($stmtAG && !empty($paramsAG)) {
                    $stmtAG->bind_param($tiposAG, ...$paramsAG);
                }
                if ($stmtAG) {
                    $stmtAG->execute();
                    $resultadoAG = $stmtAG->get_result();
                    $filasAcumulado = $resultadoAG ? $resultadoAG->fetch_all(MYSQLI_ASSOC) : [];
                } else {
                    $resultadoAG = null;
                    $filasAcumulado = [];
                }

                $datosGraficoAcumulado = array_map(function ($f) {
                    return [
                        "nombre" => $f["nombre"],
                        "cargo" => $f["cargo"],
                        "retraso" => (int) $f["total_retraso"],
                        "extra" => (int) $f["total_extra"],
                        "deuda" => (int) $f["total_deuda"],
                    ];
                }, $filasAcumulado);
                ?>

                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Empleado</th>
                                <th>Identificación</th>
                                <th>Cargo</th>
                                <th>Días Asistidos</th>
                                <th>Días No Asistidos</th>
                                <th>Total Retraso</th>
                                <th>Total Extra</th>
                                <th>Total Deuda</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($filasAcumulado)): ?>
                            <tr>
                                <td colspan="8" class="sin-resultados">No se encontraron registros acumulados.</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($filasAcumulado as $rowAG):
                                $retrasoBruto = (int)$rowAG["total_retraso"];
                                $extraBruto = (int)$rowAG["total_extra"];
                                $deudaBruta = (int)$rowAG["total_deuda"];
                                $diasAsistidos = (int)$rowAG["total_dias"];

                                $diasNoAsistidos = "—";
                                if (!empty($desdeH) && !empty($hastaH)) {
                                    $diasTotalesRango = (strtotime($hastaH) - strtotime($desdeH)) / 86400 + 1;
                                    $diasNoAsistidosCalc = $diasTotalesRango - $diasAsistidos;
                                    $diasNoAsistidos = $diasNoAsistidosCalc > 0 ? $diasNoAsistidosCalc : 0;
                                }
                            ?>
                                <tr>
                                    <td><?= escapar($rowAG["nombre"]) ?></td>
                                    <td><?= escapar($rowAG["identificacion"]) ?></td>
                                    <td><?= escapar($rowAG["cargo"]) ?></td>
                                    <td><?= $diasAsistidos ?></td>
                                    <td><?= $diasNoAsistidos ?></td>
                                    <td>
                                        <?php if ($retrasoBruto > 0): ?>
                                            <span class="color-retraso"><?= minutosAHoras($retrasoBruto) ?> (<?= $retrasoBruto ?> min)</span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($extraBruto > 0): ?>
                                            <span class="color-extra"><?= minutosAHoras($extraBruto) ?></span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($deudaBruta > 0): ?>
                                            <span class="color-deuda"><?= minutosAHoras($deudaBruta) ?> (<?= $deudaBruta ?> min)</span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="tarjeta-grafico">
                    <div class="tarjeta-grafico-cabecera">
                        <h3 style="margin:0;">📊 Ranking de Empleados</h3>
                        <div class="selector-metrica">
                            <label for="metricaGraficoHistorial">Ver por:</label>
                            <select id="metricaGraficoHistorial" onchange="actualizarGraficoAcumulado()">
                                <option value="retraso">Minutos de retraso</option>
                                <option value="extra">Minutos extra</option>
                                <option value="deuda">Minutos que deben</option>
                            </select>
                        </div>
                    </div>
                    <div style="position: relative; width: 100%;" id="contenedorGraficoAcumulado">
                        <canvas id="graficoAcumuladoHistorial" height="90"></canvas>
                    </div>
                    <p id="sinDatosGrafico" style="display:none; text-align:center; color:#6c757d; margin-top: 10px;">
                        No hay datos para graficar con los filtros actuales.
                    </p>
                </div>

                <script>
                    const datosGraficoAcumulado = <?= json_encode($datosGraficoAcumulado, JSON_UNESCAPED_UNICODE) ?>;
                    let graficoAcumuladoInstancia = null;

                    function actualizarGraficoAcumulado() {
                        const metrica = document.getElementById("metricaGraficoHistorial").value;
                        const etiquetasMetrica = {
                            retraso: "Minutos de retraso",
                            extra: "Minutos extra",
                            deuda: "Minutos que deben"
                        };
                        const coloresMetrica = {
                            retraso: "#dc3545",
                            extra: "#d39e00",
                            deuda: "#007bff"
                        };

                        const ordenado = [...datosGraficoAcumulado]
                            .filter(f => f[metrica] > 0)
                            .sort((a, b) => b[metrica] - a[metrica]);

                        const sinDatos = document.getElementById("sinDatosGrafico");
                        const contenedor = document.getElementById("contenedorGraficoAcumulado");

                        if (ordenado.length === 0) {
                            sinDatos.style.display = "block";
                            contenedor.style.display = "none";
                            if (graficoAcumuladoInstancia) {
                                graficoAcumuladoInstancia.destroy();
                                graficoAcumuladoInstancia = null;
                            }
                            return;
                        }

                        sinDatos.style.display = "none";
                        contenedor.style.display = "block";

                        const etiquetas = ordenado.map(f => f.nombre + " (" + f.cargo + ")");
                        const valores = ordenado.map(f => f[metrica]);

                        // Altura dinámica según cantidad de empleados, para que no se amontonen las barras
                        const canvas = document.getElementById("graficoAcumuladoHistorial");
                        canvas.parentElement.style.height = Math.max(90, ordenado.length * 34) + "px";

                        if (graficoAcumuladoInstancia) {
                            graficoAcumuladoInstancia.destroy();
                        }

                        graficoAcumuladoInstancia = new Chart(canvas, {
                            type: "bar",
                            data: {
                                labels: etiquetas,
                                datasets: [{
                                    label: etiquetasMetrica[metrica],
                                    data: valores,
                                    backgroundColor: coloresMetrica[metrica],
                                    borderRadius: 4,
                                    maxBarThickness: 26
                                }]
                            },
                            options: {
                                indexAxis: "y",
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            label: (ctx) => {
                                                const min = ctx.parsed.x;
                                                const h = Math.floor(min / 60);
                                                const m = min % 60;
                                                const texto = h > 0 ? (h + "h " + m + "min") : (m + " min");
                                                return etiquetasMetrica[metrica] + ": " + texto;
                                            }
                                        }
                                    }
                                },
                                scales: {
                                    x: {
                                        beginAtZero: true,
                                        title: { display: true, text: "Minutos" }
                                    }
                                }
                            }
                        });
                    }

                    document.addEventListener("DOMContentLoaded", actualizarGraficoAcumulado);
                </script>
            </section>


            <section
                id="empleados"
                class="section"
            >

                <div class="cabecera-seccion">

                    <div>

                        <h2>
                            👥 Gestionar Empleados
                        </h2>

                        <p>
                            Administre los empleados activos e inactivos.
                        </p>

                    </div>


                    <button
                        type="button"
                        onclick="mostrarFormularioEmpleado()"
                        class="btn-nuevo"
                    >
                        + Nuevo Empleado
                    </button>

                </div>


                <div class="tabla-contenedor">

                    <table class="tabla">

                        <thead>

                            <tr>

                                <th>
                                    Nombre
                                </th>

                                <th>
                                    Primer registro
                                </th>

                                <th>
                                    Documento
                                </th>

                                <th>
                                    Cargo
                                </th>

                                <th>
                                    Horario
                                </th>

                                <th>
                                    Estado
                                </th>

                                <th>
                                    Acciones
                                </th>

                            </tr>

                        </thead>


                        <tbody>

                        <?php if (
                            !$resultadoEmpleados ||
                            $resultadoEmpleados->num_rows === 0
                        ): ?>

                            <tr>

                                <td
                                    colspan="7"
                                    class="sin-resultados"
                                >
                                    No hay empleados registrados.
                                </td>

                            </tr>

                        <?php else: ?>

                            <?php while (
                                $empleado =
                                $resultadoEmpleados->fetch_assoc()
                            ): ?>

                                <tr>

                                    <td>
                                        <?= escapar(
                                            $empleado["nombre"]
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= !empty($empleado["primer_registro"])
                                            ? date("d/m/Y", strtotime($empleado["primer_registro"]))
                                            : "—" ?>
                                    </td>

                                    <td>
                                        <?= escapar(
                                            $empleado[
                                                "identificacion"
                                            ]
                                        ) ?>
                                    </td>

                                    <td>
                                        <?= escapar(
                                            $empleado["cargo"]
                                        ) ?>
                                    </td>

                                    <td>

                                        <?= formatoHora(
                                            $empleado[
                                                "hora_entrada"
                                            ]
                                        ) ?>

                                        -

                                        <?= formatoHora(
                                            $empleado[
                                                "hora_salida"
                                            ]
                                        ) ?>

                                    </td>

                                    <td>

                                        <?php if (
                                            (int)
                                            $empleado["activo"] === 1
                                        ): ?>

                                            <span
                                                class="estado estado-activo"
                                            >
                                                Activo
                                            </span>

                                        <?php else: ?>

                                            <span
                                                class="estado estado-inactivo"
                                            >
                                                Inactivo
                                            </span>

                                        <?php endif; ?>

                                    </td>

                                    <td>

                                        <button
                                            type="button"
                                            onclick='editarEmpleado(
                                                <?= (int)
                                                    $empleado["id"] ?>,
                                                <?= json_encode(
                                                    $empleado["nombre"]
                                                ) ?>,
                                                <?= json_encode(
                                                    $empleado[
                                                        "identificacion"
                                                    ]
                                                ) ?>,
                                                <?= json_encode(
                                                    $empleado["cargo"]
                                                ) ?>
                                            )'
                                            class="btn-editar"
                                        >
                                            Editar
                                        </button>


                                        <form
                                            method="POST"
                                            style="display:inline;"
                                            onsubmit='return mostrarConfirmacionEstado(
                                                event,
                                                this,
                                                <?= json_encode(
                                                    $empleado[
                                                        "nombre"
                                                    ]
                                                ) ?>,
                                                <?= (int)
                                                    $empleado[
                                                        "activo"
                                                    ] ?>
                                            )'
                                        >

                                            <input
                                                type="hidden"
                                                name="accion"
                                                value="cambiar_estado"
                                            >

                                            <input
                                                type="hidden"
                                                name="id"
                                                value="<?= (int)
                                                    $empleado[
                                                        "id"
                                                    ] ?>"
                                            >


                                            <?php if (
                                                (int)
                                                $empleado[
                                                    "activo"
                                                ] === 1
                                            ): ?>

                                                <input
                                                    type="hidden"
                                                    name="nuevo_estado"
                                                    value="0"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn-eliminar"
                                                >
                                                    Desactivar
                                                </button>

                                            <?php else: ?>

                                                <input
                                                    type="hidden"
                                                    name="nuevo_estado"
                                                    value="1"
                                                >

                                                <button
                                                    type="submit"
                                                    class="btn-activar"
                                                >
                                                    Activar
                                                </button>

                                            <?php endif; ?>

                                        </form>

                                    </td>

                                </tr>

                            <?php endwhile; ?>

                        <?php endif; ?>

                        </tbody>

                    </table>

                </div>

            </section>




            <section
                id="festivos"
                class="section"
            >

                <h2>
                    🎉 Gestionar Festivos
                </h2>

                <p>
                    Administre los días festivos de la empresa.
                </p>


                <div class="festivos-botones">

                    <a
                        href="gestionar_festivos.php"
                        class="boton-festivo"
                    >
                        📋 Ver y Eliminar
                    </a>


                    <a
                        href="crear_festivo.php"
                        class="boton-festivo boton-festivo-secundario"
                    >
                        ➕ Crear Festivo
                    </a>

                </div>

            </section>

            <!-- SECCIÓN PERMISOS INTEGRADA -->
            <section
                id="permisos"
                class="section"
            >
                <h2>📝 Solicitudes de Permisos</h2>
                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Empleado</th>
                                <th>Tipo</th>
                                <th>Fecha</th>
                                <th>Motivo</th>
                                <th>Soporte</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $q_permisos = $conexion->query("SELECT p.*, e.nombre FROM permisos p JOIN empleados e ON p.empleado_id = e.id ORDER BY p.creado_en DESC");
                            if (!$q_permisos || $q_permisos->num_rows === 0):
                            ?>
                                <tr>
                                    <td colspan="5" class="sin-resultados">No hay solicitudes de permisos.</td>
                                </tr>
                            <?php else: ?>
                                <?php while($rowP = $q_permisos->fetch_assoc()): ?>
                                <tr>
                                    <td><?= escapar($rowP['nombre']) ?></td>
                                    <td><?= escapar($rowP['tipo']) ?></td>
                                    <td><?= escapar($rowP['fecha']) ?></td>
                                    <td><?= escapar($rowP['motivo']) ?></td>
                                    <td>
                                        <?php if (!empty($rowP['soporte_datos'])): ?>
                                            <button
                                                type="button"
                                                class="btn-activar"
                                                style="padding:5px 10px; font-size:11px; border:none; cursor:pointer;"
                                                data-tipo="<?= escapar($rowP['soporte_tipo']) ?>"
                                                data-datos="<?= $rowP['soporte_datos'] ?>"
                                                onclick="verSoporte(this)"
                                            >
                                                📎 Ver
                                            </button>
                                        <?php else: ?>
                                            <span style="color:#aaa;">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <?php
            include __DIR__ . "/admin_seccion_vendedores.php";
            include __DIR__ . "/admin_seccion_pedidos.php";
            include __DIR__ . "/admin_seccion_despachos.php";
            include __DIR__ . "/admin_seccion_productos.php";
            include __DIR__ . "/admin_seccion_clientes.php";
            ?>

            <!-- SECCIÓN MATERIALES INTEGRADA -->
            <section
                id="materiales"
                class="section"
            >
                <h2>📦 Solicitudes de Materiales</h2>
                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Empleado</th>
                                <th>Material</th>
                                <th>Cantidad</th>
                                <th>Estado</th>
                                <th>Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $q_materiales = $conexion->query("SELECT sm.*, e.nombre FROM solicitud_materiales sm JOIN empleados e ON sm.empleado_id = e.id ORDER BY sm.creado_en DESC");
                            if (!$q_materiales || $q_materiales->num_rows === 0):
                            ?>
                                <tr>
                                    <td colspan="5" class="sin-resultados">No hay solicitudes de materiales.</td>
                                </tr>
                            <?php else: ?>
                                <?php while($rowM = $q_materiales->fetch_assoc()): ?>
                                <tr>
                                    <td><?= escapar($rowM['nombre']) ?></td>
                                    <td><?= escapar($rowM['material']) ?></td>
                                    <td><?= escapar($rowM['cantidad']) ?></td>
                                    <td><strong><?= ucfirst(escapar($rowM['estado'])) ?></strong></td>
                                    <td>
                                        <?php if($rowM['estado'] === 'pendiente'): ?>
                                            <a href="admin.php?accion_solicitud=aprobado&id_solicitud=<?= $rowM['id'] ?>&tipo_solicitud=material" class="btn-activar" style="padding:5px 10px; text-decoration:none; font-size:11px;">Aprobar</a>
                                            <a href="admin.php?accion_solicitud=rechazado&id_solicitud=<?= $rowM['id'] ?>&tipo_solicitud=material" class="btn-eliminar" style="padding:5px 10px; text-decoration:none; font-size:11px;">Rechazar</a>
                                        <?php else: ?>
                                            <span style="color:#78909c;">Gestionado</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </section>

        </div>

    </main>

</div>


<div class="modal bf-dlg" id="bfDialogo">

    <div
        class="modal-contenido bf-dlg-caja"
        role="alertdialog"
        aria-modal="true"
        aria-labelledby="bfDlgTitulo"
        aria-describedby="bfDlgTexto"
    >

        <div class="bf-dlg-icono" id="bfDlgIcono">❔</div>

        <h3 id="bfDlgTitulo"></h3>

        <p id="bfDlgTexto"></p>

        <div class="bf-dlg-botones">
            <button type="button" class="bf-dlg-btn bf-dlg-no" id="bfDlgNo">Cancelar</button>
            <button type="button" class="bf-dlg-btn bf-dlg-si" id="bfDlgSi">Aceptar</button>
        </div>

    </div>

</div>


<div
    class="modal"
    id="modalConfirmar"
>

    <div
        class="modal-contenido"
        style="max-width: 400px; text-align: center;"
    >

        <h3 id="confirmTitulo"></h3>

        <p
            id="confirmMensaje"
            style="color: #555; margin-bottom: 25px; line-height: 1.5;"
        ></p>

        <div
            class="botones-modal"
            style="justify-content: center;"
        >

            <button
                type="button"
                class="boton-secundario"
                onclick="cerrarConfirmar()"
            >
                Cancelar
            </button>

            <button
                type="button"
                class="boton"
                onclick="aceptarConfirmar()"
            >
                Aceptar
            </button>

        </div>

    </div>

</div>


<div
    class="modal"
    id="modalEmpleado"
>

    <div class="modal-contenido">

        <h3 id="tituloModal">
            Nuevo empleado
        </h3>


        <form
            method="POST"
            class="formulario-modal"
        >

            <input
                type="hidden"
                name="accion"
                id="accionEmpleado"
                value="agregar_empleado"
            >

            <input
                type="hidden"
                name="id"
                id="idEmpleado"
                value=""
            >


            <div>

                <label for="nombreEmpleado">
                    Nombre completo
                </label>

                <input
                    type="text"
                    id="nombreEmpleado"
                    name="nombre"
                    required
                >

            </div>


            <div>

                <label for="identificacionEmpleado">
                    Número de identificación
                </label>

                <input
                    type="text"
                    id="identificacionEmpleado"
                    name="identificacion"
                    required
                >

            </div>


            <div>

                <label for="cargoEmpleado">
                    Cargo
                </label>

                <select
                    id="cargoEmpleado"
                    name="cargo"
                    required
                    onchange="actualizarHorario()"
                >

                    <option value="">
                        Seleccione un cargo
                    </option>

                    <option value="Producción">
                        Producción
                    </option>

                    <option value="Ventas">
                        Ventas
                    </option>

                    <option value="Administración">
                        Administración
                    </option>

                    <option value="Temporales">
                        Temporales
                    </option>

                    <option value="Jefe de Maquinaria">
                        Jefe de Maquinaria
                    </option>

                    <option value="Directora de Despachos">
                        Directora de Despachos
                    </option>

                </select>

            </div>


            <div>

                <label>
                    Horario
                </label>

                <div
                    class="horario"
                    id="horarioMostrado"
                >
                    Seleccione un cargo.
                </div>

            </div>


            <div class="botones-modal">

                <button
                    type="button"
                    class="boton boton-secundario"
                    onclick="cerrarModal()"
                >
                    Cancelar
                </button>


                <button
                    type="submit"
                    class="boton"
                >
                    Guardar
                </button>

            </div>

        </form>

    </div>

</div>

<!-- Modal para Editar Minutos de Deuda por Día / Desglose -->
<div
    class="modal"
    id="modalAsistencia"
    style="display:none;"
>
    <div class="modal-contenido" style="max-width: 450px;">
        <h3>Editar Deuda de Asistencia</h3>
        <p id="infoDetalleDia" style="font-size: 0.9rem; color: #555; margin-bottom: 10px;"></p>
        <form method="POST" class="formulario-modal">
            <input type="hidden" name="accion" value="editar_asistencia">
            <input type="hidden" name="asistencia_id" id="editAsistenciaId">

            <div style="margin-bottom: 15px;">
                <label for="editMinutosDeuda" style="display:block; margin-bottom: 5px; font-weight: bold;">Minutos de Deuda</label>
                <input type="number" id="editMinutosDeuda" name="minutos_deuda" required style="width: 100%; padding: 8px; box-sizing: border-box;">
            </div>

            <div style="margin-bottom: 15px;">
                <label for="editMotivoDeuda" style="display:block; margin-bottom: 5px; font-weight: bold;">Motivo / Justificación (ej. Cita médica, salida antes de tiempo)</label>
                <input type="text" id="editMotivoDeuda" name="motivo_deuda" placeholder="Desglose del motivo..." style="width: 100%; padding: 8px; box-sizing: border-box;">
            </div>

            <div class="botones-modal" style="display: flex; justify-content: flex-end; gap: 10px;">
                <button type="button" class="boton boton-secundario" onclick="cerrarModalAsistencia()">Cancelar</button>
                <button type="submit" class="boton">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal para Listar los Días del Empleado desde el Historial Completo -->
<div
    class="modal"
    id="modalDetalleEmpleado"
    style="display:none;"
>
    <div class="modal-contenido" style="max-width: 850px; width: 95%;">
        <h3 id="tituloDetalleEmpleado">Desglose de Días del Empleado</h3>
        <div class="tabla-contenedor" style="max-height: 400px; overflow-y: auto; margin-top: 15px;">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Entrada</th>
                        <th>Salida</th>
                        <th>Retraso</th>
                        <th>Extra</th>
                        <th>Deuda</th>
                        <th>Motivo / Justificación</th>
                        <th>Acción</th>
                    </tr>
                </thead>
                <tbody id="tablaDiasEmpleadoBody">
                    <tr><td colspan="8" class="sin-resultados">Cargando...</td></tr>
                </tbody>
            </table>
        </div>
        <div class="botones-modal" style="display: flex; justify-content: flex-end; margin-top: 15px;">
            <button type="button" class="boton boton-secundario" onclick="cerrarModalDetalleEmpleado()">Cerrar</button>
        </div>
    </div>
</div>

<!-- Modal para Ver Todas las Justificaciones -->
<div
    class="modal"
    id="modalTodasJustificaciones"
    style="display:none;"
>
    <div class="modal-contenido" style="max-width: 800px; width: 90%;">
        <h3>Todas las Justificaciones</h3>
        <div class="tabla-contenedor" style="max-height: 400px; overflow-y: auto; margin-top: 15px;">
            <table class="tabla">
                <thead>
                    <tr>
                        <th>Fecha</th>
                        <th>Empleado</th>
                        <th>Tipo</th>
                        <th>Justificación</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $sqlJustificacionesModal = "
                        SELECT
                            a.fecha,
                            e.nombre,
                            a.justificacion,
                            a.justificacion_salida
                        FROM asistencias a
                        INNER JOIN empleados e ON e.id = a.empleado_id
                        WHERE (a.justificacion IS NOT NULL AND a.justificacion != '')
                           OR (a.justificacion_salida IS NOT NULL AND a.justificacion_salida != '')
                        ORDER BY a.fecha DESC, e.nombre ASC
                    ";
                    $resJustMod = $conexion->query($sqlJustificacionesModal);
                    $hayJustificaciones = false;
                    if ($resJustMod && $resJustMod->num_rows > 0) {
                        while ($jRow = $resJustMod->fetch_assoc()) {
                            if (!empty($jRow["justificacion"])) {
                                $hayJustificaciones = true;
                                echo "<tr>";
                                echo "<td>" . escapar($jRow["fecha"]) . "</td>";
                                echo "<td>" . escapar($jRow["nombre"]) . "</td>";
                                echo "<td>Entrada</td>";
                                echo "<td>" . escapar($jRow["justificacion"]) . "</td>";
                                echo "</tr>";
                            }
                            if (!empty($jRow["justificacion_salida"])) {
                                $hayJustificaciones = true;
                                echo "<tr>";
                                echo "<td>" . escapar($jRow["fecha"]) . "</td>";
                                echo "<td>" . escapar($jRow["nombre"]) . "</td>";
                                echo "<td>Salida</td>";
                                echo "<td>" . escapar($jRow["justificacion_salida"]) . "</td>";
                                echo "</tr>";
                            }
                        }
                    }
                    if (!$hayJustificaciones) {
                        echo '<tr><td colspan="4" class="sin-resultados">No hay justificaciones registradas.</td></tr>';
                    }
                    ?>
                </tbody>
            </table>
        </div>
        <div class="botones-modal" style="display: flex; justify-content: flex-end; margin-top: 15px;">
            <button type="button" class="boton boton-secundario" onclick="cerrarModalTodasJustificaciones()">Cerrar</button>
        </div>
    </div>
</div>


<!-- Modal para Ver Soporte de Permiso -->
<div
    class="modal"
    id="modalSoporte"
    style="display:none;"
>
    <div class="modal-contenido" style="max-width: 700px; width: 90%; text-align: center;">
        <h3>Soporte adjunto</h3>
        <div style="margin-top: 15px;">
            <img id="imgSoporte" style="display:none; max-width: 100%; max-height: 70vh; border-radius: 6px;">
            <iframe id="pdfSoporte" style="display:none; width: 100%; height: 70vh; border: none;"></iframe>
        </div>
        <div class="botones-modal" style="display: flex; justify-content: flex-end; margin-top: 15px;">
            <button type="button" class="boton boton-secundario" onclick="cerrarModalSoporte()">Cerrar</button>
        </div>
    </div>
</div>


<script>

    function mostrarSeccion(seccion)
    {
        document
            .querySelectorAll(".section")
            .forEach(function(elemento)
            {
                elemento.classList.remove("active");
            });

        document
            .querySelectorAll(".nav-item")
            .forEach(function(elemento)
            {
                elemento.classList.remove("active");
            });

        const objetivo =
            document.getElementById(seccion);

        if (objetivo) {
            objetivo.classList.add("active");
        }
    }


    (function inicializarSeccionActiva()
    {
        const hash =
            window.location.hash.replace("#", "");

        if (hash === "") {
            return;
        }

        mostrarSeccion(hash);

        document
            .querySelectorAll(".nav-item")
            .forEach(function(item)
            {
                item.classList.remove("active");
            });

        const navCorrespondiente =
            document.querySelector(
                '.nav-item[href="#' + hash + '"]'
            );

        if (navCorrespondiente) {
            navCorrespondiente.classList.add("active");
        }
    })();


    document
        .querySelectorAll(".nav-item[href^=\"#\"]")
        .forEach(function(elemento)
        {
            elemento.addEventListener(
                "click",
                function()
                {
                    document
                        .querySelectorAll(".nav-item")
                        .forEach(function(item)
                        {
                            item.classList.remove(
                                "active"
                            );
                        });

                    this.classList.add("active");
                }
            );
        });


    function mostrarFormularioEmpleado()
    {
        document.getElementById(
            "tituloModal"
        ).textContent =
            "Nuevo empleado";

        document.getElementById(
            "accionEmpleado"
        ).value =
            "agregar_empleado";

        document.getElementById(
            "idEmpleado"
        ).value =
            "";

        document.getElementById(
            "nombreEmpleado"
        ).value =
            "";

        document.getElementById(
            "identificacionEmpleado"
        ).value =
            "";

        document.getElementById(
            "cargoEmpleado"
        ).value =
            "";

        document.getElementById(
            "horarioMostrado"
        ).textContent =
            "Seleccione un cargo.";

        document.getElementById(
            "modalEmpleado"
        ).style.display =
            "flex";
    }


    function editarEmpleado(
        id,
        nombre,
        identificacion,
        cargo
    )
    {
        document.getElementById(
            "tituloModal"
        ).textContent =
            "Editar empleado";

        document.getElementById(
            "accionEmpleado"
        ).value =
            "editar_empleado";

        document.getElementById(
            "idEmpleado"
        ).value =
            id;

        document.getElementById(
            "nombreEmpleado"
        ).value =
            nombre;

        document.getElementById(
            "identificacionEmpleado"
        ).value =
            identificacion;

        document.getElementById(
            "cargoEmpleado"
        ).value =
            cargo;

        actualizarHorario();

        document.getElementById(
            "modalEmpleado"
        ).style.display =
            "flex";
    }


    function actualizarHorario()
    {
        const cargo =
            document.getElementById(
                "cargoEmpleado"
            ).value;

        const horario =
            document.getElementById(
                "horarioMostrado"
            );

        if (cargo === "Producción") {

            horario.textContent =
                "Lunes a viernes: 7:30 AM - 5:05 PM. Si lo citan un sábado: 7:30 AM en adelante cuenta 100% como horas extra. Domingo: no trabaja.";

        } else if (
            cargo === "Ventas" ||
            cargo === "Administración"
        ) {

            horario.textContent =
                "Lunes a viernes: 8:30 AM - 5:15 PM. Sábado: 8:30 AM - 12:30 PM. Domingo: no trabaja.";

        } else if (cargo === "Temporales") {

            horario.textContent =
                "Sin horario fijo: se paga por las horas trabajadas (de la entrada a la salida). No tiene retrasos, horas extra ni días no asistidos.";

        } else if (cargo === "Jefe de Maquinaria") {

            horario.textContent =
                "Lunes a viernes: 7:30 AM - 5:00 PM (almuerzo 12:00 PM - 1:05 PM). Si lo citan un sábado: 7:30 AM en adelante cuenta 100% como horas extra. Domingo: no trabaja.";

        } else if (cargo === "Directora de Despachos") {

            horario.textContent =
                "Lunes a jueves: 8:30 AM - 6:15 PM. Viernes: 8:30 AM - 5:45 PM. Sábado y domingo: no trabaja.";

        } else {

            horario.textContent =
                "Seleccione un cargo.";
        }
    }


    function cerrarModal()
    {
        document.getElementById(
            "modalEmpleado"
        ).style.display =
            "none";
    }


    let formularioPendiente = null;

    function mostrarConfirmacionEstado(
        event,
        formulario,
        nombre,
        activo
    )
    {
        event.preventDefault();

        formularioPendiente = formulario;

        const titulo =
            activo === 1
                ? "¿Desea desactivar a " + nombre + "?"
                : "¿Desea activar nuevamente a " + nombre + "?";

        const mensaje =
            activo === 1
                ? "Sus registros históricos se conservarán."
                : "";

        document.getElementById(
            "confirmTitulo"
        ).textContent = titulo;

        document.getElementById(
            "confirmMensaje"
        ).textContent = mensaje;

        document.getElementById(
            "modalConfirmar"
        ).style.display = "flex";

        return false;
    }


    function cerrarConfirmar()
    {
        document.getElementById(
            "modalConfirmar"
        ).style.display = "none";

        formularioPendiente = null;
    }


    function aceptarConfirmar()
    {
        if (formularioPendiente) {
            formularioPendiente.submit();
        }
    }

    function abrirModalAsistencia(id, deuda, fecha, motivo) {
        document.getElementById("editAsistenciaId").value = id;
        document.getElementById("editMinutosDeuda").value = deuda;
        document.getElementById("editMotivoDeuda").value = motivo || "";
        if (fecha) {
            document.getElementById("infoDetalleDia").textContent = "Fecha del registro: " + fecha;
        } else {
            document.getElementById("infoDetalleDia").textContent = "";
        }
        document.getElementById("modalAsistencia").style.display = "flex";
    }

    function cerrarModalAsistencia() {
        document.getElementById("modalAsistencia").style.display = "none";
    }

    function abrirModalDetalleEmpleado(empleadoId, nombreEmpleado, desdeH, hastaH) {
        document.getElementById("tituloDetalleEmpleado").textContent = "Desglose de Días - " + nombreEmpleado;
        const tbody = document.getElementById("tablaDiasEmpleadoBody");
        tbody.innerHTML = '<tr><td colspan="8" class="sin-resultados">Cargando registros...</td></tr>';
        document.getElementById("modalDetalleEmpleado").style.display = "flex";

        fetch('obtener_dias_empleado.php?empleado_id=' + empleadoId + '&desde=' + encodeURIComponent(desdeH) + '&hasta=' + encodeURIComponent(hastaH))
            .then(response => response.json())
            .then(data => {
                tbody.innerHTML = "";
                if (!data || data.length === 0) {
                    tbody.innerHTML = '<tr><td colspan="8" class="sin-resultados">No hay registros en el período seleccionado.</td></tr>';
                    return;
                }
                data.forEach(row => {
                    let tr = document.createElement("tr");
                    tr.innerHTML = `
                        <td>${row.fecha}</td>
                        <td>${row.hora_entrada ? row.hora_entrada : '—'}</td>
                        <td>${row.hora_salida ? row.hora_salida : '—'}</td>
                        <td>${row.minutos_retraso > 0 ? row.minutos_retraso + ' min' : '—'}</td>
                        <td>${row.minutos_extra > 0 ? row.minutos_extra + ' min' : '—'}</td>
                        <td>${row.minutos_deuda > 0 ? row.minutos_deuda + ' min' : '—'}</td>
                        <td>${row.justificacion ? row.justificacion : '—'}</td>
                        <td><button type="button" class="btn-editar" onclick="abrirModalAsistencia(${row.id}, ${row.minutos_deuda}, '${row.fecha}', ${JSON.stringify(row.justificacion || '')})">Editar</button></td>
                    `;
                    tbody.appendChild(tr);
                });
            })
            .catch(error => {
                tbody.innerHTML = '<tr><td colspan="8" class="sin-resultados">Error al cargar los datos.</td></tr>';
            });
    }

    function cerrarModalDetalleEmpleado() {
        document.getElementById("modalDetalleEmpleado").style.display = "none";
    }

    function abrirModalTodasJustificaciones() {
        document.getElementById("modalTodasJustificaciones").style.display = "flex";
    }

    function cerrarModalTodasJustificaciones() {
        document.getElementById("modalTodasJustificaciones").style.display = "none";
    }

    function verSoporte(btn) {
        const tipo = btn.getAttribute("data-tipo");
        const datos = btn.getAttribute("data-datos");
        const src = "data:" + tipo + ";base64," + datos;

        const imgEl = document.getElementById("imgSoporte");
        const pdfEl = document.getElementById("pdfSoporte");

        if (tipo === "application/pdf") {
            pdfEl.src = src;
            pdfEl.style.display = "block";
            imgEl.style.display = "none";
            imgEl.src = "";
        } else {
            imgEl.src = src;
            imgEl.style.display = "block";
            pdfEl.style.display = "none";
            pdfEl.src = "";
        }

        document.getElementById("modalSoporte").style.display = "flex";
    }

    function cerrarModalSoporte() {
        document.getElementById("modalSoporte").style.display = "none";
        document.getElementById("pdfSoporte").src = "";
        document.getElementById("imgSoporte").src = "";
    }


    document
        .querySelectorAll(".nav-item[href^=\"#\"]")
        .forEach(function(enlace)
        {
            enlace.addEventListener(
                "click",
                function(event)
                {
                    const destino =
                        this.getAttribute("href")
                            .replace("#", "");

                    mostrarSeccion(destino);
                }
            );
        });


    window.addEventListener(
        "click",
        function(event)
        {
            const modal =
                document.getElementById(
                    "modalEmpleado"
                );

            if (event.target === modal) {
                cerrarModal();
            }

            const modalConfirmar =
                document.getElementById(
                    "modalConfirmar"
                );

            if (event.target === modalConfirmar) {
                cerrarConfirmar();
            }

            const modalAsistencia =
                document.getElementById(
                    "modalAsistencia"
                );

            if (event.target === modalAsistencia) {
                cerrarModalAsistencia();
            }

            const modalDetalleEmpleado =
                document.getElementById(
                    "modalDetalleEmpleado"
                );

            if (event.target === modalDetalleEmpleado) {
                cerrarModalDetalleEmpleado();
            }

            const modalTodasJustificaciones =
                document.getElementById(
                    "modalTodasJustificaciones"
                );

            if (event.target === modalTodasJustificaciones) {
                cerrarModalTodasJustificaciones();
            }

            const modalSoporte =
                document.getElementById(
                    "modalSoporte"
                );

            if (event.target === modalSoporte) {
                cerrarModalSoporte();
            }
        }
    );

</script>

<script>
/* ==========================================================
   Diálogos con el estilo de la página (reemplazan confirm() y alert())
   - bfConfirmar({ icono, titulo, texto, si, no, peligro, onSi })
   - bfConfirmarForm(form, { ...mismas opciones }) -> se usa en onsubmit / onclick
   - bfAviso(texto, { icono, titulo })
   - alert("...") también sale con este diseño
   ========================================================== */
(function () {
    var caja = document.getElementById("bfDialogo");
    if (!caja) { return; }

    var $icono = document.getElementById("bfDlgIcono");
    var $titulo = document.getElementById("bfDlgTitulo");
    var $texto = document.getElementById("bfDlgTexto");
    var $si = document.getElementById("bfDlgSi");
    var $no = document.getElementById("bfDlgNo");
    var alAceptar = null;
    var focoPrevio = null;

    function cerrar() {
        caja.style.display = "none";
        alAceptar = null;
        if (focoPrevio && focoPrevio.focus) {
            try { focoPrevio.focus(); } catch (e) { /* sin foco previo */ }
        }
        focoPrevio = null;
    }

    function abrir(o, soloAviso) {
        focoPrevio = document.activeElement;
        $icono.textContent = o.icono || (soloAviso ? "⚠️" : "❔");
        $titulo.textContent = o.titulo || (soloAviso ? "Aviso" : "¿Confirmar?");
        $texto.textContent = o.texto || "";
        $texto.style.display = o.texto ? "" : "none";
        $si.textContent = o.si || (soloAviso ? "Entendido" : "Aceptar");
        $no.textContent = o.no || "Cancelar";
        $no.style.display = soloAviso ? "none" : "";
        $si.classList.toggle("peligro", !!o.peligro);
        caja.classList.toggle("bf-dlg--peligro", !!o.peligro);
        alAceptar = o.onSi || null;
        caja.style.display = "flex";
        $si.focus();
    }

    window.bfConfirmar = function (o) { abrir(o || {}, false); };

    window.bfConfirmarForm = function (form, o) {
        var opciones = {};
        var k;
        for (k in (o || {})) { if (Object.prototype.hasOwnProperty.call(o, k)) { opciones[k] = o[k]; } }
        opciones.onSi = function () { form.submit(); };
        abrir(opciones, false);
        return false; // frena el envío: se envía cuando la persona acepta
    };

    window.bfAviso = function (texto, o) {
        o = o || {};
        abrir({ icono: o.icono, titulo: o.titulo, texto: String(texto == null ? "" : texto), si: o.si }, true);
    };

    // Cualquier alert("...") de la página sale con este diseño
    window.alert = function (mensaje) { window.bfAviso(mensaje); };

    $no.addEventListener("click", cerrar);
    $si.addEventListener("click", function () {
        var f = alAceptar;
        cerrar();
        if (f) { f(); }
    });
    caja.addEventListener("click", function (e) { if (e.target === caja) { cerrar(); } });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape" && caja.style.display === "flex") { cerrar(); }
    });
})();
</script>

    <script src="js/bellaforma-ui.js"></script>
</body>

</html>
