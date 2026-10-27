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
require_once __DIR__ . "/admin_permisos.php";
require_once __DIR__ . "/cobros_lib.php";
require_once __DIR__ . "/contraentrega_lib.php";
if (adminEsPrincipal() && empty($_SESSION["admin_cols_ok"])) {
    adminAsegurarColumnas($conexion);
    $_SESSION["admin_cols_ok"] = 1;
}

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
    $GLOBALS["bf_mov_resultado"] = [(string) $mensaje, (string) $tipo];   // lo lee el registro de movimientos de subadministradores

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
    // Pedidos por cobrar (cob_cobrado / cob_reabrir)
    require __DIR__ . "/admin_acciones_cobros.php";
    // Pedidos contraentrega (coe_cobrado / coe_reabrir)
    require __DIR__ . "/admin_acciones_contraentrega.php";
    // Subadministradores (solo el administrador principal)
    require __DIR__ . "/admin_acciones_subadmins.php";

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

// Pedidos por cobrar: cuántos faltan y cuántos comprobantes nuevos hay (menú y campanita)
$cobNavPend = 0;
$cobNavNuevos = 0;
$cobNavLista = [];
if (adminPuede("por_cobrar")) {
    try {
        $cobNavPend = cobroContarPorCobrar($conexion);
        $cobNavNuevos = cobroContarNuevos($conexion);
        $cobNavLista = cobroListarNuevos($conexion);
    } catch (Throwable $e) {
        // si algo falla, el panel sigue funcionando sin el aviso
    }
}

$mensaje = $_SESSION["mensaje"] ?? "";
$tipoMensaje = $_SESSION["tipo_mensaje"] ?? "";

unset($_SESSION["mensaje"]);
unset($_SESSION["tipo_mensaje"]);

