/* Comportamientos pequeños del portal: mostrar contraseña, pestañas y la
   selección de vigencias con el total en vivo. Sin dependencias. */
(function () {
    'use strict';

    // Mostrar u ocultar la contraseña.
    document.querySelectorAll('.ver-clave').forEach(function (b) {
        b.addEventListener('click', function () {
            var input = b.parentNode.querySelector('input');
            var ver = input.type === 'password';
            input.type = ver ? 'text' : 'password';
            b.setAttribute('aria-label', ver ? 'Ocultar contraseña' : 'Mostrar contraseña');
        });
    });

    // Pestañas accesibles (flechas izquierda/derecha).
    document.querySelectorAll('[data-pestanas]').forEach(function (caja) {
        var botones = Array.prototype.slice.call(caja.querySelectorAll('[role="tab"]'));
        function activar(b) {
            botones.forEach(function (x) {
                var si = x === b;
                x.setAttribute('aria-selected', si ? 'true' : 'false');
                x.tabIndex = si ? 0 : -1;
                document.getElementById(x.getAttribute('aria-controls')).hidden = !si;
            });
            b.focus();
        }
        botones.forEach(function (b, i) {
            b.addEventListener('click', function () { activar(b); });
            b.addEventListener('keydown', function (ev) {
                if (ev.key === 'ArrowRight') { activar(botones[(i + 1) % botones.length]); }
                if (ev.key === 'ArrowLeft') { activar(botones[(i - 1 + botones.length) % botones.length]); }
            });
        });
    });

    // Vigencias: se pagan años seguidos. Marcar o desmarcar un año completa
    // o recorta el rango para que no queden huecos.
    var tabla = document.querySelector('[data-vigencias]');
    if (!tabla) { return; }
    var filas = Array.prototype.slice.call(tabla.querySelectorAll('tbody tr')).filter(function (f) {
        return f.querySelector('[data-casilla]');
    });
    var todas = tabla.querySelector('[data-todas]');
    var form = document.querySelector('[data-form-pago]');

    function marcadas() {
        return filas.map(function (f, i) { return f.querySelector('[data-casilla]').checked ? i : -1; })
                    .filter(function (i) { return i >= 0; });
    }
    var totales = document.querySelectorAll('[data-total], [data-total-pie]');
    var pedido = 0, espera = null;

    function mostrarTotal(texto, calculando) {
        totales.forEach(function (n) {
            n.textContent = texto;
            n.classList.toggle('calculando', !!calculando);
        });
    }

    // El valor real lo da el procedimiento oficial: el descuento depende de
    // los años que se pagan juntos, así que no basta sumar las filas.
    function cotizar(desde, hasta) {
        var mio = ++pedido;
        var botones = form.querySelectorAll('button[type="submit"]');
        botones.forEach(function (b) { b.disabled = true; });
        mostrarTotal('Calculando…', true);
        var datos = new FormData();
        datos.append('csrf', form.querySelector('[name="csrf"]').value);
        datos.append('id', form.getAttribute('data-predio'));
        datos.append('desde', desde);
        datos.append('hasta', hasta);
        fetch('cotizar.php', { method: 'POST', body: datos, credentials: 'same-origin' })
            .then(function (r) { return r.json().then(function (j) { return { r: r, j: j }; }); })
            .then(function (x) {
                if (mio !== pedido) { return; }
                if (x.j.sinSesion) { location.href = './'; return; }
                if (!x.r.ok) { throw new Error(x.j.mensaje || 'Error'); }
                mostrarTotal(x.j.total, false);
                var fecha = document.querySelector('[data-fecha]');
                if (fecha) { fecha.textContent = x.j.hasta; }
                x.j.filas.forEach(function (f) {
                    var tr = tabla.querySelector('tr[data-anio="' + f.anio + '"]');
                    if (!tr) { return; }
                    ['impuesto', 'intereses', 'sobretasa', 'descuento', 'otros', 'total'].forEach(function (c) {
                        var td = tr.querySelector('[data-campo="' + c + '"]');
                        if (td) { td.textContent = f[c]; }
                    });
                });
                botones.forEach(function (b) { b.disabled = false; });
            })
            .catch(function (e) {
                if (mio !== pedido) { return; }
                mostrarTotal('No se pudo calcular', false);
                var fecha = document.querySelector('[data-fecha]');
                if (fecha) { fecha.textContent = '—'; }
                console.error(e);
            });
    }

    function pintar(sinCotizar) {
        var idx = marcadas();
        filas.forEach(function (f, i) { f.classList.toggle('seleccionada', idx.indexOf(i) >= 0); });
        if (todas) {
            todas.checked = idx.length === filas.length;
            todas.indeterminate = idx.length > 0 && idx.length < filas.length;
        }
        if (!form) { return; }
        var botones = form.querySelectorAll('button[type="submit"]');
        if (!idx.length) {
            pedido++;
            clearTimeout(espera);
            botones.forEach(function (b) { b.disabled = true; });
            mostrarTotal('$ 0', false);
            var rango0 = document.querySelector('[data-rango]');
            if (rango0) { rango0.textContent = '—'; }
            return;
        }
        var desde = filas[idx[0]].getAttribute('data-anio');
        var hasta = filas[idx[idx.length - 1]].getAttribute('data-anio');
        form.querySelector('[data-desde]').value = desde;
        form.querySelector('[data-hasta]').value = hasta;
        var rango = document.querySelector('[data-rango]');
        if (rango) { rango.textContent = desde === hasta ? desde : desde + ' – ' + hasta; }
        var plural = document.querySelector('[data-plural]');
        if (plural) { plural.hidden = desde === hasta; }
        if (!sinCotizar) {
            clearTimeout(espera);
            mostrarTotal('Calculando…', true);
            espera = setTimeout(function () { cotizar(desde, hasta); }, 350);
        }
    }
    filas.forEach(function (f, i) {
        f.querySelector('[data-casilla]').addEventListener('change', function (ev) {
            var idx = marcadas();
            if (idx.length) {
                var a = Math.min.apply(null, idx), b = Math.max.apply(null, idx);
                if (!ev.target.checked && i > a && i < b) {
                    // Desmarcar un año del medio recorta el rango hasta ese año.
                    b = i - 1;
                }
                filas.forEach(function (g, j) { g.querySelector('[data-casilla]').checked = j >= a && j <= b; });
            }
            pintar();
        });
    });
    if (todas) {
        todas.addEventListener('change', function () {
            filas.forEach(function (f) { f.querySelector('[data-casilla]').checked = todas.checked; });
            pintar();
        });
    }
    pintar(true);
})();
