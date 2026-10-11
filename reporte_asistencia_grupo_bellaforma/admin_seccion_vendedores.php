<?php
/* Sección "Vendedores" del panel admin (se incluye dentro de admin.php). */

$buscarV = trim($_GET["buscar_v"] ?? "");
$desdeV = $_GET["desde_v"] ?? "";
$hastaV = $_GET["hasta_v"] ?? "";
$ordenV = ($_GET["orden_v"] ?? "valor") === "pedidos" ? "pedidos" : "valor";

$joinExtra = "";
$tiposV = "";
$paramsV = [];

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $desdeV)) {
    $joinExtra .= " AND DATE(p.creado_en) >= ?";
    $tiposV .= "s";
    $paramsV[] = $desdeV;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $hastaV)) {
    $joinExtra .= " AND DATE(p.creado_en) <= ?";
    $tiposV .= "s";
    $paramsV[] = $hastaV;
}

$sqlV = "SELECT v.id, v.nombre, v.identificacion, v.ciudad, v.telefono, v.usuario, v.activo,
                COUNT(p.id) AS total_pedidos,
                COALESCE(SUM(p.total), 0) AS total_valor
         FROM vendedores v
         LEFT JOIN pedidos_vendedores p
                ON p.vendedor_id = v.id AND p.estado NOT IN ('anulado','borrador')" . $joinExtra;

if ($buscarV !== "") {
    $sqlV .= " WHERE (v.nombre LIKE ? OR v.identificacion LIKE ? OR v.usuario LIKE ?)";
    $likeV = "%" . $buscarV . "%";
    $tiposV .= "sss";
    array_push($paramsV, $likeV, $likeV, $likeV);
}

$sqlV .= " GROUP BY v.id, v.nombre, v.identificacion, v.ciudad, v.telefono, v.usuario, v.activo";
$sqlV .= $ordenV === "pedidos"
    ? " ORDER BY total_pedidos DESC, total_valor DESC, v.nombre ASC"
    : " ORDER BY total_valor DESC, total_pedidos DESC, v.nombre ASC";

$vendedoresLista = [];
$stmtV = $conexion->prepare($sqlV);
if ($stmtV) {
    if ($paramsV) {
        $stmtV->bind_param($tiposV, ...$paramsV);
    }
    $stmtV->execute();
    $resV = $stmtV->get_result();
    $vendedoresLista = $resV ? $resV->fetch_all(MYSQLI_ASSOC) : [];
    $stmtV->close();
}

