<?php
/* Sección "Control de despachos" del panel admin (se incluye dentro de admin.php). */
require_once __DIR__ . "/despachos_lib.php";

$hoyDesp = date("Y-m-d");
$fechaDesp = (string) ($_GET["fecha_d"] ?? $hoyDesp);
if (!fechaDespachoValida($fechaDesp)) {
    $fechaDesp = $hoyDesp;
}
$tsDesp = strtotime($fechaDesp);
$diaAnteriorDesp = date("Y-m-d", strtotime($fechaDesp . " -1 day"));
$diaSiguienteDesp = date("Y-m-d", strtotime($fechaDesp . " +1 day"));
$nombresDiaDesp = ["DOMINGO", "LUNES", "MARTES", "MIÉRCOLES", "JUEVES", "VIERNES", "SÁBADO"];
$tituloDiaDesp = $nombresDiaDesp[(int) date("w", $tsDesp)] . " " . date("d/m/Y", $tsDesp);

$errorDesp = "";
try {
    $despachosDia = obtenerDespachosDelDia($conexion, $fechaDesp);
} catch (Throwable $e) {
    $despachosDia = [];
    $errorDesp = "No se pudo cargar el control de despachos. Recargue la página.";
}

$totalValorDesp = 0.0;
$cuentaDesp = 0;
foreach ($despachosDia as $dd) {
    if (!$dd["anulado"]) {
        $cuentaDesp++;
        $totalValorDesp += (float) ($dd["valor"] ?? 0);
    }
}

$nombresVendedoresDesp = [];
$resVD = $conexion->query("SELECT nombre FROM vendedores ORDER BY nombre ASC");
if ($resVD) {
    foreach ($resVD->fetch_all(MYSQLI_ASSOC) as $vv) {
        $nombresVendedoresDesp[] = $vv["nombre"];
    }
}

/** Lista desplegable que guarda sola al cambiar (edición en línea). */
function despSelectHtml(int $id, string $campo, array $catalogo, string $actual, bool $permiteVacio = true): string
{
    $h = '<select class="desp-in" data-id="' . $id . '" data-campo="' . escapar($campo) . '" data-prev="' . escapar($actual) . '">';
    if ($permiteVacio || $actual === "") {
        $h .= '<option value="">—</option>';
    }
    foreach ($catalogo as $k => $etiqueta) {
        $h .= '<option value="' . escapar($k) . '"' . ((string) $k === $actual ? " selected" : "") . ">" . escapar($etiqueta) . "</option>";
    }
    if ($actual !== "" && !isset($catalogo[$actual])) {   // dato histórico que no está en el catálogo
        $h .= '<option value="' . escapar($actual) . '" selected>' . escapar(etiquetaDeCatalogo($catalogo, $actual)) . "</option>";
    }
    return $h . "</select>";
}

/** Caja de texto que guarda sola al salir de ella (edición en línea). */
function despInputHtml(int $id, string $campo, string $valor, int $max, string $extra = ""): string
{
    return '<input type="text" class="desp-in ' . $extra . '" data-id="' . $id . '" data-campo="' . escapar($campo) . '" data-prev="' . escapar($valor)
        . '" value="' . escapar($valor) . '" maxlength="' . $max . '" autocomplete="off">';
}

