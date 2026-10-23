<?php
/*
 * Sección "Pedidos para despachar" del panel admin (se incluye dentro de admin.php).
 * Es la hoja mensual (ENERO, FEBRERO…): se llena sola con cada pedido, remisión o cotización que envían los ejecutivos.
 * Lee lo mismo que «Control de despachos» (tabla despachos), pero por mes y con lo más reciente arriba.
 */
require_once __DIR__ . "/despachos_lib.php";

$hoyPD = date("Y-m-d");
$mesPD = (string) ($_GET["mes_pd"] ?? substr($hoyPD, 0, 7));
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $mesPD)) {
    $mesPD = substr($hoyPD, 0, 7);
}
$anioPD = (int) substr($mesPD, 0, 4);
$mesNumPD = (int) substr($mesPD, 5, 2);
$desdePD = $mesPD . "-01";
$hastaPD = date("Y-m-t", strtotime($desdePD));
$mesActualPD = substr($hoyPD, 0, 7);

$nombresMesPD = [1 => "ENERO", "FEBRERO", "MARZO", "ABRIL", "MAYO", "JUNIO", "JULIO", "AGOSTO", "SEPTIEMBRE", "OCTUBRE", "NOVIEMBRE", "DICIEMBRE"];
$nombresDiaPD = ["Domingo", "Lunes", "Martes", "Miércoles", "Jueves", "Viernes", "Sábado"];

$errorPD = "";
try {
    $filasPD = obtenerDespachosRango($conexion, $desdePD, $hastaPD, true);   // de la más antigua a la más reciente
} catch (Throwable $exPD) {
    $filasPD = [];
    $errorPD = "No se pudo cargar la hoja de pedidos. Recargue la página.";
}

$totalPD = count($filasPD);
$conteoEstadoPD = ["en_proceso" => 0, "despachado" => 0, "con_novedad" => 0];
$porDiaPD = [];
foreach ($filasPD as $filaP) {
    if ($filaP["anulado"]) {
        continue;
    }
    $claveEstP = isset($conteoEstadoPD[$filaP["estado_pedido"]]) ? $filaP["estado_pedido"] : "en_proceso";
    $conteoEstadoPD[$claveEstP]++;
    $diaK = substr((string) $filaP["fecha_origen"], 0, 10);
    $porDiaPD[$diaK] = ($porDiaPD[$diaK] ?? 0) + 1;
}
$totalActivosPD = array_sum($conteoEstadoPD);

$qsMesPD = fn(string $m) => "admin.php?mes_pd=" . $m . "#pedidos_despachar";

