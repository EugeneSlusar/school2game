// Проверка аватара-кнопки, профиля, входа по коду и рейтинга в jsdom с поддельным сервером.
// Запуск: NODE_PATH=<папка с node_modules, где стоит jsdom> node tests/ui.test.js
const { JSDOM } = require('jsdom');
const fs = require('fs');
const SRC = require('path').resolve(__dirname, '../docs/artifact-version.html');
const html = '<!doctype html><html><body>' + fs.readFileSync(SRC, 'utf8').replace(/<link[^>]*>/g, '') + '</body></html>';
let fails = 0, n = 0;
const ok = (c, name) => { n++; if (!c) { fails++; console.log('ПРОВАЛ:', name); } };
const sleep = (ms) => new Promise(r => setTimeout(r, ms));

const AV = ['⚽', '🏀', '🏆', '🦊', '🐼', '🐯'];
const CODE = 'ABCD-EFGH-JKMN';
function makeServer(opts) {
  const st = { cookie: !!opts.cookie, user: { id: 7, nick: null, name: 'Неопознанный №7', avatar: '🐼', needsNick: true }, calls: [], synced: [], guests: 0, recovers: 0 };
  const handler = (method, path, body) => {
    st.calls.push(method + ' ' + path);
    if (path === 'config') return [200, { accounts: true, avatars: AV }];
    if (path === 'me') return st.cookie ? [200, { user: st.user }] : [401, { error: 'not_logged_in' }];
    if (path === 'auth/guest') { st.guests++; st.cookie = true; return [200, { user: st.user, recoveryCode: CODE }]; }
    if (path === 'auth/recover') {
      st.recovers++;
      if (String(body.code).replace(/[^A-Z0-9]/gi, '').toUpperCase() !== CODE.replace(/-/g, '')) return [404, { error: 'bad_code' }];
      st.cookie = true; return [200, { user: st.user }];
    }
    if (path === 'auth/newcode') return [200, { recoveryCode: 'WXYZ-2345-6789' }];
    if (path === 'profile') {
      if (!st.cookie) return [401, { error: 'not_logged_in' }];
      if (body.nick !== undefined) {
        const nick = String(body.nick).trim();
        if (nick.length < 2) return [400, { error: 'bad_nick' }];
        if (nick === 'плохой') return [422, { error: 'nick_not_allowed' }];
        if (nick.toLowerCase() === 'занятый') return [409, { error: 'nick_taken' }];
        st.user = Object.assign({}, st.user, { nick, name: nick, needsNick: false });
      }
      if (body.avatar) st.user = Object.assign({}, st.user, { avatar: body.avatar });
      return [200, { user: st.user }];
    }
    if (path === 'sync') {
      if (!st.cookie) return [401, { error: 'not_logged_in' }];
      st.synced.push(...body.matches.map(m => m.id));
      return [200, { accepted: body.matches.map(m => m.id), duplicate: [], rejected: [], stats: {}, user: st.user }];
    }
    if (path.startsWith('leaderboard')) {
      const me = st.cookie ? { rank: 7, name: st.user.name, anon: st.user.nick === null, avatar: st.user.avatar, points: 10, matches: 1, wins: 0, me: true } : null;
      return [200, { period: 'week', subject: 'all', me, top: [
        { rank: 1, name: 'Ветер', anon: false, avatar: '🦊', points: 120, matches: 5, wins: 4 },
        { rank: 2, name: 'Неопознанный №3', anon: true, avatar: '🔥', points: 90, matches: 4, wins: 2 }] }];
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

async function boot(opts, store = {}) {
  const srv = makeServer(opts);
  const errs = [];
  const dom = new JSDOM(html, {
    runScripts: 'dangerously', pretendToBeVisual: true, url: 'http://localhost/',
    beforeParse(w) {
      w.fetch = srv.fetch;
      for (const k of Object.keys(store)) w.localStorage.setItem(k, store[k]);
    }
  });
  const w = dom.window; w.addEventListener('error', e => errs.push(e.message));
  const d = w.document, $ = s => d.querySelector(s), $$ = s => Array.from(d.querySelectorAll(s));
  const click = s => $(s).dispatchEvent(new w.MouseEvent('click', { bubbles: true }));
  await sleep(150);
  return { srv, w, d, $, $$, click, errs };
}
const match = { id: 'seed1', t: Date.now() - 60000, subject: 'math', topic: 'all', level: 'medium', sport: 'football', format: '1x1', you: 20, foe: 10, outcome: 'win', correct: 12, wrong: 2, timeouts: 1, sec: 120, byTopic: { mul: { n: 15, ok: 12 } }, sent: false };
const seed = { 'tablichny-sport-v1': JSON.stringify({ guestId: 'g1', savedAt: 1, matches: [match] }) };

(async () => {
  // 1. сервера нет: игра живёт
  let t = await boot({ down: true });
  ok(t.$('#avb-name').textContent === 'Гость' && /нет связи/.test(t.$('#avb-hint').textContent), 'без сервера: «Гость, нет связи»');
  t.click('#btn-avatar');
  ok(!t.$('#screen-profile').hidden && !t.$('#pf-offline').hidden && /Нет связи/.test(t.$('#pf-offline').textContent) && t.$('#pf-user').hidden, 'без сервера профиль объясняет ситуацию');
  t.click('#btn-profile-back'); t.click('#btn-rating'); await sleep(50);
  ok(/недоступен/.test(t.$('#rating-body').textContent), 'без сервера рейтинг недоступен');
  t.click('#btn-rating-back'); ok(!t.$('#screen-menu').hidden, 'возврат в меню');
  ok(t.errs.length === 0, 'без сервера нет ошибок JS: ' + t.errs);

  // 2. новый посетитель: сразу «неопознанный» со случайным аватаром, матч уходит в рейтинг
  t = await boot({}, seed);
  ok(t.srv.st.guests === 1, 'создан один анонимный профиль');
  ok(t.$('#avb-av').textContent === '🐼' && t.$('#avb-name').textContent === 'Неопознанный' && /впиши своё имя/.test(t.$('#avb-hint').textContent) && !t.$('#avb-dot').hidden, 'кнопка-аватар вверху: неопознанный, подсказка и красная точка');
  ok(t.$('#screen-menu .topbar') && t.$('#screen-menu').firstElementChild.classList.contains('topbar'), 'аватар в самом верху меню');
  ok(t.w.localStorage.getItem('school2game-recovery') === CODE, 'код восстановления сохранён на устройстве');
  ok(t.srv.st.synced.includes('seed1') && JSON.parse(t.w.localStorage.getItem('tablichny-sport-v1')).matches[0].sent === true, 'прежний гостевой матч ушёл в рейтинг и помечен отправленным');
  ok(!/MAX|max\.ru/.test(t.$('#app').textContent), 'в интерфейсе нет упоминаний стороннего мессенджера');

  // 3. профиль: ник, фильтр, аватар, коды
  t.click('#btn-avatar');
  ok(/Неопознанный игрок/.test(t.$('#pf-sub').textContent) && t.$$('#pf-avs button').length === 6, 'профиль предлагает вписать ник, есть сетка аватаров');
  ok(t.$('#pf-rc').textContent === CODE, 'код восстановления показан');
  t.$('#pf-nick-in').value = 'а'; t.click('#btn-pf-save'); await sleep(40);
  ok(/от 2 до 16/.test(t.$('#pf-err').textContent), 'короткий ник: ошибка');
  t.$('#pf-nick-in').value = 'плохой'; t.click('#btn-pf-save'); await sleep(40);
  ok(/нельзя/.test(t.$('#pf-err').textContent), 'запрещённый ник: «нельзя»');
  t.$('#pf-nick-in').value = 'занятый'; t.click('#btn-pf-save'); await sleep(40);
  ok(/уже занят/.test(t.$('#pf-err').textContent), 'занятый ник: ошибка');
  t.$$('#pf-avs button')[3].dispatchEvent(new t.w.MouseEvent('click', { bubbles: true }));
  t.$('#pf-nick-in').value = 'Ветер'; t.click('#btn-pf-save'); await sleep(60);
  ok(t.$('#pf-nick').textContent === 'Ветер' && t.$('#pf-av').textContent === '🦊' && t.$('#pf-err').textContent === 'Сохранено', 'ник и аватар сохранены');
  ok(t.$('#avb-name').textContent === 'Ветер' && t.$('#avb-av').textContent === '🦊' && t.$('#avb-dot').hidden && t.$('#avb-hint').textContent === 'мой профиль', 'кнопка-аватар обновилась, точка пропала');
  t.$('#pf-nick-in').value = 'Гром'; t.click('#btn-pf-save'); await sleep(60);
  ok(t.$('#avb-name').textContent === 'Гром', 'ник можно сменить');
  t.click('#btn-code-new'); await sleep(40);
  ok(t.$('#pf-rc').textContent === 'WXYZ-2345-6789' && t.w.localStorage.getItem('school2game-recovery') === 'WXYZ-2345-6789', 'новый код показан и сохранён');
  t.$('#rc-in').value = 'xxxx'; t.click('#btn-recover'); await sleep(40);
  ok(/Код не подошёл/.test(t.$('#rc-err').textContent), 'неверный код: сообщение');
  t.$('#rc-in').value = 'abcd efgh jkmn'; t.click('#btn-recover'); await sleep(60);
  ok(t.srv.st.recovers >= 1 && t.$('#rc-err').textContent === '' && t.w.localStorage.getItem('school2game-recovery') === 'ABCD-EFGH-JKMN', 'вход по коду: регистр и пробелы не важны, код сохранён в общем виде');

  // 4. рейтинг с неопознанными
  t.click('#btn-profile-back'); t.click('#btn-rating'); await sleep(80);
  ok(t.$$('#rating-body .lrow').length === 3 && !!t.$('#rating-body .lrow.me'), 'рейтинг: две строки и своя ниже');
  ok(/Неопознанный №3/.test(t.$('#rating-body').textContent), 'в рейтинге есть неопознанные игроки');
  t.$('[data-rt=period][data-v=all]').dispatchEvent(new t.w.MouseEvent('click', { bubbles: true })); await sleep(60);
  ok(t.srv.st.calls.some(c => c.includes('period=all')), 'переключение периода запрашивает сервер');
  t.click('#btn-rating-profile'); ok(!t.$('#screen-profile').hidden, 'из рейтинга можно перейти в профиль');
  ok(t.errs.length === 0, 'нет ошибок JS: ' + t.errs);

  // 5. вернувшийся посетитель: есть cookie, нового профиля не создаём
  t = await boot({ cookie: true }, { 'school2game-recovery': CODE });
  ok(t.srv.st.guests === 0 && t.srv.st.recovers === 0, 'с cookie профиль не пересоздаётся');

  // 6. cookie пропала, но код на устройстве есть: возвращаем тот же профиль
  t = await boot({}, { 'school2game-recovery': CODE });
  ok(t.srv.st.recovers === 1 && t.srv.st.guests === 0 && t.$('#avb-av').textContent === '🐼', 'без cookie профиль восстановлен по сохранённому коду');

  // 7. код на устройстве устарел: создаём нового
  t = await boot({}, { 'school2game-recovery': 'AAAA-AAAA-AAAA' });
  ok(t.srv.st.recovers === 1 && t.srv.st.guests === 1 && t.w.localStorage.getItem('school2game-recovery') === CODE, 'неверный сохранённый код заменён новым профилем');

  console.log(fails ? `\nПровалено: ${fails} из ${n}` : `UI: все ${n} проверок пройдены`);
  process.exit(fails ? 1 : 0);
})();