$fechaDesde = $_GET["desde"] ?? date("Y-m-d");
$fechaHasta = $_GET["hasta"] ?? $fechaDesde;
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
        .color-retraso, .retraso { color: #dc3545 !important; font-weight: bold; }
        .color-extra, .extra { color: #d39e00 !important; font-weight: bold; }
        .color-deuda { color: #007bff !important; font-weight: bold; }
        .campana-cobros { position: relative; display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 34px; padding: 0; color: var(--tinta-suave); background: var(--superficie); border: 1px solid var(--linea); border-radius: 999px; cursor: pointer; font-family: inherit; }
        .campana-cobros:hover { background: #F1F7FE; }
        .campana-cobros.con-nuevos { color: #1D6821; border-color: #9ED6A8; animation: campana-cobros-pulso 2s ease-in-out infinite; }
        .campana-n { position: absolute; top: -6px; right: -4px; min-width: 18px; padding: 1px 5px; border-radius: 999px; background: #C62839; color: #fff; font-size: 10.5px; font-weight: 800; line-height: 15px; text-align: center; }
        .campana-n[hidden] { display: none; }
        .campana-vacio { padding: 18px 14px; text-align: center; font-size: 13px; color: var(--tinta-suave); }
        .campana-wrap { position: relative; }
        .campana-menu { position: absolute; right: 0; top: calc(100% + 8px); z-index: 60; width: min(380px, 92vw); max-height: 70vh; overflow-y: auto; background: #fff; border: 1px solid var(--linea); border-radius: 14px; box-shadow: 0 24px 50px -18px rgba(7, 27, 64, .45); }
        .campana-menu[hidden] { display: none; }
        .campana-menu-cab { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 12px 14px; border-bottom: 1px solid var(--linea); font-size: 13px; color: var(--azul-900); }
        .campana-menu-cab button { border: none; background: none; color: var(--azul-600); font-weight: 700; font-size: 12.5px; cursor: pointer; padding: 4px 6px; }
        .campana-item { display: block; width: 100%; text-align: left; padding: 11px 14px; border: none; border-bottom: 1px solid #EEF2F9; background: #fff; cursor: pointer; font-family: inherit; }
        .campana-item { position: relative; padding-left: 28px; }
        .campana-item:hover { background: #F1FAF3; }
        .campana-item.no-leida { background: #F1FAF3; }
        .campana-item.no-leida::before { content: ""; position: absolute; left: 11px; top: 17px; width: 8px; height: 8px; border-radius: 50%; background: #27983A; }
        .campana-item.leida b { font-weight: 600; color: var(--tinta-suave); }
        .campana-item b { display: block; font-size: 13.5px; color: var(--azul-900); }
        .campana-item span { display: block; font-size: 12px; color: var(--tinta-suave); margin-top: 2px; }
        .campana-cobros .ico { width: 18px; height: 18px; }
        @keyframes campana-cobros-pulso { 0%, 100% { box-shadow: 0 6px 16px -8px rgba(29, 104, 33, .9), 0 0 0 0 rgba(51, 181, 64, .5); } 50% { box-shadow: 0 6px 16px -8px rgba(29, 104, 33, .9), 0 0 0 8px rgba(51, 181, 64, 0); } }
        .nav-badge-cobrar { margin-left: auto; min-width: 22px; padding: 1px 7px; border-radius: 999px; background: #C62839; color: #fff; font-size: 11px; font-weight: 800; text-align: center; }
        .nav-badge-cobrar[hidden] { display: none; }
        .toast-cobros { position: fixed; right: 20px; bottom: 20px; z-index: 10060; max-width: 340px; padding: 14px 18px; border-radius: 12px; background: #1D6821; color: #fff; font-weight: 600; font-size: 14px; box-shadow: 0 18px 40px -16px rgba(0, 0, 0, .5); cursor: pointer; }
    </style>

    <script>
    /* Sección inicial: se decide ANTES de que se pinte la página, para que no aparezca el dashboard un instante.
       Orden: #hash de la URL -> sección guardada al enviar un formulario -> primera sección permitida / dashboard. */
    (function () {
        var permitidas = <?= json_encode(adminSeccionesPermitidas()) ?>;
        var destino = (window.location.hash || "").replace("#", "");
        var guardado = null;

        try {
            var crudo = sessionStorage.getItem("bf_admin_volver");
            sessionStorage.removeItem("bf_admin_volver");
            if (crudo) { guardado = JSON.parse(crudo); }
        } catch (e) { guardado = null; }

        if (guardado && (!guardado.t || Date.now() - guardado.t > 20000)) { guardado = null; }
        if (destino === "" && guardado && guardado.s) { destino = guardado.s; }
        if (permitidas && permitidas.length > 0 && permitidas.indexOf(destino) === -1) { destino = permitidas[0]; }
        if (destino === "") { destino = "dashboard"; }
        destino = String(destino).replace(/[^A-Za-z0-9_\-]/g, "");

        window.bfSeccionInicial = destino;
        window.bfVolver = guardado;

        var estilo = document.createElement("style");
        estilo.id = "bfSeccionInicial";
        estilo.textContent = ".section{display:none!important}#" + destino + ".section{display:block!important}";
        document.head.appendChild(estilo);

        // Al salir de la página (enviar un formulario, eliminar, guardar...) se recuerda dónde estaba la persona
        window.addEventListener("beforeunload", function () {
            var activa = document.querySelector(".section.active");
            try {
                sessionStorage.setItem("bf_admin_volver", JSON.stringify({
                    s: activa ? activa.id : "",
                    y: window.pageYOffset || document.documentElement.scrollTop || 0,
                    t: Date.now()
                }));
            } catch (e) { /* sin almacenamiento: se ignora */ }
        });
    })();
    </script>

</head>

<body>

<svg width="0" height="0" style="position:absolute" aria-hidden="true" focusable="false"><defs><symbol id="i-dashboard" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="9" rx="1"/><rect x="14" y="3" width="7" height="5" rx="1"/><rect x="14" y="12" width="7" height="9" rx="1"/><rect x="3" y="16" width="7" height="5" rx="1"/></symbol><symbol id="i-asistencias" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/><path d="m9 14 2 2 4-4"/></symbol><symbol id="i-folder" viewBox="0 0 24 24"><path d="M4 20h16a2 2 0 0 0 2-2V8a2 2 0 0 0-2-2h-7.9a2 2 0 0 1-1.7-.9L9.6 3.9A2 2 0 0 0 7.9 3H4a2 2 0 0 0-2 2v13a2 2 0 0 0 2 2Z"/></symbol><symbol id="i-users" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></symbol><symbol id="i-users-check" viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="m16 11 2 2 4-4"/></symbol><symbol id="i-user" viewBox="0 0 24 24"><path d="M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></symbol><symbol id="i-calendar" viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="18" rx="2"/><path d="M16 2v4M8 2v4M3 10h18"/></symbol><symbol id="i-sparkles" viewBox="0 0 24 24"><path d="M12 3l1.9 5.1L19 10l-5.1 1.9L12 17l-1.9-5.1L5 10l5.1-1.9z"/><path d="M19 15v4M17 17h4"/></symbol><symbol id="i-qr" viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7" rx="1"/><rect x="14" y="3" width="7" height="7" rx="1"/><rect x="3" y="14" width="7" height="7" rx="1"/><path d="M14 14h3v3h-3zM21 14v.01M21 21v.01M17 21h-3v-3"/></symbol><symbol id="i-wallet" viewBox="0 0 24 24"><path d="M19 7V4a1 1 0 0 0-1-1H5a2 2 0 0 0 0 4h15a1 1 0 0 1 1 1v4h-3a2 2 0 0 0 0 4h3a1 1 0 0 0 1-1v-2a1 1 0 0 0-1-1"/><path d="M3 5v14a2 2 0 0 0 2 2h15a1 1 0 0 0 1-1v-4"/></symbol><symbol id="i-file-text" viewBox="0 0 24 24"><path d="M15 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7z"/><path d="M14 2v4a2 2 0 0 0 2 2h4M10 9H8M16 13H8M16 17H8"/></symbol><symbol id="i-package" viewBox="0 0 24 24"><path d="m7.5 4.27 9 5.15"/><path d="M21 8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16Z"/><path d="m3.3 7 8.7 5 8.7-5M12 22V12"/></symbol><symbol id="i-briefcase" viewBox="0 0 24 24"><rect x="2" y="7" width="20" height="14" rx="2"/><path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/></symbol><symbol id="i-receipt" viewBox="0 0 24 24"><path d="M4 2v20l2-1 2 1 2-1 2 1 2-1 2 1 2-1 2 1V2l-2 1-2-1-2 1-2-1-2 1-2-1-2 1Z"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8M12 17.5v-11"/></symbol><symbol id="i-truck" viewBox="0 0 24 24"><path d="M14 18V6a2 2 0 0 0-2-2H4a2 2 0 0 0-2 2v11a1 1 0 0 0 1 1h2"/><path d="M15 18H9"/><path d="M19 18h2a1 1 0 0 0 1-1v-3.65a1 1 0 0 0-.22-.624l-3.48-4.35A1 1 0 0 0 17.52 8H14"/><circle cx="17" cy="18" r="2"/><circle cx="7" cy="18" r="2"/></symbol><symbol id="i-tag" viewBox="0 0 24 24"><path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".6" fill="currentColor"/></symbol><symbol id="i-hourglass" viewBox="0 0 24 24"><path d="M5 22h14M5 2h14"/><path d="M17 22v-4.172a2 2 0 0 0-.586-1.414L12 12l-4.414 4.414A2 2 0 0 0 7 17.828V22"/><path d="M7 2v4.172a2 2 0 0 0 .586 1.414L12 12l4.414-4.414A2 2 0 0 0 17 6.172V2"/></symbol><symbol id="i-clock" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M12 6v6l4 2"/></symbol><symbol id="i-chart" viewBox="0 0 24 24"><path d="M3 3v16a2 2 0 0 0 2 2h16"/><path d="M18 17V9M13 17V5M8 17v-3"/></symbol><symbol id="i-check-circle" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></symbol><symbol id="i-download" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m7 10 5 5 5-5M12 15V3"/></symbol><symbol id="i-upload" viewBox="0 0 24 24"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><path d="m17 8-5-5-5 5M12 3v12"/></symbol><symbol id="i-plus" viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></symbol><symbol id="i-paperclip" viewBox="0 0 24 24"><path d="m21.44 11.05-9.19 9.19a6 6 0 0 1-8.49-8.49l8.57-8.57A4 4 0 1 1 18 8.84l-8.59 8.57a2 2 0 0 1-2.83-2.83l8.49-8.48"/></symbol><symbol id="i-camera" viewBox="0 0 24 24"><path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3z"/><circle cx="12" cy="13" r="3"/></symbol><symbol id="i-clipboard" viewBox="0 0 24 24"><rect x="8" y="2" width="8" height="4" rx="1"/><path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"/></symbol><symbol id="i-list" viewBox="0 0 24 24"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></symbol><symbol id="i-logout" viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></symbol><symbol id="i-alert" viewBox="0 0 24 24"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4M12 17h.01"/></symbol><symbol id="i-help" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M9.09 9a3 3 0 0 1 5.83 1c0 2-3 3-3 3M12 17h.01"/></symbol></defs></svg>


<div class="container-admin">

    <aside class="sidebar">

        <div class="sidebar-header">

            <img src="img/logo-blanco.png" alt="Grupo Bella Forma S.A.S." class="logo-marca-sidebar">

            <p>
                <?= adminEsPrincipal() ? "Administrador" : "Subadministrador" ?>
            </p>

        </div>


        <nav class="sidebar-nav">

            <?php if (adminPuede("dashboard")): ?>
            <a
                href="#dashboard"
                class="nav-item<?= adminPuede("dashboard") ? " active" : "" ?>"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-dashboard"/></svg> Dashboard
            </a>
            <?php endif; ?>

            <?php if (adminPuede("asistencias")): ?>
            <a
                href="#asistencias"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-asistencias"/></svg> Asistencias
            </a>
            <?php endif; ?>

            <?php if (adminPuede("historial")): ?>
            <a
                href="#historial"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-folder"/></svg> Historial Completo
            </a>
            <?php endif; ?>

            <?php if (adminPuede("empleados")): ?>
            <a
                href="#empleados"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-users"/></svg> Empleados
            </a>
            <?php endif; ?>

            <?php if (adminPuede("festivos")): ?>
            <a
                href="#festivos"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-sparkles"/></svg> Festivos
            </a>
            <?php endif; ?>

            <?php if (adminPuede("codigo_qr")): ?>
            <a
                href="codigo_qr.php"
                class="nav-item"
                target="_blank"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-qr"/></svg> Código QR
            </a>
            <?php endif; ?>

            <?php if (adminPuede("liquidacion")): ?>
            <a
                href="liquidacion.php"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-wallet"/></svg> Liquidación
            </a>
            <?php endif; ?>

            <?php if (adminPuede("permisos")): ?>
            <a
                href="#permisos"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-file-text"/></svg> Permisos
            </a>
            <?php endif; ?>

            <?php if (adminPuede("materiales")): ?>
            <a
                href="#materiales"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-package"/></svg> Materiales
            </a>
            <?php endif; ?>

            <?php if (adminPuede("vendedores")): ?>
            <a
                href="#vendedores"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-briefcase"/></svg> Ejecutivos de negocios
            </a>
            <?php endif; ?>

            <?php if (adminPuede("pedidos")): ?>
            <a
                href="#pedidos"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-receipt"/></svg> Pedidos de ejecutivos
            </a>
            <?php endif; ?>

            <?php if (adminPuede("por_cobrar")): ?>
            <a
                href="#por_cobrar"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-wallet"/></svg> Pedidos por cobrar
                <span id="navBadgeCobrar" class="nav-badge-cobrar" <?= $cobNavPend > 0 ? "" : "hidden" ?>><?= (int) $cobNavPend ?></span>
            </a>
            <?php endif; ?>

            <?php if (adminPuede("contraentrega")): ?>
            <?php $coeNavPend = contraentregaContarPorCobrar($conexion); ?>
            <a
                href="#contraentrega"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-truck"/></svg> Pedidos contraentrega
                <span class="nav-badge-cobrar" <?= $coeNavPend > 0 ? "" : "hidden" ?>><?= (int) $coeNavPend ?></span>
            </a>
            <?php endif; ?>

            <?php if (adminPuede("despachos")): ?>
            <a
                href="#despachos"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-truck"/></svg> Control de despachos
            </a>
            <?php endif; ?>

            <?php if (adminPuede("pedidos_despachar")): ?>
            <a
                href="#pedidos_despachar"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-clipboard"/></svg> Pedidos para despachar
            </a>
            <?php endif; ?>

            <?php if (adminPuede("productos")): ?>
            <a
                href="#productos_mayoristas"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-tag"/></svg> Productos
            </a>
            <?php endif; ?>

            <?php if (adminPuede("clientes")): ?>
            <a
                href="#clientes"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-users"/></svg> Clientes
            </a>
            <?php endif; ?>

            <?php if (adminEsPrincipal()): ?>
            <a
                href="#subadmins"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-users-check"/></svg> Subadministradores
            </a>
            <?php endif; ?>

            <?php if (adminEsPrincipal()): ?>
            <a
                href="#movimientos"
                class="nav-item"
            >
                <svg class="ico" aria-hidden="true"><use href="#i-clipboard"/></svg> Movimientos subadministradores
            </a>
            <?php endif; ?>

        </nav>

        <script>
        (function () {
            var id = window.bfSeccionInicial;
            if (!id) { return; }
            document.querySelectorAll(".sidebar-nav .nav-item").forEach(function (a) {
                a.classList.toggle("active", a.getAttribute("href") === "#" + id);
            });
        })();
        </script>


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
                <svg class="ico" aria-hidden="true"><use href="#i-logout"/></svg> Cerrar Sesión
            </a>

        </div>

    </aside>


    <main class="main-content">

        <header class="top-header">

            <h1>
                Bienvenido al Panel Administrativo
            </h1>

            <div class="header-info">

                <?php if (adminPuede("por_cobrar")): ?>
                <div class="campana-wrap" id="campanaWrap">
                    <button type="button" id="campanaCobros" class="campana-cobros<?= $cobNavNuevos > 0 ? " con-nuevos" : "" ?>" aria-haspopup="true" aria-expanded="false" title="Comprobantes de pago de pedidos a crédito">
                        <svg class="ico" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.7 21a2 2 0 0 1-3.4 0"/></svg>
                        <span id="campanaCobrosTxt" class="campana-n" <?= $cobNavNuevos > 0 ? "" : "hidden" ?>><?= (int) $cobNavNuevos ?></span>
                    </button>
                    <div class="campana-menu" id="campanaMenu" hidden>
                        <div class="campana-menu-cab">
                            <strong>Comprobantes de pago</strong>
                            <button type="button" id="campanaTodas" <?= $cobNavNuevos > 0 ? "" : "hidden" ?>>Marcar todas como leídas</button>
                        </div>
                        <div id="campanaLista"></div>
                    </div>
                </div>
                <?php endif; ?>

                <span>
                    <svg class="ico" aria-hidden="true"><use href="#i-user"/></svg>
                    <?= escapar(
                        $_SESSION["admin_usuario"]
                        ?? "Administrador"
                    ) ?>
                </span>

                <span>
                    <svg class="ico" aria-hidden="true"><use href="#i-calendar"/></svg>
                    <span id="relojAdmin" data-ts="<?= time() * 1000 ?>" data-off="<?= (int) date("Z") * 1000 ?>"><?= date("d/m/Y H:i:s") ?></span>
                </span>

            </div>

        </header>


        <div class="content">


            <?php if ($mensaje !== ""): ?>

                <div class="mensaje <?= escapar($tipoMensaje) ?>">

                    <?= escapar($mensaje) ?>

                </div>

            <?php endif; ?>


            <?php if (adminPuede("dashboard")): ?>
            <section
                id="dashboard"
                class="section active"
            >

                <h2>
                    <svg class="ico" aria-hidden="true"><use href="#i-dashboard"/></svg> Dashboard
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
                        <div class="dash-icono"><svg class="ico" aria-hidden="true"><use href="#i-users"/></svg></div>
                        <div class="dash-dato">
                            <span class="dash-etiqueta">Total Empleados</span>
                            <span class="dash-numero"><?= $totalEmpleados ?></span>
                            <span class="dash-pie">Personal en el sistema</span>
                        </div>
                    </div>

                    <div class="dash-stat dash-stat--verde">
                        <div class="dash-icono"><svg class="ico" aria-hidden="true"><use href="#i-users-check"/></svg></div>
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
                        <div class="dash-icono"><svg class="ico" aria-hidden="true"><use href="#i-hourglass"/></svg></div>
                        <div class="dash-dato">
                            <span class="dash-etiqueta"><?= $dashPendiente ? "Pendientes Hoy" : "No registraron" ?></span>
                            <span class="dash-numero"><?= $dashTotalFaltan ?></span>
                            <span class="dash-pie"><?= $dashPendiente ? "Aún sin registrar" : "Sin registro en el día" ?></span>
                        </div>
                    </div>

                    <div class="dash-stat dash-stat--rojo">
                        <div class="dash-icono"><svg class="ico" aria-hidden="true"><use href="#i-clock"/></svg></div>
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
                                <span class="dash-vacio-icono"><svg class="ico" aria-hidden="true"><use href="#i-check-circle"/></svg></span>
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
                                <span class="dash-vacio-icono"><svg class="ico" aria-hidden="true"><use href="#i-check-circle"/></svg></span>
                                Nadie llegó tarde
                            </div>
                        <?php endif; ?>
                    </div>

                </div>

            </section>
            <?php endif; ?>


            <?php if (adminPuede("asistencias")): ?>
            <section
                id="asistencias"
                class="section"
            >

                <h2>
                    <svg class="ico" aria-hidden="true"><use href="#i-asistencias"/></svg> Registro de Asistencias
                </h2>


                <form
                    method="GET"
                    action="admin.php#asistencias"
                    class="filtros"
                >
                    <input type="hidden" name="seccion" value="asistencias">

                    <div>

                        <label for="desde">
                            Fecha
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
            <?php endif; ?>


            <?php if (adminPuede("historial")): ?>
           <section id="historial" class="section">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 15px;">
                    <div>
                        <h2><svg class="ico" aria-hidden="true"><use href="#i-folder"/></svg> Acumulado General por Empleado</h2>
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
                        <h3 style="margin:0;"><svg class="ico" aria-hidden="true"><use href="#i-chart"/></svg> Ranking de Empleados</h3>
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
            <?php endif; ?>


            <?php if (adminPuede("empleados")): ?>
            <section
                id="empleados"
                class="section"
            >

                <div class="cabecera-seccion">

                    <div>

                        <h2>
                            <svg class="ico" aria-hidden="true"><use href="#i-users"/></svg> Gestionar Empleados
                        </h2>

                        <p>
                            Administre los empleados activos e inactivos.
                        </p>

                    </div>


                    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;">

                        <a
                            href="empleados_excel.php"
                            class="btn-nuevo"
                            style="text-decoration:none;display:inline-block;"
                        >
                            Descargar Excel (activos)
                        </a>

                        <button
                            type="button"
                            onclick="mostrarFormularioEmpleado()"
                            class="btn-nuevo"
                        >
                            + Nuevo Empleado
                        </button>

                    </div>

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
            <?php endif; ?>




            <?php if (adminPuede("festivos")): ?>
            <section
                id="festivos"
                class="section"
            >

                <h2>
                    <svg class="ico" aria-hidden="true"><use href="#i-sparkles"/></svg> Gestionar Festivos
                </h2>

                <p>
                    Administre los días festivos de la empresa.
                </p>


                <div class="festivos-botones">

                    <a
                        href="gestionar_festivos.php"
                        class="boton-festivo"
                    >
                        <svg class="ico" aria-hidden="true"><use href="#i-list"/></svg> Ver y Eliminar
                    </a>


                    <a
                        href="crear_festivo.php"
                        class="boton-festivo boton-festivo-secundario"
                    >
                        <svg class="ico" aria-hidden="true"><use href="#i-plus"/></svg> Crear Festivo
                    </a>

                </div>

            </section>
            <?php endif; ?>

            <!-- SECCIÓN PERMISOS INTEGRADA -->
            <?php if (adminPuede("permisos")): ?>
            <section
                id="permisos"
                class="section"
            >
                <h2><svg class="ico" aria-hidden="true"><use href="#i-file-text"/></svg> Solicitudes de Permisos</h2>
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
                                                <svg class="ico" aria-hidden="true"><use href="#i-paperclip"/></svg> Ver
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
            <?php endif; ?>

            <?php
            if (adminPuede("vendedores")) { include __DIR__ . "/admin_seccion_vendedores.php"; }
            if (adminPuede("pedidos")) { include __DIR__ . "/admin_seccion_pedidos.php"; }
            if (adminPuede("por_cobrar")) { include __DIR__ . "/admin_seccion_cobrar.php"; }
            if (adminPuede("contraentrega")) { include __DIR__ . "/admin_seccion_contraentrega.php"; }
            if (adminPuede("despachos")) { include __DIR__ . "/admin_seccion_despachos.php"; }
            if (adminPuede("pedidos_despachar")) { include __DIR__ . "/admin_seccion_pedidos_despachar.php"; }
            if (adminPuede("productos")) { include __DIR__ . "/admin_seccion_productos.php"; }
            if (adminPuede("clientes")) { include __DIR__ . "/admin_seccion_clientes.php"; }
            if (adminEsPrincipal()) { include __DIR__ . "/admin_seccion_subadmins.php"; }
            if (adminEsPrincipal()) { include __DIR__ . "/admin_seccion_movimientos.php"; }
            ?>

            <!-- SECCIÓN MATERIALES INTEGRADA -->
            <?php if (adminPuede("materiales")): ?>
            <section
                id="materiales"
                class="section"
            >
                <h2><svg class="ico" aria-hidden="true"><use href="#i-package"/></svg> Solicitudes de Materiales</h2>
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
            <?php endif; ?>

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

        <div class="bf-dlg-icono" id="bfDlgIcono"><svg class="ico" aria-hidden="true"><use href="#i-help"/></svg></div>

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


    function marcarNav(id)
    {
        document
            .querySelectorAll(".nav-item")
            .forEach(function(item)
            {
                item.classList.toggle(
                    "active",
                    item.getAttribute("href") === "#" + id
                );
            });
    }


    (function inicializarSeccionActiva()
    {
        // La sección ya la calculó el script del <head> (hash, sección guardada o permitida)
        const id =
            window.bfSeccionInicial ||
            window.location.hash.replace("#", "");

        const objetivo = id ? document.getElementById(id) : null;

        if (objetivo && objetivo.classList.contains("section")) {

            mostrarSeccion(id);
            marcarNav(id);

            if (window.location.hash.replace("#", "") !== id) {
                history.replaceState(null, "", "#" + id);
            }
        }

        // Ya están puestas las clases reales: se quita la regla temporal
        const temporal = document.getElementById("bfSeccionInicial");

        if (temporal) {
            temporal.remove();
        }

        // Volver al mismo punto de la pantalla donde estaba (Productos lo maneja por su cuenta)
        const volver = window.bfVolver;

        if (
            volver &&
            volver.s === id &&
            volver.y > 0 &&
            id !== "productos_mayoristas"
        ) {
            window.scrollTo(0, volver.y);

            window.addEventListener("load", function()
            {
                setTimeout(function()
                {
                    window.scrollTo(0, volver.y);
                }, 0);
            });
        }
    })();


    // Botón «atrás» / «adelante» del navegador entre secciones
    window.addEventListener("hashchange", function()
    {
        const id = window.location.hash.replace("#", "");
        const destino = id ? document.getElementById(id) : null;

        if (destino && destino.classList.contains("section")) {
            mostrarSeccion(id);
            marcarNav(id);
        }
    });


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
        $icono.innerHTML = '<svg class="ico" aria-hidden="true"><use href="#i-' + (soloAviso ? "alert" : (o.peligro ? "alert" : "help")) + '"/></svg>';
        caja.classList.toggle("bf-dlg--peligro", !!(o.peligro || soloAviso));
        $titulo.textContent = o.titulo || (soloAviso ? "Aviso" : "¿Confirmar?");
        $texto.textContent = o.texto || "";
        $texto.style.display = o.texto ? "" : "none";
        $si.textContent = o.si || (soloAviso ? "Entendido" : "Aceptar");
        $no.textContent = o.no || "Cancelar";
        $no.style.display = soloAviso ? "none" : "";
        $si.classList.toggle("peligro", !!o.peligro);
        caja.classList.toggle("bf-dlg--peligro", !!(o.peligro || soloAviso));
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
<script>
(function () {
    var el = document.getElementById("relojAdmin");
    if (!el) return;
    var desfase = parseInt(el.dataset.ts, 10) - Date.now();
    var off = parseInt(el.dataset.off, 10) || 0;
    function dos(n) { return (n < 10 ? "0" : "") + n; }
    function pintar() {
        var d = new Date(Date.now() + desfase + off);
        el.textContent = dos(d.getUTCDate()) + "/" + dos(d.getUTCMonth() + 1) + "/" + d.getUTCFullYear() + " " +
            dos(d.getUTCHours()) + ":" + dos(d.getUTCMinutes()) + ":" + dos(d.getUTCSeconds());
    }
    pintar();
    setInterval(pintar, 1000);
})();
</script>
<?php if (adminPuede("por_cobrar")): ?>
<script>
/* Campanita de «Pedidos por cobrar»: lista los comprobantes que subieron los ejecutivos. Al abrir uno queda leído. */
(function () {
    var campana = document.getElementById("campanaCobros");
    var txt = document.getElementById("campanaCobrosTxt");
    var menu = document.getElementById("campanaMenu");
    var listaEl = document.getElementById("campanaLista");
    var badge = document.getElementById("navBadgeCobrar");
    var lista = <?= json_encode($cobNavLista, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
    var nuevosIni = <?= (int) $cobNavNuevos ?>;
    var ultimo = nuevosIni;
    var todasBtn = document.getElementById("campanaTodas");

    function pintarLista() {
        listaEl.innerHTML = "";
        if (!lista.length) {
            var vacio = document.createElement("div");
            vacio.className = "campana-vacio";
            vacio.textContent = "No hay comprobantes por revisar.";
            listaEl.appendChild(vacio);
        }
        lista.forEach(function (it) {
            var b = document.createElement("button");
            b.type = "button";
            b.className = "campana-item " + (it.visto ? "leida" : "no-leida");
            var t = document.createElement("b");
            t.textContent = "Pedido #" + it.numero + " · " + (it.cliente || "Sin cliente");
            var d = document.createElement("span");
            d.textContent = it.vendedor + " · " + it.total + " · " + it.subido + (it.visto ? " · leído" : "");
            b.appendChild(t); b.appendChild(d);
            b.addEventListener("click", function () { abrirItem(it); });
            listaEl.appendChild(b);
        });
    }

    function pintarCampana(n, nuevaLista) {
        if (nuevaLista) { lista = nuevaLista; }
        n = parseInt(n, 10) || 0;
        pintarLista();
        // La campana siempre se ve; el número rojo cuenta solo los que faltan por leer
        txt.textContent = n;
        txt.hidden = n < 1;
        campana.classList.toggle("con-nuevos", n > 0);
        if (todasBtn) { todasBtn.hidden = n < 1; }
        ultimo = n;
    }
    window.bfActualizarCampanaCobros = pintarCampana;

    function irASeccion() {
        if (typeof mostrarSeccion === "function") { mostrarSeccion("por_cobrar"); }
        document.querySelectorAll(".nav-item").forEach(function (i) { i.classList.remove("active"); });
        var nav = document.querySelector('.nav-item[href="#por_cobrar"]');
        if (nav) { nav.classList.add("active"); }
        history.replaceState(null, "", "#por_cobrar");
        window.scrollTo(0, 0);
    }

    // Elegir cuál ver: abre la sección y el comprobante; el servidor lo marca como leído
    function abrirItem(it) {
        menu.hidden = true;
        campana.setAttribute("aria-expanded", "false");
        irASeccion();
        if (window.cbAbrirVisor) { window.cbAbrirVisor(it.id, it.mime, "Pedido #" + it.numero + " · " + it.cliente); }
    }

    campana.addEventListener("click", function (e) {
        e.stopPropagation();
        menu.hidden = !menu.hidden;
        campana.setAttribute("aria-expanded", menu.hidden ? "false" : "true");
    });
    menu.addEventListener("click", function (e) { e.stopPropagation(); });
    document.addEventListener("click", function () { menu.hidden = true; campana.setAttribute("aria-expanded", "false"); });

    document.getElementById("campanaTodas").addEventListener("click", function () {
        var fd = new FormData();
        fd.append("op", "visto");
        fd.append("todos", "1");
        fetch("cobro_comprobante.php", { method: "POST", body: fd, credentials: "same-origin", headers: { "X-Requested-With": "fetch" } })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.ok) {
                    pintarCampana(j.nuevos, j.lista);
                    document.querySelectorAll('[id^="cb-nuevo-"]').forEach(function (m) { m.remove(); });
                }
            }).catch(function () {});
    });

    function aviso(n) {
        var t = document.createElement("div");
        t.className = "toast-cobros";
        t.textContent = n === 1 ? "Un ejecutivo subió un comprobante de pago." : "Hay " + n + " comprobantes de pago sin leer.";
        t.addEventListener("click", function () { t.remove(); menu.hidden = false; campana.setAttribute("aria-expanded", "true"); window.scrollTo(0, 0); });
        document.body.appendChild(t);
        setTimeout(function () { t.remove(); }, 8000);
    }

    function revisar() {
        if (document.hidden) { return; }
        fetch("cobro_comprobante.php?op=estado&t=" + Date.now(), { credentials: "same-origin", headers: { "X-Requested-With": "fetch" } })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.ok) { return; }
                var hayNuevo = j.nuevos > ultimo;
                pintarCampana(j.nuevos, j.lista);
                if (hayNuevo) { aviso(j.nuevos); }
                if (badge) { badge.textContent = j.por_cobrar; badge.hidden = j.por_cobrar < 1; }
            }).catch(function () {});
    }
    pintarLista();
    setInterval(revisar, 45000);
    document.addEventListener("visibilitychange", function () { if (!document.hidden) { revisar(); } });
})();
</script>
<?php endif; ?>
</body>

</html>
