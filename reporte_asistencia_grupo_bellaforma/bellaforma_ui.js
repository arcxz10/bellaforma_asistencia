/* ==========================================================
   BELLAFORMA · Animación de carga "Florecer"
   - Con data-splash en el <script> (index, login, registro):
     muestra la pantalla de bienvenida al abrir la página.
   - En todas las páginas: muestra una versión compacta cuando
     una navegación o envío de formulario tarda en responder.
   Los estilos están en css/base.css.
   ========================================================== */
(function () {
    'use strict';

    var script = document.currentScript;
    var conSplash = !!(script && script.hasAttribute('data-splash'));
    var html = document.documentElement;
    var reducirMovimiento = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    /* ---------- SVG: ocho pétalos alrededor del monograma GB ---------- */
    function crearFlor() {
        var petalos = '';
        var puntos = '';
        var i, angulo, rad, x, y;

        for (i = 0; i < 8; i++) {
            angulo = i * 45;
            petalos +=
                '<g transform="rotate(' + angulo + ')">' +
                    '<path class="petalo' + (i % 2 ? ' verde' : '') + '" style="--i:' + i + '" ' +
                        'd="M0 -14 C15 -24 16 -42 2 -55 C-12 -42 -14 -26 0 -14Z"/>' +
                    '<path class="nervio" style="--i:' + i + '" d="M1 -23 C6 -32 6 -42 2 -49"/>' +
                '</g>';

            rad = (angulo + 22.5) * Math.PI / 180;
            x = (Math.sin(rad) * 63).toFixed(2);
            y = (-Math.cos(rad) * 63).toFixed(2);
            puntos += '<circle class="punto" style="--i:' + i + '" cx="' + x + '" cy="' + y + '" r="3"/>';
        }

        return '<svg class="bf-flor" viewBox="-72 -72 144 144" aria-hidden="true" focusable="false">' +
            petalos + puntos +
            '<circle class="aro" r="12.5"/>' +
            '<text class="monograma" x="0" y="0.5">GB</text>' +
        '</svg>';
    }

    function crearOverlay(clase, texto) {
        var el = document.createElement('div');
        el.className = 'bf-splash ' + clase;
        el.setAttribute('role', 'status');
        el.setAttribute('aria-live', 'polite');
        el.innerHTML =
            '<div class="bf-splash__centro">' +
                crearFlor() +
                '<p class="bf-splash__marca">Grupo Bella Forma</p>' +
                '<span class="bf-sr">' + texto + '</span>' +
            '</div>';
        return el;
    }

    function leerSesion(clave) {
        try { return window.sessionStorage.getItem(clave); } catch (e) { return null; }
    }

    function guardarSesion(clave, valor) {
        try { window.sessionStorage.setItem(clave, valor); } catch (e) { /* sin almacenamiento: se ignora */ }
    }

    /* ---------- Pantalla de bienvenida ---------- */
    function iniciarSplash() {
        var splash = crearOverlay('', 'Cargando');
        var inicio = Date.now();
        var yaVisto = leerSesion('bf_splash_visto') === '1';
        var minimo = reducirMovimiento ? 250 : (yaVisto ? 900 : 1700);
        var cerrado = false;

        document.body.insertBefore(splash, document.body.firstChild);
        html.classList.add('bf-bloqueado');
        guardarSesion('bf_splash_visto', '1');

        function cerrar() {
            if (cerrado) { return; }
            cerrado = true;
            var resto = Math.max(0, minimo - (Date.now() - inicio));
            window.setTimeout(function () {
                splash.classList.add('is-leaving');
                html.classList.remove('bf-bloqueado');
                window.setTimeout(function () {
                    if (splash.parentNode) { splash.parentNode.removeChild(splash); }
                }, 800);
            }, resto);
        }

        if (document.readyState === 'complete') {
            cerrar();
        } else {
            window.addEventListener('load', cerrar);
        }

        // Seguro: nunca dejar la pantalla de bienvenida más de 6 s
        window.setTimeout(cerrar, 6000);
    }

    /* ---------- Transición entre páginas ---------- */
    function iniciarNavegacion() {
        var overlay = null;
        var temporizador = null;

        function mostrar() {
            if (!overlay) {
                overlay = crearOverlay('bf-nav', 'Cargando');
                document.body.appendChild(overlay);
                // fuerza el reflujo para que la transición de opacidad se ejecute
                void overlay.offsetWidth;
            }
            overlay.classList.add('is-on');
            window.clearTimeout(temporizador);
            // Si la navegación era una descarga o no ocurrió, se oculta sola
            temporizador = window.setTimeout(ocultar, 6000);
        }

        function ocultar() {
            window.clearTimeout(temporizador);
            if (overlay) { overlay.classList.remove('is-on'); }
        }

        window.addEventListener('beforeunload', mostrar);
        window.addEventListener('pageshow', ocultar);
    }

    function arrancar() {
        if (conSplash) { iniciarSplash(); }
        iniciarNavegacion();
    }

    if (document.body) {
        arrancar();
    } else {
        document.addEventListener('DOMContentLoaded', arrancar);
    }
})();
