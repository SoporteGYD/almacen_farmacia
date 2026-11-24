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
</body>
</html>
