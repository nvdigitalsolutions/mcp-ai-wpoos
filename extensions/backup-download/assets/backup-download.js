(() => {
  'use strict';

  // ── Backup Download extension for Hermes WebUI ───────────────────────────
  // Adds a floating "Backup" button that opens a small panel to:
  //   - create a full or quick backup (runs `hermes backup` on the server
  //     via the extension's loopback sidecar, through the WebUI proxy)
  //   - list recent backups with sizes
  //   - download a backup in the browser (assembled from 448 KB chunks
  //     fetched through the proxy — proxied responses are capped at 512 KB,
  //     so a whole zip can never be proxied in one response)
  //
  // All state-changing calls go through the authenticated same-origin WebUI
  // proxy and carry the WebUI CSRF token. The sidecar token is learned from
  // /health via the proxy and sent as X-Backup-Download-Token (the proxy
  // forwards custom headers; it strips cookie/authorization/origin only).

  const EXT = 'backup-download';
  if (window.__hermesBackupDownloadLoaded) return;
  window.__hermesBackupDownloadLoaded = true;

  const API = '/api/extensions/' + EXT + '/sidecar';
  const CONSENT_API = '/api/extensions/sidecar-proxy-consent';
  const CHUNK = 448 * 1024;             // must stay under the 512 KB proxy cap
  const MAX_ASSEMBLE = 800 * 1024 * 1024; // refuse larger in-browser assembly
  const POLL_MS = 2000;
  const POLL_LIMIT = 1800;              // ~1 hour of polling

  const cfg = window.__HERMES_CONFIG__ || {};
  const csrfToken = cfg.csrfToken || '';

  let sidecarToken = null;
  let sidecarStatus = 'unknown'; // unknown | ok | consent | down
  let pollTimer = null;
  let pollCount = 0;
  let downloading = false;

  function $(id) { return document.getElementById(id); }

  function esc(s) {
    return String(s == null ? '' : s)
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
  }

  function humanSize(n) {
    n = Number(n) || 0;
    if (n < 1024) return n + ' B';
    const units = ['KB', 'MB', 'GB'];
    let v = n;
    let i = -1;
    do { v /= 1024; i++; } while (v >= 1024 && i < units.length - 1);
    return v.toFixed(1) + ' ' + units[i];
  }

  // ── fetch helpers ────────────────────────────────────────────────────────
  // Always use ABSOLUTE same-origin fetch paths (core's api() can resolve
  // relative to a /session/<id> baseURI and 404).
  function proxyHeaders(withSidecarToken) {
    const h = {};
    if (csrfToken) h['X-Hermes-CSRF-Token'] = csrfToken;
    if (withSidecarToken && sidecarToken) h['X-Backup-Download-Token'] = sidecarToken;
    return h;
  }

  function fetchJson(path, opts, withSidecarToken) {
    const init = Object.assign({ credentials: 'same-origin' }, opts || {});
    init.headers = Object.assign({}, init.headers, proxyHeaders(withSidecarToken));
    return fetch(path, init).then(function (r) {
      if (r.status === 204) return {};
      return r.json().catch(function () { return {}; }).then(function (body) {
        return { status: r.status, body: body };
      });
    });
  }

  // ── toast ────────────────────────────────────────────────────────────────
  function toast(msg) {
    const t = document.createElement('div');
    t.className = 'hwx-bd-toast';
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('hwx-bd-toast--in'); }, 10);
    setTimeout(function () {
      t.classList.remove('hwx-bd-toast--in');
      setTimeout(function () { t.remove(); }, 250);
    }, 3200);
  }

  // ── panel rendering ──────────────────────────────────────────────────────
  function ensurePanel() {
    let btn = $('hwxBdBtn');
    if (!btn) {
      btn = document.createElement('button');
      btn.id = 'hwxBdBtn';
      btn.type = 'button';
      btn.className = 'hwx-bd-btn';
      btn.textContent = '\uD83D\uDCBE Backup';
      btn.title = 'Hermes backup — create and download';
      btn.addEventListener('click', function () {
        const p = $('hwxBdPanel');
        p.classList.toggle('hwx-bd-panel--open');
        if (p.classList.contains('hwx-bd-panel--open')) refreshHealth();
      });
      document.body.appendChild(btn);
    }
    let panel = $('hwxBdPanel');
    if (!panel) {
      panel = document.createElement('div');
      panel.id = 'hwxBdPanel';
      panel.className = 'hwx-bd-panel';
      panel.innerHTML =
        '<div class="hwx-bd-head">' +
          '<span class="hwx-bd-title">Hermes backup</span>' +
          '<button type="button" class="hwx-bd-close" id="hwxBdClose" aria-label="Close">&times;</button>' +
        '</div>' +
        '<div class="hwx-bd-status" id="hwxBdStatus">Checking sidecar&hellip;</div>' +
        '<div class="hwx-bd-actions" id="hwxBdActions">' +
          '<button type="button" class="hwx-bd-create" id="hwxBdFull">Create full backup</button>' +
          '<button type="button" class="hwx-bd-create hwx-bd-create--ghost" id="hwxBdQuick">Quick backup</button>' +
        '</div>' +
        '<div class="hwx-bd-consent" id="hwxBdConsent" hidden>' +
          'The WebUI needs your approval to talk to this extension\u2019s sidecar. ' +
          '<button type="button" class="hwx-bd-approve" id="hwxBdApprove">Enable sidecar access</button>' +
        '</div>' +
        '<div class="hwx-bd-progress" id="hwxBdProgress"></div>' +
        '<div class="hwx-bd-list" id="hwxBdList"></div>' +
        '<div class="hwx-bd-foot">Full = config + skills + sessions + data. Quick = critical state files.</div>';
      document.body.appendChild(panel);
      $('hwxBdClose').addEventListener('click', function () {
        panel.classList.remove('hwx-bd-panel--open');
      });
      $('hwxBdFull').addEventListener('click', function () { createBackup('full'); });
      $('hwxBdQuick').addEventListener('click', function () { createBackup('quick'); });
      $('hwxBdApprove').addEventListener('click', approveConsent);
    }
    return panel;
  }

  function setStatus(html) {
    const el = $('hwxBdStatus');
    if (el) el.innerHTML = html;
  }

  function setConsent(show) {
    const el = $('hwxBdConsent');
    if (el) el.hidden = !show;
  }

  function setButtons(enabled) {
    ['hwxBdFull', 'hwxBdQuick'].forEach(function (id) {
      const el = $(id);
      if (el) el.disabled = !enabled;
    });
  }

  function renderList(backups) {
    const el = $('hwxBdList');
    if (!el) return;
    if (!backups || !backups.length) {
      el.innerHTML = '<div class="hwx-bd-empty">No backups yet.</div>';
      return;
    }
    let html = '';
    backups.forEach(function (b) {
      const kind = b.kind === 'quick' ? 'quick' : 'full';
      const when = (b.created_at || '').replace('T', ' ').slice(0, 19) || '';
      html +=
        '<div class="hwx-bd-row">' +
          '<div class="hwx-bd-row-main">' +
            '<div class="hwx-bd-row-name">' + esc(b.file) + '</div>' +
            '<div class="hwx-bd-row-meta">' + esc(kind) + ' &middot; ' +
              esc(humanSize(b.size)) + ' &middot; ' + esc(when) + '</div>' +
          '</div>' +
          '<button type="button" class="hwx-bd-dl" data-file="' + esc(b.file) +
            '" data-size="' + esc(b.size) + '">Download</button>' +
        '</div>';
    });
    el.innerHTML = html;
    el.querySelectorAll('.hwx-bd-dl').forEach(function (btn) {
      btn.addEventListener('click', function () {
        const file = btn.getAttribute('data-file');
        const size = parseInt(btn.getAttribute('data-size'), 10) || 0;
        assembleDownload(file, size);
      });
    });
  }

  // ── sidecar plumbing ─────────────────────────────────────────────────────
  function refreshHealth() {
    setStatus('Checking sidecar&hellip;');
    return fetchJson(API + '/health', { method: 'GET' }, false).then(function (res) {
      if (res.status === 200 && res.body && res.body.ok) {
        sidecarToken = res.body.token || null;
        sidecarStatus = 'ok';
        setConsent(false);
        setButtons(true);
        setStatus('Sidecar connected.');
        refreshList();
        return true;
      }
      if (res.status === 403) {
        sidecarStatus = 'consent';
        setConsent(true);
        setButtons(false);
        setStatus('Sidecar proxy approval required.');
        return false;
      }
      if (res.status === 409 || res.status === 502 || res.status === 404) {
        sidecarStatus = 'down';
        setConsent(false);
        setButtons(false);
        setStatus(
          'Sidecar not reachable. Start it on the server:<br>' +
          '<code>~/.hermes/webui/extensions/' + EXT + '/run-sidecar.sh start</code>'
        );
        return false;
      }
      sidecarStatus = 'down';
      setButtons(false);
      setStatus('Unexpected response (HTTP ' + esc(res.status) + '). See browser console.');
      return false;
    }).catch(function () {
      sidecarStatus = 'down';
      setButtons(false);
      setStatus('Sidecar not reachable (network error). Start it on the server:<br>' +
        '<code>~/.hermes/webui/extensions/' + EXT + '/run-sidecar.sh start</code>');
      return false;
    });
  }

  function approveConsent() {
    setStatus('Requesting sidecar approval&hellip;');
    fetchJson(CONSENT_API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: EXT, approved: true }),
    }, false).then(function (res) {
      if (res.status === 200) {
        toast('Sidecar access approved');
        refreshHealth();
      } else if (res.status === 409) {
        sidecarStatus = 'down';
        setConsent(false);
        setStatus('Sidecar is not running — start it first:<br>' +
          '<code>~/.hermes/webui/extensions/' + EXT + '/run-sidecar.sh start</code>');
      } else {
        setStatus('Approval failed (HTTP ' + esc(res.status) +
          '). You can also approve in Settings \u2192 Extensions.');
      }
    }).catch(function () {
      setStatus('Approval request failed. Approve in Settings \u2192 Extensions.');
    });
  }

  function refreshList() {
    if (!sidecarToken) return;
    fetchJson(API + '/list', { method: 'GET' }, true).then(function (res) {
      if (res.status === 200 && res.body && Array.isArray(res.body.backups)) {
        renderList(res.body.backups);
      }
    }).catch(function () {});
  }

  function createBackup(kind) {
    if (downloading) { toast('A download is already in progress'); return; }
    const quick = kind === 'quick';
    setButtons(false);
    setStatus('Starting ' + kind + ' backup&hellip;');
    fetchJson(API + '/backup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ quick: quick }),
    }, true).then(function (res) {
      if (res.status === 202) {
        setStatus('Backup running&hellip; (this can take a while)');
        startPolling();
      } else if (res.status === 409) {
        setButtons(true);
        setStatus('A backup is already running.');
      } else {
        setButtons(true);
        setStatus('Could not start backup (HTTP ' + esc(res.status) + ').');
      }
    }).catch(function () {
      setButtons(true);
      setStatus('Backup request failed.');
    });
  }

  function startPolling() {
    stopPolling();
    pollCount = 0;
    pollTimer = setInterval(pollStatus, POLL_MS);
  }

  function stopPolling() {
    if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
  }

  function pollStatus() {
    pollCount++;
    if (pollCount > POLL_LIMIT) {
      stopPolling();
      setButtons(true);
      setStatus('Backup is taking too long — check the server logs.');
      return;
    }
    fetchJson(API + '/status', { method: 'GET' }, true).then(function (res) {
      const st = (res.body && res.body.state) || 'unknown';
      if (st === 'running') {
        setStatus('Backup running&hellip; (this can take a while)');
        return;
      }
      stopPolling();
      if (st === 'done') {
        setStatus('Backup complete: <b>' + esc(res.body.file) + '</b> (' +
          esc(humanSize(res.body.size)) + ')');
        toast('Backup complete');
        refreshList();
      } else if (st === 'error') {
        setStatus('Backup failed: ' + esc(res.body.error || 'unknown error'));
      } else {
        setStatus('Backup finished (state: ' + esc(st) + ').');
        refreshList();
      }
      setButtons(true);
    }).catch(function () {
      // transient proxy failure — keep polling until the limit
    });
  }

  // ── chunked download through the proxy ───────────────────────────────────
  function assembleDownload(file, size) {
    if (downloading) return;
    if (!sidecarToken) { refreshHealth().then(function () { if (sidecarToken) assembleDownload(file, size); }); return; }
    if (!size || size <= 0) {
      setStatus('Unknown file size — refresh the list and try again.');
      return;
    }
    if (size > MAX_ASSEMBLE) {
      setStatus('Too large for browser download (' + esc(humanSize(size)) +
        '). Fetch it on the server from <code>~/.hermes/backups/' + esc(file) + '</code>.');
      return;
    }
    downloading = true;
    setButtons(false);
    const totalChunks = Math.ceil(size / CHUNK);
    const parts = [];
    let fetched = 0;

    function fail(msg) {
      downloading = false;
      setButtons(true);
      setStatus('Download failed: ' + msg);
    }

    function step(offset) {
      if (offset >= size) {
        let blob;
        try {
          blob = new Blob(parts, { type: 'application/zip' });
        } catch (e) {
          fail('could not build the archive in memory');
          return;
        }
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = file;
        document.body.appendChild(a);
        a.click();
        a.remove();
        setTimeout(function () { URL.revokeObjectURL(a.href); }, 15000);
        downloading = false;
        setButtons(true);
        setStatus('Downloaded <b>' + esc(file) + '</b> (' + esc(humanSize(size)) + ').');
        toast('Backup downloaded');
        return;
      }
      const len = Math.min(CHUNK, size - offset);
      const url = API + '/chunk/' + encodeURIComponent(sidecarToken) + '/' +
        encodeURIComponent(file) + '/' + offset + '/' + len;
      fetch(url, {
        credentials: 'same-origin',
        headers: proxyHeaders(false),
      }).then(function (r) {
        if (!r.ok) { fail('chunk fetch failed (HTTP ' + r.status + ')'); return; }
        return r.arrayBuffer().then(function (buf) {
          if (buf.byteLength !== len) { fail('chunk size mismatch'); return; }
          parts.push(buf);
          fetched += len;
          const pct = Math.round((fetched / size) * 100);
          setStatus('Downloading&hellip; ' + pct + '% (' + humanSize(fetched) +
            ' / ' + humanSize(size) + ', ' +
            Math.ceil((offset + len) / CHUNK) + '/' + totalChunks + ' chunks)');
          step(offset + len);
        });
      }).catch(function () { fail('network error during chunk fetch'); });
    }

    setStatus('Downloading&hellip; 0% (0 / ' + totalChunks + ' chunks)');
    step(0);
  }

  // ── boot ─────────────────────────────────────────────────────────────────
  function boot() {
    ensurePanel();
    refreshHealth();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot, { once: true });
  } else {
    boot();
  }
})();
