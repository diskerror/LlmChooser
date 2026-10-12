// Presentation only (GUI-DESIGN.md §4): help toggles, advanced mode,
// disable confirmation. No data ever goes to the server from here.

// Per-field help toggle: each ⓘ button toggles its row's .help div.
document.querySelectorAll('.help-toggle').forEach(btn => {
  btn.addEventListener('click', () => {
    const help = btn.closest('.field-row')?.querySelector('.help');
    if (help) help.hidden = !help.hidden;
  });
});

// "full help" switch: opens/closes every help div; persists per browser.
const fullHelp = document.getElementById('full-help-toggle');
if (fullHelp) {
  const KEY = 'llmch...Help';
  const apply = (on) => document.querySelectorAll('.help').forEach(h => h.hidden = !on);
  fullHelp.checked = localStorage.getItem(KEY) === '1';
  apply(fullHelp.checked);
  fullHelp.addEventListener('change', () => {
    localStorage.setItem(KEY, fullHelp.checked ? '1' : '0');
    apply(fullHelp.checked);
  });
}

// "advanced mode" switch: shows .advanced rows — but an advanced row set
// away from its default (.non-default) is NEVER hidden, so a setting in
// effect cannot disappear from the form.
const advMode = document.getElementById('advanced-toggle');
if (advMode) {
  const KEY = 'llmch...de';
  const apply = (on) => document.querySelectorAll('.field-row.advanced').forEach(row => {
    row.hidden = !on && !row.classList.contains('non-default');
  });
  advMode.checked = localStorage.getItem(KEY) === '1';
  apply(advMode.checked);
  advMode.addEventListener('change', () => {
    localStorage.setItem(KEY, advMode.checked ? '1' : '0');
    apply(advMode.checked);
  });
}

// Disabling a RUNNING preset asks first; Cancel aborts the submit entirely.
// Without JS there is no dialog and the server's stop-then-disable runs
// unconditionally — the accepted degraded path.
document.querySelectorAll('form.disable-form[data-running="1"]').forEach(form => {
  form.addEventListener('submit', (e) => {
    if (!confirm('Stop it now?')) e.preventDefault();
  });
});

// Dashboard auto-refresh (GUI-DESIGN.md §3): poll a server-rendered HTML
// partial and rewrite the two panel bodies in place. Row markup stays in
// PHP (one renderer for first paint and every refresh); meta-refresh in
// the page head remains the no-JS fallback.
const loadedBody = document.getElementById('loaded-tbody');
const availableBody = document.getElementById('available-tbody');
if (loadedBody && availableBody) {
  setInterval(async () => {
    try {
      const res = await fetch('/api/dashboard-panels');
      if (!res.ok) return;
      const data = await res.json();
      // A form the user is mid-click on must not vanish under them.
      if (!document.activeElement || !document.activeElement.closest('form')) {
        if (data.loaded_html) {
          loadedBody.innerHTML = data.loaded_html;
          bindTestForms(loadedBody);
        }
        if (data.available_html) availableBody.innerHTML = data.available_html;
      }
    } catch (e) { /* network hiccup: leave the stale table; meta-refresh covers it */ }
  }, 3000);
}

// Test button: POST /presets/{id}/test, show the result inline. One real
// one-token generation per click, never automatic. ttft is really
// time-to-first-response-byte (the server notes it too).
function bindTestForms(scope) {
  scope.querySelectorAll('form.test-form:not([data-bound])').forEach(form => {
    form.dataset.bound = '1';
    form.addEventListener('submit', async (e) => {
      e.preventDefault();
      const out = form.parentElement.querySelector('.test-result');
      const btn = form.querySelector('button');
      out.textContent = 'testing…';
      btn.disabled = true;
      try {
        const res = await fetch(form.action, { method: 'POST' });
        const data = await res.json();
        out.textContent = data.ok
          ? `ttft ${data.ttft_ms}ms, total ${data.total_ms}ms`
          : `error: ${data.error}`;
      } catch (e) {
        out.textContent = 'request failed';
      }
      btn.disabled = false;
    });
  });
}
bindTestForms(document);

// Engine picker popup (preset form): a <dialog> listing engine names only.
// Without JS the button still submits whatever engine_id the hidden input
// already has, so a preset with no JS just keeps its previous/default value
// — never a silently blank required field.
document.querySelectorAll('.engine-picker-open').forEach(btn => {
  const dialog = document.getElementById(btn.dataset.target);
  if (!dialog) return;
  btn.addEventListener('click', () => {
    if (typeof dialog.showModal === 'function') dialog.showModal();
  });
  dialog.querySelectorAll('.engine-pick').forEach(pick => {
    pick.addEventListener('click', () => {
      const hidden = document.getElementById('engine_id');
      const label  = btn.querySelector('.engine-picker-label');
      if (hidden) hidden.value = pick.dataset.engineId;
      if (label) label.textContent = pick.textContent;
      dialog.close();
    });
  });
  dialog.querySelectorAll('.engine-picker-close').forEach(close => {
    close.addEventListener('click', () => dialog.close());
  });
});

// Models table (Phase 12): client-side sort of whole tbody blocks, so a
// model's error/drawer rows travel with its main row. Only columns whose
// header is .sortable respond; data-sort holds the numeric sort key.
const modelsTable = document.getElementById('models-table');
if (modelsTable) {
  modelsTable.querySelectorAll('th.sortable').forEach(th => {
    th.addEventListener('click', () => {
      const head = [...modelsTable.querySelectorAll('thead th')];
      const col  = head.indexOf(th);
      const dir  = th.classList.contains('asc') ? -1 : 1;
      modelsTable.querySelectorAll('th.sortable').forEach(h => h.classList.remove('asc', 'desc'));
      th.classList.add(dir === 1 ? 'asc' : 'desc');
      const key = tb => {
        const cell = tb.querySelector('tr.model-row td:nth-child(' + (col + 1) + ')');
        if (!cell) return null;
        return cell.hasAttribute('data-sort') ? parseFloat(cell.getAttribute('data-sort')) : cell.textContent.trim();
      };
      const tbodies = [...modelsTable.querySelectorAll('tbody.model-block')];
      tbodies.sort((a, b) => {
        const ka = key(a), kb = key(b);
        if (typeof ka === 'number' && typeof kb === 'number' && !isNaN(ka) && !isNaN(kb)) return (ka - kb) * dir;
        return String(ka).localeCompare(String(kb), undefined, { numeric: true }) * dir;
      });
      // Detach, then re-append in sorted order (append preserves order).
      tbodies.forEach(tb => tb.remove());
      tbodies.forEach(tb => modelsTable.append(tb));
    });
  });
  // Clicking a model row opens/closes its drawer (§5), except when the
  // click was on a link (Used-by / New preset) or selected text.
  modelsTable.querySelectorAll('tr.model-row').forEach(row => {
    row.style.cursor = 'pointer';
    row.addEventListener('click', e => {
      if (e.target.closest('a') || window.getSelection().toString()) return;
      const drawer = row.parentElement.querySelector('details.drawer');
      if (drawer) drawer.open = !drawer.open;
    });
  });
}
