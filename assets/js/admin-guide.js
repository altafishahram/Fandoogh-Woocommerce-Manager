/* Progressive guide tabs and public web-app address copying, scoped to plugin admin. */
(function () {
  'use strict';
  function init() {
    var root = document.querySelector('.fandoogh-manager-admin-page');
    if (!root) return;
    var guide = root.querySelector('.fandoogh-admin-guide');
    if (guide) {
      var navigation = guide.querySelector('.fandoogh-guide-tabs');
      var tabs = Array.prototype.slice.call(navigation.querySelectorAll('[data-guide-tab]'));
      var panels = tabs.map(function (tab) { return guide.querySelector(tab.getAttribute('href')); });
      if (panels.every(Boolean)) {
        navigation.setAttribute('role', 'tablist');
        tabs.forEach(function (tab, index) {
          tab.setAttribute('role', 'tab');
          tab.setAttribute('aria-controls', panels[index].id);
          panels[index].setAttribute('role', 'tabpanel');
          panels[index].tabIndex = 0;
        });
        function select(index, focus) {
          tabs.forEach(function (tab, current) {
            var active = current === index;
            tab.setAttribute('aria-selected', String(active));
            tab.tabIndex = active ? 0 : -1;
            panels[current].hidden = !active;
          });
          if (focus) tabs[index].focus();
        }
        function followHash() {
          var index = panels.findIndex(function (panel) { return '#' + panel.id === window.location.hash; });
          if (index >= 0) select(index, false);
          var health = root.querySelector('#fandoogh-admin-health');
          if (health && window.location.hash === '#fandoogh-admin-health') health.open = true;
        }
        guide.classList.add('is-enhanced');
        select(0, false);
        followHash();
        window.addEventListener('hashchange', followHash);
        guide.addEventListener('click', function (event) {
          var link = event.target.closest('a');
          if (!link || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button !== 0) return;
          var index = panels.findIndex(function (panel) { return link.getAttribute('href') === '#' + panel.id; });
          if (index < 0) return;
          event.preventDefault();
          select(index, !link.hasAttribute('data-guide-tab'));
          // Replace only the guide hash, preserving WordPress tab/query parameters.
          try { window.history.replaceState(null, '', '#' + panels[index].id); } catch (error) { /* Tabs still work. */ }
        });
        tabs.forEach(function (tab, index) {
          tab.addEventListener('keydown', function (event) {
            var next;
            var rtl = window.getComputedStyle(navigation).direction === 'rtl';
            if (event.key === 'ArrowLeft') next = (index + (rtl ? 1 : tabs.length - 1)) % tabs.length;
            if (event.key === 'ArrowRight') next = (index + (rtl ? tabs.length - 1 : 1)) % tabs.length;
            if (event.key === 'Home') next = 0;
            if (event.key === 'End') next = tabs.length - 1;
            if (next === undefined) return;
            event.preventDefault();
            select(next, true);
            try { window.history.replaceState(null, '', '#' + panels[next].id); } catch (error) { /* Optional URL enhancement. */ }
          });
        });
      }
    }
    var copy = root.querySelector('[data-copy-app-address]');
    var address = root.querySelector('#fandoogh-app-address');
    var status = root.querySelector('[data-copy-status]');
    if (copy && address && status) {
      copy.hidden = false;
      copy.addEventListener('click', async function () {
        copy.disabled = true;
        try {
          if (!navigator.clipboard || !navigator.clipboard.writeText) throw new Error('Clipboard unavailable');
          await navigator.clipboard.writeText(address.value);
          status.textContent = copy.getAttribute('data-copy-success');
        } catch (error) {
          address.focus();
          address.select();
          status.textContent = copy.getAttribute('data-copy-error');
        } finally {
          copy.disabled = false;
        }
      });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
