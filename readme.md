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
├── public/                  # CSS, JS e imagens
├── storage/downloads/       # Instaladores do aplicativo (Windows e Android) e manifestos, fora de public/
└── ia/                      # API e modelos em Python para previsão de demanda
```

## Relatórios e Assistente virtual

- **/relatorios** — registros de acesso (1ª entrada e última saída de cada pessoa por dia), com filtros por nome, período e função, indicadores (total, hoje, duração média, pico de entrada), paginação e exportação CSV (`/relatorios/exportar`).
- **/assistente** — assistente virtual em PHP (sem IA externa). Entende perguntas como "previsão para amanhã", "previsão para sexta", "previsão semanal", "desperdício" ou "precisão". A previsão (`app/models/Previsao.php`) usa média ponderada dos últimos dias da semana equivalentes + tendência recente, converte pessoas em refeições (meta do cadastro) e em kg (produção real − desperdício), considera feriados nacionais e mede a própria precisão refazendo os últimos 30 dias.
- Painel, Análise mensal e Pessoas leem do banco (`app/models/Painel.php`, `Acesso.php`, `Producao.php`), sempre filtrando pela empresa do usuário logado.

Configuração local: copie `config/.env.example` para `config/.env` e importe `config/database.sql`.

## Aplicativo (Windows e Android)

O SICAPDA tem um aplicativo para **Windows** e **Android**, feito em Flutter ([fluxe_app](https://github.com/rianzitos/fluxe_app)), que usa o **mesmo login e os mesmos dados** da versão web.

- **API do app** — `/api/*` (`app/controllers/ApiController.php`): login com token assinado e as mesmas consultas do painel, análise mensal, pessoas, relatórios e assistente, sempre filtradas pela empresa do usuário logado. Exige o `API_SECRET` (mínimo de 32 caracteres) no `config/.env`; para gerar um: `php -r "echo bin2hex(random_bytes(32));"`.
- **Apresentação do app** — a página `/sicapda` tem as seções *Aplicativo*, *Telas*, *Por que usar o app* e *Baixar* (item **Aplicativo** do menu), com as telas do app, o passo a passo de instalação e perguntas frequentes.
- **Download** — a seção *Baixar* tem um botão só: ele entrega o instalador certo para o aparelho de quem acessa (Windows → `SICAPDA-Setup.exe`, Android → `SICAPDA.apk`). `/app/baixar` faz essa escolha automaticamente; `/app/baixar/windows` e `/app/baixar/android` entregam cada arquivo (com suporte a retomar o download); `/app` é um atalho curto para a seção de download. Enquanto um instalador não for publicado, a página mostra "Em breve" (ou oferece só o que existe) e a rota dele responde 404.
- **Publicar ou atualizar** — copie para `storage/downloads/` os arquivos `SICAPDA-Setup.exe` + `app-windows.json` (Windows) e `SICAPDA.apk` + `app.json` (Android: versão, tamanho e data). O GitHub Actions do `fluxe_app` gera tudo no artefato `SICAPDA-downloads`. Passo a passo em [`storage/downloads/LEIA-ME.md`](storage/downloads/LEIA-ME.md).

Variáveis do `config/.env`: `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS` e `API_SECRET`.

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