$datosGraficoVend = array_map(function ($v) {
    return [
        "nombre" => $v["nombre"],
        "pedidos" => (int) $v["total_pedidos"],
        "valor" => (float) $v["total_valor"],
    ];
}, $vendedoresLista);
?>
<style>
    .grid-detalle { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 6px 20px; margin-bottom: 14px; font-size: 14px; }
    .grid-detalle span.et { color: #667; font-size: 12px; display: block; }
    .btn-fila { display: inline-block; margin: 2px; }
    .form-inline { display: inline; margin: 0; }
    .num-der { text-align: right; white-space: nowrap; }
</style>

<section id="vendedores" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2>🧳 Vendedores</h2>
            <p>Ejecutivos de negocios con acceso para tomar pedidos. No se cuentan los pedidos anulados ni los borradores.</p>
        </div>
        <button type="button" class="btn-nuevo" onclick="nuevoVendedor()">+ Nuevo Vendedor</button>
    </div>

    <form method="GET" action="admin.php#vendedores" class="filtros">
        <input type="hidden" name="seccion" value="vendedores">
        <div>
            <label for="buscar_v">Buscar</label>
            <input type="text" id="buscar_v" name="buscar_v" placeholder="Nombre, identificación o usuario" value="<?= escapar($buscarV) ?>">
        </div>
        <div>
            <label for="desde_v">Pedidos desde</label>
            <input type="date" id="desde_v" name="desde_v" value="<?= escapar($desdeV) ?>">
        </div>
        <div>
            <label for="hasta_v">Hasta</label>
            <input type="date" id="hasta_v" name="hasta_v" value="<?= escapar($hastaV) ?>">
        </div>
        <div>
            <label for="orden_v">Ordenar por</label>
            <select id="orden_v" name="orden_v">
                <option value="valor" <?= $ordenV === "valor" ? "selected" : "" ?>>Valor vendido</option>
                <option value="pedidos" <?= $ordenV === "pedidos" ? "selected" : "" ?>>Número de pedidos</option>
            </select>
        </div>
        <button type="submit" class="btn-filtrar">Filtrar</button>
    </form>

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Vendedor</th>
                    <th>Identificación</th>
                    <th>Usuario</th>
                    <th>Ciudad</th>
                    <th class="num-der">Pedidos</th>
                    <th class="num-der">Valor total</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$vendedoresLista): ?>
                    <tr><td colspan="9" class="sin-resultados">No hay vendedores registrados.</td></tr>
                <?php else: ?>
                    <?php foreach ($vendedoresLista as $i => $v): ?>
                        <tr>
                            <td><?= $i + 1 ?></td>
                            <td><?= escapar($v["nombre"]) ?></td>
                            <td><?= escapar($v["identificacion"]) ?></td>
                            <td><?= escapar($v["usuario"]) ?></td>
                            <td><?= escapar($v["ciudad"]) ?: "—" ?></td>
                            <td class="num-der"><?= (int) $v["total_pedidos"] ?></td>
                            <td class="num-der"><?= formatoCOP($v["total_valor"]) ?></td>
                            <td>
                                <?php if ((int) $v["activo"] === 1): ?>
                                    <span class="estado estado-activo">Activo</span>
                                <?php else: ?>
                                    <span class="estado estado-inactivo">Inactivo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn-editar btn-fila"
                                    data-v="<?= escapar(json_encode([
                                        "id" => (int) $v["id"],
                                        "nombre" => $v["nombre"],
                                        "identificacion" => $v["identificacion"],
                                        "ciudad" => $v["ciudad"],
                                        "telefono" => $v["telefono"],
                                        "usuario" => $v["usuario"],
                                    ], JSON_UNESCAPED_UNICODE)) ?>"
                                    onclick="editarVendedor(this)">Editar</button>

                                <form method="POST" action="admin.php" class="form-inline">
                                    <input type="hidden" name="accion" value="vend_estado">
                                    <input type="hidden" name="id" value="<?= (int) $v["id"] ?>">
                                    <input type="hidden" name="activo" value="<?= (int) $v["activo"] === 1 ? 0 : 1 ?>">
                                    <?php if ((int) $v["activo"] === 1): ?>
                                        <button type="submit" class="btn-eliminar btn-fila" onclick="return confirm('¿Desactivar este vendedor? No podrá ingresar.')">Desactivar</button>
                                    <?php else: ?>
                                        <button type="submit" class="btn-activar btn-fila">Activar</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <div class="tarjeta-grafico">
        <div class="tarjeta-grafico-cabecera">
            <h3 style="margin:0;">🏆 Ranking de Vendedores</h3>
            <div class="selector-metrica">
                <label for="metricaVendedores">Ver por:</label>
                <select id="metricaVendedores" onchange="actualizarGraficoVendedores()">
                    <option value="valor" <?= $ordenV === "valor" ? "selected" : "" ?>>Valor vendido</option>
                    <option value="pedidos" <?= $ordenV === "pedidos" ? "selected" : "" ?>>Número de pedidos</option>
                </select>
            </div>
        </div>
        <div id="contenedorGraficoVendedores" style="position: relative; width: 100%;">
            <canvas id="graficoVendedores"></canvas>
        </div>
        <p id="sinDatosVendedores" style="display:none; text-align:center; color:#6c757d; margin-top:10px;">
            No hay datos para graficar con los filtros actuales.
        </p>
    </div>
</section>

<!-- MODAL VENDEDOR -->
<div class="modal" id="modalVendedor">
    <div class="modal-contenido">
        <h3 id="tituloModalVendedor">Nuevo vendedor</h3>
        <form method="POST" action="admin.php" class="formulario-modal">
            <input type="hidden" name="accion" id="vend_accion" value="vend_crear">
            <input type="hidden" name="id" id="vend_id" value="">

            <div>
                <label for="vend_nombre">Nombre completo *</label>
                <input type="text" id="vend_nombre" name="nombre" maxlength="150" required>
            </div>
            <div>
                <label for="vend_identificacion">Número de identificación *</label>
                <input type="text" id="vend_identificacion" name="identificacion" maxlength="40" required>
            </div>
            <div>
                <label for="vend_ciudad">Ciudad</label>
                <input type="text" id="vend_ciudad" name="ciudad" maxlength="100">
            </div>
            <div>
                <label for="vend_telefono">Teléfono</label>
                <input type="text" id="vend_telefono" name="telefono" maxlength="40">
            </div>
            <div>
                <label for="vend_usuario">Usuario de acceso *</label>
                <input type="text" id="vend_usuario" name="usuario" maxlength="60" autocomplete="off" required>
            </div>
            <div>
                <label for="vend_password" id="vend_password_label">Contraseña * (mínimo 6)</label>
                <div style="display:flex; gap:8px;">
                    <input type="text" id="vend_password" name="password" autocomplete="off" minlength="6">
                    <button type="button" class="btn-editar" onclick="generarClaveVendedor()">Generar</button>
                </div>
            </div>

            <div class="botones-modal">
                <button type="button" class="btn-editar" onclick="cerrarModalVendedor()">Cancelar</button>
                <button type="submit" class="btn-nuevo">Guardar</button>
            </div>
        </form>
    </div>
