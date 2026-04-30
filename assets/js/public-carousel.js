/* public-carousel.js – SafetyFlash carousel embed widget */
(function () {
  'use strict';

  var cfg = window.sfCarouselConfig || {};
  var apiUrl = cfg.apiUrl || '';
  var token = cfg.token || '';
  var intervalMs = (cfg.interval || 15) * 1000;
  var refreshMs = cfg.refreshInterval || 300000;

  var track = document.getElementById('sf-carousel-track');
  var dotsEl = document.getElementById('sf-dots');
  var prevBtn = document.getElementById('sf-prev');
  var nextBtn = document.getElementById('sf-next');
  var loadingEl = document.getElementById('sf-loading');
  var emptyEl = document.getElementById('sf-empty');
  var modal = document.getElementById('sf-modal');
  var modalBackdrop = document.getElementById('sf-modal-backdrop');
  var modalClose = document.getElementById('sf-modal-close');
  var modalImage = document.getElementById('sf-modal-image');
  var modalTitle = document.getElementById('sf-modal-title');
  var modalMeta = document.getElementById('sf-modal-meta');

  var slides = [];
  var current = 0;
  var timer = null;
  var prefersReduced = window.matchMedia
    ? window.matchMedia('(prefers-reduced-motion: reduce)').matches
    : false;

  // ---- Fetch data -----------------------------------------------------------
  function load() {
    if (!apiUrl || !token) {
      showEmpty();
      return;
    }

    var url = apiUrl + (apiUrl.indexOf('?') >= 0 ? '&' : '?') + 't=' + encodeURIComponent(token);

    fetch(url, { credentials: 'omit' })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
      })
      .then(function (data) {
        var items = data.items || [];
        if (items.length === 0) {
          showEmpty();
          return;
        }
        buildSlides(items);
        hideLoading();
        goTo(0);
        if (!prefersReduced) startAuto();
      })
      .catch(function () {
        showEmpty();
      });
  }

  // ---- Build DOM slides -----------------------------------------------------
  function buildSlides(items) {
    slides = items;
    track.innerHTML = '';
    dotsEl.innerHTML = '';

    items.forEach(function (item, i) {
      // Slide
      var slide = document.createElement('div');
      slide.className = 'sf-carousel__slide';
      slide.setAttribute('data-index', String(i));
      slide.setAttribute('tabindex', '0');
      slide.setAttribute('role', 'button');
      slide.setAttribute('aria-label', escapeAttr(item.title || ''));

      if (item.cover_image_url) {
        var img = document.createElement('img');
        img.className = 'sf-carousel__slide-img';
        img.src = item.cover_image_url;
        img.alt = escapeAttr(item.title || '');
        img.loading = 'lazy';
        slide.appendChild(img);
      }

      var caption = document.createElement('div');
      caption.className = 'sf-carousel__slide-caption';

      var titleEl = document.createElement('div');
      titleEl.className = 'sf-carousel__slide-title';
      titleEl.textContent = item.title || '';
      caption.appendChild(titleEl);

      var meta = document.createElement('div');
      meta.className = 'sf-carousel__slide-meta';
      meta.textContent = formatMeta(item);
      caption.appendChild(meta);

      slide.appendChild(caption);
      track.appendChild(slide);

      slide.addEventListener('click', function () { openModal(i); });
      slide.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openModal(i); }
      });

      // Dot
      var dot = document.createElement('button');
      dot.className = 'sf-carousel__dot';
      dot.setAttribute('aria-label', 'Siirry kohtaan ' + (i + 1));
      dot.addEventListener('click', function () { goTo(i); resetAuto(); });
      dotsEl.appendChild(dot);
    });
  }

  // ---- Navigation -----------------------------------------------------------
  function goTo(idx) {
    var len = slides.length;
    if (len === 0) return;

    current = (idx + len) % len;

    var slideEls = track.querySelectorAll('.sf-carousel__slide');
    var dotEls = dotsEl.querySelectorAll('.sf-carousel__dot');

    slideEls.forEach(function (s, i) {
      s.classList.toggle('is-active', i === current);
    });

    dotEls.forEach(function (d, i) {
      d.classList.toggle('is-active', i === current);
    });
  }

  function prev() { goTo(current - 1); resetAuto(); }
  function next() { goTo(current + 1); resetAuto(); }

  function startAuto() {
    stopAuto();
    timer = setInterval(function () { goTo(current + 1); }, intervalMs);
  }

  function stopAuto() {
    if (timer !== null) { clearInterval(timer); timer = null; }
  }

  function resetAuto() {
    if (!prefersReduced) startAuto();
  }

  // ---- Modal ----------------------------------------------------------------
  function openModal(idx) {
    var item = slides[idx];
    if (!item) return;

    stopAuto();

    modalImage.src = item.cover_image_url || '';
    modalImage.alt = item.title || '';
    modalTitle.textContent = item.title || '';
    modalMeta.textContent = formatMeta(item);

    modal.hidden = false;
    modalClose.focus();

    document.addEventListener('keydown', handleModalKey);
  }

  function closeModal() {
    modal.hidden = true;
    document.removeEventListener('keydown', handleModalKey);
    if (!prefersReduced) startAuto();
  }

  function handleModalKey(e) {
    if (e.key === 'Escape') closeModal();
  }

  // ---- Touch / swipe --------------------------------------------------------
  var touchStartX = 0;

  track.addEventListener('touchstart', function (e) {
    touchStartX = e.changedTouches[0].clientX;
  }, { passive: true });

  track.addEventListener('touchend', function (e) {
    var dx = e.changedTouches[0].clientX - touchStartX;
    if (Math.abs(dx) > 40) {
      if (dx < 0) next(); else prev();
    }
  }, { passive: true });

  // ---- State helpers --------------------------------------------------------
  function hideLoading() {
    if (loadingEl) loadingEl.style.display = 'none';
  }

  function showEmpty() {
    hideLoading();
    if (emptyEl) emptyEl.hidden = false;
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

  function escapeAttr(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/"/g, '&quot;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  // ---- Event wiring ---------------------------------------------------------
  if (prevBtn) prevBtn.addEventListener('click', prev);
  if (nextBtn) nextBtn.addEventListener('click', next);
  if (modalClose) modalClose.addEventListener('click', closeModal);
  if (modalBackdrop) modalBackdrop.addEventListener('click', closeModal);

  // ---- Auto-refresh ---------------------------------------------------------
  setInterval(function () {
    stopAuto();
    load();
  }, refreshMs);

  // ---- Init -----------------------------------------------------------------
  load();
})();
