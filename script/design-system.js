(() => {
  'use strict';

  if (window.SuperOpticUI?.initialized) return;

  const focusableSelector = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
  ].join(',');
  const state = {
    activeOverlay: null,
    activeOpener: null,
    dirtyForms: new Set(),
    confirmResolver: null,
  };

  const focusable = root => Array.from(root.querySelectorAll(focusableSelector))
    .filter(element => !element.hidden && element.getAttribute('aria-hidden') !== 'true');

  const ensureToastRegion = () => {
    let region = document.querySelector('[data-ui-toast-region]');
    if (region) return region;
    region = document.createElement('section');
    region.className = 'ui-toast-region';
    region.dataset.uiToastRegion = '';
    region.setAttribute('role', 'region');
    region.setAttribute('aria-label', 'Bildirisler');
    region.setAttribute('aria-live', 'polite');
    document.body.appendChild(region);
    return region;
  };

  const toast = ({ tone = 'info', title = '', message = '', duration = 5000 } = {}) => {
    const allowed = ['info', 'success', 'warning', 'error'];
    tone = allowed.includes(tone) ? tone : 'info';
    const item = document.createElement('article');
    item.className = `ui-toast ui-toast-${tone}`;
    item.setAttribute('role', tone === 'error' ? 'alert' : 'status');

    const copy = document.createElement('div');
    if (title) {
      const heading = document.createElement('strong');
      heading.textContent = title;
      copy.appendChild(heading);
    }
    const body = document.createElement('p');
    body.textContent = message;
    copy.appendChild(body);

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'ui-toast-close';
    close.setAttribute('aria-label', 'Bildirisi bagla');
    close.textContent = 'X';
    close.addEventListener('click', () => item.remove());

    item.append(copy, close);
    ensureToastRegion().appendChild(item);
    if (duration > 0) window.setTimeout(() => item.remove(), duration);
    return item;
  };

  const ensureConfirmDialog = () => {
    let overlay = document.querySelector('[data-ui-confirm-dialog]');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.className = 'ui-overlay';
    overlay.dataset.uiConfirmDialog = '';
    overlay.hidden = true;
    overlay.innerHTML = [
      '<section class="ui-dialog" role="alertdialog" aria-modal="true" aria-labelledby="ui-confirm-title" aria-describedby="ui-confirm-message">',
      '<header class="ui-dialog-header"><div><h2 id="ui-confirm-title">Emeliyyati tesdiq edin</h2><p id="ui-confirm-message"></p></div></header>',
      '<footer class="ui-dialog-footer">',
      '<button class="ui-button ui-button-secondary" type="button" data-ui-confirm-cancel>Legv et</button>',
      '<button class="ui-button ui-button-danger" type="button" data-ui-confirm-approve>Tesdiq et</button>',
      '</footer></section>',
    ].join('');
    document.body.appendChild(overlay);
    overlay.querySelector('[data-ui-confirm-cancel]').addEventListener('click', () => closeConfirm(false));
    overlay.querySelector('[data-ui-confirm-approve]').addEventListener('click', () => closeConfirm(true));
    overlay.addEventListener('click', event => {
      if (event.target === overlay) closeConfirm(false);
    });
    return overlay;
  };

  const closeOverlay = (overlay, restoreFocus = true) => {
    if (!overlay) return;
    overlay.hidden = true;
    overlay.classList.remove('is-open');
    document.body.classList.remove('ui-overlay-open');
    if (state.activeOverlay === overlay) state.activeOverlay = null;
    if (restoreFocus && state.activeOpener instanceof HTMLElement && state.activeOpener.isConnected) {
      state.activeOpener.focus({ preventScroll: true });
    }
    state.activeOpener = null;
  };

  const closeConfirm = approved => {
    const overlay = document.querySelector('[data-ui-confirm-dialog]');
    closeOverlay(overlay);
    const resolver = state.confirmResolver;
    state.confirmResolver = null;
    if (resolver) resolver(Boolean(approved));
  };

  const confirmAction = ({ title = 'Emeliyyati tesdiq edin', message = 'Davam etmek istediyinize eminsiniz?' } = {}) => {
    const overlay = ensureConfirmDialog();
    if (state.confirmResolver) state.confirmResolver(false);
    overlay.querySelector('#ui-confirm-title').textContent = title;
    overlay.querySelector('#ui-confirm-message').textContent = message;
    state.activeOverlay = overlay;
    state.activeOpener = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    overlay.hidden = false;
    overlay.classList.add('is-open');
    document.body.classList.add('ui-overlay-open');
    window.requestAnimationFrame(() => overlay.querySelector('[data-ui-confirm-cancel]')?.focus());
    return new Promise(resolve => {
      state.confirmResolver = resolve;
    });
  };

  const resolveOverlay = name => document.getElementById(name)
    || document.querySelector(`[data-ui-modal="${CSS.escape(name)}"], [data-ui-drawer="${CSS.escape(name)}"]`);

  const openOverlay = (overlay, opener = null) => {
    if (!(overlay instanceof HTMLElement)) return false;
    if (state.activeOverlay && state.activeOverlay !== overlay) closeOverlay(state.activeOverlay, false);
    state.activeOverlay = overlay;
    state.activeOpener = opener instanceof HTMLElement ? opener : document.activeElement;
    overlay.hidden = false;
    overlay.classList.add('is-open');
    document.body.classList.add('ui-overlay-open');
    window.requestAnimationFrame(() => {
      const target = overlay.querySelector('[autofocus]') || focusable(overlay)[0] || overlay;
      if (!overlay.hasAttribute('tabindex')) overlay.setAttribute('tabindex', '-1');
      target.focus({ preventScroll: true });
    });
    return true;
  };

  const formSnapshot = form => {
    const values = [];
    new FormData(form).forEach((value, key) => {
      values.push([key, value instanceof File ? `${value.name}:${value.size}:${value.lastModified}` : String(value)]);
    });
    return JSON.stringify(values.sort((a, b) => `${a[0]}:${a[1]}`.localeCompare(`${b[0]}:${b[1]}`)));
  };

  const markFormClean = form => {
    form.dataset.uiInitialValue = formSnapshot(form);
    form.dataset.uiDirty = 'false';
    state.dirtyForms.delete(form);
  };

  const syncDirtyForm = form => {
    const dirty = formSnapshot(form) !== (form.dataset.uiInitialValue || '');
    form.dataset.uiDirty = dirty ? 'true' : 'false';
    if (dirty) state.dirtyForms.add(form);
    else state.dirtyForms.delete(form);
  };

  const setupDirtyForm = form => {
    if (!(form instanceof HTMLFormElement) || form.dataset.uiDirtyReady === 'true') return;
    form.dataset.uiDirtyReady = 'true';
    markFormClean(form);
    form.addEventListener('input', () => syncDirtyForm(form));
    form.addEventListener('change', () => syncDirtyForm(form));
    form.addEventListener('reset', () => window.setTimeout(() => markFormClean(form), 0));
    form.addEventListener('submit', event => {
      window.setTimeout(() => {
        if (!event.defaultPrevented) markFormClean(form);
      }, 0);
    });
  };

  const dirtyFormSelector = [
    '[data-ui-dirty-form]',
    '[data-design-system="v2"] .admin-users-modal form',
    '[data-design-system="v2"] .billing-create-panel form',
    '[data-design-system="v2"] .finance-dialog form',
    '[data-design-system="v2"] .hr-dialog form',
  ].join(',');

  const initializeDirtyForms = root => {
    if (root instanceof Element && root.matches(dirtyFormSelector)) setupDirtyForm(root);
    root.querySelectorAll(dirtyFormSelector).forEach(setupDirtyForm);
  };

  const setLoading = (element, loading, label = 'Yuklenir') => {
    if (!(element instanceof HTMLElement)) return;
    element.toggleAttribute('aria-busy', Boolean(loading));
    element.classList.toggle('is-loading', Boolean(loading));
    if (element instanceof HTMLButtonElement) element.disabled = Boolean(loading);
    if (loading && !element.dataset.uiOriginalLabel) {
      element.dataset.uiOriginalLabel = element.textContent || '';
      element.textContent = label;
    } else if (!loading && element.dataset.uiOriginalLabel) {
      element.textContent = element.dataset.uiOriginalLabel;
      delete element.dataset.uiOriginalLabel;
    }
  };

  const handleDocumentClick = async event => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    const opener = target.closest('[data-ui-open]');
    if (opener) {
      const overlay = resolveOverlay(opener.dataset.uiOpen || '');
      if (overlay) {
        event.preventDefault();
        openOverlay(overlay, opener);
      }
      return;
    }

    const closer = target.closest('[data-ui-close]');
    if (closer) {
      event.preventDefault();
      closeOverlay(closer.closest('.ui-overlay, [data-ui-modal], [data-ui-drawer]') || state.activeOverlay);
      return;
    }

    const confirmedLink = target.closest('a[data-ui-confirm]');
    if (confirmedLink instanceof HTMLAnchorElement) {
      event.preventDefault();
      const approved = await confirmAction({
        title: confirmedLink.dataset.uiConfirmTitle,
        message: confirmedLink.dataset.uiConfirm,
      });
      if (approved) window.location.assign(confirmedLink.href);
      return;
    }

    const link = target.closest('a[href]');
    if (!(link instanceof HTMLAnchorElement)
      || link.hasAttribute('download')
      || link.target === '_blank'
      || link.getAttribute('href')?.startsWith('#')
      || state.dirtyForms.size === 0) {
      return;
    }
    event.preventDefault();
    const approved = await confirmAction({
      title: 'Yazilmamis deyisiklik var',
      message: 'Sehifeden cixsaniz yazilmamis deyisiklikler itirilecek.',
    });
    if (approved) {
      state.dirtyForms.clear();
      window.location.assign(link.href);
    }
  };

  const handleSubmit = async event => {
    const form = event.target;
    if (!(form instanceof HTMLFormElement) || !form.matches('[data-ui-confirm]')) return;
    if (form.dataset.uiConfirmApproved === 'true') {
      delete form.dataset.uiConfirmApproved;
      return;
    }
    event.preventDefault();
    const approved = await confirmAction({
      title: form.dataset.uiConfirmTitle,
      message: form.dataset.uiConfirm,
    });
    if (!approved) return;
    form.dataset.uiConfirmApproved = 'true';
    if (typeof form.requestSubmit === 'function') form.requestSubmit(event.submitter || undefined);
    else form.submit();
  };

  const handleKeydown = event => {
    const overlay = state.activeOverlay;
    if (!overlay || overlay.hidden) return;
    if (event.key === 'Escape') {
      event.preventDefault();
      if (overlay.matches('[data-ui-confirm-dialog]')) closeConfirm(false);
      else closeOverlay(overlay);
      return;
    }
    if (event.key !== 'Tab') return;
    const controls = focusable(overlay);
    if (controls.length === 0) {
      event.preventDefault();
      overlay.focus();
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
    }
  };

  const init = () => {
    ensureToastRegion();
    initializeDirtyForms(document);
    document.querySelectorAll('[data-ui-toast]').forEach(element => {
      toast({
        tone: element.dataset.uiToast || 'info',
        title: element.dataset.uiToastTitle || '',
        message: element.textContent?.trim() || '',
      });
      element.hidden = true;
    });
    document.addEventListener('click', handleDocumentClick);
    document.addEventListener('submit', handleSubmit);
    document.addEventListener('keydown', handleKeydown);
    window.addEventListener('beforeunload', event => {
      if (state.dirtyForms.size === 0) return;
      event.preventDefault();
      event.returnValue = '';
    });
    const observer = new MutationObserver(records => {
      records.forEach(record => {
        record.addedNodes.forEach(node => {
          if (node instanceof Element) initializeDirtyForms(node);
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  };

  window.SuperOpticUI = {
    initialized: true,
    init,
    toast,
    confirm: confirmAction,
    openOverlay,
    closeOverlay,
    markFormClean,
    setLoading,
  };

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, { once: true });
  else init();
})();