</div>

<script>
    function nuevoVendedor() {
        document.getElementById("tituloModalVendedor").textContent = "Nuevo vendedor";
        document.getElementById("vend_accion").value = "vend_crear";
        document.getElementById("vend_id").value = "";
        ["nombre", "identificacion", "ciudad", "telefono", "usuario", "password"].forEach(function (c) {
            document.getElementById("vend_" + c).value = "";
        });
        document.getElementById("vend_password").required = true;
        document.getElementById("vend_password_label").textContent = "Contraseña * (mínimo 6)";
        document.getElementById("modalVendedor").style.display = "flex";
    }

    function editarVendedor(btn) {
        var d = JSON.parse(btn.dataset.v);
        document.getElementById("tituloModalVendedor").textContent = "Editar vendedor";
        document.getElementById("vend_accion").value = "vend_editar";
        document.getElementById("vend_id").value = d.id;
        document.getElementById("vend_nombre").value = d.nombre || "";
        document.getElementById("vend_identificacion").value = d.identificacion || "";
        document.getElementById("vend_ciudad").value = d.ciudad || "";
        document.getElementById("vend_telefono").value = d.telefono || "";
        document.getElementById("vend_usuario").value = d.usuario || "";
        document.getElementById("vend_password").value = "";
        document.getElementById("vend_password").required = false;
        document.getElementById("vend_password_label").textContent = "Nueva contraseña (dejar vacío para no cambiarla)";
        document.getElementById("modalVendedor").style.display = "flex";
    }

    function cerrarModalVendedor() {
        document.getElementById("modalVendedor").style.display = "none";
    }

    function generarClaveVendedor() {
        var c = "abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789";
        var s = "";
        for (var i = 0; i < 8; i++) { s += c.charAt(Math.floor(Math.random() * c.length)); }
        document.getElementById("vend_password").value = s;
    }

    var datosGraficoVend = <?= json_encode($datosGraficoVend, JSON_UNESCAPED_UNICODE) ?>;
    var graficoVendInstancia = null;

    function actualizarGraficoVendedores() {
        var metrica = document.getElementById("metricaVendedores").value;
        var canvas = document.getElementById("graficoVendedores");
        var contenedor = document.getElementById("contenedorGraficoVendedores");
        var aviso = document.getElementById("sinDatosVendedores");

        var datos = datosGraficoVend.filter(function (d) { return d[metrica] > 0; })
            .sort(function (a, b) { return b[metrica] - a[metrica]; });

        if (graficoVendInstancia) { graficoVendInstancia.destroy(); graficoVendInstancia = null; }

        if (typeof Chart === "undefined" || datos.length === 0) {
            contenedor.style.display = "none";
            aviso.style.display = "block";
            return;
        }
        contenedor.style.display = "block";
        aviso.style.display = "none";
        contenedor.style.height = Math.max(220, datos.length * 36 + 60) + "px";

        graficoVendInstancia = new Chart(canvas, {
            type: "bar",
            data: {
                labels: datos.map(function (d, i) { return (i + 1) + ". " + d.nombre; }),
                datasets: [{
                    label: metrica === "valor" ? "Valor vendido (COP)" : "Número de pedidos",
                    data: datos.map(function (d) { return d[metrica]; }),
                    backgroundColor: "#1565C0",
                    borderRadius: 4
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
                            label: function (ctx) {
                                return metrica === "valor"
                                    ? "$ " + Math.round(ctx.parsed.x).toLocaleString("es-CO")
                                    : ctx.parsed.x + " pedidos";
                            }
                        }
                    }
                },
                scales: { x: { beginAtZero: true } }
            }
        });
    }

    document.addEventListener("DOMContentLoaded", actualizarGraficoVendedores);
</script>
