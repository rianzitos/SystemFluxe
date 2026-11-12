<?php
// Atalhos do canto direito do cabeçalho, iguais em todas as telas internas:
//   - botão amarelo "Integrações" e botão "IoT": levam direto para Configurações, sem passar pelo menu;
//   - sino com o painel de alertas (o conteúdo vem de GET /notificacoes, ver public/js/notificacoes.js).
// Espera $notificacoesNaoLidas (número do selo do sino) definido pela tela que inclui este arquivo.
$notificacoesNaoLidas = (int) ($notificacoesNaoLidas ?? 0);
?>
                    <a class="btn-atalho btn-atalho-integracoes" href="/configuracoes#integracoes"
                        title="Abrir Configurações › Integrações">
                        <i class="bi bi-plug-fill" aria-hidden="true"></i>
                        <span>Integrações</span>
                    </a>
                    <a class="btn-atalho btn-atalho-iot" href="/configuracoes#iot"
                        title="Abrir os dispositivos IoT (catracas e balanças)">
                        <i class="bi bi-cpu-fill" aria-hidden="true"></i>
                        <span>IoT</span>
                    </a>

                    <div class="sino-wrap">
                        <button type="button" class="sino" id="btnNotificacoes" aria-label="Notificações"
                            aria-haspopup="true" aria-expanded="false" aria-controls="painelNotificacoes">
                            <?= Pagina::svg('bell') ?>
                            <?php if ($notificacoesNaoLidas > 0): ?>
                                <span class="badge-sino"><?= $notificacoesNaoLidas ?></span>
                            <?php endif; ?>
                        </button>

                        <div class="painel-notificacoes" id="painelNotificacoes" role="dialog"
                            aria-label="Notificações" hidden>
                            <div class="painel-notificacoes-topo">
                                <strong>Notificações</strong>
                                <a href="/configuracoes#notificacoes" class="painel-notificacoes-config"
                                    title="Escolher quais alertas aparecem aqui">
                                    <i class="bi bi-sliders" aria-hidden="true"></i> Preferências
                                </a>
                            </div>
                            <div class="painel-notificacoes-lista" id="listaNotificacoes" aria-live="polite">
                                <p class="painel-notificacoes-vazio">Carregando alertas…</p>
                            </div>
                            <a href="/assistente" class="painel-notificacoes-rodape">
                                Ver recomendações do assistente <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        </div>
                    </div>
                    <script src="/js/aoVivo.js?v=<?= (int) @filemtime(__DIR__ . '/../../../public/js/aoVivo.js') ?>" defer></script>
                    <script src="/js/notificacoes.js?v=<?= (int) @filemtime(__DIR__ . '/../../../public/js/notificacoes.js') ?>" defer></script>
