// Gera banner.html (910 x 1170 px = proporção 70 x 90 cm)
const fs = require('fs');
const path = require('path');
const QRCode = require('qrcode');

const icon = (name) =>
  fs.readFileSync(path.join(__dirname, 'node_modules/lucide-static/icons', name + '.svg'), 'utf8')
    .replace(/<!--.*?-->/s, '')
    .replace(/\s(width|height)="24"/g, '')
    .replace('stroke-width="2"', 'stroke-width="1.9"')
    .replace(/\n\s*/g, ' ')
    .trim();

const recursos = [
  ['nfc', 'Catraca com cartão RFID', 'Entradas e saídas na hora'],
  ['trending-up', 'Previsão de demanda', 'Quanto preparar, pelo histórico'],
  ['scale', 'Balança de desperdício', 'Sobras pesadas automaticamente'],
  ['sparkles', 'Assistente com IA', 'Dúvidas respondidas na conversa'],
];

(async () => {
  const url = 'https://fluxeteam.com.br/sicapda';
  const qr = await QRCode.toString(url, {
    type: 'svg', margin: 0, errorCorrectionLevel: 'M',
    color: { dark: '#121212', light: '#00000000' },
  });

  const itens = recursos.map(([ic, t, d]) => `
      <li>
        <span class="tile">${icon(ic)}</span>
        <span class="txt"><strong>${t}</strong><small>${d}</small></span>
      </li>`).join('');

  const html = `<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<title>Banner SICAPDA</title>
<style>
  @font-face { font-family: 'SG'; src: url(space-grotesk-latin-400-normal.woff2) format('woff2'); font-weight: 400; }
  @font-face { font-family: 'SG'; src: url(space-grotesk-latin-500-normal.woff2) format('woff2'); font-weight: 500; }
  @font-face { font-family: 'SG'; src: url(space-grotesk-latin-600-normal.woff2) format('woff2'); font-weight: 600; }
  @font-face { font-family: 'SG'; src: url(space-grotesk-latin-700-normal.woff2) format('woff2'); font-weight: 700; }

  :root {
    --amarelo: #FFC400;
    --fundo: #121212;
    --texto: #F5F5F5;
    --suave: #A3A3A3;
    --linha: #262626;
  }
  * { margin: 0; padding: 0; box-sizing: border-box; }
  html, body { width: 910px; height: 1170px; background: var(--fundo); }
  body {
    font-family: 'SG', sans-serif; color: var(--texto);
    -webkit-font-smoothing: antialiased;
  }
  .banner {
    position: relative; width: 910px; height: 1170px; overflow: hidden;
    background: var(--fundo);
    padding: 68px 64px 0;
  }

  /* traço de circuito (motivo do site da Fluxe) */
  .circuito { position: absolute; top: 120px; right: 0; width: 360px; height: 180px; }

  .topo { display: flex; align-items: center; justify-content: space-between; }
  .topo .fluxe { width: 176px; display: block; }
  .selo {
    display: flex; align-items: center; gap: 14px;
    font-size: 13px; font-weight: 600; letter-spacing: .28em; text-transform: uppercase;
    color: var(--suave);
  }
  .selo::before { content: ''; width: 34px; height: 3px; border-radius: 2px; background: var(--amarelo); }

  .logo { display: block; width: 430px; margin: 48px 0 0 -12px; }

  h1 {
    margin-top: 34px;
    font-size: 52px; line-height: 1.06; font-weight: 700; letter-spacing: -.02em;
  }
  h1 em { font-style: normal; color: var(--amarelo); }
  .sub {
    margin-top: 18px; max-width: 600px;
    font-size: 19px; line-height: 1.5; color: var(--suave);
  }
  .sub b { color: var(--texto); font-weight: 500; }

  /* recursos + notebook e celular */
  .meio { position: relative; margin-top: 40px; height: 410px; display: flex; align-items: center; }
  ul { list-style: none; position: relative; width: 470px; display: grid; gap: 42px; }
  li { position: relative; display: flex; align-items: center; gap: 24px; }
  .tile {
    flex: none; width: 60px; height: 60px; border-radius: 16px;
    background: var(--amarelo); color: var(--fundo);
    display: grid; place-items: center;
  }
  .tile svg { width: 31px; height: 31px; }
  .txt { display: grid; gap: 4px; }
  .txt strong { font-size: 24px; font-weight: 600; letter-spacing: -.01em; }
  .txt small { font-size: 17px; color: var(--suave); }

  .disco {
    position: absolute; width: 440px; height: 440px; border-radius: 50%;
    background: var(--amarelo); right: -164px; top: 16px;
  }
  /* notebook com o painel web */
  .notebook {
    position: absolute; left: 398px; top: 26px; width: 406px;
    filter: drop-shadow(0 30px 40px rgba(0,0,0,.55));
  }
  .tampa {
    width: 370px; margin: 0 auto; padding: 9px 9px 12px;
    border-radius: 16px 16px 4px 4px;
    background: #070707; border: 1.5px solid #343434; border-bottom: none;
  }
  .tampa img { display: block; width: 100%; border-radius: 3px; }
  .base {
    position: relative; height: 13px; border-radius: 2px 2px 14px 14px;
    background: linear-gradient(#5a5a5a, #2b2b2b 45%, #1c1c1c);
  }
  .base::before { /* encaixe para abrir a tampa */
    content: ''; position: absolute; left: 50%; top: 0; width: 64px; height: 5px;
    transform: translateX(-50%); border-radius: 0 0 6px 6px; background: #1a1a1a;
  }
  /* celular com o aplicativo, na frente do notebook */
  .celular {
    position: absolute; right: -42px; top: 100px;
    width: 160px; padding: 6px; border-radius: 27px;
    background: #050505; border: 1.5px solid #3a3a3a;
    box-shadow: 0 30px 50px -14px rgba(0,0,0,.85), 0 0 0 1px rgba(0,0,0,.6);
  }
  .celular img { display: block; width: 100%; border-radius: 21px; }

  /* rodapé */
  .rodape {
    position: absolute; left: 64px; bottom: 52px; width: 452px;
    padding-top: 30px; border-top: 1px solid var(--linha);
  }
  .acesso { display: flex; align-items: center; gap: 22px; }
  .qr { width: 100px; height: 100px; padding: 9px; border-radius: 14px; background: #fff; }
  .qr svg { display: block; width: 100%; height: 100%; }
  .acesso p { font-size: 15px; color: var(--suave); line-height: 1.45; }
  .acesso p strong { display: block; font-size: 20px; color: var(--texto); font-weight: 600; }
  .acesso p span { display: block; margin-top: 6px; font-size: 17px; color: var(--amarelo); font-weight: 500; letter-spacing: .01em; }
</style>
</head>
<body>
<div class="banner">

  <svg class="circuito" viewBox="0 0 360 180" fill="none" stroke-linecap="round" stroke-linejoin="round">
    <path d="M360 58 H262 L220 100 H150" stroke="#FFC400" stroke-width="3"/>
    <circle cx="143" cy="100" r="7" stroke="#FFC400" stroke-width="3"/>
    <path d="M360 104 H300 L262 142 H214" stroke="#363636" stroke-width="3"/>
    <circle cx="208" cy="142" r="6" fill="#363636"/>
  </svg>

  <div class="topo">
    <img class="fluxe" src="fluxe_logo.png" alt="Fluxe — Fluindo na sua empresa!">
    <div class="selo">Projeto SENAI · 2026</div>
  </div>

  <img class="logo" src="sicapda_logo.png" alt="SICAPDA by Fluxe">

  <h1>Acesso seguro.<br>Refeitório <em>sem desperdício.</em></h1>
  <p class="sub">Sistema Inteligente de Controle de Acesso e Previsão de Demanda Alimentar: tudo em um único painel.</p>

  <div class="meio">
    <div class="disco"></div>
    <div class="notebook">
      <div class="tampa"><img src="painel-web.webp" alt=""></div>
      <div class="base"></div>
    </div>
    <div class="celular"><img src="app-painel-escuro.webp" alt=""></div>
    <ul>${itens}
    </ul>
  </div>

  <div class="rodape">
    <div class="acesso">
      <div class="qr">${qr}</div>
      <p><strong>Conheça o sistema</strong>Aponte a câmera para o código<span>fluxeteam.com.br/sicapda</span></p>
    </div>
  </div>

</div>
</body>
</html>`;
  fs.writeFileSync(path.join(__dirname, 'banner.html'), html);
  console.log('banner.html ok');
})();
