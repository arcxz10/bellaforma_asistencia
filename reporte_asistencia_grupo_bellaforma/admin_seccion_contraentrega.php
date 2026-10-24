<?php
/* Sección "Pedidos contraentrega" del panel admin (se incluye dentro de admin.php). */

require_once __DIR__ . "/contraentrega_lib.php";
asegurarTablaCobros($conexion);

$ordenesCoe = [
    "cobro_cerca" => "Fecha de cobro más próxima",
    "cobro_lejos" => "Fecha de cobro más lejana",
    "valor_mayor" => "Mayor valor del pedido",
    "valor_menor" => "Menor valor del pedido",
];
$estadosCoe = [
    "por_cobrar" => "Por cobrar (todos los pendientes)",
    "cobrados"   => "Ya cobrados",
    "todos"      => "Todos",
];

$filtrosCoe = [
    "buscar"       => trim((string) ($_GET["buscar_e"] ?? "")),
    "desde"        => (string) ($_GET["desde_e"] ?? ""),
    "hasta"        => (string) ($_GET["hasta_e"] ?? ""),
    "vendedor"     => (int) ($_GET["vendedor_e"] ?? 0),
    "cobro"        => (string) ($_GET["cobro_e"] ?? ""),
    "estado_cobro" => isset($estadosCoe[$_GET["estado_e"] ?? ""]) ? (string) $_GET["estado_e"] : "por_cobrar",
    "orden"        => isset($ordenesCoe[$_GET["orden_e"] ?? ""]) ? (string) $_GET["orden_e"] : "cobro_cerca",
];

$pedidosCoe = contraentregaObtenerPedidos($conexion, $filtrosCoe);

// Resumen general (sin filtros): lo que de verdad falta por cobrar, agrupado por fecha de cobro
$todosCoe = contraentregaObtenerPedidos($conexion, ["estado_cobro" => "por_cobrar"]);
$coeTotalPend = 0.0;
$coeAtrasados = 0;
$coePorFecha = [];
foreach ($todosCoe as $pc) {
    $coeTotalPend += (float) $pc["total"];
    if ($pc["restantes"] < 0) {
        $coeAtrasados++;
    }
    $k = $pc["fecha_cobro"];
    if (!isset($coePorFecha[$k])) {
        $coePorFecha[$k] = ["n" => 0, "total" => 0.0, "restantes" => $pc["restantes"]];
    }
    $coePorFecha[$k]["n"]++;
    $coePorFecha[$k]["total"] += (float) $pc["total"];
}
ksort($coePorFecha);

$coeSumaLista = 0.0;
foreach ($pedidosCoe as $pc) {
    $coeSumaLista += (float) $pc["total"];
}

