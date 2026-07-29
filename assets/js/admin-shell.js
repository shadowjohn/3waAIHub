(() => {
  'use strict';

  const nav = document.getElementById('mainnav');
  const navToggle = document.getElementById('nav-toggle');
  const navClose = document.getElementById('nav-close');
  const desktop = window.matchMedia('(min-width: 1400px)');
  const inertAreas = document.querySelectorAll('[data-drawer-inert]');
  let scrim = null;

  const setDrawerInert = (inert) => {
    inertAreas.forEach((area) => {
      area.inert = inert;
    });
  };

  const drawerOpen = () => nav?.classList.contains('is-open') === true;

  const drawerControls = () => {
    if (!nav) return [];
    return Array.from(nav.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'))
      .filter((control) => control.getClientRects().length > 0);
  };

  const closeDrawer = (restoreFocus = true) => {
    if (!nav || !navToggle) return;
    const wasOpen = drawerOpen();
    nav.classList.remove('is-open');
    document.body.classList.remove('nav-open');
    navToggle.setAttribute('aria-expanded', 'false');
    navToggle.setAttribute('aria-label', '開啟主選單');
    setDrawerInert(false);
    nav.querySelectorAll('[aria-expanded="true"]').forEach((control) => {
      control.setAttribute('aria-expanded', 'false');
      const target = document.getElementById(control.getAttribute('aria-controls') || '');
      if (target) target.hidden = true;
    });
    if (scrim) {
      scrim.remove();
      scrim = null;
    }
    if (restoreFocus && wasOpen) navToggle.focus();
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
      setDrawerInert(true);
      navToggle.setAttribute('aria-expanded', 'true');
      navToggle.setAttribute('aria-label', '關閉主選單');
      scrim = document.createElement('div');
      scrim.className = 'navscrim';
      scrim.addEventListener('click', () => closeDrawer());
      (nav.closest('.appbar') || document.body).appendChild(scrim);
      (navClose || drawerControls()[0])?.focus();
    });

    nav.addEventListener('click', (event) => {
      if (!desktop.matches && event.target instanceof Element && event.target.closest('a')) {
        closeDrawer();
      }
    });
  }
  if (navClose) {
    navClose.addEventListener('click', () => closeDrawer());
  }

  const syncBreakpoint = () => {
    if (desktop.matches) {
      closeDrawer(false);
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
      if (drawerOpen()) {
        closeDrawer();
      } else {
        closeExpanded(null);
      }
      return;
    }
    if (!drawerOpen() || event.key !== 'Tab') {
      return;
    }

    const controls = drawerControls();
    if (controls.length === 0) {
      event.preventDefault();
      return;
    }
    const first = controls[0];
    const last = controls[controls.length - 1];
    if (event.shiftKey && document.activeElement === first) {
      event.preventDefault();
      last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
      event.preventDefault();
      first.focus();
    } else if (!nav?.contains(document.activeElement)) {
      event.preventDefault();
      first.focus();
    }
  });
  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Element) || !event.target.closest('.appbar')) closeExpanded(null);
  });
})();
