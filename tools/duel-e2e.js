// Живая проверка онлайн-дуэли: два браузера (Playwright) против локального PHP-сервера на SQLite.
// Запуск (из любой папки, Playwright должен быть доступен):
//   rm -f /tmp/s2g-duel.sqlite; S2G_ENV_FILE=/nonexistent DB_DRIVER=sqlite DB_PATH=/tmp/s2g-duel.sqlite php -S 127.0.0.1:8099 -t public_html &
//   NODE_PATH=$(npm root -g) SHOTS=/tmp node tools/duel-e2e.js
// Создаёт комнату, входит по коду, играет матч, проверяет одинаковый итог и рейтинг онлайн-дуэлей.
const { chromium } = require('playwright');
const BASE = 'http://127.0.0.1:8099/';
const log = (...a) => console.log(...a);
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

async function player(browser, name) {
  const ctx = await browser.newContext({ viewport: { width: 390, height: 780 }, deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  page.on('pageerror', e => log(name, 'PAGEERROR', e.message));
  await page.goto(BASE);
  await page.waitForFunction(() => document.querySelector('#avb-name').textContent !== 'Гость' && document.querySelector('#avb-hint').textContent !== 'загружаю…', null, { timeout: 15000 });
  return { ctx, page, name };
}
// отвечает на примеры: умножение/деление «a × b =», «a ÷ b =»
async function answerLoop(p, ms, maxSec, tag, wrongEvery = 0) {
  const t0 = Date.now(); let n = 0;
  while (Date.now() - t0 < maxSec * 1000) {
    const st = await p.page.evaluate(() => {
      const res = !document.querySelector('#screen-result').hidden;
      const pre = document.querySelector('#eq-pre').textContent;
      const keys = document.querySelector('#keypad').classList.contains('locked');
      return { res, pre, keys, cd: document.querySelector('#countdown').hidden ? '' : document.querySelector('#countdown').textContent };
    });
    if (st.res) return n;
    const m = /^(\d+)\s*([×÷])\s*(\d+)\s*=$/.exec(st.pre.trim());
    if (m && !st.keys) {
      const a = +m[1], b = +m[3];
      let ans = m[2] === '×' ? a * b : a / b;
      if (wrongEvery && (n + 1) % wrongEvery === 0) ans = ans % 10 === 0 ? ans + 1 : ans - (ans % 10) + ((ans % 10) + 1) % 10;   // намеренная ошибка
      for (const ch of String(ans)) await p.page.click(`#keypad button[data-k="${ch}"]`);
      n++;
    }
    await sleep(ms);
  }
  return n;
}

(async () => {
  const browser = await chromium.launch();
  const A = await player(browser, 'A'), B = await player(browser, 'B');
  await A.page.fill('#pf-nick-in', 'x').catch(() => {});
  // ники для рейтинга
  for (const [p, nick] of [[A, 'Альфа'], [B, 'Бета']]) {
    await p.page.click('#btn-avatar'); await p.page.fill('#pf-nick-in', nick); await p.page.click('#btn-pf-save');
    await p.page.waitForFunction(() => document.querySelector('#pf-err').textContent === 'Сохранено');
    await p.page.click('#btn-profile-back');
  }
  // A создаёт комнату
  await A.page.click('#btn-duel');
  await A.page.click('#du-create');
  await A.page.waitForFunction(() => /^\d{4}$/.test(document.querySelector('#du-code').textContent));
  const code = await A.page.textContent('#du-code');
  log('код комнаты:', code);
  await A.page.screenshot({ path: (process.env.SHOTS || '/tmp') + '/duel-room.png' });
  // B входит по коду
  await B.page.click('#btn-duel');
  await B.page.fill('#du-code-in', code);
  await B.page.click('#du-join');
  await Promise.all([A, B].map(p => p.page.waitForFunction(() => !document.querySelector('#screen-match').hidden, null, { timeout: 15000 })));
  log('оба в матче');
  await sleep(1500);
  await A.page.screenshot({ path: (process.env.SHOTS || '/tmp') + '/duel-countdown-A.png' });
  const foe = await Promise.all([A, B].map(p => p.page.textContent('#foe-label')));
  log('соперники:', foe.join(' / '));
  // играют: A быстро, B медленнее
  const [na, nb] = await Promise.all([answerLoop(A, 700, 100, 'A', 5), answerLoop(B, 1700, 100, 'B', 3)]);
  log('ответов A/B:', na, nb);
  for (const p of [A, B]) await p.page.waitForFunction(() => !document.querySelector('#screen-result').hidden, null, { timeout: 20000 });
  const res = await Promise.all([A, B].map(p => p.page.evaluate(() => ({
    title: document.querySelector('#res-title').textContent, you: document.querySelector('#res-you-pts').textContent, foe: document.querySelector('#res-foe-pts').textContent,
    why: document.querySelector('#res-reason').textContent, rematch: document.querySelector('#btn-rematch').textContent
  }))));
  log('итог A:', JSON.stringify(res[0])); log('итог B:', JSON.stringify(res[1]));
  for (const p of [A, B]) {
    const cmp = await p.page.$$eval('#cmp-rows .cmp-row', r => r.map(x => Array.from(x.children).map(c => c.textContent).join(' | ')));
    const foeErrs = await p.page.$$eval('#res-foe-errs .chip', r => r.map(x => x.textContent));
    const myErrs = await p.page.$$eval('#review .chip', r => r.map(x => x.textContent));
    log(p.name, 'сравнение:', JSON.stringify(cmp), '\n   ошибки соперника:', JSON.stringify(foeErrs), 'мои:', JSON.stringify(myErrs));
  }
  await A.page.screenshot({ path: (process.env.SHOTS || '/tmp') + '/duel-result-A.png', fullPage: true });
  await B.page.screenshot({ path: (process.env.SHOTS || '/tmp') + '/duel-result-B.png', fullPage: true });
  // рейтинг онлайн-дуэлей
  await A.page.click('#btn-menu'); await A.page.click('#btn-rating');
  await A.page.click('#rt-mode [data-v="duel"]');
  await A.page.waitForFunction(() => document.querySelectorAll('#rating-body .lrow').length >= 2, null, { timeout: 8000 });
  const rows = await A.page.$$eval('#rating-body .lrow', r => r.map(x => x.textContent.replace(/\s+/g, ' ')));
  log('рейтинг дуэлей:', JSON.stringify(rows));
  await A.page.screenshot({ path: (process.env.SHOTS || '/tmp') + '/duel-rating.png' });
  await A.page.click('#rt-mode [data-v="bot"]'); await sleep(800);
  const rowsBot = await A.page.$$eval('#rating-body .lrow', r => r.length);
  log('рейтинг против ботов (дуэли не попали):', rowsBot);
  await browser.close();
})().catch(e => { console.error('FAIL', e); process.exit(1); });
