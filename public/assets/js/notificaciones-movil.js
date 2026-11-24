(function () {
    'use strict';

    const configuracion =
        window.AlmacenNotificacionesConfig || null;

    if (!configuracion || !configuracion.usuarioId) {
        return;
    }

    const boton = document.getElementById(
        'botonAlertasMoviles'
    );

    if (!boton) {
        return;
    }

    const tipos = [
        {
            clave: 'resurtidos',
            nombre: 'resurtido',
            nombrePlural: 'resurtidos',
            contadorId: 'contadorResurtidos',
            destino: 'resurtidos.php',
            habilitado: Boolean(
                configuracion.recibeResurtidos
            )
        },
        {
            clave: 'tickets',
            nombre: 'ticket',
            nombrePlural: 'tickets',
            contadorId: 'contadorTickets',
            destino: 'tickets.php',
            habilitado: Boolean(
                configuracion.recibeTickets
            )
        }
    ].filter((tipo) => tipo.habilitado);

    if (tipos.length === 0) {
        boton.hidden = true;
        return;
    }

    const usuarioId = String(configuracion.usuarioId);
    const claveActivacion =
        `almacen_alertas_moviles_${usuarioId}`;
    const prefijoAvisado =
        `almacen_alerta_avisada_${usuarioId}_`;
    const prefijoPendientes =
        `almacen_pendientes_${usuarioId}_`;
    const claveUltimoRecordatorio =
        `almacen_ultimo_recordatorio_${usuarioId}`;
    const intervalo = Math.max(
        3000,
        Number(configuracion.intervalo || 5000)
    );
    const intervaloRecordatorio = Math.max(
        60000,
        Number(configuracion.recordatorio || 180000)
    );
    const tituloOriginal = document.title;
    const estados = {};

    let alertasActivas =
        localStorage.getItem(claveActivacion) === '1';
    let contextoAudio = null;
    let consultaEnCurso = false;
    let temporizador = null;
    let toastActual = null;
    let ultimoRecordatorio = Number(
        localStorage.getItem(claveUltimoRecordatorio) || 0
    );

    if (!Number.isFinite(ultimoRecordatorio)) {
        ultimoRecordatorio = 0;
    }

    tipos.forEach((tipo) => {
        let idsGuardados = null;

        try {
            const contenido = localStorage.getItem(
                `${prefijoPendientes}${tipo.clave}`
            );
            const datos = contenido
                ? JSON.parse(contenido)
                : null;

            if (Array.isArray(datos)) {
                idsGuardados = datos
                    .map((id) => String(id))
                    .filter(Boolean);
            }
        } catch (error) {
            idsGuardados = null;
        }

        estados[tipo.clave] = {
            inicializado: idsGuardados !== null,
            ids: new Set(idsGuardados || []),
            cantidad: idsGuardados?.length || 0
        };
    });

    function guardarPreferencia() {
        localStorage.setItem(
            claveActivacion,
            alertasActivas ? '1' : '0'
        );
    }

    function guardarUltimoRecordatorio(valor) {
        ultimoRecordatorio = Math.max(0, Number(valor) || 0);

        try {
            if (ultimoRecordatorio > 0) {
                localStorage.setItem(
                    claveUltimoRecordatorio,
                    String(ultimoRecordatorio)
                );
            } else {
                localStorage.removeItem(
                    claveUltimoRecordatorio
                );
            }
        } catch (error) {
            /* El recordatorio continúa durante la página actual. */
        }
    }

    function actualizarBoton() {
        boton.classList.toggle(
            'alertas-activas',
            alertasActivas
        );
        boton.setAttribute(
            'aria-pressed',
            alertasActivas ? 'true' : 'false'
        );
        boton.title = alertasActivas
            ? 'Sonido y vibración activados. Pulse para desactivar.'
            : 'Pulse para activar sonido y vibración.';

        const texto = boton.querySelector(
            '.texto-alertas'
        );

        if (texto) {
            texto.textContent = alertasActivas
                ? 'Timbre activo'
                : 'Activar timbre';
        }
    }

    function obtenerContextoAudio() {
        if (contextoAudio) {
            return contextoAudio;
        }

        const ConstructorAudio =
            window.AudioContext
            || window.webkitAudioContext;

        if (!ConstructorAudio) {
            return null;
        }

        contextoAudio = new ConstructorAudio();
        return contextoAudio;
    }

    async function desbloquearAudio() {
        const contexto = obtenerContextoAudio();

        if (!contexto) {
            return false;
        }

        if (contexto.state === 'suspended') {
            await contexto.resume();
        }

        return contexto.state === 'running';
    }

    function reproducirTono(
        contexto,
        frecuencia,
        inicio,
        duracion,
        tipoOnda = 'square',
        nivel = 0.42
    ) {
        const oscilador = contexto.createOscillator();
        const volumen = contexto.createGain();

        oscilador.type = tipoOnda;
        oscilador.frequency.setValueAtTime(
            frecuencia,
            inicio
        );

        volumen.gain.setValueAtTime(0.0001, inicio);
        volumen.gain.exponentialRampToValueAtTime(
            nivel,
            inicio + 0.015
        );
        volumen.gain.setValueAtTime(
            nivel,
            Math.max(
                inicio + 0.02,
                inicio + duracion - 0.045
            )
        );
        volumen.gain.exponentialRampToValueAtTime(
            0.0001,
            inicio + duracion
        );

        oscilador.connect(volumen);
        volumen.connect(contexto.destination);
        oscilador.start(inicio);
        oscilador.stop(inicio + duracion + 0.03);
    }

    async function reproducirSonido(prueba = false) {
        if (!alertasActivas) {
            return;
        }

        try {
            const disponible = await desbloquearAudio();

            if (!disponible || !contextoAudio) {
                return;
            }

            const ahora = contextoAudio.currentTime + 0.02;

            const ciclos = prueba ? 1 : 3;

            /*
             * Timbre fuerte para computadora: tres ráfagas de dos tonos.
             * El volumen final también depende de Windows, Android y de las
             * bocinas del dispositivo.
             */
            for (let ciclo = 0; ciclo < ciclos; ciclo++) {
                const base = ahora + (ciclo * 1.05);

                reproducirTono(
                    contextoAudio,
                    880,
                    base,
                    0.28,
                    'square',
                    0.46
                );
                reproducirTono(
                    contextoAudio,
                    1174,
                    base + 0.32,
                    0.28,
                    'square',
                    0.46
                );
                reproducirTono(
                    contextoAudio,
                    880,
                    base + 0.64,
                    0.3,
                    'sawtooth',
                    0.4
                );
            }
        } catch (error) {
            console.warn(
                'El navegador no permitió reproducir el sonido.'
            );
        }
    }

    function vibrar(prueba = false) {
        if (
            !alertasActivas
            || typeof navigator.vibrate !== 'function'
        ) {
            return;
        }

        navigator.vibrate(
            prueba
                ? [180, 80, 180]
                : [350, 150, 350, 150, 600]
        );
    }

    function quitarToast() {
        if (toastActual) {
            toastActual.remove();
            toastActual = null;
        }
    }

    function mostrarToast(
        titulo,
        mensaje,
        destino,
        clase = 'nueva'
    ) {
        quitarToast();

        const toast = document.createElement('button');
        toast.type = 'button';
        toast.className =
            `alerta-movil-toast alerta-${clase}`;
        toast.innerHTML = `
            <span class="alerta-movil-icono">🔔</span>
            <span class="alerta-movil-contenido">
                <strong></strong>
                <small></small>
            </span>
            <span class="alerta-movil-cerrar"
                aria-hidden="true">×</span>
        `;

        toast.querySelector('strong').textContent = titulo;
        toast.querySelector('small').textContent = mensaje;
        toast.addEventListener('click', () => {
            if (destino) {
                window.location.href = destino;
                return;
            }

            quitarToast();
        });

        document.body.appendChild(toast);
        toastActual = toast;

        window.setTimeout(() => {
            if (toastActual === toast) {
                quitarToast();
            }
        }, 12000);
    }

    function puedeMostrarNotificacionSistema() {
        return (
            window.isSecureContext
            && 'Notification' in window
            && 'serviceWorker' in navigator
            && Notification.permission === 'granted'
        );
    }

    async function obtenerRegistroNotificaciones() {
        if (
            !window.isSecureContext
            || !('serviceWorker' in navigator)
        ) {
            return null;
        }

        const existente =
            await navigator.serviceWorker.getRegistration();

        if (existente) {
            return existente;
        }

        return navigator.serviceWorker.register(
            configuracion.serviceWorker
            || 'sw-notificaciones.js'
        );
    }

    async function mostrarNotificacionSistema(
        titulo,
        mensaje,
        destino,
        etiqueta
    ) {
        if (!puedeMostrarNotificacionSistema()) {
            return;
        }

        try {
            const registro =
                await obtenerRegistroNotificaciones();

            if (!registro) {
                return;
            }

            await registro.showNotification(
                titulo,
                {
                    body: mensaje,
                    tag: etiqueta,
                    renotify: true,
                    requireInteraction: true,
                    icon: 'assets/img/logo2.png',
                    badge: 'assets/img/logo2.png',
                    data: {
                        destino
                    }
                }
            );
        } catch (error) {
            console.warn(
                'No fue posible mostrar la notificación del sistema.'
            );
        }
    }

    function solicitudYaAvisada(tipo, id) {
        if (!id) {
            return false;
        }

        const clave =
            `${prefijoAvisado}${tipo.clave}_${id}`;
        const anterior = Number(
            localStorage.getItem(clave) || 0
        );
        const vigencia = 7 * 24 * 60 * 60 * 1000;

        if (
            anterior > 0
            && Date.now() - anterior < vigencia
        ) {
            return true;
        }

        localStorage.setItem(clave, String(Date.now()));
        return false;
    }

    function describirSolicitud(tipo, solicitud) {
        const folio =
            String(solicitud?.folio || '').trim();
        const verificador =
            String(
                solicitud?.verificador_nombre
                || solicitud?.solicitante_nombre
                || ''
            ).trim();
        const almacen =
            String(solicitud?.almacen_nombre || '').trim();
        const partes = [];

        if (folio) {
            partes.push(`Folio ${folio}`);
        }

        if (verificador) {
            partes.push(`Solicita: ${verificador}`);
        }

        if (almacen) {
            partes.push(almacen);
        }

        return partes.length > 0
            ? partes.join(' · ')
            : `Abra el módulo de ${tipo.nombrePlural}.`;
    }

    async function avisarNuevas(
        tipo,
        solicitudes
    ) {
        const nuevas = solicitudes.filter(
            (solicitud) => !solicitudYaAvisada(
                tipo,
                solicitud.id
            )
        );

        if (nuevas.length === 0) {
            return false;
        }

        const plural = nuevas.length === 1
            ? tipo.nombre
            : tipo.nombrePlural;
        const titulo = nuevas.length === 1
            ? `Nuevo ${tipo.nombre}`
            : `${nuevas.length} nuevos ${plural}`;
        const mensaje = nuevas.length === 1
            ? describirSolicitud(tipo, nuevas[0])
            : `Hay ${nuevas.length} solicitudes nuevas por atender.`;

        if (alertasActivas) {
            await reproducirSonido();
            vibrar();
        }

        mostrarToast(
            titulo,
            mensaje,
            tipo.destino
        );
        mostrarNotificacionSistema(
            titulo,
            mensaje,
            tipo.destino,
            `almacen-${tipo.clave}-${nuevas
                .map((solicitud) => solicitud.id)
                .join('-')}`
        );

        return true;
    }

    function actualizarContador(tipo, cantidad, aumento) {
        const contador = document.getElementById(
            tipo.contadorId
        );

        if (!contador) {
            return;
        }

        if (cantidad <= 0) {
            contador.textContent = '0';
            contador.hidden = true;
            return;
        }

        contador.textContent =
            cantidad > 99 ? '99+' : String(cantidad);
        contador.hidden = false;

        if (aumento) {
            contador.classList.remove('nuevas');
            void contador.offsetWidth;
            contador.classList.add('nuevas');
        }
    }

    function guardarPendientes(tipo, ids) {
        try {
            localStorage.setItem(
                `${prefijoPendientes}${tipo.clave}`,
                JSON.stringify(Array.from(ids))
            );
        } catch (error) {
            /*
             * Los contadores y avisos siguen funcionando aunque el
             * navegador tenga desactivado el almacenamiento local.
             */
        }
    }

    function actualizarTituloYBadge() {
        const total = tipos.reduce(
            (acumulado, tipo) =>
                acumulado
                + (estados[tipo.clave]?.cantidad || 0),
            0
        );

        document.title = total > 0
            ? `(${total}) ${tituloOriginal}`
            : tituloOriginal;

        if (
            total > 0
            && typeof navigator.setAppBadge === 'function'
        ) {
            navigator.setAppBadge(total).catch(() => {});
        } else if (
            total === 0
            && typeof navigator.clearAppBadge === 'function'
        ) {
            navigator.clearAppBadge().catch(() => {});
        }
    }

    function obtenerTotalPendientes() {
        return tipos.reduce(
            (acumulado, tipo) =>
                acumulado
                + (estados[tipo.clave]?.cantidad || 0),
            0
        );
    }

    function describirPendientes() {
        const partes = tipos
            .map((tipo) => ({
                ...tipo,
                cantidad:
                    estados[tipo.clave]?.cantidad || 0
            }))
            .filter((tipo) => tipo.cantidad > 0)
            .map((tipo) => {
                const nombre = tipo.cantidad === 1
                    ? tipo.nombre
                    : tipo.nombrePlural;

                return `${tipo.cantidad} ${nombre}`;
            });

        return partes.length > 0
            ? `Siguen pendientes: ${partes.join(' y ')}.`
            : 'No hay solicitudes pendientes.';
    }

    async function revisarRecordatorio(huboAvisoNuevo) {
        const total = obtenerTotalPendientes();

        if (total <= 0) {
            guardarUltimoRecordatorio(0);
            return;
        }

        const ahora = Date.now();

        if (huboAvisoNuevo || ultimoRecordatorio <= 0) {
            guardarUltimoRecordatorio(ahora);
            return;
        }

        if (
            ahora - ultimoRecordatorio
            < intervaloRecordatorio
        ) {
            return;
        }

        if (!alertasActivas) {
            return;
        }

        /*
         * Se registra antes de reproducir para impedir dos timbres
         * simultáneos si coinciden el sondeo y un cambio de página.
         */
        guardarUltimoRecordatorio(ahora);

        await reproducirSonido();
        vibrar();

        const tipoDestino = tipos.find(
            (tipo) =>
                (estados[tipo.clave]?.cantidad || 0) > 0
        );
        const mensaje = describirPendientes();
        const destino = tipoDestino?.destino || '';

        mostrarToast(
            'Recordatorio de solicitudes',
            `${mensaje} Deben surtirse.`,
            destino,
            'recordatorio'
        );
        mostrarNotificacionSistema(
            'Solicitudes pendientes por surtir',
            mensaje,
            destino,
            'almacen-recordatorio-pendientes'
        );
    }

    async function procesarTipo(tipo, datos) {
        const estado = estados[tipo.clave];
        const solicitudes = Array.isArray(
            datos?.solicitudes
        )
            ? datos.solicitudes
            : [];
        const cantidad = Number.isFinite(
            Number(datos?.cantidad)
        )
            ? Math.max(0, Number(datos.cantidad))
            : solicitudes.length;
        const idsActuales = new Set(
            solicitudes
                .map((solicitud) =>
                    String(solicitud.id || '')
                )
                .filter(Boolean)
        );

        if (!estado.inicializado) {
            estado.inicializado = true;
            estado.ids = idsActuales;
            estado.cantidad = cantidad;
            guardarPendientes(tipo, idsActuales);
            actualizarContador(tipo, cantidad, false);
            return false;
        }

        const nuevas = solicitudes.filter(
            (solicitud) =>
                solicitud.id
                && !estado.ids.has(String(solicitud.id))
        );
        const aumento =
            nuevas.length > 0
            || cantidad > estado.cantidad;

        estado.ids = idsActuales;
        estado.cantidad = cantidad;

        guardarPendientes(tipo, idsActuales);
        actualizarContador(tipo, cantidad, aumento);

        if (nuevas.length > 0) {
            return await avisarNuevas(tipo, nuevas);
        }

        return false;
    }

    async function consultarPendientes() {
        if (consultaEnCurso || !navigator.onLine) {
            return;
        }

        consultaEnCurso = true;

        try {
            const respuesta = await fetch(
                configuracion.endpoint
                || 'api_notificaciones_pendientes.php',
                {
                    method: 'GET',
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }
            );

            if (!respuesta.ok) {
                return;
            }

            const resultado = await respuesta.json();

            if (!resultado.ok) {
                return;
            }

            let huboAvisoNuevo = false;

            for (const tipo of tipos) {
                const avisoRealizado = await procesarTipo(
                    tipo,
                    resultado.datos?.[tipo.clave] || {}
                );

                huboAvisoNuevo =
                    huboAvisoNuevo || avisoRealizado;
            }

            actualizarTituloYBadge();
            await revisarRecordatorio(huboAvisoNuevo);
        } catch (error) {
            console.warn(
                'No fue posible actualizar las notificaciones.'
            );
        } finally {
            consultaEnCurso = false;
        }
    }

    async function solicitarPermisoSistema() {
        if (
            !window.isSecureContext
            || !('Notification' in window)
            || Notification.permission !== 'default'
        ) {
            return;
        }

        try {
            await obtenerRegistroNotificaciones();
            await Notification.requestPermission();
        } catch (error) {
            console.warn(
                'El navegador no permitió solicitar notificaciones.'
            );
        }
    }

    boton.addEventListener('click', async () => {
        alertasActivas = !alertasActivas;
        guardarPreferencia();
        actualizarBoton();

        if (!alertasActivas) {
            if (typeof navigator.vibrate === 'function') {
                navigator.vibrate(0);
            }

            mostrarToast(
                'Alertas desactivadas',
                'Los contadores seguirán actualizándose sin sonido ni vibración.',
                '',
                'estado'
            );
            return;
        }

        await desbloquearAudio();
        await solicitarPermisoSistema();
        await reproducirSonido(true);
        vibrar(true);

        if (obtenerTotalPendientes() > 0) {
            guardarUltimoRecordatorio(Date.now());
        }

        const mensaje = window.isSecureContext
            ? 'La computadora sonará fuerte; en celular también vibrará al recibir solicitudes nuevas.'
            : 'Timbre fuerte activo mientras el sistema esté abierto. En celular también vibrará. Para avisos con el navegador cerrado se requiere HTTPS y Web Push.';

        mostrarToast(
            'Timbre activado',
            mensaje,
            '',
            'estado'
        );
    });

    function reactivarAudioConInteraccion() {
        if (alertasActivas) {
            desbloquearAudio().catch(() => {});
        }
    }

    document.addEventListener(
        'pointerdown',
        reactivarAudioConInteraccion,
        {passive: true}
    );
    document.addEventListener(
        'keydown',
        reactivarAudioConInteraccion
    );

    document.addEventListener(
        'visibilitychange',
        () => {
            if (!document.hidden) {
                reactivarAudioConInteraccion();
                consultarPendientes();
            }
        }
    );

    window.addEventListener(
        'online',
        consultarPendientes
    );
    window.addEventListener(
        'pageshow',
        consultarPendientes
    );

    actualizarBoton();

    if (alertasActivas && puedeMostrarNotificacionSistema()) {
        obtenerRegistroNotificaciones().catch(() => {});
    }

    consultarPendientes();

    temporizador = window.setInterval(
        consultarPendientes,
        intervalo
    );

    window.addEventListener('beforeunload', () => {
        if (temporizador) {
            window.clearInterval(temporizador);
        }
    });
})();
