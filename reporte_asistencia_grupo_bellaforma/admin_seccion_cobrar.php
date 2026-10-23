<?php
/* Sección "Pedidos por cobrar" del panel admin (se incluye dentro de admin.php). */

require_once __DIR__ . "/cobros_lib.php";
asegurarTablaCobros($conexion);

$ordenesCobro = [
    "dias_menos"  => "Menos días por pagar (los más urgentes primero)",
    "dias_mas"    => "Más días por pagar (los más lejanos primero)",
    "valor_mayor" => "Mayor valor del pedido",
    "valor_menor" => "Menor valor del pedido",
];
$estadosCobro = [
    "por_cobrar"      => "Por cobrar (todos los pendientes)",
    "con_comprobante" => "Con comprobante (por confirmar)",
    "sin_comprobante" => "Sin comprobante",
    "cobrados"        => "Ya cobrados",
];

$filtrosCob = [
    "buscar"       => trim((string) ($_GET["buscar_c"] ?? "")),
    "desde"        => (string) ($_GET["desde_c"] ?? ""),
    "hasta"        => (string) ($_GET["hasta_c"] ?? ""),
    "vendedor"     => (int) ($_GET["vendedor_c"] ?? 0),
    "estado_cobro" => isset($estadosCobro[$_GET["estado_c"] ?? ""]) ? (string) $_GET["estado_c"] : "por_cobrar",
    "orden"        => isset($ordenesCobro[$_GET["orden_c"] ?? ""]) ? (string) $_GET["orden_c"] : "dias_menos",
];

$pedidosCobro = cobroObtenerPedidos($conexion, $filtrosCob);

// Conteo general (sin filtros): lo que de verdad falta por cobrar
$todosPendientes = cobroObtenerPedidos($conexion, ["estado_cobro" => "por_cobrar"]);
$cobTotalPend = 0.0;
$cobVencidos = 0;
$cobConComp = 0;
foreach ($todosPendientes as $pc) {
    $cobTotalPend += (float) $pc["total"];
    if ($pc["plazo"]["restantes"] < 0) {
        $cobVencidos++;
    }
    if ($pc["tiene_comprobante"]) {
        $cobConComp++;
    }
}
$cobSumaLista = 0.0;
foreach ($pedidosCobro as $pc) {
    $cobSumaLista += (float) $pc["total"];
}

