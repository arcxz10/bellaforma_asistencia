<?php
require_once "conexion.php";

// Obtener filtros si se envían
$desde = $_GET["desde"] ?? "";
$hasta = $_GET["hasta"] ?? "";
$estado = $_GET["estado"] ?? "todos";
$empleadoFiltro = $_GET["empleado"] ?? "";

$sql = "
    SELECT 
        a.*, 
        e.nombre, 
        e.cargo, 
        e.identificacion
    FROM asistencias a
    INNER JOIN empleados e ON a.empleado_id = e.id
    WHERE 1=1
";

$params = [];
$tipos = "";

if (!empty($desde) && !empty($hasta)) {
    $sql .= " AND a.fecha BETWEEN ? AND ?";
    $params[] = $desde;
    $params[] = $hasta;
    $tipos .= "ss";
}

if ($estado !== "todos" && !empty($estado)) {
    $sql .= " AND a.estado_entrada = ?";
    $params[] = $estado;
    $tipos .= "s";
}

if (!empty($empleadoFiltro)) {
    $sql .= " (e.nombre LIKE ? OR e.identificacion LIKE ?)";
    $busquedaLike = "%" . $empleadoFiltro . "%";
    $params[] = $busquedaLike;
    $params[] = $busquedaLike;
    $tipos .= "ss";
}

$sql .= " ORDER BY a.fecha DESC, a.id DESC";

$stmt = $conexion->prepare($sql);
if (!empty($params)) {
    $stmt->bind_param($tipos, ...$params);
}
$stmt->execute();
$resultadoHistorial = $stmt->get_result();
?>

<div class="container-fluid p-4">
    <h2>Historial Completo de Asistencias y Tiempos</h2>
    <p>Consulta el acumulado histórico de retrasos, horas extra y deudas de los empleados.</p>

    <!-- Filtros de búsqueda -->
    <form method="GET" action="admin.php" class="row g-3 mb-4">
        <input type="hidden" name-="modulo" value="historial">
        
        <div class="col-md-3">
            <label class="form-label">Desde</label>
            <input type="date" name="desde" class="form-control" value="<?= htmlspecialchars($desde) ?>">
        </div>
        
        <div class="col-md-3">
            <label class="form-label">Hasta</label>
            <input type="date" name="hasta" class="form-control" value="<?= htmlspecialchars($hasta) ?>">
        </div>

        <div class="col-md-3">
            <label class="form-label">Empleado</label>
            <input type="text" name="empleado" class="form-control" placeholder="Nombre o cédula" value="<?= htmlspecialchars($empleadoFiltro) ?>">
        </div>

        <div class="col-md-3 d-flex align-items-end">
            <button type="submit" class="btn btn-primary w-100">Filtrar Historial</button>
        </div>
    </form>

    <!-- Tabla de resultados -->
    <div class="table-responsive">
        <table class="table table-striped table-bordered">
            <thead>
                <tr>
                    <th>Fecha</th>
                    <th>Empleado</th>
                    <th>Cargo</th>
                    <th>Entrada</th>
                    <th>Retraso</th>
                    <th>S. Almuerzo</th>
                    <th>E. Almuerzo</th>
                    <th>Salida</th>
                    <th>Extra Netas</th>
                    <th>Deuda</th>
                    <th>Justificación</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($resultadoHistorial->num_rows > 0): ?>
                    <?php while ($row = $resultadoHistorial->fetch_assoc()): ?>
                        <tr>
                            <td><?= $row["fecha"] ?></td>
                            <td><?= htmlspecialchars($row["nombre"]) ?></td>
                            <td><?= htmlspecialchars($row["cargo"]) ?></td>
                            <td><?= $row["hora_entrada"] ?? "-" ?></td>
                            <td><?= $row["minutos_retraso"] ? $row["minutos_retraso"] . " min" : "-" ?></td>
                            <td><?= $row["hora_salida_almuerzo"] ?? "-" ?></td>
                            <td><?= $row["hora_entrada_almuerzo"] ?? "-" ?></td>
                            <td><?= $row["hora_salida"] ?? "-" ?></td>
                            <td><?= $row["minutos_extra"] ? $row["minutos_extra"] . " min" : "-" ?></td>
                            <td><?= $row["minutos_deuda"] ? $row["minutos_deuda"] . " min" : "-" ?></td>
                            <td><?= htmlspecialchars($row["justificacion"] ?? "") ?></td>
                        </tr>
                    <?php endwhile; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="11" class="text-center">No se encontraron registros en el historial.</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
