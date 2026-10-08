<?php
/* Sección "Clientes" del panel admin (se incluye dentro de admin.php). */

$buscarC = trim($_GET["buscar_c"] ?? "");
$pagC = max(1, (int) ($_GET["pag_c"] ?? 1));
$porPagina = 50;

$whereC = "";
$tiposC = "";
$paramsC = [];
if ($buscarC !== "") {
    $whereC = " WHERE (razon_social LIKE ? OR nombre_comercial LIKE ? OR identificacion_norm LIKE ? OR municipio LIKE ? OR codigo LIKE ?)";
    $likeC = "%" . $buscarC . "%";
    $dig = "%" . (preg_replace('/\D+/', '', $buscarC) ?: "__sin_digitos__") . "%";
    $tiposC = "sssss";
    array_push($paramsC, $likeC, $likeC, $dig, $likeC, $likeC);
}

$totalClientes = 0;
$stC = $conexion->prepare("SELECT COUNT(*) FROM clientes" . $whereC);
if ($paramsC) {
    $stC->bind_param($tiposC, ...$paramsC);
}
$stC->execute();
$stC->bind_result($totalClientes);
$stC->fetch();
$stC->close();

$paginasC = max(1, (int) ceil($totalClientes / $porPagina));
$pagC = min($pagC, $paginasC);
$offsetC = ($pagC - 1) * $porPagina;

$clientesLista = [];
$stC = $conexion->prepare("SELECT * FROM clientes" . $whereC . " ORDER BY razon_social ASC LIMIT $porPagina OFFSET $offsetC");
if ($paramsC) {
    $stC->bind_param($tiposC, ...$paramsC);
}
$stC->execute();
$rC = $stC->get_result();
$clientesLista = $rC ? $rC->fetch_all(MYSQLI_ASSOC) : [];
$stC->close();

$qsPag = function (int $n) use ($buscarC) {
    return "admin.php?" . http_build_query(["buscar_c" => $buscarC, "pag_c" => $n]) . "#clientes";
};
?>
<section id="clientes" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2>👥 Clientes</h2>
            <p>Base de clientes (como el catálogo de terceros de Syscafe). Cuando el ejecutivo escribe el NIT o la cédula, sus datos aparecen solos.</p>
        </div>
        <button type="button" class="btn-nuevo" onclick="nuevoCliente()">+ Nuevo Cliente</button>
    </div>

    <div class="card-importar" style="background:#fff; border:1px dashed #90CAF9; border-radius:10px; padding:14px; margin-bottom:16px;">
        <strong>📥 Cargar clientes desde Excel</strong>
        <p style="margin:6px 0 10px; font-size:13px; color:#556;">
            En Excel use <em>Guardar como → CSV (delimitado por comas / UTF-8)</em> y súbalo aquí. Si el NIT/cédula ya existe, se actualizan sus datos.
            <a href="clientes_plantilla.php">Descargar plantilla de ejemplo</a>
        </p>
        <form method="POST" action="admin.php" enctype="multipart/form-data" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <input type="hidden" name="accion" value="cli_importar">
            <input type="file" name="archivo" accept=".csv,text/csv,text/plain" required>
            <button type="submit" class="btn-nuevo">Importar clientes</button>
        </form>
    </div>

    <form method="GET" action="admin.php#clientes" class="filtros">
        <input type="hidden" name="seccion" value="clientes">
        <div>
            <label for="buscar_c">Buscar</label>
            <input type="text" id="buscar_c" name="buscar_c" placeholder="Nombre, NIT/cédula, municipio o código" value="<?= escapar($buscarC) ?>">
        </div>
        <button type="submit" class="btn-filtrar">Buscar</button>
    </form>

    <p style="margin:0 0 10px; font-size:14px;"><strong><?= number_format($totalClientes, 0, ",", ".") ?></strong> clientes · página <?= $pagC ?> de <?= $paginasC ?></p>

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Documento</th>
                    <th>Razón social / Nombre</th>
                    <th>Nombre comercial</th>
                    <th>Teléfono</th>
                    <th>Municipio</th>
                    <th>Pago</th>
                    <th>Estado</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!$clientesLista): ?>
                    <tr><td colspan="8" class="sin-resultados">No hay clientes<?= $buscarC !== "" ? " con esa búsqueda" : " cargados todavía" ?>.</td></tr>
                <?php else: ?>
                    <?php foreach ($clientesLista as $cl): ?>
                        <tr>
                            <td><small><?= $cl["tipo_persona"] === "natural" ? "CC" : "NIT" ?></small> <?= escapar($cl["identificacion"]) ?><?= ($cl["dv"] ?? "") !== "" ? "-" . escapar($cl["dv"]) : "" ?></td>
                            <td><?= escapar($cl["razon_social"]) ?></td>
                            <td><?= escapar($cl["nombre_comercial"]) ?: "—" ?></td>
                            <td><?= escapar($cl["telefono1"] ?: $cl["movil"]) ?: "—" ?></td>
                            <td><?= escapar($cl["municipio"]) ?: "—" ?></td>
                            <td><?= $cl["condicion_pago"] === "credito" ? "Crédito " . (int) $cl["dias_credito"] . " d" : "Contado" ?></td>
                            <td>
                                <?php if ((int) $cl["inactivo"] === 1): ?>
                                    <span class="estado estado-inactivo">Inactivo</span>
                                <?php else: ?>
                                    <span class="estado estado-activo">Activo</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn-editar btn-fila"
                                    data-c="<?= escapar(json_encode($cl, JSON_UNESCAPED_UNICODE)) ?>"
                                    onclick="editarCliente(this)">Editar</button>
                                <form method="POST" action="admin.php" class="form-inline">
                                    <input type="hidden" name="accion" value="cli_estado">
                                    <input type="hidden" name="id" value="<?= (int) $cl["id"] ?>">
                                    <input type="hidden" name="inactivo" value="<?= (int) $cl["inactivo"] === 1 ? 0 : 1 ?>">
                                    <?php if ((int) $cl["inactivo"] === 1): ?>
                                        <button type="submit" class="btn-activar btn-fila">Activar</button>
                                    <?php else: ?>
                                        <button type="submit" class="btn-eliminar btn-fila">Inactivar</button>
                                    <?php endif; ?>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($paginasC > 1): ?>
        <div style="display:flex; gap:10px; margin-top:14px; align-items:center;">
            <?php if ($pagC > 1): ?><a class="btn-editar" style="text-decoration:none;" href="<?= escapar($qsPag($pagC - 1)) ?>">← Anterior</a><?php endif; ?>
            <span style="font-size:14px;">Página <?= $pagC ?> / <?= $paginasC ?></span>
            <?php if ($pagC < $paginasC): ?><a class="btn-editar" style="text-decoration:none;" href="<?= escapar($qsPag($pagC + 1)) ?>">Siguiente →</a><?php endif; ?>
        </div>
    <?php endif; ?>
