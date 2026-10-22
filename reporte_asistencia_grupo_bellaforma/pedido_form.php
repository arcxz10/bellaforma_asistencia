<?php
/**
 * Formulario de pedido (pensado para celular). Se incluye desde vendedor.php y admin_pedido_editar.php.
 * Variable requerida: $pf = [
 *   'rol' => 'vendedor'|'admin', 'csrf' => string, 'pedido' => array|null, 'productos' => array,
 *   'volver' => string, 'autosave' => bool, 'url_base' => string, 'texto_enviar' => string
 * ]
 */
$ped = $pf["pedido"] ?? null;

$datosIniciales = [
    "tipo_documento" => tipoDocumentoValido($ped["tipo_pedido"] ?? "pedido"),
    "numero" => $ped["numero_pedido"] ?? "",
    "tipo_persona" => $ped["cliente_tipo_persona"] ?? "juridica",
    "identificacion" => $ped["cliente_nit"] ?? "",
    "cliente_nombre" => $ped["cliente_nombre"] ?? "",
    "nombre_comercial" => $ped["cliente_nombre_comercial"] ?? "",
    "telefono" => $ped["cliente_telefono"] ?? "",
    "email" => $ped["cliente_email"] ?? "",
    "departamento" => $ped["cliente_departamento"] ?? "",
    "municipio" => $ped["cliente_ciudad"] ?? "",
    "barrio" => $ped["cliente_barrio"] ?? "",
    "direccion" => $ped["cliente_direcciones"] ?? "",
    "puntos_referencia" => $ped["cliente_puntos_referencia"] ?? "",
    "factura_electronica" => (int) ($ped["factura_electronica"] ?? 0),
    "email_fe" => $ped["email_fe"] ?? "",
    "condicion_pago" => $ped["condicion_pago"] ?? "contado",
    "dias_credito" => $ped["dias_credito"] ?? "",
    "forma_pago" => $ped["forma_pago"] ?? "",
    "transportadora" => $ped["transportadora"] ?? "",
    "observaciones" => $ped["observaciones"] ?? "",
    "cliente_id" => $ped["cliente_id"] ?? null,
];
$itemsIniciales = [];
$bonosIniciales = [];   // productos bonificados (sin costo)
if ($ped) {
    $idsProductos = [];
    $idPorRef = [];
    foreach ($pf["productos"] as $pp) {
        $idsProductos[(int) $pp["id"]] = true;
        $idPorRef[$pp["referencia"]] = (int) $pp["id"];
    }
    foreach ($ped["items"] as $it) {
        $pid = (int) $it["producto_id"];
        if (!isset($idsProductos[$pid]) && isset($idPorRef[$it["referencia"]])) {
            $pid = $idPorRef[$it["referencia"]];   // respaldo: si el id no coincide, se une por referencia
        }
        if (!empty($it["bonificado"])) {
            $bonosIniciales[$pid] = ($bonosIniciales[$pid] ?? 0) + (int) $it["cantidad"];
        } else {
            $itemsIniciales[$pid] = (int) $it["cantidad"];
        }
    }
}
$cfgJs = [
    "rol" => $pf["rol"],
    "csrf" => $pf["csrf"],
    "api" => $pf["api"] ?? "pedido_api.php",
    "volver" => $pf["volver"],
    "autosave" => (bool) $pf["autosave"],
    "urlBase" => $pf["url_base"] ?? "",
    "pedidoId" => $ped ? (int) $ped["id"] : null,
    "estadoPedido" => $ped["estado"] ?? "nuevo",
    "numeroAuto" => $ped ? numeroPedido($ped["id"]) : "",
    "tipoCliente" => $ped ? ($ped["tipo_cliente"] ?? "mayorista") : "",
    "datos" => $datosIniciales,
    "items" => (object) $itemsIniciales,
    "bonos" => (object) $bonosIniciales,
    "textoEnviar" => $pf["texto_enviar"] ?? "Enviar pedido",
    // Documento enviado antes de que existieran estos datos: se muestra cómo estaba el pago para que el ejecutivo lo tenga de referencia
    "pagoAnterior" => ($ped && $ped["estado"] !== "borrador" && trim((string) ($ped["forma_pago"] ?? "")) === "") ? etiquetaCondicion($ped) : "",
];
$flagsJson = JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<div class="pf" id="pf">

    <div class="pf-pasos">
        <button type="button" class="pf-paso-btn activo" data-paso="1"><span>1</span>Cliente</button>
        <button type="button" class="pf-paso-btn" data-paso="2"><span>2</span>Productos</button>
        <button type="button" class="pf-paso-btn" data-paso="3"><span>3</span>Resumen</button>
    </div>
    <div class="pf-estado" id="pfEstado"></div>

    <!-- ============ PASO 1: CLIENTE ============ -->
    <section class="pf-seccion activo" data-paso="1">

        <div class="pf-card" id="pfCardTipoDoc">
            <h2>¿Qué documento va a generar?</h2>
            <div class="pf-segmento pf-seg-3" id="pfDocTipo">
                <button type="button" data-doc="pedido">🛒 Pedido de cliente</button>
                <button type="button" data-doc="remision">🚚 Remisión</button>
                <button type="button" data-doc="cotizacion">📝 Cotización</button>
            </div>
            <div id="pfNumeroBox" <?= ($pf["rol"] === "admin" || !empty($pf["editar_numero"])) ? "" : "hidden" ?>>
                <label for="pfNumero" id="pfNumeroLabel">Número de pedido</label>
                <input type="text" id="pfNumero" inputmode="numeric" pattern="[0-9]*" maxlength="10" autocomplete="off" placeholder="Automático">
                <p class="pf-nota" id="pfNumeroNota">Déjelo vacío para que el número sea automático. Si escribe uno, ese mismo saldrá en el PDF y en el archivo de Syscafe.</p>
            </div>
        </div>

        <div class="pf-card" id="pfCardTipoCliente">
            <h2>¿Qué tipo de cliente es?</h2>
            <p class="pf-nota" style="margin:0 0 10px;">Elija primero el tipo: define la lista de precios del pedido.</p>
            <div class="pf-segmento" id="pfListaTipo">
                <button type="button" data-lista="mayorista">🏪 Cliente mayorista<small>Precio mayorista</small></button>
                <button type="button" data-lista="detal">🛍️ Cliente detal<small>Precio detal</small></button>
            </div>
            <div class="pf-aviso alerta" id="pfAvisoLista" style="margin-top:10px;">👆 Elija una de las dos opciones para continuar.</div>
        </div>

        <div id="pfResto" class="pf-bloqueado">

        <div class="pf-card">
            <h2>Factura electrónica</h2>
            <div class="pf-segmento pf-seg-2" id="pfFE">
                <button type="button" data-fe="0" class="activo">No requiere</button>
                <button type="button" data-fe="1">Sí, factura electrónica</button>
            </div>
            <div id="pfFEBox" hidden>
                <label for="pfEmailFe">Correo para factura electrónica *</label>
                <input type="email" id="pfEmailFe" maxlength="150" autocomplete="off" inputmode="email">
            </div>
        </div>

        <div class="pf-card">
            <h2>Datos del cliente</h2>

            <div class="pf-segmento" id="pfTipo">
                <button type="button" data-tipo="juridica" class="activo">🏢 Persona jurídica<small>Con NIT</small></button>
                <button type="button" data-tipo="natural">👤 Persona natural<small>Con cédula</small></button>
            </div>

            <label for="pfDoc" id="pfDocLabel">NIT *</label>
            <div class="pf-doc-fila">
                <input type="text" id="pfDoc" inputmode="numeric" autocomplete="off" placeholder="Ej: 900123456-7" maxlength="40">
                <button type="button" id="pfBuscar" class="pf-btn-sec">Buscar</button>
            </div>
            <div id="pfClienteMsg" class="pf-aviso" hidden></div>

            <label for="pfNombre" id="pfNombreLabel">Razón social *</label>
            <input type="text" id="pfNombre" maxlength="200" autocomplete="off">

            <label for="pfComercial">Nombre comercial</label>
            <input type="text" id="pfComercial" maxlength="200" autocomplete="off">

            <label for="pfTelefono">Teléfono *</label>
            <input type="tel" id="pfTelefono" inputmode="tel" maxlength="40" autocomplete="off">

            <label for="pfEmail">Correo electrónico</label>
            <input type="email" id="pfEmail" maxlength="150" autocomplete="off" inputmode="email">
        </div>

        <div class="pf-card">
            <h2>Ubicación y entrega</h2>

            <label for="pfDepto">Departamento *</label>
            <select id="pfDepto"><option value="">Cargando…</option></select>

            <label for="pfMuni">Municipio</label>
            <select id="pfMuni"><option value="">Primero elija departamento</option></select>

            <label for="pfBarrio">Barrio</label>
            <input type="text" id="pfBarrio" maxlength="120" autocomplete="off">

            <label for="pfDireccion">Dirección de entrega *</label>
            <input type="text" id="pfDireccion" maxlength="255" autocomplete="off">

            <label for="pfPuntos">Puntos de referencia</label>
            <textarea id="pfPuntos" rows="2" maxlength="255" placeholder="Ej: frente al parque, local 2, al lado de la droguería"></textarea>
        </div>

        </div><!-- /pfResto -->
    </section>

    <!-- ============ PASO 2: PRODUCTOS ============ -->
    <section class="pf-seccion" data-paso="2">
        <div class="pf-busqueda">
            <input type="search" id="pfBuscarProd" placeholder="🔍 Buscar referencia o nombre" autocomplete="off">
            <button type="button" class="pf-chip" id="pfSoloSel">Elegidos</button>
        </div>
        <div class="pf-busq-nota" id="pfBusqNota" hidden></div>

        <div class="pf-lista-etq" id="pfListaEtq"></div>
        <div class="pf-lista" id="pfLista">
            <?php if (empty($pf["productos"])): ?>
                <p class="pf-vacio">Aún no hay productos cargados.</p>
            <?php endif; ?>
            <?php foreach ($pf["productos"] as $p): ?>
                <div class="pf-prod" data-id="<?= (int) $p["id"] ?>"
                     data-precio-mayorista="<?= htmlspecialchars((string) $p["precio_mayorista"], ENT_QUOTES, "UTF-8") ?>"
                     data-precio-detal="<?= htmlspecialchars((string) $p["precio_detal"], ENT_QUOTES, "UTF-8") ?>"
                     data-busq="<?= htmlspecialchars($p["referencia"] . " " . $p["nombre"], ENT_QUOTES, "UTF-8") ?>" hidden>
                    <?php if (!empty($p["foto_v"])): ?>
                        <button type="button" class="pf-thumb" data-foto="foto.php?id=<?= (int) $p["id"] ?>&amp;v=<?= (int) $p["foto_v"] ?>">
                            <img src="foto.php?id=<?= (int) $p["id"] ?>&amp;v=<?= (int) $p["foto_v"] ?>" alt="" loading="lazy" decoding="async">
                        </button>
                    <?php else: ?>
                        <div class="pf-thumb pf-thumb-vacio">🧴</div>
                    <?php endif; ?>
                    <div class="pf-prod-info">
                        <b><?= htmlspecialchars($p["referencia"], ENT_QUOTES, "UTF-8") ?></b>
                        <span><?= htmlspecialchars($p["nombre"], ENT_QUOTES, "UTF-8") ?><?= (int) $p["activo"] !== 1 ? " (desactivado)" : "" ?></span>
                        <em></em>
                    </div>
                    <div class="pf-step pf-step-main">
                        <button type="button" class="menos" aria-label="Quitar uno">−</button>
                        <input type="number" class="cant" inputmode="numeric" min="0" max="100000" step="1" value="0" aria-label="Cantidad">
                        <button type="button" class="mas" aria-label="Agregar uno">+</button>
                        <button type="button" class="bonif" aria-pressed="false" title="Agregar unidades de este producto sin costo (100 % de descuento)">🎁 Bonificado</button>
                    </div>
                    <div class="pf-bonif" hidden>
                        <div class="pf-bonif-info">
                            <b>🎁 Bonificado</b>
                            <span>Sin costo · 100 % de descuento · precio lista <s class="pf-bonif-precio"></s></span>
                        </div>
                        <div class="pf-step">
                            <button type="button" class="bmenos" aria-label="Quitar un bonificado">−</button>
                            <input type="number" class="cantb" inputmode="numeric" min="0" max="100000" step="1" value="0" aria-label="Cantidad bonificada">
                            <button type="button" class="bmas" aria-label="Agregar un bonificado">+</button>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="pf-vacio" id="pfSinRes" hidden>Ningún producto coincide con la búsqueda.</p>
    </section>

    <!-- ============ PASO 3: RESUMEN ============ -->
    <section class="pf-seccion" data-paso="3">
        <div class="pf-card">
            <h2>Productos del pedido</h2>
            <div id="pfResumen"></div>
            <div class="pf-total-linea"><span>Total</span><b id="pfTotal2">$ 0</b></div>
        </div>

        <div class="pf-card">
            <h2>Condiciones comerciales</h2>

            <label id="pfFormaPagoLabel">Forma de pago *</label>
            <div class="pf-segmento pf-seg-2" id="pfFormaPago" role="group" aria-labelledby="pfFormaPagoLabel">
                <?php foreach (formasPagoDespacho() as $clave => $etiqueta): ?>
                    <button type="button" data-fp="<?= htmlspecialchars($clave, ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($etiqueta, ENT_QUOTES, "UTF-8") ?></button>
                <?php endforeach; ?>
            </div>
            <p class="pf-nota" id="pfPagoAnterior" hidden></p>

            <label id="pfTransportadoraLabel" style="margin-top:16px;">Transportadora *</label>
            <div class="pf-segmento pf-seg-2" id="pfTransportadora" role="group" aria-labelledby="pfTransportadoraLabel">
                <?php foreach (transportadorasDespacho() as $clave => $etiqueta): ?>
                    <button type="button" data-tr="<?= htmlspecialchars($clave, ENT_QUOTES, "UTF-8") ?>"><?= htmlspecialchars($etiqueta, ENT_QUOTES, "UTF-8") ?></button>
                <?php endforeach; ?>
            </div>
            <p class="pf-nota">Estos dos datos pasan al Control de despachos de la oficina.</p>

            <label for="pfObs">Observaciones del pedido</label>
            <textarea id="pfObs" rows="3" maxlength="2000"></textarea>
        </div>

        <?php if ($pf["autosave"]): ?>
            <div class="pf-card">
                <button type="button" class="pf-btn-sec pf-ancho" id="pfBorrador">💾 Guardar como borrador y salir</button>
                <p class="pf-nota">El pedido también se guarda solo como borrador mientras lo llena. Lo encuentra en «Mis pedidos».</p>
            </div>
        <?php endif; ?>
    </section>

    <div class="pf-toast" id="pfToast" hidden></div>

    <div class="pf-barra" id="pfBarra">
        <button type="button" class="pf-atras" id="pfAtras" aria-label="Paso anterior">←</button>
        <div class="pf-total"><small id="pfCuantos">0 productos</small><b id="pfTotal">$ 0</b></div>
        <button type="button" class="pf-btn-prim" id="pfPrincipal">Siguiente →</button>
    </div>

    <div class="pf-dialogo" id="pfDialogo" hidden>
        <div class="pf-dialogo-caja" role="dialog" aria-modal="true" aria-labelledby="pfDialogoTitulo">
            <div class="pf-dialogo-icono" id="pfDialogoIcono">🛒</div>
            <h3 id="pfDialogoTitulo">¿Confirmar?</h3>
            <p id="pfDialogoTexto"></p>
            <div class="pf-dialogo-botones">
                <button type="button" class="pf-dialogo-no" id="pfDialogoNo">Cancelar</button>
                <button type="button" class="pf-dialogo-si" id="pfDialogoSi">Aceptar</button>
            </div>
        </div>
    </div>

    <div class="pf-lightbox" id="pfLightbox" hidden><img alt=""></div>
