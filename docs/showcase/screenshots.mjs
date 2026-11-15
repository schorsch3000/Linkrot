// Screenshots of the pages of linkrot for the README, with headless Chromium. Called by build.sh, which starts the server
// and passes PORT, TOKEN (of the tenant files.example.com), CHROMIUM and OUT.
//
// The browser is told that files.example.com is this machine, so the pages show the URLs they would show for real
// (https://files.example.com/...): the server builds them from the host name.
import { chromium } from 'playwright-core';
import { createHash, randomBytes } from 'node:crypto';
import http from 'node:http';

const { PORT, TOKEN, CHROMIUM, OUT } = process.env;
const HOST = 'files.example.com';

function api(method, path, body = null, headers = {}) {
  return new Promise((resolve, reject) => {
    const buffer = body === null ? null : Buffer.isBuffer(body) ? body : Buffer.from(typeof body === 'string' ? body : JSON.stringify(body));
    const req = http.request({
      host: '127.0.0.1', port: Number(PORT), method, path,
      headers: { Host: HOST, Authorization: `Bearer ${TOKEN}`, ...(buffer ? { 'Content-Length': buffer.length } : {}), ...headers },
    }, (res) => {
      const chunks = [];
      res.on('data', (c) => chunks.push(c));
      res.on('end', () => {
        const text = Buffer.concat(chunks).toString();
        if (res.statusCode >= 300) return reject(new Error(`${method} ${path}: ${res.statusCode} ${text}`));
        resolve(text ? JSON.parse(text) : null);
      });
    });
    req.on('error', reject);
    if (buffer) req.write(buffer);
    req.end();
  });
}

const json = (body) => ({ 'Content-Type': 'application/json' });

async function upload(name, size) {
  const content = randomBytes(size);
  const sha256 = createHash('sha256').update(content).digest('hex');
  const created = await api('POST', '/api/v1/files', { name, size, sha256, rm_after: '1w' }, json());
  const body = Buffer.concat([content, createHash('sha256').update(content).digest()]);
  const file = await api('PATCH', `/api/v1/files/${created.id}/content`, body, {
    'Content-Type': 'application/offset+octet-stream',
    'Upload-Offset': '0',
  });
  return { id: file.id, path: `/file/${file.id}/${encodeURIComponent(name)}` };
}

const report = await upload('quarterly-report.pdf', 2_400_000);
const photos = await upload('holiday-photos.zip', 18_000_000);

const browser = await chromium.launch({
  executablePath: CHROMIUM,
  args: ['--no-sandbox', `--host-resolver-rules=MAP ${HOST} 127.0.0.1`],
});
const origin = `http://${HOST}:${PORT}`;

async function shoot(file, path, scheme = 'light', width = 640) {
  const context = await browser.newContext({ colorScheme: scheme, viewport: { width, height: 200 }, deviceScaleFactor: 2 });
  const page = await context.newPage();
  await page.goto(origin + path, { waitUntil: 'networkidle' });
  // a short viewport and fullPage: the image is as tall as the page, no empty space below
  await page.screenshot({ path: `${OUT}/${file}`, fullPage: true });
  await context.close();
  console.log(`  ${file}`);
}

// the built-in look
await shoot('download-light.png', report.path, 'light');
await shoot('download-dark.png', report.path, 'dark');
await shoot('about.png', '/', 'light');

// the look of a host that made it its own: colors, taglines, an imprint and a privacy notice, its own start page
const theme = `/* a warm paper theme */
:root {
  --bg: #f7ecd8; --card: #fffaf0; --fg: #3b2a1a; --muted: #8a6f55; --line: #ead7b8;
  --accent: #b94a12; --accent-fg: #fff; --code-bg: #f4e6c9;
  --rot-a: #c2410c; --rot-b: #7c2d12;
}
.custompage .name { font-family: Georgia, "Times New Roman", serif; }
`;
await api('PUT', '/api/v1/custom/css', theme, { 'Content-Type': 'text/css' });
await api('PUT', '/api/v1/custom/taglines', [
  'Served warm, deleted cold.',
  'Handle with oven mitts.',
  'Best consumed before the mold gets any ideas.',
  'Freshly baked, slowly stale.',
].join('\n'), { 'Content-Type': 'text/plain' });

const imprint = `---
title: Imprint
link: Imprint
order: 10
---
Information according to the applicable law.

**Example Bakery Ltd.**  
12 Sample Street  
Anytown, 12345

## Contact

| Channel | Reach us at |
| --- | --- |
| Phone | +00 123 4567890 |
| E-mail | [hello@example.com](mailto:hello@example.com) |

## Represented by

Managing director: Jane Doe

> This is an example. The data is made up.
`;
const privacy = `---
title: Privacy
link: Privacy
order: 20
---
We only keep what is needed to hand out a file, and we delete it when its link has rotted.
`;
const welcome = `---
title: The Bread Box
replaces_startpage: true
---
Files to pick up are kept here. All you need is a link, nothing else.

The link expires on its own. After that the file is gone, and nobody has to clean up.
`;
for (const [name, text] of [['imprint', imprint], ['privacy', privacy], ['welcome', welcome]]) {
  await api('PUT', `/api/v1/pages/${name}`, text, { 'Content-Type': 'text/markdown' });
}
await shoot('themed-download.png', photos.path, 'light');
await shoot('imprint.png', '/page/imprint', 'light');
await shoot('startpage.png', '/', 'light');

// the same download page with five of the six switches off: no headline, no tagline, no commands for a terminal
await api('PATCH', '/api/v1/settings', {
  show_taglines: false,
  show_title: false,
  show_linkrot_line: false,
  show_curl_line: false,
  show_hash_line: false,
}, json());
await shoot('minimal-download.png', photos.path, 'light');

await browser.close();
