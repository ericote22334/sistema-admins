(function () {
  var storageKey = 'grupo-lux-theme';
  var root = document.documentElement;
  var theme = localStorage.getItem(storageKey) === 'dark' ? 'dark' : 'light';
  root.setAttribute('data-theme', theme);

  function updateButton(button) {
    var dark = root.getAttribute('data-theme') === 'dark';
    button.setAttribute('aria-pressed', dark ? 'true' : 'false');
    button.setAttribute('aria-label', dark ? 'Cambiar a modo claro' : 'Cambiar a modo oscuro');
    button.querySelector('[data-theme-icon]').textContent = dark ? '☀️' : '🌙';
    button.querySelector('[data-theme-label]').textContent = dark ? 'Modo claro' : 'Modo oscuro';
  }

  function initializeToggle() {
    document.querySelectorAll('[data-theme-toggle]').forEach(function (button) {
      updateButton(button);
      button.addEventListener('click', function () {
        theme = root.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        root.setAttribute('data-theme', theme);
        localStorage.setItem(storageKey, theme);
        document.querySelectorAll('[data-theme-toggle]').forEach(updateButton);
      });
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initializeToggle);
  else initializeToggle();
})();
