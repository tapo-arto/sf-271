/* public-resize.js – send iframe height to parent frame via postMessage */
(function () {
  'use strict';

  var allowedOrigin = (window.sfCarouselConfig || window.sfArchiveConfig || {}).allowedOrigin || '*';

  function sendHeight() {
    var h = document.documentElement.scrollHeight;
    try {
      parent.postMessage({ type: 'sf-embed-height', height: h }, allowedOrigin);
    } catch (e) {
      // Cross-origin postMessage may throw in some environments; ignore silently
    }
  }

  if (window.ResizeObserver) {
    new ResizeObserver(sendHeight).observe(document.body);
  }

  window.addEventListener('load', sendHeight);

  // Extra passes to let images finish loading
  setTimeout(sendHeight, 500);
  setTimeout(sendHeight, 2000);
})();