/** Lista desplegable de la hoja (se guarda sola al cambiar). */
function pdSelectHtml(int $id, string $campo, array $catalogo, string $actual, string $clase = ""): string
{
    $h = '<select class="pd-in ' . $clase . '" data-id="' . $id . '" data-campo="' . escapar($campo) . '" data-prev="' . escapar($actual) . '" data-valor="' . escapar($actual) . '">';
    if ($campo !== "estado_pedido") {
        $h .= '<option value="">—</option>';
    }
    foreach ($catalogo as $k => $etiqueta) {
        $h .= '<option value="' . escapar($k) . '"' . ((string) $k === $actual ? " selected" : "") . ">" . escapar($etiqueta) . "</option>";
    }
    if ($actual !== "" && !isset($catalogo[$actual])) {
        $h .= '<option value="' . escapar($actual) . '" selected>' . escapar($actual) . "</option>";
    }
    return $h . "</select>";
}
?>
<style>
    #pedidos_despachar .pd-meses { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin: 0 0 14px; }
    #pedidos_despachar .pd-anio { display: inline-flex; align-items: center; gap: 4px; margin-right: 8px; font-weight: 700; font-size: 15px; }
    #pedidos_despachar .pd-anio a { display: inline-flex; align-items: center; justify-content: center; width: 32px; height: 32px; border-radius: 8px; background: #EDF1F7; color: var(--tinta); text-decoration: none; font-weight: 700; }
    #pedidos_despachar .pd-anio a:hover { background: #DFE6F0; }
    #pedidos_despachar .pd-mes { padding: 8px 12px; border-radius: 8px; background: #EDF1F7; color: var(--tinta); font-size: 12.5px; font-weight: 700; letter-spacing: .3px; text-decoration: none; }
    #pedidos_despachar .pd-mes:hover { background: #DFE6F0; }
    #pedidos_despachar .pd-mes.activo { background: #3F51B5; color: #fff; }
    #pedidos_despachar .pd-mes.actual:not(.activo) { box-shadow: inset 0 -3px 0 #3F51B5; }
    #pedidos_despachar .pd-herramientas { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin: 0 0 12px; }
    #pedidos_despachar .pd-buscar { flex: 1 1 260px; max-width: 380px; padding: 10px 12px; border: 1.5px solid var(--linea, #E2E8F0); border-radius: 10px; font-size: 14px; background: #fff; }
    #pedidos_despachar .pd-chip { padding: 8px 12px; border: 1.5px solid transparent; border-radius: 20px; background: #EDF1F7; color: var(--tinta); font-size: 12.5px; font-weight: 700; cursor: pointer; }
    #pedidos_despachar .pd-chip b { margin-left: 4px; }
    #pedidos_despachar .pd-chip.activo { border-color: #3F51B5; background: #E8EBFA; color: #2B3A9F; }
    #pedidos_despachar .pd-chip[data-filtro="en_proceso"] { background: #FFF1CC; color: #8A6100; }
    #pedidos_despachar .pd-chip[data-filtro="despachado"] { background: #DDF3E3; color: #1E7A3C; }
    #pedidos_despachar .pd-chip[data-filtro="con_novedad"] { background: #FFE0D6; color: #B23B16; }
    #pedidos_despachar .pd-chip.activo[data-filtro] { border-color: currentColor; }
    #pedidos_despachar .pd-scroll { max-height: 68vh; overflow: auto; border-radius: 12px; background: #fff; box-shadow: 0 2px 10px rgba(0, 0, 0, .06); }
    #pedidos_despachar table.pd-tabla { width: 100%; min-width: 880px; border-collapse: separate; border-spacing: 0; }
    #pedidos_despachar .pd-tabla thead th { position: sticky; top: 0; z-index: 3; padding: 12px 10px; background: #3F51B5; color: #fff; font-size: 12px; letter-spacing: .4px; text-align: left; white-space: nowrap; }
    #pedidos_despachar .pd-tabla td { padding: 7px 10px; font-size: 13px; vertical-align: middle; border-bottom: 1px solid #EEF1F6; }
    #pedidos_despachar .pd-tabla td.nw { white-space: nowrap; }
    #pedidos_despachar .pd-tabla tr.pd-fila:nth-child(even) td { background: #FAFBFE; }
    #pedidos_despachar .pd-tabla tr.pd-fila:hover td { background: #F1F4FD; }
    #pedidos_despachar .pd-tabla tr.pd-dia td { position: sticky; top: 42px; z-index: 2; padding: 7px 10px; background: #E8EBFA; color: #2B3A9F; font-size: 12.5px; font-weight: 700; border-bottom: 1px solid #D5DBF3; }
    #pedidos_despachar .pd-tabla tr.pd-anulado td { opacity: .5; text-decoration: line-through; }
    #pedidos_despachar .pd-tabla tr.pd-anulado td .pd-in { text-decoration: none; }
    #pedidos_despachar .pd-codigo { font-weight: 700; letter-spacing: .2px; }
    #pedidos_despachar .pd-tipo { display: inline-block; margin-left: 6px; padding: 1px 7px; border-radius: 9px; font-size: 10.5px; font-weight: 700; background: #FFF3E0; color: #E65100; }
    #pedidos_despachar .pd-tipo.cotizacion { background: #F3E5F5; color: #6A1B9A; }
    #pedidos_despachar .pd-in { width: 100%; padding: 6px 8px; border: 1.5px solid transparent; border-radius: 6px; background: #F6F8FC; font-size: 12.5px; color: inherit; }
    #pedidos_despachar .pd-in:hover { border-color: var(--linea, #E2E8F0); }
    #pedidos_despachar .pd-in:focus { outline: none; border-color: var(--azul-500, #2563EB); background: #fff; }
    #pedidos_despachar .pd-in.guardando { border-color: #F0B429; }
    #pedidos_despachar .pd-in.ok { border-color: #2E9E5B; background: #EAF7F0; }
    #pedidos_despachar .pd-in.err { border-color: var(--peligro, #D63A4B); background: var(--peligro-bg, #FDECEE); }
    #pedidos_despachar .pd-estado { min-width: 142px; font-weight: 700; font-size: 12px; border-radius: 14px; }
    #pedidos_despachar .pd-estado[data-valor="despachado"] { background: #DDF3E3; color: #1E7A3C; }
    #pedidos_despachar .pd-estado[data-valor="en_proceso"] { background: #FFF1CC; color: #8A6100; }
    #pedidos_despachar .pd-estado[data-valor="con_novedad"] { background: #FFE0D6; color: #B23B16; }
    #pedidos_despachar .pd-obs { min-width: 220px; }
    #pedidos_despachar td.pd-obs-celda { min-width: 240px; }
    #pedidos_despachar .pd-obs-ej { margin-top: 6px; padding: 6px 9px; border-left: 3px solid #3F51B5; border-radius: 6px; background: #EEF1FC; color: #243071; font-size: 12.5px; line-height: 1.35; white-space: pre-wrap; word-break: break-word; }
    #pedidos_despachar .pd-obs-ej b { display: block; margin-bottom: 1px; font-size: 10.5px; letter-spacing: .4px; text-transform: uppercase; color: #3F51B5; }
    #pedidos_despachar .pd-comp { display: inline-flex; align-items: center; gap: 8px; margin-top: 6px; padding: 4px 10px 4px 4px; border: 1.5px solid #CFE8D6; border-radius: 8px; background: #F1FAF4; color: #1E7A3C; font-size: 12px; font-weight: 700; cursor: pointer; text-align: left; }
    #pedidos_despachar .pd-comp:hover { background: #E3F5E9; border-color: #2E9E5B; }
    #pedidos_despachar .pd-comp img { display: block; width: 46px; height: 38px; border-radius: 5px; object-fit: cover; background: #fff; }
    .pd-visor { position: fixed; inset: 0; z-index: 9999; display: none; align-items: center; justify-content: center; padding: 20px; background: rgba(10, 20, 40, .72); }
    .pd-visor.abierto { display: flex; }
    .pd-visor-caja { display: flex; flex-direction: column; gap: 12px; max-width: min(92vw, 760px); max-height: 94vh; padding: 14px; border-radius: 14px; background: #fff; }
    .pd-visor-caja h4 { margin: 0; font-size: 15px; color: #1B2A41; }
    .pd-visor-caja img { max-width: 100%; max-height: 74vh; object-fit: contain; border-radius: 8px; }
    .pd-visor-btns { display: flex; flex-wrap: wrap; justify-content: flex-end; gap: 8px; }
    .pd-visor-btns a, .pd-visor-btns button { display: inline-flex; align-items: center; padding: 9px 14px; border: 0; border-radius: 8px; font-size: 14px; font-weight: 600; text-decoration: none; cursor: pointer; background: #EDF1F7; color: #1B2A41; }
    #pedidos_despachar .pd-vacio { padding: 28px; text-align: center; color: #667; }
</style>

<section id="pedidos_despachar" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2><svg class="ico" aria-hidden="true"><use href="#i-clipboard"/></svg> Pedidos para despachar</h2>
            <p>La hoja del mes: cada pedido, remisión o cotización que envía un ejecutivo entra sola, con lo más reciente arriba. Usted solo cambia el estado y escribe la observación (se guarda solo). En la misma columna verá la observación que dejó el ejecutivo y el comprobante de pago si el pedido es anticipado. Los datos de envío (guía, flete, rótulo) se llenan en «Control de despachos».</p>
        </div>
    </div>

    <?php if ($errorPD !== ""): ?>
        <div class="mensaje error"><?= escapar($errorPD) ?></div>
    <?php endif; ?>

    <div class="pd-meses">
        <span class="pd-anio">
            <a href="<?= escapar($qsMesPD(($anioPD - 1) . "-" . sprintf("%02d", $mesNumPD))) ?>" title="Año anterior">‹</a>
            <?= (int) $anioPD ?>
            <a href="<?= escapar($qsMesPD(($anioPD + 1) . "-" . sprintf("%02d", $mesNumPD))) ?>" title="Año siguiente">›</a>
        </span>
        <?php foreach ($nombresMesPD as $nMes => $nombreMes): ?>
            <?php $claveMes = $anioPD . "-" . sprintf("%02d", $nMes); ?>
            <a class="pd-mes<?= $claveMes === $mesPD ? " activo" : "" ?><?= $claveMes === $mesActualPD ? " actual" : "" ?>" href="<?= escapar($qsMesPD($claveMes)) ?>"><?= $nombreMes ?></a>
        <?php endforeach; ?>
    </div>

    <div class="pd-herramientas">
        <input type="search" class="pd-buscar" id="pdBuscar" placeholder="Buscar por vendedor, # de pedido, empresa, cliente u observación…" autocomplete="off">
        <button type="button" class="pd-chip activo" data-filtro="todos">Todos <b id="pdN_todos"><?= (int) $totalActivosPD ?></b></button>
        <?php foreach (estadosPedidoDespachar() as $kEst => $lEst): ?>
            <button type="button" class="pd-chip" data-filtro="<?= $kEst ?>"><?= escapar($lEst) ?> <b id="pdN_<?= $kEst ?>"><?= (int) $conteoEstadoPD[$kEst] ?></b></button>
        <?php endforeach; ?>
    </div>

    <div class="pd-scroll">
        <table class="pd-tabla" id="pdTabla">
            <thead>
                <tr>
                    <th>N.</th>
                    <th>FECHA</th>
                    <th>VENDEDOR</th>
                    <th># DE PEDIDO Ó REMISIÓN</th>
                    <th>EMPRESA</th>
                    <th>ESTADO</th>
                    <th>OBSERVACIÓN</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$filasPD): ?>
                    <tr><td colspan="7" class="pd-vacio">No hay pedidos en <?= escapar($nombresMesPD[$mesNumPD]) ?> de <?= (int) $anioPD ?>.</td></tr>
                <?php else: ?>
                    <?php $diaAnteriorPD = null; ?>
                    <?php foreach (array_reverse($filasPD, true) as $iPD => $d): ?>
                        <?php
                        $did = (int) $d["id"];
                        $diaPD = substr((string) $d["fecha_origen"], 0, 10);
                        $estadoPD = $d["anulado"] ? "anulado" : (isset($conteoEstadoPD[$d["estado_pedido"]]) ? $d["estado_pedido"] : "en_proceso");
                        $codigoPD = $d["codigo_doc"] !== "" ? $d["codigo_doc"] : ($d["factura"] !== "" ? $d["factura"] : "—");
                        $textoBusqueda = function_exists("mb_strtolower")
                            ? mb_strtolower($d["vendedor"] . " " . $codigoPD . " " . $d["empresa"] . " " . $d["cliente"] . " " . $d["comercial"] . " " . $d["observacion"] . " " . $d["obs_ejecutivo"], "UTF-8")
                            : strtolower($d["vendedor"] . " " . $codigoPD . " " . $d["empresa"] . " " . $d["cliente"] . " " . $d["comercial"] . " " . $d["observacion"] . " " . $d["obs_ejecutivo"]);
                        ?>
                        <?php if ($diaPD !== $diaAnteriorPD): ?>
                            <?php $tsDiaPD = strtotime($diaPD); $nDiaPD = (int) ($porDiaPD[$diaPD] ?? 0); ?>
                            <tr class="pd-dia" data-dia="<?= escapar($diaPD) ?>">
                                <td colspan="7"><?= escapar($nombresDiaPD[(int) date("w", $tsDiaPD)] . " " . date("d/m/Y", $tsDiaPD)) ?> · <span class="pd-dia-n"><?= $nDiaPD ?></span> <?= $nDiaPD === 1 ? "pedido" : "pedidos" ?></td>
                            </tr>
                            <?php $diaAnteriorPD = $diaPD; ?>
                        <?php endif; ?>
                        <tr class="pd-fila<?= $d["anulado"] ? " pd-anulado" : "" ?>" data-dia="<?= escapar($diaPD) ?>" data-estado="<?= escapar($estadoPD) ?>" data-texto="<?= escapar($textoBusqueda) ?>">
                            <td class="nw"><?= $iPD + 1 ?></td>
                            <td class="nw"><?= escapar(date("d/m/Y", strtotime($diaPD))) ?></td>
                            <td><?= escapar($d["vendedor"]) ?></td>
                            <td class="nw" title="<?= escapar(trim($d["cliente"] . ($d["comercial"] !== "" && $d["comercial"] !== $d["cliente"] ? " · " . $d["comercial"] : ""))) ?>">
                                <span class="pd-codigo"><?= escapar($codigoPD) ?></span>
                                <?php if ($d["tipo"] !== "pedido"): ?><span class="pd-tipo <?= escapar($d["tipo"]) ?>"><?= escapar(etiquetaTipoDocumento($d["tipo"])) ?></span><?php endif; ?>
                                <?php if ($d["anulado"]): ?><span class="pd-tipo" style="background:#FDECEE; color:#B42334;">anulado</span><?php endif; ?>
                            </td>
                            <td><?= pdSelectHtml($did, "empresa", empresasDespacho(), (string) $d["empresa"]) ?></td>
                            <td><?= pdSelectHtml($did, "estado_pedido", estadosPedidoDespachar(), $d["estado_pedido"], "pd-estado") ?></td>
                            <td class="pd-obs-celda">
                                <input type="text" class="pd-in pd-obs" data-id="<?= $did ?>" data-campo="observacion" data-prev="<?= escapar($d["observacion"]) ?>" value="<?= escapar($d["observacion"]) ?>" maxlength="255" autocomplete="off">
                                <?php if (trim((string) $d["obs_ejecutivo"]) !== ""): ?>
                                    <div class="pd-obs-ej"><b>Observación del ejecutivo</b><?= escapar($d["obs_ejecutivo"]) ?></div>
                                <?php endif; ?>
                                <?php if (!empty($d["tiene_comprobante"]) && !empty($d["pedido_id"])): ?>
                                    <button type="button" class="pd-comp" data-pedido="<?= (int) $d["pedido_id"] ?>" data-titulo="<?= escapar($codigoPD) ?>" title="Ver comprobante de pago">
                                        <img src="pedido_comprobante.php?id=<?= (int) $d["pedido_id"] ?>" alt="" loading="lazy">
                                        <span>Comprobante de pago</span>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<script>
(function () {
    var raiz = document.getElementById("pedidos_despachar");
    var tabla = document.getElementById("pdTabla");
    if (!raiz || !tabla) { return; }
    var filtro = "todos";

    /* ---------- Edición en línea (usa el mismo guardado que «Control de despachos») ---------- */
    function guardar(el) {
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
                    el.dataset.prev = el.value;
                    var fila = el.closest("tr.pd-fila");
                    if (el.dataset.campo === "estado_pedido") {
                        el.dataset.valor = el.value;
                        if (fila && fila.dataset.estado !== "anulado") { fila.dataset.estado = el.value; }
                        contar();
                        aplicar();
                    }
                    if (el.dataset.campo === "observacion" && fila) {
                        fila.dataset.texto = (fila.dataset.texto || "") + " " + el.value.toLowerCase();
                    }
                    el.classList.add("ok");
                    setTimeout(function () { el.classList.remove("ok"); }, 1400);
                } else {
                    el.classList.add("err");
                    el.value = el.dataset.prev || "";
                    if (el.dataset.campo === "estado_pedido") { el.dataset.valor = el.value; }
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
    tabla.addEventListener("change", function (e) {
        if (e.target.classList && e.target.classList.contains("pd-in")) { guardar(e.target); }
    });
    tabla.addEventListener("keydown", function (e) {
        if (e.key === "Enter" && e.target.tagName === "INPUT" && e.target.classList.contains("pd-in")) { e.preventDefault(); e.target.blur(); }
    });

    /* ---------- Contadores, buscador y filtro por estado ---------- */
    function contar() {
        var n = { en_proceso: 0, despachado: 0, con_novedad: 0 }, total = 0;
        Array.prototype.forEach.call(tabla.querySelectorAll("tr.pd-fila"), function (tr) {
            if (n.hasOwnProperty(tr.dataset.estado)) { n[tr.dataset.estado]++; total++; }
        });
        document.getElementById("pdN_todos").textContent = total;
        Object.keys(n).forEach(function (k) { document.getElementById("pdN_" + k).textContent = n[k]; });
    }

    function aplicar() {
        var q = document.getElementById("pdBuscar").value.trim().toLowerCase();
        var sep = null, visibles = 0;
        function cerrarGrupo() {
            if (sep) {
                sep.style.display = visibles > 0 ? "" : "none";
                var c = sep.querySelector(".pd-dia-n");
                if (c) { c.textContent = visibles; }
            }
        }
        Array.prototype.forEach.call(tabla.querySelectorAll("tbody tr"), function (tr) {
            if (tr.classList.contains("pd-dia")) { cerrarGrupo(); sep = tr; visibles = 0; return; }
            if (!tr.classList.contains("pd-fila")) { return; }
            var okEstado = filtro === "todos" ? true : tr.dataset.estado === filtro;
            var okTexto = q === "" || (tr.dataset.texto || "").indexOf(q) !== -1;
            var ver = okEstado && okTexto;
            tr.style.display = ver ? "" : "none";
            if (ver) { visibles++; }
        });
        cerrarGrupo();
    }

    /* ---------- Comprobante de pago: ver grande o descargar ---------- */
    var visor = null;
    function crearVisor() {
        visor = document.createElement("div");
        visor.className = "pd-visor";
        visor.innerHTML = '<div class="pd-visor-caja"><h4></h4><img alt="Comprobante de pago"><div class="pd-visor-btns">' +
            '<a class="pd-bajar" download>⬇ Descargar imagen</a><button type="button" class="pd-cerrar">Cerrar</button></div></div>';
        document.body.appendChild(visor);
        visor.addEventListener("click", function (e) { if (e.target === visor) { cerrarVisor(); } });
        visor.querySelector(".pd-cerrar").addEventListener("click", cerrarVisor);
    }
    function abrirVisor(id, titulo) {
        if (!visor) { crearVisor(); }
        var base = "pedido_comprobante.php?id=" + encodeURIComponent(id);
        visor.querySelector("h4").textContent = "Comprobante de pago" + (titulo ? " · " + titulo : "");
        visor.querySelector("img").src = base;
        visor.querySelector(".pd-bajar").href = base + "&descargar=1";
        visor.classList.add("abierto");
    }
    function cerrarVisor() { if (visor) { visor.classList.remove("abierto"); } }
    document.addEventListener("keydown", function (e) { if (e.key === "Escape") { cerrarVisor(); } });
    tabla.addEventListener("click", function (e) {
        var b = e.target.closest ? e.target.closest(".pd-comp") : null;
        if (b) { abrirVisor(b.dataset.pedido, b.dataset.titulo); }
    });

    document.getElementById("pdBuscar").addEventListener("input", aplicar);
    Array.prototype.forEach.call(raiz.querySelectorAll(".pd-chip"), function (b) {
        b.addEventListener("click", function () {
            filtro = b.dataset.filtro;
            Array.prototype.forEach.call(raiz.querySelectorAll(".pd-chip"), function (x) { x.classList.toggle("activo", x === b); });
            aplicar();
        });
    });
})();
</script>
