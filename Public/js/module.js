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
                conv: it.conv || null
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
            conv: item.conv || null
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
            }
          });
        } catch (e) {
          rememberQuery(q);
        }
      }

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
          li.innerHTML =
            '<form class="navbar-form adamsmartsearchui-inline-form" role="search" method="GET" action="' +
            smartUrl +
            '">' +
            '<div class="input-group input-group-sm">' +
            '<input type="text" class="form-control adamsmartsearchui-inline-input" name="q" autocomplete="off" placeholder="Search...">' +
            '<span class="input-group-btn">' +
            // We keep ONE clear way into Smart Search: press Enter or click the ⋮ menu.
            // The magnifier button is intentionally non-submitting (focus-only).
            '<button class="btn btn-default adamsmartsearchui-search-btn" type="button" aria-label="Focus search" title="Focus search"><i class="glyphicon glyphicon-search"></i></button>' +
            '<button class="btn btn-default adamsmartsearchui-more" type="button" aria-label="Open Smart Search" title="Open Smart Search"><i class="glyphicon glyphicon-option-vertical"></i></button>' +
            '</span>' +
            '</div>' +
            // Hidden submit ensures Enter submits reliably across browsers.
            '<button type="submit" class="hidden" tabindex="-1" aria-hidden="true"></button>' +
            '</form>';

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
                dd.innerHTML = '';
                activeIndex = -1;
                lastItems = [];
                if (abortCtl) { try { abortCtl.abort(); } catch(e) {} }
                abortCtl = null;
              }

              function renderDd(items) {
                // Always provide a "Search Smart for …" entry when user typed something,
                // even if there are no suggestions.
                items = items || [];

                function escapeHtml(s) {
                  s = (s || '').toString();
                  return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                }

                function highlight(escapedText, rawQuery) {
                  try {
                    rawQuery = (rawQuery || '').toString().trim();
                    if (!rawQuery || rawQuery.length < 2) return escapedText;
                    // Escape regex special chars.
                    var q = rawQuery.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
                    var re = new RegExp('(' + q + ')', 'ig');
                    return escapedText.replace(re, '<span class="adamsmartsearchui-hl">$1</span>');
                  } catch (e) {
                    return escapedText;
                  }
                }

                var qNow = (inputEl && inputEl.value) ? (inputEl.value || '') : '';
                var qTrim = (qNow || '').toString().trim();
                var html = '<div class="adamsmartsearchui-suggest-head">Suggestions</div>';

                if (smartUrl && qTrim) {
                  var hrefAll = smartUrl + (smartUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(qTrim);
                  var safeQ = escapeHtml(qTrim);
                  html +=
                    '<a class="adamsmartsearchui-suggest-item adamsmartsearchui-suggest-searchall" data-kind="searchall" href="' + hrefAll + '">' +
                      '<span class="adamsmartsearchui-suggest-num"><i class="glyphicon glyphicon-search"></i></span>' +
                      '<span class="adamsmartsearchui-suggest-main">' +
                        '<span class="adamsmartsearchui-suggest-row1">' +
                          '<span class="adamsmartsearchui-suggest-subj">Search Smart for “' + safeQ + '”</span>' +
                          '<span class="adamsmartsearchui-suggest-hint">Enter</span>' +
                        '</span>' +
                      '</span>' +
                    '</a>' +
                    '<div class="adamsmartsearchui-suggest-sep"></div>';
                }
                var any = false;
                for (var i = 0; i < items.length; i++) {
                  var it = items[i] || {};
                  var subj = (it.subject || '').toString();
                  var num = (it.id || '').toString();
                  var url = (it.url || '').toString();
                  var mb = (it.mailbox_name || '').toString();
                  var upd = (it.updated_human || '').toString();
                  var stName = (it.status_name || '').toString();
                  var stClass = (it.status_class || '').toString();
                  if (!url) continue;
                  any = true;
                  subj = highlight(escapeHtml(subj), qNow);
                  num = escapeHtml(num);
                  mb = escapeHtml(mb);
                  upd = escapeHtml(upd);
                  stName = escapeHtml(stName);
                  stClass = escapeHtml(stClass);

                  var badge = '';
                  if (stName) {
                    // FreeScout uses bootstrap label-* classes (label-success, label-danger, etc.)
                    // Some themes may use badge, but label is the safest baseline.
                    badge = '<span class="label label-' + (stClass || 'default') + ' adamsmartsearchui-suggest-status">' + stName + '</span>';
                  }

                  var meta = '';
                  if (mb && upd) meta = mb + ' • ' + upd;
                  else if (mb) meta = mb;
                  else if (upd) meta = upd;

                  html +=
                    '<a class="adamsmartsearchui-suggest-item" data-kind="conv" data-idx="' + i + '" href="' +
                    url +
                    '">' +
                    '<span class="adamsmartsearchui-suggest-num">#' + num + '</span>' +
                    '<span class="adamsmartsearchui-suggest-main">' +
                      '<span class="adamsmartsearchui-suggest-row1">' +
                        '<span class="adamsmartsearchui-suggest-subj">' + subj + '</span>' +
                        badge +
                      '</span>' +
                      (meta ? ('<span class="adamsmartsearchui-suggest-row2">' + meta + '</span>') : '') +
                    '</span>' +
                    '</a>';
                }
                // If there are no suggestion items, still show the search-all entry.
                if (!any && !(smartUrl && qTrim)) {
                  hideDd();
                  return;
                }
                dd.innerHTML = html;
                dd.style.display = 'block';
                lastItems = items || [];
                setActive(-1);
              }

              function renderHistory() {
                try {
                  var arr = loadHistory();
                  if (!arr || !arr.length || !smartUrl) {
                    hideDd();
                    return;
                  }
                  var html = '<div class="adamsmartsearchui-suggest-head">Recent searches</div><div class="adamsmartsearchui-suggest-sep"></div>';
                  var any = false;
                  for (var i = 0; i < arr.length; i++) {
                    var hi = arr[i] || null;
                    var q = hi && hi.q ? hi.q.toString() : '';
                    if (!q) continue;
                    any = true;
                    var safe = q.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                    var href = smartUrl + (smartUrl.indexOf('?') >= 0 ? '&' : '?') + 'q=' + encodeURIComponent(q);

                    // Enrich only when we have confirmed conversation metadata.
                    var conv = (hi && hi.kind === 'conv' && hi.conv) ? hi.conv : null;
                    if (conv && conv.url) {
                      var subj = (conv.subject || safe).toString();
                      var num = (conv.id || '').toString();
                      var mb = (conv.mailbox_name || '').toString();
                      var upd = (conv.updated_human || '').toString();
                      var stName = (conv.status_name || '').toString();
                      var stClass = (conv.status_class || '').toString();

                      function eh(s) {
                        s = (s || '').toString();
                        return s.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                      }

                      var badge = '';
                      if (stName) {
                        badge = '<span class="label label-' + (eh(stClass) || 'default') + ' adamsmartsearchui-suggest-status">' + eh(stName) + '</span>';
                      }
                      var meta = '';
                      if (mb && upd) meta = eh(mb) + ' • ' + eh(upd);
                      else if (mb) meta = eh(mb);
                      else if (upd) meta = eh(upd);

                      html +=
                        '<a class="adamsmartsearchui-suggest-item" data-kind="history-conv" href="' + eh(conv.url) + '">' +
                          '<span class="adamsmartsearchui-suggest-num">#' + eh(num) + '</span>' +
                          '<span class="adamsmartsearchui-suggest-main">' +
                            '<span class="adamsmartsearchui-suggest-row1">' +
                              '<span class="adamsmartsearchui-suggest-subj">' + eh(subj) + '</span>' +
                              badge +
                            '</span>' +
                            (meta ? ('<span class="adamsmartsearchui-suggest-row2">' + meta + '</span>') : '') +
                          '</span>' +
                        '</a>';
                    } else {
                      // Plain query fallback (unknown validity)
                      html += '<a class="adamsmartsearchui-suggest-item" data-kind="history-q" href="' + href + '">' +
                        '<span class="adamsmartsearchui-suggest-subj">' + safe + '</span>' +
                        '</a>';
                    }
                  }
                  if (!any) {
                    hideDd();
                    return;
                  }
                  dd.innerHTML = html;
                  dd.style.display = 'block';
                  lastItems = [];
                  setActive(-1);
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
            input.setAttribute('placeholder', 'Search...');
          }
          var btn = form.querySelector('button[type="submit"]');
          if (btn) {
            btn.textContent = 'Search';
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
