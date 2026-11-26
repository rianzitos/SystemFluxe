// node render.js <escala> <saida.png> [saida.pdf]
// A arte é desenhada em 910 x 1170 px (7:9). O PDF sai no tamanho real: 70 x 90 cm.
const { chromium } = require('playwright');
const path = require('path');
const W = 910, H = 1170;
(async () => {
  const [escala, png, pdf] = process.argv.slice(2);
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: W, height: H }, deviceScaleFactor: Number(escala) });
  await p.goto('file://' + path.join(__dirname, 'banner.html'));
  await p.evaluate(() => document.fonts.ready);
  await p.waitForTimeout(400);
  await p.screenshot({ path: png, clip: { x: 0, y: 0, width: W, height: H } });
  if (pdf) {
    // 70 cm em px CSS (96 por polegada) dividido pela largura da arte
    const k = (70 / 2.54 * 96) / W;
    await p.addStyleTag({ content: `
      @page { size: 70cm 90cm; margin: 0; }
      html, body { width: 70cm; height: 90cm; overflow: hidden; }
      .banner { transform: scale(${k}); transform-origin: 0 0; }` });
    await p.pdf({ path: pdf, width: '70cm', height: '90cm', printBackground: true, pageRanges: '1' });
  }
  await b.close();
})();
