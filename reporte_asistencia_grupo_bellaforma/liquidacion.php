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

$mensaje = "";
$tipoMensaje = "exito";

// --- PROCESAR LIQUIDACIÓN (marcar como pagado) ---
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["accion"]) && $_POST["accion"] === "liquidar") {

    $empleadoId = (int) ($_POST["empleado_id"] ?? 0);
    $desdeForm = $_POST["desde"] ?? "";
    $hastaForm = $_POST["hasta"] ?? "";

    if ($empleadoId > 0) {

        $sqlLiquidar = "
            UPDATE asistencias
            SET liquidado = 1,
                fecha_liquidacion = NOW()
            WHERE empleado_id = ?
              AND liquidado = 0
        ";

        $paramsL = [$empleadoId];
        $tiposL = "i";

        if (!empty($desdeForm) && !empty($hastaForm)) {
            $sqlLiquidar .= " AND fecha BETWEEN ? AND ?";
            $paramsL[] = $desdeForm;
            $paramsL[] = $hastaForm;
            $tiposL .= "ss";
        }

        $stmtL = $conexion->prepare($sqlLiquidar);

        if ($stmtL) {
            $stmtL->bind_param($tiposL, ...$paramsL);
            $stmtL->execute();
            $stmtL->close();

            $mensaje = "Se liquidó correctamente el periodo de ese empleado. Sus contadores de retraso, extra y deuda vuelven a cero para el próximo periodo (sin borrar nada del Historial Completo).";
        } else {
            $mensaje = "No se pudo liquidar el periodo.";
            $tipoMensaje = "error";
        }
    }

    // Guardamos el mensaje y redirigimos para evitar reenvío del formulario al recargar
    $_SESSION["mensaje_liquidacion"] = $mensaje;
    $_SESSION["tipo_mensaje_liquidacion"] = $tipoMensaje;

    $redirParams = [];
    if (!empty($_POST["desde"])) {
        $redirParams[] = "desde=" . urlencode($_POST["desde"]);
    }
    if (!empty($_POST["hasta"])) {
        $redirParams[] = "hasta=" . urlencode($_POST["hasta"]);
    }

    $queryString = !empty($redirParams) ? ("?" . implode("&", $redirParams)) : "";

    header("Location: liquidacion.php" . $queryString);
    exit;
}

if (isset($_SESSION["mensaje_liquidacion"])) {
    $mensaje = $_SESSION["mensaje_liquidacion"];
    $tipoMensaje = $_SESSION["tipo_mensaje_liquidacion"] ?? "exito";
    unset($_SESSION["mensaje_liquidacion"], $_SESSION["tipo_mensaje_liquidacion"]);
}

// --- CONSULTAR PENDIENTES POR LIQUIDAR ---
$desde = $_GET["desde"] ?? "";
$hasta = $_GET["hasta"] ?? "";
$buscar = trim($_GET["buscar"] ?? "");

$sql = "
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
    INNER JOIN asistencias a ON a.empleado_id = e.id
";

$params = [];
$tipos = "";
$where = ["e.activo = 1", "a.liquidado = 0"];

if (!empty($desde) && !empty($hasta)) {
    $where[] = "a.fecha BETWEEN ? AND ?";
    $params[] = $desde;
    $params[] = $hasta;
    $tipos .= "ss";
}

if (!empty($buscar)) {
    $where[] = "(e.nombre LIKE ? OR e.identificacion LIKE ?)";
    $like = "%" . $buscar . "%";
    $params[] = $like;
    $params[] = $like;
    $tipos .= "ss";
}

$sql .= " WHERE " . implode(" AND ", $where);
$sql .= " GROUP BY e.id, e.nombre, e.identificacion, e.cargo ORDER BY e.nombre ASC";

$stmt = $conexion->prepare($sql);

if ($stmt) {
    if (!empty($params)) {
        $stmt->bind_param($tipos, ...$params);
    }
    $stmt->execute();
    $resultado = $stmt->get_result();
} else {
    $resultado = null;
}

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
        Liquidación de Nómina - Grupo Bella Forma S.A.S.
    </title>

    <link rel="icon" type="image/x-icon" href="img/favicon.ico">
    <link rel="icon" type="image/png" sizes="32x32" href="img/favicon-32x32.png">
    <link rel="apple-touch-icon" href="img/apple-touch-icon.png">

    <link rel="stylesheet" href="css/admin.css">

    <style>
        .color-retraso {
            color: #dc3545 !important;
            font-weight: bold;
        }

        .color-extra {
            color: #d39e00 !important;
            font-weight: bold;
        }

        .color-deuda {
            color: #007bff !important;
            font-weight: bold;
        }

        .nota-liquidacion {
            background: #F5FAFF;
            border-left: 4px solid #1565C0;
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-size: 14px;
            color: #0D47A1;
            line-height: 1.5;
        }

        .mensaje-liq {
            padding: 12px 16px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 500;
        }

        .mensaje-liq.exito {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }

        .mensaje-liq.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }

        .btn-liquidar {
            padding: 8px 14px;
            background: #1565C0;
            color: white;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            font-weight: bold;
        }

        .btn-liquidar:hover {
            background: #1565C0;
        }
    </style>

