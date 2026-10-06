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