</section>

<!-- MODAL CLIENTE -->
<div class="modal" id="modalCliente">
    <div class="modal-contenido" style="max-width: 820px; width: 96%; max-height: 92vh; overflow-y: auto;">
        <h3 id="tituloModalCliente">Nuevo cliente</h3>
        <form method="POST" action="admin.php" class="formulario-modal" id="formCliente">
            <input type="hidden" name="accion" value="cli_guardar">
            <input type="hidden" name="id" id="cli_id" value="">

            <h4 style="margin:6px 0;">Identificación</h4>
            <div class="cli-grid">
                <div>
                    <label for="cli_tipo_persona">Tipo de persona</label>
                    <select name="tipo_persona" id="cli_tipo_persona" onchange="cambiarTipoCliente()">
                        <option value="juridica">Persona jurídica (NIT)</option>
                        <option value="natural">Persona natural (cédula)</option>
                    </select>
                </div>
                <div>
                    <label for="cli_tipo_documento">Tipo de documento</label>
                    <select name="tipo_documento" id="cli_tipo_documento">
                        <option value="NIT">NIT</option>
                        <option value="CC">Cédula de ciudadanía</option>
                        <option value="CE">Cédula de extranjería</option>
                        <option value="PAS">Pasaporte</option>
                    </select>
                </div>
                <div>
                    <label for="cli_identificacion" id="cli_lbl_ident">NIT *</label>
                    <input type="text" name="identificacion" id="cli_identificacion" maxlength="30" required>
                </div>
                <div>
                    <label for="cli_dv">Dígito de verificación</label>
                    <input type="text" name="dv" id="cli_dv" maxlength="3">
                </div>
                <div>
                    <label for="cli_codigo">Código (Syscafe)</label>
                    <input type="text" name="codigo" id="cli_codigo" maxlength="30">
                </div>
                <div class="cli-ancho">
                    <label for="cli_razon_social" id="cli_lbl_razon">Razón social *</label>
                    <input type="text" name="razon_social" id="cli_razon_social" maxlength="200" required>
                </div>
                <div class="cli-ancho">
                    <label for="cli_nombre_comercial">Nombre comercial</label>
                    <input type="text" name="nombre_comercial" id="cli_nombre_comercial" maxlength="200">
                </div>
            </div>

            <h4 style="margin:14px 0 6px;">Contacto</h4>
            <div class="cli-grid">
                <div><label for="cli_telefono1">Teléfono 1</label><input type="text" name="telefono1" id="cli_telefono1" maxlength="40"></div>
                <div><label for="cli_telefono2">Teléfono 2</label><input type="text" name="telefono2" id="cli_telefono2" maxlength="40"></div>
                <div><label for="cli_telefono3">Teléfono 3</label><input type="text" name="telefono3" id="cli_telefono3" maxlength="40"></div>
                <div><label for="cli_movil">Tel. móvil</label><input type="text" name="movil" id="cli_movil" maxlength="40"></div>
                <div><label for="cli_email">Email</label><input type="email" name="email" id="cli_email" maxlength="150"></div>
                <div><label for="cli_email_fe">Email factura electrónica</label><input type="email" name="email_fe" id="cli_email_fe" maxlength="150"></div>
            </div>

            <h4 style="margin:14px 0 6px;">Ubicación</h4>
            <div class="cli-grid">
                <div><label for="cli_departamento">Departamento</label><select name="departamento" id="cli_departamento"></select></div>
                <div><label for="cli_municipio">Municipio</label><select name="municipio" id="cli_municipio"></select></div>
                <div><label for="cli_codigo_municipio">Código municipio</label><input type="text" name="codigo_municipio" id="cli_codigo_municipio" maxlength="12"></div>
                <div><label for="cli_pais">País</label><input type="text" name="pais" id="cli_pais" maxlength="60" value="Colombia"></div>
                <div><label for="cli_barrio">Barrio</label><input type="text" name="barrio" id="cli_barrio" maxlength="120"></div>
                <div><label for="cli_codigo_postal">Código postal</label><input type="text" name="codigo_postal" id="cli_codigo_postal" maxlength="20"></div>
                <div class="cli-ancho"><label for="cli_direccion">Dirección</label><input type="text" name="direccion" id="cli_direccion" maxlength="255"></div>
                <div class="cli-ancho"><label for="cli_direccion2">2ª dirección</label><input type="text" name="direccion2" id="cli_direccion2" maxlength="255"></div>
                <div class="cli-ancho"><label for="cli_puntos_referencia">Puntos de referencia</label><input type="text" name="puntos_referencia" id="cli_puntos_referencia" maxlength="255"></div>
            </div>

            <h4 style="margin:14px 0 6px;">Datos comerciales</h4>
            <div class="cli-grid">
                <div><label for="cli_condicion_pago">Condición de pago</label>
                    <select name="condicion_pago" id="cli_condicion_pago"><option value="contado">Contado</option><option value="credito">Crédito</option></select></div>
                <div><label for="cli_dias_credito">Días de crédito</label><input type="number" name="dias_credito" id="cli_dias_credito" min="0" max="365"></div>
                <div><label for="cli_cupo_cartera">Cupo de cartera</label><input type="text" name="cupo_cartera" id="cli_cupo_cartera" inputmode="decimal"></div>
                <div><label for="cli_lista_precios">Lista de precios</label><input type="text" name="lista_precios" id="cli_lista_precios" maxlength="40"></div>
                <div><label for="cli_grupo">Grupo</label><input type="text" name="grupo" id="cli_grupo" maxlength="80"></div>
                <div><label for="cli_subgrupo">Subgrupo</label><input type="text" name="subgrupo" id="cli_subgrupo" maxlength="80"></div>
                <div><label for="cli_zona">Zona</label><input type="text" name="zona" id="cli_zona" maxlength="80"></div>
                <div><label for="cli_vendedor_asignado">Vendedor</label><input type="text" name="vendedor_asignado" id="cli_vendedor_asignado" maxlength="100"></div>
                <div><label for="cli_cobrador">Cobrador</label><input type="text" name="cobrador" id="cli_cobrador" maxlength="100"></div>
                <div><label for="cli_encargado">Encargado</label><input type="text" name="encargado" id="cli_encargado" maxlength="150"></div>
                <div><label for="cli_representante_legal">Representante legal</label><input type="text" name="representante_legal" id="cli_representante_legal" maxlength="150"></div>
                <div class="cli-ancho"><label for="cli_observaciones">Observaciones</label><textarea name="observaciones" id="cli_observaciones" rows="2" maxlength="2000"></textarea></div>
                <div><label style="font-weight:normal;"><input type="checkbox" name="inactivo" id="cli_inactivo" value="1" style="width:auto;"> Cliente inactivo</label></div>
            </div>

            <div class="botones-modal">
                <button type="button" class="btn-editar" onclick="cerrarModalCliente()">Cancelar</button>
                <button type="submit" class="btn-nuevo">Guardar</button>
            </div>
        </form>
    </div>