$listaVendedoresCobro = [];
$resLVC = $conexion->query("SELECT id, nombre FROM vendedores ORDER BY nombre ASC");
if ($resLVC) {
    $listaVendedoresCobro = $resLVC->fetch_all(MYSQLI_ASSOC);
}
$qsCobro = (string) ($_SERVER["QUERY_STRING"] ?? "");
?>
<style>
    .cb-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin: 0 0 16px; }
    .cb-kpi { background: #fff; border: 1px solid var(--linea); border-radius: 14px; padding: 14px 16px; box-shadow: var(--sombra-sm); }
    .cb-kpi span { display: block; font-size: 12px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--tinta-suave); }
    .cb-kpi strong { display: block; margin-top: 4px; font-size: 24px; color: var(--azul-900); font-variant-numeric: tabular-nums; }
    .cb-kpi.rojo strong { color: #A0192B; }
    .cb-kpi.verde strong { color: var(--verde-700); }
    .cb-badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; }
    .cb-b-ok { background: #E0F4F8; color: #0E6A7E; }
    .cb-b-pronto { background: #FFF4D6; color: #8A5F00; }
    .cb-b-hoy { background: #FFE3CC; color: #B24A00; }
    .cb-b-vencido { background: #FDEBED; color: #A0192B; }
    .tabla tbody tr.cb-ok td { background: #E6F7EA; }
    .tabla tbody tr.cb-ok td:first-child { box-shadow: inset 4px 0 0 var(--verde-500); }
    .tabla tbody tr.cb-ok:hover td { background: #D9F1DF; }
    .tabla tbody tr.cb-cobrado td { opacity: .6; }
    .cb-comp { display: inline-flex; align-items: center; gap: 6px; padding: 6px 12px; border-radius: 999px; border: 1px solid #9ED6A8; background: #fff; color: var(--verde-700); font-weight: 700; font-size: 12.5px; cursor: pointer; }
    .cb-comp:hover { background: #F2FBF4; }
    .cb-nuevo { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 999px; background: #C62839; color: #fff; font-size: 10.5px; font-weight: 800; letter-spacing: .04em; vertical-align: middle; }
    .cb-sin { color: var(--tinta-tenue); font-size: 12.5px; }
    .cb-visor { position: fixed; inset: 0; z-index: 9999; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(10, 20, 40, .72); }
    .cb-visor.abierto { display: flex; }
    .cb-visor-caja { display: flex; flex-direction: column; gap: 12px; width: min(94vw, 780px); max-height: 94vh; padding: 14px; border-radius: 14px; background: #fff; }
    .cb-visor-caja h4 { margin: 0; font-size: 15px; color: #1B2A41; }
    .cb-visor-caja img { max-width: 100%; max-height: 72vh; object-fit: contain; border-radius: 8px; align-self: center; }
    .cb-visor-caja iframe { width: 100%; height: 72vh; border: 1px solid var(--linea); border-radius: 8px; }
    .cb-visor-btns { display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }
    .cb-visor-btns a, .cb-visor-btns button { padding: 9px 16px; border-radius: 8px; border: 1px solid var(--linea); background: #fff; color: var(--tinta); font-weight: 600; font-size: 13.5px; text-decoration: none; cursor: pointer; }
    .cb-btn-cobrar { background: #1D6F42; color: #fff; border: none; }
    .cb-btn-cobrar:hover { filter: brightness(1.1); }
</style>

<section id="por_cobrar" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2><svg class="ico" aria-hidden="true"><use href="#i-wallet"/></svg> Pedidos por cobrar</h2>
            <p>Pedidos que los ejecutivos vendieron a crédito y todavía no se han cobrado. Los días corren desde la fecha del pedido. Cuando el ejecutivo sube el comprobante de pago, la fila se pone en <b style="color:#1D6821;">verde</b> y le llega el aviso arriba: revíselo y márquelo como cobrado para que salga de la lista.</p>
        </div>
    </div>

    <div class="cb-kpis">
        <div class="cb-kpi"><span>Pedidos por cobrar</span><strong><?= count($todosPendientes) ?></strong></div>
        <div class="cb-kpi"><span>Valor por cobrar</span><strong><?= formatoCOP($cobTotalPend) ?></strong></div>
        <div class="cb-kpi rojo"><span>Vencidos</span><strong><?= $cobVencidos ?></strong></div>
        <div class="cb-kpi verde"><span>Con comprobante por confirmar</span><strong><?= $cobConComp ?></strong></div>
    </div>

    <form method="GET" action="admin.php#por_cobrar" class="filtros">
        <input type="hidden" name="seccion" value="por_cobrar">
        <div>
            <label for="buscar_c">Cliente / NIT / N° pedido</label>
            <input type="text" id="buscar_c" name="buscar_c" value="<?= escapar($filtrosCob["buscar"]) ?>" placeholder="Ej.: 900123456">
        </div>
        <div>
            <label for="desde_c">Pedido desde</label>
            <input type="date" id="desde_c" name="desde_c" value="<?= escapar($filtrosCob["desde"]) ?>">
        </div>
        <div>
            <label for="hasta_c">Pedido hasta</label>
            <input type="date" id="hasta_c" name="hasta_c" value="<?= escapar($filtrosCob["hasta"]) ?>">
        </div>
        <div>
            <label for="vendedor_c">Ejecutivo</label>
            <select id="vendedor_c" name="vendedor_c">
                <option value="0">Todos</option>
                <?php foreach ($listaVendedoresCobro as $lv): ?>
                    <option value="<?= (int) $lv["id"] ?>" <?= $filtrosCob["vendedor"] === (int) $lv["id"] ? "selected" : "" ?>><?= escapar($lv["nombre"]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="estado_c">Mostrar</label>
            <select id="estado_c" name="estado_c">
                <?php foreach ($estadosCobro as $clave => $texto): ?>
                    <option value="<?= $clave ?>" <?= $filtrosCob["estado_cobro"] === $clave ? "selected" : "" ?>><?= escapar($texto) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="orden_c">Ordenar por</label>
            <select id="orden_c" name="orden_c">
                <?php foreach ($ordenesCobro as $clave => $texto): ?>
                    <option value="<?= $clave ?>" <?= $filtrosCob["orden"] === $clave ? "selected" : "" ?>><?= escapar($texto) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-filtrar">Filtrar</button>
    </form>

    <p style="margin:0 0 10px; font-size:14px;">
        Mostrando <strong><?= count($pedidosCobro) ?></strong> pedidos · Suma: <strong><?= formatoCOP($cobSumaLista) ?></strong>
    </p>

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Pedido</th>
                    <th>Fecha del pedido</th>
                    <th>Ejecutivo</th>
                    <th>Cliente</th>
                    <th>Crédito</th>
                    <th>Vence</th>
                    <th>Días restantes</th>
                    <th class="num-der">Total</th>
                    <th>Comprobante</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$pedidosCobro): ?>
                    <tr><td colspan="10" class="sin-resultados">No hay pedidos por cobrar con estos filtros.</td></tr>
                <?php else: ?>
                    <?php foreach ($pedidosCobro as $pc):
                        $pl = $pc["plazo"];
                        $claseFila = $pc["cobrado"] ? "cb-cobrado" : ($pc["tiene_comprobante"] ? "cb-ok" : "");
                        ?>
                        <tr class="<?= $claseFila ?>" id="cb-fila-<?= (int) $pc["id"] ?>">
                            <td><strong>#<?= escapar(numeroDocumento($pc)) ?></strong></td>
                            <td><?= date("d/m/Y", strtotime($pl["base"])) ?></td>
                            <td><?= escapar($pc["vendedor_nombre"]) ?></td>
                            <td>
                                <?= escapar($pc["cliente_nombre"]) ?: "—" ?>
                                <?php if (!empty($pc["cliente_nombre_comercial"])): ?><br><small style="color:#667;"><?= escapar($pc["cliente_nombre_comercial"]) ?></small><?php endif; ?>
                                <?php if (!empty($pc["cliente_nit"])): ?><br><small style="color:#667;"><?= escapar(etiquetaDocumento($pc["cliente_tipo_persona"] ?? "juridica")) ?> <?= escapar($pc["cliente_nit"]) ?></small><?php endif; ?>
                            </td>
                            <td><?= (int) $pl["dias"] ?> días</td>
                            <td><?= date("d/m/Y", strtotime($pl["vence"])) ?></td>
                            <td>
                                <?php if ($pc["cobrado"]): ?>
                                    <span class="cb-badge cb-b-ok">Cobrado<?= !empty($pc["cb_cobrado_en"]) ? " · " . date("d/m/Y", strtotime($pc["cb_cobrado_en"])) : "" ?></span>
                                <?php else: ?>
                                    <span class="cb-badge cb-b-<?= cobroClaseRestantes($pl["restantes"]) ?>"><?= escapar(cobroTextoRestantes($pl["restantes"])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="num-der"><?= formatoCOP($pc["total"]) ?></td>
                            <td>
                                <?php if ($pc["tiene_comprobante"]): ?>
                                    <button type="button" class="cb-comp" data-id="<?= (int) $pc["id"] ?>" data-mime="<?= escapar($pc["cb_mime"]) ?>"
                                            data-titulo="Pedido #<?= escapar(numeroDocumento($pc)) ?> · <?= escapar($pc["cliente_nombre"]) ?>">
                                        <svg class="ico" aria-hidden="true" style="width:14px;height:14px;"><use href="#i-paperclip"/></svg> Ver comprobante
                                    </button>
                                    <?php if (!$pc["comprobante_visto"] && !$pc["cobrado"]): ?><span class="cb-nuevo" id="cb-nuevo-<?= (int) $pc["id"] ?>">NUEVO</span><?php endif; ?>
                                    <?php if (!empty($pc["cb_subido_en"])): ?><br><small class="cb-sin">Subido <?= date("d/m/Y H:i", strtotime($pc["cb_subido_en"])) ?></small><?php endif; ?>
                                <?php else: ?>
                                    <span class="cb-sin">Sin comprobante</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="POST" action="admin.php" class="form-inline"
                                      <?php if (!$pc["cobrado"]): ?>onsubmit="return confirm('¿Marcar este pedido como cobrado? Saldrá de la lista de pendientes.');"<?php endif; ?>>
                                    <input type="hidden" name="accion" value="<?= $pc["cobrado"] ? "cob_reabrir" : "cob_cobrado" ?>">
                                    <input type="hidden" name="id" value="<?= (int) $pc["id"] ?>">
                                    <input type="hidden" name="qs" value="<?= escapar($qsCobro) ?>">
                                    <?php if ($pc["cobrado"]): ?>
                                        <button type="submit" class="btn-editar btn-fila">Reabrir</button>
                                    <?php else: ?>
                                        <button type="submit" class="btn-nuevo btn-fila cb-btn-cobrar">Marcar cobrado</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div class="cb-visor" id="cbVisor">
    <div class="cb-visor-caja">
        <h4 id="cbVisorTitulo">Comprobante de pago</h4>
        <div id="cbVisorCuerpo"></div>
        <div class="cb-visor-btns">
            <a id="cbVisorBajar" download>Descargar</a>
            <button type="button" id="cbVisorCerrar">Cerrar</button>
        </div>
    </div>
</div>

<script>
    (function () {
        var visor = document.getElementById("cbVisor");
        if (!visor) { return; }
        function cerrar() { visor.classList.remove("abierto"); document.getElementById("cbVisorCuerpo").innerHTML = ""; }
        document.getElementById("cbVisorCerrar").addEventListener("click", cerrar);
        visor.addEventListener("click", function (e) { if (e.target === visor) { cerrar(); } });
        document.addEventListener("keydown", function (e) { if (e.key === "Escape") { cerrar(); } });

        document.querySelectorAll(".cb-comp").forEach(function (b) {
            b.addEventListener("click", function () {
                var id = b.dataset.id;
                var url = "cobro_comprobante.php?id=" + encodeURIComponent(id) + "&t=" + Date.now();
                var cuerpo = document.getElementById("cbVisorCuerpo");
                cuerpo.innerHTML = "";
                var el;
                if ((b.dataset.mime || "").indexOf("pdf") !== -1) {
                    el = document.createElement("iframe");
                } else {
                    el = document.createElement("img");
                    el.alt = "Comprobante de pago";
                }
                el.src = url;
                cuerpo.appendChild(el);
                document.getElementById("cbVisorTitulo").textContent = "Comprobante de pago · " + (b.dataset.titulo || "");
                document.getElementById("cbVisorBajar").href = "cobro_comprobante.php?id=" + encodeURIComponent(id) + "&descargar=1";
                visor.classList.add("abierto");

                // Al abrirlo se considera visto: se quita la etiqueta NUEVO y baja la campanita
                var marca = document.getElementById("cb-nuevo-" + id);
                if (marca) {
                    var fd = new FormData();
                    fd.append("op", "visto");
                    fd.append("pedido_id", id);
                    fetch("cobro_comprobante.php", { method: "POST", body: fd, credentials: "same-origin", headers: { "X-Requested-With": "fetch" } })
                        .then(function (r) { return r.json(); })
                        .then(function (j) {
                            if (j && j.ok) {
                                marca.remove();
                                if (window.bfActualizarCampanaCobros) { window.bfActualizarCampanaCobros(j.nuevos); }
                            }
                        }).catch(function () {});
                }
            });
        });
    })();
</script>
