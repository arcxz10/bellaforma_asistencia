<?php
/* Sección "Pedidos" del panel admin (se incluye dentro de admin.php). */

$filtrosPed = [
    "vendedor" => (int) ($_GET["vendedor_p"] ?? 0),
    "estado"   => $_GET["estado_p"] ?? "",
    "tipo_cliente" => $_GET["tipo_p"] ?? "",
    "tipo_documento" => $_GET["doc_p"] ?? "",
    "desde"    => $_GET["desde_p"] ?? "",
    "hasta"    => $_GET["hasta_p"] ?? "",
    "buscar"   => trim($_GET["buscar_p"] ?? ""),
];

$pedidosAdmin = obtenerPedidos($conexion, $filtrosPed, 300);
$ultimosNumeros = ultimosNumerosDocumento($conexion);

$listaVendedoresFiltro = [];
$resLV = $conexion->query("SELECT id, nombre FROM vendedores ORDER BY nombre ASC");
if ($resLV) {
    $listaVendedoresFiltro = $resLV->fetch_all(MYSQLI_ASSOC);
}

$qsDescarga = http_build_query([
    "vendedor_p" => $filtrosPed["vendedor"] ?: "",
    "estado_p"   => $filtrosPed["estado"],
    "tipo_p"     => $filtrosPed["tipo_cliente"],
    "doc_p"      => $filtrosPed["tipo_documento"],
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
    .tag-doc { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: bold; background: #E3F2FD; color: #0D47A1; }
    .tag-doc-remision { background: #FFF3E0; color: #E65100; }
    .tag-doc-cotizacion { background: #F3E5F5; color: #6A1B9A; }
</style>

<section id="pedidos" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2><svg class="ico" aria-hidden="true"><use href="#i-receipt"/></svg> Pedidos de ejecutivos</h2>
            <p>Revise, edite y descargue los pedidos, remisiones y cotizaciones (PDF, archivo para Syscafe o Excel detallado). Márquelos «Subido a Syscafe» cuando ya estén digitados: desde ese momento el ejecutivo ya no puede editarlos.</p>
        </div>
        <?php if ($pedidosAdmin): ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <a class="btn-nuevo" style="text-decoration:none;" href="pedido_pdf.php?<?= escapar($qsDescarga) ?>"><svg class="ico" aria-hidden="true"><use href="#i-download"/></svg> PDF (<?= count($pedidosAdmin) ?>)</a>
                <a class="btn-nuevo" style="text-decoration:none; background:#00796B;" href="pedido_syscafe.php?<?= escapar($qsDescarga) ?>"><svg class="ico" aria-hidden="true"><use href="#i-download"/></svg> Syscafe .XLS (<?= count($pedidosAdmin) ?>)</a>
                <a class="btn-nuevo" style="text-decoration:none; background:#1D6F42;" href="pedido_excel.php?<?= escapar($qsDescarga) ?>"><svg class="ico" aria-hidden="true"><use href="#i-download"/></svg> Excel detallado</a>
            </div>
        <?php endif; ?>
    </div>

    <!-- NUMERACIÓN AUTOMÁTICA -->
    <details style="background:#fff; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.06); padding:14px 18px; margin:0 0 16px;">
        <summary style="cursor:pointer; font-weight:700; font-size:15px;"><svg class="ico" aria-hidden="true" style="vertical-align:-.2em; margin-right:6px;"><use href="#i-list"/></svg>Numeración automática de documentos</summary>
        <form method="POST" action="admin.php" style="margin-top:14px;">
            <input type="hidden" name="accion" value="ped_numeracion">
            <p style="margin:0 0 12px; font-size:13px; color:#667;">Escriba el <b>número con el que quiere que salga el próximo documento</b> de cada clase (ej.: escribe 1000 y el próximo saldrá con el 1000, luego el 1001, 1002...). El cuadro siempre muestra el número que sigue. Déjelo vacío para mantener el número automático de antes. Si el ejecutivo escribe un número a mano, se respeta.</p>
            <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(260px, 1fr)); gap:12px;">
                <?php foreach (clasesNumeracionDocumento() as $claveNum => $infoNum): $ultNum = (int) ($ultimosNumeros[$claveNum] ?? 0); ?>
                    <div>
                        <label for="num_<?= $claveNum ?>" style="display:block; font-weight:600; font-size:13px; margin-bottom:4px;"><?= escapar($infoNum[2]) ?> <span style="color:#889; font-weight:400;">(Syscafe <?= $claveNum ?>)</span></label>
                        <input type="text" name="num[<?= $claveNum ?>]" id="num_<?= $claveNum ?>" inputmode="numeric" pattern="[0-9]*" maxlength="9" autocomplete="off"
                               value="<?= $ultNum > 0 ? $ultNum : "" ?>" placeholder="Automático" data-prox="prox_<?= $claveNum ?>"
                               style="width:100%; padding:9px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box;">
                        <small id="prox_<?= $claveNum ?>" style="color:#0D47A1;"><?= $ultNum > 0 ? "Próximo: " . $ultNum : "" ?></small>
                    </div>
                <?php endforeach; ?>
            </div>
            <div style="display:flex; justify-content:flex-end; margin-top:14px;">
                <button type="submit" class="btn-nuevo">Guardar numeración</button>
            </div>
        </form>
        <script>
            document.querySelectorAll("input[data-prox]").forEach(function (inp) {
                inp.addEventListener("input", function () {
                    var n = parseInt(inp.value, 10);
                    document.getElementById(inp.dataset.prox).textContent = n > 0 ? "Próximo: " + n : "";
                });
            });
        </script>
    </details>

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
            <label for="doc_p">Tipo de documento</label>
            <select id="doc_p" name="doc_p">
                <option value="">Todos</option>
                <?php foreach (tiposDocumentoPedido() as $clave => $infoDoc): ?>
                    <option value="<?= $clave ?>" <?= $filtrosPed["tipo_documento"] === $clave ? "selected" : "" ?>><?= escapar($infoDoc[0]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="tipo_p">Tipo de cliente</label>
            <select id="tipo_p" name="tipo_p">
                <option value="">Todos</option>
                <option value="mayorista" <?= $filtrosPed["tipo_cliente"] === "mayorista" ? "selected" : "" ?>>Mayorista</option>
                <option value="detal" <?= $filtrosPed["tipo_cliente"] === "detal" ? "selected" : "" ?>>Detal</option>
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
            <label for="buscar_p">Cliente / NIT / N° documento</label>
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
                    <th>Documento / N°</th>
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
                            <td>
                                <span class="tag-doc tag-doc-<?= escapar(tipoDocumentoValido($ped["tipo_pedido"] ?? "pedido")) ?>"><?= escapar(etiquetaTipoDocumento($ped["tipo_pedido"] ?? "pedido")) ?></span><br>
                                <strong><?= escapar(numeroDocumento($ped)) ?></strong>
                            </td>
                            <td><?= date("d/m/Y H:i", strtotime($ped["actualizado_en"] ?? $ped["creado_en"])) ?></td>
                            <td><?= escapar($ped["vendedor_nombre"]) ?></td>
                            <td>
                                <span class="pf-tag-lista <?= listaValida($ped["tipo_cliente"] ?? "") ?>" style="display:inline-block;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:bold;background:<?= ($ped["tipo_cliente"] ?? "") === "detal" ? "#FCE4EC" : "#E3F2FD" ?>;color:<?= ($ped["tipo_cliente"] ?? "") === "detal" ? "#AD1457" : "#0D47A1" ?>;"><?= escapar(etiquetaTipoCliente($ped["tipo_cliente"] ?? "mayorista")) ?></span><br>
                                <?= escapar($ped["cliente_nombre"]) ?: "—" ?>
                                <?php if (!empty($ped["cliente_nombre_comercial"])): ?><br><small style="color:#667;"><?= escapar($ped["cliente_nombre_comercial"]) ?></small><?php endif; ?>
                            </td>
                            <td><?= escapar(etiquetaDocumento($ped["cliente_tipo_persona"] ?? "juridica")) ?> <?= escapar($ped["cliente_nit"]) ?></td>
                            <td><?= escapar($ped["cliente_ciudad"]) ?></td>
                            <td><?= escapar(etiquetaCondicion($ped)) ?><?= !empty($ped["transportadora"]) ? "<br><small><svg class='ico' aria-hidden='true'><use href='#i-truck'/></svg> " . escapar(etiquetaTransportadora($ped["transportadora"])) . "</small>" : "" ?><?= !empty($ped["factura_electronica"]) ? "<br><small>Fact. electrónica</small>" : "" ?></td>
                            <td class="num-der"><?= formatoCOP($ped["total"]) ?></td>
                            <td><span class="estado <?= claseEstadoPedido($ped["estado"]) ?>"><?= escapar(etiquetaEstadoPedido($ped["estado"])) ?></span></td>
                            <td>
                                <button type="button" class="btn-editar btn-fila" onclick="verPedidoAdmin(<?= (int) $ped["id"] ?>)">Ver</button>
                                <button type="button" class="btn-editar btn-fila"
                                    data-id="<?= (int) $ped["id"] ?>"
                                    data-tipo="<?= escapar(tipoDocumentoValido($ped["tipo_pedido"] ?? "pedido")) ?>"
                                    data-numero="<?= escapar(trim((string) ($ped["numero_pedido"] ?? ""))) ?>"
                                    data-auto="<?= escapar(numeroPedido($ped["id"])) ?>"
                                    data-estado="<?= escapar($ped["estado"]) ?>"
                                    onclick="abrirEditarNumero(this)">Editar</button>
                                <button type="button" class="btn-eliminar btn-fila"
                                    data-id="<?= (int) $ped["id"] ?>"
                                    data-num="<?= escapar(numeroDocumento($ped)) ?>"
                                    data-doc="<?= escapar(etiquetaTipoDocumento($ped["tipo_pedido"] ?? "pedido")) ?>"
                                    data-cliente="<?= escapar($ped["cliente_nombre"] ?: "Sin cliente") ?>"
                                    data-total="<?= escapar(formatoCOP($ped["total"])) ?>"
                                    data-estado="<?= escapar($ped["estado"]) ?>"
                                    onclick="pedirEliminarPedido(this)">Eliminar</button>
                                <?php if (!$esBorr): ?>
                                    <a class="btn-activar btn-fila" href="pedido_pdf.php?id=<?= (int) $ped["id"] ?>">PDF</a>
                                    <a class="btn-activar btn-fila" style="background:#00796B;" href="pedido_syscafe.php?id=<?= (int) $ped["id"] ?>" title="Archivo .XLS con la plantilla de pedidos de Syscafe">Syscafe</a>
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
                                    <h3><?= escapar(tituloDocumento($ped)) ?> · <?= escapar(etiquetaEstadoPedido($ped["estado"])) ?></h3>
                                    <div class="grid-detalle">
                                        <div><span class="et">Fecha</span><?= date("d/m/Y H:i", strtotime($ped["creado_en"])) ?></div>
                                        <div><span class="et">Ejecutivo</span><?= escapar($ped["vendedor_nombre"]) ?> (ID <?= escapar($ped["vendedor_identificacion"]) ?>)</div>
                                        <div><span class="et">Tipo de documento</span><?= escapar(etiquetaTipoDocumento($ped["tipo_pedido"] ?? "pedido")) ?></div>
                                        <div><span class="et">Número</span><?= escapar(numeroDocumento($ped)) ?><?= trim((string) ($ped["numero_pedido"] ?? "")) === "" ? " (automático)" : "" ?></div>
                                        <div><span class="et">Tipo de cliente (lista de precios)</span><?= escapar(etiquetaTipoCliente($ped["tipo_cliente"] ?? "mayorista")) ?></div>
                                        <div><span class="et">Persona</span><?= ($ped["cliente_tipo_persona"] ?? "juridica") === "natural" ? "Persona natural" : "Persona jurídica" ?></div>
                                        <div><span class="et"><?= ($ped["cliente_tipo_persona"] ?? "") === "natural" ? "Nombre completo" : "Razón social" ?></span><?= escapar($ped["cliente_nombre"]) ?: "—" ?></div>
                                        <div><span class="et"><?= escapar(etiquetaDocumento($ped["cliente_tipo_persona"] ?? "juridica")) ?></span><?= escapar($ped["cliente_nit"]) ?: "—" ?></div>
                                        <div><span class="et">Nombre comercial</span><?= escapar($ped["cliente_nombre_comercial"]) ?: "—" ?></div>
                                        <div><span class="et">Teléfono</span><?= escapar($ped["cliente_telefono"]) ?: "—" ?></div>
                                        <div><span class="et">Correo</span><?= escapar($ped["cliente_email"]) ?: "—" ?></div>
                                        <div><span class="et">Departamento / municipio</span><?= escapar($ped["cliente_departamento"]) ?> / <?= escapar($ped["cliente_ciudad"]) ?></div>
                                        <div><span class="et">Barrio</span><?= escapar($ped["cliente_barrio"]) ?: "—" ?></div>
                                        <div><span class="et">Dirección de entrega</span><?= escapar($ped["cliente_direcciones"]) ?: "—" ?></div>
                                        <div><span class="et">Puntos de referencia</span><?= escapar($ped["cliente_puntos_referencia"]) ?: "—" ?></div>
                                        <div><span class="et">Forma de pago</span><?= escapar(etiquetaCondicion($ped)) ?></div>
                                        <div><span class="et">Transportadora</span><?= !empty($ped["transportadora"]) ? escapar(etiquetaTransportadora($ped["transportadora"])) : "—" ?></div>
                                        <div><span class="et">Factura electrónica</span><?= !empty($ped["factura_electronica"]) ? "Sí — " . escapar($ped["email_fe"]) : "No" ?></div>
                                        <div><span class="et">Observaciones</span><?= nl2br(escapar($ped["observaciones"])) ?: "—" ?></div>
                                    </div>
                                    <table class="tabla">
                                        <thead>
                                            <tr><th>Referencia</th><th>Producto</th><th class="num-der">Cant.</th><th class="num-der">Precio</th><th class="num-der">Subtotal</th></tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($ped["items"] as $it): $esBon = !empty($it["bonificado"]); ?>
                                                <tr<?= $esBon ? ' style="background:#FFF8E6"' : "" ?>>
                                                    <td><strong><?= escapar($it["referencia"]) ?></strong></td>
                                                    <td><?= escapar($it["nombre"]) ?><?= $esBon ? ' <span style="background:#FFF1CC;color:#8A5A00;border-radius:999px;padding:1px 8px;font-size:11px;font-weight:700;">' . ((int) $it["bonificado"] === 3 ? "APOYO A FLETE · " : ((int) $it["bonificado"] === 2 ? "DOCENA DE 13 · " : "")) . 'BONIFICADO · 100 % dto.</span>' : "" ?></td>
                                                    <td class="num-der"><?= (int) $it["cantidad"] ?></td>
                                                    <td class="num-der"><?= formatoCOP($it["precio_unitario"]) ?></td>
                                                    <td class="num-der"><?= formatoCOP($esBon ? 0 : $it["subtotal"]) ?></td>
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

<!-- MODAL CONFIRMAR ELIMINAR PEDIDO -->
<style>
    .modal-contenido.modal-confirmar { max-width: 420px; text-align: center; border-top: 5px solid var(--peligro); }
    .modal-confirmar .confirmar-icono { width: 58px; height: 58px; margin: 0 auto 12px; border-radius: 50%; background: var(--peligro-bg); display: flex; align-items: center; justify-content: center; font-size: 28px; }
    .modal-confirmar h3 { margin-bottom: 10px; }
    .modal-confirmar .confirmar-texto { margin: 0 0 8px; padding: 10px 12px; background: var(--lienzo); border-radius: var(--radio-sm); color: var(--azul-900); font-size: 14px; word-break: break-word; }
    .modal-confirmar .confirmar-extra { margin: 0 0 8px; padding: 9px 12px; background: var(--aviso-bg); border-radius: var(--radio-sm); color: var(--aviso); font-size: 13px; font-weight: 600; }
    .modal-confirmar .confirmar-aviso { margin: 0 0 18px; font-size: 13px; color: var(--tinta-suave); }
    .botones-confirmar { justify-content: center; }
    .btn-confirmar-elim { background: linear-gradient(180deg, #D63A4B, var(--peligro)); }
    .btn-confirmar-elim:hover { background: linear-gradient(180deg, #E04A5B, #A81F2E); }
</style>
<div class="modal" id="modalEliminarPed">
    <div class="modal-contenido modal-confirmar" role="alertdialog" aria-modal="true" aria-labelledby="elimPedTitulo" aria-describedby="elimPedTexto">
        <div class="confirmar-icono"><svg class="ico" aria-hidden="true"><use href="#i-alert"/></svg></div>
        <h3 id="elimPedTitulo">¿Eliminar este documento?</h3>
        <p id="elimPedTexto" class="confirmar-texto"></p>
        <p id="elimPedExtra" class="confirmar-extra" style="display:none;"></p>
        <p class="confirmar-aviso">Se borrará de la base de datos con todos sus productos y <strong>no se puede deshacer</strong>.</p>
        <form method="POST" action="admin.php" class="botones-modal botones-confirmar">
            <input type="hidden" name="accion" value="ped_eliminar">
            <input type="hidden" name="id" id="elimPedId" value="">
            <button type="button" class="btn-editar btn-cancelar-elim-ped" onclick="cerrarEliminarPedido()">Cancelar</button>
            <button type="submit" class="btn-nuevo btn-confirmar-elim">Sí, eliminar</button>
        </form>
    </div>
</div>

<!-- MODAL EDITAR TIPO Y NÚMERO -->
<div class="modal" id="modalEditarNum">
    <div class="modal-contenido" style="max-width: 440px; width: 95%;">
        <h3 id="edNumTitulo">Editar documento</h3>
        <form method="POST" action="admin.php">
            <input type="hidden" name="accion" value="ped_numero">
            <input type="hidden" name="id" id="edNumId" value="">
            <label for="edNumTipo" style="display:block; margin:10px 0 4px; font-weight:600;">Tipo de documento</label>
            <select name="tipo_documento" id="edNumTipo" style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px;">
                <?php foreach (tiposDocumentoPedido() as $clave => $infoDoc): ?>
                    <option value="<?= $clave ?>"><?= escapar($infoDoc[0]) ?></option>
                <?php endforeach; ?>
            </select>
            <label for="edNumNumero" style="display:block; margin:12px 0 4px; font-weight:600;">Número</label>
            <input type="text" name="numero" id="edNumNumero" inputmode="numeric" pattern="[0-9]*" maxlength="10" autocomplete="off"
                   style="width:100%; padding:10px; border:1px solid #ccc; border-radius:6px; box-sizing:border-box;">
            <p style="margin:6px 0 0; font-size:12px; color:#667;">Solo dígitos. Déjelo vacío para usar el número automático. Este mismo número saldrá en el PDF y en el archivo de Syscafe.</p>
            <p id="edNumAviso" style="display:none; margin:10px 0 0; padding:8px 10px; background:#FFF3E0; color:#E65100; border-radius:6px; font-size:13px; font-weight:600;">Este pedido ya está marcado como subido a Syscafe.</p>
            <div class="botones-modal" style="display:flex; justify-content:flex-end; gap:10px; margin-top:16px;">
                <button type="button" class="btn-editar" onclick="cerrarEditarNumero()">Cancelar</button>
                <button type="submit" class="btn-nuevo">Guardar</button>
            </div>
        </form>
        <p style="margin:14px 0 0; text-align:center; font-size:13px;"><a id="edNumCompleto" href="#">Editar también productos y datos del cliente →</a></p>
    </div>
</div>

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
    function pedirEliminarPedido(btn) {
        document.getElementById("elimPedId").value = btn.dataset.id;
        var t = document.getElementById("elimPedTexto");
        t.innerHTML = "";
        var b = document.createElement("strong");
        b.textContent = (btn.dataset.doc || "Pedido") + " #" + btn.dataset.num;
        t.appendChild(b);
        t.appendChild(document.createTextNode(" — " + btn.dataset.cliente + " — " + btn.dataset.total));
        var extra = document.getElementById("elimPedExtra");
        if (btn.dataset.estado === "procesado") {
            extra.textContent = "Este pedido ya está marcado como subido a Syscafe.";
            extra.style.display = "block";
        } else {
            extra.style.display = "none";
        }
        document.getElementById("modalEliminarPed").style.display = "flex";
        document.querySelector("#modalEliminarPed .btn-cancelar-elim-ped").focus();
    }
    function cerrarEliminarPedido() { document.getElementById("modalEliminarPed").style.display = "none"; }
    document.getElementById("modalEliminarPed").addEventListener("click", function (e) { if (e.target === this) { cerrarEliminarPedido(); } });

    document.addEventListener("keydown", function (e) { if (e.key === "Escape") { cerrarEliminarPedido(); cerrarEditarNumero(); } });

    function abrirEditarNumero(btn) {
        document.getElementById("edNumId").value = btn.dataset.id;
        document.getElementById("edNumTipo").value = btn.dataset.tipo;
        var n = document.getElementById("edNumNumero");
        n.value = btn.dataset.numero;
        n.placeholder = "Automático: " + btn.dataset.auto;
        document.getElementById("edNumTitulo").textContent = "Editar documento (id " + btn.dataset.auto + ")";
        document.getElementById("edNumAviso").style.display = btn.dataset.estado === "procesado" ? "block" : "none";
        document.getElementById("edNumCompleto").href = "admin_pedido_editar.php?id=" + encodeURIComponent(btn.dataset.id);
        document.getElementById("modalEditarNum").style.display = "flex";
        n.focus();
        n.select();
    }
    function cerrarEditarNumero() { document.getElementById("modalEditarNum").style.display = "none"; }
    document.getElementById("modalEditarNum").addEventListener("click", function (e) { if (e.target === this) { cerrarEditarNumero(); } });
    document.getElementById("edNumNumero").addEventListener("input", function () {
        var l = this.value.replace(/\D+/g, "").slice(0, 10);
        if (l !== this.value) { this.value = l; }
    });

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
