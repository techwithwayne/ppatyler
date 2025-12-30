/* global window, document */
/**
 * PostPress AI — Composer Store Module (ES5-safe)
 *
 * Purpose:
 * - Provide a reusable "store/save" action wrapper around:
 *   - window.PPAAdminModules.api.apiPost('ppa_store', ...)
 *   - optional payload building via window.PPAAdminModules.payloads
 *
 * IMPORTANT:
 * - NO side effects on load.
 * - Not wired into admin.js yet (one-file rule).
 *
 * WP side:
 * - Uses admin-ajax action `ppa_store` (WP proxy/controller already defines it).
 *
 * Output:
 * - Normalizes the transport + WP envelope (when present) into a stable object.
 *
 * ========= CHANGE LOG =========
 * 2025-12-30: UX: After successful "Save Draft", open the saved draft in a NEW TAB (popup-safe: open blank tab on click, then navigate it on success). // CHANGED:
 * 2025-12-30: UX: Render an obvious "View Draft ↗" link next to the Save Draft button (opens in a NEW TAB). Link updates after each successful save. // CHANGED:
 *              No endpoint/payload/auth changes. No auto-wiring. // CHANGED:
 *
 * 2025-12-22.1: Fix parity helper calling bug: window.PPAAdmin.postStore expects a mode string (draft/publish), not a payload object; pass mode when using the stable admin.js surface. Add safe fallback to window.PPAAdmin.apiPost('ppa_store', payload) when postStore is unavailable. Also auto-detect post_ID from DOM when not provided in opts (parity helper only). // CHANGED:
 *               No endpoint/payload/auth changes. NO auto-wiring. store() contract unchanged. // CHANGED:
 * 2025-12-21.1: Add Composer Draft/Publish helper functions + payload builder (parity targets for admin.js cutover); NO auto-wiring; store() contract unchanged. // CHANGED:
 * 2025-12-20.3: Merge export (no early return); strip ANY *El helper keys from outgoing payload to prevent leaking DOM refs/selectors while payload builders preserve unknown keys. // CHANGED:
 */