$listaVendedoresCoe = [];
$resLVE = $conexion->query("SELECT id, nombre FROM vendedores ORDER BY nombre ASC");
if ($resLVE) {
    $listaVendedoresCoe = $resLVE->fetch_all(MYSQLI_ASSOC);
}
$qsCoe = (string) ($_SERVER["QUERY_STRING"] ?? "");
?>
<style>
    .ce-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin: 0 0 16px; }
    .ce-kpi { background: #fff; border: 1px solid var(--linea); border-radius: 14px; padding: 14px 16px; box-shadow: var(--sombra-sm); }
    .ce-kpi span { display: block; font-size: 12px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--tinta-suave); }
    .ce-kpi strong { display: block; margin-top: 4px; font-size: 24px; color: var(--azul-900); font-variant-numeric: tabular-nums; }
    .ce-kpi.rojo strong { color: #A0192B; }
    .ce-fechas { display: flex; flex-wrap: wrap; gap: 10px; margin: 0 0 16px; }
    .ce-fecha { display: block; min-width: 180px; padding: 10px 14px; border-radius: 12px; border: 1px solid var(--linea); background: #fff; text-decoration: none; color: inherit; }
    .ce-fecha:hover { background: #F1F7FE; }
    .ce-fecha b { display: block; font-size: 15px; color: var(--azul-900); }
    .ce-fecha small { display: block; margin-top: 2px; color: var(--tinta-suave); font-size: 12.5px; }
    .ce-fecha em { display: block; margin-top: 4px; font-style: normal; font-weight: 700; font-variant-numeric: tabular-nums; }
    .ce-badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; }
    .ce-b-ok { background: #E0F4F8; color: #0E6A7E; }
    .ce-b-pronto { background: #FFF4D6; color: #8A5F00; }
    .ce-b-hoy { background: #FFE3CC; color: #B24A00; }
    .ce-b-vencido { background: #FDEBED; color: #A0192B; }
    .tabla tbody tr.ce-cobrado td { opacity: .6; }
    .ce-btn-cobrar { background: #1D6F42; color: #fff; border: none; }
    .ce-btn-cobrar:hover { filter: brightness(1.1); }
</style>

<section id="contraentrega" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2><svg class="ico" aria-hidden="true"><use href="#i-truck"/></svg> Pedidos contraentrega</h2>
            <p>Pedidos que los ejecutivos vendieron con pago contraentrega. La transportadora paga los días <b>15</b> y <b>30</b> de cada mes: los pedidos del 1 al 12 se cobran el 15; los del 13 al 27, el 30 (fin de mes); y los del 28 al 31, el 15 del mes siguiente. Cuando llegue el pago, márquelo como cobrado para que salga de la lista.</p>
        </div>
    </div>

    <div class="ce-kpis">
        <div class="ce-kpi"><span>Pedidos por cobrar</span><strong><?= count($todosCoe) ?></strong></div>
        <div class="ce-kpi"><span>Valor por cobrar</span><strong><?= formatoCOP($coeTotalPend) ?></strong></div>
        <div class="ce-kpi rojo"><span>Atrasados</span><strong><?= $coeAtrasados ?></strong></div>
    </div>

    <?php if ($coePorFecha): ?>
        <div class="ce-fechas">
            <?php foreach ($coePorFecha as $fechaK => $info): ?>
                <a class="ce-fecha" href="admin.php?seccion=contraentrega&amp;cobro_e=<?= escapar($fechaK) ?>#contraentrega">
                    <b><?= date("d/m/Y", strtotime($fechaK)) ?></b>
                    <small><?= escapar(contraentregaTextoRestantes($info["restantes"])) ?> · <?= (int) $info["n"] ?> <?= $info["n"] === 1 ? "pedido" : "pedidos" ?></small>
                    <em><?= formatoCOP($info["total"]) ?></em>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <form method="GET" action="admin.php#contraentrega" class="filtros">
        <input type="hidden" name="seccion" value="contraentrega">
        <div>
            <label for="buscar_e">Cliente / NIT / N° pedido</label>
            <input type="text" id="buscar_e" name="buscar_e" value="<?= escapar($filtrosCoe["buscar"]) ?>" placeholder="Ej.: 900123456">
        </div>
        <div>
            <label for="desde_e">Pedido desde</label>
            <input type="date" id="desde_e" name="desde_e" value="<?= escapar($filtrosCoe["desde"]) ?>">
        </div>
        <div>
            <label for="hasta_e">Pedido hasta</label>
            <input type="date" id="hasta_e" name="hasta_e" value="<?= escapar($filtrosCoe["hasta"]) ?>">
        </div>
        <div>
            <label for="cobro_e">Fecha de cobro</label>
            <input type="date" id="cobro_e" name="cobro_e" value="<?= escapar($filtrosCoe["cobro"]) ?>">
        </div>
        <div>
            <label for="vendedor_e">Ejecutivo</label>
            <select id="vendedor_e" name="vendedor_e">
                <option value="0">Todos</option>
                <?php foreach ($listaVendedoresCoe as $lv): ?>
                    <option value="<?= (int) $lv["id"] ?>" <?= $filtrosCoe["vendedor"] === (int) $lv["id"] ? "selected" : "" ?>><?= escapar($lv["nombre"]) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="estado_e">Mostrar</label>
            <select id="estado_e" name="estado_e">
                <?php foreach ($estadosCoe as $clave => $texto): ?>
                    <option value="<?= $clave ?>" <?= $filtrosCoe["estado_cobro"] === $clave ? "selected" : "" ?>><?= escapar($texto) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="orden_e">Ordenar por</label>
            <select id="orden_e" name="orden_e">
                <?php foreach ($ordenesCoe as $clave => $texto): ?>
                    <option value="<?= $clave ?>" <?= $filtrosCoe["orden"] === $clave ? "selected" : "" ?>><?= escapar($texto) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <button type="submit" class="btn-filtrar">Filtrar</button>
    </form>

    <p style="margin:0 0 10px; font-size:14px;">
        Mostrando <strong><?= count($pedidosCoe) ?></strong> pedidos · Suma: <strong><?= formatoCOP($coeSumaLista) ?></strong>
    </p>

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Pedido</th>
                    <th>Fecha del pedido</th>
                    <th>Ejecutivo</th>
                    <th>Cliente</th>
                    <th>Fecha de cobro</th>
                    <th>Días restantes</th>
                    <th class="num-der">Total</th>
                    <th>Acción</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$pedidosCoe): ?>
                    <tr><td colspan="8" class="sin-resultados">No hay pedidos contraentrega con estos filtros.</td></tr>
                <?php else: ?>
                    <?php foreach ($pedidosCoe as $pc):
                        $claseBadge = $pc["restantes"] < 0 ? "vencido" : ($pc["restantes"] === 0 ? "hoy" : ($pc["restantes"] <= 5 ? "pronto" : "ok"));
                        ?>
                        <tr class="<?= $pc["cobrado"] ? "ce-cobrado" : "" ?>" id="ce-fila-<?= (int) $pc["id"] ?>">
                            <td><strong>#<?= escapar(numeroDocumento($pc)) ?></strong></td>
                            <td><?= date("d/m/Y", strtotime($pc["fecha_pedido"])) ?></td>
                            <td><?= escapar($pc["vendedor_nombre"]) ?></td>
                            <td>
                                <?= escapar($pc["cliente_nombre"]) ?: "—" ?>
                                <?php if (!empty($pc["cliente_nombre_comercial"])): ?><br><small style="color:#667;"><?= escapar($pc["cliente_nombre_comercial"]) ?></small><?php endif; ?>
                                <?php if (!empty($pc["cliente_nit"])): ?><br><small style="color:#667;"><?= escapar(etiquetaDocumento($pc["cliente_tipo_persona"] ?? "juridica")) ?> <?= escapar($pc["cliente_nit"]) ?></small><?php endif; ?>
                            </td>
                            <td><strong><?= date("d/m/Y", strtotime($pc["fecha_cobro"])) ?></strong></td>
                            <td>
                                <?php if ($pc["cobrado"]): ?>
                                    <span class="ce-badge ce-b-ok">Cobrado<?= !empty($pc["cb_cobrado_en"]) ? " · " . date("d/m/Y", strtotime($pc["cb_cobrado_en"])) : "" ?></span>
                                <?php else: ?>
                                    <span class="ce-badge ce-b-<?= $claseBadge ?>"><?= escapar(contraentregaTextoRestantes($pc["restantes"])) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="num-der"><?= formatoCOP($pc["total"]) ?></td>
                            <td>
                                <form method="POST" action="admin.php" class="form-inline"
                                      <?php if (!$pc["cobrado"]): ?>onsubmit="return confirm('¿Marcar este pedido como cobrado? Saldrá de la lista de pendientes.');"<?php endif; ?>>
                                    <input type="hidden" name="accion" value="<?= $pc["cobrado"] ? "coe_reabrir" : "coe_cobrado" ?>">
                                    <input type="hidden" name="id" value="<?= (int) $pc["id"] ?>">
                                    <input type="hidden" name="qs" value="<?= escapar($qsCoe) ?>">
                                    <?php if ($pc["cobrado"]): ?>
                                        <button type="submit" class="btn-editar btn-fila">Reabrir</button>
                                    <?php else: ?>
                                        <button type="submit" class="btn-nuevo btn-fila ce-btn-cobrar">Marcar cobrado</button>
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
