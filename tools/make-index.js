const fs = require('fs');
const [,, src, out] = process.argv;
let s = fs.readFileSync(src, 'utf8');
s = s.replace(/<link[^>]*>\s*/g, '').replace(/<meta name="claude:viewport-fit-sides"[^>]*>\s*/, '');
const cyr = 'U+0301,U+0400-045F,U+0490-0491,U+04B0-04B1,U+2116';
const lat = 'U+0000-00FF,U+0131,U+0152-0153,U+02BB-02BC,U+02C6,U+02DA,U+02DC,U+0304,U+0308,U+0329,U+2000-206F,U+20AC,U+2122,U+2191,U+2193,U+2212,U+2215,U+FEFF,U+FFFD';
const face = (fam, file, range) => `@font-face{font-family:"${fam}";font-style:normal;font-weight:400;font-display:swap;src:url(fonts/${file}.woff2) format("woff2");unicode-range:${range}}\n`;
const fonts = face('Russo One','russo-one-cyrillic-400-normal',cyr) + face('Russo One','russo-one-latin-400-normal',lat) +
              face('Neucha','neucha-cyrillic-400-normal',cyr) + face('Neucha','neucha-latin-400-normal',lat);
const i = s.indexOf('</style>') + '</style>'.length;
let head = s.slice(0, i), body = s.slice(i);
head = head.replace('<style>', '<style>\n' + fonts);
const title = (head.match(/<title>[\s\S]*?<\/title>/) || ['<title>Табличный спорт</title>'])[0];
head = head.replace(title, '');
const html = `<!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover, user-scalable=no">
${title}
<style>:root{color-scheme:light;padding-top:env(safe-area-inset-top,0px);padding-bottom:env(safe-area-inset-bottom,0px)}body{margin:0}[hidden]{display:none!important}img{max-width:100%}</style>
${head}
</head>
<body>
${body}
</body>
</html>
`;
const pkg = JSON.parse(fs.readFileSync(require('path').resolve(__dirname, '../package.json'), 'utf8'));
const d = new Date(Date.now() + 3 * 3600e3).toISOString();   // время по Москве
const build = pkg.version + ' · ' + d.slice(8, 10) + '.' + d.slice(5, 7) + ' ' + d.slice(11, 16);
fs.writeFileSync(out, html.split('__BUILD__').join(build));
console.log('index.html bytes:', html.length);