(function (window, document) {
  "use strict";

  // ---- Namespace guard -------------------------------------------------------
  window.PPAAdminModules = window.PPAAdminModules || {};

  // CHANGED: Do NOT early-return if object pre-exists; merge into it.
  // Late scripts may pre-create namespace objects; we must still attach functions.
  var MOD_VER = "ppa-admin-composer-store.v2025-12-30.1"; // CHANGED:
  var composerStore = window.PPAAdminModules.composerStore || {}; // CHANGED:

  // ---- Small utils (ES5) -----------------------------------------------------
  function hasOwn(obj, key) {
    return Object.prototype.hasOwnProperty.call(obj, key);
  }

  function isObject(val) {
    return !!val && (typeof val === "object");
  }

  function toStr(val) { // CHANGED:
    return (val === undefined || val === null) ? "" : String(val); // CHANGED:
  } // CHANGED:

  // CHANGED: shallow clone helper (avoid mutating caller input).
  function shallowClone(obj) { // CHANGED:
    var out = {}; // CHANGED:
    if (!obj || typeof obj !== "object") return out; // CHANGED:
    for (var k in obj) { // CHANGED:
      if (Object.prototype.hasOwnProperty.call(obj, k)) { // CHANGED:
        out[k] = obj[k]; // CHANGED:
      } // CHANGED:
    } // CHANGED:
    return out; // CHANGED:
  } // CHANGED:

  // CHANGED: Strip any helper keys that end in "El" (postEl, titleEl, contentEl, etc.)
  // Prevent DOM nodes/selectors from being sent to WP/Django while we preserve unknown keys.
  function stripElKeys(obj) { // CHANGED:
    if (!obj || typeof obj !== "object") return obj; // CHANGED:
    for (var k in obj) { // CHANGED:
      if (Object.prototype.hasOwnProperty.call(obj, k)) { // CHANGED:
        if (k && k.length >= 2 && k.slice(-2) === "El") { // CHANGED:
          try { delete obj[k]; } catch (e) {} // CHANGED:
        } // CHANGED:
      } // CHANGED:
    } // CHANGED:
    return obj; // CHANGED:
  } // CHANGED:

  // ---- WP ajax envelope helpers ---------------------------------------------
  function unwrapWpAjax(body) {
    if (!isObject(body)) {
      return { hasEnvelope: false, success: null, data: body };
    }
    if (hasOwn(body, "success") && hasOwn(body, "data")) {
      return { hasEnvelope: true, success: body.success === true, data: body.data };
    }
    return { hasEnvelope: false, success: null, data: body };
  }

  // ---- Payload building ------------------------------------------------------
  function buildStorePayload(input) {
    var payloads = window.PPAAdminModules.payloads;
    if (payloads && typeof payloads.buildStorePayload === "function") {
      var built = payloads.buildStorePayload(input); // CHANGED:
      stripElKeys(built); // CHANGED:
      return built; // CHANGED:
    }
    input = input || {};
    var payload = shallowClone(input); // CHANGED:
    stripElKeys(payload); // CHANGED:
    return payload; // CHANGED:
  }

  // ---- NEW: Draft URL extraction + UI link helpers -------------------------- // CHANGED:
  function getPath(obj, path) { // CHANGED:
    if (!obj || typeof obj !== "object") return null; // CHANGED:
    var parts = String(path || "").split("."); // CHANGED:
    var cur = obj; // CHANGED:
    for (var i = 0; i < parts.length; i++) { // CHANGED:
      if (!cur || typeof cur !== "object") return null; // CHANGED:
      if (!hasOwn(cur, parts[i])) return null; // CHANGED:
      cur = cur[parts[i]]; // CHANGED:
    } // CHANGED:
    return cur; // CHANGED:
  } // CHANGED:

  function pickFirstUrl(resObj) { // CHANGED:
    // We favor edit_link for drafts (permalink may not be viewable for drafts without preview tokens). // CHANGED:
    var paths = [ // CHANGED:
      "edit_link", "result.edit_link", "result.meta.edit_link", // CHANGED:
      "data.edit_link", "data.result.edit_link", "data.result.meta.edit_link", // CHANGED:
      "preview_url", "result.preview_url", "result.meta.preview_url", // CHANGED:
      "data.preview_url", "data.result.preview_url", "data.result.meta.preview_url", // CHANGED:
      "permalink", "result.permalink", "result.meta.permalink", // CHANGED:
      "data.permalink", "data.result.permalink", "data.result.meta.permalink" // CHANGED:
    ]; // CHANGED:

    for (var i = 0; i < paths.length; i++) { // CHANGED:
      var v = getPath(resObj, paths[i]); // CHANGED:
      if (v && typeof v === "string" && v.trim()) { // CHANGED:
        return v.trim(); // CHANGED:
      } // CHANGED:
    } // CHANGED:
    return ""; // CHANGED:
  } // CHANGED:

  function safeOpenBlankTab() { // CHANGED:
    // Popup-safe strategy: open a blank tab synchronously on click, then navigate it later. // CHANGED:
    try { // CHANGED:
      var w = window.open("about:blank", "_blank"); // CHANGED:
      if (w) { try { w.opener = null; } catch (e0) {} } // CHANGED:
      return w; // CHANGED:
    } catch (e1) { // CHANGED:
      return null; // CHANGED:
    } // CHANGED:
  } // CHANGED:

  function safeOpenUrlNewTab(url) { // CHANGED:
    if (!url) return null; // CHANGED:
    try { // CHANGED:
      return window.open(url, "_blank", "noopener,noreferrer"); // CHANGED:
    } catch (e0) { // CHANGED:
      return null; // CHANGED:
    } // CHANGED:
  } // CHANGED:

  function insertAfter(newNode, referenceNode) { // CHANGED:
    if (!referenceNode || !referenceNode.parentNode) return false; // CHANGED:
    if (referenceNode.nextSibling) { // CHANGED:
      referenceNode.parentNode.insertBefore(newNode, referenceNode.nextSibling); // CHANGED:
    } else { // CHANGED:
      referenceNode.parentNode.appendChild(newNode); // CHANGED:
    } // CHANGED:
    return true; // CHANGED:
  } // CHANGED:

  function ensureViewDraftLink(btnEl) { // CHANGED:
    var existing = document.getElementById("ppa-view-draft-link"); // CHANGED:
    if (existing) return existing; // CHANGED:

    var a = document.createElement("a"); // CHANGED:
    a.id = "ppa-view-draft-link"; // CHANGED:
    a.href = "#"; // CHANGED:
    a.target = "_blank"; // CHANGED:
    a.rel = "noopener noreferrer"; // CHANGED:
    a.textContent = "View Draft ↗"; // CHANGED:

    // Make it obvious without needing CSS edits. // CHANGED:
    a.style.marginLeft = "12px"; // CHANGED:
    a.style.fontWeight = "700"; // CHANGED:
    a.style.textDecoration = "underline"; // CHANGED:
    a.style.fontSize = "14px"; // CHANGED:
    a.style.display = "none"; // CHANGED:
    a.style.verticalAlign = "middle"; // CHANGED:

    // Prefer placing next to the button if available. // CHANGED:
    if (btnEl && btnEl.nodeType === 1) { // CHANGED:
      if (!insertAfter(a, btnEl)) { // CHANGED:
        try { (btnEl.parentNode || document.body).appendChild(a); } catch (e0) {} // CHANGED:
      } // CHANGED:
      return a; // CHANGED:
    } // CHANGED:

    // Fallback: append inside .wrap if present. // CHANGED:
    var wrap = document.querySelector(".wrap") || document.body; // CHANGED:
    try { wrap.appendChild(a); } catch (e1) {} // CHANGED:
    return a; // CHANGED:
  } // CHANGED:

  function updateViewDraftLink(url, btnEl) { // CHANGED:
    var link = ensureViewDraftLink(btnEl); // CHANGED:
    if (!link) return; // CHANGED:
    url = toStr(url).trim(); // CHANGED:
    if (!url) { // CHANGED:
      link.style.display = "none"; // CHANGED:
      return; // CHANGED:
    } // CHANGED:
    link.href = url; // CHANGED:
    link.style.display = "inline-block"; // CHANGED:
  } // CHANGED:

  // ---- Public API ------------------------------------------------------------
  function store(input, options) {
    options = options || {};
    var api = window.PPAAdminModules.api;
    if (!api || typeof api.apiPost !== "function") {
      return window.Promise ? Promise.resolve({ ok:false, status:0, error:"api_module_missing" })
                            : { ok:false, status:0, error:"api_module_missing" };
    }

    var payload = buildStorePayload(input);
    var p = api.apiPost("ppa_store", payload, options.apiOptions || {});
    if (p && typeof p.then === "function") {
      return p.then(function (transport) {
        var wp = unwrapWpAjax(transport.body);
        var overallOk = transport.ok && (!wp.hasEnvelope || wp.success === true);
        var data = wp.hasEnvelope ? wp.data : transport.body;
        var out = { ok: overallOk, status: transport.status, transport: transport, wp: wp, data: data };
        if ((options.storeDebug !== undefined ? !!options.storeDebug : true)) {
          try { window.PPA_LAST_STORE = out; } catch (e) {}
        }
        return out;
      }, function (transportErr) {
        var wpErr = unwrapWpAjax(transportErr && transportErr.body);
        var dataErr = wpErr.hasEnvelope ? wpErr.data : (transportErr ? transportErr.body : null);
        var outErr = { ok:false, status:(transportErr && transportErr.status) ? transportErr.status : 0,
                       transport: transportErr, wp: wpErr, data: dataErr };
        if ((options.storeDebug !== undefined ? !!options.storeDebug : true)) {
          try { window.PPA_LAST_STORE = outErr; } catch (e2) {}
        }
        throw outErr;
      });
    }
    return window.Promise ? Promise.resolve({ ok:false, status:0, error:"no_promise_support" })
                          : { ok:false, status:0, error:"no_promise_support" };
  }

  // ---- Parity helpers (NO auto-wiring) ---------------------------------------
  function $(sel, ctx) { return (ctx || document).querySelector(sel); } // CHANGED:
  function getEditorContent() { // CHANGED:
    try { var ed = window.PPAAdminModules.editor;
      if (ed && typeof ed.getEditorContent === "function") return String(ed.getEditorContent() || "");
    } catch (e0) {}
    var txt = $("#ppa-content"); if (txt && String(txt.value||"").trim()) return String(txt.value||"");
    try { if (window.tinyMCE && tinyMCE.get) { var mce = tinyMCE.get("content");
      if (mce && !mce.isHidden()) return String(mce.getContent() || ""); } } catch (e1) {}
    var raw = $("#content"); if (raw && String(raw.value||"").trim()) return String(raw.value||"");
    return "";
  } // CHANGED:
  function safeInt(n, fallback) { var x = parseInt(n,10); return isNaN(x) ? (fallback||0) : x; } // CHANGED:

  function buildComposerStorePayload(mode, opts) { // CHANGED:
    opts = opts || {};
    var titleEl = $("#ppa-title") || $("#title");
    var excerptEl = $("#ppa-excerpt") || $("#excerpt");
    var slugEl = $("#ppa-slug") || $("#post_name");
    var payload = {
      mode: String(mode || ""),
      post_id: safeInt(opts.post_id || opts.postId || 0, 0),
      title: titleEl ? String(titleEl.value || "") : "",
      excerpt: excerptEl ? String(excerptEl.value || "") : "",
      slug: slugEl ? String(slugEl.value || "") : "",
      content: String(getEditorContent() || ""),
      meta: shallowClone(opts.meta || {})
    };
    if (!payload.post_id) {
      var postIdEl = $("#post_ID");
      if (postIdEl && postIdEl.value) payload.post_id = safeInt(postIdEl.value, 0);
    }
    return buildStorePayload(payload);
  } // CHANGED:

  function renderNotice(type, message) { // CHANGED:
    try { var n = window.PPAAdminModules.notices;
      if (n && typeof n.renderNotice === "function") { n.renderNotice(type, message); return; }
    } catch (e0) {}
    console.info("PPA: notice", { type: type, message: String(message || "") });
  } // CHANGED:

  function withBusy(promiseFactory, label) { // CHANGED:
    try { var n = window.PPAAdminModules.notices;
      if (n && typeof n.withBusy === "function") return n.withBusy(promiseFactory, label);
    } catch (e0) {}
    try { var p = promiseFactory(); return window.Promise ? Promise.resolve(p) : p; }
    catch (e1) { renderNotice("error","There was an error while preparing your request."); throw e1; }
  } // CHANGED:

  function doStoreViaStableSurface(payload, mode) { // CHANGED:
    try {
      if (window.PPAAdmin && typeof window.PPAAdmin.postStore === "function") {
        var m = String(mode || (payload && payload.mode) || "draft");
        return window.PPAAdmin.postStore(m);
      }
    } catch (e0) {}
    try {
      if (window.PPAAdmin && typeof window.PPAAdmin.apiPost === "function") {
        return window.PPAAdmin.apiPost("ppa_store", payload);
      }
    } catch (e1) {}
    return store(payload);
  } // CHANGED:

  function handleStoreClick(ev, btnEl, mode, opts) { // CHANGED:
    opts = opts || {};
    if (ev && ev.preventDefault) ev.preventDefault();
    if (ev && ev.stopImmediatePropagation) ev.stopImmediatePropagation();
    if (ev && ev.stopPropagation) ev.stopPropagation();

    // CHANGED: Always try to resolve the clicked element so we can place the link correctly.
    btnEl = btnEl || (ev && (ev.currentTarget || ev.target)) || null; // CHANGED:

    console.info("PPA: Store clicked →", mode);

    var payload = (opts.payload && typeof opts.payload === "object")
      ? buildStorePayload(opts.payload)
      : buildComposerStorePayload(mode, opts);

    if (!toStr(payload.title).trim() && !toStr(payload.content).trim()) {
      renderNotice("warn","Add a title or some content before saving."); return;
    }

    // CHANGED: Draft UX toggles (defaults ON for drafts).
    var isDraft = (String(mode || "") === "draft"); // CHANGED:
    var openDraftOnSave = (opts.openDraftOnSave !== undefined) ? !!opts.openDraftOnSave : true; // CHANGED:
    var showViewDraftLink = (opts.showViewDraftLink !== undefined) ? !!opts.showViewDraftLink : true; // CHANGED:

    // CHANGED: Popup-safe tab strategy — open immediately on click, navigate later on success.
    var draftTab = null; // CHANGED:
    if (isDraft && openDraftOnSave) { // CHANGED:
      draftTab = safeOpenBlankTab(); // CHANGED:
    } // CHANGED:

    return withBusy(function () {
      var p = doStoreViaStableSurface(payload, mode);
      if (!p || typeof p.then !== "function") {
        if (draftTab && draftTab.close) { try { draftTab.close(); } catch (e0) {} } // CHANGED:
        renderNotice("error","Save failed: transport unavailable.");
        return null;
      }

      return p.then(function (res) {
        if (!res || !res.ok) {
          if (draftTab && draftTab.close) { try { draftTab.close(); } catch (e1) {} } // CHANGED:
          renderNotice("error","Save failed ("+(res?res.status:0)+").");
          return;
        }

        // CHANGED: Extract the best URL we can for drafts (prefer edit_link).
        var draftUrl = pickFirstUrl(res); // CHANGED:

        // CHANGED: Update / render the "View Draft" link next to the button.
        if (isDraft && showViewDraftLink) { // CHANGED:
          updateViewDraftLink(draftUrl, btnEl); // CHANGED:
        } // CHANGED:

        // CHANGED: Navigate the pre-opened tab if available; fallback to opening URL.
        if (isDraft && openDraftOnSave) { // CHANGED:
          if (draftUrl) { // CHANGED:
            if (draftTab && draftTab.location) { // CHANGED:
              try { draftTab.location = draftUrl; } catch (e2) { safeOpenUrlNewTab(draftUrl); } // CHANGED:
            } else { // CHANGED:
              safeOpenUrlNewTab(draftUrl); // CHANGED:
            } // CHANGED:
          } else { // CHANGED:
            // If we opened a blank tab but got no URL back, close it. // CHANGED:
            if (draftTab && draftTab.close) { try { draftTab.close(); } catch (e3) {} } // CHANGED:
          } // CHANGED:
        } // CHANGED:

        renderNotice("success",(mode==="publish")?"Post published.":"Draft saved.");
      }, function () {
        if (draftTab && draftTab.close) { try { draftTab.close(); } catch (e4) {} } // CHANGED:
      });
    }, (mode==="publish")?"publish":"draft");
  } // CHANGED:

  function handleDraftClick(ev, btnEl, opts) { return handleStoreClick(ev, btnEl, "draft", opts); } // CHANGED:
  function handlePublishClick(ev, btnEl, opts) { return handleStoreClick(ev, btnEl, "publish", opts); } // CHANGED:

  // Export (merge)
  composerStore.ver = MOD_VER; // CHANGED:
  composerStore.store = store; // CHANGED:
  composerStore._unwrapWpAjax = unwrapWpAjax; // CHANGED:
  composerStore._buildStorePayload = buildStorePayload; // CHANGED:
  composerStore.buildComposerStorePayload = buildComposerStorePayload; // CHANGED:
  composerStore.handleDraftClick = handleDraftClick; // CHANGED:
  composerStore.handlePublishClick = handlePublishClick; // CHANGED:
  composerStore._handleStoreClick = handleStoreClick; // CHANGED:
  composerStore._doStoreViaStableSurface = doStoreViaStableSurface; // CHANGED:
  window.PPAAdminModules.composerStore = composerStore; // CHANGED:

})(window, document);
