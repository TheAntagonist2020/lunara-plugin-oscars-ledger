// Query the Academy Awards Database (awardsdatabase.oscars.org) through a real
// browser session. The site blocks plain HTTP clients, so this drives Chromium.
//
//   node ampas.js search '<json>'   structured results as JSON
//       json keys: Nominee, FilmTitle, AwardShowNumberFrom, AwardShowNumberTo,
//                  AwardCategory (array of category ids as strings), IsWinnersOnly
//       e.g. '{"Nominee":"RCA Sound"}'  '{"FilmTitle":"Hamlet","AwardShowNumberFrom":21,"AwardShowNumberTo":21}'
//   node ampas.js follow '<json>' '<href part>'   run the search, click the first
//       result link whose href contains <href part> (e.g. 'nominationId=2148' for
//       that nominee's page, 'filmId=673' for a film's page), return that page's
//       text and links. Nominee pages show every nomination the Academy files
//       under that nominee record, with any [aka: ...] alternate names.
//
// Run with:  bash ampas.sh search '{"Nominee":"RCA Sound"}'
const { chromium } = require('playwright');

const [mode, arg] = process.argv.slice(2);
const BASE = 'https://awardsdatabase.oscars.org';

(async () => {
  const browser = await chromium.launch({
    executablePath: '/opt/pw-browsers/chromium',
    proxy: { server: process.env.AMPAS_PROXY || 'http://127.0.0.1:45815' },
    // Trust exactly the session proxy's CA key (Chromium has no CA-file flag).
    args: ['--no-sandbox', '--ignore-certificate-errors-spki-list=' + process.env.SPKI, '--disable-blink-features=AutomationControlled'],
  });
  const ctx = await browser.newContext({ userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36', locale: 'en-US' });
  const page = await ctx.newPage();
  await page.route(/google|doubleclick|googletagmanager/, r => r.abort());
  await page.goto(BASE + '/', { waitUntil: 'domcontentloaded', timeout: 60000 });

  if (mode === 'follow') {
    // Result links (nominee, film, ceremony and category views) are form POSTs
    // that carry the current search, so run the search, then click the link.
    const [, , , , hrefPart] = process.argv;
    const q = Object.assign({ Sort: '3-Award Category-Chron', Search: 'Basic' }, JSON.parse(arg));
    await page.goto(BASE + '/Search/GetResults?query=' + encodeURIComponent(JSON.stringify(q)), { waitUntil: 'networkidle', timeout: 120000 });
    const link = await page.$(`a.nominations-link[href*="${hrefPart.replace(/"/g, '')}"]`);
    if (!link) throw new Error('no result link whose href contains ' + hrefPart);
    const nav = page.waitForNavigation({ waitUntil: 'networkidle', timeout: 120000 });
    await link.click();
    const resp = await nav;
    const text = await page.evaluate(() => {
      const t = document.body.innerText;
      const i = t.indexOf('Results displayed');
      const j = t.indexOf('Note: Names and film titles');
      return t.slice(i < 0 ? 0 : i, j < 0 ? undefined : j).trim();
    });
    const links = await page.$$eval('a.nominations-link', as => as.map(a => ({ text: a.innerText.trim(), href: a.getAttribute('href') })));
    process.stdout.write(JSON.stringify({ status: resp && resp.status(), url: page.url(), text, links }, null, 1) + '\n');
  } else {
    const q = Object.assign({ Sort: '3-Award Category-Chron', Search: 'Basic' }, JSON.parse(arg));
    const url = BASE + '/Search/GetResults?query=' + encodeURIComponent(JSON.stringify(q));
    const resp = await page.goto(url, { waitUntil: 'networkidle', timeout: 120000 });
    const results = await page.evaluate(() => {
      const t = (el, sel) => { const x = el.querySelector(sel); return x ? x.innerText.trim() : null; };
      const all = (el, sel) => [...el.querySelectorAll(sel)].map(x => x.innerText.trim());
      const out = [];
      for (const g of document.querySelectorAll('.result-group')) {
        const year = t(g, '.result-group-title');
        for (const sg of g.querySelectorAll('.result-subgroup')) {
          const category = t(sg, '.result-subgroup-title');
          for (const d of sg.querySelectorAll('.result-details')) {
            out.push({
              year,
              category,
              winner: !!d.querySelector('.glyphicon-star'),
              kind: (d.className.match(/awards-result-(\w+)/) || [])[1] || null,
              statement: t(d, '.awards-result-nominationstatement'),
              films: all(d, '.awards-result-film-title'),
              character: t(d, '.awards-result-character-name'),
              description: t(d, '.awards-result-description'),
              citation: t(d, '.awards-result-citation'),
              links: [...d.querySelectorAll('a.nominations-link')].map(a => ({ text: a.innerText.trim(), href: a.getAttribute('href') })),
              text: d.innerText.replace(/\s+\n/g, '\n').trim(),
            });
          }
        }
      }
      return out;
    });
    process.stdout.write(JSON.stringify({ status: resp && resp.status(), url, count: results.length, results }, null, 1) + '\n');
  }
  await browser.close();
})().catch(e => { console.error('ERR', e.message.split('\n')[0]); process.exit(1); });
