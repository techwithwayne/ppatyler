/* global window, document, jQuery */
/**
 * PostPress AI — Admin JS
 * Path: assets/js/admin.js
 *
 * ========= CHANGE LOG =========
 * 2026-01-03.1: FIX: De-dupe redundant title line in Outline/Body for Preview + Generate autofill. No endpoint/payload changes. // CHANGED:
 * 2025-12-30.1: UX: After successful Save Draft (store), open the draft edit screen in a new tab (popup-safe via about:blank) and render/update a "View Draft" link right after the Save Draft button. No endpoint/payload changes. // CHANGED:
 * 2025-12-22.1: Preview outline cleanup: stop rendering Outline in a <pre> block; render Outline via markdownToHtml() inside a <div class="ppa-outline"> to prevent pre overflow/wrap issues; broaden list hardening scope to the full preview pane so Outline + Body bullets render reliably. // CHANGED:
 *               No contract changes. No endpoint changes. No payload shape changes. Preview pane placement preserved. // CHANGED:
 * 2025-12-21.8: Preview pane list hardening: restore visible bullets + safe left padding and wrapping for ul/ol/li inside the preview pane only (WP admin resets were hiding markers / clipping). // CHANGED:
 *               No contract changes. No endpoint changes. No payload shape changes. No global CSS edits. // CHANGED:
 * 2025-12-21.7: Fix ppa_generate 400 ('Root must be an object') by restoring proven JSON-body transport for ppa_generate only; keep other actions on legacy form transport to avoid breaking stable store/publish flows. // CHANGED:
 *               No endpoint changes; no payload key changes; preserves preview pane hardening + exports + module bridge parity. // CHANGED:
 * 2025-12-21.6: Fix ppa_generate 400 ('Root must be an object') by sending payload as a real POST object (payload[...]) so PHP proxy forwards JSON object root; other actions unchanged. // CHANGED:
 * 2025-12-21.5: Fix btn* refs to use real DOM elements (avoid addEventListener crash); use getElementById for toolbar buttons + msg container (no behavior change). // CHANGED:
 * 2025-12-21.3: Remove version parity check block (data-at... older comment history; no runtime behavior changes. // CHANGED:
 * 2025-12-21.2: Shrink admin.js by removing legacy hidden ...view) click handler + related response/html helpers; // CHANGED:
 *               keep Generate/Store/Publish flows and expo...e behavior unchanged.                                // CHANGED:
 * 2025-12-21.1: Composer preview hardening: force Generate...legacy transport only (no modular DOM side-effects); // CHANGED:
 *               capture + stop propagation on button click...to prevent orchestrator hijacks;                     // CHANGED:
 *               add preview pane resolver + visibility unh...de (no DOM moves, no new styles);                    // CHANGED:
 *               export postStore so required console check... pass.                                               // CHANGED:
 * 2025-12-20.2: Fix PPAAdmin export merge so new helpers always exist (postGenerate no longer undefined); // CHANGED:
 *               add robust module bridge polling + on-demand patch so module apiPost equals legacy apiPost. // CHANGED:
 *               add reactive PPAAdminModules setter + 30s ...true even if modules load late or overwrite exports. // CHANGED:
 * 2025-12-20.1: Nonce priority fix — prefer ppaAdmin.nonce before data-ppa-nonce to prevent wp_rest nonce being sent to admin-ajax actions. // CHANGED:
 * 2025-12-09.1: Expose selected helpers on window.PPAAdmin for safe future modularization (no behavior change). // CHANGED:
 * 2025-11-18.7: Clean AI-generated titles to strip trailing ellipsis/punctuation before filling WP title. // CHANGED:
 * 2025-11-18.6: Hide Preview button in Composer and rename Generate Draft → Generate Preview.         // CHANGED:
 * 2025-11-18.5: Preview now builds HTML from AI title + body/markdown/text instead of only raw html; // CHANGED:
 *               JSON diagnostic fallback kept only as last resort.                                   // CHANGED:
 * 2025-11-17.2: Enhance Markdown → HTML rendering for generate preview (headings, lists, inline em/strong). // CHANGED:
 * 2025-11-17: Wire target audience field into preview payload.                                       // CHANGED:
 * 2025-11-16.3: Wire ppa_generate (Generate button) to AI /generate/ endpoint; render structured draft + SEO    // CHANGED:
 *               meta in preview and auto-fill core fields where empty.                                         // CHANGED:
 * 2025-11-16.2: Clarify draft success notice to mention WordPress draft; bump JS internal version.              // CHANGED:
 * 2025-11-16: Add mode hint to store payloads (draft/publish/update) for Django/WP store pipeline.               // CHANGED:
 * 2025-11-15: Add X-PPA-View ('composer') and X-Requested-With headers for Composer AJAX parity with Django logs;  // CHANGED:
 *             keeps existing payload/UX unchanged while improving diagnostics.                                      // CHANGED:
 * 2025-11-11.6: Always render preview from result.html (fallback to content); set data-ppa-provider on pane;    // CHANGED:
 *               de-duplicate readCsvValues; add window.PPA_LAST_PREVIEW debug hook; version bump.               // CHANGED:
 * 2025-11-11.5: Preview payload now maps UI fields for Django normalize endpoint:
 *               - subject → title, brief → content (+html/text synonyms).
 *               - If no HTML returned, show JSON diagnostic in preview pane.
 * 2025-11-11.4: Add early guard for Preview when both subject & brief are empty (UX); DevTools test hook.
 * 2025-11-11.3: Support nested preview shapes (data.result.content/html, result.content/html); provider pick.
 * 2025-10..2025-11: Prior fixes & UX polish (see earlier entries).
 */