$qsDiaDesp = fn(string $f) => "admin.php?fecha_d=" . $f . "#despachos";
?>
<style>
    #despachos .desp-nav { display: flex; flex-wrap: wrap; gap: 10px; align-items: end; margin: 0 0 16px; }
    #despachos .desp-nav .btn-dia { display: inline-flex; align-items: center; min-height: 44px; padding: 0 16px; border-radius: 8px; background: #EDF1F7; color: var(--tinta); font-weight: 600; font-size: 14px; text-decoration: none; }
    #despachos .desp-nav .btn-dia:hover { background: #DFE6F0; }
    #despachos .desp-nav form { display: flex; gap: 8px; align-items: end; margin-left: 6px; }
    #despachos .desp-nav label { display: block; margin-bottom: 6px; font-size: 13px; font-weight: 600; }
    #despachos .desp-nav input[type=date] { min-height: 44px; padding: 8px 12px; border: 1.5px solid var(--linea); border-radius: var(--radio-sm); font-size: 14px; background: var(--superficie); }
    #despachos .desp-titulo { margin: 0 0 4px; font-size: 18px; letter-spacing: .3px; color: var(--azul-900); }
    #despachos .desp-resumen { margin: 0 0 12px; font-size: 14px; }
    #despachos .tabla-desp { min-width: 1900px; }
    #despachos .tabla-desp th { padding: 10px 8px; font-size: 12px; }
    #despachos .tabla-desp td { padding: 7px 8px; font-size: 12.5px; vertical-align: middle; }
    #despachos .tabla-desp td.nw { white-space: nowrap; }
    #despachos .tabla-desp td.num { text-align: right; white-space: nowrap; }
    #despachos .desp-in { width: 100%; min-width: 96px; padding: 6px 7px; border: 1.5px solid transparent; border-radius: 6px; background: #F6F8FC; font-size: 12.5px; color: inherit; }
    #despachos .desp-in:hover { border-color: var(--linea); }
    #despachos .desp-in:focus { outline: none; border-color: var(--azul-500); background: #fff; }
    #despachos .desp-in.guardando { border-color: #F0B429; }
    #despachos .desp-in.ok { border-color: #2E9E5B; background: #EAF7F0; }
    #despachos .desp-in.err { border-color: var(--peligro); background: var(--peligro-bg); }
    #despachos .desp-in.guia { min-width: 120px; }
    #despachos .desp-in.flete { min-width: 84px; text-align: right; }
    #despachos .desp-in.factura { min-width: 110px; }
    #despachos tr.desp-anulado td { opacity: .5; text-decoration: line-through; }
    #despachos tr.desp-anulado td:last-child, #despachos tr.desp-anulado td .desp-in { text-decoration: none; }
    #despachos .desp-tag { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 11px; font-weight: 700; background: #E3F2FD; color: #0D47A1; }
    #despachos .desp-tag.remision { background: #FFF3E0; color: #E65100; }
    #despachos .desp-tag.cotizacion { background: #F3E5F5; color: #6A1B9A; }
    #despachos .desp-origen { display: block; margin-top: 2px; font-size: 11px; color: #667; }
    #despachos .desp-total td { background: #FFF9C4; font-weight: 700; }
    #despachos .desp-vacio { padding: 26px; text-align: center; color: #667; }
    #despachos .desp-acc { white-space: nowrap; }
    #desp-modal-form .desp-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px 12px; }
    #desp-modal-form .desp-grid .ancho { grid-column: 1 / -1; }
    #desp-modal-form label { display: block; margin: 0 0 4px; font-size: 13px; font-weight: 600; }
    #desp-modal-form input, #desp-modal-form select { width: 100%; padding: 9px 10px; border: 1px solid #ccc; border-radius: 6px; box-sizing: border-box; font-size: 14px; }
    @media (max-width: 620px) { #desp-modal-form .desp-grid { grid-template-columns: 1fr; } }
</style>

<section id="despachos" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2>🚚 Control de despachos</h2>
            <p>Cada pedido, remisión o cotización que un ejecutivo envía aparece aquí el día en que se envía, con la forma de pago y la transportadora que él eligió. Usted completa empresa, n.º de factura, guía, flete y estados directamente en la tabla (se guarda solo).</p>
        </div>
        <button type="button" class="btn-nuevo" onclick="abrirDespacho(null)">+ Agregar despacho</button>
    </div>

    <?php if ($errorDesp !== ""): ?>
        <div class="mensaje error"><?= escapar($errorDesp) ?></div>
    <?php endif; ?>

    <div class="desp-nav">
        <a class="btn-dia" href="<?= escapar($qsDiaDesp($diaAnteriorDesp)) ?>">← Día anterior</a>
        <form method="GET" action="admin.php#despachos">
            <div>
                <label for="fecha_d">Buscar otro día</label>
                <input type="date" id="fecha_d" name="fecha_d" value="<?= escapar($fechaDesp) ?>" required>
            </div>
            <button type="submit" class="btn-filtrar">Ver</button>
        </form>
        <?php if ($fechaDesp !== $hoyDesp): ?>
            <a class="btn-dia" href="<?= escapar($qsDiaDesp($hoyDesp)) ?>">Hoy</a>
        <?php endif; ?>
        <?php if ($fechaDesp < $hoyDesp): ?>
            <a class="btn-dia" href="<?= escapar($qsDiaDesp($diaSiguienteDesp)) ?>">Día siguiente →</a>
        <?php endif; ?>
    </div>

    <h3 class="desp-titulo">CONTROL DE DESPACHOS DIARIOS — <?= escapar($tituloDiaDesp) ?></h3>
    <p class="desp-resumen">
        <strong><?= (int) $cuentaDesp ?></strong> <?= $cuentaDesp === 1 ? "despacho" : "despachos" ?> ·
        Valor total: <strong><?= formatoCOP($totalValorDesp) ?></strong>
    </p>

    <div class="tabla-contenedor">
        <table class="tabla tabla-desp">
            <thead>
                <tr>
                    <th>No.</th>
                    <th>Fecha</th>
                    <th>NIT / CC</th>
                    <th>Nombre del cliente</th>
                    <th>Nombre comercial / persona natural</th>
                    <th>Ciudad</th>
                    <th>Departamento</th>
                    <th>Empresa</th>
                    <th># Factura</th>
                    <th>Documento</th>
                    <th class="num-der">Valor</th>
                    <th>Vendedor</th>
                    <th>Forma de pago</th>
                    <th>Transportadora</th>
                    <th>N.º de guía</th>
                    <th>Flete</th>
                    <th class="num-der">Valor del flete</th>
                    <th>Estado de entrega</th>
                    <th>Estado de pago</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$despachosDia): ?>
                    <tr><td colspan="20" class="desp-vacio">No hay despachos registrados el <?= escapar(date("d/m/Y", $tsDesp)) ?>.</td></tr>
                <?php else: ?>
                    <?php foreach ($despachosDia as $i => $d): ?>
                        <?php $did = (int) $d["id"]; $ligado = $d["pedido_id"] !== null; ?>
                        <tr class="<?= $d["anulado"] ? "desp-anulado" : "" ?>">
                            <td class="nw"><?= $i + 1 ?></td>
                            <td class="nw"><?= escapar(date("d/m/Y", strtotime($d["fecha"]))) ?></td>
                            <td class="nw"><?= escapar($d["nit"]) ?></td>
                            <td><?= escapar($d["cliente"]) ?></td>
                            <td><?= escapar($d["comercial"]) ?></td>
                            <td><?= escapar($d["ciudad"]) ?></td>
                            <td><?= escapar($d["departamento"]) ?></td>
                            <td><?= despSelectHtml($did, "empresa", empresasDespacho(), (string) $d["empresa"]) ?></td>
                            <td><?= despInputHtml($did, "factura", (string) $d["factura"], 60, "factura") ?></td>
                            <td class="nw">
                                <span class="desp-tag <?= escapar($d["tipo"]) ?>"><?= escapar(etiquetaTipoDocumento($d["tipo"])) ?></span>
                                <?php if ($ligado): ?>
                                    <span class="desp-origen">#<?= escapar($d["numero_doc"]) ?><?= $d["anulado"] ? " · anulado" : "" ?></span>
                                <?php else: ?>
                                    <span class="desp-origen">manual</span>
                                <?php endif; ?>
                            </td>
                            <td class="num"><?= $d["valor"] !== null ? formatoCOP($d["valor"]) : "" ?></td>
                            <td><?= escapar($d["vendedor"]) ?></td>
                            <td><?= despSelectHtml($did, "forma_pago", formasPagoDespacho(), (string) $d["forma_pago"], !$ligado || (string) $d["forma_pago"] === "") ?></td>
                            <td><?= despSelectHtml($did, "transportadora", transportadorasDespacho(), (string) $d["transportadora"], !$ligado || (string) $d["transportadora"] === "") ?></td>
                            <td><?= despInputHtml($did, "numero_guia", (string) $d["numero_guia"], 40, "guia") ?></td>
                            <td><?= despSelectHtml($did, "flete_tipo", fletesTipoDespacho(), (string) $d["flete_tipo"]) ?></td>
                            <td><?= despInputHtml($did, "valor_flete", $d["valor_flete"] !== null ? number_format($d["valor_flete"], 0, ",", ".") : "", 14, "flete") ?></td>
                            <td><?= despSelectHtml($did, "estado_entrega", estadosEntregaDespacho(), (string) $d["estado_entrega"]) ?></td>
                            <td><?= despSelectHtml($did, "estado_pago", estadosPagoDespacho(), (string) $d["estado_pago"]) ?></td>
                            <td class="desp-acc">
                                <?php if (!$ligado): ?>
                                    <button type="button" class="btn-editar btn-fila"
                                        data-id="<?= $did ?>"
                                        data-fecha="<?= escapar($d["fecha"]) ?>"
                                        data-tipo="<?= escapar($d["tipo"]) ?>"
                                        data-nit="<?= escapar($d["nit"]) ?>"
                                        data-cliente="<?= escapar($d["cliente"]) ?>"
                                        data-comercial="<?= escapar($d["comercial"]) ?>"
                                        data-ciudad="<?= escapar($d["ciudad"]) ?>"
                                        data-depto="<?= escapar($d["departamento"]) ?>"
                                        data-valor="<?= $d["valor"] !== null ? escapar((string) round($d["valor"])) : "" ?>"
                                        data-vendedor="<?= escapar($d["vendedor"]) ?>"
                                        data-forma="<?= escapar($d["forma_pago"]) ?>"
                                        data-transp="<?= escapar($d["transportadora"]) ?>"
                                        data-empresa="<?= escapar($d["empresa"]) ?>"
                                        data-factura="<?= escapar($d["factura"]) ?>"
                                        onclick="abrirDespacho(this)">Editar</button>
                                <?php else: ?>
                                    <button type="button" class="btn-editar btn-fila" onclick="verPedidoDesdeDespacho(<?= (int) $d["pedido_id"] ?>)">Ver pedido</button>
                                <?php endif; ?>
                                <button type="button" class="btn-eliminar btn-fila"
                                    data-id="<?= $did ?>"
                                    data-ligado="<?= $ligado ? "1" : "0" ?>"
                                    data-texto="<?= escapar(($d["cliente"] !== "" ? $d["cliente"] : "Sin cliente") . ($d["factura"] !== "" ? " · " . $d["factura"] : "")) ?>"
                                    onclick="pedirQuitarDespacho(this)"><?= $ligado ? "Quitar" : "Eliminar" ?></button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <tr class="desp-total">
                        <td colspan="10" class="num">TOTAL</td>
                        <td class="num"><?= formatoCOP($totalValorDesp) ?></td>
                        <td colspan="9"></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<!-- MODAL AGREGAR / EDITAR DESPACHO MANUAL -->
