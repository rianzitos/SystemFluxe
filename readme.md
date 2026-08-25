# SICAPDA

**Sistema Inteligente de Controle de Acesso e Previsão de Demanda Alimentar**

Desenvolvido por [Fluxe](#-sobre-a-fluxe) — Soluções Inteligentes.

---

## Sobre a Fluxe

A **Fluxe** é uma empresa de tecnologia sediada em Mococa - SP, formada por uma equipe que acredita no poder da tecnologia para transformar ideias simples em soluções que realmente ajudam pessoas.

Nosso propósito é usar a tecnologia para resolver problemas reais e gerar impacto positivo, indo além de apenas criar sistemas: buscamos transformar rotinas e contribuir para uma gestão mais eficiente e consciente. O SICAPDA é o principal projeto desenvolvido pela Fluxe até o momento.

## Sobre o SICAPDA

O **SICAPDA** é uma plataforma web voltada para instituições (empresas, escolas, condomínios etc.) que precisam gerenciar simultaneamente o **controle de acesso** de pessoas e o **planejamento da demanda de refeições** em seus refeitórios.

O sistema une essas duas frentes em um único painel de gestão, com o objetivo de tornar os processos mais **seguros, eficientes e previsíveis**:

- **Controle de Acesso** — gestão de entradas e saídas de colaboradores, visitantes e estudantes, com autenticação segura (senha, cartão RFID e biometria).
- **Previsão de Demanda Alimentar** — algoritmos que preveem a quantidade de refeições necessárias (café, almoço, jantar e ceia) com base em dados históricos, reduzindo desperdício e otimizando compras.
- **Relatórios e Dashboards** — acompanhamento em tempo real de métricas de acesso e consumo para apoiar a tomada de decisão.
- **Gestão Integrada** — cadastro de empresas, usuários, turnos e integrações (ERP, RH, folha de pagamento, Active Directory/Azure AD).

## Estrutura do Projeto

O sistema é dividido em três frentes principais:

```text
SystemFluxe/
├── index.html              # Landing page institucional da Fluxe
├── app/
│   ├── views/
│   │   ├── indexSys.html   # Landing page do SICAPDA
│   │   ├── login.php       # Tela de login
│   │   ├── cadastro.php    # Wizard de cadastro de empresa/administrador
│   │   └── painel.php      # Painel interno (protegido)
│   ├── controllers/        # Regras de negócio
│   ├── models/             # Entidades do sistema
│   ├── middleware/         # Autenticação, papéis e logs
│   └── routes/web.php      # Rotas públicas e protegidas
├── config/                 # Configurações da aplicação e banco
├── public/                 # CSS, JS e imagens
└── ia/                     # API e modelos em Python
