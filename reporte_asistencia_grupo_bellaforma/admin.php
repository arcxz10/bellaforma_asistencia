<?php

error_reporting(E_ALL);
ini_set("display_errors", 1);

session_start();

if (!isset($_SESSION["admin_id"])) {
    header("Location: login.html");
    exit;
}

require_once "conexion.php";

date_default_timezone_set("America/Bogota");

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

    $accion = $_POST["accion"] ?? "";

    if ($accion === "agregar_empleado") {

        $nombre = trim($_POST["nombre"] ?? "");
        $identificacion = trim($_POST["identificacion"] ?? "");
        $cargo = trim($_POST["cargo"] ?? "");

        $cargosPermitidos = [
            "Producción",
            "Ventas",
            "Administración"
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
            "Administración"
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
            "empleados"
        );
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
            a.hora_salida,
            a.minutos_extra
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
        id,
        nombre,
        identificacion,
        cargo,
        hora_entrada,
        hora_salida,
        activo
    FROM empleados
    ORDER BY
        activo DESC,
        nombre ASC
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
        Panel Administrativo - Bellaforma
    </title>

    <link
        rel="stylesheet"
        href="css/admin.css"
    >

</head>

<body>

<div class="container-admin">

    <aside class="sidebar">

        <div class="sidebar-header">

            <h2>
                🏢 BELLAFORMA
            </h2>

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

                <div class="cards-dashboard">

                    <div class="card-stat">

                        <h3>
                            Total Empleados
                        </h3>

                        <p>
                            <?= $totalEmpleados ?>
                        </p>

                    </div>


                    <div class="card-stat">

                        <h3>
                            Registraron Hoy
                        </h3>

                        <p>
                            <?= $registraronDia ?>
                        </p>

                    </div>


                    <div class="card-stat">

                        <h3>

                            <?= (
                                $esHoy &&
                                !$diaCerrado
                            )
                                ? "Pendientes Hoy"
                                : "No registraron"
                            ?>

                        </h3>

                        <p>

                            <?= (
                                $esHoy &&
                                !$diaCerrado
                            )
                                ? $pendientesDia
                                : $noRegistraronDia
                            ?>

                        </p>

                    </div>


                    <div class="card-stat">

                        <h3>
                            Retardos Hoy
                        </h3>

                        <p>
                            <?= $tardanzasDia ?>
                        </p>

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

                                    <th>
                                        Fecha
                                    </th>

                                    <th>
                                        Empleado
                                    </th>

                                    <th>
                                        Cargo
                                    </th>

                                    <th>
                                        Entrada
                                    </th>

                                    <th>
                                        Estado
                                    </th>

                                    <th>
                                        Retraso
                                    </th>

                                    <th>
                                        Salida
                                    </th>

                                    <th>
                                        Extra
                                    </th>

                                </tr>

                            </thead>

                            <tbody>

                            <?php if (
                                !$historial ||
                                $historial->num_rows === 0
                            ): ?>

                                <tr>

                                    <td
                                        colspan="8"
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

                                    </tr>

                                <?php endwhile; ?>

                            <?php endif; ?>

                            </tbody>

                        </table>

                    </div>

                <?php endif; ?>

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
                                    colspan="6"
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

        </div>

    </main>

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


    // Al cargar la página, si la URL trae un hash (#empleados, #festivos, etc.)
    // se muestra esa sección en vez de quedarse siempre en el Dashboard.
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
                "Lunes a viernes: 7:30 AM - 5:05 PM. Sábado y domingo: no trabaja.";

        } else if (
            cargo === "Ventas" ||
            cargo === "Administración"
        ) {

            horario.textContent =
                "Lunes a viernes: 8:30 AM - 5:15 PM. Sábado: 8:30 AM - 12:30 PM. Domingo: no trabaja.";

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
        }
    );

</script>

</body>

</html>