</head>

<body>

    <div class="container-admin">

        <div class="main-content" style="margin: 0 auto; max-width: 1250px; width: 100%;">

            <div class="top-header">
                <h1>💰 Liquidación de Nómina</h1>
                <a href="admin.php" class="btn-back" style="text-decoration:none; color:#0D47A1; font-size:14px;">← Volver al panel</a>
            </div>

            <div class="content">

                <div class="nota-liquidacion">
                    ℹ️ Aquí solo se cuentan los minutos <strong>pendientes por liquidar</strong> (los que aún no has pagado/descontado). El tiempo extra ya compensa primero el retraso y luego la deuda pendiente — por ejemplo, 30 min de retraso con 45 min de extra quedan mostrados como 15 min de extra y 0 de retraso. Si filtras por fechas (ej. del 1 al 15), el botón "Liquidar" solo marca como pagados los registros de <strong>ese rango de fechas</strong> para ese empleado. Nada se borra: en <strong>Historial Completo</strong> siempre vas a seguir viendo todo (sin compensar), liquidado o no.
                </div>

                <?php if ($mensaje !== ""): ?>
                    <div class="mensaje-liq <?= $tipoMensaje ?>">
                        <?= escapar($mensaje) ?>
                    </div>
                <?php endif; ?>

                <form method="GET" action="liquidacion.php" class="filtros">

                    <div>
                        <label for="desde">Desde</label>
                        <input type="date" id="desde" name="desde" value="<?= escapar($desde) ?>">
                    </div>

                    <div>
                        <label for="hasta">Hasta</label>
                        <input type="date" id="hasta" name="hasta" value="<?= escapar($hasta) ?>">
                    </div>

                    <div>
                        <label for="buscar">Empleado</label>
                        <input type="text" id="buscar" name="buscar" placeholder="Nombre o identificación" value="<?= escapar($buscar) ?>">
                    </div>

                    <button type="submit" class="btn-filtrar">Filtrar</button>

                </form>

                <div class="tabla-contenedor">
                    <table class="tabla">
                        <thead>
                            <tr>
                                <th>Empleado</th>
                                <th>Identificación</th>
                                <th>Cargo</th>
                                <th>Días con registros pendientes</th>
                                <th>Retraso pendiente</th>
                                <th>Extra pendiente</th>
                                <th>Deuda pendiente</th>
                                <th>Acción</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (!$resultado || $resultado->num_rows === 0): ?>
                            <tr>
                                <td colspan="8" class="sin-resultados">
                                    No hay minutos pendientes por liquidar en este momento.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php while ($fila = $resultado->fetch_assoc()):

                                $retrasoBruto = (int) $fila["total_retraso"];
                                $extraBruto = (int) $fila["total_extra"];
                                $deudaBruta = (int) $fila["total_deuda"];

                                // El tiempo extra primero recupera el retraso (la empresa
                                // le da la oportunidad de compensarlo quedándose más tiempo
                                // a la salida), y lo que sobre recupera la deuda.
                                $extraDisponible = $extraBruto;

                                if ($extraDisponible >= $retrasoBruto) {
                                    $extraDisponible -= $retrasoBruto;
                                    $retrasoNeto = 0;
                                } else {
                                    $retrasoNeto = $retrasoBruto - $extraDisponible;
                                    $extraDisponible = 0;
                                }

                                if ($extraDisponible >= $deudaBruta) {
                                    $extraDisponible -= $deudaBruta;
                                    $deudaNeta = 0;
                                } else {
                                    $deudaNeta = $deudaBruta - $extraDisponible;
                                    $extraDisponible = 0;
                                }

                                $extraNeto = $extraDisponible;
                            ?>
                                <tr>
                                    <td><?= escapar($fila["nombre"]) ?></td>
                                    <td><?= escapar($fila["identificacion"]) ?></td>
                                    <td><?= escapar($fila["cargo"]) ?></td>
                                    <td><?= (int) $fila["total_dias"] ?></td>
                                    <td>
                                        <?php if ($retrasoNeto > 0): ?>
                                            <span class="color-retraso"><?= minutosAHoras($retrasoNeto) ?></span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($extraNeto > 0): ?>
                                            <span class="color-extra"><?= minutosAHoras($extraNeto) ?></span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($deudaNeta > 0): ?>
                                            <span class="color-deuda"><?= minutosAHoras($deudaNeta) ?></span>
                                        <?php else: ?>
                                            —
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <form method="POST" action="liquidacion.php" style="display:inline;" onsubmit="return confirm('¿Liquidar el periodo pendiente de ' + <?= json_encode($fila["nombre"]) ?> + '? Sus minutos pendientes quedarán en cero para el próximo periodo. Esto no borra nada de Historial Completo.');">
                                            <input type="hidden" name="accion" value="liquidar">
                                            <input type="hidden" name="empleado_id" value="<?= (int) $fila["id"] ?>">
                                            <input type="hidden" name="desde" value="<?= escapar($desde) ?>">
                                            <input type="hidden" name="hasta" value="<?= escapar($hasta) ?>">
                                            <button type="submit" class="btn-liquidar">💰 Liquidar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endwhile; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

            </div>

        </div>

    </div>

</body>

</html>