<div class="modal" id="modalDespacho">
    <div class="modal-contenido" style="max-width: 640px; width: 95%; max-height: 92vh; overflow-y: auto;">
        <h3 id="despModalTitulo">Agregar despacho</h3>
        <form method="POST" action="admin.php" id="desp-modal-form">
            <input type="hidden" name="accion" value="desp_guardar">
            <input type="hidden" name="id" id="despId" value="">
            <input type="hidden" name="fecha_vista" value="<?= escapar($fechaDesp) ?>">
            <div class="desp-grid">
                <div>
                    <label for="despFecha">Fecha *</label>
                    <input type="date" name="fecha" id="despFecha" required>
                </div>
                <div>
                    <label for="despTipo">Documento</label>
                    <select name="tipo_documento" id="despTipo">
                        <?php foreach (tiposDocumentoPedido() as $clave => $infoDoc): ?>
                            <option value="<?= $clave ?>"><?= escapar($infoDoc[0]) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="despNit">NIT / CC</label>
                    <input type="text" name="nit" id="despNit" maxlength="40" autocomplete="off">
                </div>
                <div>
                    <label for="despCliente">Nombre del cliente *</label>
                    <input type="text" name="cliente_nombre" id="despCliente" maxlength="200" autocomplete="off" required>
                </div>
                <div class="ancho">
                    <label for="despComercial">Nombre comercial / persona natural</label>
                    <input type="text" name="nombre_comercial" id="despComercial" maxlength="200" autocomplete="off">
                </div>
                <div>
                    <label for="despCiudad">Ciudad</label>
                    <input type="text" name="ciudad" id="despCiudad" maxlength="100" autocomplete="off">
                </div>
                <div>
                    <label for="despDepto">Departamento</label>
                    <input type="text" name="departamento" id="despDepto" maxlength="80" autocomplete="off">
                </div>
                <div>
                    <label for="despEmpresa">Empresa</label>
                    <select name="empresa" id="despEmpresa">
                        <option value="">—</option>
                        <?php foreach (empresasDespacho() as $k => $l): ?>
                            <option value="<?= escapar($k) ?>"><?= escapar($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="despFactura"># Factura</label>
                    <input type="text" name="factura" id="despFactura" maxlength="60" autocomplete="off">
                </div>
                <div>
                    <label for="despValor">Valor</label>
                    <input type="text" name="valor" id="despValor" inputmode="numeric" autocomplete="off" placeholder="Ej: 150000">
                </div>
                <div>
                    <label for="despVendedor">Vendedor</label>
                    <input type="text" name="vendedor_nombre" id="despVendedor" maxlength="150" autocomplete="off" list="despListaVendedores">
                    <datalist id="despListaVendedores">
                        <?php foreach ($nombresVendedoresDesp as $nv): ?>
                            <option value="<?= escapar($nv) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label for="despForma">Forma de pago</label>
                    <select name="forma_pago" id="despForma">
                        <option value="">—</option>
                        <?php foreach (formasPagoDespacho() as $k => $l): ?>
                            <option value="<?= escapar($k) ?>"><?= escapar($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div>
                    <label for="despTransp">Transportadora</label>
                    <select name="transportadora" id="despTransp">
                        <option value="">—</option>
                        <?php foreach (transportadorasDespacho() as $k => $l): ?>
                            <option value="<?= escapar($k) ?>"><?= escapar($l) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <p style="margin:10px 0 0; font-size:12px; color:#667;">La guía, el flete y los estados se llenan directamente en la tabla.</p>
            <div class="botones-modal" style="display:flex; justify-content:flex-end; gap:10px; margin-top:16px;">
                <button type="button" class="btn-editar" onclick="cerrarDespacho()">Cancelar</button>
                <button type="submit" class="btn-nuevo">Guardar</button>
            </div>
        </form>
    </div>
</div>

<!-- MODAL CONFIRMAR QUITAR DESPACHO -->
<div class="modal" id="modalQuitarDespacho">
    <div class="modal-contenido" style="max-width: 420px; width: 95%; text-align: center;">
        <h3 id="quitarDespTitulo">¿Quitar este despacho?</h3>
        <p id="quitarDespTexto" style="margin:0 0 8px; padding:10px 12px; background:var(--lienzo); border-radius:8px; word-break:break-word;"></p>
        <p id="quitarDespNota" style="margin:0 0 16px; font-size:13px; color:#667;"></p>
        <form method="POST" action="admin.php" class="botones-modal" style="display:flex; justify-content:center; gap:10px;">
            <input type="hidden" name="accion" value="desp_eliminar">
            <input type="hidden" name="id" id="quitarDespId" value="">
            <input type="hidden" name="fecha_vista" value="<?= escapar($fechaDesp) ?>">
            <button type="button" class="btn-editar" onclick="cerrarQuitarDespacho()">Cancelar</button>
            <button type="submit" class="btn-nuevo" style="background:linear-gradient(180deg,#D63A4B,var(--peligro));">Sí, continuar</button>
        </form>
    </div>
</div>

<script>
(function () {
    var FECHA_VISTA = <?= json_encode($fechaDesp) ?>;

    /* ---------- Edición en línea: cada celda se guarda sola ---------- */
    function formatoPesos(n) { return Number(n).toLocaleString("es-CO", { maximumFractionDigits: 0 }); }

    function guardarCelda(el) {
        var fd = new FormData();
        fd.append("accion", "desp_campo");
        fd.append("id", el.dataset.id);
        fd.append("campo", el.dataset.campo);
        fd.append("valor", el.value);
        el.classList.remove("ok", "err");
        el.classList.add("guardando");
        fetch("admin.php", { method: "POST", body: fd, credentials: "same-origin" })
            .then(function (r) { return r.text(); })
            .then(function (t) {
                var j = null;
                try { j = JSON.parse(t); } catch (e) { j = null; }
                el.classList.remove("guardando");
                if (j && j.ok) {
                    if (el.dataset.campo === "valor_flete") { el.value = (j.valor === null || j.valor === undefined) ? "" : formatoPesos(j.valor); }
                    el.dataset.prev = el.value;
                    el.classList.add("ok");
                    setTimeout(function () { el.classList.remove("ok"); }, 1400);
                } else {
                    el.classList.add("err");
                    el.value = el.dataset.prev || "";
                    alert((j && j.error) || "No se pudo guardar. Revise la conexión o vuelva a iniciar sesión.");
                    setTimeout(function () { el.classList.remove("err"); }, 1800);
                }
            })
            .catch(function () {
                el.classList.remove("guardando");
                el.classList.add("err");
                el.value = el.dataset.prev || "";
                alert("Sin conexión: el cambio no se guardó.");
            });
    }
    var tabla = document.querySelector("#despachos .tabla-desp");
    if (tabla) {
        tabla.addEventListener("change", function (e) {
            var el = e.target;
            if (el.classList && el.classList.contains("desp-in")) { guardarCelda(el); }
        });
        tabla.addEventListener("keydown", function (e) {
            if (e.key === "Enter" && e.target.tagName === "INPUT" && e.target.classList.contains("desp-in")) { e.preventDefault(); e.target.blur(); }
        });
    }

    /* ---------- Agregar / editar despacho manual ---------- */
    function ponerSelect(sel, valor) {
        // Un dato histórico que no está en la lista se conserva como opción extra
        for (var i = sel.options.length - 1; i >= 0; i--) { if (sel.options[i].dataset.extra) { sel.remove(i); } }
        var existe = false;
        Array.prototype.forEach.call(sel.options, function (o) { if (o.value === valor) { existe = true; } });
        if (valor && !existe) {
            var o = document.createElement("option");
            o.value = valor; o.textContent = valor.replace(/_/g, " "); o.dataset.extra = "1";
            sel.appendChild(o);
        }
        sel.value = valor || "";
    }
    window.abrirDespacho = function (btn) {
        var $ = function (id) { return document.getElementById(id); };
        var d = btn ? btn.dataset : {};
        $("despModalTitulo").textContent = btn ? "Editar despacho" : "Agregar despacho";
        $("despId").value = btn ? d.id : "";
        $("despFecha").value = btn ? d.fecha : FECHA_VISTA;
        $("despTipo").value = btn ? d.tipo : "pedido";
        $("despNit").value = d.nit || "";
        $("despCliente").value = d.cliente || "";
        $("despComercial").value = d.comercial || "";
        $("despCiudad").value = d.ciudad || "";
        $("despDepto").value = d.depto || "";
        $("despValor").value = d.valor || "";
        $("despVendedor").value = d.vendedor || "";
        $("despFactura").value = d.factura || "";
        ponerSelect($("despEmpresa"), d.empresa || "");
        ponerSelect($("despForma"), d.forma || "");
        ponerSelect($("despTransp"), d.transp || "");
        $("modalDespacho").style.display = "flex";
        $("despCliente").focus();
    };
    window.cerrarDespacho = function () { document.getElementById("modalDespacho").style.display = "none"; };

    /* ---------- Quitar ---------- */
    window.pedirQuitarDespacho = function (btn) {
        var ligado = btn.dataset.ligado === "1";
        document.getElementById("quitarDespId").value = btn.dataset.id;
        document.getElementById("quitarDespTitulo").textContent = ligado ? "¿Quitar del control de despachos?" : "¿Eliminar este despacho?";
        document.getElementById("quitarDespTexto").textContent = btn.dataset.texto || "";
        document.getElementById("quitarDespNota").textContent = ligado
            ? "El pedido NO se borra: sigue en «Pedidos de ejecutivos». Solo desaparece de esta lista."
            : "Se borrará de la base de datos y no se puede deshacer.";
        document.getElementById("modalQuitarDespacho").style.display = "flex";
    };
    window.cerrarQuitarDespacho = function () { document.getElementById("modalQuitarDespacho").style.display = "none"; };

    /* ---------- Ver el pedido (reutiliza el detalle de la sección Pedidos) ---------- */
    window.verPedidoDesdeDespacho = function (idPedido) {
        if (typeof verPedidoAdmin === "function" && document.getElementById("detalle-ped-" + idPedido)) {
            verPedidoAdmin(idPedido);
        } else {
            window.location.href = "admin_pedido_editar.php?id=" + encodeURIComponent(idPedido);
        }
    };

    ["modalDespacho", "modalQuitarDespacho"].forEach(function (id) {
        document.getElementById(id).addEventListener("click", function (e) { if (e.target === this) { this.style.display = "none"; } });
    });
    document.addEventListener("keydown", function (e) {
        if (e.key === "Escape") { cerrarDespacho(); cerrarQuitarDespacho(); }
    });
})();
</script>
