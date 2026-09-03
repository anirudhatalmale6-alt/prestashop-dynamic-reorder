/**
 * Dynamic Reorder - front office.
 *
 * Binds the shop's existing homepage banner/button, asks the module for the
 * logged-in customer's LAST order, shows a confirmation pop-up and keeps the
 * customer where they are. No checkout redirect, ever.
 *
 * Deliberately dependency-free (no jQuery, no Bootstrap) so it behaves the same
 * on the Classic theme and on custom themes.
 *
 * @author Anirudha Talmale
 */
(function () {
  'use strict';

  var cfg = window.dynamicReorderConfig;
  if (!cfg || !cfg.endpoint) {
    return;
  }

  var busy = false;

  /* ---------------------------------------------------------------- */
  /* Pop-up                                                            */
  /* ---------------------------------------------------------------- */

  function buildModal(payload) {
    var overlay = document.createElement('div');
    overlay.className = 'dr-overlay';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');

    var box = document.createElement('div');
    box.className = 'dr-modal dr-modal--' + (payload.status || 'info');

    var title = document.createElement('h3');
    title.className = 'dr-modal__title';
    title.textContent = payload.title || cfg.title || '';

    var body = document.createElement('p');
    body.className = 'dr-modal__body';
    body.textContent = payload.message || '';

    var actions = document.createElement('div');
    actions.className = 'dr-modal__actions';

    box.appendChild(title);
    box.appendChild(body);

    if (payload.cart && payload.cart.nb) {
      var summary = document.createElement('p');
      summary.className = 'dr-modal__summary';
      summary.textContent = cfg.labels.cartNow
        .replace('%nb%', payload.cart.nb)
        .replace('%total%', payload.cart.total);
      box.appendChild(summary);
    }

    var urls = payload.urls || {};

    if (payload.status === 'not_logged_in') {
      actions.appendChild(linkButton(cfg.labels.login, urls.login, 'dr-btn dr-btn--primary'));
      if (urls.register) {
        actions.appendChild(linkButton(cfg.labels.register, urls.register, 'dr-btn dr-btn--ghost'));
      }
    } else if (payload.status === 'success' || payload.status === 'partial') {
      actions.appendChild(closeButton(cfg.labels.close, 'dr-btn dr-btn--primary'));
      if (urls.cart) {
        actions.appendChild(linkButton(cfg.labels.cart, urls.cart, 'dr-btn dr-btn--ghost'));
      }
    } else {
      actions.appendChild(closeButton(cfg.labels.close, 'dr-btn dr-btn--primary'));
    }

    box.appendChild(actions);
    overlay.appendChild(box);

    // The close button and the backdrop both dismiss; on a successful load we
    // then reload so the theme's cart block is guaranteed to be in sync.
    overlay.addEventListener('click', function (e) {
      if (e.target === overlay) {
        dismiss(overlay, payload);
      }
    });

    document.addEventListener('keydown', function onEsc(e) {
      if (e.key === 'Escape' || e.keyCode === 27) {
        document.removeEventListener('keydown', onEsc);
        dismiss(overlay, payload);
      }
    });

    return overlay;
  }

  function linkButton(label, href, className) {
    var a = document.createElement('a');
    a.className = className;
    a.href = href;
    a.textContent = label;
    return a;
  }

  function closeButton(label, className) {
    var b = document.createElement('button');
    b.type = 'button';
    b.className = className;
    b.textContent = label;
    b.addEventListener('click', function () {
      dismiss(b.closest ? b.closest('.dr-overlay') : null, currentPayload);
    });
    return b;
  }

  var currentPayload = null;

  function showModal(payload) {
    currentPayload = payload;
    closeModal();
    var overlay = buildModal(payload);
    document.body.appendChild(overlay);
    // next frame, so the CSS transition actually runs
    requestAnimationFrame(function () {
      overlay.classList.add('dr-overlay--visible');
    });
  }

  function closeModal() {
    var existing = document.querySelector('.dr-overlay');
    if (existing && existing.parentNode) {
      existing.parentNode.removeChild(existing);
    }
  }

  function dismiss(overlay, payload) {
    // The Esc handler outlives its modal; without this guard a stray Esc after
    // the pop-up was already closed would fire another reload.
    if (!document.querySelector('.dr-overlay')) {
      return;
    }
    closeModal();
    var loaded = payload && (payload.status === 'success' || payload.status === 'partial');
    if (loaded && cfg.after === 'reload') {
      // Stays on the homepage - this is a refresh, not a redirect to checkout.
      window.location.reload();
    }
  }

  /* ---------------------------------------------------------------- */
  /* Cart block refresh                                                */
  /* ---------------------------------------------------------------- */

  function refreshCartBlock(payload) {
    if (typeof window.prestashop === 'undefined' || !window.prestashop.emit) {
      return;
    }
    try {
      window.prestashop.emit('updateCart', {
        reason: { linkAction: 'refresh', cart: payload.cart || null },
        resp: { hasError: false, errors: [] }
      });
    } catch (e) {
      /* a theme that does not listen is fine - the reload path covers it */
    }
  }

  /* ---------------------------------------------------------------- */
  /* Request                                                           */
  /* ---------------------------------------------------------------- */

  function setBusy(el, on) {
    busy = on;
    if (!el) {
      return;
    }
    if (on) {
      el.classList.add('dr-busy');
      el.setAttribute('aria-busy', 'true');
    } else {
      el.classList.remove('dr-busy');
      el.removeAttribute('aria-busy');
    }
  }

  function reorder(trigger) {
    if (busy) {
      return;
    }
    setBusy(trigger, true);

    fetch(cfg.endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
      },
      body: 'ajax=1'
    })
      .then(function (res) {
        return res.json().catch(function () {
          throw new Error('bad json');
        });
      })
      .then(function (payload) {
        setBusy(trigger, false);
        if (payload.status === 'success' || payload.status === 'partial') {
          refreshCartBlock(payload);
        }
        showModal(payload);
      })
      .catch(function () {
        setBusy(trigger, false);
        showModal({ status: 'error', title: cfg.title, message: cfg.labels.network });
      });
  }

  /* ---------------------------------------------------------------- */
  /* Wiring                                                            */
  /* ---------------------------------------------------------------- */

  function matches(el, selector) {
    var fn = el.matches || el.msMatchesSelector || el.webkitMatchesSelector;
    if (!fn) {
      return false;
    }
    try {
      return fn.call(el, selector);
    } catch (e) {
      return false; // an invalid selector typed in the back office
    }
  }

  // Delegated, so buttons injected later by a slider or another module still work.
  document.addEventListener('click', function (e) {
    if (!cfg.selector) {
      return;
    }
    var node = e.target;
    while (node && node !== document) {
      if (node.nodeType === 1 && matches(node, cfg.selector)) {
        e.preventDefault();
        e.stopPropagation();
        reorder(node);
        return;
      }
      node = node.parentNode;
    }
  }, true);

  function stripFlag(flag) {
    if (!window.history || !window.history.replaceState) {
      return;
    }
    var url = new URL(window.location.href);
    url.searchParams.delete(flag);
    window.history.replaceState({}, document.title, url.toString());
  }

  function boot() {
    // Came back from the no-JS redirect: show the stored result.
    if (cfg.flash) {
      showModal(cfg.flash);
      stripFlag(cfg.doneFlag);
      return;
    }

    // Came back from logging in: finish what they clicked before being asked
    // to log in, without making them click the banner a second time.
    var params = new URLSearchParams(window.location.search);
    if (params.get(cfg.resumeFlag)) {
      stripFlag(cfg.resumeFlag);
      var trigger = null;
      try {
        trigger = cfg.selector ? document.querySelector(cfg.selector) : null;
      } catch (e) {
        trigger = null; // an invalid selector must not stop the resume
      }
      reorder(trigger);
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
