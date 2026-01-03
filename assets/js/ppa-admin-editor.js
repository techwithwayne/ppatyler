/* global window, document, jQuery */
/**
 * PostPress AI — Admin Editor Helpers
 * Path: assets/js/ppa-admin-editor.js
 *
 * ========= CHANGE LOG =========
 * 2026-01-03.1: FIX: Add missing file referenced by inc/admin/enqueue.php to eliminate 404.
 *              Intentionally minimal + inert (no automatic behavior changes). // CHANGED:
 */

(function (window, document, $) {
  'use strict';

  // Namespace safety (no behavior change; just prevents "undefined" hazards).
  window.PPAAdmin = window.PPAAdmin || {};
  window.PPAAdmin.editor = window.PPAAdmin.editor || {};

  /**
   * Optional helper: safely run a function on DOM ready.
   * Not auto-used — available for future modules. // CHANGED:
   */
  window.PPAAdmin.editor.ready = function (fn) {
    if (typeof fn !== 'function') return;
    if (typeof $ === 'function' && $.fn && $.fn.ready) {
      $(fn);
      return;
    }
    // Fallback (no jQuery)
    if (document.readyState === 'complete' || document.readyState === 'interactive') {
      fn();
    } else {
      document.addEventListener('DOMContentLoaded', fn);
    }
  };

  /**
   * Optional helper: auto-grow a textarea to fit content.
   * Not auto-bound — calling code must opt in. // CHANGED:
   */
  window.PPAAdmin.editor.autoGrowTextarea = function (el, maxPx) {
    try {
      if (!el || !el.style) return;
      var maxH = (typeof maxPx === 'number' && maxPx > 0) ? maxPx : 0;

      el.style.height = 'auto';
      var h = el.scrollHeight || 0;
      if (maxH && h > maxH) h = maxH;
      if (h > 0) el.style.height = h + 'px';
    } catch (e) {
      // silent — never break admin UX
    }
  };

})(window, document, (typeof jQuery !== 'undefined' ? jQuery : null));