(function () {
  'use strict';

  var PPA_JS_VER = 'admin.v2026-01-03.1'; // CHANGED:

  // Abort if composer root is missing (defensive)
  var root = document.getElementById('ppa-composer');
  if (!root) {
    console.info('PPA: composer root not found, admin.js is idle');
    return;
  }

  // Ensure toolbar message acts as a live region (A11y)
  (function ensureLiveRegion(){
    var msg = document.getElementById('ppa-toolbar-msg');
    if (!msg) return;
    try {
      msg.setAttribute('role', 'status');
      msg.setAttribute('aria-live', 'polite');
      msg.setAttribute('aria-atomic', 'true');
    } catch (e) {}
  })();

  // ---- Preview Pane Resolver (Composer) ------------------------------------
  // cache + resolve preview pane without moving DOM nodes.
  var __ppaPreviewPane = null;

  function getPreviewPane() {
    if (__ppaPreviewPane && __ppaPreviewPane.nodeType === 1) return __ppaPreviewPane;
    var pane = null;
    try { pane = root.querySelector('#ppa-preview-pane'); } catch (e) { pane = null; }
    if (!pane) pane = document.getElementById('ppa-preview-pane');
    if (!pane) {
      // Last-resort fallbacks without moving any DOM nodes.
      try { pane = root.querySelector('[data-ppa-preview-pane]') || root.querySelector('.ppa-preview-pane'); }
      catch (e2) { pane = null; }
    }
    __ppaPreviewPane = pane || null;
    return __ppaPreviewPane;
  }

  // Make preview pane focusable for screen readers
  (function ensurePreviewPaneFocusable(){
    var pane = getPreviewPane();
    if (pane && !pane.hasAttribute('tabindex')) pane.setAttribute('tabindex', '-1');
  })();

  // ---- Helpers -------------------------------------------------------------

  function $(sel, ctx) { return (ctx || document).querySelector(sel); }
  function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel) || []); }

  function getAjaxUrl() {
    if (window.PPA && window.PPA.ajaxUrl) return window.PPA.ajaxUrl;
    if (window.PPA && window.PPA.ajax) return window.PPA.ajax; // legacy
    if (window.ppaAdmin && window.ppaAdmin.ajaxurl) return window.ppaAdmin.ajaxurl;
    if (window.ajaxurl) return window.ajaxurl;
    return '/wp-admin/admin-ajax.php';
  }

  function getNonce() {
    // Prefer the enqueued config, then fallback to data attrs.
    if (window.ppaAdmin && window.ppaAdmin.nonce) return String(window.ppaAdmin.nonce);
    var el = $('#ppa-nonce');
    if (el && el.value) return String(el.value);
    var data = $('[data-ppa-nonce]');
    if (data) return String(data.getAttribute('data-ppa-nonce') || '');
    return '';
  }

  function toFormBody(obj) {
    var parts = [];
    Object.keys(obj || {}).forEach(function (k) {
      parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(String(obj[k] == null ? '' : obj[k])));
    });
    return parts.join('&');
  }

  function normalizePayloadObject(payload) {
    // If a caller passes JSON as a string, parse it so we always work with an object root.
    if (payload === undefined || payload === null) return {};
    if (typeof payload === 'string') {
      var s = String(payload || '').trim();
      if (!s) return {};
      try {
        var parsed = JSON.parse(s);
        if (typeof parsed === 'string') {
          try { parsed = JSON.parse(parsed); } catch (e2) {}
        }
        if (parsed && typeof parsed === 'object') return parsed;
        return { value: parsed };
      } catch (e1) {
        return { raw: s };
      }
    }
    if (typeof payload !== 'object') return { value: payload };
    return payload;
  }

  function apiPost(action, payload) {
    var ajaxUrl = getAjaxUrl();
    var payloadObj = normalizePayloadObject(payload);

    // IMPORTANT: Fix only the known-bad path.
    // - ppa_generate uses JSON body (object root) to avoid double-encoding that triggers Django "Root must be an object".
    // - other actions keep legacy form body to avoid changing stable store/publish behavior.
    var isGenerate = (String(action) === 'ppa_generate');

    // Build endpoint robustly (supports ajaxUrl already containing a '?').
    var endpoint = ajaxUrl + (ajaxUrl.indexOf('?') === -1 ? '?' : '&') + 'action=' + encodeURIComponent(String(action || ''));

    var headers = {
      'X-Requested-With': 'XMLHttpRequest',
      'X-PPA-View': (window.ppaAdmin && window.ppaAdmin.view) ? String(window.ppaAdmin.view) : 'composer'
    };

    var nonce = getNonce();
    if (nonce) headers['X-WP-Nonce'] = nonce;

    var body;
    if (isGenerate) {
      headers['Content-Type'] = 'application/json; charset=UTF-8';
      body = JSON.stringify(payloadObj || {});
    } else {
      headers['Content-Type'] = 'application/x-www-form-urlencoded; charset=UTF-8';
      body = toFormBody({ action: action, payload: JSON.stringify(payloadObj || {}) });
    }

    // Debug hook: verify which transport was used without logging user content.
    try {
      window.PPA_LAST_REQUEST = {
        action: String(action || ''),
        transport: (isGenerate ? 'json-body' : 'form-payload-json'),
        js_ver: PPA_JS_VER
      };
    } catch (e0) {}

    return fetch(endpoint, {
      method: 'POST',
      credentials: 'same-origin',
      headers: headers,
      body: body
    }).then(function (resp) {
      return resp.text().then(function (txt) {
        var parsed = null;
        try { parsed = JSON.parse(txt); } catch (e) {}
        return {
          ok: resp.ok,
          status: resp.status,
          bodyText: txt,
          body: (parsed != null ? parsed : txt)
        };
      });
    }).catch(function (err) {
      return { ok: false, status: 0, error: err };
    });
  }

  // Simple escapers
  function escHtml(s){
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
  }
  function escAttr(s){
    return String(s || '')
      .replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
      .replace(/"/g,'&quot;').replace(/'/g,'&#39;');
  }

  // Clean AI-generated titles so we never leave trailing ellipsis or dangling punctuation.
  function ppaCleanTitle(raw) {
    if (!raw) return '';
    var t = String(raw).trim();
    t = t.replace(/[.?!…]+$/g, '').trim();
    return t;
  }

  // --- Title De-Dupe helpers -------------------------------------------------
  function normalizeComparableText(s) { // CHANGED:
    var v = String(s || '');
    v = v.replace(/<[^>]*>/g, ' ');
    v = v.replace(/&nbsp;/gi, ' ');
    v = v.replace(/\s+/g, ' ').trim().toLowerCase();
    // remove punctuation (keep letters/numbers/spaces)
    v = v.replace(/[“”"‘’'`~!@#$%^&*()_+\-=\[\]{};:\\|,.<>\/?…]/g, '');
    v = v.replace(/\s+/g, ' ').trim();
    return v;
  }

  function stripLeadingTitleFromMarkdown(md, title) { // CHANGED:
    var t = normalizeComparableText(title);
    var s = String(md || '');
    if (!t || !s.trim()) return s;

    var lines = s.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');

    // Find first non-empty line
    var i = 0;
    while (i < lines.length && String(lines[i]).trim() === '') i++;
    if (i >= lines.length) return s;

    var line = String(lines[i]).trim();
    var lineNorm = normalizeComparableText(line);

    // Setext heading style: Title line + ==== or ----
    if (i + 1 < lines.length) {
      var next = String(lines[i + 1]).trim();
      if ((/^={2,}$/.test(next) || /^-{2,}$/.test(next)) && lineNorm === t) {
        lines.splice(i, 2);
        if (i < lines.length && String(lines[i]).trim() === '') lines.splice(i, 1);
        return lines.join('\n');
      }
    }

    // ATX heading style: # Title
    var m = line.match(/^#{1,6}\s+(.*)$/);
    if (m && normalizeComparableText(m[1]) === t) {
      lines.splice(i, 1);
      if (i < lines.length && String(lines[i]).trim() === '') lines.splice(i, 1);
      return lines.join('\n');
    }

    // "Title: ..." or "Post Title: ..."
    var m2 = line.match(/^(?:post\s*)?title\s*[:\-]\s*(.+)$/i);
    if (m2 && normalizeComparableText(m2[1]) === t) {
      lines.splice(i, 1);
      if (i < lines.length && String(lines[i]).trim() === '') lines.splice(i, 1);
      return lines.join('\n');
    }

    // Bullet "Title: ..." or bullet containing only the title.
    var m3 = line.match(/^([-*]|\d+\.)\s+(.*)$/);
    if (m3) {
      var rest = String(m3[2] || '').trim();
      var m3b = rest.match(/^(?:post\s*)?title\s*[:\-]\s*(.+)$/i);
      if (m3b && normalizeComparableText(m3b[1]) === t) {
        lines.splice(i, 1);
        if (i < lines.length && String(lines[i]).trim() === '') lines.splice(i, 1);
        return lines.join('\n');
      }
      if (normalizeComparableText(rest) === t) {
        lines.splice(i, 1);
        if (i < lines.length && String(lines[i]).trim() === '') lines.splice(i, 1);
        return lines.join('\n');
      }
    }

    // Plain first line equals title — ONLY strip if followed by a blank line (reduces false positives).
    if (lineNorm === t) {
      var next2 = (i + 1 < lines.length) ? String(lines[i + 1]).trim() : '';
      if (next2 === '') {
        lines.splice(i, 1);
        if (i < lines.length && String(lines[i]).trim() === '') lines.splice(i, 1);
        return lines.join('\n');
      }
    }

    return s;
  }

  function stripTitleFromOutline(outline, title) { // CHANGED:
    var t = normalizeComparableText(title);
    var s = String(outline || '');
    if (!t || !s.trim()) return s;

    var lines = s.replace(/\r\n/g, '\n').replace(/\r/g, '\n').split('\n');

    // Find first non-empty line
    var i = 0;
    while (i < lines.length && String(lines[i]).trim() === '') i++;
    if (i >= lines.length) return s;

    var line = String(lines[i]).trim();
    var lineNorm = normalizeComparableText(line);

    // Heading-style title line
    var h = line.match(/^#{1,6}\s+(.*)$/);
    if (h && normalizeComparableText(h[1]) === t) {
      lines.splice(i, 1);
      if (i < lines.length && String(lines[i]).trim() === '') lines.splice(i, 1);
      return lines.join('\n');
    }

    // "Title: ..." or "Post Title: ..."
    var m2 = line.match(/^(?:post\s*)?title\s*[:\-]\s*(.+)$/i);
    if (m2 && normalizeComparableText(m2[1]) === t) {
      lines.splice(i, 1);
      if (i < lines.length && String(lines[i]).trim() === '') lines.splice(i, 1);
      return lines.join('\n');
    }

    // Bullet containing "Title: ..." or bullet containing only the title
    var m3 = line.match(/^([-*]|\d+\.)\s+(.*)$/);
    if (m3) {
      var rest = String(m3[2] || '').trim();
      var m3b = rest.match(/^(?:post\s*)?title\s*[:\-]\s*(.+)$/i);
      if (m3b && normalizeComparableText(m3b[1]) === t) {
        lines.splice(i, 1);
        if (i < lines.length && String(lines[i]).trim() === '') lines.splice(i, 1);
        return lines.join('\n');
      }
      if (normalizeComparableText(rest) === t) {
        lines.splice(i, 1);
        if (i < lines.length && String(lines[i]).trim() === '') lines.splice(i, 1);
        return lines.join('\n');
      }
    }

    // Plain first line equals title — ONLY strip if followed by a blank line.
    if (lineNorm === t) {
      var next2 = (i + 1 < lines.length) ? String(lines[i + 1]).trim() : '';
      if (next2 === '') {
        lines.splice(i, 1);
        if (i < lines.length && String(lines[i]).trim() === '') lines.splice(i, 1);
        return lines.join('\n');
      }
    }

    return s;
  }
  // --------------------------------------------------------------------------

  function sanitizeSlug(s) {
    var v = String(s || '').trim().toLowerCase();
    v = v.replace(/\s+/g,'-').replace(/[^a-z0-9\-]/g,'').replace(/\-+/g,'-').replace(/^\-+|\-+$/g,'');
    return v;
  }

  function readCsvValues(el) {
    if (!el) return [];
    var v = '';
    if (typeof el.value === 'string') v = el.value;
    else v = String(el.textContent || '');
    v = v.trim();
    if (!v) return [];
    v = v.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
    var parts = v.split(/[\n,]/);
    return parts.map(function(p){ return String(p || '').trim(); }).filter(Boolean);
  }

  function toHtmlFromText(txt) {
    var s = String(txt || '').trim();
    if (!s) return '';
    // Escape and basic paragraphing
    s = escHtml(s);
    var parts = s.split(/\n\s*\n/).map(function (p) {
      p = String(p || '').trim();
      if (!p) return '';
      return '<p>' + p.replace(/\n/g,'<br>') + '</p>';
    }).filter(Boolean);
    return parts.join('');
  }

  function markdownToHtml(md) {
    var txt = String(md || '').trim();
    if (!txt) return '';
    // Escape first, then apply minimal transforms
    txt = escHtml(txt).replace(/\r\n/g,'\n').replace(/\r/g,'\n');

    // Headings
    txt = txt.replace(/^######\s+(.*)$/gm, '<h6>$1</h6>');
    txt = txt.replace(/^#####\s+(.*)$/gm, '<h5>$1</h5>');
    txt = txt.replace(/^####\s+(.*)$/gm, '<h4>$1</h4>');
    txt = txt.replace(/^###\s+(.*)$/gm, '<h3>$1</h3>');
    txt = txt.replace(/^##\s+(.*)$/gm, '<h2>$1</h2>');
    txt = txt.replace(/^#\s+(.*)$/gm, '<h1>$1</h1>');

    // Bold/italic
    txt = txt.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    txt = txt.replace(/\*(.+?)\*/g, '<em>$1</em>');

    // Inline code
    txt = txt.replace(/`([^`]+)`/g, '<code>$1</code>');

    // Lists (simple)
    txt = txt.replace(/^\-\s+(.*)$/gm, '<li>$1</li>');
    txt = txt.replace(/(<li>.*<\/li>\n?)+/g, function (block) {
      return '<ul>' + block.replace(/\n/g, '') + '</ul>';
    });

    // Paragraphs
    var htmlParts = [];
    var blocks = txt.split(/\n\s*\n/);
    blocks.forEach(function (b) {
      var p = String(b || '').trim();
      if (!p) return;
      if (p.indexOf('<h') === 0 || p.indexOf('<ul>') === 0) {
        htmlParts.push(p);
      } else {
        htmlParts.push('<p>' + p.replace(/\n/g,'<br>') + '</p>');
      }
    });

    if (!htmlParts.length) {
      // Fallback to the old behavior if nothing parsed
      return toHtmlFromText(txt);
    }
    return htmlParts.join('');
  }

  // ---- Payload builders ----------------------------------------------------

  function buildPreviewPayload() {
    var subject = $('#ppa-subject');
    var brief   = $('#ppa-brief');
    var genre   = $('#ppa-genre');
    var tone    = $('#ppa-tone');
    var wc      = $('#ppa-word-count');
    var audience = $('#ppa-audience');

    var payload = {
      // Keep legacy synonyms for backend parity
      subject: subject ? String(subject.value || '').trim() : '',
      title:   subject ? String(subject.value || '').trim() : '',
      brief:   brief   ? String(brief.value   || '').trim() : '',
      content: brief   ? String(brief.value   || '').trim() : '',
      text:    brief   ? String(brief.value   || '').trim() : '',
      html:    '',
      genre:   genre ? String(genre.value || '').trim() : '',
      tone:    tone  ? String(tone.value  || '').trim() : '',
      word_count: wc ? String(wc.value || '').trim() : '',
      audience: audience ? String(audience.value || '').trim() : '',
      keywords: readCsvValues($('#ppa-keywords')),
      _js_ver: PPA_JS_VER
    };

    // Optional: pass tags/categories if present on the page
    var tagsEl = $('#ppa-tags') || $('#new-tag-post_tag') || $('#tax-input-post_tag');
    var catsEl = $('#ppa-categories') || $('#post_category');
    if (tagsEl) {
      payload.tags = (function(){
        if (tagsEl.tagName === 'SELECT') {
          return $all('option:checked', tagsEl).map(function (o) { return o.value; });
        } return readCsvValues(tagsEl);
      })();
    }
    if (catsEl) {
      payload.categories = (function(){
        if (catsEl.tagName === 'SELECT') {
          return $all('option:checked', catsEl).map(function (o) { return o.value; });
        } return readCsvValues(catsEl);
      })();
    }

    // Optional meta fields
    var focusEl = $('#yoast_wpseo_focuskw_text_input');
    var metaEl  = $('#yoast_wpseo_metadesc');
    payload.meta = {
      focus_keyphrase: focusEl ? String(focusEl.value || '').trim() : '',
      meta_description: metaEl ? String(metaEl.value  || '').trim() : ''
    };

    return payload;
  }

  function extractTitleFromHtml(html) {
    var h = String(html || '');
    var m = h.match(/<h[12][^>]*>(.*?)<\/h[12]>/i);
    if (!m) return '';
    return String(m[1] || '').replace(/<[^>]+>/g,'').trim();
  }

  function extractExcerptFromHtml(html) {
    var h = String(html || '');
    // first paragraph
    var m = h.match(/<p[^>]*>(.*?)<\/p>/i);
    if (!m) return '';
    return String(m[1] || '').replace(/<[^>]+>/g,'').trim();
  }

  function buildStorePayload(mode) {
    var title   = $('#ppa-title')   || $('#title');
    var excerpt = $('#ppa-excerpt') || $('#excerpt');
    var slug    = $('#ppa-slug')    || $('#post_name');
    var content = $('#ppa-content') || $('#content');

    var payload = {
      mode: mode || 'draft',
      title:   title ? String(title.value || '').trim() : '',
      excerpt: excerpt ? String(excerpt.value || '').trim() : '',
      slug:    slug ? String(slug.value || '').trim() : '',
      content: content ? String(content.value || '').trim() : '',
      _js_ver: PPA_JS_VER
    };

    // Optional: post id if present
    var postId = $('#post_ID');
    if (postId && postId.value) payload.post_id = String(postId.value);

    // Optional: status override from UI
    var statusEl = $('#ppa-status');
    var modeVal = statusEl ? String(statusEl.value || '').trim() : '';
    if (modeVal) {
      payload.mode = modeVal;
    }

    // If editor is empty, use Preview HTML and auto-fill
    if (!payload.content || !String(payload.content).trim()) {
      var pane = getPreviewPane();
      var html = pane ? String(pane.innerHTML || '').trim() : '';
      if (html) {
        payload.content = html;
        if (!payload.title)   payload.title   = extractTitleFromHtml(html);
        if (!payload.excerpt) payload.excerpt = extractExcerptFromHtml(html);
        if (!payload.slug && payload.title) payload.slug = sanitizeSlug(payload.title);
      }
    }

    // Optional meta fields
    var focusEl2 = $('#yoast_wpseo_focuskw_text_input');
    var metaEl2  = $('#yoast_wpseo_metadesc');
    payload.meta = {
      focus_keyphrase: focusEl2 ? String(focusEl2.value || '').trim() : '',
      meta_description: metaEl2 ? String(metaEl2.value  || '').trim() : ''
    };

    return payload;
  }

  function hasTitleOrSubject() {
    var subjectEl = $('#ppa-subject');
    var titleEl   = $('#ppa-title') || $('#title');

    var subject = subjectEl ? String(subjectEl.value || '').trim() : '';
    var title   = titleEl ? String(titleEl.value || '').trim() : '';

    return !!(subject || title);
  }

  // ---- Toolbar Notices & Busy State ---------------------------------------

  var btnPreview  = document.getElementById('ppa-preview');
  var btnDraft    = document.getElementById('ppa-draft');
  var btnPublish  = document.getElementById('ppa-publish');
  var btnGenerate = document.getElementById('ppa-generate');

  // Repurpose Generate as the main preview button and hide the old Preview.
  (function adaptGenerateAsPreview(){
    if (btnPreview) {
      try { btnPreview.style.display = 'none'; } catch (e) {}
    }
    if (btnGenerate) {
      try { btnGenerate.textContent = 'Generate Preview'; } catch (e) {}
    }
  })();

  // Ensure we always have a notice container above the main buttons
  function noticeContainer() {
    var el = document.getElementById('ppa-toolbar-msg');
    if (el) return el;

    // Try to anchor it in the same row as the primary buttons
    var host = null;
    if (btnGenerate && btnGenerate.parentNode) {
      host = btnGenerate.parentNode;
    } else if (btnPreview && btnPreview.parentNode) {
      host = btnPreview.parentNode;
    } else if (btnDraft && btnDraft.parentNode) {
      host = btnDraft.parentNode;
    } else if (btnPublish && btnPublish.parentNode) {
      host = btnPublish.parentNode;
    }
    if (!host) return null;

    el = document.createElement('div');
    el.id = 'ppa-toolbar-msg';
    el.className = 'ppa-notice';
    try {
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      el.setAttribute('aria-atomic', 'true');
    } catch (e2) {}
    host.insertBefore(el, host.firstChild);
    return el;
  }

  function renderNotice(type, message) {
    var el = noticeContainer();
    if (!el) {
      if (type === 'error' || type === 'warn') { try { window.alert(String(message || '')); } catch (e) {} }
      return;
    }
    el.className = 'ppa-notice ppa-notice-' + String(type || 'info');
    el.textContent = String(message == null ? '' : message);
  }

  function renderNoticeTimed(type, message, ms) {
    renderNotice(type, message);
    var dur = parseInt(ms, 10);
    if (!dur || dur < 250) dur = 2500;
    window.setTimeout(function () { clearNotice(); }, dur);
  }

  function renderNoticeHtml(type, html) {
    var el = noticeContainer();
    if (!el) return;
    el.className = 'ppa-notice ppa-notice-' + String(type || 'info');
    el.innerHTML = String(html == null ? '' : html);
  }

  function renderNoticeTimedHtml(type, html, ms) {
    renderNoticeHtml(type, html);
    var dur = parseInt(ms, 10);
    if (!dur || dur < 250) dur = 2500;
    window.setTimeout(function () { clearNotice(); }, dur);
  }

  function clearNotice() {
    var el = noticeContainer();
    if (!el) return;
    el.className = 'ppa-notice';
    el.textContent = '';
  }

  function setButtonsDisabled(disabled) {
    var dis = !!disabled;
    if (btnPreview)  btnPreview.disabled = dis;
    if (btnDraft)    btnDraft.disabled = dis;
    if (btnPublish)  btnPublish.disabled = dis;
    if (btnGenerate) btnGenerate.disabled = dis;
  }

  function clickGuard(btn) {
    if (!btn) return false;
    var ts = Number(btn.getAttribute('data-ppa-ts') || 0);
    var now = (Date.now ? Date.now() : (new Date()).getTime());
    if (now - ts < 350) return true;
    btn.setAttribute('data-ppa-ts', String(now));
    return false;
  }

  function withBusy(promiseFactory, label) {
    setButtonsDisabled(true);
    clearNotice();
    var tag = label || 'request';
    try { console.info('PPA: busy start →', tag); } catch (e0) {}

    try {
      var p = promiseFactory();
      return Promise.resolve(p)
        .catch(function (err) {
          try { console.info('PPA: busy error on', tag, err); } catch (e1) {}
          renderNotice('error', 'There was an error while processing your request.');
          throw err;
        })
        .finally(function () {
          setButtonsDisabled(false);
          try { console.info('PPA: busy end ←', tag); } catch (e2) {}
        });
    } catch (e3) {
      setButtonsDisabled(false);
      try { console.info('PPA: busy sync error on', tag, e3); } catch (e4) {}
      renderNotice('error', 'There was an error while preparing your request.');
      throw e3;
    }
  }

  // ---- Preview render -------------------------------------------------------

  function ensurePreviewPaneVisible() {
    var pane = getPreviewPane();
    if (!pane) return;
    try {
      if (pane.style && pane.style.display === 'none') pane.style.display = '';
      pane.removeAttribute('hidden');
      pane.classList.remove('ppa-hidden');
    } catch (e) {}
  }

  function hardenPreviewLists(pane) { // CHANGED:
    // WP admin CSS frequently resets ul/ol styles. We only fix inside the preview pane to avoid global side-effects. // CHANGED:
    if (!pane || pane.nodeType !== 1) return; // CHANGED:
    var scope = null; // CHANGED:
    scope = pane; // CHANGED: style lists across the entire preview pane (Outline + Body), not only the first .ppa-body

    var lists = []; // CHANGED:
    try { lists = Array.prototype.slice.call(scope.querySelectorAll('ul, ol') || []); } catch (e1) { lists = []; } // CHANGED:
    for (var i = 0; i < lists.length; i++) { // CHANGED:
      var list = lists[i]; // CHANGED:
      if (!list || list.nodeType !== 1) continue; // CHANGED:
      try { // CHANGED:
        list.style.listStylePosition = 'outside'; // CHANGED:
        list.style.paddingLeft = '1.25em'; // CHANGED: ensures markers are not clipped
        list.style.marginLeft = '0.75em'; // CHANGED:
        list.style.maxWidth = '100%'; // CHANGED:
        list.style.boxSizing = 'border-box'; // CHANGED:
        if (String(list.tagName || '').toUpperCase() === 'UL') list.style.listStyleType = 'disc'; // CHANGED:
        if (String(list.tagName || '').toUpperCase() === 'OL') list.style.listStyleType = 'decimal'; // CHANGED:
      } catch (e2) {} // CHANGED:
    } // CHANGED:

    var items = []; // CHANGED:
    try { items = Array.prototype.slice.call(scope.querySelectorAll('li') || []); } catch (e3) { items = []; } // CHANGED:
    for (var j = 0; j < items.length; j++) { // CHANGED:
      var li = items[j]; // CHANGED:
      if (!li || li.nodeType !== 1) continue; // CHANGED:
      try { // CHANGED:
        li.style.display = 'list-item'; // CHANGED:
        li.style.overflowWrap = 'anywhere'; // CHANGED:
        li.style.wordBreak = 'break-word'; // CHANGED:
        li.style.maxWidth = '100%'; // CHANGED:
        li.style.boxSizing = 'border-box'; // CHANGED:
      } catch (e4) {} // CHANGED:
    } // CHANGED:
  } // CHANGED:

  function buildPreviewHtml(result) {
    var title = '';
    var outline = '';
    var bodyMd = '';
    var meta = null;

    if (result && typeof result === 'object') {
      title = String(result.title || '').trim();
      outline = String(result.outline || '');
      bodyMd = String(result.body_markdown || result.body || '');
      meta = result.meta || result.seo || null;
    }

    title = ppaCleanTitle(title); // CHANGED:
    if (title) { // CHANGED:
      outline = stripTitleFromOutline(outline, title); // CHANGED:
      bodyMd = stripLeadingTitleFromMarkdown(bodyMd, title); // CHANGED:
    } // CHANGED:

    var html = '';
    html += '<div class="ppa-preview">';
    if (title) html += '<h2>' + escHtml(title) + '</h2>';
    if (outline) {
      html += '<h3>Outline</h3>';
      html += '<div class="ppa-outline">' + markdownToHtml(outline) + '</div>'; // CHANGED:
    }
    if (bodyMd) {
      html += '<h3>Body</h3>';
      html += '<div class="ppa-body">' + markdownToHtml(bodyMd) + '</div>';
    }
    if (meta && typeof meta === 'object') {
      html += '<h3>Meta</h3>';
      html += '<ul class="ppa-meta">';
      if (meta.focus_keyphrase) html += '<li><strong>Focus keyphrase:</strong> ' + escHtml(meta.focus_keyphrase) + '</li>';
      if (meta.meta_description) html += '<li><strong>Meta description:</strong> ' + escHtml(meta.meta_description) + '</li>';
      if (meta.slug) html += '<li><strong>Slug:</strong> ' + escHtml(meta.slug) + '</li>';
      html += '</ul>';
    }
    html += '</div>';
    return html;
  }

  function renderPreview(result, provider) {
    ensurePreviewPaneVisible();
    var pane = getPreviewPane();
    if (!pane) {
      renderNoticeTimed('error', 'Preview pane not found on this screen.', 3500);
      return;
    }
    if (provider) {
      try { pane.setAttribute('data-ppa-provider', String(provider)); } catch (e) {}
    }

    var html = '';
    if (result && typeof result === 'object' && result.html) {
      // If backend sends HTML, we honor it (no guessing). // CHANGED:
      html = String(result.html);
    } else {
      html = buildPreviewHtml(result);
    }
    pane.innerHTML = html;

    hardenPreviewLists(pane); // CHANGED: restore bullets + wrap inside preview pane only

    try { pane.focus(); } catch (e2) {}
  }

  // ---- Response unwrapping --------------------------------------------------

  function unwrapWpAjax(body) {
    if (!body || typeof body !== 'object') return { hasEnvelope: false, success: null, data: body };
    if (Object.prototype.hasOwnProperty.call(body, 'success') && Object.prototype.hasOwnProperty.call(body, 'data')) {
      return { hasEnvelope: true, success: body.success === true, data: body.data };
    }
    return { hasEnvelope: false, success: null, data: body };
  }

  function pickDjangoResultShape(unwrappedData) {
    if (!unwrappedData || typeof unwrappedData !== 'object') return unwrappedData;
    if (unwrappedData.result && typeof unwrappedData.result === 'object') return unwrappedData.result;
    if (Object.prototype.hasOwnProperty.call(unwrappedData, 'title') ||
        Object.prototype.hasOwnProperty.call(unwrappedData, 'outline') ||
        Object.prototype.hasOwnProperty.call(unwrappedData, 'body_markdown')) return unwrappedData;
    return unwrappedData;
  }

  // ---- Generate + Store -----------------------------------------------------

  function applyGenerateResult(result) {
    if (!result || typeof result !== 'object') return { titleFilled: false, excerptFilled: false, slugFilled: false };

    var title = ppaCleanTitle(result.title || '');
    var bodyMdRaw = String(result.body_markdown || result.body || ''); // CHANGED:
    var bodyMd = title ? stripLeadingTitleFromMarkdown(bodyMdRaw, title) : bodyMdRaw; // CHANGED:
    var meta = result.meta || result.seo || {};

    var filled = { titleFilled: false, excerptFilled: false, slugFilled: false };

    var titleEl = $('#ppa-title') || $('#title');
    if (titleEl && title && !String(titleEl.value || '').trim()) {
      titleEl.value = title;
      filled.titleFilled = true;
    }

    // Fill content if empty
    var contentEl = $('#ppa-content') || $('#content');
    if (contentEl && (!String(contentEl.value || '').trim()) && bodyMd) {
      contentEl.value = markdownToHtml(bodyMd); // CHANGED:
    }

    // Excerpt
    var excerptEl = $('#ppa-excerpt') || $('#excerpt');
    if (excerptEl && meta && meta.meta_description && !String(excerptEl.value || '').trim()) {
      excerptEl.value = String(meta.meta_description);
      filled.excerptFilled = true;
    }

    // Slug
    var slugEl = $('#ppa-slug') || $('#post_name');
    if (slugEl && (!String(slugEl.value || '').trim())) {
      var s = '';
      if (meta && meta.slug) s = String(meta.slug);
      if (!s && title) s = sanitizeSlug(title);
      if (s) {
        slugEl.value = s;
        filled.slugFilled = true;
      }
    }

    // Yoast best-effort
    var focusEl3 = $('#yoast_wpseo_focuskw_text_input');
    var metaEl3  = $('#yoast_wpseo_metadesc');
    if (focusEl3 && meta && meta.focus_keyphrase && !String(focusEl3.value || '').trim()) {
      focusEl3.value = String(meta.focus_keyphrase);
    }
    if (metaEl3 && meta && meta.meta_description && !String(metaEl3.value || '').trim()) {
      metaEl3.value = String(meta.meta_description);
    }

    return filled;
  }

  function pickMessage(body) {
    if (!body) return '';
    if (typeof body === 'string') return body;
    if (typeof body === 'object') {
      if (body.message) return String(body.message);
      if (body.error) return String(body.error);
      if (body.data && body.data.message) return String(body.data.message);
    }
    return '';
  }

  function pickId(body) { // CHANGED:
    try { // CHANGED:
      if (body && typeof body === 'object') { // CHANGED:
        if (body.id) return body.id; // CHANGED:
        if (body.post_id) return body.post_id; // CHANGED:
        if (body.result && typeof body.result === 'object') { // CHANGED:
          if (body.result.id) return body.result.id; // CHANGED:
          if (body.result.post_id) return body.result.post_id; // CHANGED:
        } // CHANGED:
        if (body.data && typeof body.data === 'object') { // CHANGED:
          if (body.data.id) return body.data.id; // CHANGED:
          if (body.data.post_id) return body.data.post_id; // CHANGED:
          if (body.data.result && typeof body.data.result === 'object') { // CHANGED:
            if (body.data.result.id) return body.data.result.id; // CHANGED:
            if (body.data.result.post_id) return body.data.result.post_id; // CHANGED:
          } // CHANGED:
        } // CHANGED:
      } // CHANGED:
    } catch (e) {} // CHANGED:
    return ''; // CHANGED:
  }

  function pickEditLink(body) { // CHANGED:
    try { // CHANGED:
      if (body && typeof body === 'object') { // CHANGED:
        if (body.edit_link) return body.edit_link; // CHANGED:
        if (body.result && typeof body.result === 'object' && body.result.edit_link) return body.result.edit_link; // CHANGED:
        if (body.data && typeof body.data === 'object') { // CHANGED:
          if (body.data.edit_link) return body.data.edit_link; // CHANGED:
          if (body.data.result && typeof body.data.result === 'object' && body.data.result.edit_link) return body.data.result.edit_link; // CHANGED:
        } // CHANGED:
      } // CHANGED:
    } catch (e) {} // CHANGED:
    return ''; // CHANGED:
  }

  function pickViewLink(body) { // CHANGED:
    try { // CHANGED:
      if (body && typeof body === 'object') { // CHANGED:
        if (body.view_link) return body.view_link; // CHANGED:
        if (body.result && typeof body.result === 'object' && body.result.view_link) return body.result.view_link; // CHANGED:
        if (body.data && typeof body.data === 'object') { // CHANGED:
          if (body.data.view_link) return body.data.view_link; // CHANGED:
          if (body.data.result && typeof body.data.result === 'object' && body.data.result.view_link) return body.data.result.view_link; // CHANGED:
        } // CHANGED:
      } // CHANGED:
    } catch (e) {} // CHANGED:
    return ''; // CHANGED:
  }

  function buildWpEditUrlFromId(id) { // CHANGED:
    var n = parseInt(id, 10); // CHANGED:
    if (!n || n < 1) return ''; // CHANGED:
    return '/wp-admin/post.php?post=' + encodeURIComponent(String(n)) + '&action=edit'; // CHANGED:
  } // CHANGED:

  function upsertViewDraftLink(editUrl) { // CHANGED:
    var href = String(editUrl || '').trim(); // CHANGED:
    if (!href) return null; // CHANGED:

    var a = document.getElementById('ppa-view-draft-link'); // CHANGED:
    if (!a) { // CHANGED:
      a = document.createElement('a'); // CHANGED:
      a.id = 'ppa-view-draft-link'; // CHANGED:
      a.textContent = 'View Draft'; // CHANGED:
      a.target = '_blank'; // CHANGED:
      a.rel = 'noopener noreferrer'; // CHANGED:

      // Prefer: immediately after the Save Draft button (btnDraft). // CHANGED:
      var wrap = document.getElementById('ppa-view-draft-wrap'); // CHANGED:
      if (!wrap) { // CHANGED:
        wrap = document.createElement('span'); // CHANGED:
        wrap.id = 'ppa-view-draft-wrap'; // CHANGED:
        wrap.className = 'ppa-view-draft-wrap'; // CHANGED:
        try { // CHANGED:
          wrap.style.marginLeft = '10px'; // CHANGED:
          wrap.style.whiteSpace = 'nowrap'; // CHANGED:
          wrap.style.display = 'inline-block'; // CHANGED:
        } catch (e0) {} // CHANGED:
      } // CHANGED:

      // Ensure the anchor is inside our wrapper (so we can insert once). // CHANGED:
      try { // CHANGED:
        if (a.parentNode !== wrap) { // CHANGED:
          while (wrap.firstChild) wrap.removeChild(wrap.firstChild); // CHANGED:
          wrap.appendChild(a); // CHANGED:
        } // CHANGED:
      } catch (e1) {} // CHANGED:

      var inserted = false; // CHANGED:
      try { // CHANGED:
        if (btnDraft && btnDraft.parentNode) { // CHANGED:
          if (btnDraft.nextSibling) btnDraft.parentNode.insertBefore(wrap, btnDraft.nextSibling); // CHANGED:
          else btnDraft.parentNode.appendChild(wrap); // CHANGED:
          inserted = true; // CHANGED:
        } // CHANGED:
      } catch (e2) { inserted = false; } // CHANGED:

      // Fallback: append near toolbar notice container or composer root. // CHANGED:
      if (!inserted) { // CHANGED:
        try { // CHANGED:
          var msg = noticeContainer(); // CHANGED:
          if (msg && msg.parentNode) { // CHANGED:
            if (msg.nextSibling) msg.parentNode.insertBefore(wrap, msg.nextSibling); // CHANGED:
            else msg.parentNode.appendChild(wrap); // CHANGED:
            inserted = true; // CHANGED:
          } // CHANGED:
        } catch (e3) { inserted = false; } // CHANGED:
      } // CHANGED:
      if (!inserted) { // CHANGED:
        try { root.appendChild(wrap); } catch (e4) {} // CHANGED:
      } // CHANGED:
    } // CHANGED:

    // Always update the URL + safety attrs. // CHANGED:
    try { a.href = href; } catch (e5) {} // CHANGED:
    try { a.target = '_blank'; } catch (e6) {} // CHANGED:
    try { a.rel = 'noopener noreferrer'; } catch (e7) {} // CHANGED:
    return a; // CHANGED:
  } // CHANGED:

  function stopEvent(ev) {
    if (!ev) return;
    try { if (typeof ev.preventDefault === 'function') ev.preventDefault(); } catch (e1) {}
    try { if (typeof ev.stopPropagation === 'function') ev.stopPropagation(); } catch (e2) {}
    try { if (typeof ev.stopImmediatePropagation === 'function') ev.stopImmediatePropagation(); } catch (e3) {}
  }

  // Wire buttons
  if (btnDraft) {
    btnDraft.addEventListener('click', function (ev) {
      stopEvent(ev);
      if (clickGuard(btnDraft)) return;
      console.info('PPA: Draft clicked');

      if (!hasTitleOrSubject()) {
        renderNotice('warn', 'Add a subject or title before saving.');
        return;
      }

      // Popup-safe behavior: open a blank tab immediately on click, then navigate it on success. // CHANGED:
      // This avoids popup blockers that would block window.open() inside async callbacks.         // CHANGED:
      var draftTab = null; // CHANGED:
      try { draftTab = window.open('about:blank', '_blank'); } catch (e0) { draftTab = null; } // CHANGED:
      if (draftTab) { // CHANGED:
        try { draftTab.opener = null; } catch (e1) {} // CHANGED:
        try { draftTab.document.title = 'PostPress AI — Opening Draft…'; } catch (e2) {} // CHANGED:
      } // CHANGED:

      withBusy(function () {
        var payload = buildStorePayload('draft');
        return apiPost('ppa_store', payload).then(function (res) {
          var wp = unwrapWpAjax(res.body);
          var data = wp.hasEnvelope ? wp.data : res.body;
          var msg = pickMessage(res.body) || 'Draft request sent.';

          if (!res.ok || (wp.hasEnvelope && !wp.success)) {
            renderNotice('error', 'Save draft failed (' + res.status + '): ' + msg);
            console.info('PPA: draft failed', res);
            // If we opened a blank tab for this action, close it on failure. // CHANGED:
            try { if (draftTab && !draftTab.closed) draftTab.close(); } catch (e3) {} // CHANGED:
            return;
          }

          // Extract edit URL from the store response (multiple shapes supported), fallback to ID. // CHANGED:
          var edit = pickEditLink(data) || pickEditLink(res.body); // CHANGED:
          var pid = pickId(data) || pickId(res.body); // CHANGED:
          if (!edit && pid) edit = buildWpEditUrlFromId(pid); // CHANGED:

          // Always create/update the "View Draft" link (if we can resolve an edit URL). // CHANGED:
          if (edit) upsertViewDraftLink(edit); // CHANGED:

          // Navigate the already-opened tab to the edit screen (popup-safe). // CHANGED:
          if (draftTab) { // CHANGED:
            if (edit) { // CHANGED:
              try { draftTab.location.href = String(edit); } catch (e4) { try { draftTab.location = String(edit); } catch (e5) {} } // CHANGED:
              try { draftTab.focus(); } catch (e6) {} // CHANGED:
            } else { // CHANGED:
              // If no URL could be derived, don't leave a blank tab open. // CHANGED:
              try { if (!draftTab.closed) draftTab.close(); } catch (e7) {} // CHANGED:
            } // CHANGED:
          } // CHANGED:

          renderNoticeTimed('success', 'Draft saved successfully.', 3500);
          console.info('PPA: draft ok', data);
        });
      }, 'store');
    }, true);
  }

  if (btnPublish) {
    btnPublish.addEventListener('click', function (ev) {
      stopEvent(ev);
      if (clickGuard(btnPublish)) return;
      console.info('PPA: Publish clicked');

      if (!hasTitleOrSubject()) {
        renderNotice('warn', 'Add a subject or title before publishing.');
        return;
      }

      withBusy(function () {
        var payload = buildStorePayload('publish');
        return apiPost('ppa_store', payload).then(function (res) {
          var wp = unwrapWpAjax(res.body);
          var data = wp.hasEnvelope ? wp.data : res.body;
          var serr = (data && data.error) ? data.error : null;
          var msg = (serr && serr.message) || pickMessage(res.body) || 'Publish request sent.';
          var pid = pickId(res.body);
          var edit = pickEditLink(res.body);
          var view = pickViewLink(res.body);
          if (!res.ok || (wp.hasEnvelope && !wp.success)) {
            renderNotice('error', 'Publish failed (' + res.status + '): ' + msg);
            console.info('PPA: publish failed', res);
            return;
          }
          if (view || edit) {
            var parts = [];
            if (view) parts.push('<a href="' + escAttr(view) + '" target="_blank" rel="noopener">View</a>');
            if (edit) parts.push('<a href="' + escAttr(edit) + '" target="_blank" rel="noopener">Edit</a>');
            var html = 'Published successfully.' + (pid ? ' ID: ' + pid : '') + ' — ' + parts.join(' &middot; ');
            renderNoticeTimedHtml('success', html, 8000);
          } else {
            renderNoticeTimed('success', 'Published successfully.', 5000);
          }
          console.info('PPA: publish ok', data);
        });
      }, 'store');
    }, true);
  }

  if (btnGenerate) {
    btnGenerate.addEventListener('click', function (ev) {
      stopEvent(ev);
      if (clickGuard(btnGenerate)) return;
      console.info('PPA: Generate clicked');

      // Reuse preview payload so subject/brief/genre/tone/word_count all flow through.
      var probe = buildPreviewPayload();
      if (!String(probe.title || '').trim() &&
          !String(probe.text || '').trim() &&
          !String(probe.content || '').trim()) {
        renderNotice('warn', 'Add a subject or a brief before generating.');
        return;
      }

      withBusy(function () {
        var payload = probe;

        return apiPost('ppa_generate', payload).then(function (res) {
          var wp = unwrapWpAjax(res.body);
          var overallOk = res.ok;
          if (wp.hasEnvelope) overallOk = overallOk && (wp.success === true);

          var data = wp.hasEnvelope ? wp.data : res.body;
          var django = pickDjangoResultShape(data);

          try { window.PPA_LAST_GENERATE = { ok: overallOk, status: res.status, body: res.body, data: data, djangoResult: django }; } catch (e) {}

          if (!overallOk) {
            renderNotice('error', 'Generate failed (' + res.status + '): ' + (pickMessage(res.body) || 'Unknown error'));
            console.info('PPA: generate failed', res);
            return;
          }

          var provider = (data && data.provider) ? data.provider : (django && django.provider ? django.provider : '');
          renderPreview(django, provider);

          var filled = applyGenerateResult(django);
          try { console.info('PPA: applyGenerateResult →', filled); } catch (e2) {}

          renderNotice('success', 'AI draft generated. Review, tweak, then Save Draft or Publish.');
        });
      }, 'generate');
    }, true);
  }

  // ---- Export Surface (window.PPAAdmin) -------------------------------------
  window.PPAAdmin = window.PPAAdmin || {};
  window.PPAAdmin.apiPost = apiPost;
  window.PPAAdmin.postGenerate = function () { return apiPost('ppa_generate', buildPreviewPayload()); };
  window.PPAAdmin.postStore = function (mode) { return apiPost('ppa_store', buildStorePayload(mode || 'draft')); };
  window.PPAAdmin.markdownToHtml = markdownToHtml;
  window.PPAAdmin.renderPreview = renderPreview;
  window.PPAAdmin._js_ver = PPA_JS_VER;

  // ---- Module Bridge (parity) ----------------------------------------------
  (function patchModuleBridge(){
    try {
      window.PPAAdminModules = window.PPAAdminModules || {};
      window.PPAAdminModules.api = window.PPAAdminModules.api || {};
      if (window.PPAAdminModules.api.apiPost !== apiPost) {
        window.PPAAdminModules.api.apiPost = apiPost;
      }
    } catch (e) {}
  })();

  console.info('PPA: admin.js initialized →', PPA_JS_VER);

})();
