// The setup page of the shared hosting archive: sends the forms as JSON and tests the web server from the browser
// (the probes of Setup.php), then hands the results to the server, which decides.
'use strict';

(() => {
  const body = document.body;
  const output = document.querySelector('.output');

  const show = (text, ok) => {
    if (!output) return;
    output.hidden = false;
    output.className = 'output ' + (ok ? 'ok' : 'error');
    output.textContent = text;
    output.scrollIntoView({ block: 'nearest' });
  };

  const post = async (data) => {
    const response = await fetch(location.pathname, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/json', 'X-Linkrot-Setup': '1' },
      body: JSON.stringify(data),
    });
    try {
      return await response.json();
    } catch {
      return { ok: false, error: 'HTTP ' + response.status };
    }
  };

  const report = (result, reload) => {
    show(result.ok ? (result.message || 'done') : (result.error || 'failed'), result.ok);
    if (result.ok && reload) setTimeout(() => location.reload(), 800);
  };

  for (const form of document.querySelectorAll('form[data-action]')) {
    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      const data = Object.fromEntries(new FormData(form));
      data.action = form.dataset.action;
      const buttons = form.querySelectorAll('button');
      buttons.forEach((b) => { b.disabled = true; });
      try {
        report(await post(data), form.dataset.reload !== '0');
      } catch (e) {
        show(String(e), false);
      } finally {
        buttons.forEach((b) => { b.disabled = false; });
      }
    });
  }

  for (const button of document.querySelectorAll('button[data-action]')) {
    button.addEventListener('click', async () => {
      if (!confirm('Remove the token ' + button.dataset.name + '?')) return;
      report(await post({ action: button.dataset.action, host: button.dataset.host, name: button.dataset.name }), true);
    });
  }

  // --- the probes ---

  const probeUrl = body.dataset.probe;
  const status = document.querySelector('.probe-status');
  const say = (text) => { if (status) status.textContent = text; };

  const attempt = async (url, options) => {
    try {
      const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
      return { status: response.status, text: await response.text() };
    } catch {
      return { status: 0, text: '' };
    }
  };
  const json = (text) => { try { return JSON.parse(text); } catch { return {}; } };

  const probe = async () => {
    const results = { methods: {}, authorization: false, largest: 0, reachable: {}, modes: {} };

    say('requests through the front controller…');
    for (const method of ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']) {
      const reply = await attempt(probeUrl + '?probe=echo', {
        method,
        headers: { Authorization: 'Bearer linkrot-probe', 'X-Linkrot-Setup': '1' },
        body: method === 'GET' ? undefined : 'x',
      });
      const answer = json(reply.text);
      results.methods[method] = reply.status === 200 && answer.method === method;
      if (method === 'GET') results.authorization = answer.authorization === 'Bearer linkrot-probe';
    }

    for (const size of (body.dataset.sizes || '').split(',').map(Number)) {
      say('a request of ' + (size / 1048576).toFixed(1) + ' MiB…');
      const reply = await attempt(probeUrl + '?probe=echo', { method: 'PATCH', headers: { 'X-Linkrot-Setup': '1' }, body: new Uint8Array(size) });
      if (reply.status === 200 && json(reply.text).length === size) {
        results.largest = size;
        break;
      }
    }

    say('that data and code cannot be fetched…');
    const expected = body.dataset.expected;
    if (body.dataset.dataUrl) {
      const reply = await attempt(body.dataset.dataUrl);
      results.reachable.data = reply.status === 200 && reply.text.includes(expected);
    }
    if (body.dataset.appUrl) {
      const reply = await attempt(body.dataset.appUrl);
      results.reachable.app = reply.status === 200 && reply.text.includes('"name"');
    } else {
      results.reachable.app = false;
    }

    say('who can send a file…');
    const modes = ['xsendfile', 'php'];
    if (body.dataset.litespeed === '1') modes.splice(1, 0, 'litespeed');
    for (const mode of modes) {
      const reply = await attempt(probeUrl + '?probe=send&mode=' + mode);
      results.modes[mode] = reply.status === 200 && reply.text === expected;
    }

    say('saving…');
    const result = await post({ action: 'apply', results });
    say('');
    const lines = (result.errors || []).map((e) => '✗ ' + e).concat((result.warnings || []).map((w) => '! ' + w));
    if (result.download_mode) lines.push('downloads: ' + result.download_mode + ', chunks: ' + (result.chunk_size || 'none'));
    show(lines.join('\n') || result.error || 'done', result.ok);
    if (result.ok) setTimeout(() => location.reload(), 1500);
  };

  const button = document.querySelector('button[data-probe]');
  if (button) {
    button.addEventListener('click', () => { button.disabled = true; probe().finally(() => { button.disabled = false; }); });
    if (body.dataset.autoProbe === '1') button.click();
  }
})();
