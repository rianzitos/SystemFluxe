    <!-- Aplica o tema salvo (claro/escuro/sistema) antes do CSS carregar, pra evitar
         o "flash" da tela clara antes de escurecer. -->
    <script>
        (function () {
            try {
                var tema = localStorage.getItem('sicapda_tema') || 'claro';
                if (tema === 'sistema') {
                    tema = window.matchMedia('(prefers-color-scheme: dark)').matches ? 'escuro' : 'claro';
                }
                if (tema === 'escuro') {
                    document.documentElement.setAttribute('data-tema', 'escuro');
                }
            } catch (e) { /* localStorage indisponível: segue no tema claro */ }
        })();
    </script>
