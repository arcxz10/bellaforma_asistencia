<?php
/*
 * Sección «Movimientos subadministradores» del panel admin (se incluye dentro de admin.php).
 * SOLO la ve el administrador principal: muestra todo lo que hacen los subadministradores en el panel.
 */
if (!adminEsPrincipal()) {
    return;
}

require_once __DIR__ . "/admin_movimientos_lib.php";

$mvEtiquetas = movSubEtiquetas();

$filtrosMov = [
    "admin_id" => (int) ($_GET["sub_m"] ?? 0),
    "modulo"   => (string) ($_GET["modulo_m"] ?? ""),
    "desde"    => (string) ($_GET["desde_m"] ?? ""),
    "hasta"    => (string) ($_GET["hasta_m"] ?? ""),
    "buscar"   => trim((string) ($_GET["buscar_m"] ?? "")),
    "pagina"   => (int) ($_GET["pag_m"] ?? 1),
];
$resMov = movSubListar($conexion, $filtrosMov);

// Resumen general (sin filtros)
$mvHoy = $mv7 = $mvTotal = 0;
$mvSubs = [];
try {
    $rKpi = $conexion->query("SELECT COUNT(*) AS total,
                                     SUM(DATE(creado_en) = CURDATE()) AS hoy,
                                     SUM(creado_en >= DATE_SUB(NOW(), INTERVAL 7 DAY)) AS sem
                              FROM admin_movimientos");
    $kpi = $rKpi ? $rKpi->fetch_assoc() : null;
    $mvTotal = (int) ($kpi["total"] ?? 0);
    $mvHoy = (int) ($kpi["hoy"] ?? 0);
    $mv7 = (int) ($kpi["sem"] ?? 0);

    // Subadministradores para el filtro (los actuales y los que ya fueron eliminados pero dejaron movimientos)
    $rSubs = $conexion->query("SELECT admin_id, MAX(admin_nombre) AS nombre FROM admin_movimientos GROUP BY admin_id ORDER BY nombre ASC");
    while ($rSubs && ($fs = $rSubs->fetch_assoc())) {
        $mvSubs[(int) $fs["admin_id"]] = (string) $fs["nombre"];
    }
    $rSubs2 = $conexion->query("SELECT id, COALESCE(NULLIF(nombre, ''), usuario) AS nombre FROM administradores WHERE rol = 'sub'");
    while ($rSubs2 && ($fs = $rSubs2->fetch_assoc())) {
        $mvSubs[(int) $fs["id"]] = (string) $fs["nombre"];
    }
    asort($mvSubs);
} catch (Throwable $exMov) {
    // si algo falla, la sección se muestra vacía
}

// Módulos para el filtro
$mvModulos = [];
foreach ($mvEtiquetas as $datosEt) {
    $mvModulos[$datosEt[1]] = movSubNombreModulo($datosEt[1]);
}
asort($mvModulos);

// Enlaces de paginación que conservan los filtros
$qsMov = $_GET;
unset($qsMov["pag_m"]);
$qsMov["seccion"] = "movimientos";
$urlPagMov = function (int $p) use ($qsMov): string {
    $q = $qsMov;
    $q["pag_m"] = $p;
    return "admin.php?" . http_build_query($q) . "#movimientos";
};
?>
<style>
    .mv-kpis { display: grid; grid-template-columns: repeat(auto-fit, minmax(170px, 1fr)); gap: 12px; margin: 0 0 16px; }
    .mv-kpi { background: #fff; border: 1px solid var(--linea); border-radius: 14px; padding: 14px 16px; box-shadow: var(--sombra-sm); }
    .mv-kpi span { display: block; font-size: 12px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase; color: var(--tinta-suave); }
    .mv-kpi strong { display: block; margin-top: 4px; font-size: 24px; color: var(--azul-900); font-variant-numeric: tabular-nums; }
    .mv-badge { display: inline-block; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; white-space: nowrap; }
    .mv-t-rojo  { background: #FDEBED; color: #A0192B; }
    .mv-t-verde { background: #E3F5E8; color: #1D6F42; }
    .mv-t-azul  { background: #E8EBFA; color: #2B3A9F; }
    .mv-t-gris  { background: #EEF0F3; color: #55606E; }
    .mv-quien { font-weight: 700; }
    .mv-quien small { display: block; font-weight: 400; color: #667; }
    .mv-detalle { max-width: 520px; line-height: 1.4; }
    .mv-fecha { white-space: nowrap; font-variant-numeric: tabular-nums; }
    .mv-pag { display: flex; align-items: center; justify-content: center; gap: 14px; margin: 16px 0 4px; font-size: 14px; }
    .mv-pag a { padding: 8px 14px; border: 1px solid var(--linea); border-radius: 8px; background: #fff; color: var(--tinta); font-weight: 600; text-decoration: none; }
    .mv-pag a:hover { background: #F2F5FB; }
</style>

<section id="movimientos" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2><svg class="ico" aria-hidden="true"><use href="#i-clipboard"/></svg> Movimientos subadministradores</h2>
            <p>Todo lo que hacen los subadministradores en el panel: subir comprobantes, crear, editar o eliminar pedidos, clientes, productos, despachos, liquidaciones, etc. Solo usted ve esta lista. Lo que hace el administrador principal no se registra aquí.</p>
        </div>
    </div>

    <div class="mv-kpis">
        <div class="mv-kpi"><span>Movimientos hoy</span><strong><?= $mvHoy ?></strong></div>
        <div class="mv-kpi"><span>Últimos 7 días</span><strong><?= $mv7 ?></strong></div>
        <div class="mv-kpi"><span>Total registrados</span><strong><?= $mvTotal ?></strong></div>
    </div>

    <form method="GET" action="admin.php#movimientos" class="filtros">
        <input type="hidden" name="seccion" value="movimientos">
        <div>
            <label for="sub_m">Subadministrador</label>
            <select id="sub_m" name="sub_m">
                <option value="0">Todos</option>
                <?php foreach ($mvSubs as $idSub => $nomSub): ?>
                    <option value="<?= (int) $idSub ?>" <?= $filtrosMov["admin_id"] === (int) $idSub ? "selected" : "" ?>><?= escapar($nomSub) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="modulo_m">Módulo</label>
            <select id="modulo_m" name="modulo_m">
                <option value="">Todos</option>
                <?php foreach ($mvModulos as $claveMod => $textoMod): ?>
                    <option value="<?= escapar($claveMod) ?>" <?= $filtrosMov["modulo"] === $claveMod ? "selected" : "" ?>><?= escapar($textoMod) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label for="desde_m">Desde</label>
            <input type="date" id="desde_m" name="desde_m" value="<?= escapar($filtrosMov["desde"]) ?>">
        </div>
        <div>
            <label for="hasta_m">Hasta</label>
            <input type="date" id="hasta_m" name="hasta_m" value="<?= escapar($filtrosMov["hasta"]) ?>">
        </div>
        <div>
            <label for="buscar_m">Buscar en el detalle</label>
            <input type="text" id="buscar_m" name="buscar_m" value="<?= escapar($filtrosMov["buscar"]) ?>" placeholder="Ej.: #4512, cliente, producto">
        </div>
        <button type="submit" class="btn-filtrar">Filtrar</button>
    </form>

    <p style="margin:0 0 10px; font-size:14px;">
        Mostrando <strong><?= count($resMov["filas"]) ?></strong> de <strong><?= (int) $resMov["total"] ?></strong> movimientos
    </p>

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Fecha y hora</th>
                    <th>Subadministrador</th>
                    <th>Módulo</th>
                    <th>Qué hizo</th>
                    <th>Detalle</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$resMov["filas"]): ?>
                    <tr><td colspan="5" class="sin-resultados">Todavía no hay movimientos de subadministradores con estos filtros.</td></tr>
                <?php else: ?>
                    <?php foreach ($resMov["filas"] as $mv):
                        $textoAccion = $mvEtiquetas[$mv["accion"]][0] ?? $mv["accion"];
                        ?>
                        <tr>
                            <td class="mv-fecha" title="<?= $mv["ip"] ? "IP " . escapar($mv["ip"]) : "" ?>"><?= date("d/m/Y H:i:s", strtotime($mv["creado_en"])) ?></td>
                            <td class="mv-quien"><?= escapar($mv["admin_nombre"] !== "" ? $mv["admin_nombre"] : $mv["admin_usuario"]) ?>
                                <?php if ($mv["admin_nombre"] !== "" && $mv["admin_usuario"] !== ""): ?><small><?= escapar($mv["admin_usuario"]) ?></small><?php endif; ?>
                            </td>
                            <td><?= escapar(movSubNombreModulo((string) $mv["modulo"])) ?></td>
                            <td><span class="mv-badge mv-t-<?= movSubTono((string) $mv["accion"]) ?>"><?= escapar($textoAccion) ?></span></td>
                            <td class="mv-detalle"><?= escapar($mv["detalle"]) ?: "—" ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($resMov["paginas"] > 1): ?>
        <div class="mv-pag">
            <?php if ($resMov["pagina"] > 1): ?><a href="<?= escapar($urlPagMov($resMov["pagina"] - 1)) ?>">← Más recientes</a><?php endif; ?>
            <span>Página <strong><?= (int) $resMov["pagina"] ?></strong> de <?= (int) $resMov["paginas"] ?></span>
            <?php if ($resMov["pagina"] < $resMov["paginas"]): ?><a href="<?= escapar($urlPagMov($resMov["pagina"] + 1)) ?>">Más antiguos →</a><?php endif; ?>
        </div>
    <?php endif; ?>
</section>
