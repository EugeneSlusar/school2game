// Проверка экранов входа, профиля и рейтинга в jsdom с поддельным сервером.
// Запуск: NODE_PATH=<папка с node_modules, где стоит jsdom> node tests/ui.test.js
const { JSDOM } = require('jsdom');
const fs = require('fs');
const SRC = require('path').resolve(__dirname, '../docs/artifact-version.html');
const html = '<!doctype html><html><body>' + fs.readFileSync(SRC, 'utf8').replace(/<link[^>]*>/g, '') + '</body></html>';
let fails = 0, n = 0;
const ok = (c, name) => { n++; if (!c) { fails++; console.log('ПРОВАЛ:', name); } };
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

const AV = ['⚽', '🏀', '🏆', '🦊', '🐼', '🐯'];
function makeServer(opts) {
  const st = { loggedIn: false, user: { id: 1, nick: null, avatar: '⚽', needsNick: true }, polls: 0, synced: [], calls: [] };
  const taken = 'Занятый';
  const handler = (method, path, body) => {
    st.calls.push(method + ' ' + path);
    if (path === 'config') return [200, { login: opts.login, avatars: AV }];
    if (path === 'me') return st.loggedIn ? [200, { user: st.user }] : [401, { error: 'not_logged_in' }];
    if (path === 'auth/start') return [200, { code: 'ABCD2345', poll: 'p'.repeat(48), expiresIn: 600, link: 'https://max.ru/testbot?start=ABCD2345' }];
    if (path === 'auth/poll') { st.polls++; if (st.polls < 2) return [200, { status: 'pending' }]; st.loggedIn = true; return [200, { status: 'ok', user: st.user }]; }
    if (path === 'profile') {
      if (!st.loggedIn) return [401, { error: 'not_logged_in' }];
      const nick = String(body.nick || '').trim();
      if (nick.length < 2) return [400, { error: 'bad_nick' }];
      if (nick.toLowerCase() === taken.toLowerCase()) return [409, { error: 'nick_taken' }];
      st.user = { id: 1, nick, avatar: body.avatar, needsNick: false };
      return [200, { user: st.user }];
    }
    if (path === 'logout') { st.loggedIn = false; return [200, { ok: true }]; }
    if (path === 'sync') {
      if (!st.loggedIn) return [401, { error: 'not_logged_in' }];
      st.synced.push(...body.matches.map(m => m.id));
      return [200, { accepted: body.matches.map(m => m.id), duplicate: [], rejected: [], stats: {}, user: st.user }];
    }
    if (path.startsWith('leaderboard')) {
      const me = st.loggedIn && st.user.nick ? { rank: 7, nick: st.user.nick, avatar: st.user.avatar, points: 10, matches: 1, wins: 0, me: true } : null;
      return [200, { period: 'week', subject: 'all', me, top: [
        { rank: 1, nick: 'Ветер', avatar: '🦊', points: 120, matches: 5, wins: 4 },
        { rank: 2, nick: 'Молния', avatar: '🔥', points: 90, matches: 4, wins: 2 }] }];
    }
    return [404, { error: 'not_found' }];
  };
  return {
    st,
    fetch: async (url, o = {}) => {
      if (opts.down) throw new TypeError('network');
      const [status, data] = handler(o.method || 'GET', String(url).replace(/^api\//, ''), o.body ? JSON.parse(o.body) : null);
      return { ok: status < 400, status, json: async () => data };
    }
  };
}

async function boot(opts, seedMatch) {
  const srv = makeServer(opts);
  const errs = [];
  const dom = new JSDOM(html, {
    runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/',
    beforeParse(w) {
      w.fetch = srv.fetch;
      const real = w.setTimeout.bind(w); w.setTimeout = (f, ms, ...a) => real(f, ms >= 1000 ? 30 : ms, ...a); // опрос входа быстрее
      if (seedMatch) w.localStorage.setItem('tablichny-sport-v1', JSON.stringify({ guestId: 'g1', savedAt: 1, matches: [seedMatch] }));
    }
  });
  const w = dom.window; w.addEventListener('error', e => errs.push(e.message));
  const d = w.document, $ = s => d.querySelector(s), $$ = s => Array.from(d.querySelectorAll(s));
  const click = s => $(s).dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  await sleep(150);
  return { srv, w, d, $, $$, click, errs };
}
const match = { id: 'seed1', t: Date.now() - 60000, subject: 'math', topic: 'all', level: 'medium', sport: 'football', format: '1x1', you: 20, foe: 10, outcome: 'win', correct: 12, wrong: 2, timeouts: 1, sec: 120, byTopic: { mul: { n: 15, ok: 12 } }, sent: false };

(async () => {
  // 1. сервера нет: игра живёт
  let t = await boot({ login: true, down: true });
  ok(t.$('#btn-account').textContent === 'Профиль', 'без сервера кнопка «Профиль»');
  t.click('#btn-account');
  ok(!t.$('#screen-profile').hidden && /Нет связи/.test(t.$('#pf-guest-text').textContent) && t.$('#btn-login').hidden, 'без сервера профиль объясняет и не даёт войти');
  t.click('#btn-profile-back'); t.click('#btn-rating'); await sleep(50);
  ok(/недоступен/.test(t.$('#rating-body').textContent), 'без сервера рейтинг сообщает о недоступности');
  t.click('#btn-rating-back'); ok(!t.$('#screen-menu').hidden, 'возврат в меню');
  ok(t.errs.length === 0, 'без сервера нет ошибок JS: ' + t.errs);

  // 2. сервер есть, MAX не настроен
  t = await boot({ login: false });
  t.click('#btn-account');
  ok(/пока не включён/.test(t.$('#pf-guest-text').textContent) && t.$('#btn-login').hidden, 'MAX не настроен: вход скрыт');

  // 3. полный сценарий входа
  t = await boot({ login: true }, match);
  ok(t.$('#btn-account').textContent === 'Войти через MAX', 'гость видит «Войти через MAX»');
  t.click('#btn-account'); t.click('#btn-login'); await sleep(60);
  ok(t.$('#pf-code').textContent === 'ABCD2345' && !t.$('#pf-link').hidden && t.$('#pf-link').href.includes('start=ABCD2345'), 'показан код и ссылка на бота');
  await sleep(250);
  ok(!t.$('#pf-user').hidden && t.$('#pf-guest').hidden, 'после подтверждения показан профиль');
  ok(/Придумай ник/.test(t.$('#pf-sub').textContent), 'новому предложено придумать ник');
  ok(t.$$('#pf-avs button').length === 6, 'сетка аватаров');
  ok(t.srv.st.synced.includes('seed1'), 'гостевой матч отправлен после входа');
  ok(JSON.parse(t.w.localStorage.getItem('tablichny-sport-v1')).matches[0].sent === true, 'матч помечен отправленным');
  t.$('#pf-nick-in').value = 'а'; t.click('#btn-pf-save'); await sleep(40);
  ok(/от 2 до 16/.test(t.$('#pf-err').textContent), 'короткий ник: ошибка');
  t.$('#pf-nick-in').value = 'занятый'; t.click('#btn-pf-save'); await sleep(40);
  ok(/уже занят/.test(t.$('#pf-err').textContent), 'занятый ник: ошибка');
  t.$$('#pf-avs button')[3].dispatchEvent(new t.w.MouseEvent('click', { bubbles: true }));
  t.$('#pf-nick-in').value = 'Ветер'; t.click('#btn-pf-save'); await sleep(60);
  ok(t.$('#pf-nick').textContent === 'Ветер' && t.$('#pf-av').textContent === '🦊' && t.$('#pf-err').textContent === 'Сохранено', 'ник и аватар сохранены');
  ok(t.$('#btn-account').textContent === '🦊 Ветер', 'кнопка в меню показывает ник');
  t.$('#pf-nick-in').value = 'Гром'; t.click('#btn-pf-save'); await sleep(60);
  ok(t.$('#btn-account').textContent.endsWith('Гром'), 'ник можно сменить');
  t.click('#btn-profile-back'); t.click('#btn-rating'); await sleep(80);
  ok(t.$$('#rating-body .lrow').length === 3 && !!t.$('#rating-body .lrow.me'), 'рейтинг: две строки и своя строка ниже');
  ok(/Твоё место: 7/.test(t.$('#rating-note').textContent), 'рейтинг: своё место');
  t.$('[data-rt=period][data-v=all]').dispatchEvent(new t.w.MouseEvent('click', { bubbles: true })); await sleep(60);
  ok(t.srv.st.calls.some(c => c.includes('period=all')), 'переключение периода запрашивает сервер');
  t.click('#btn-rating-back');
  t.click('#btn-account'); t.click('#btn-logout'); await sleep(40);
  ok(!t.$('#pf-guest').hidden && t.$('#pf-user').hidden, 'после выхода снова гость');
  ok(t.errs.length === 0, 'нет ошибок JS: ' + t.errs);

  console.log(fails ? `\nПровалено: ${fails} из ${n}` : `UI: все ${n} проверок пройдены`);
  process.exit(fails ? 1 : 0);
})();
