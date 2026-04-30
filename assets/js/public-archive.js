/* public-archive.js – SafetyFlash archive embed widget */
(function () {
  'use strict';

  var cfg = window.sfArchiveConfig || {};
  var apiUrl = cfg.apiUrl || '';
  var sitesUrl = cfg.sitesUrl || '';
  var token = cfg.token || '';
  var perPage = cfg.perPage || 12;

  var grid = document.getElementById('sf-flash-grid');
  var siteSelect = document.getElementById('sf-filter-site');
  var searchInput = document.getElementById('sf-filter-q');
  var fromInput = document.getElementById('sf-filter-from');
  var toInput = document.getElementById('sf-filter-to');
  var applyBtn = document.getElementById('sf-filter-apply');
  var clearBtn = document.getElementById('sf-filter-clear');
  var loadingEl = document.getElementById('sf-loading');
  var emptyEl = document.getElementById('sf-empty');
  var paginationEl = document.getElementById('sf-pagination');
  var modal = document.getElementById('sf-modal');
  var modalBackdrop = document.getElementById('sf-modal-backdrop');
  var modalClose = document.getElementById('sf-modal-close');
  var modalImage = document.getElementById('sf-modal-image');
  var modalTitle = document.getElementById('sf-modal-title');
  var modalMeta = document.getElementById('sf-modal-meta');
  var modalSummary = document.getElementById('sf-modal-summary');

  var currentPage = 1;
  var debounceTimer = null;

  // ---- Load worksites -------------------------------------------------------
  function loadSites() {
    if (!sitesUrl || !token) return;
    var url = sitesUrl + '?t=' + encodeURIComponent(token);
    fetch(url, { credentials: 'omit' })
      .then(function (r) { return r.ok ? r.json() : { sites: [] }; })
      .then(function (data) {
        (data.sites || []).forEach(function (s) {
          var opt = document.createElement('option');
          opt.value = s.name || String(s.id);
          opt.textContent = s.name || String(s.id);
          siteSelect.appendChild(opt);
        });
      })
      .catch(function () {});
  }

  // ---- Build query URL ------------------------------------------------------
  function buildUrl(page) {
    var params = new URLSearchParams({
      t: token,
      p: String(page),
      per_page: String(perPage)
    });

    var site = siteSelect ? siteSelect.value.trim() : '';
    var q = searchInput ? searchInput.value.trim() : '';
    var from = fromInput ? fromInput.value.trim() : '';
    var to = toInput ? toInput.value.trim() : '';

    if (site) params.set('site', site);
    if (q) params.set('q', q);
    if (from) params.set('from', from);
    if (to) params.set('to', to);

    return apiUrl + '?' + params.toString();
  }

  // ---- Fetch flashes --------------------------------------------------------
  function loadFlashes(page) {
    page = page || 1;
    currentPage = page;

    showLoading();

    fetch(buildUrl(page), { credentials: 'omit' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        hideLoading();
        renderGrid(data.items || []);
        renderPagination(data.total_pages || 1, data.page || 1);

        if ((data.items || []).length === 0) {
          if (emptyEl) emptyEl.hidden = false;
        } else {
          if (emptyEl) emptyEl.hidden = true;
        }
      })
      .catch(function () {
        hideLoading();
        if (grid) grid.innerHTML = '';
        if (emptyEl) emptyEl.hidden = false;
      });
  }

  // ---- Render grid ----------------------------------------------------------
  function renderGrid(items) {
    if (!grid) return;
    grid.innerHTML = '';

    items.forEach(function (item) {
      var card = document.createElement('div');
      card.className = 'sf-card';
      card.setAttribute('tabindex', '0');
      card.setAttribute('role', 'button');
      card.setAttribute('aria-label', escapeText(item.title || ''));

      var imgWrap = document.createElement('div');
      imgWrap.className = 'sf-card__image-wrap';

      var img = document.createElement('img');
      img.className = 'sf-card__image';
      img.src = item.cover_image_url || '';
      img.alt = escapeText(item.title || '');
      img.loading = 'lazy';
      imgWrap.appendChild(img);
      card.appendChild(imgWrap);

      var body = document.createElement('div');
      body.className = 'sf-card__body';

      var titleEl = document.createElement('div');
      titleEl.className = 'sf-card__title';
      titleEl.textContent = item.title || '';
      body.appendChild(titleEl);

      var metaEl = document.createElement('div');
      metaEl.className = 'sf-card__meta';
      metaEl.textContent = formatMeta(item);
      body.appendChild(metaEl);

      if (item.summary) {
        var summaryEl = document.createElement('div');
        summaryEl.className = 'sf-card__summary';
        summaryEl.textContent = item.summary;
        body.appendChild(summaryEl);
      }

      card.appendChild(body);
      grid.appendChild(card);

      card.addEventListener('click', function () { openModal(item); });
      card.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openModal(item); }
      });
    });
  }

  // ---- Pagination -----------------------------------------------------------
  function renderPagination(total, page) {
    if (!paginationEl) return;
    paginationEl.innerHTML = '';

    if (total <= 1) return;

    function makeBtn(label, targetPage, disabled, active) {
      var btn = document.createElement('button');
      btn.className = 'sf-page-btn' + (active ? ' is-active' : '');
      btn.textContent = label;
      btn.disabled = disabled;
      if (!disabled) {
        btn.addEventListener('click', function () { loadFlashes(targetPage); });
      }
      return btn;
    }

    paginationEl.appendChild(makeBtn('‹', page - 1, page === 1, false));

    var start = Math.max(1, page - 2);
    var end   = Math.min(total, page + 2);

    for (var i = start; i <= end; i++) {
      paginationEl.appendChild(makeBtn(String(i), i, false, i === page));
    }

    paginationEl.appendChild(makeBtn('›', page + 1, page === total, false));
  }

  // ---- Modal ----------------------------------------------------------------
  function openModal(item) {
    modalImage.src = item.cover_image_url || '';
    modalImage.alt = item.title || '';
    modalTitle.textContent = item.title || '';
    modalMeta.textContent = formatMeta(item);
    if (modalSummary) modalSummary.textContent = item.summary || '';

    modal.hidden = false;
    modalClose.focus();
    document.addEventListener('keydown', handleModalKey);
  }

  function closeModal() {
    modal.hidden = true;
    document.removeEventListener('keydown', handleModalKey);
  }

  function handleModalKey(e) {
    if (e.key === 'Escape') closeModal();
  }

  // ---- Filter events --------------------------------------------------------
  if (applyBtn) applyBtn.addEventListener('click', function () { loadFlashes(1); });

  if (clearBtn) clearBtn.addEventListener('click', function () {
    if (siteSelect) siteSelect.value = '';
    if (searchInput) searchInput.value = '';
    if (fromInput) fromInput.value = '';
    if (toInput) toInput.value = '';
    loadFlashes(1);
  });

  if (searchInput) {
    searchInput.addEventListener('input', function () {
      clearTimeout(debounceTimer);
      debounceTimer = setTimeout(function () { loadFlashes(1); }, 450);
    });
  }

  // ---- Modal wiring ---------------------------------------------------------
  if (modalClose) modalClose.addEventListener('click', closeModal);
  if (modalBackdrop) modalBackdrop.addEventListener('click', closeModal);

  // ---- State helpers --------------------------------------------------------
  function showLoading() {
    if (loadingEl) loadingEl.style.display = 'flex';
    if (emptyEl) emptyEl.hidden = true;
  }

  function hideLoading() {
    if (loadingEl) loadingEl.style.display = 'none';
  }

  // ---- Utilities ------------------------------------------------------------
  function formatMeta(item) {
    var parts = [];
    if (item.site_name) parts.push(item.site_name);
    if (item.occurred_at) parts.push(formatDate(item.occurred_at));
    return parts.join(' · ');
  }

  function formatDate(str) {
    if (!str) return '';
    var d = new Date(str);
    if (isNaN(d.getTime())) return str;
    return d.toLocaleDateString('fi-FI');
  }

  function escapeText(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  // ---- Init -----------------------------------------------------------------
  loadSites();
  loadFlashes(1);
})();
