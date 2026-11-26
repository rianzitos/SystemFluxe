// node render.js <escala> <saida.png> [saida.pdf]
const { chromium } = require('playwright');
const path = require('path');
(async () => {
  const [escala, png, pdf] = process.argv.slice(2);
  const b = await chromium.launch();
  const p = await b.newPage({ viewport: { width: 900, height: 1200 }, deviceScaleFactor: Number(escala) });
  await p.goto('file://' + path.join(__dirname, 'banner.html'));
  await p.evaluate(() => document.fonts.ready);
  await p.waitForTimeout(400);
  await p.screenshot({ path: png, clip: { x: 0, y: 0, width: 900, height: 1200 } });
  if (pdf) {
    await p.pdf({ path: pdf, width: '900px', height: '1200px', printBackground: true, pageRanges: '1' });
  }
  await b.close();
})();
