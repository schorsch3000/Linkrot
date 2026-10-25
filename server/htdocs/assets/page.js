(function () {
  function legacyCopy(el) {
    var range = document.createRange();
    range.selectNodeContents(el);
    var selection = window.getSelection();
    selection.removeAllRanges();
    selection.addRange(range);
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) {}
    selection.removeAllRanges();
    return ok;
  }
  function copy(el) {
    if (navigator.clipboard && window.isSecureContext) {
      return navigator.clipboard.writeText(el.textContent).then(function () { return true; }, function () { return legacyCopy(el); });
    }
    return Promise.resolve(legacyCopy(el));
  }
  document.querySelectorAll('button[data-qr]').forEach(function (button) {
    var dialog = document.getElementById(button.getAttribute('data-qr'));
    if (!dialog || !dialog.showModal) { return; }
    button.hidden = false;
    button.addEventListener('click', function () { dialog.showModal(); });
    dialog.addEventListener('click', function (event) { if (event.target === dialog) { dialog.close(); } });
  });
  document.querySelectorAll('button[data-copy]').forEach(function (button) {
    button.hidden = false;
    button.addEventListener('click', function () {
      copy(document.getElementById(button.getAttribute('data-copy'))).then(function (ok) {
        button.classList.add(ok ? 'done' : 'failed');
        button.setAttribute('aria-label', ok ? 'Copied' : 'Copy failed');
        setTimeout(function () {
          button.classList.remove('done', 'failed');
          button.setAttribute('aria-label', 'Copy');
        }, 1500);
      });
    });
  });
})();
