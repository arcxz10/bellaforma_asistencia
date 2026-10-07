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

$qsPdf = http_build_query([
    "vendedor_p" => $filtrosPed["vendedor"] ?: "",
    "estado_p"   => $filtrosPed["estado"],
    "desde_p"    => $filtrosPed["desde"],
    "hasta_p"    => $filtrosPed["hasta"],
    "buscar_p"   => $filtrosPed["buscar"],
]);

$sumaListados = 0;
foreach ($pedidosAdmin as $pp) {
    if ($pp["estado"] !== "anulado") {
        $sumaListados += (float) $pp["total"];
    }
}
?>
<section id="pedidos" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2>🧾 Pedidos de vendedores</h2>
            <p>Revise los pedidos, descárguelos en PDF y márquelos cuando ya estén digitados en Syscafe.</p>
        </div>
        <?php if ($pedidosAdmin): ?>
            <a class="btn-nuevo" style="text-decoration:none;" href="pedido_pdf.php?<?= escapar($qsPdf) ?>">
                ⬇ PDF de estos pedidos (<?= count($pedidosAdmin) ?>)
            </a>
        <?php endif; ?>
    </div>

    <form method="GET" action="admin.php#pedidos" class="filtros">
        <input type="hidden" name="seccion" value="pedidos">
        <div>
            <label for="vendedor_p">Vendedor</label>
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
                <option value="">Todos</option>
                <?php foreach (["pendiente", "procesado", "anulado"] as $est): ?>
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
        Mostrando <strong><?= count($pedidosAdmin) ?></strong> pedidos (máx. 300) · Suma sin anulados:
        <strong><?= formatoCOP($sumaListados) ?></strong>
    </p>

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>N°</th>
                    <th>Fecha</th>
                    <th>Vendedor</th>
                    <th>Cliente</th>
                    <th>NIT</th>
                    <th>Ciudad</th>
                    <th class="num-der">Total</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$pedidosAdmin): ?>
                    <tr><td colspan="9" class="sin-resultados">No hay pedidos con estos filtros.</td></tr>
                <?php else: ?>
                    <?php foreach ($pedidosAdmin as $ped): ?>
                        <tr>
                            <td><strong><?= numeroPedido($ped["id"]) ?></strong></td>
                            <td><?= date("d/m/Y H:i", strtotime($ped["creado_en"])) ?></td>
                            <td><?= escapar($ped["vendedor_nombre"]) ?></td>
                            <td><?= escapar($ped["cliente_nombre"]) ?></td>
                            <td><?= escapar($ped["cliente_nit"]) ?></td>
                            <td><?= escapar($ped["cliente_ciudad"]) ?></td>
                            <td class="num-der"><?= formatoCOP($ped["total"]) ?></td>
                            <td><span class="estado <?= claseEstadoPedido($ped["estado"]) ?>"><?= escapar(etiquetaEstadoPedido($ped["estado"])) ?></span></td>
                            <td>
                                <button type="button" class="btn-editar btn-fila" onclick="verPedidoAdmin(<?= (int) $ped["id"] ?>)">Ver</button>
                                <a class="btn-activar btn-fila" href="pedido_pdf.php?id=<?= (int) $ped["id"] ?>">PDF</a>
                                <form method="POST" action="admin.php" class="form-inline">
                                    <input type="hidden" name="accion" value="ped_estado">
                                    <input type="hidden" name="id" value="<?= (int) $ped["id"] ?>">
                                    <select name="estado" onchange="this.form.submit()" style="padding:6px; border-radius:5px; border:1px solid #ccc; font-size:12px;">
                                        <?php foreach (["pendiente", "procesado", "anulado"] as $est): ?>
                                            <option value="<?= $est ?>" <?= $ped["estado"] === $est ? "selected" : "" ?>><?= escapar(etiquetaEstadoPedido($est)) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>

                                <!-- Detalle oculto (ya escapado en servidor) -->
                                <div id="detalle-ped-<?= (int) $ped["id"] ?>" style="display:none;">
                                    <h3>Pedido #<?= numeroPedido($ped["id"]) ?></h3>
                                    <div class="grid-detalle">
                                        <div><span class="et">Fecha</span><?= date("d/m/Y H:i", strtotime($ped["creado_en"])) ?></div>
                                        <div><span class="et">Vendedor</span><?= escapar($ped["vendedor_nombre"]) ?> (ID <?= escapar($ped["vendedor_identificacion"]) ?>)</div>
                                        <div><span class="et">Cliente</span><?= escapar($ped["cliente_nombre"]) ?></div>
                                        <div><span class="et">NIT</span><?= escapar($ped["cliente_nit"]) ?></div>
                                        <div><span class="et">Teléfono</span><?= escapar($ped["cliente_telefono"]) ?></div>
                                        <div><span class="et">Ciudad</span><?= escapar($ped["cliente_ciudad"]) ?></div>
                                        <div><span class="et">Barrio</span><?= escapar($ped["cliente_barrio"]) ?: "—" ?></div>
                                        <div><span class="et">Direcciones</span><?= nl2br(escapar($ped["cliente_direcciones"])) ?: "—" ?></div>
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
