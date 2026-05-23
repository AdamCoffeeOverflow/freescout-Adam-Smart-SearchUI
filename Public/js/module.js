(function () {
  function onReady(fn) {
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', fn);
    } else {
      fn();
    }
  }

  onReady(function () {
    try {
      var cfg = document.getElementById('adamsmartsearchui-config');
      var smartUrl = cfg ? (cfg.getAttribute('data-search-url') || '') : '';
      var suggestUrl = cfg ? (cfg.getAttribute('data-suggest-url') || '') : '';
      // Default to NOT touching core search if config node is missing.
      // (Fail-safe for unexpected layouts / older FreeScout versions.)
      var useCore = cfg ? (cfg.getAttribute('data-use-core') === '1') : false;
      var hideCore = cfg ? (cfg.getAttribute('data-hide-core') === '1') : false;
      var showInline = cfg ? (cfg.getAttribute('data-show-inline') === '1') : true;

      // Lightweight i18n (CSP-safe): strings are rendered server-side into data-* attrs.
      function getCfgAttr(name, fallback) {
        try {
          if (!cfg) return fallback;
          var v = cfg.getAttribute(name);
          if (typeof v !== 'string') return fallback;
          v = v.toString();
          return v ? v : fallback;
        } catch (e) {
          return fallback;
        }
      }

      function clearNode(node) {
        try {
          while (node && node.firstChild) {
            node.removeChild(node.firstChild);
          }
        } catch (e) {}
      }
      var fieldsUrl = cfg ? (cfg.getAttribute('data-fields-url') || '') : '';
      var recentMetaUrl = cfg ? (cfg.getAttribute('data-recent-meta-url') || '') : '';

      var I18N = {
        inlinePlaceholder: getCfgAttr('data-i18n-inline-placeholder', 'Search...'),
        focusSearch: getCfgAttr('data-i18n-focus-search', 'Focus search'),
        openSmartSearch: getCfgAttr('data-i18n-open-smart-search', 'Open Smart Search'),
        suggestions: getCfgAttr('data-i18n-suggestions', 'Suggestions'),
        searchSmartFor: getCfgAttr('data-i18n-search-smart-for', 'Search Smart for “:q”'),
        enter: getCfgAttr('data-i18n-enter', 'Enter'),
        recentSearches: getCfgAttr('data-i18n-recent-searches', 'Recent searches'),
        search: getCfgAttr('data-i18n-search', 'Search'),
        anyCustomField: getCfgAttr('data-i18n-any-custom-field', 'Any custom field'),
        loadingFields: getCfgAttr('data-i18n-loading-fields', 'Loading fields…'),
        loadingRecent: getCfgAttr('data-i18n-loading-recent', 'Loading…')
      };

      function bindSmartSearchMailboxFields() {
        try {
          var form = document.querySelector('form.adamsmartsearch-form');
          if (!form || !fieldsUrl) {
            return;
          }

          var mailboxSelect = form.querySelector('select[name="mailbox_id"]');
          var fieldSelect = form.querySelector('select[name="field_id"]');
          if (!mailboxSelect || !fieldSelect) {
            return;
          }

          function setFieldOptions(fields, selectedId) {
            var i;
            while (fieldSelect.firstChild) {
              fieldSelect.removeChild(fieldSelect.firstChild);
            }

            var emptyOpt = document.createElement('option');
            emptyOpt.value = '0';
            emptyOpt.appendChild(document.createTextNode(I18N.anyCustomField));
            fieldSelect.appendChild(emptyOpt);

            if (!fields || !fields.length) {
              fieldSelect.value = '0';
              return;
            }

            for (i = 0; i < fields.length; i++) {
              var f = fields[i];
              var opt = document.createElement('option');
              opt.value = String(f.id || 0);
              opt.appendChild(document.createTextNode((f.name || '').toString()));
              if (String(selectedId || '0') === opt.value) {
                opt.selected = true;
              }
              fieldSelect.appendChild(opt);
            }
            if (!fieldSelect.value) {
              fieldSelect.value = '0';
            }
          }

          function setLoadingState() {
            while (fieldSelect.firstChild) {
              fieldSelect.removeChild(fieldSelect.firstChild);
            }
            var opt = document.createElement('option');
            opt.value = '0';
            opt.appendChild(document.createTextNode(I18N.loadingFields));
            fieldSelect.appendChild(opt);
          }

          function fetchFields() {
            var selectedBefore = fieldSelect.value || '0';
            var mailboxId = mailboxSelect.value || '0';
            var xhr = new XMLHttpRequest();
            xhr.open('GET', fieldsUrl + '?mailbox_id=' + encodeURIComponent(mailboxId), true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
          xhr.setRequestHeader('Cache-Control', 'no-cache');
            setLoadingState();
            xhr.onreadystatechange = function () {
              var payload, fields;
              if (xhr.readyState !== 4) {
                return;
              }
              if (xhr.status < 200 || xhr.status >= 300) {
                setFieldOptions([], '0');
                return;
              }
              try {
                payload = JSON.parse(xhr.responseText || '{}');
              } catch (e) {
                payload = {};
              }
              fields = (payload && payload.fields && payload.fields.length) ? payload.fields : [];
              setFieldOptions(fields, selectedBefore);
            };
            xhr.send(null);
          }

          mailboxSelect.addEventListener('change', fetchFields);
        } catch (e) {
          // Never break the Smart Search page.
        }
      }

      function isTypingTarget(el) {
        if (!el) return false;
        var tag = (el.tagName || '').toLowerCase();
        return tag === 'input' || tag === 'textarea' || tag === 'select' || el.isContentEditable;
      }

      function debounce(fn, wait) {
        var t;
        return function () {
          var args = arguments;
          clearTimeout(t);
          t = setTimeout(function () {
            fn.apply(null, args);
          }, wait);
        };
      }

      // Last 5 searches (per browser) — simple, private, and useful.
      // v2 stores structured items so we can enrich "recent searches" when we
      // *know* a query led to a real conversation (Option A: confirmed-valid only).
      // Backward compatible with v1 (array of strings).
      var HISTORY_KEY = 'adam_smart_search_ui_recent_v2';

      function normalizeHistory(arr) {
        try {
          if (!Array.isArray(arr)) return [];
          var out = [];
          for (var i = 0; i < arr.length; i++) {
            var it = arr[i];
            if (!it) continue;
            // v1: string
            if (typeof it === 'string') {
              var qs = it.toString().trim();
              if (!qs) continue;
              out.push({ q: qs, ts: null, kind: 'q' });
              continue;
            }
            // v2: object
            if (typeof it === 'object') {
              var q = (it.q || '').toString().trim();
              if (!q) continue;
              out.push({
                q: q,
                ts: (typeof it.ts === 'number' ? it.ts : null),
                kind: (it.kind || 'q'),
                conv: it.conv || null,
                refreshed_at: (typeof it.refreshed_at === 'number' ? it.refreshed_at : null)
              });
            }
          }
          return out;
        } catch (e) {
          return [];
        }
      }

      function loadHistory() {
        try {
          if (!window.localStorage) return [];
          var raw = window.localStorage.getItem(HISTORY_KEY);
          // Also read legacy key if present.
          if (!raw) {
            raw = window.localStorage.getItem('adam_smart_search_ui_recent_v1');
          }
          if (!raw) return [];
          var arr = JSON.parse(raw);
          return normalizeHistory(arr);
        } catch (e) {
          return [];
        }
      }

      function saveHistory(arr) {
        try {
          if (!window.localStorage) return;
          window.localStorage.setItem(HISTORY_KEY, JSON.stringify(arr || []));
        } catch (e) {}
      }

      function rememberHistory(item) {
        try {
          if (!item) return;
          var q = (item.q || '').toString().trim();
          if (!q || q.length < 2) return;

          var arr = loadHistory();
          var ql = q.toLowerCase();

          // Prepend new item, dedupe by query (case-insensitive).
          var next = [];
          next.push({
            q: q,
            ts: (typeof item.ts === 'number' ? item.ts : Date.now()),
            kind: item.kind || 'q',
            conv: item.conv || null,
            refreshed_at: (typeof item.refreshed_at === 'number' ? item.refreshed_at : null)
          });
          for (var i = 0; i < arr.length; i++) {
            var it = arr[i];
            if (!it || !it.q) continue;
            if (it.q.toLowerCase() === ql) continue;
            next.push(it);
            if (next.length >= 5) break;
          }
          saveHistory(next.slice(0, 5));
        } catch (e) {}
      }

      function rememberQuery(q) {
        rememberHistory({ q: q, kind: 'q', ts: Date.now(), conv: null });
      }

      var historyRefreshInFlight = false;

      function isRefreshableHistoryConversation(it) {
        try {
          return !!(it && it.kind === 'conv' && it.conv && it.conv.id);
        } catch (e) {
          return false;
        }
      }

      function refreshHistoryConversations(done) {
        try {
          if (historyRefreshInFlight || !recentMetaUrl || !window.localStorage) {
            if (typeof done === 'function') done(false);
            return;
          }

          var arr = loadHistory();
          if (!arr || !arr.length) {
            if (typeof done === 'function') done(false);
            return;
          }

          var ids = [];
          var touched = false;
          for (var i = 0; i < arr.length; i++) {
            var it = arr[i];
            if (!isRefreshableHistoryConversation(it)) {
              continue;
            }
            touched = true;
            var convId = parseInt((it.conv && it.conv.id) || 0, 10);
            if (convId > 0 && ids.indexOf(convId) < 0) {
              ids.push(convId);
            }
          }

          if (!touched || !ids.length) {
            if (typeof done === 'function') done(false);
            return;
          }

          historyRefreshInFlight = true;
          var xhr = new XMLHttpRequest();
          var cacheBust = 'ts=' + encodeURIComponent(String(Date.now()));
          var joiner = recentMetaUrl.indexOf('?') >= 0 ? '&' : '?';
          xhr.open('GET', recentMetaUrl + joiner + 'ids=' + encodeURIComponent(ids.join(',')) + '&' + cacheBust, true);
          xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
          xhr.setRequestHeader('Cache-Control', 'no-cache');
          xhr.onreadystatechange = function () {
            var payload = {};
            var byId = {};
            var changed = false;
            var nowTs = Date.now();

            if (xhr.readyState !== 4) {
              return;
            }

            historyRefreshInFlight = false;

            if (xhr.status < 200 || xhr.status >= 300) {
              if (typeof done === 'function') done(false);
              return;
            }

            try {
              payload = JSON.parse(xhr.responseText || '{}');
            } catch (e) {
              payload = {};
            }

            var items = (payload && payload.items && payload.items.length) ? payload.items : [];
            for (var j = 0; j < items.length; j++) {
              var meta = items[j] || {};
              var metaId = parseInt(meta.id || 0, 10);
              if (metaId > 0) {
                byId[metaId] = meta;
              }
            }

            for (var k = 0; k < arr.length; k++) {
              var hist = arr[k];
              if (!hist || hist.kind !== 'conv' || !hist.conv || !hist.conv.id) {
                continue;
              }
              var histId = parseInt(hist.conv.id || 0, 10);
              if (histId <= 0 || !isRefreshableHistoryConversation(hist)) {
                continue;
              }

              hist.refreshed_at = nowTs;
              if (byId[histId]) {
                hist.conv = {
                  id: byId[histId].id || hist.conv.id || null,
                  url: byId[histId].url || hist.conv.url || null,
                  subject: byId[histId].subject || hist.conv.subject || hist.q || '',
                  mailbox_name: byId[histId].mailbox_name || '',
                  updated_human: byId[histId].updated_human || '',
                  status_name: byId[histId].status_name || '',
                  status_class: byId[histId].status_class || ''
                };
                changed = true;
              }
            }

            if (changed) {
              saveHistory(arr);
            }
            if (typeof done === 'function') done(changed);
          };
          xhr.send(null);
        } catch (e) {
          historyRefreshInFlight = false;
          if (typeof done === 'function') done(false);
        }
      }

      function rememberConversationFromSuggest(q, it) {
        try {
          if (!it) {
            rememberQuery(q);
            return;
          }
          rememberHistory({
            q: q,
            kind: 'conv',
            ts: Date.now(),
            conv: {
              id: it.id || null,
              url: it.url || null,
              subject: it.subject || '',
              mailbox_name: it.mailbox_name || '',
              updated_human: it.updated_human || '',
              status_name: it.status_name || '',
              status_class: it.status_class || ''
            },
            refreshed_at: Date.now()
          });
        } catch (e) {
          rememberQuery(q);
        }
      }

      bindSmartSearchMailboxFields();

      function ensureInlineTopbarSearch() {
        try {
          if (!showInline || !smartUrl) {
            return;
          }

          // Insert next to the notifications icon (right side navbar)
          var navRight = document.querySelector('ul.nav.navbar-nav.navbar-right');
          if (!navRight) {
            return;
          }

          // Avoid duplicates
          if (document.getElementById('adamsmartsearchui-inline-li')) {
            return;
          }

          var notifLi = navRight.querySelector('li.web-notifications');

          var li = document.createElement('li');
          li.id = 'adamsmartsearchui-inline-li';
          li.className = 'adamsmartsearchui-inline';

          // Bootstrap/FreeScout-friendly inline form
          (function buildInlineForm() {
            var form = document.createElement('form');
            var group = document.createElement('div');
            var input = document.createElement('input');
            var btnWrap = document.createElement('span');
            var searchButton = document.createElement('button');
            var searchIcon = document.createElement('i');
            var moreButton = document.createElement('button');
            var moreIcon = document.createElement('i');
            var hiddenSubmit = document.createElement('button');

            form.className = 'navbar-form adamsmartsearchui-inline-form';
            form.setAttribute('role', 'search');
            form.setAttribute('method', 'GET');
            form.setAttribute('action', smartUrl);

            group.className = 'input-group input-group-sm';

            input.type = 'text';
            input.className = 'form-control adamsmartsearchui-inline-input';
            input.name = 'q';
            input.autocomplete = 'off';
            input.placeholder = I18N.inlinePlaceholder;

            btnWrap.className = 'input-group-btn';

            searchButton.className = 'btn btn-default adamsmartsearchui-search-btn';
            searchButton.type = 'button';
            searchButton.setAttribute('aria-label', I18N.focusSearch);
            searchButton.setAttribute('title', I18N.focusSearch);
            searchIcon.className = 'glyphicon glyphicon-search';
            searchButton.appendChild(searchIcon);

            moreButton.className = 'btn btn-default adamsmartsearchui-more';
            moreButton.type = 'button';
            moreButton.setAttribute('aria-label', I18N.openSmartSearch);
            moreButton.setAttribute('title', I18N.openSmartSearch);
            moreIcon.className = 'glyphicon glyphicon-option-vertical';
            moreButton.appendChild(moreIcon);

            hiddenSubmit.type = 'submit';
            hiddenSubmit.className = 'hidden';
            hiddenSubmit.setAttribute('tabindex', '-1');
            hiddenSubmit.setAttribute('aria-hidden', 'true');

            btnWrap.appendChild(searchButton);
            btnWrap.appendChild(moreButton);
            group.appendChild(input);
            group.appendChild(btnWrap);
            form.appendChild(group);
            form.appendChild(hiddenSubmit);
            li.appendChild(form);
          })();

          if (notifLi && notifLi.parentNode === navRight) {
            // Place it right after notifications
            if (notifLi.nextSibling) {
              navRight.insertBefore(li, notifLi.nextSibling);
            } else {
              navRight.appendChild(li);
            }
          } else {
            // Fallback: prepend to right nav
            navRight.insertBefore(li, navRight.firstChild);
          }

          // Autosuggest dropdown (lightweight).
          try {
            var formEl = li.querySelector('form.adamsmartsearchui-inline-form');
            var inputEl = li.querySelector('input.adamsmartsearchui-inline-input');
            var moreBtn = li.querySelector('button.adamsmartsearchui-more');
            var searchBtn = li.querySelector('button.adamsmartsearchui-search-btn');
            if (formEl && inputEl) {
              // "More" always opens the Smart Search page (dumb-proof escape hatch).
              if (moreBtn) {
                moreBtn.addEventListener('click', function (ev) {
                  try { ev.preventDefault(); } catch (e) {}
                  var q = (inputEl.value || '').toString().trim();
                  var url = smartUrl;
                  if (q) {
                    url = smartUrl + (smartUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
                    rememberQuery(q);
                  }
                  window.location.href = url;
                });
              }

              // Attach dropdown to the form (not the <li>) so its width matches
              // the input-group precisely (fixes subtle misalignment in some navbars).
              var dd = document.createElement('div');
              // Mark as expandable on hover (desktop-only via CSS).
              dd.className = 'dropdown-menu adamsmartsearchui-suggest adamsmartsearchui-suggest-expandable';
              dd.style.display = 'none';
              formEl.appendChild(dd);

              function esc(s) {
                return (s || '').toString()
                  .replace(/&/g, '&amp;')
                  .replace(/</g, '&lt;')
                  .replace(/>/g, '&gt;')
                  .replace(/"/g, '&quot;')
                  .replace(/'/g, '&#039;');
              }

              // Magnifier is intentionally NOT a navigation affordance.
              if (searchBtn) {
                searchBtn.addEventListener('click', function (ev) {
                  try { ev.preventDefault(); } catch (e) {}
                  try { inputEl.focus(); } catch (e) {}
                });
              }

              var activeIndex = -1;
              var lastItems = [];
              var abortCtl = null;

              function setActive(idx) {
                var links = dd.querySelectorAll('.adamsmartsearchui-suggest-item');
                for (var j = 0; j < links.length; j++) {
                  if (j === idx) links[j].classList.add('active');
                  else links[j].classList.remove('active');
                }
                activeIndex = idx;
              }


              function hideDd() {
                dd.style.display = 'none';
                clearNode(dd);
                activeIndex = -1;
                lastItems = [];
                if (abortCtl) { try { abortCtl.abort(); } catch(e) {} }
                abortCtl = null;
              }

              function appendText(parent, text) {
                parent.appendChild(document.createTextNode((text || '').toString()));
              }

              function appendHighlightedText(parent, text, rawQuery) {
                try {
                  text = (text || '').toString();
                  rawQuery = (rawQuery || '').toString().trim();
                  if (!rawQuery || rawQuery.length < 2) {
                    appendText(parent, text);
                    return;
                  }
                  var q = rawQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                  var re = new RegExp(q, 'ig');
                  var lastIndex = 0;
                  var match;
                  while ((match = re.exec(text)) !== null) {
                    if (match.index > lastIndex) {
                      appendText(parent, text.slice(lastIndex, match.index));
                    }
                    var hl = document.createElement('span');
                    hl.className = 'adamsmartsearchui-hl';
                    appendText(hl, match[0]);
                    parent.appendChild(hl);
                    lastIndex = match.index + match[0].length;
                    if (match[0].length === 0) {
                      break;
                    }
                  }
                  if (lastIndex < text.length) {
                    appendText(parent, text.slice(lastIndex));
                  }
                } catch (e) {
                  appendText(parent, text);
                }
              }

              function buildSuggestItem(opts) {
                var link = document.createElement('a');
                var num = document.createElement('span');
                var main = document.createElement('span');
                var row1 = document.createElement('span');
                var subj = document.createElement('span');

                link.className = 'adamsmartsearchui-suggest-item' + (opts.extraClass ? (' ' + opts.extraClass) : '');
                link.setAttribute('data-kind', opts.kind || 'conv');
                link.setAttribute('href', (opts.href || '').toString());
                if (typeof opts.idx === 'number') {
                  link.setAttribute('data-idx', String(opts.idx));
                }

                num.className = 'adamsmartsearchui-suggest-num';
                if (opts.numIconClass) {
                  var icon = document.createElement('i');
                  icon.className = opts.numIconClass;
                  num.appendChild(icon);
                } else {
                  appendText(num, opts.numText || '');
                }

                main.className = 'adamsmartsearchui-suggest-main';
                row1.className = 'adamsmartsearchui-suggest-row1';
                subj.className = 'adamsmartsearchui-suggest-subj';
                if (opts.highlight) {
                  appendHighlightedText(subj, opts.subjectText || '', opts.highlightQuery || '');
                } else {
                  appendText(subj, opts.subjectText || '');
                }
                row1.appendChild(subj);

                if (opts.statusName) {
                  var safeStatusClass = ((opts.statusClass || 'default').toString().replace(/[^a-z0-9_-]/ig, '') || 'default');
                  var badge = document.createElement('span');
                  badge.className = 'label label-' + safeStatusClass + ' adamsmartsearchui-suggest-status';
                  appendText(badge, opts.statusName);
                  row1.appendChild(badge);
                }

                if (opts.hintText) {
                  var hint = document.createElement('span');
                  hint.className = 'adamsmartsearchui-suggest-hint';
                  appendText(hint, opts.hintText);
                  row1.appendChild(hint);
                }

                main.appendChild(row1);

                if (opts.metaText) {
                  var row2 = document.createElement('span');
                  row2.className = 'adamsmartsearchui-suggest-row2';
                  appendText(row2, opts.metaText);
                  main.appendChild(row2);
                }

                link.appendChild(num);
                link.appendChild(main);
                return link;
              }

              function appendSeparator(parent) {
                var sep = document.createElement('div');
                sep.className = 'adamsmartsearchui-suggest-sep';
                parent.appendChild(sep);
              }

              function appendHeading(parent, text) {
                var head = document.createElement('div');
                head.className = 'adamsmartsearchui-suggest-head';
                appendText(head, text || '');
                parent.appendChild(head);
              }

              function renderDd(items) {
                items = items || [];
                var qNow = (inputEl && inputEl.value) ? (inputEl.value || '') : '';
                var qTrim = (qNow || '').toString().trim();
                var frag = document.createDocumentFragment();
                var any = false;

                clearNode(dd);
                appendHeading(frag, I18N.suggestions || 'Suggestions');

                if (smartUrl && qTrim) {
                  frag.appendChild(buildSuggestItem({
                    kind: 'searchall',
                    href: smartUrl + (smartUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(qTrim),
                    extraClass: 'adamsmartsearchui-suggest-searchall',
                    numIconClass: 'glyphicon glyphicon-search',
                    subjectText: (I18N.searchSmartFor || '').replace(':q', qTrim),
                    highlight: true,
                    highlightQuery: qNow,
                    hintText: I18N.enter || 'Enter'
                  }));
                  appendSeparator(frag);
                }

                for (var i = 0; i < items.length; i++) {
                  var it = items[i] || {};
                  var url = (it.url || '').toString();
                  if (!url) {
                    continue;
                  }
                  any = true;
                  var mb = (it.mailbox_name || '').toString();
                  var upd = (it.updated_human || '').toString();
                  var meta = '';
                  if (mb && upd) meta = mb + ' • ' + upd;
                  else if (mb) meta = mb;
                  else if (upd) meta = upd;

                  frag.appendChild(buildSuggestItem({
                    kind: 'conv',
                    idx: i,
                    href: url,
                    numText: '#' + (it.id || '').toString(),
                    subjectText: (it.subject || '').toString(),
                    highlight: true,
                    highlightQuery: qNow,
                    statusName: (it.status_name || '').toString(),
                    statusClass: (it.status_class || '').toString(),
                    metaText: meta
                  }));
                }

                if (!any && !(smartUrl && qTrim)) {
                  hideDd();
                  return;
                }
                dd.appendChild(frag);
                dd.style.display = 'block';
                lastItems = items || [];
                setActive(-1);
              }

              function renderHistoryList(arr) {
                arr = arr || [];
                if (!arr.length || !smartUrl) {
                  hideDd();
                  return;
                }
                clearNode(dd);
                var frag = document.createDocumentFragment();
                appendHeading(frag, I18N.recentSearches || 'Recent searches');
                appendSeparator(frag);
                var any = false;
                for (var i = 0; i < arr.length; i++) {
                  var hi = arr[i] || null;
                  var q = hi && hi.q ? hi.q.toString() : '';
                  if (!q) {
                    continue;
                  }
                  any = true;
                  var href = smartUrl + (smartUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
                  var conv = (hi && hi.kind === 'conv' && hi.conv) ? hi.conv : null;
                  if (conv && conv.url) {
                    var mb = (conv.mailbox_name || '').toString();
                    var upd = (conv.updated_human || '').toString();
                    var meta = '';
                    if (mb && upd) meta = mb + ' • ' + upd;
                    else if (mb) meta = mb;
                    else if (upd) meta = upd;
                    frag.appendChild(buildSuggestItem({
                      kind: 'history-conv',
                      href: (conv.url || href).toString(),
                      numText: '#' + (conv.id || '').toString(),
                      subjectText: (conv.subject || q).toString(),
                      statusName: (conv.status_name || '').toString(),
                      statusClass: (conv.status_class || '').toString(),
                      metaText: meta
                    }));
                  } else {
                    frag.appendChild(buildSuggestItem({
                      kind: 'history-q',
                      href: href,
                      subjectText: q
                    }));
                  }
                }
                if (!any) {
                  hideDd();
                  return;
                }
                dd.appendChild(frag);
                dd.style.display = 'block';
                lastItems = [];
                setActive(-1);
              }

              function renderHistoryLoading() {
                clearNode(dd);
                var frag = document.createDocumentFragment();
                appendHeading(frag, I18N.recentSearches || 'Recent searches');
                appendSeparator(frag);
                frag.appendChild(buildSuggestItem({
                  kind: 'history-loading',
                  href: '#',
                  numIconClass: 'glyphicon glyphicon-refresh',
                  subjectText: I18N.loadingRecent || 'Loading…',
                  extraClass: 'disabled'
                }));
                dd.appendChild(frag);
                dd.style.display = 'block';
                lastItems = [];
                setActive(-1);
              }

              function renderHistory() {
                try {
                  var arr = loadHistory();
                  if (!arr || !arr.length || !smartUrl) {
                    hideDd();
                    return;
                  }

                  var hasRefreshable = false;
                  for (var i = 0; i < arr.length; i++) {
                    if (isRefreshableHistoryConversation(arr[i])) {
                      hasRefreshable = true;
                      break;
                    }
                  }

                  if (!hasRefreshable || !recentMetaUrl) {
                    renderHistoryList(arr);
                    return;
                  }

                  renderHistoryLoading();
                  refreshHistoryConversations(function () {
                    try {
                      var qCurrent = ((inputEl && inputEl.value) || '').toString().trim();
                      if (qCurrent || document.activeElement !== inputEl) {
                        hideDd();
                        return;
                      }
                      renderHistoryList(loadHistory());
                    } catch (e) {
                      hideDd();
                    }
                  });
                } catch (e) {
                  hideDd();
                }
              }

              var fetchSuggest = debounce(function () {
                try {
                  var q = (inputEl.value || '').trim();
                  // Empty input: show last 5 searches.
                  if (!q) {
                    renderHistory();
                    return;
                  }
                  // Too short: hide dropdown (keep it simple).
                  if (q.length < 2 || !suggestUrl) {
                    hideDd();
                    return;
                  }
                  var url = suggestUrl + (suggestUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);
                  if (abortCtl) { try { abortCtl.abort(); } catch(e) {} }
                  abortCtl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
                  // Prefer fetch(), but fall back to jQuery if needed (older browsers).
                  if (typeof fetch === 'function') {
                    fetch(url, {
                      credentials: 'same-origin',
                      headers: { 'X-Requested-With': 'XMLHttpRequest' },
                      signal: abortCtl ? abortCtl.signal : undefined
                    })
                      .then(function (r) {
                        return r.json();
                      })
                      .then(function (data) {
                        renderDd((data && data.items) || []);
                      })
                      .catch(function () {
                        hideDd();
                      });
                  } else if (window.jQuery && typeof window.jQuery.getJSON === 'function') {
                    window.jQuery.getJSON(url)
                      .done(function (data) {
                        renderDd((data && data.items) || []);
                      })
                      .fail(function () {
                        hideDd();
                      });
                  } else {
                    hideDd();
                  }
                } catch (e) {
                  hideDd();
                }
              }, 180);

              inputEl.addEventListener('input', fetchSuggest);
              inputEl.addEventListener('focus', function () {
                // Re-render if user focuses back in.
                fetchSuggest();
              });

              // Store history on submit.
              formEl.addEventListener('submit', function () {
                try { rememberQuery(inputEl.value || ''); } catch (e) {}
              });

              inputEl.addEventListener('keydown', function (ev) {
                if (!ev) return;
                // ESC closes suggestions.
                if (ev.key === 'Escape') {
                  hideDd();
                  return;
                }
                if (dd.style.display !== 'block') return;

                var links = dd.querySelectorAll('.adamsmartsearchui-suggest-item');
                if (!links || !links.length) return;

                if (ev.key === 'ArrowDown') {
                  ev.preventDefault();
                  var next = activeIndex + 1;
                  if (next >= links.length) next = 0;
                  setActive(next);
                  return;
                }
                if (ev.key === 'ArrowUp') {
                  ev.preventDefault();
                  var prev = activeIndex - 1;
                  if (prev < 0) prev = links.length - 1;
                  setActive(prev);
                  return;
                }
                if (ev.key === 'Enter' && activeIndex >= 0) {
                  ev.preventDefault();
                  try {
                    var qNow2 = (inputEl.value || '').toString();
                    var kind2 = links[activeIndex].getAttribute('data-kind') || '';
                    if (kind2 === 'conv') {
                      var idx2 = parseInt(links[activeIndex].getAttribute('data-idx') || '-1', 10);
                      if (!isNaN(idx2) && idx2 >= 0 && lastItems && lastItems[idx2]) {
                        rememberConversationFromSuggest(qNow2, lastItems[idx2]);
                      } else {
                        rememberQuery(qNow2);
                      }
                    } else {
                      rememberQuery(qNow2);
                    }
                    window.location.href = links[activeIndex].getAttribute('href');
                  } catch(e) {}
                }
              });

              // Robust selection: navigate on mousedown so blur/hide can't cancel the click.
              // (Some FreeScout layouts re-render/hide navbar elements on blur/click.)
              dd.addEventListener('mousedown', function (ev) {
                try {
                  var a = ev && ev.target && ev.target.closest ? ev.target.closest('a.adamsmartsearchui-suggest-item') : null;
                  if (!a) {
                    // Still remember typed query when interacting with dropdown.
                    try { rememberQuery(inputEl.value || ''); } catch (e) {}
                    return;
                  }
                  ev.preventDefault();
                  ev.stopPropagation();
                  try {
                    var qNow3 = (inputEl.value || '').toString();
                    var kind = a.getAttribute('data-kind') || '';
                    if (kind === 'conv') {
                      var idx = parseInt(a.getAttribute('data-idx') || '-1', 10);
                      if (!isNaN(idx) && idx >= 0 && lastItems && lastItems[idx]) {
                        rememberConversationFromSuggest(qNow3, lastItems[idx]);
                      } else {
                        rememberQuery(qNow3);
                      }
                    } else {
                      rememberQuery(qNow3);
                    }
                  } catch (e) {
                    try { rememberQuery(inputEl.value || ''); } catch (e2) {}
                  }
                  var href = a.getAttribute('href');
                  if (href) {
                    window.location.href = href;
                  }
                } catch (e) {}
              });
              // Click-away hide
              document.addEventListener('click', function (ev) {
                try {
                  if (!li.contains(ev.target)) {
                    hideDd();
                  }
                } catch (e) {}
              });
              // Slight blur delay so interactions inside dropdown don't instantly close it.
              inputEl.addEventListener('blur', function () {
                setTimeout(function () {
                  try {
                    // If focus moved into the dropdown, keep it open.
                    var ae = document.activeElement;
                    if (ae && dd.contains(ae)) {
                      return;
                    }
                  } catch (e) {}
                  hideDd();
                }, 200);
              });
            }
          } catch (e) {
            // no-op
          }
        } catch (e) {
          // no-op
        }
      }

      // Core magnifier dropdown form
      var form = document.querySelector('form.form-nav-search');
      if (form) {
        if (hideCore) {
          // Hide the core magnifier icon/trigger when explicitly configured.
          var iconHide = document.getElementById('search-dt');
          if (iconHide) {
            iconHide.style.display = 'none';
            // Also hide the closest <li> if present (varies by FreeScout version/theme).
            try {
              var li2 = iconHide.closest && iconHide.closest('li');
              if (li2) li2.style.display = 'none';
            } catch (e) {}
          }
        }

        if (useCore) {
          if (smartUrl) {
            form.setAttribute('action', smartUrl);
          }
          form.removeAttribute('target');

          var input = form.querySelector('input[name="q"]');
          if (input) {
            input.setAttribute('placeholder', I18N.inlinePlaceholder || 'Search...');
          }
          var btn = form.querySelector('button[type="submit"]');
          if (btn) {
            btn.textContent = I18N.search || 'Search';
          }
        }
      }

      // Add the always-visible inline bar (right side).
      ensureInlineTopbarSearch();

      // Keyboard shortcut: '/' focuses the topbar search (like GitHub, Slack, etc.)
      document.addEventListener('keydown', function (ev) {
        try {
          if (!ev || ev.defaultPrevented) return;
          var isSlash = (ev.key === '/');
          var isCtrlK = (ev.key && (ev.key.toLowerCase ? ev.key.toLowerCase() : ev.key) === 'k') && (ev.ctrlKey || ev.metaKey);
          if (!isSlash && !isCtrlK) return;
          if (isSlash && (ev.metaKey || ev.ctrlKey || ev.altKey)) return;
          if (isTypingTarget(ev.target)) return;

          var inlineInput = document.querySelector('#adamsmartsearchui-inline-li input.adamsmartsearchui-inline-input');
          if (inlineInput) {
            ev.preventDefault();
            inlineInput.focus();
            inlineInput.select();
          }
        } catch (e) {}
      });

      // If user clicks the magnifier icon, focus the input.
      var icon = document.getElementById('search-dt');
      if (icon && form && useCore) {
        icon.addEventListener('click', function () {
          setTimeout(function () {
            try {
              var input = form.querySelector('input[name="q"]');
              if (input) input.focus();
            } catch (e) {}
          }, 50);
        });
      }
    } catch (e) {
      // never break UI
    }
  });
})();
