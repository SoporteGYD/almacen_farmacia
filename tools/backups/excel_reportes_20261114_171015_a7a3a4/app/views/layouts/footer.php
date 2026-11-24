    </main>
    <script>
    (function mantenerSesionActiva() {
        const intervalo = 4 * 60 * 1000;

        function renovarSesion() {
            fetch('session_keepalive.php', {
                method: 'GET',
                credentials: 'same-origin',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).catch(function() {
                // Un fallo temporal de red no interrumpe el trabajo.
            });
        }

        window.setInterval(renovarSesion, intervalo);

        document.addEventListener(
            'visibilitychange',
            function() {
                if (!document.hidden) {
                    renovarSesion();
                }
            }
        );
    }());
    </script>
<script defer src="assets/js/erp_notificaciones.js?v=1"></script>
<script src="assets/js/gyd_feedback_modal.js?v=5" defer></script>
</body>
</html>
