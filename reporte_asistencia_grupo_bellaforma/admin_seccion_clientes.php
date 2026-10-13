<?php
/*
 * Sección "Clientes" del panel admin: una sola base de clientes. El campo "Tipo de cliente" (mayorista / detal)
 * define la lista de precios que se usa en los pedidos. Incluye todos los campos del catálogo de terceros de Syscafe.
 */
require_once __DIR__ . "/clientes_lib.php";

$C = "";                       // sin sufijo en los identificadores
$CliTabla = tablaClientes();
$CliSeccion = "clientes";
$CliTitulo = "Clientes";

$pBuscar = "buscar_c";
$pPag = "pag_c";
$buscarC = trim($_GET[$pBuscar] ?? "");
$tipoFiltro = in_array($_GET["tipo_c"] ?? "", ["mayorista", "detal"], true) ? $_GET["tipo_c"] : "";
$pagC = max(1, (int) ($_GET[$pPag] ?? 1));
$porPagina = 50;

$condiciones = [];
$tiposC = "";
$paramsC = [];
if ($buscarC !== "") {
    $condiciones[] = "(razon_social LIKE ? OR nombre_comercial LIKE ? OR identificacion_norm LIKE ? OR municipio LIKE ? OR codigo LIKE ?)";
    $likeC = "%" . $buscarC . "%";
    $dig = "%" . (preg_replace('/\D+/', '', $buscarC) ?: "__sin_digitos__") . "%";
    $tiposC .= "sssss";
    array_push($paramsC, $likeC, $likeC, $dig, $likeC, $likeC);
}
if ($tipoFiltro !== "") {
    $condiciones[] = "tipo_cliente = ?";
    $tiposC .= "s";
    $paramsC[] = $tipoFiltro;
}
$whereC = $condiciones ? " WHERE " . implode(" AND ", $condiciones) : "";

$totalClientes = 0;
$stC = $conexion->prepare("SELECT COUNT(*) FROM `$CliTabla`" . $whereC);
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

$stC = $conexion->prepare("SELECT * FROM `$CliTabla`" . $whereC . " ORDER BY razon_social ASC LIMIT $porPagina OFFSET $offsetC");
if ($paramsC) {
    $stC->bind_param($tiposC, ...$paramsC);
}
$stC->execute();
$rC = $stC->get_result();
$clientesLista = $rC ? $rC->fetch_all(MYSQLI_ASSOC) : [];
$stC->close();

$hijosC = cargarHijosClientes($conexion, array_map(fn($x) => (int) $x["id"], $clientesLista));

$qsPag = function (int $n) use ($pBuscar, $pPag, $buscarC, $tipoFiltro, $CliSeccion) {
    return "admin.php?" . http_build_query([$pBuscar => $buscarC, "tipo_c" => $tipoFiltro, $pPag => $n]) . "#" . $CliSeccion;
};