</div>

<script src="colombia.php?js=1"></script>
<script>
    window.PF_CFG = <?= json_encode($cfgJs, $flagsJson) ?>;
</script>
<script>
(function () {
    var CFG = window.PF_CFG;
    var $ = function (id) { return document.getElementById(id); };
    var S = { paso: 1, tipo: "juridica", doc: "pedido", lista: "", clienteId: null, fe: 0, fp: "", tr: "", pedidoId: CFG.pedidoId, items: {}, bonos: {}, abiertos: {},
              ocupado: false, sucio: false, timer: null, ignorar: false, docOk: "" };

    var todasLasFilas = Array.prototype.slice.call(document.querySelectorAll(".pf-prod"));
    var filas = [];                 // productos de la lista activa (mayorista o detal)
    var PRECIO = {}; var INFO = {};

    // Aplica la lista de precios del tipo de cliente elegido (mayorista o detal) a todos los productos
    function reconstruirLista() {
        var col = S.lista === "detal" ? "precioDetal" : "precioMayorista";
        filas = []; PRECIO = {}; INFO = {};
        todasLasFilas.forEach(function (f) {
            var precio = parseFloat(f.dataset[col]) || 0;
            var disponible = !!S.lista && precio > 0;       // un producto sin precio en esta lista no se puede pedir
            f.dataset.disponible = disponible ? "1" : "0";
            f.hidden = !disponible;
            if (disponible) {
                var id = f.dataset.id;
                filas.push(f);
                PRECIO[id] = precio;
                INFO[id] = { ref: f.querySelector(".pf-prod-info b").textContent, nombre: f.querySelector(".pf-prod-info span").textContent };
                f.querySelector(".pf-prod-info em").textContent = dinero(precio);
                f.querySelector(".pf-bonif-precio").textContent = dinero(precio);
            }
        });
        $("pfListaEtq").textContent = S.lista ? "Lista de precios: " + (S.lista === "detal" ? "DETAL" : "MAYORISTA") : "";
    }

    // Hasta elegir mayorista o detal no se puede llenar nada más
    function aplicarBloqueo() {
        var ok = !!S.lista;
        $("pfResto").classList.toggle("pf-bloqueado", !ok);
        try { $("pfResto").inert = !ok; } catch (e) {}
        $("pfAvisoLista").hidden = ok;
        Array.prototype.forEach.call(document.querySelectorAll("#pfListaTipo button"), function (b) { b.classList.toggle("activo", b.dataset.lista === S.lista); });
    }

    function norm(t) { return (t || "").toString().toLowerCase().normalize("NFD").replace(/[\u0300-\u036f]/g, ""); }
    function dinero(n) { return "$ " + Math.round(n).toLocaleString("es-CO"); }
    function soloDig(s) { return (s || "").replace(/\D+/g, ""); }

    function toast(msg, tipo) {
        var t = $("pfToast");
        t.textContent = msg; t.className = "pf-toast " + (tipo || "");
        t.hidden = false;
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { t.hidden = true; }, 4500);
    }

    /* ---------- Tipo de documento: pedido de cliente / remisión / cotización ---------- */
    var NOMBRE_DOC = { pedido: "pedido", remision: "remisión", cotizacion: "cotización" };
    function setDoc(d) {
        S.doc = NOMBRE_DOC[d] ? d : "pedido";
        Array.prototype.forEach.call(document.querySelectorAll("#pfDocTipo button"), function (b) {
            b.classList.toggle("activo", b.dataset.doc === S.doc);
        });
        var n = NOMBRE_DOC[S.doc];
        $("pfNumeroLabel").textContent = "Número de " + n;
        $("pfNumero").placeholder = CFG.numeroAuto ? "Automático: " + CFG.numeroAuto : "Automático";
        // El botón final dice «Enviar remisión», «Enviar cotización»… (al editar sigue diciendo «Guardar cambios»)
        if (S.paso === 3) { $("pfPrincipal").textContent = textoPrincipal(); }
    }
    function textoPrincipal() {
        return CFG.textoEnviar === "Enviar pedido" ? "Enviar " + NOMBRE_DOC[S.doc] : CFG.textoEnviar;
    }
    Array.prototype.forEach.call(document.querySelectorAll("#pfDocTipo button"), function (b) {
        b.addEventListener("click", function () { if (b.dataset.doc !== S.doc) { setDoc(b.dataset.doc); sucio(); } });
    });
    $("pfNumero").addEventListener("input", function () {
        var limpio = this.value.replace(/\D+/g, "").slice(0, 10);
        if (limpio !== this.value) { this.value = limpio; }
        sucio();
    });

    /* ---------- Tipo de persona ---------- */
    function setTipo(t) {
        S.tipo = t === "natural" ? "natural" : "juridica";
        Array.prototype.forEach.call(document.querySelectorAll("#pfTipo button"), function (b) {
            b.classList.toggle("activo", b.dataset.tipo === S.tipo);
        });
        var nat = S.tipo === "natural";
        $("pfDocLabel").textContent = nat ? "Cédula *" : "NIT *";
        $("pfDoc").placeholder = nat ? "Ej: 1020304050" : "Ej: 900123456-7";
        $("pfNombreLabel").textContent = nat ? "Nombre completo *" : "Razón social *";
    }
    Array.prototype.forEach.call(document.querySelectorAll("#pfTipo button"), function (b) {
        b.addEventListener("click", function () { setTipo(b.dataset.tipo); sucio(); });
    });

    /* ---------- Factura electrónica ---------- */
    function setFE(v) {
        S.fe = v ? 1 : 0;
        Array.prototype.forEach.call(document.querySelectorAll("#pfFE button"), function (b) {
            b.classList.toggle("activo", b.dataset.fe === String(S.fe));
        });
        $("pfFEBox").hidden = !S.fe;
        if (S.fe && !$("pfEmailFe").value) { $("pfEmailFe").value = $("pfEmail").value; }
    }
    Array.prototype.forEach.call(document.querySelectorAll("#pfFE button"), function (b) {
        b.addEventListener("click", function () { setFE(b.dataset.fe === "1"); sucio(); });
    });

    /* ---------- Forma de pago y transportadora ---------- */
    var FP_DIAS = { "3": "dias_3", "5": "dias_5", "8": "dias_8", "30": "dias_30", "45": "dias_45" };
    function diasDeFP(k) { var m = /^dias_(\d+)$/.exec(k || ""); return m ? parseInt(m[1], 10) : null; }
    function marcarOpcion(contenedor, atributo, valor) {
        Array.prototype.forEach.call(document.querySelectorAll("#" + contenedor + " button"), function (b) {
            b.classList.toggle("activo", valor !== "" && b.getAttribute(atributo) === valor);
        });
    }
    function setFP(k) { S.fp = k || ""; marcarOpcion("pfFormaPago", "data-fp", S.fp); $("pfPagoAnterior").hidden = true; }
    function setTR(k) { S.tr = k || ""; marcarOpcion("pfTransportadora", "data-tr", S.tr); }
    Array.prototype.forEach.call(document.querySelectorAll("#pfFormaPago button"), function (b) {
        b.addEventListener("click", function () { setFP(b.getAttribute("data-fp")); sucio(); });
    });
    Array.prototype.forEach.call(document.querySelectorAll("#pfTransportadora button"), function (b) {
        b.addEventListener("click", function () { setTR(b.getAttribute("data-tr")); sucio(); });
    });

    /* ---------- Buscar cliente por NIT / cédula ---------- */
    function llamar(payload, opciones) {
        payload.csrf = CFG.csrf; payload.rol = CFG.rol;
        var o = { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload), credentials: "same-origin" };
        if (opciones && opciones.keepalive) { o.keepalive = true; }
        return fetch(CFG.api, o).then(function (r) {
            // Si la sesión se perdió, el servidor puede mandar a la pantalla de ingreso: se avisa con claridad
            if (r.redirected && /login/i.test(r.url)) {
                return { ok: false, sesion_expirada: true, error: "Su sesión expiró (puede pasar cuando se actualiza el sistema). Abra el ingreso de ejecutivos en otra pestaña, inicie sesión y vuelva a abrir el pedido. Lo que ya se guardó automáticamente se conserva." };
            }
            return r.text().then(function (t) {
                try { return JSON.parse(t); }
                catch (e) {
                    var det = "código " + r.status + (r.redirected ? ", redirigido a " + r.url : "");
                    var ini = (t || "").replace(/<[^>]*>/g, " ").replace(/\s+/g, " ").trim().slice(0, 140);
                    return { ok: false, error: "El servidor no respondió bien (" + det + "). " + ini };
                }
            });
        });
    }

    function msgCliente(texto, clase) {
        var m = $("pfClienteMsg");
        if (!texto) { m.hidden = true; return; }
        m.textContent = texto; m.className = "pf-aviso " + (clase || ""); m.hidden = false;
    }

    function aplicarCliente(c) {
        S.ignorar = true;
        S.clienteId = c.id;
        setTipo(c.tipo_persona);
        $("pfDoc").value = c.identificacion || "";
        S.docOk = soloDig(c.identificacion);
        $("pfNombre").value = c.razon_social || "";
        $("pfComercial").value = c.nombre_comercial || "";
        $("pfTelefono").value = c.telefono || "";
        $("pfEmail").value = c.email || "";
        $("pfBarrio").value = c.barrio || "";
        $("pfDireccion").value = c.direccion || "";
        $("pfPuntos").value = c.puntos_referencia || "";
        if (c.email_fe) { $("pfEmailFe").value = c.email_fe; }
        // Si el cliente ya tiene crédito pactado con un plazo de la lista, se sugiere esa forma de pago (solo si aún no eligió una)
        if (!S.fp && c.condicion_pago === "credito" && FP_DIAS[String(parseInt(c.dias_credito, 10))]) { setFP(FP_DIAS[String(parseInt(c.dias_credito, 10))]); }
        colombiaSet($("pfDepto"), $("pfMuni"), c.departamento, c.municipio).then(function () { S.ignorar = false; sucio(); });
        if (c.inactivo) { msgCliente("⚠️ Cliente encontrado, pero está INACTIVO en el sistema. Consulte con la oficina.", "alerta"); }
        else { msgCliente("✔ Cliente encontrado. Se cargaron sus datos; revíselos y corrija si hace falta. Los datos que le faltaban y usted complete quedarán guardados para los próximos pedidos.", "ok"); }
    }

    function buscarCliente() {
        if (!S.lista) { return; }
        var doc = $("pfDoc").value.trim();
        if (soloDig(doc).length < 5) { msgCliente(""); return; }
        if (soloDig(doc) === S.docOk) { return; }
        S.docOk = soloDig(doc);
        msgCliente("Buscando cliente…", "");
        llamar({ accion: "buscar_cliente", doc: doc, lista: S.lista }).then(function (r) {
            if (!r.ok) { S.docOk = ""; msgCliente(r.error || "No se pudo buscar.", "alerta"); return; }
            if (r.encontrado) { aplicarCliente(r.cliente); }
            else if (r.otro_tipo) {
                S.clienteId = null;
                msgCliente("⚠️ Este cliente está registrado como " + (r.otro_tipo === "detal" ? "DETAL" : "MAYORISTA") +
                           ". Elija arriba «Cliente " + (r.otro_tipo === "detal" ? "detal" : "mayorista") + "» para cargar sus datos y su lista de precios.", "alerta");
            }
            else { S.clienteId = null; msgCliente("Cliente nuevo: complete sus datos. Al enviar el pedido quedará guardado en la base de clientes y la próxima vez aparecerá al buscar su NIT / cédula.", ""); }
        }).catch(function () { msgCliente("Sin conexión: no se pudo buscar el cliente.", "alerta"); });
    }
    var tBuscar = null;
    $("pfDoc").addEventListener("input", function () {
        if (S.clienteId && soloDig($("pfDoc").value) !== S.docOk) { S.clienteId = null; msgCliente(""); }
        clearTimeout(tBuscar);
        tBuscar = setTimeout(buscarCliente, 700);
    });
    $("pfDoc").addEventListener("blur", function () { clearTimeout(tBuscar); buscarCliente(); });
    $("pfBuscar").addEventListener("click", function () { S.docOk = ""; S.clienteId = null; buscarCliente(); });

    /* ---------- Catálogo ---------- */
    function cantidadDe(id) { return S.items[id] || 0; }

    function bonoDe(id) { return S.bonos[id] || 0; }

    function pintarFila(f) {
        var id = f.dataset.id, q = cantidadDe(id), qb = bonoDe(id);
        var inp = f.querySelector(".cant");
        if (document.activeElement !== inp) { inp.value = q; }
        f.classList.toggle("sel", q > 0 || qb > 0);
        // Bonificado: el panel se muestra al pulsar el botón (o si ya hay unidades bonificadas)
        var abierto = !!S.abiertos[id] || qb > 0;
        f.querySelector(".pf-bonif").hidden = !abierto;
        var btn = f.querySelector(".bonif");
        btn.classList.toggle("activo", abierto);
        btn.setAttribute("aria-pressed", abierto ? "true" : "false");
        var inb = f.querySelector(".cantb");
        if (document.activeElement !== inb) { inb.value = qb; }
    }

    function fijarCantidad(id, q) {
        q = Math.max(0, Math.min(100000, parseInt(q, 10) || 0));
        if (q > 0) { S.items[id] = q; } else { delete S.items[id]; }
        var f = document.querySelector('#pfLista .pf-prod[data-id="' + id + '"]');
        if (f) { pintarFila(f); }
        totales();
        sucio();
    }

    function fijarBono(id, q) {
        q = Math.max(0, Math.min(100000, parseInt(q, 10) || 0));
        if (q > 0) { S.bonos[id] = q; } else { delete S.bonos[id]; }
        var f = document.querySelector('#pfLista .pf-prod[data-id="' + id + '"]');
        if (f) { pintarFila(f); }
        totales();
        sucio();
    }

    // El total NO incluye los bonificados (100 % de descuento)
    function totales() {
        var total = 0, n = 0, nb = Object.keys(S.bonos).length;
        Object.keys(S.items).forEach(function (id) { total += S.items[id] * (PRECIO[id] || 0); n++; });
        $("pfTotal").textContent = dinero(total);
        $("pfTotal2").textContent = dinero(total);
        $("pfCuantos").textContent = n + (n === 1 ? " producto" : " productos") + (nb ? " + " + nb + " bonif." : "");
    }

    todasLasFilas.forEach(function (f) {
        var id = f.dataset.id, inp = f.querySelector(".cant");
        f.querySelector(".mas").addEventListener("click", function () { fijarCantidad(id, cantidadDe(id) + 1); });
        f.querySelector(".menos").addEventListener("click", function () { fijarCantidad(id, cantidadDe(id) - 1); });
        inp.addEventListener("input", function () { fijarCantidad(id, inp.value); });
        inp.addEventListener("focus", function () { if (inp.value === "0") { inp.value = ""; } });
        inp.addEventListener("blur", function () { pintarFila(f); });

        // Bonificado
        var inb = f.querySelector(".cantb");
        f.querySelector(".bonif").addEventListener("click", function () {
            if (S.abiertos[id] || bonoDe(id) > 0) {
                var tenia = bonoDe(id) > 0;
                S.abiertos[id] = false;
                if (tenia) { fijarBono(id, 0); toast("Se quitó el bonificado de " + INFO[id].ref + ".", ""); }
                else { pintarFila(f); }
            } else {
                S.abiertos[id] = true;
                pintarFila(f);
            }
        });
        f.querySelector(".bmas").addEventListener("click", function () { fijarBono(id, bonoDe(id) + 1); });
        f.querySelector(".bmenos").addEventListener("click", function () { fijarBono(id, bonoDe(id) - 1); });
        inb.addEventListener("input", function () { fijarBono(id, inb.value); });
        inb.addEventListener("focus", function () { if (inb.value === "0") { inb.value = ""; } });
        inb.addEventListener("blur", function () { pintarFila(f); });
    });

    /* ---------- Búsqueda inteligente ----------
       - Cada palabra se busca por separado (en cualquier orden): «laca negra» = laca + negra.
       - No importan tildes, mayúsculas, plurales ni género (negro / negra / negros).
       - Se entienden colores y términos en español e inglés (negro = black, rosado = pink, ...).
       - Se puede escribir solo el inicio de la palabra («neg», «ros»).
       - Si nada coincide con todas las palabras, se muestran los más parecidos. */
    var GRUPOS = [
        ["negro", "negra", "black", "azabache", "noir"],
        ["blanco", "blanca", "white", "whithe"],
        ["rojo", "roja", "red"],
        ["azul", "blue", "blu", "navy", "royal", "marino"],
        ["celeste", "sky", "cielo"],
        ["verde", "green", "lima", "militar"],
        ["amarillo", "amarilla", "yellow"],
        ["naranja", "anaranjado", "orange", "tangerine"],
        ["rosa", "rosado", "rosada", "pink", "rose"],
        ["fucsia", "fuchsia", "fuxia", "magenta"],
        ["morado", "morada", "violeta", "violetta", "purple", "violet", "lila", "lilac", "purpura"],
        ["dorado", "dorada", "oro", "gold", "golden"],
        ["plateado", "plateada", "plata", "silver", "silvery"],
        ["cafe", "marron", "chocolate", "brown", "caramelo", "caramel"],
        ["gris", "gray", "grey"],
        ["beige", "nude", "piel", "arena"],
        ["turquesa", "turquoise", "aqua", "agua"],
        ["cobre", "copper"],
        ["bronce", "bronze"],
        ["glitter", "escarcha", "brillo", "brillante", "shimmer"],
        ["neon", "fluor", "fluorescente"],
        ["cejas", "ceja", "brow", "betun"],
        ["labial", "labiales", "lipstick", "labios"],
        ["delineador", "delineadores", "eyeliner", "liner"],
        ["sombra", "sombras", "eyeshadow", "shadow"],
        ["rubor", "rubores", "blush", "colorete"],
        ["iluminador", "iluminadores", "highlighter", "glow"],
        ["corrector", "correctores", "concealer"],
        ["contorno", "contour", "contouring"],
        ["perfume", "perfumes", "splash", "fragancia", "body"],
        ["brocha", "brochas", "brush", "pincel"],
        ["laca", "lacas", "lacquer"],
        ["pigmento", "pigmentos", "pigment"],
        ["paleta", "paletas", "palette"]
    ];
    todasLasFilas.forEach(function (f, i) {
        var ref = f.querySelector(".pf-prod-info b").textContent;
        var nom = f.querySelector(".pf-prod-info span").textContent;
        f._txt = " " + norm(ref + " " + nom).replace(/[^a-z0-9]+/g, " ") + " ";
        f._ref = norm(ref).replace(/[^a-z0-9]/g, "");
        f._orden = i;
        f.style.order = i;
    });
    function variantes(t) {
        var v = [t];
        if (t.length >= 4 && /s$/.test(t)) { v.push(t.slice(0, -1)); }
        v.slice().forEach(function (x) { if (x.length >= 5 && /[ao]$/.test(x)) { v.push(x.slice(0, -1)); } });
        return v;
    }
    function expandir(t) {
        var alts = {};
        var vt = variantes(t);
        vt.forEach(function (x) { alts[x] = 1; });
        GRUPOS.forEach(function (g) {
            var coincide = g.some(function (m) { return m === t || (t.length >= 3 && m.indexOf(t) === 0) || vt.indexOf(m) !== -1; });
            if (coincide) { g.forEach(function (m) { variantes(m).forEach(function (x) { alts[x] = 1; }); }); }
        });
        return Object.keys(alts);
    }

    var soloSel = false;
    function filtrar() {
        var q = norm($("pfBuscarProd").value).replace(/[^a-z0-9]+/g, " ").trim();
        var terminos = q ? q.split(" ").filter(function (t) { return t.length >= 2 || /\d/.test(t); }) : [];
        var candidatos = filas.filter(function (f) { return !soloSel || cantidadDe(f.dataset.id) > 0 || bonoDe(f.dataset.id) > 0; });
        var res = [];
        if (!terminos.length) {
            res = candidatos.map(function (f) { return { f: f, score: 1, full: true }; });
        } else {
            var exp = terminos.map(expandir);
            var junto = terminos.join("");
            candidatos.forEach(function (f) {
                var s = 0;
                exp.forEach(function (alts) { if (alts.some(function (a) { return f._txt.indexOf(a) !== -1; })) { s++; } });
                var full = s === terminos.length || f._ref.indexOf(junto) !== -1;
                if (s > 0 || full) { res.push({ f: f, score: full ? terminos.length + 1 : s, full: full }); }
            });
        }
        var hayCompletas = res.some(function (r) { return r.full; });
        var mostrar = hayCompletas ? res.filter(function (r) { return r.full; }) : res;
        mostrar.sort(function (a, b) { return (b.score - a.score) || (a.f._orden - b.f._orden); });

        var visibles = {};
        mostrar.forEach(function (r, i) { visibles[r.f.dataset.id] = 1; r.f.style.order = i; });
        filas.forEach(function (f) { var oculto = !visibles[f.dataset.id]; if (f.hidden !== oculto) { f.hidden = oculto; } });

        var nota = $("pfBusqNota");
        if (terminos.length && !hayCompletas && mostrar.length) {
            nota.textContent = "No hay una coincidencia exacta con «" + $("pfBuscarProd").value.trim() + "». Estos son los más parecidos.";
            nota.hidden = false;
        } else { nota.hidden = true; }
        $("pfSinRes").hidden = mostrar.length !== 0 || filas.length === 0;
    }
    $("pfBuscarProd").addEventListener("input", filtrar);

    /* El buscador no debe perder el cursor mientras se escribe, sin importar cuántos productos queden.
       Si el campo se desenfoca solo (sin que la persona toque otra cosa), se le devuelve el cursor. */
    (function () {
        var inp = $("pfBuscarProd");
        var ultimaTecla = 0, accionPropia = 0;
        function marcar() { accionPropia = Date.now(); }
        inp.addEventListener("input", function () { ultimaTecla = Date.now(); });
        inp.addEventListener("keydown", function (e) { if (e.key === "Enter" || e.key === "Tab" || e.key === "Escape") { marcar(); } });
        document.addEventListener("pointerdown", function (e) { if (e.target !== inp) { marcar(); } }, true);
        document.addEventListener("touchstart", function (e) { if (e.target !== inp) { marcar(); } }, true);
        inp.addEventListener("blur", function () {
            var ahora = Date.now();
            if (ahora - ultimaTecla > 5000 || ahora - accionPropia < 700) { return; }
            setTimeout(function () {
                if (S.paso !== 2 || document.activeElement === inp || !document.hasFocus()) { return; }
                if (!$("pfDialogo").hidden) { return; }
                try { inp.focus({ preventScroll: true }); } catch (e) { inp.focus(); }
            }, 0);
        });
    })();
    $("pfSoloSel").addEventListener("click", function () {
        soloSel = !soloSel;
        this.classList.toggle("activo", soloSel);
        filtrar();
    });

    /* ---------- Resumen (paso 3) ---------- */
    function renderResumen() {
        var cont = $("pfResumen");
        cont.innerHTML = "";
        var lineas = [];
        Object.keys(S.items).forEach(function (id) { lineas.push({ id: id, bon: false }); });
        Object.keys(S.bonos).forEach(function (id) { lineas.push({ id: id, bon: true }); });
        if (!lineas.length) {
            var p = document.createElement("p");
            p.className = "pf-vacio"; p.textContent = "Aún no ha elegido productos. Vaya al paso 2.";
            cont.appendChild(p); return;
        }
        lineas.forEach(function (l) {
            var id = l.id, bon = l.bon;
            var cant = bon ? S.bonos[id] : S.items[id];
            var fila = document.createElement("div"); fila.className = "pf-res-fila" + (bon ? " bonif" : "");
            var info = document.createElement("div"); info.className = "pf-res-info";
            var b = document.createElement("b"); b.textContent = INFO[id].ref;
            if (bon) {
                var tag = document.createElement("small"); tag.className = "pf-tag-bonif"; tag.textContent = "🎁 BONIFICADO";
                b.appendChild(tag);
            }
            var s = document.createElement("span"); s.textContent = INFO[id].nombre;
            var e = document.createElement("em");
            e.textContent = bon
                ? cant + " × " + dinero(PRECIO[id]) + " = " + dinero(0) + " (100 % de descuento)"
                : cant + " × " + dinero(PRECIO[id]) + " = " + dinero(cant * PRECIO[id]);
            info.appendChild(b); info.appendChild(s); info.appendChild(e);
            var st = document.createElement("div"); st.className = "pf-step";
            var m = document.createElement("button"); m.type = "button"; m.textContent = "−";
            var q = document.createElement("span"); q.className = "pf-qty"; q.textContent = cant;
            var a = document.createElement("button"); a.type = "button"; a.textContent = "+";
            m.addEventListener("click", function () { if (bon) { fijarBono(id, bonoDe(id) - 1); } else { fijarCantidad(id, cantidadDe(id) - 1); } renderResumen(); });
            a.addEventListener("click", function () { if (bon) { fijarBono(id, bonoDe(id) + 1); } else { fijarCantidad(id, cantidadDe(id) + 1); } renderResumen(); });
            st.appendChild(m); st.appendChild(q); st.appendChild(a);
            fila.appendChild(info); fila.appendChild(st);
            cont.appendChild(fila);
        });
    }

    /* ---------- Pasos ---------- */
    function irPaso(n) {
        if (n > 1 && !S.lista) {
            toast("Primero elija el tipo de cliente: mayorista o detal.", "error");
            n = 1;
        }
        S.paso = n;
        Array.prototype.forEach.call(document.querySelectorAll(".pf-seccion"), function (s) { s.classList.toggle("activo", s.dataset.paso === String(n)); });
        Array.prototype.forEach.call(document.querySelectorAll(".pf-paso-btn"), function (b) { b.classList.toggle("activo", b.dataset.paso === String(n)); });
        $("pfAtras").style.visibility = n === 1 ? "hidden" : "visible";
        $("pfPrincipal").textContent = n === 3 ? textoPrincipal() : "Siguiente →";
        $("pfPrincipal").classList.toggle("pf-enviar", n === 3);
        if (n === 3) { renderResumen(); }
        if (n === 2) { filtrar(); }
        window.scrollTo(0, 0);
    }
    Array.prototype.forEach.call(document.querySelectorAll(".pf-paso-btn"), function (b) {
        b.addEventListener("click", function () { irPaso(parseInt(b.dataset.paso, 10)); });
    });
    $("pfAtras").addEventListener("click", function () { if (S.paso > 1) { irPaso(S.paso - 1); } });
    $("pfPrincipal").addEventListener("click", function () {
        if (S.paso < 3) { irPaso(S.paso + 1); } else { enviar(); }
    });

    /* ---------- Datos y guardado ---------- */
    function datos() {
        return {
            tipo_documento: S.doc, numero: $("pfNumero").value.trim(),
            tipo_cliente: S.lista, tipo_persona: S.tipo, cliente_id: S.clienteId,
            identificacion: $("pfDoc").value.trim(), cliente_nombre: $("pfNombre").value.trim(),
            cliente_nombre_comercial: $("pfComercial").value.trim(), telefono: $("pfTelefono").value.trim(),
            email: $("pfEmail").value.trim(), departamento: $("pfDepto").value, municipio: $("pfMuni").value,
            barrio: $("pfBarrio").value.trim(), direccion: $("pfDireccion").value.trim(),
            puntos_referencia: $("pfPuntos").value.trim(), factura_electronica: S.fe,
            email_fe: $("pfEmailFe").value.trim(),
            forma_pago: S.fp, transportadora: S.tr,
            // La forma de pago define la condición comercial; si el documento es anterior y no tiene forma de pago, se conserva la que tenía
            condicion_pago: S.fp ? (diasDeFP(S.fp) ? "credito" : "contado") : (D.condicion_pago || "contado"),
            dias_credito: S.fp ? diasDeFP(S.fp) : (D.dias_credito ? parseInt(D.dias_credito, 10) : null),
            observaciones: $("pfObs").value.trim()
        };
    }

    function hayContenido() {
        if (!S.lista) { return false; }
        var d = datos();
        return Object.keys(S.items).length > 0 || Object.keys(S.bonos).length > 0 || d.identificacion || d.cliente_nombre || d.telefono || d.direccion;
    }

    function estadoTexto(t) { $("pfEstado").textContent = t || ""; }

    function guardar(modo, opciones) {
        opciones = opciones || {};
        if (S.ocupado && !opciones.keepalive) { return Promise.resolve(null); }
        S.ocupado = true;
        var payload = { accion: "guardar", modo: modo, pedido_id: S.pedidoId, datos: datos(), items: S.items, bonos: S.bonos };
        return llamar(payload, opciones).then(function (r) {
            S.ocupado = false;
            return r;
        }).catch(function () { S.ocupado = false; return { ok: false, error: "Sin conexión.", red: true }; });
    }

    function sucio() {
        if (S.ignorar) { return; }
        S.sucio = true;
        if (!CFG.autosave) { return; }
        estadoTexto("Cambios sin guardar…");
        clearTimeout(S.timer);
        S.timer = setTimeout(autoGuardar, 1800);
    }

    function autoGuardar(opciones) {
        if (!CFG.autosave || !S.sucio || !hayContenido()) { return Promise.resolve(); }
        if (S.ocupado && !(opciones && opciones.keepalive)) { S.timer = setTimeout(autoGuardar, 1500); return Promise.resolve(); }
        return guardar("borrador", opciones).then(function (r) {
            if (!r) { return; }
            if (r.ok) {
                S.sucio = false;
                if (!S.pedidoId) {
                    S.pedidoId = r.id;
                    if (CFG.urlBase && history.replaceState) { history.replaceState(null, "", CFG.urlBase + "?pedido=" + r.id); }
                }
                var h = new Date();
                estadoTexto("✔ Borrador guardado " + h.toLocaleTimeString("es-CO", { hour: "2-digit", minute: "2-digit" }));
            } else if (r.red) {
                estadoTexto("Sin conexión: se guardará al volver la señal.");
                S.timer = setTimeout(autoGuardar, 8000);
            } else {
                estadoTexto(r.error || "No se pudo guardar el borrador.");
            }
        });
    }

    if (CFG.autosave) {
        document.addEventListener("visibilitychange", function () { if (document.visibilityState === "hidden") { clearTimeout(S.timer); autoGuardar({ keepalive: true }); } });
        window.addEventListener("pagehide", function () { clearTimeout(S.timer); autoGuardar({ keepalive: true }); });
        window.addEventListener("online", function () { autoGuardar(); });
    } else {
        window.addEventListener("beforeunload", function (e) { if (S.sucio) { e.preventDefault(); e.returnValue = ""; } });
    }

    function validar() {
        var d = datos();
        var nat = S.tipo === "natural";
        if (d.numero && !(/^\d{1,10}$/.test(d.numero) && parseInt(d.numero, 10) >= 1)) { return [1, "El número de la " + NOMBRE_DOC[S.doc] + " debe tener solo dígitos y ser mayor que 0.", "pfNumero"]; }
        if (!S.lista) { return [1, "Elija primero el tipo de cliente: mayorista o detal.", "pfListaTipo"]; }
        if (!soloDig(d.identificacion)) { return [1, nat ? "Ingrese la cédula del cliente." : "Ingrese el NIT del cliente.", "pfDoc"]; }
        if (!d.cliente_nombre) { return [1, nat ? "Ingrese el nombre completo del cliente." : "Ingrese la razón social.", "pfNombre"]; }
        if (!d.telefono) { return [1, "Ingrese un teléfono de contacto.", "pfTelefono"]; }
        var ubicacionOpcional = S.pedidoId && !D.departamento && CFG.estadoPedido !== "borrador" && CFG.estadoPedido !== "nuevo";
        if (!ubicacionOpcional && !d.departamento) { return [1, "Seleccione el departamento.", "pfDepto"]; }
        if (!d.direccion) { return [1, "Ingrese la dirección de entrega.", "pfDireccion"]; }
        if (S.fe && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email_fe || d.email)) { return [1, "Ingrese un correo válido para la factura electrónica.", "pfEmailFe"]; }
        if (!Object.keys(S.items).length && !Object.keys(S.bonos).length) { return [2, "Elija al menos un producto.", "pfBuscarProd"]; }
        // Forma de pago y transportadora son obligatorias en documentos nuevos (uno enviado antes de existir este dato puede seguir sin ellos)
        var enviadoAntes = S.pedidoId && CFG.estadoPedido !== "borrador" && CFG.estadoPedido !== "nuevo";
        if (!S.fp && !(enviadoAntes && !D.forma_pago)) { return [3, "Seleccione la forma de pago.", "pfFormaPago"]; }
        if (!S.tr && !(enviadoAntes && !D.transportadora)) { return [3, "Seleccione la transportadora.", "pfTransportadora"]; }
        return null;
    }

    function enviar() {
        var v = validar();
        if (v) {
            irPaso(v[0]); toast(v[1], "error");
            var el = $(v[2]); if (el) { setTimeout(function () { el.focus(); }, 150); }
            return;
        }
        var edicion = S.pedidoId && CFG.estadoPedido !== "borrador" && CFG.estadoPedido !== "nuevo";
        var total = $("pfTotal").textContent;
        var texto = edicion
            ? "Se guardarán los cambios de la " + NOMBRE_DOC[S.doc] + ". Total: " + total + "."
            : "Se enviará la " + NOMBRE_DOC[S.doc] + " por " + total + "." + (CFG.rol === "vendedor" ? " Podrás editarla hasta que la oficina la suba a Syscafe." : "");
        pfConfirmar({
            titulo: edicion ? "¿Guardar los cambios?" : "¿Enviar " + (S.doc === "pedido" ? "el pedido" : "la " + NOMBRE_DOC[S.doc]) + "?",
            texto: texto,
            icono: edicion ? "✏️" : "🛒",
            si: edicion ? "Guardar cambios" : "Sí, enviar",
            onSi: function () {
                clearTimeout(S.timer);
                $("pfPrincipal").disabled = true;
                var intentar = function () {
                    guardar("enviar").then(function (r) {
                        if (r && r.ok) { S.sucio = false; window.location.href = CFG.volver; return; }
                        if (r === null) { setTimeout(intentar, 400); return; }
                        $("pfPrincipal").disabled = false;
                        toast((r && r.error) || "No se pudo enviar el pedido.", "error");
                    });
                };
                intentar();
            }
        });
    }

    if ($("pfBorrador")) {
        $("pfBorrador").addEventListener("click", function () {
            if (!hayContenido()) { toast("Aún no hay nada para guardar.", "error"); return; }
            clearTimeout(S.timer); S.sucio = true;
            autoGuardar().then(function () {
                if (!S.sucio) { window.location.href = CFG.volver; }
                else { toast("No se pudo guardar. Revise la conexión.", "error"); }
            });
        });
    }

    /* ---------- Diálogo de confirmación (reemplaza al confirm() del navegador) ---------- */
    var dialogoSi = null;
    function cerrarDialogo() { $("pfDialogo").hidden = true; dialogoSi = null; }
    window.pfConfirmar = function (o) {
        $("pfDialogoIcono").textContent = o.icono || "❔";
        $("pfDialogoTitulo").textContent = o.titulo || "¿Confirmar?";
        $("pfDialogoTexto").textContent = o.texto || "";
        $("pfDialogoSi").textContent = o.si || "Aceptar";
        $("pfDialogoSi").classList.toggle("peligro", !!o.peligro);
        dialogoSi = o.onSi || null;
        $("pfDialogo").hidden = false;
        $("pfDialogoSi").focus();
    };
    window.pfConfirmarForm = function (form, texto, titulo) {
        pfConfirmar({ titulo: titulo || "¿Eliminar?", texto: texto, icono: "🗑️", si: "Sí, eliminar", peligro: true,
                      onSi: function () { form.submit(); } });
        return false;
    };
    $("pfDialogoNo").addEventListener("click", cerrarDialogo);
    $("pfDialogoSi").addEventListener("click", function () { var f = dialogoSi; cerrarDialogo(); if (f) { f(); } });
    $("pfDialogo").addEventListener("click", function (e) { if (e.target === this) { cerrarDialogo(); } });
    document.addEventListener("keydown", function (e) { if (e.key === "Escape" && !$("pfDialogo").hidden) { cerrarDialogo(); } });

    /* ---------- Foto ampliada ---------- */
    document.addEventListener("click", function (e) {
        var th = e.target.closest ? e.target.closest(".pf-thumb[data-foto]") : null;
        if (th) { $("pfLightbox").querySelector("img").src = th.dataset.foto; $("pfLightbox").hidden = false; return; }
        if (e.target.closest && e.target.closest("#pfLightbox")) { $("pfLightbox").hidden = true; }
    });

    /* ---------- La barra inferior siempre pegada a la parte visible de la pantalla ---------- */
    function ajustarBarra() {
        var vv = window.visualViewport, dif = 0;
        // Solo en celulares/tablets (pantalla táctil). En computador no hay teclado en pantalla y este cálculo subía la barra por error.
        var tactil = window.matchMedia && window.matchMedia("(pointer: coarse)").matches;
        if (vv && tactil) { dif = Math.max(0, Math.round(window.innerHeight - vv.height - vv.offsetTop)); }
        document.documentElement.style.setProperty("--vv-dif", dif + "px");
    }
    if (window.visualViewport) {
        window.visualViewport.addEventListener("resize", ajustarBarra);
        window.visualViewport.addEventListener("scroll", ajustarBarra);
    }
    window.addEventListener("resize", ajustarBarra);
    window.addEventListener("orientationchange", function () { setTimeout(ajustarBarra, 300); });

    /* ---------- Teclado: ocultar la barra inferior para que no se mueva ---------- */
    document.addEventListener("focusin", function (e) {
        if (e.target.matches && e.target.matches("input,textarea,select")) { document.body.classList.add("pf-kb"); }
    });
    document.addEventListener("focusout", function () {
        setTimeout(function () {
            var a = document.activeElement;
            if (!a || !a.matches || !a.matches("input,textarea,select")) { document.body.classList.remove("pf-kb"); }
            ajustarBarra();
            setTimeout(ajustarBarra, 400);   // por si el navegador tarda en recuperar el tamaño tras cerrar el teclado
        }, 120);
    });

    /* Al cerrar el teclado (gesto "atrás", botón de ocultar teclado, etc.) el campo puede seguir enfocado y "focusout"
       nunca se dispara: la barra se quedaba escondida. Se detecta el cierre por el tamaño de la pantalla visible. */
    (function () {
        var vv = window.visualViewport;
        if (!vv) { return; }
        var base = Math.max(window.innerHeight, vv.height);
        function tecladoAbierto() { return vv.height < base - 120; }
        var ultimaTecla = 0;
        // Cada vez que se escribe, el teclado está abierto: la barra se oculta de nuevo
        document.addEventListener("input", function (e) {
            ultimaTecla = Date.now();
            if (e.target && e.target.matches && e.target.matches("input,textarea")) { document.body.classList.add("pf-kb"); }
        }, true);
        function revisarTeclado() {
            // Si se está escribiendo, no es un cierre de teclado (el tamaño cambia al filtrar la lista o al moverse la barra del navegador)
            if (Date.now() - ultimaTecla < 4000) { return; }
            if (!tecladoAbierto()) {
                // Pantalla completa de nuevo: el teclado se cerró
                base = Math.max(base, window.innerHeight, vv.height);
                if (document.body.classList.contains("pf-kb")) {
                    document.body.classList.remove("pf-kb");   // ya NO se desenfoca el campo: solo reaparece la barra
                    ajustarBarra();
                }
            }
        }
        vv.addEventListener("resize", function () { setTimeout(revisarTeclado, 150); });
        window.addEventListener("orientationchange", function () { setTimeout(function () { base = Math.max(window.innerHeight, vv.height); }, 500); });
    })();

    /* ---------- Cambios en cualquier campo ---------- */
    ["pfNombre", "pfComercial", "pfTelefono", "pfEmail", "pfBarrio", "pfDireccion", "pfPuntos", "pfEmailFe", "pfObs", "pfDoc"].forEach(function (id) {
        $(id).addEventListener("input", sucio);
    });
    $("pfDepto").addEventListener("change", sucio);
    $("pfMuni").addEventListener("change", sucio);

    /* ---------- Tipo de cliente: mayorista / detal ---------- */
    function cambiarLista(l) {
        if (l === S.lista) { return; }
        var habiaLista = !!S.lista;
        var habiaProductos = Object.keys(S.items).length > 0;
        S.lista = l;
        S.clienteId = null; S.docOk = "";
        msgCliente("");
        reconstruirLista();
        // Los productos se conservan; solo cambian los precios. Se quitan los que no tengan precio en la nueva lista.
        var quitados = 0;
        Object.keys(S.items).forEach(function (id) { if (PRECIO[id] === undefined) { delete S.items[id]; quitados++; } });
        Object.keys(S.bonos).forEach(function (id) { if (PRECIO[id] === undefined) { delete S.bonos[id]; quitados++; } });
        todasLasFilas.forEach(pintarFila);
        totales();
        aplicarBloqueo();
        sucio();
        if (habiaLista && (habiaProductos || quitados)) {
            toast("Precios actualizados a la lista " + (l === "detal" ? "detal" : "mayorista") + "." +
                  (quitados ? " " + quitados + " producto(s) sin precio en esta lista se quitaron." : ""), quitados ? "error" : "");
        }
        if (soloDig($("pfDoc").value).length >= 5) { buscarCliente(); }
    }
    Array.prototype.forEach.call(document.querySelectorAll("#pfListaTipo button"), function (b) {
        b.addEventListener("click", function () { cambiarLista(b.dataset.lista); });
    });

    /* ---------- Carga inicial ---------- */
    var D = CFG.datos;
    $("pfNumero").value = D.numero || "";
    setDoc(D.tipo_documento);
    setTipo(D.tipo_persona);
    $("pfDoc").value = D.identificacion || "";
    $("pfNombre").value = D.cliente_nombre || "";
    $("pfComercial").value = D.nombre_comercial || "";
    $("pfTelefono").value = D.telefono || "";
    $("pfEmail").value = D.email || "";
    $("pfBarrio").value = D.barrio || "";
    $("pfDireccion").value = D.direccion || "";
    $("pfPuntos").value = D.puntos_referencia || "";
    $("pfEmailFe").value = D.email_fe || "";
    $("pfObs").value = D.observaciones || "";
    setFE(D.factura_electronica ? 1 : 0);
    setFP(D.forma_pago);
    setTR(D.transportadora);
    if (!D.forma_pago && CFG.pagoAnterior) {
        $("pfPagoAnterior").textContent = "Este documento se envió con: " + CFG.pagoAnterior + ". Puede elegir una forma de pago nueva o dejarlo como está.";
        $("pfPagoAnterior").hidden = false;
    }
    S.clienteId = D.cliente_id || null;
    S.docOk = soloDig(D.identificacion);   // al editar, el NIT que ya tiene el pedido no se vuelve a buscar
    S.lista = CFG.tipoCliente || "";
    reconstruirLista();
    aplicarBloqueo();
    Object.keys(CFG.items).forEach(function (id) { if (PRECIO[id] !== undefined) { S.items[id] = CFG.items[id]; } });
    Object.keys(CFG.bonos || {}).forEach(function (id) { if (PRECIO[id] !== undefined) { S.bonos[id] = CFG.bonos[id]; } });
    todasLasFilas.forEach(pintarFila);
    totales();

    S.ignorar = true;
    colombiaInit($("pfDepto"), $("pfMuni")).then(function () {
        return colombiaSet($("pfDepto"), $("pfMuni"), D.departamento, D.municipio);
    }).then(function () { S.ignorar = false; }).catch(function () {
        S.ignorar = false;
        $("pfDepto").innerHTML = '<option value="">No se pudo cargar la lista</option>';
    });

    irPaso(1);
    $("pfPrincipal").textContent = "Siguiente →";
})();
</script>
