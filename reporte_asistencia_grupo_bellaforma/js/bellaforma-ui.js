
/* ==========================================================
   BELLAFORMA · Animación de carga "Florecer"
   La flor del logo (8 pétalos + 8 puntos + monograma GB) se
   abre y se cierra mientras algo está cargando.

   - Con data-splash en el <script> (index, login, registro):
     pantalla de bienvenida al abrir la página.
   - En todas las páginas: aparece sola cuando una navegación,
     un envío de formulario o una eliminación tarda en responder.
   - Uso manual (por ejemplo con fetch):
       Bellaforma.cargando('Eliminando…');   // mostrar
       Bellaforma.listo();                   // ocultar
       Bellaforma.envolver(fetch(url), 'Guardando…'); // automático
   Los estilos están en css/base.css.
   ========================================================== */
(function () {
    'use strict';

    var script = document.currentScript;
    var conSplash = !!(script && script.hasAttribute('data-splash'));
    var html = document.documentElement;
    var reducirMovimiento = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);

    /* ---------- Trazos vectorizados del logo ---------- */
    var PETALO = "M2.5 -436.0 0.5 -436.0 -0.5 -434.0 -14.5 -424.0 -21.5 -417.0 -22.5 -417.0 -37.5 -401.0 -50.5 -383.0 -62.5 -361.0 -62.5 -359.0 -65.5 -354.0 -65.5 -352.0 -67.5 -349.0 -67.5 -347.0 -70.5 -340.0 -70.5 -337.0 -71.5 -336.0 -73.5 -326.0 -74.5 -325.0 -75.5 -315.0 -76.5 -314.0 -77.5 -294.0 -78.5 -293.0 -78.5 -282.0 -77.5 -281.0 -77.5 -269.0 -76.5 -268.0 -76.5 -263.0 -75.5 -262.0 -74.5 -254.0 -68.5 -239.0 -59.5 -227.0 -58.5 -227.0 -54.5 -223.0 -48.5 -220.0 -35.5 -220.0 -34.5 -221.0 -29.5 -222.0 -18.5 -228.0 -14.5 -232.0 -13.5 -232.0 -4.5 -242.0 1.5 -251.0 7.5 -263.0 7.5 -265.0 12.5 -278.0 12.5 -281.0 13.5 -282.0 14.5 -292.0 15.5 -293.0 15.5 -301.0 16.5 -302.0 16.5 -326.0 15.5 -327.0 15.5 -335.0 14.5 -336.0 14.5 -342.0 13.5 -343.0 12.5 -352.0 11.5 -353.0 10.5 -360.0 7.5 -368.0 7.5 -370.0 8.5 -371.0 12.5 -366.0 20.5 -350.0 20.5 -348.0 22.5 -345.0 22.5 -343.0 24.5 -340.0 24.5 -338.0 28.5 -328.0 28.5 -325.0 30.5 -320.0 30.5 -316.0 31.5 -315.0 31.5 -310.0 32.5 -309.0 32.5 -299.0 33.5 -298.0 32.5 -276.0 31.5 -275.0 31.5 -271.0 30.5 -270.0 30.5 -267.0 29.5 -266.0 29.5 -263.0 28.5 -262.0 26.5 -254.0 19.5 -240.0 9.5 -226.0 -2.5 -214.0 -3.5 -214.0 -8.5 -209.0 -12.5 -207.0 -15.5 -204.0 -37.5 -192.0 -39.5 -192.0 -47.5 -188.0 -49.5 -188.0 -62.5 -183.0 -65.5 -183.0 -65.5 -177.0 -64.5 -176.0 -63.5 -166.0 -62.5 -165.0 -62.5 -158.0 -61.5 -157.0 -61.5 -146.0 -60.5 -145.0 -60.5 -123.0 -61.5 -122.0 -61.5 -112.0 -60.5 -111.0 -37.5 -117.0 -34.5 -119.0 -27.5 -121.0 -22.5 -124.0 -20.5 -124.0 -14.5 -127.0 -12.5 -129.0 -5.5 -132.0 19.5 -150.0 38.5 -169.0 38.5 -170.0 51.5 -186.0 66.5 -213.0 66.5 -215.0 70.5 -223.0 70.5 -225.0 75.5 -238.0 75.5 -241.0 77.5 -246.0 77.5 -250.0 78.5 -251.0 78.5 -255.0 79.5 -256.0 80.5 -271.0 81.5 -272.0 81.5 -301.0 80.5 -302.0 80.5 -310.0 79.5 -311.0 79.5 -317.0 78.5 -318.0 78.5 -322.0 77.5 -323.0 77.5 -327.0 76.5 -328.0 75.5 -335.0 74.5 -336.0 70.5 -350.0 66.5 -358.0 66.5 -360.0 56.5 -379.0 46.5 -394.0 37.5 -404.0 37.5 -405.0 21.5 -421.0 20.5 -421.0 10.5 -430.0 6.5 -432.0Z";
    var MONOGRAMA = "M-47.5 -87.0 -48.5 -86.0 -53.5 -86.0 -54.5 -85.0 -61.5 -84.0 -81.5 -75.0 -92.5 -67.0 -103.5 -55.0 -107.5 -49.0 -109.5 -44.0 -111.5 -42.0 -111.5 -40.0 -115.5 -32.0 -117.5 -22.0 -118.5 -21.0 -119.5 -9.0 -120.5 -8.0 -120.5 5.0 -119.5 6.0 -119.5 12.0 -118.5 13.0 -118.5 17.0 -117.5 18.0 -114.5 29.0 -110.5 37.0 -100.5 51.0 -85.5 65.0 -69.5 74.0 -67.5 74.0 -64.5 76.0 -62.5 76.0 -58.5 78.0 -54.5 78.0 -53.5 79.0 -49.5 79.0 -48.5 80.0 -23.5 80.0 -22.5 79.0 -17.5 79.0 -13.5 77.0 -7.5 76.0 5.5 70.0 9.5 67.0 10.5 65.0 10.5 16.0 7.5 14.0 -7.5 14.0 -8.5 15.0 -8.5 51.0 -12.5 56.0 -17.5 58.0 -24.5 59.0 -25.5 60.0 -45.5 60.0 -46.5 59.0 -54.5 58.0 -62.5 54.0 -64.5 54.0 -76.5 46.0 -85.5 37.0 -90.5 30.0 -97.5 15.0 -98.5 7.0 -99.5 6.0 -100.5 -8.0 -99.5 -9.0 -98.5 -20.0 -92.5 -34.0 -85.5 -44.0 -76.5 -53.0 -69.5 -58.0 -54.5 -65.0 -46.5 -66.0 -45.5 -67.0 -40.5 -67.0 -35.5 -69.0 -35.5 -85.0 -37.5 -87.0ZM-6.5 -87.0 -8.5 -84.0 -8.5 -27.0 -6.5 -24.0 -4.5 -24.0 -3.5 -23.0 5.5 -23.0 9.5 -25.0 9.5 -27.0 10.5 -28.0 10.5 -67.0 12.5 -69.0 59.5 -69.0 68.5 -65.0 76.5 -57.0 79.5 -51.0 80.5 -45.0 81.5 -44.0 81.5 -37.0 77.5 -26.0 68.5 -17.0 58.5 -13.0 -63.5 -13.0 -65.5 -11.0 -65.5 4.0 -64.5 5.0 -29.5 5.0 -28.5 6.0 57.5 6.0 58.5 7.0 63.5 8.0 72.5 15.0 75.5 19.0 79.5 28.0 79.5 38.0 78.5 39.0 78.5 42.0 75.5 48.0 71.5 52.0 71.5 53.0 66.5 57.0 58.5 60.0 51.5 60.0 50.5 61.0 21.5 61.0 19.5 63.0 16.5 70.0 9.5 76.0 3.5 79.0 3.5 80.0 61.5 80.0 62.5 79.0 65.5 79.0 66.5 78.0 71.5 77.0 79.5 73.0 88.5 65.0 93.5 58.0 95.5 54.0 95.5 52.0 97.5 49.0 97.5 46.0 99.5 41.0 99.5 25.0 98.5 24.0 98.5 21.0 97.5 20.0 96.5 15.0 90.5 5.0 83.5 -3.0 83.5 -5.0 92.5 -15.0 97.5 -25.0 98.5 -31.0 99.5 -32.0 99.5 -49.0 98.5 -50.0 98.5 -53.0 97.5 -54.0 96.5 -59.0 93.5 -65.0 89.5 -71.0 81.5 -79.0 67.5 -86.0 55.5 -87.0 54.5 -88.0 -3.5 -88.0 -4.5 -87.0Z";
    var PUNTOS = [[-166.6,-391.8],[162.9,-391.0],[395.3,-157.5],[394.6,172.0],[161.0,404.4],[-168.4,403.6],[-400.9,170.1],[-400.1,-159.4]];

    function crearFlor() {
        var petalos = '';
        var puntos = '';
        var i;

        for (i = 0; i < 8; i++) {
            petalos +=
                '<g transform="rotate(' + (i * 45) + ')">' +
                    '<path class="petalo" style="--i:' + i + '" fill-rule="evenodd" d="' + PETALO + '"/>' +
                '</g>';
            puntos += '<circle class="punto" style="--i:' + i + '" cx="' + PUNTOS[i][0] + '" cy="' + PUNTOS[i][1] + '" r="30.4"/>';
        }

        return '<svg class="bf-flor" viewBox="-440 -440 880 880" aria-hidden="true" focusable="false">' +
            petalos + puntos +
            '<path class="monograma" fill-rule="evenodd" d="' + MONOGRAMA + '"/>' +
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
                '<p class="bf-splash__texto" hidden></p>' +
                '<span class="bf-sr">' + (texto || 'Cargando') + '</span>' +
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
        var minimo = reducirMovimiento ? 250 : (yaVisto ? 1200 : 2400);
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

    /* ---------- Loader durante la página (navegar, enviar, eliminar, fetch) ---------- */
    var overlay = null;
    var texto = null;
    var temporizador = null;
    var activos = 0;
    var omitirSiguiente = false;

    function asegurar() {
        if (overlay) { return; }
        overlay = crearOverlay('bf-nav', 'Cargando');
        texto = overlay.querySelector('.bf-splash__texto');
        document.body.appendChild(overlay);
        void overlay.offsetWidth; // fuerza el reflujo para que la transición se ejecute
    }

    function mostrar(mensaje, segundosMax) {
        asegurar();
        if (mensaje) {
            texto.textContent = mensaje;
            texto.hidden = false;
        } else {
            texto.hidden = true;
        }
        overlay.classList.add('is-on');
        window.clearTimeout(temporizador);
        // Seguro: si nada lo oculta (p. ej. una descarga), se va solo
        temporizador = window.setTimeout(ocultar, (segundosMax || 8) * 1000);
    }

    function ocultar() {
        window.clearTimeout(temporizador);
        activos = 0;
        if (overlay) { overlay.classList.remove('is-on'); }
    }

    /* Las descargas y las pestañas nuevas no cambian la página: no se tapa la pantalla */
    var PATRON_DESCARGA = /(pdf|excel|xlsx|xls|csv|descarg|export|plantilla|foto|qr)/i;

    function esSalidaLarga(el) {
        if (!el) { return false; }
        if (el.hasAttribute && el.hasAttribute('download')) { return false; }
        if (el.target && el.target !== '_self') { return false; }
        var destino = el.getAttribute ? (el.getAttribute('href') || el.getAttribute('action') || '') : '';
        return !PATRON_DESCARGA.test(destino);
    }

    function iniciarNavegacion() {
        document.addEventListener('click', function (e) {
            var a = e.target.closest ? e.target.closest('a[href]') : null;
            if (!a) { return; }
            var href = a.getAttribute('href') || '';
            if (/^(#|javascript:|mailto:|tel:)/i.test(href) || !esSalidaLarga(a)) { omitirSiguiente = true; }
            else { omitirSiguiente = false; }
        }, true);

        document.addEventListener('submit', function (e) {
            omitirSiguiente = !esSalidaLarga(e.target);
        }, true);

        window.addEventListener('beforeunload', function () {
            if (omitirSiguiente) { omitirSiguiente = false; return; }
            mostrar();
        });
        window.addEventListener('pageshow', ocultar);
    }

    /* ---------- API pública ---------- */
    window.Bellaforma = {
        cargando: function (mensaje) { activos++; mostrar(mensaje, 30); },
        listo: function () { activos = Math.max(0, activos - 1); if (activos === 0) { ocultar(); } },
        envolver: function (promesa, mensaje) {
            var self = window.Bellaforma;
            self.cargando(mensaje);
            var fin = function () { self.listo(); };
            promesa.then(fin, fin);
            return promesa;
        },
        flor: crearFlor
    };

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