// Ayuda para dibujar campos de texto del formulario
$cx = function (string $name, string $label, string $extra = "", string $tipo = "text") use ($C) {
    $id = "cli" . $C . "_" . $name;
    echo '<div' . ($extra === "ancho" ? ' class="cli-ancho"' : '') . '><label for="' . $id . '">' . escapar($label) . '</label>'
        . '<input type="' . $tipo . '" name="' . $name . '" id="' . $id . '"></div>';
};
?>
<section id="<?= $CliSeccion ?>" class="section">

    <div class="cabecera-seccion">
        <div>
            <h2>👥 Clientes</h2>
            <p>Una sola base de clientes, con los datos del catálogo de terceros de Syscafe (incluye subterceros y contactos). Cada cliente tiene su <strong>tipo</strong> (mayorista o detal), que define la lista de precios. Cuando el ejecutivo elige el tipo y escribe el NIT o la cédula, los datos aparecen solos.</p>
        </div>
        <button type="button" class="btn-nuevo" onclick="nuevoCliente<?= $C ?>()">+ Nuevo Cliente</button>
    </div>

    <div style="background:#fff; border:1px dashed #90CAF9; border-radius:10px; padding:14px; margin-bottom:16px;">
        <strong>📥 Cargar clientes desde Excel</strong>
        <p style="margin:6px 0 10px; font-size:13px; color:#556;">
            Suba el Excel (.xlsx) o un CSV directamente. Si el Excel tiene hojas llamadas <em>mayoristas</em> y <em>detal</em>, cada una se carga con su tipo (y se ignoran las demás hojas). Si el NIT/cédula ya existe, se actualizan sus datos.
            <a href="clientes_plantilla.php">Descargar plantilla de ejemplo</a>. Si el archivo trae la columna <em>tipo_cliente</em> (mayorista / detal) se respeta; si no, se usa el tipo que elija aquí.
        </p>
        <form method="POST" action="admin.php" enctype="multipart/form-data" style="display:flex; gap:10px; flex-wrap:wrap; align-items:center;">
            <input type="hidden" name="accion" value="cli_importar">
            <select name="tipo_defecto" style="padding:9px; border:1px solid #ccd; border-radius:6px;">
                <option value="mayorista">Estos clientes son MAYORISTAS</option>
                <option value="detal">Estos clientes son DETAL</option>
            </select>
            <input type="file" name="archivo" accept=".xlsx,.csv,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required>
            <button type="submit" class="btn-nuevo">Importar clientes</button>
        </form>
    </div>

    <form method="GET" action="admin.php#<?= $CliSeccion ?>" class="filtros">
        <input type="hidden" name="seccion" value="<?= $CliSeccion ?>">
        <div>
            <label for="<?= $pBuscar ?>">Buscar</label>
            <input type="text" id="<?= $pBuscar ?>" name="<?= $pBuscar ?>" placeholder="Nombre, NIT/cédula, municipio o código" value="<?= escapar($buscarC) ?>">
        </div>
        <div>
            <label for="tipo_c">Tipo de cliente</label>
            <select id="tipo_c" name="tipo_c">
                <option value="">Todos</option>
                <option value="mayorista" <?= $tipoFiltro === "mayorista" ? "selected" : "" ?>>Mayorista</option>
                <option value="detal" <?= $tipoFiltro === "detal" ? "selected" : "" ?>>Detal</option>
            </select>
        </div>
        <button type="submit" class="btn-filtrar">Buscar</button>
    </form>

    <p style="margin:0 0 10px; font-size:14px;"><strong><?= number_format($totalClientes, 0, ",", ".") ?></strong> clientes · página <?= $pagC ?> de <?= $paginasC ?></p>

    <div class="tabla-contenedor">
        <table class="tabla">
            <thead>
                <tr>
                    <th>Tipo</th>
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
                    <tr><td colspan="9" class="sin-resultados">No hay clientes<?= $buscarC !== "" ? " con esa búsqueda" : " cargados todavía" ?>.</td></tr>
                <?php else: ?>
                    <?php foreach ($clientesLista as $cl): ?>
                        <?php
                        $datosJs = $cl;
                        $datosJs["subterceros"] = $hijosC[(int) $cl["id"]]["subterceros"] ?? [];
                        $datosJs["contactos"] = $hijosC[(int) $cl["id"]]["contactos"] ?? [];
                        ?>
                        <tr>
                            <td>
                                <span class="estado" style="background:<?= ($cl["tipo_cliente"] ?? "mayorista") === "detal" ? "#FCE4EC" : "#E3F2FD" ?>; color:<?= ($cl["tipo_cliente"] ?? "mayorista") === "detal" ? "#AD1457" : "#0D47A1" ?>;"><?= escapar(etiquetaTipoCliente($cl["tipo_cliente"] ?? "mayorista")) ?></span>
                            </td>
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
                                    data-c="<?= escapar(json_encode($datosJs, JSON_UNESCAPED_UNICODE)) ?>"
                                    onclick="editarCliente<?= $C ?>(this)">Editar</button>
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
<div class="modal" id="modalCli<?= $C ?>">
    <div class="modal-contenido" style="max-width: 900px; width: 96%; max-height: 92vh; overflow-y: auto;">
        <h3 id="tituloCli<?= $C ?>">Nuevo cliente</h3>
        <form method="POST" action="admin.php" class="formulario-modal" id="formCli<?= $C ?>">
            <input type="hidden" name="accion" value="cli_guardar">
            <input type="hidden" name="id" id="cli<?= $C ?>_id" value="">
            <input type="hidden" name="subterceros_json" id="cli<?= $C ?>_subterceros_json" value="[]">
            <input type="hidden" name="contactos_json" id="cli<?= $C ?>_contactos_json" value="[]">

            <h4 class="cli-h">Identificación</h4>
            <div class="cli-grid">
                <div class="cli-ancho" style="background:#E3F2FD; padding:10px; border-radius:8px;">
                    <label for="cli<?= $C ?>_tipo_cliente">Tipo de cliente * (define la lista de precios)</label>
                    <select name="tipo_cliente" id="cli<?= $C ?>_tipo_cliente" required>
                        <option value="">— Seleccione —</option>
                        <option value="mayorista">Cliente mayorista (precio mayorista)</option>
                        <option value="detal">Cliente detal (precio detal)</option>
                    </select>
                </div>
                <div>
                    <label for="cli<?= $C ?>_tipo_documento">Identificación (tipo)</label>
                    <select name="tipo_documento" id="cli<?= $C ?>_tipo_documento">
                        <?php foreach (TIPOS_DOCUMENTO as $td): ?>
                            <option value="<?= escapar($td) ?>"><?= escapar($td) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php $cx("identificacion", "Número (NIT o cédula) *"); ?>
                <?php $cx("dv", "Dígito de verificación"); ?>
                <?php $cx("codigo", "Cód."); ?>
                <?php $cx("razon_social", "Razón social / Nombre completo *", "ancho"); ?>
                <div>
                    <label>&nbsp;</label>
                    <label style="font-weight:normal;"><input type="hidden" name="es_juridica" value="0"><input type="checkbox" name="es_juridica" id="cli<?= $C ?>_es_juridica" value="1" style="width:auto;"> Persona jurídica</label>
                    <label style="font-weight:normal;"><input type="checkbox" name="inactivo" id="cli<?= $C ?>_inactivo" value="1" style="width:auto;"> Inactivo</label>
                </div>
                <?php $cx("nombre_comercial", "Nombre c/cial", "ancho"); ?>
                <div class="cli-ancho"><label for="cli<?= $C ?>_nota">Nota del cliente (recuadro amarillo)</label><textarea name="nota" id="cli<?= $C ?>_nota" rows="2" maxlength="500"></textarea></div>
            </div>

            <h4 class="cli-h">Direcciones y contacto</h4>
            <div class="cli-grid">
                <?php $cx("direccion", "Dirección", "ancho"); ?>
                <?php $cx("direccion2", "2º Dir.", "ancho"); ?>
                <?php $cx("puntos_referencia", "Puntos de referencia (entrega)", "ancho"); ?>
                <?php $cx("telefono1", "Teléfono 1"); ?>
                <?php $cx("telefono2", "Teléfono 2"); ?>
                <?php $cx("telefono3", "Teléfono 3"); ?>
                <?php $cx("movil", "Tel. móvil"); ?>
                <?php $cx("codigo_postal", "Cód. postal"); ?>
                <?php $cx("email", "Email", "", "email"); ?>
                <?php $cx("email_fe", "Email FE (factura electrónica)", "", "email"); ?>
            </div>

            <h4 class="cli-h">Ubicación</h4>
            <div class="cli-grid">
                <div><label for="cli<?= $C ?>_departamento">Departamento</label><select name="departamento" id="cli<?= $C ?>_departamento"></select></div>
                <div><label for="cli<?= $C ?>_municipio">Municipio</label><select name="municipio" id="cli<?= $C ?>_municipio"></select></div>
                <?php $cx("codigo_municipio", "Código municipio (ej. 11-001)"); ?>
                <?php $cx("codigo_pais", "País (código)"); ?>
                <?php $cx("pais", "País"); ?>
                <?php $cx("codigo_barrio", "Barrio (código)"); ?>
                <?php $cx("barrio", "Barrio"); ?>
            </div>

            <h4 class="cli-h">Clasificación</h4>
            <div class="cli-grid">
                <?php $cx("grupo", "Grupo"); ?>
                <?php $cx("subgrupo", "Subgrupo"); ?>
                <?php $cx("encargado", "Encargado"); ?>
                <?php $cx("representante_legal", "Rep. legal"); ?>
                <div class="cli-ancho"><label for="cli<?= $C ?>_observaciones">Observaciones</label><textarea name="observaciones" id="cli<?= $C ?>_observaciones" rows="3"></textarea></div>
            </div>

            <h4 class="cli-h">Datos comerciales</h4>
            <div class="cli-grid">
                <?php $cx("zona", "Zona"); ?>
                <?php $cx("codigo_vendedor", "Vendedor (código)"); ?>
                <?php $cx("vendedor_asignado", "Vendedor (nombre)"); ?>
                <?php $cx("codigo_cobrador", "Cobrador (código)"); ?>
                <?php $cx("cobrador", "Cobrador (nombre)"); ?>
                <?php $cx("codigo_agente", "Agente cial. (código)"); ?>
                <?php $cx("agente_comercial", "Agente cial. (nombre)"); ?>
                <?php $cx("codigo_transporta", "Transporta (código)"); ?>
                <?php $cx("transportadora", "Transporta (nombre)"); ?>
                <?php $cx("lista_precios", "List. prec."); ?>
                <?php $cx("calificacion", "Calific."); ?>
                <?php $cx("cupo_cartera", "Cupo cartera"); ?>
                <?php $cx("no_facturas", "No. fact", "", "number"); ?>
                <?php $cx("dias_mora", "No. días mora", "", "number"); ?>
                <div><label for="cli<?= $C ?>_condicion_pago">Condición de pago</label>
                    <select name="condicion_pago" id="cli<?= $C ?>_condicion_pago"><option value="contado">Contado</option><option value="credito">Crédito</option></select></div>
                <?php $cx("dias_credito", "Días de crédito", "", "number"); ?>
            </div>

            <h4 class="cli-h">Subterceros</h4>
            <table class="cli-mini" id="cli<?= $C ?>_tabla_subterceros">
                <thead><tr><th>Tipo</th><th>Código</th><th>Nombre</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
            <button type="button" class="btn-editar" onclick="agregarFilaSub<?= $C ?>()">+ Agregar subtercero</button>

            <h4 class="cli-h">Contactos</h4>
            <table class="cli-mini" id="cli<?= $C ?>_tabla_contactos">
                <thead><tr><th>Nombre</th><th>Cargo</th><th>Teléfono</th><th>Móvil</th><th>Email</th><th>Obs.</th><th></th></tr></thead>
                <tbody></tbody>
            </table>
            <button type="button" class="btn-editar" onclick="agregarFilaCont<?= $C ?>()">+ Agregar contacto</button>

            <div class="botones-modal">
                <button type="button" class="btn-editar" onclick="cerrarCli<?= $C ?>()">Cancelar</button>
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
    .cli-h { margin: 16px 0 6px; color: #0D47A1; border-bottom: 2px solid #E3F2FD; padding-bottom: 4px; }
    .cli-mini { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
    .cli-mini th { text-align: left; font-size: 12px; color: #556; padding: 4px; }
    .cli-mini td { padding: 3px; }
    .cli-mini input { width: 100%; padding: 7px; border: 1px solid #ccd; border-radius: 6px; font-size: 13px; }
</style>

<script src="colombia.php?js=1"></script>
<script>
    (function () {
        var C = "<?= $C ?>";
        var P = "cli" + C + "_";
        var $ = function (id) { return document.getElementById(P + id); };
        var CAMPOS = ["identificacion", "dv", "codigo", "razon_social", "nombre_comercial", "nota", "direccion", "direccion2", "puntos_referencia",
            "telefono1", "telefono2", "telefono3", "movil", "codigo_postal", "email", "email_fe", "codigo_municipio", "codigo_pais", "pais",
            "codigo_barrio", "barrio", "grupo", "subgrupo", "encargado", "representante_legal", "observaciones", "zona", "codigo_vendedor",
            "vendedor_asignado", "codigo_cobrador", "cobrador", "codigo_agente", "agente_comercial", "codigo_transporta", "transportadora",
            "lista_precios", "calificacion", "cupo_cartera", "no_facturas", "dias_mora", "condicion_pago", "dias_credito", "tipo_documento"];
        var SUB = ["tipo", "codigo", "nombre"];
        var CONT = ["nombre", "cargo", "telefono", "movil", "email", "observaciones"];

        var listo = colombiaInit($("departamento"), $("municipio"));

        function nuevaFila(tbody, campos, valores) {
            var tr = document.createElement("tr");
            campos.forEach(function (c) {
                var td = document.createElement("td");
                var inp = document.createElement("input");
                inp.type = "text"; inp.dataset.campo = c; inp.value = (valores && valores[c]) || "";
                td.appendChild(inp); tr.appendChild(td);
            });
            var td = document.createElement("td");
            var b = document.createElement("button");
            b.type = "button"; b.textContent = "✕"; b.className = "btn-eliminar";
            b.addEventListener("click", function () { tr.remove(); });
            td.appendChild(b); tr.appendChild(td);
            tbody.appendChild(tr);
        }
        function leerFilas(tbody) {
            var filas = [];
            tbody.querySelectorAll("tr").forEach(function (tr) {
                var o = {};
                tr.querySelectorAll("input").forEach(function (i) { o[i.dataset.campo] = i.value; });
                filas.push(o);
            });
            return filas;
        }
        var tbSub = function () { return $("tabla_subterceros").querySelector("tbody"); };
        var tbCont = function () { return $("tabla_contactos").querySelector("tbody"); };

        window["agregarFilaSub" + C] = function () { nuevaFila(tbSub(), SUB, null); };
        window["agregarFilaCont" + C] = function () { nuevaFila(tbCont(), CONT, null); };

        function limpiarYMostrar() {
            document.getElementById("modalCli" + C).style.display = "flex";
        }

        window["nuevoCliente" + C] = function () {
            document.getElementById("tituloCli" + C).textContent = "Nuevo cliente";
            $("id").value = "";
            $("tipo_cliente").value = "";
            CAMPOS.forEach(function (c) { $(c).value = ""; });
            $("tipo_documento").selectedIndex = 0;
            $("pais").value = "Colombia"; $("codigo_pais").value = "169";
            $("cupo_cartera").value = "0"; $("no_facturas").value = "0"; $("dias_mora").value = "0";
            $("condicion_pago").value = "contado";
            $("es_juridica").checked = true; $("inactivo").checked = false;
            tbSub().innerHTML = ""; tbCont().innerHTML = "";
            listo.then(function () { return colombiaSet($("departamento"), $("municipio"), "", ""); });
            limpiarYMostrar();
        };

        window["editarCliente" + C] = function (btn) {
            var d = JSON.parse(btn.dataset.c);
            document.getElementById("tituloCli" + C).textContent = "Editar cliente";
            $("id").value = d.id;
            $("tipo_cliente").value = d.tipo_cliente || "mayorista";
            CAMPOS.forEach(function (c) { $(c).value = (d[c] === null || d[c] === undefined) ? "" : d[c]; });
            if (d.tipo_documento && $("tipo_documento").value !== d.tipo_documento) {
                var op = document.createElement("option"); op.value = d.tipo_documento; op.textContent = d.tipo_documento;
                $("tipo_documento").appendChild(op); $("tipo_documento").value = d.tipo_documento;
            }
            $("es_juridica").checked = d.tipo_persona !== "natural";
            $("inactivo").checked = String(d.inactivo) === "1";
            tbSub().innerHTML = ""; tbCont().innerHTML = "";
            (d.subterceros || []).forEach(function (f) { nuevaFila(tbSub(), SUB, f); });
            (d.contactos || []).forEach(function (f) { nuevaFila(tbCont(), CONT, f); });
            listo.then(function () { return colombiaSet($("departamento"), $("municipio"), d.departamento, d.municipio); });
            limpiarYMostrar();
        };

        window["cerrarCli" + C] = function () { document.getElementById("modalCli" + C).style.display = "none"; };

        // El tipo de identificación marca solo si es persona jurídica (NIT)
        $("tipo_documento").addEventListener("change", function () {
            $("es_juridica").checked = this.value.toLowerCase().indexOf("nit") === 0;
        });

        document.getElementById("formCli" + C).addEventListener("submit", function () {
            $("subterceros_json").value = JSON.stringify(leerFilas(tbSub()));
            $("contactos_json").value = JSON.stringify(leerFilas(tbCont()));
        });
    })();
</script>
