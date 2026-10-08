<?php
/* Sección "Pedidos" del panel admin (se incluye dentro de admin.php). */

$filtrosPed = [
    "vendedor" => (int) ($_GET["vendedor_p"] ?? 0),
    "estado"   => $_GET["estado_p"] ?? "",
    "desde"    => $_GET["desde_p"] ?? "",
    "hasta"    => $_GET["hasta_p"] ?? "",
    "buscar"   => trim($_GET["buscar_p"] ?? ""),
];

$pedidosAdmin = obtenerPedidos($conexion, $filtrosPed, 300);

$listaVendedoresFiltro = [];
$resLV = $conexion->query("SELECT id, nombre FROM vendedores ORDER BY nombre ASC");
if ($resLV) {
    $listaVendedoresFiltro = $resLV->fetch_all(MYSQLI_ASSOC);
}

$qsDescarga = http_build_query([
    "vendedor_p" => $filtrosPed["vendedor"] ?: "",
    "estado_p"   => $filtrosPed["estado"],
    "desde_p"    => $filtrosPed["desde"],
    "hasta_p"    => $filtrosPed["hasta"],
    "buscar_p"   => $filtrosPed["buscar"],
]);

$sumaListados = 0;
foreach ($pedidosAdmin as $pp) {
    if (!in_array($pp["estado"], ["anulado", "borrador"], true)) {
        $sumaListados += (float) $pp["total"];
    }
}
?>
<style>
    .estado-borrador { background: #E8EAF6; color: #3949AB; }
    .grid-detalle span.et { color: #667; font-size: 12px; display: block; }
</style>

<section id="pedidos" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2>🧾 Pedidos de ejecutivos</h2>
            <p>Revise, edite y descargue los pedidos (PDF, archivo para Syscafe o Excel detallado). Márquelos «Subido a Syscafe» cuando ya estén digitados: desde ese momento el ejecutivo ya no puede editarlos.</p>
        </div>
        <?php if ($pedidosAdmin): ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a class="btn-nuevo" style="text-decoration:none;" href="pedido_pdf.php?<?= escapar($qsDescarga) ?>">⬇ PDF (<?= count($pedidosAdmin) ?>)</a>
                <a class="btn-nuevo" style="text-decoration:none; background:#00796B;" href="pedido_syscafe.php?<?= escapar($qsDescarga) ?>">⬇ Syscafe .XLS (<?= count($pedidosAdmin) ?>)</a>
                <a class="btn-nuevo" style="text-decoration:none; background:#1D6F42;" href="pedido_excel.php?<?= escapar($qsDescarga) ?>">⬇ Excel detallado</a>
            </div>
        <?php endif; ?>
    </div>

    <form method="GET" action="admin.php#pedidos" class="filtros">
        <input type="hidden" name="seccion" value="pedidos">
        <div>
            <label for="vendedor_p">Ejecutivo</label>
            <select id="vendedor_p" name="vendedor_p">
                <option value="0">Todos</option>
                <?php foreach ($listaVendedoresFiltro as $lv): ?>
                    <option value="<?= (int) $lv["id"] ?>" <?= $filtrosPed["vendedor"] === (int) $lv["id"] ? "selected" : "" ?>><?= escapar($lv["nombre"]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="estado_p">Estado</label>
            <select id="estado_p" name="estado_p">
                <option value="">Todos (sin borradores)</option>
                <?php foreach (["pendiente", "procesado", "anulado", "borrador"] as $est): ?>
                    <option value="<?= $est ?>" <?= $filtrosPed["estado"] === $est ? "selected" : "" ?>><?= escapar(etiquetaEstadoPedido($est)) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="desde_p">Desde</label>
            <input type="date" id="desde_p" name="desde_p" value="<?= escapar($filtrosPed["desde"]) ?>">
        </div>
        <div>
            <label for="hasta_p">Hasta</label>
            <input type="date" id="hasta_p" name="hasta_p" value="<?= escapar($filtrosPed["hasta"]) ?>">
        </div>
        <div>
            <label for="buscar_p">Cliente / NIT / N° pedido</label>
            <input type="text" id="buscar_p" name="buscar_p" value="<?= escapar($filtrosPed["buscar"]) ?>">
        </div>
        <button type="submit" class="btn-filtrar">Filtrar</button>
    </form>

    <p style="margin:0 0 10px; font-size:14px;">
        Mostrando <strong><?= count($pedidosAdmin) ?></strong> pedidos (máx. 300) · Suma sin anulados ni borradores:
        <strong><?= formatoCOP($sumaListados) ?></strong>
    </p>

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>N°</th>
                    <th>Fecha</th>
                    <th>Ejecutivo</th>
                    <th>Cliente</th>
                    <th>Documento</th>
                    <th>Municipio</th>
                    <th>Pago</th>
                    <th class="num-der">Total</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$pedidosAdmin): ?>
                    <tr><td colspan="10" class="sin-resultados">No hay pedidos con estos filtros.</td></tr>
                <?php else: ?>
                    <?php foreach ($pedidosAdmin as $ped): ?>
                        <?php $esBorr = $ped["estado"] === "borrador"; ?>
                        <tr>
                            <td><strong><?= numeroPedido($ped["id"]) ?></strong></td>
                            <td><?= date("d/m/Y H:i", strtotime($ped["actualizado_en"] ?? $ped["creado_en"])) ?></td>
                            <td><?= escapar($ped["vendedor_nombre"]) ?></td>
                            <td>
                                <?= escapar($ped["cliente_nombre"]) ?: "—" ?>
                                <?php if (!empty($ped["cliente_nombre_comercial"])): ?><br><small style="color:#667;"><?= escapar($ped["cliente_nombre_comercial"]) ?></small><?php endif; ?>
                            </td>
                            <td><?= escapar(etiquetaDocumento($ped["cliente_tipo_persona"] ?? "juridica")) ?> <?= escapar($ped["cliente_nit"]) ?></td>
                            <td><?= escapar($ped["cliente_ciudad"]) ?></td>
                            <td><?= escapar(etiquetaCondicion($ped)) ?><?= !empty($ped["factura_electronica"]) ? "<br><small>📧 Fact. electrónica</small>" : "" ?></td>
                            <td class="num-der"><?= formatoCOP($ped["total"]) ?></td>
                            <td><span class="estado <?= claseEstadoPedido($ped["estado"]) ?>"><?= escapar(etiquetaEstadoPedido($ped["estado"])) ?></span></td>
                            <td>
                                <button type="button" class="btn-editar btn-fila" onclick="verPedidoAdmin(<?= (int) $ped["id"] ?>)">Ver</button>
                                <a class="btn-editar btn-fila" style="text-decoration:none;" href="admin_pedido_editar.php?id=<?= (int) $ped["id"] ?>"
                                   <?= $ped["estado"] === "procesado" ? "onclick=\"return confirm('Este pedido ya está subido a Syscafe. ¿Editarlo de todos modos?')\"" : "" ?>>Editar</a>
                                <?php if (!$esBorr): ?>
                                    <a class="btn-activar btn-fila" href="pedido_pdf.php?id=<?= (int) $ped["id"] ?>">PDF</a>
                                    <a class="btn-activar btn-fila" style="background:#00796B;" href="pedido_syscafe.php?id=<?= (int) $ped["id"] ?>" title="Archivo .XLS para importar en Syscafe">Syscafe</a>
                                    <form method="POST" action="admin.php" class="form-inline">
                                        <input type="hidden" name="accion" value="ped_estado">
                                        <input type="hidden" name="id" value="<?= (int) $ped["id"] ?>">
                                        <select name="estado" onchange="this.form.submit()" style="padding:6px; border-radius:5px; border:1px solid #ccc; font-size:12px;">
                                            <?php foreach (["pendiente", "procesado", "anulado"] as $est): ?>
                                                <option value="<?= $est ?>" <?= $ped["estado"] === $est ? "selected" : "" ?>><?= escapar(etiquetaEstadoPedido($est)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php endif; ?>

                                <div id="detalle-ped-<?= (int) $ped["id"] ?>" style="display:none;">
                                    <h3>Pedido #<?= numeroPedido($ped["id"]) ?> · <?= escapar(etiquetaEstadoPedido($ped["estado"])) ?></h3>
                                    <div class="grid-detalle">
                                        <div><span class="et">Fecha</span><?= date("d/m/Y H:i", strtotime($ped["creado_en"])) ?></div>
                                        <div><span class="et">Ejecutivo</span><?= escapar($ped["vendedor_nombre"]) ?> (ID <?= escapar($ped["vendedor_identificacion"]) ?>)</div>
                                        <div><span class="et">Tipo de cliente</span><?= ($ped["cliente_tipo_persona"] ?? "juridica") === "natural" ? "Persona natural" : "Persona jurídica" ?></div>
                                        <div><span class="et"><?= ($ped["cliente_tipo_persona"] ?? "") === "natural" ? "Nombre completo" : "Razón social" ?></span><?= escapar($ped["cliente_nombre"]) ?: "—" ?></div>
                                        <div><span class="et"><?= escapar(etiquetaDocumento($ped["cliente_tipo_persona"] ?? "juridica")) ?></span><?= escapar($ped["cliente_nit"]) ?: "—" ?></div>
                                        <div><span class="et">Nombre comercial</span><?= escapar($ped["cliente_nombre_comercial"]) ?: "—" ?></div>
                                        <div><span class="et">Teléfono</span><?= escapar($ped["cliente_telefono"]) ?: "—" ?></div>
                                        <div><span class="et">Correo</span><?= escapar($ped["cliente_email"]) ?: "—" ?></div>
                                        <div><span class="et">Departamento / municipio</span><?= escapar($ped["cliente_departamento"]) ?> / <?= escapar($ped["cliente_ciudad"]) ?></div>
                                        <div><span class="et">Barrio</span><?= escapar($ped["cliente_barrio"]) ?: "—" ?></div>
                                        <div><span class="et">Dirección de entrega</span><?= escapar($ped["cliente_direcciones"]) ?: "—" ?></div>
                                        <div><span class="et">Puntos de referencia</span><?= escapar($ped["cliente_puntos_referencia"]) ?: "—" ?></div>
                                        <div><span class="et">Condiciones de pago</span><?= escapar(etiquetaCondicion($ped)) ?></div>
                                        <div><span class="et">Factura electrónica</span><?= !empty($ped["factura_electronica"]) ? "Sí — " . escapar($ped["email_fe"]) : "No" ?></div>
                                        <div><span class="et">Observaciones</span><?= nl2br(escapar($ped["observaciones"])) ?: "—" ?></div>
                                    </div>
                                    <table class="tabla">
                                        <thead>
                                            <tr><th>Referencia</th><th>Producto</th><th class="num-der">Cant.</th><th class="num-der">Precio</th><th class="num-der">Subtotal</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($ped["items"] as $it): ?>
                                                <tr>
                                                    <td><strong><?= escapar($it["referencia"]) ?></strong></td>
                                                    <td><?= escapar($it["nombre"]) ?></td>
                                                    <td class="num-der"><?= (int) $it["cantidad"] ?></td>
                                                    <td class="num-der"><?= formatoCOP($it["precio_unitario"]) ?></td>
                                                    <td class="num-der"><?= formatoCOP($it["subtotal"]) ?></td>
                                                </tr>
                                            <?php endforeach; ?>
                                            <tr>
                                                <td colspan="4" class="num-der"><strong>TOTAL</strong></td>
                                                <td class="num-der"><strong><?= formatoCOP($ped["total"]) ?></strong></td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- MODAL DETALLE PEDIDO -->
<div class="modal" id="modalPedidoAdmin">
    <div class="modal-contenido" style="max-width: 850px; width: 95%; max-height: 90vh; overflow-y: auto;">
        <div id="contenidoPedidoAdmin"></div>
        <div class="botones-modal">
            <button type="button" class="btn-editar" onclick="cerrarPedidoAdmin()">Cerrar</button>
        </div>
    </div>
</div>

<script>
    function verPedidoAdmin(id) {
        var origen = document.getElementById("detalle-ped-" + id);
        if (!origen) { return; }
        document.getElementById("contenidoPedidoAdmin").innerHTML = origen.innerHTML;
        document.getElementById("modalPedidoAdmin").style.display = "flex";
    }
    function cerrarPedidoAdmin() {
        document.getElementById("modalPedidoAdmin").style.display = "none";
    }
</script>
