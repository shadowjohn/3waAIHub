(() => {
  'use strict';

  const nav = document.getElementById('mainnav');
  const navToggle = document.getElementById('nav-toggle');
  const desktop = window.matchMedia('(min-width: 1200px)');
  let scrim = null;

  const closeDrawer = (focusToggle = false) => {
    if (!nav || !navToggle) return;
    nav.classList.remove('is-open');
    document.body.classList.remove('nav-open');
    navToggle.setAttribute('aria-expanded', 'false');
    navToggle.setAttribute('aria-label', '開啟主選單');
    if (scrim) {
      scrim.remove();
      scrim = null;
    }
    if (focusToggle) navToggle.focus();
  };

  const closeExpanded = (except) => {
    document.querySelectorAll('[aria-expanded="true"]').forEach((control) => {
      if (control === navToggle && except && nav?.contains(except)) return;
      if (control !== except) {
        control.setAttribute('aria-expanded', 'false');
        const target = document.getElementById(control.getAttribute('aria-controls') || '');
        if (control === navToggle) {
          closeDrawer();
        } else if (target) {
          target.hidden = true;
        }
      }
    });
  };

  document.querySelectorAll('[aria-controls]').forEach((control) => {
    if (control === navToggle) return;
    const target = document.getElementById(control.getAttribute('aria-controls') || '');
    if (!target) return;
    control.addEventListener('click', () => {
      const opening = control.getAttribute('aria-expanded') !== 'true';
      closeExpanded(control);
      control.setAttribute('aria-expanded', opening ? 'true' : 'false');
      target.hidden = !opening;
    });
  });

  if (nav && navToggle) {
    navToggle.addEventListener('click', (event) => {
      event.stopPropagation();
      if (nav.classList.contains('is-open')) {
        closeDrawer(true);
        return;
      }

      closeExpanded(navToggle);
      nav.classList.add('is-open');
      document.body.classList.add('nav-open');
      navToggle.setAttribute('aria-expanded', 'true');
      navToggle.setAttribute('aria-label', '關閉主選單');
      scrim = document.createElement('div');
      scrim.className = 'navscrim';
      scrim.addEventListener('click', () => closeDrawer());
      (nav.closest('.appbar') || document.body).appendChild(scrim);
      nav.querySelector('a, button')?.focus();
    });

    nav.addEventListener('click', (event) => {
      if (!desktop.matches && event.target instanceof Element && event.target.closest('a')) {
        closeDrawer();
      }
    });
  }

  const syncBreakpoint = () => {
    if (desktop.matches) {
      closeDrawer();
      closeExpanded(null);
    }
  };
  if (typeof desktop.addEventListener === 'function') {
    desktop.addEventListener('change', syncBreakpoint);
  } else {
    desktop.addListener(syncBreakpoint);
  }

  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape') {
      const drawerWasOpen = nav?.classList.contains('is-open') === true;
      closeExpanded(null);
      if (drawerWasOpen) closeDrawer(true);
    }
  });
  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element) || !event.target.closest('.appbar')) closeExpanded(null);
  });
})();
