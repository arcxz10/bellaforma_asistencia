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
    "observaciones" => $ped["observaciones"] ?? "",
    "cliente_id" => $ped["cliente_id"] ?? null,
];
$itemsIniciales = [];
if ($ped) {
    foreach ($ped["items"] as $it) {
        $itemsIniciales[(int) $it["producto_id"]] = (int) $it["cantidad"];
    }
}
$cfgJs = [
    "rol" => $pf["rol"],
    "csrf" => $pf["csrf"],
    "api" => "pedido_api.php",
    "volver" => $pf["volver"],
    "autosave" => (bool) $pf["autosave"],
    "urlBase" => $pf["url_base"] ?? "",
    "pedidoId" => $ped ? (int) $ped["id"] : null,
    "estadoPedido" => $ped["estado"] ?? "nuevo",
    "datos" => $datosIniciales,
    "items" => (object) $itemsIniciales,
    "textoEnviar" => $pf["texto_enviar"] ?? "Enviar pedido",
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

            <label for="pfMuni">Municipio *</label>
            <select id="pfMuni"><option value="">Primero elija departamento</option></select>

            <label for="pfBarrio">Barrio</label>
            <input type="text" id="pfBarrio" maxlength="120" autocomplete="off">

            <label for="pfDireccion">Dirección de entrega *</label>
            <input type="text" id="pfDireccion" maxlength="255" autocomplete="off">

            <label for="pfPuntos">Puntos de referencia</label>
            <textarea id="pfPuntos" rows="2" maxlength="255" placeholder="Ej: frente al parque, local 2, al lado de la droguería"></textarea>
        </div>

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
    </section>

    <!-- ============ PASO 2: PRODUCTOS ============ -->
    <section class="pf-seccion" data-paso="2">
        <div class="pf-busqueda">
            <input type="search" id="pfBuscarProd" placeholder="🔍 Buscar referencia o nombre" autocomplete="off">
            <button type="button" class="pf-chip" id="pfSoloSel">Elegidos</button>
        </div>

        <div class="pf-lista" id="pfLista">
            <?php if (!$pf["productos"]): ?>
                <p class="pf-vacio">Aún no hay productos cargados.</p>
            <?php endif; ?>
            <?php foreach ($pf["productos"] as $p): ?>
                <div class="pf-prod" data-id="<?= (int) $p["id"] ?>" data-precio="<?= htmlspecialchars((string) $p["precio"], ENT_QUOTES, "UTF-8") ?>"
                     data-busq="<?= htmlspecialchars($p["referencia"] . " " . $p["nombre"], ENT_QUOTES, "UTF-8") ?>">
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
                        <em><?= formatoCOP($p["precio"]) ?></em>
                    </div>
                    <div class="pf-step">
                        <button type="button" class="menos" aria-label="Quitar uno">−</button>
                        <input type="number" class="cant" inputmode="numeric" min="0" max="100000" step="1" value="0" aria-label="Cantidad">
                        <button type="button" class="mas" aria-label="Agregar uno">+</button>
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
            <div class="pf-segmento pf-seg-2" id="pfCond">
                <button type="button" data-cond="contado" class="activo">💵 Contado</button>
                <button type="button" data-cond="credito">🗓️ Crédito</button>
            </div>
            <div id="pfDiasBox" hidden>
                <label for="pfDias">¿Cuántos días de crédito? *</label>
                <input type="number" id="pfDias" inputmode="numeric" min="1" max="365" placeholder="Ej: 30">
                <div class="pf-chips">
                    <button type="button" class="pf-chip" data-dias="8">8 días</button>
                    <button type="button" class="pf-chip" data-dias="15">15 días</button>
                    <button type="button" class="pf-chip" data-dias="30">30 días</button>
                    <button type="button" class="pf-chip" data-dias="45">45 días</button>
                    <button type="button" class="pf-chip" data-dias="60">60 días</button>
                </div>
            </div>

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
    var S = { paso: 1, tipo: "juridica", clienteId: null, fe: 0, cond: "contado", pedidoId: CFG.pedidoId, items: {},
              ocupado: false, sucio: false, timer: null, ignorar: false, docOk: "" };

    var filas = Array.prototype.slice.call(document.querySelectorAll(".pf-prod"));
    var PRECIO = {}; var INFO = {};
    filas.forEach(function (f) {
        var id = f.dataset.id;
        PRECIO[id] = parseFloat(f.dataset.precio) || 0;
        INFO[id] = { ref: f.querySelector(".pf-prod-info b").textContent, nombre: f.querySelector(".pf-prod-info span").textContent };
    });

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

    /* ---------- Condiciones de pago ---------- */
    function setCond(v) {
        S.cond = v === "credito" ? "credito" : "contado";
        Array.prototype.forEach.call(document.querySelectorAll("#pfCond button"), function (b) {
            b.classList.toggle("activo", b.dataset.cond === S.cond);
        });
        $("pfDiasBox").hidden = S.cond !== "credito";
    }
    Array.prototype.forEach.call(document.querySelectorAll("#pfCond button"), function (b) {
        b.addEventListener("click", function () { setCond(b.dataset.cond); sucio(); });
    });
    Array.prototype.forEach.call(document.querySelectorAll("[data-dias]"), function (b) {
        b.addEventListener("click", function () { $("pfDias").value = b.dataset.dias; sucio(); });
    });

    /* ---------- Buscar cliente por NIT / cédula ---------- */
    function llamar(payload, opciones) {
        payload.csrf = CFG.csrf; payload.rol = CFG.rol;
        var o = { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify(payload), credentials: "same-origin" };
        if (opciones && opciones.keepalive) { o.keepalive = true; }
        return fetch(CFG.api, o).then(function (r) { return r.json().catch(function () { return { ok: false, error: "Respuesta no válida." }; }); });
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
        setCond(c.condicion_pago);
        $("pfDias").value = c.dias_credito || "";
        colombiaSet($("pfDepto"), $("pfMuni"), c.departamento, c.municipio).then(function () { S.ignorar = false; sucio(); });
        if (c.inactivo) { msgCliente("⚠️ Cliente encontrado, pero está INACTIVO en el sistema. Consulte con la oficina.", "alerta"); }
        else { msgCliente("✔ Cliente encontrado. Se cargaron sus datos; revíselos y corrija si hace falta.", "ok"); }
    }

    function buscarCliente() {
        var doc = $("pfDoc").value.trim();
        if (soloDig(doc).length < 5) { msgCliente(""); return; }
        if (S.clienteId && soloDig(doc) === S.docOk) { return; }
        msgCliente("Buscando cliente…", "");
        llamar({ accion: "buscar_cliente", doc: doc }).then(function (r) {
            if (!r.ok) { msgCliente(r.error || "No se pudo buscar.", "alerta"); return; }
            if (r.encontrado) { aplicarCliente(r.cliente); }
            else { S.clienteId = null; msgCliente("Cliente nuevo: complete sus datos para el pedido.", ""); }
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

    function pintarFila(f) {
        var q = cantidadDe(f.dataset.id);
        var inp = f.querySelector(".cant");
        if (document.activeElement !== inp) { inp.value = q; }
        f.classList.toggle("sel", q > 0);
    }

    function fijarCantidad(id, q) {
        q = Math.max(0, Math.min(100000, parseInt(q, 10) || 0));
        if (q > 0) { S.items[id] = q; } else { delete S.items[id]; }
        var f = document.querySelector('.pf-prod[data-id="' + id + '"]');
        if (f) { pintarFila(f); }
        totales();
        sucio();
    }

    function totales() {
        var total = 0, n = 0;
        Object.keys(S.items).forEach(function (id) { total += S.items[id] * (PRECIO[id] || 0); n++; });
        $("pfTotal").textContent = dinero(total);
        $("pfTotal2").textContent = dinero(total);
        $("pfCuantos").textContent = n + (n === 1 ? " producto" : " productos");
    }

    filas.forEach(function (f) {
        var id = f.dataset.id, inp = f.querySelector(".cant");
        f.querySelector(".mas").addEventListener("click", function () { fijarCantidad(id, cantidadDe(id) + 1); });
        f.querySelector(".menos").addEventListener("click", function () { fijarCantidad(id, cantidadDe(id) - 1); });
        inp.addEventListener("input", function () { fijarCantidad(id, inp.value); });
        inp.addEventListener("focus", function () { if (inp.value === "0") { inp.value = ""; } });
        inp.addEventListener("blur", function () { pintarFila(f); });
    });

    var soloSel = false;
    function filtrar() {
        var q = norm($("pfBuscarProd").value.trim());
        var vis = 0;
        filas.forEach(function (f) {
            var ok = (q === "" || norm(f.dataset.busq).indexOf(q) !== -1) && (!soloSel || cantidadDe(f.dataset.id) > 0);
            f.hidden = !ok;
            if (ok) { vis++; }
        });
        $("pfSinRes").hidden = vis !== 0 || filas.length === 0;
    }
    $("pfBuscarProd").addEventListener("input", filtrar);
    $("pfSoloSel").addEventListener("click", function () {
        soloSel = !soloSel;
        this.classList.toggle("activo", soloSel);
        filtrar();
    });

    /* ---------- Resumen (paso 3) ---------- */
    function renderResumen() {
        var cont = $("pfResumen");
        cont.innerHTML = "";
        var ids = Object.keys(S.items);
        if (!ids.length) {
            var p = document.createElement("p");
            p.className = "pf-vacio"; p.textContent = "Aún no ha elegido productos. Vaya al paso 2.";
            cont.appendChild(p); return;
        }
        ids.forEach(function (id) {
            var fila = document.createElement("div"); fila.className = "pf-res-fila";
            var info = document.createElement("div"); info.className = "pf-res-info";
            var b = document.createElement("b"); b.textContent = INFO[id].ref;
            var s = document.createElement("span"); s.textContent = INFO[id].nombre;
            var e = document.createElement("em"); e.textContent = S.items[id] + " × " + dinero(PRECIO[id]) + " = " + dinero(S.items[id] * PRECIO[id]);
            info.appendChild(b); info.appendChild(s); info.appendChild(e);
            var st = document.createElement("div"); st.className = "pf-step";
            var m = document.createElement("button"); m.type = "button"; m.textContent = "−";
            var q = document.createElement("span"); q.className = "pf-qty"; q.textContent = S.items[id];
            var a = document.createElement("button"); a.type = "button"; a.textContent = "+";
            m.addEventListener("click", function () { fijarCantidad(id, cantidadDe(id) - 1); renderResumen(); });
            a.addEventListener("click", function () { fijarCantidad(id, cantidadDe(id) + 1); renderResumen(); });
            st.appendChild(m); st.appendChild(q); st.appendChild(a);
            fila.appendChild(info); fila.appendChild(st);
            cont.appendChild(fila);
        });
    }

    /* ---------- Pasos ---------- */
    function irPaso(n) {
        S.paso = n;
        Array.prototype.forEach.call(document.querySelectorAll(".pf-seccion"), function (s) { s.classList.toggle("activo", s.dataset.paso === String(n)); });
        Array.prototype.forEach.call(document.querySelectorAll(".pf-paso-btn"), function (b) { b.classList.toggle("activo", b.dataset.paso === String(n)); });
        $("pfAtras").style.visibility = n === 1 ? "hidden" : "visible";
        $("pfPrincipal").textContent = n === 3 ? CFG.textoEnviar : "Siguiente →";
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
            tipo_persona: S.tipo, cliente_id: S.clienteId,
            identificacion: $("pfDoc").value.trim(), cliente_nombre: $("pfNombre").value.trim(),
            cliente_nombre_comercial: $("pfComercial").value.trim(), telefono: $("pfTelefono").value.trim(),
            email: $("pfEmail").value.trim(), departamento: $("pfDepto").value, municipio: $("pfMuni").value,
            barrio: $("pfBarrio").value.trim(), direccion: $("pfDireccion").value.trim(),
            puntos_referencia: $("pfPuntos").value.trim(), factura_electronica: S.fe,
            email_fe: $("pfEmailFe").value.trim(), condicion_pago: S.cond,
            dias_credito: S.cond === "credito" ? (parseInt($("pfDias").value, 10) || 0) : null,
            observaciones: $("pfObs").value.trim()
        };
    }

    function hayContenido() {
        var d = datos();
        return Object.keys(S.items).length > 0 || d.identificacion || d.cliente_nombre || d.telefono || d.direccion;
    }

    function estadoTexto(t) { $("pfEstado").textContent = t || ""; }

    function guardar(modo, opciones) {
        opciones = opciones || {};
        if (S.ocupado && !opciones.keepalive) { return Promise.resolve(null); }
        S.ocupado = true;
        var payload = { accion: "guardar", modo: modo, pedido_id: S.pedidoId, datos: datos(), items: S.items };
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
        if (!soloDig(d.identificacion)) { return [1, nat ? "Ingrese la cédula del cliente." : "Ingrese el NIT del cliente.", "pfDoc"]; }
        if (!d.cliente_nombre) { return [1, nat ? "Ingrese el nombre completo del cliente." : "Ingrese la razón social.", "pfNombre"]; }
        if (!d.telefono) { return [1, "Ingrese un teléfono de contacto.", "pfTelefono"]; }
        if (!d.departamento) { return [1, "Seleccione el departamento.", "pfDepto"]; }
        if (!d.municipio) { return [1, "Seleccione el municipio.", "pfMuni"]; }
        if (!d.direccion) { return [1, "Ingrese la dirección de entrega.", "pfDireccion"]; }
        if (S.fe && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(d.email_fe || d.email)) { return [1, "Ingrese un correo válido para la factura electrónica.", "pfEmailFe"]; }
        if (!Object.keys(S.items).length) { return [2, "Elija al menos un producto.", "pfBuscarProd"]; }
        if (S.cond === "credito" && !(d.dias_credito >= 1 && d.dias_credito <= 365)) { return [3, "Indique cuántos días de crédito.", "pfDias"]; }
        return null;
    }

    function enviar() {
        var v = validar();
        if (v) {
            irPaso(v[0]); toast(v[1], "error");
            var el = $(v[2]); if (el) { setTimeout(function () { el.focus(); }, 150); }
            return;
        }
        var pregunta = S.pedidoId && CFG.estadoPedido !== "borrador" && CFG.estadoPedido !== "nuevo"
            ? "¿Guardar los cambios del pedido?" : "¿Enviar el pedido por " + $("pfTotal").textContent + "?";
        if (!confirm(pregunta)) { return; }
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

    /* ---------- Foto ampliada ---------- */
    document.addEventListener("click", function (e) {
        var th = e.target.closest ? e.target.closest(".pf-thumb[data-foto]") : null;
        if (th) { $("pfLightbox").querySelector("img").src = th.dataset.foto; $("pfLightbox").hidden = false; return; }
        if (e.target.closest && e.target.closest("#pfLightbox")) { $("pfLightbox").hidden = true; }
    });

    /* ---------- Teclado: ocultar la barra inferior para que no se mueva ---------- */
    document.addEventListener("focusin", function (e) {
        if (e.target.matches && e.target.matches("input,textarea,select")) { document.body.classList.add("pf-kb"); }
    });
    document.addEventListener("focusout", function () {
        setTimeout(function () {
            var a = document.activeElement;
            if (!a || !a.matches || !a.matches("input,textarea,select")) { document.body.classList.remove("pf-kb"); }
        }, 120);
    });

    /* ---------- Cambios en cualquier campo ---------- */
    ["pfNombre", "pfComercial", "pfTelefono", "pfEmail", "pfBarrio", "pfDireccion", "pfPuntos", "pfEmailFe", "pfDias", "pfObs", "pfDoc"].forEach(function (id) {
        $(id).addEventListener("input", sucio);
    });
    $("pfDepto").addEventListener("change", sucio);
    $("pfMuni").addEventListener("change", sucio);

    /* ---------- Carga inicial ---------- */
    var D = CFG.datos;
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
    $("pfDias").value = D.dias_credito || "";
    $("pfObs").value = D.observaciones || "";
    setFE(D.factura_electronica ? 1 : 0);
    setCond(D.condicion_pago);
    S.clienteId = D.cliente_id || null;
    if (S.clienteId) { S.docOk = soloDig(D.identificacion); }
    Object.keys(CFG.items).forEach(function (id) { if (PRECIO[id] !== undefined) { S.items[id] = CFG.items[id]; } });
    filas.forEach(pintarFila);
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