</div>

<style>
    .cli-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 10px 14px; }
    .cli-grid .cli-ancho { grid-column: 1 / -1; }
    .cli-grid label { display: block; font-size: 12px; font-weight: bold; margin-bottom: 4px; }
    .cli-grid input[type=text], .cli-grid input[type=email], .cli-grid input[type=number], .cli-grid select, .cli-grid textarea { width: 100%; padding: 9px; border: 1px solid #ccd; border-radius: 6px; font-size: 14px; }
</style>

<script src="colombia.php?js=1"></script>
<script>
    var CLI_CAMPOS = ["tipo_documento", "identificacion", "dv", "codigo", "razon_social", "nombre_comercial", "telefono1", "telefono2", "telefono3",
        "movil", "email", "email_fe", "codigo_municipio", "pais", "barrio", "codigo_postal", "direccion", "direccion2", "puntos_referencia",
        "condicion_pago", "dias_credito", "cupo_cartera", "lista_precios", "grupo", "subgrupo", "zona", "vendedor_asignado", "cobrador",
        "encargado", "representante_legal", "observaciones"];

    var cliColombiaLista = colombiaInit(document.getElementById("cli_departamento"), document.getElementById("cli_municipio"));

    function cambiarTipoCliente() {
        var nat = document.getElementById("cli_tipo_persona").value === "natural";
        document.getElementById("cli_lbl_ident").textContent = nat ? "Cédula *" : "NIT *";
        document.getElementById("cli_lbl_razon").textContent = nat ? "Nombre completo *" : "Razón social *";
        var td = document.getElementById("cli_tipo_documento");
        if (nat && td.value === "NIT") { td.value = "CC"; }
        if (!nat) { td.value = "NIT"; }
    }

    function nuevoCliente() {
        document.getElementById("tituloModalCliente").textContent = "Nuevo cliente";
        document.getElementById("cli_id").value = "";
        document.getElementById("cli_tipo_persona").value = "juridica";
        CLI_CAMPOS.forEach(function (c) { document.getElementById("cli_" + c).value = ""; });
        document.getElementById("cli_pais").value = "Colombia";
        document.getElementById("cli_condicion_pago").value = "contado";
        document.getElementById("cli_inactivo").checked = false;
        cambiarTipoCliente();
        cliColombiaLista.then(function () { return colombiaSet(document.getElementById("cli_departamento"), document.getElementById("cli_municipio"), "", ""); });
        document.getElementById("modalCliente").style.display = "flex";
    }

    function editarCliente(btn) {
        var d = JSON.parse(btn.dataset.c);
        document.getElementById("tituloModalCliente").textContent = "Editar cliente";
        document.getElementById("cli_id").value = d.id;
        document.getElementById("cli_tipo_persona").value = d.tipo_persona === "natural" ? "natural" : "juridica";
        CLI_CAMPOS.forEach(function (c) { document.getElementById("cli_" + c).value = d[c] === null || d[c] === undefined ? "" : d[c]; });
        document.getElementById("cli_inactivo").checked = String(d.inactivo) === "1";
        var nat = d.tipo_persona === "natural";
        document.getElementById("cli_lbl_ident").textContent = nat ? "Cédula *" : "NIT *";
        document.getElementById("cli_lbl_razon").textContent = nat ? "Nombre completo *" : "Razón social *";
        cliColombiaLista.then(function () { return colombiaSet(document.getElementById("cli_departamento"), document.getElementById("cli_municipio"), d.departamento, d.municipio); });
        document.getElementById("modalCliente").style.display = "flex";
    }

    function cerrarModalCliente() {
        document.getElementById("modalCliente").style.display = "none";
    }
</script>
