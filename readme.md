<p align="center">
  <img src="https://fluxeteam.com.br/public/img/logotipo.svg" width="300" align="middle" alt="Logo Fluxe" />
  &nbsp;&nbsp;&nbsp;&nbsp;
  <img src="https://fluxeteam.com.br/public/img/logo_sicapda.png" width="300" align="middle" alt="Logo SICAPDA" />
</p>

---

# SICAPDA

**Sistema Inteligente de Controle de Acesso e Previsão de Demanda Alimentar**

Desenvolvido por [Fluxe](#-sobre-a-fluxe) — Soluções Inteligentes.

---

## Sobre a Fluxe

A **Fluxe** é uma empresa de tecnologia sediada em Mococa - SP, formada por uma equipe que acredita no poder da tecnologia para transformar ideias simples em soluções que realmente ajudam pessoas.

Nosso propósito é usar a tecnologia para resolver problemas reais e gerar impacto positivo, indo além de apenas criar sistemas: buscamos transformar rotinas e contribuir para uma gestão mais eficiente e consciente. O SICAPDA é o principal projeto desenvolvido pela Fluxe até o momento.

## Sobre o SICAPDA

O **SICAPDA** é uma plataforma web voltada para instituições (empresas, escolas, condomínios etc.) que precisam gerenciar simultaneamente **controle de acesso** de pessoas e **planejamento da demanda de refeições** em seus refeitórios.

O sistema une essas duas frentes em um único painel de gestão, com o objetivo de tornar os processos mais **seguros, eficientes e previsíveis**:

- **Controle de Acesso** — gestão de entradas e saídas de colaboradores, visitantes e estudantes, com autenticação segura (senha, cartão RFID e biometria).

- **Previsão de Demanda Alimentar** — algoritmos que preveem a quantidade de refeições necessárias (café, almoço, jantar e ceia) com base em dados históricos, reduzindo desperdício e otimizando compras.

- **Relatórios e Dashboards** — acompanhamento em tempo real de métricas de acesso e consumo para apoiar a tomada de decisão.

- **Gestão Integrada** — cadastro de empresas, usuários, turnos e integrações (ERP, RH, folha de pagamento, Active Directory/Azure AD).

## Estrutura do Projeto

O sistema é dividido em três frentes principais:

```
SystemFluxe/
├── index.html              # Landing page institucional da Fluxe
├── app/
│   ├── views/
│   │   ├── indexSys.html   # Landing page do SICAPDA (com as seções do aplicativo e o download)
│   │   ├── login.php       # Tela de login
│   │   ├── cadastro.php    # Wizard de cadastro de empresa/administrador
│   │   └── painel.php      # Painel interno (protegido)
│   ├── controllers/        # Regras de negócio (Acesso, Usuário, Demanda, Relatório, API do app, download do app)
│   ├── models/             # Entidades do sistema (Usuario, Empresa, Acesso, Refeicao...)
│   ├── middleware/         # Autenticação, controle de papéis e logs
│   └── routes/web.php       # Roteamento das rotas públicas e protegidas
├── config/                  # Configurações de app e banco de dados
├── public/                  # CSS, JS, imagens e o vídeo "Ver como funciona" (public/video/)
│   └── aplicativo/          # Versão web do aplicativo (Flutter), que abre no navegador e instala como app
├── storage/downloads/       # Instaladores do aplicativo (Windows e Android) e manifestos, fora de public/
└── ia/                      # API e modelos em Python para previsão de demanda
```

## Relatórios e Assistente virtual

- **/relatorios** — registros de acesso (1ª entrada e última saída de cada pessoa por dia), com filtros por nome, período e função, indicadores (total, hoje, duração média, pico de entrada), paginação e exportação CSV (`/relatorios/exportar`).
- **/assistente** — assistente virtual em PHP (sem IA externa). Entende perguntas como "previsão para amanhã", "previsão para sexta", "previsão semanal", "desperdício" ou "precisão". A previsão (`app/models/Previsao.php`) usa média ponderada dos últimos dias da semana equivalentes + tendência recente, converte pessoas em refeições (meta do cadastro) e em kg (produção real − desperdício), considera feriados nacionais e mede a própria precisão refazendo os últimos 30 dias.
- Painel, Análise mensal e Pessoas leem do banco (`app/models/Painel.php`, `Acesso.php`, `Producao.php`), sempre filtrando pela empresa do usuário logado.

Configuração local: copie `config/.env.example` para `config/.env` e importe `config/database.sql`.

Dados de exemplo (opcional, para testar e demonstrar): abra `config/dados_exemplo.sql` no Workbench e execute o script inteiro (Ctrl+Shift+Enter). Por padrão ele usa a primeira empresa; para escolher outra, veja o passo 1 do arquivo (`SET @emp = 8;`). Gera 40 funcionários trabalhando em turnos o dia todo e nos 7 dias da semana, com ~90 dias de acessos e de produção de refeições, então o painel, as pessoas presentes, a análise mensal e os relatórios aparecem preenchidos em qualquer dia e horário. **A cada execução ele apaga e recria** funcionários, credenciais, dispositivos, logs de acesso e produção da empresa escolhida (nunca de outra empresa, e nunca usuários, empresas ou metas de refeições). Use só em banco de teste. Na empresa escolhida ele marca "possui refeitório" e, se ela tiver menos de 40 colaboradores, ajusta para 45 (os valores de antes aparecem na primeira consulta do script). No final mostra uma tabela de diagnóstico.

## Aplicativo (Android, Windows e navegador)

O SICAPDA tem um aplicativo feito em Flutter ([fluxe_app](https://github.com/rianzitos/fluxe_app)) que usa o **mesmo login e os mesmos dados** do painel web. Há três jeitos de usar, todos oferecidos na seção *Baixar* da página `/sicapda`:

| Jeito | Para quem | Onde fica |
|---|---|---|
| **Abrir no navegador** (versão web do app, instalável como PWA) | Qualquer aparelho, inclusive iPhone e Mac. Não instala nada | `public/aplicativo/` → `/aplicativo/` |
| **Android** (`SICAPDA.apk`) | Celulares e tablets Android 7.0 ou superior (ARM e x86_64) | `storage/downloads/` → `/app/baixar/android` |
| **Windows** (`SICAPDA-Setup.exe`) | Windows 10 ou superior | `storage/downloads/` → `/app/baixar/windows` |

- **API do app** — `/api/*` (`app/controllers/ApiController.php`): login com token assinado e as mesmas consultas do painel, análise mensal, pessoas, relatórios e assistente, sempre filtradas pela empresa do usuário logado. Exige o `API_SECRET` (mínimo de 32 caracteres) no `config/.env`; para gerar um: `php -r "echo bin2hex(random_bytes(32));"`. Responde **CORS** (`Api::cors()`), então o app web também funciona aberto em outro endereço (por exemplo `flutter run -d chrome`); é seguro liberar qualquer origem porque a API usa token no cabeçalho `Authorization`, nunca cookie.
- **Apresentação do app** — a página `/sicapda` tem as seções *Aplicativo*, *Telas*, *Por que usar o app* e *Baixar* (item **Aplicativo** do menu), com as telas do app, o passo a passo de instalação e perguntas frequentes.
- **Download** — o botão principal entrega o instalador certo para o aparelho de quem acessa (Windows → `SICAPDA-Setup.exe`, Android → `SICAPDA.apk`) e, ao lado, fica sempre o **Abrir no navegador**. Em iPhone, Mac e Linux, que não têm instalador, o navegador é o botão principal. `/app/baixar` faz essa escolha automaticamente; `/app/baixar/windows` e `/app/baixar/android` entregam cada arquivo (com suporte a retomar o download); `/app` é um atalho curto para a seção de download. Enquanto um instalador não for publicado, a página oferece só o que existe e a rota dele responde 404.
- **Versão web (`public/aplicativo/`)** — é o resultado de `flutter build web` (veja "Versão web" no README do `fluxe_app`), servida como arquivos estáticos pelo Apache, na **mesma origem** da API (não precisa configurar CORS nem endereço de servidor: o app usa o próprio site). O `.htaccess` dessa pasta comprime o motor gráfico (7 MB → 2 MB), define `application/wasm` e usa `Cache-Control: no-cache` para uma versão nova aparecer sozinha. No servidor embutido do PHP (`php -S`), `app/routes/web.php` entrega o `index.html` da pasta, como o Apache faz.
- **Publicar ou atualizar** — o GitHub Actions do `fluxe_app` compila tudo e publica a **Release** do GitHub (`SICAPDA.apk`, `SICAPDA-Setup.exe`, `SICAPDA-web.zip` e os `.json`). Copie o APK e o `app.json` (e o instalador do Windows + `app-windows.json`) para `storage/downloads/` e extraia o `SICAPDA-web.zip` em `public/aplicativo/` (mantendo o `.htaccess`). Passo a passo em [`storage/downloads/LEIA-ME.md`](storage/downloads/LEIA-ME.md).

Variáveis do `config/.env`: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` e `API_SECRET`.

## Vídeo "Ver como funciona"

O botão **Ver como funciona**, no início da página `/sicapda`, abre um modal com um vídeo de 30 segundos que percorre o sistema: login, painel, análise mensal, pessoas, assistente virtual, relatórios, configurações (com o tema escuro) e o aplicativo. O vídeo tem trilha e efeitos sonoros (sem narração) e toca com som ao abrir; os textos estão na própria imagem, então também dá para assistir sem som. Ao lado dele há capítulos clicáveis para pular para cada parte.

- **Arquivos** — `public/video/sicapda-como-funciona.mp4` (H.264 + áudio AAC, 1920×1080, ~3,5 MB, com o índice no início do arquivo para começar a tocar antes de baixar tudo; a trilha e os efeitos foram sintetizados, sem músicas de terceiros, então não há licença a pagar) e `public/video/sicapda-como-funciona.jpg` (pôster: aparece até o vídeo carregar e para quem usa "reduzir animações", que não toca sozinho). O arquivo só é baixado quando a pessoa abre o modal (ou passa o mouse no botão).
- **Código** — o modal (`<dialog id="modalVideo">`) fica no fim de `app/views/indexSys.html`; estilos em `public/css/videoSys.css`; comportamento em `public/js/videoSys.js` (abrir e fechar, ESC, clique no fundo, foco, capítulos, aviso se o vídeo não carregar). É um `<dialog>` nativo, então o navegador cuida de prender o foco e de bloquear a página de fundo.
- **Trocar o vídeo** — substitua os dois arquivos mantendo os nomes (a página usa a data de modificação deles para renovar o cache). Se o novo vídeo tiver outra duração ou outras partes, ajuste os botões de capítulo (`data-tempo`, em segundos) e o texto "30 segundos" no `<dialog>`. Para exportar: `ffmpeg -i entrada.mov -c:v libx264 -crf 24 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart sicapda-como-funciona.mp4` (para um vídeo sem som, troque `-c:a aac -b:a 128k` por `-an`; a página funciona nos dois casos).
- **Servidor** — Apache e Nginx entregam o MP4 com suporte a `Range`, que é o que permite pular na barra de progresso (e é exigido pelo Safari). O servidor embutido do PHP (`php -S`) não tem esse suporte, então `app/helpers/Midia.php` cobre isso só no desenvolvimento (bloco `cli-server` de `app/routes/web.php`); em produção ele nunca é chamado.

## Tecnologias utilizadas

- **Back-end:** PHP (arquitetura MVC própria, sem framework), com controllers, models e middlewares de autenticação/autorização.
- **Front-end:** HTML, CSS e JavaScript.
- **Inteligência Artificial:** Python (módulo `ia/` para treino, avaliação e previsão de demanda alimentar).
- **Banco de dados:** MySQL/MariaDB via PDO.

## Equipe

| Nome            | Função                                      |
|-----------------|---------------------------------------------|
| Davi Gonçalves  | Project Owner (PO) / Full-Stack Developer  |
| Rian Rafael     | Scrum Master / Back-End Developer          |
| Rhyan Gabriel   | Front-End                                   |
| Beatriz Moreira | Documentação                                |
| Julia Ferreira  | Banco de Dados                              |

---

© 2026 Fluxe Soluções Inteligentes — Mococa, SP, Brasil
