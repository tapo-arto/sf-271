/**
 * SafetyFlash - Display Targets Modal
 *
 * Handles open/close, chip toggle logic and AJAX save for
 * the "Infonäytöt" (display targets) management modal on the view page.
 */
(function () {
    'use strict';

    var modalId = 'displayTargetsModal';
    var defaultTab = 'timing';

    function setActiveTab(tabName) {
        var modal = document.getElementById(modalId);
        if (!modal) return;

        modal.querySelectorAll('.sf-dt-tab').forEach(function (tab) {
            var isActive = tab.getAttribute('data-tab') === tabName;
            tab.classList.toggle('sf-dt-tab-active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
            tab.setAttribute('tabindex', isActive ? '0' : '-1');
        });

        modal.querySelectorAll('.sf-dt-tab-panel').forEach(function (panel) {
            var isActive = panel.getAttribute('data-tab-panel') === tabName;
            panel.classList.toggle('sf-dt-tab-panel-active', isActive);
        });
    }

    function initTabs() {
        var modal = document.getElementById(modalId);
        if (!modal) return;

        var tabs = modal.querySelectorAll('.sf-dt-tab');
        if (!tabs.length) return;

        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                setActiveTab(tab.getAttribute('data-tab') || defaultTab);
            });

            tab.addEventListener('keydown', function (e) {
                if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
                e.preventDefault();
                var orderedTabs = Array.from(tabs);
                var currentIndex = orderedTabs.indexOf(tab);
                if (currentIndex < 0) return;
                var nextIndex = e.key === 'ArrowRight'
                    ? (currentIndex + 1) % orderedTabs.length
                    : (currentIndex - 1 + orderedTabs.length) % orderedTabs.length;
                var nextTab = orderedTabs[nextIndex];
                setActiveTab(nextTab.getAttribute('data-tab') || defaultTab);
                nextTab.focus();
            });
        });

        setActiveTab(defaultTab);
    }

    function openDisplayTargetsModal() {
        if (window._sf && window._sf.openModal) {
            window._sf.openModal(modalId);
        } else if (window.openModal) {
            window.openModal(modalId);
        } else {
            var el = document.getElementById(modalId);
            if (el) {
                el.classList.remove('hidden');
                document.body.classList.add('sf-modal-open');
            }
        }
        setActiveTab(defaultTab);
        clearStatus();
    }

    function closeDisplayTargetsModal() {
        if (window._sf && window._sf.closeModal) {
            window._sf.closeModal(modalId);
        } else if (window.closeModal) {
            window.closeModal(modalId);
        } else {
            var el = document.getElementById(modalId);
            if (el) {
                el.classList.add('hidden');
                var anyOpen = document.querySelector('.sf-modal:not(.hidden)');
                if (!anyOpen) {
                    document.body.classList.remove('sf-modal-open');
                }
            }
        }
    }

    function clearStatus() {
        var statusEl = document.getElementById('dtSaveStatus');
        if (statusEl) {
            statusEl.textContent = '';
            statusEl.className = 'sf-dt-status';
        }
    }

    function setStatus(msg, isError) {
        var statusEl = document.getElementById('dtSaveStatus');
        if (!statusEl) return;
        statusEl.textContent = msg;
        statusEl.className = 'sf-dt-status ' + (isError ? 'sf-dt-status-error' : 'sf-dt-status-ok');
    }

    // Returns the .sf-display-target-selector container inside the modal, or null
    function getContainer() {
        var modal = document.getElementById(modalId);
        return modal ? modal.querySelector('.sf-display-target-selector') : null;
    }

    // Build lang→checkboxes map for a container
    function buildCbByLang(container) {
        var map = {};
        container.querySelectorAll('.dt-display-chip-cb').forEach(function (cb) {
            var lang = cb.getAttribute('data-lang') || '';
            if (!map[lang]) map[lang] = [];
            map[lang].push(cb);
        });
        return map;
    }

    // Update selection tags display
    function initSelectionDisplay() {
        var container = getContainer();
        if (!container) return;
        updateSelectionDisplay(container);
        updateLangChipStates(container);

        // Checkbox changes (delegated)
        container.addEventListener('change', function (e) {
            if (e.target.classList.contains('dt-display-chip-cb')) {
                updateSelectionDisplay(container);
                updateLangChipStates(container);
            }
        });

        // Clear all button
        var clearAllBtn = container.querySelector('.sf-dt-clear-all-btn');
        if (clearAllBtn) {
            clearAllBtn.addEventListener('click', function () {
                container.querySelectorAll('.dt-display-chip-cb').forEach(function (cb) {
                    cb.checked = false;
                });
                updateSelectionDisplay(container);
                updateLangChipStates(container);
            });
        }
    }

    function updateSelectionDisplay(container) {
        var display = container.querySelector('.sf-dt-selection-display');
        var tags = container.querySelector('.sf-dt-selection-tags');
        if (!display || !tags) return;

        tags.innerHTML = '';
        var checked = container.querySelectorAll('.dt-display-chip-cb:checked');

        checked.forEach(function (cb) {
            var label = cb.getAttribute('data-label') || cb.value;
            var tag = document.createElement('span');
            tag.className = 'sf-dt-sel-tag';
            var text = document.createTextNode(label + ' ');
            var removeBtn = document.createElement('span');
            removeBtn.className = 'sf-dt-sel-tag-remove';
            removeBtn.textContent = '×';
            removeBtn.addEventListener('click', function () {
                cb.checked = false;
                updateSelectionDisplay(container);
                updateLangChipStates(container);
            });
            tag.appendChild(text);
            tag.appendChild(removeBtn);
            tags.appendChild(tag);
        });

        display.classList.toggle('hidden', checked.length === 0);
    }

    function updateLangChipStates(container) {
        var cbByLang = buildCbByLang(container);
        container.querySelectorAll('.sf-dt-lang-chip').forEach(function (chip) {
            var lang = chip.getAttribute('data-lang');
            var cbs = cbByLang[lang] || [];
            var checkedCount = 0;
            cbs.forEach(function (cb) { if (cb.checked) checkedCount++; });
            chip.classList.toggle('sf-dt-lang-chip-active', cbs.length > 0 && checkedCount === cbs.length);
        });

        // Update special chip active states
        container.querySelectorAll('.sf-dt-special-chip').forEach(function (chip) {
            var selectType = chip.getAttribute('data-select');
            var cbs;
            if (selectType === 'all') {
                cbs = Array.from(container.querySelectorAll('.dt-display-chip-cb'));
            } else {
                cbs = Array.from(container.querySelectorAll('.dt-display-chip-cb[data-type="' + selectType + '"]'));
            }
            var checkedCount = cbs.filter(function (cb) { return cb.checked; }).length;
            chip.classList.toggle('sf-dt-lang-chip-active', cbs.length > 0 && checkedCount === cbs.length);
        });
    }

    // Language chip toggles
    function initLangChips() {
        var container = getContainer();
        if (!container) return;

        container.querySelectorAll('.sf-dt-lang-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var lang = this.getAttribute('data-lang');
                var isActive = this.classList.contains('sf-dt-lang-chip-active');
                (buildCbByLang(container)[lang] || []).forEach(function (cb) {
                    cb.checked = !isActive;
                });
                updateLangChipStates(container);
                updateSelectionDisplay(container);
            });
        });

        // Erikoischippien klikkaukset (Kaikki näytöt / Tunnelityömaat / Avolouhokset)
        container.querySelectorAll('.sf-dt-special-chip').forEach(function (chip) {
            chip.addEventListener('click', function () {
                var selectType = this.getAttribute('data-select');
                var isActive = this.classList.contains('sf-dt-lang-chip-active');
                var cbs;
                if (selectType === 'all') {
                    cbs = container.querySelectorAll('.dt-display-chip-cb');
                } else {
                    cbs = container.querySelectorAll('.dt-display-chip-cb[data-type="' + selectType + '"]');
                }
                cbs.forEach(function (cb) { cb.checked = !isActive; });
                updateLangChipStates(container);
                updateSelectionDisplay(container);
            });
        });
    }

    // Search filtering for worksite results
    function initSearch() {
        var container = getContainer();
        if (!container) return;

        var searchInput = container.querySelector('.sf-dt-search-input');
        var searchResults = container.querySelector('.sf-dt-search-results');
        if (!searchInput || !searchResults) return;

        // Ensure all items are hidden initially
        container.querySelectorAll('.sf-dt-result-item').forEach(function (item) {
            item.classList.add('hidden');
        });

        searchInput.addEventListener('input', function () {
            var term = this.value.toLowerCase().trim();
            var items = container.querySelectorAll('.sf-dt-result-item');
            var hasVisible = false;

            if (term.length === 0) {
                items.forEach(function (item) { item.classList.add('hidden'); });
                searchResults.classList.add('hidden');
                return;
            }

            searchResults.classList.remove('hidden');
            items.forEach(function (item) {
                var search = item.getAttribute('data-search') || '';
                var visible = search.includes(term);
                item.classList.toggle('hidden', !visible);
                if (visible) hasVisible = true;
            });

            if (!hasVisible) {
                searchResults.classList.add('hidden');
            }
        });

        // Auto-clear search after selecting a result
        searchResults.addEventListener('click', function (e) {
            var resultItem = e.target.closest('.sf-dt-result-item');
            if (resultItem) {
                searchInput.value = '';
                container.querySelectorAll('.sf-dt-result-item').forEach(function (item) {
                    item.classList.add('hidden');
                });
                searchResults.classList.add('hidden');
                searchInput.focus();
            }
        });
    }

    // Save handler
    function initSaveButton() {
        var btn = document.getElementById('btnSaveDisplayTargets');
        if (!btn) return;

        btn.addEventListener('click', function () {
            var flashId = parseInt(btn.getAttribute('data-flash-id'), 10);
            if (!flashId) return;

            // Collect TTL
            var ttlInput = document.querySelector('#displayTargetsModal input[name="dt_display_ttl_days"]:checked');
            var ttlDays = ttlInput ? parseInt(ttlInput.value, 10) : 30;

            // Collect Duration
            var durationInput = document.querySelector('#displayTargetsModal input[name="dt_display_duration_seconds"]:checked');
            var durationSeconds = durationInput ? parseInt(durationInput.value, 10) : 30;

            // Collect selected display IDs
            var displayTargets = [];
            document.querySelectorAll('#displayTargetsModal .dt-display-chip-cb:checked').forEach(function (cb) {
                var val = parseInt(cb.value, 10);
                if (val > 0) displayTargets.push(val);
            });

            var payload = {
                flash_id: flashId,
                display_targets: displayTargets,
                display_ttl_days: ttlDays,
                display_duration_seconds: durationSeconds,
                csrf_token: window.SF_CSRF_TOKEN || ''
            };

            var originalHtml = btn.innerHTML;
            btn.disabled = true;
            btn.setAttribute('aria-busy', 'true');
            btn.innerHTML = '<span class="sf-spinner" aria-hidden="true"></span>Tallennetaan...';
            clearStatus();

            var baseUrl = window.SF_BASE_URL || '';
            fetch(baseUrl + '/app/api/display_targets_save.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            })
                .then(function (response) {
                    return response.json();
                })
                .then(function (data) {
                    if (data && data.ok) {
                        btn.innerHTML = '✓ Tallennettu!';
                        setStatus(data.message || '✓ Tallennettu!', false);
                        // Reload page after short delay to reflect changes
                        setTimeout(function () {
                            closeDisplayTargetsModal();
                            window.location.reload();
                        }, 800);
                    } else {
                        btn.disabled = false;
                        btn.removeAttribute('aria-busy');
                        btn.innerHTML = originalHtml;
                        setStatus((data && data.error) ? data.error : 'Tallentaminen epäonnistui.', true);
                    }
                })
                .catch(function () {
                    btn.disabled = false;
                    btn.removeAttribute('aria-busy');
                    btn.innerHTML = originalHtml;
                    setStatus('Verkkovirhe. Yritä uudelleen.', true);
                });
        });
    }

    function init() {
        // Expose open/close globally so PHP-rendered button onclick can call them
        window.openDisplayTargetsModal = openDisplayTargetsModal;
        window.closeDisplayTargetsModal = closeDisplayTargetsModal;

        var openBtn = document.getElementById('footerDisplayTargets');
        if (openBtn) {
            openBtn.addEventListener('click', openDisplayTargetsModal);
        }

        initSelectionDisplay();
        initTabs();
        initLangChips();
        initSearch();
        initSaveButton();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
