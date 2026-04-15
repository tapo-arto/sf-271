// assets/js/dashboard.js
(function () {
    'use strict';

    // -------------------------------------------------------
    // Injury Heatmap – module-level state
    // -------------------------------------------------------
    var injuryData          = null;  // latest API response
    var activeBpFilter      = null;  // svg_id of selected body part (main dashboard)
    var activeModalBpFilter = null;  // svg_id of selected body part (modal)
    var currentTimeParams   = {};    // mirror of the stats time filter params
    var DASHBOARD_MAX_ITEMS = 4;     // max items shown on dashboard list

    // i18n strings injected by PHP
    var I18N = (typeof window.SF_INJURY_I18N === 'object' && window.SF_INJURY_I18N)
        ? window.SF_INJURY_I18N
        : { empty: 'No injuries', noMatch: 'No cases', activeFilter: 'Filtered:' };

    // -------------------------------------------------------
    // Time filter functionality
    // -------------------------------------------------------
    function initTimeFilter() {
        const filterButtons = document.querySelectorAll('.sf-time-filter-btn');
        const monthSelect = document.getElementById('sf-filter-month');
        const yearSelect = document.getElementById('sf-filter-year');
        const statsContainer = document.querySelector('.sf-dashboard-stats-section');

        if (!statsContainer) return;

        // Get current date values once
        const now = new Date();
        const currentYear = now.getFullYear();
        const currentMonth = now.getMonth() + 1;

        // Handle quick selection buttons
        filterButtons.forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();

                const period = this.dataset.period || 'thisyear';

                // Update active state
                filterButtons.forEach(b => b.classList.remove('sf-active'));
                this.classList.add('sf-active');

                // Set dropdowns based on period
                if (monthSelect && yearSelect) {
                    switch (period) {
                        case 'thismonth':
                            monthSelect.value = currentMonth;
                            yearSelect.value = currentYear;
                            break;
                        case 'thisyear':
                            monthSelect.value = '';
                            yearSelect.value = currentYear;
                            break;
                        case 'all':
                            monthSelect.value = '';
                            yearSelect.value = '';
                            break;
                        // For 3months and 6months, clear dropdowns and use period parameter
                        default:
                            monthSelect.value = '';
                            yearSelect.value = '';
                            break;
                    }
                }

                // Fetch stats using period
                var timeParams = { period: period };
                fetchStats(timeParams);
                currentTimeParams = timeParams;
                fetchInjuryData(Object.assign({}, currentTimeParams, { site: getSiteFilterValue() }));
            });
        });

        // Handle month dropdown change
        if (monthSelect) {
            monthSelect.addEventListener('change', function () {
                // Clear active state from buttons
                filterButtons.forEach(b => b.classList.remove('sf-active'));

                const month = this.value;
                let year = yearSelect ? yearSelect.value : '';

                // If month is selected but no year, default to current year
                if (month && !year) {
                    if (yearSelect) {
                        yearSelect.value = currentYear;
                        year = currentYear;
                    }
                }

                // If both month and year are selected, or just year
                if (month || year) {
                    var timeParams = { month: month, year: year };
                    fetchStats(timeParams);
                    currentTimeParams = timeParams;
                    fetchInjuryData(Object.assign({}, currentTimeParams, { site: getSiteFilterValue() }));
                }
            });
        }

        // Handle year dropdown change
        if (yearSelect) {
            yearSelect.addEventListener('change', function () {
                // Clear active state from buttons
                filterButtons.forEach(b => b.classList.remove('sf-active'));

                const year = this.value;
                const month = monthSelect ? monthSelect.value : '';

                var timeParams = { month: month, year: year };
                fetchStats(timeParams);
                currentTimeParams = timeParams;
                fetchInjuryData(Object.assign({}, currentTimeParams, { site: getSiteFilterValue() }));
            });
        }

        // Fetch stats function
        function fetchStats(params) {
            // Show loading state
            statsContainer.style.opacity = '0.5';
            statsContainer.style.pointerEvents = 'none';

            // Build query string
            const queryParams = new URLSearchParams();
            if (params.period) {
                queryParams.set('period', params.period);
            }
            if (params.month) {
                queryParams.set('month', params.month);
            }
            if (params.year) {
                queryParams.set('year', params.year);
            }

            // Use window.SF_BASE_URL if available
            const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
            const apiUrl = `${baseUrl}/app/api/dashboard-stats.php?${queryParams.toString()}`;

            fetch(apiUrl)
                .then(response => response.json())
                .then(data => {
                    updateStats(data);
                    statsContainer.style.opacity = '1';
                    statsContainer.style.pointerEvents = 'auto';
                })
                .catch(error => {
                    console.error('Failed to fetch stats:', error);
                    statsContainer.style.opacity = '1';
                    statsContainer.style.pointerEvents = 'auto';
                });
        }
    }

    // Update statistics on page
    function updateStats(data) {
        // Update type statistics
        const redCount = document.querySelector('[data-stat="red"]');
        const yellowCount = document.querySelector('[data-stat="yellow"]');
        const totalCount = document.querySelector('[data-stat="total"]');

        if (redCount) redCount.textContent = data.originalStats.red || 0;
        if (yellowCount) yellowCount.textContent = data.originalStats.yellow || 0;
        if (totalCount) totalCount.textContent = data.originalStats.total || 0;

        // Update worksite statistics
        updateWorksiteStats(data.worksiteStats);
    }

    // Update worksite bars
    function updateWorksiteStats(worksiteStats) {
        const container = document.querySelector('.sf-worksite-bars');
        if (!container) return;

        const maxCount = worksiteStats.length > 0
            ? Math.max(...worksiteStats.map(ws => ws.count))
            : 1;

        // Clear existing content
        container.innerHTML = '';

        // Get base URL once (outside the loop)
        const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');

        // Add all worksites (will show/hide based on expanded state)
        worksiteStats.forEach((ws, index) => {
            const barWidth = maxCount > 0 ? Math.round((ws.count / maxCount) * 100) : 0;
            const row = document.createElement('a');
            row.href = `${baseUrl}/index.php?page=list&site=${encodeURIComponent(ws.site)}`;
            row.className = `sf-worksite-bar-row ${index >= 5 ? 'sf-worksite-hidden' : ''}`;
            row.style.setProperty('--bar-delay', `${index * 0.08}s`);

            row.innerHTML = `
                <span class="sf-worksite-name">${escapeHtml(ws.site)}</span>
                <div class="sf-worksite-bar-wrap">
                    <div class="sf-worksite-bar" style="--bar-width: ${barWidth}%;">
                        <span class="sf-worksite-count">${ws.count}</span>
                    </div>
                </div>
            `;

            container.appendChild(row);
        });

        // Update show all button visibility
        const showAllBtn = document.querySelector('.sf-worksite-show-all');
        if (showAllBtn) {
            showAllBtn.style.display = worksiteStats.length > 5 ? 'flex' : 'none';
        }
    }

    // Toggle worksite list expansion
    function initWorksiteToggle() {
        const showAllBtn = document.querySelector('.sf-worksite-show-all');
        if (!showAllBtn) return;

        showAllBtn.addEventListener('click', function (e) {
            e.preventDefault();

            const hiddenItems = document.querySelectorAll('.sf-worksite-hidden');
            const isExpanded = this.classList.contains('sf-expanded');

            if (isExpanded) {
                // Collapse
                hiddenItems.forEach(item => {
                    item.style.display = 'none';
                });
                this.classList.remove('sf-expanded');
                this.querySelector('.sf-toggle-text').textContent = this.dataset.showText;
                this.querySelector('.sf-toggle-icon').textContent = '▼';
            } else {
                // Expand
                hiddenItems.forEach(item => {
                    item.style.display = 'flex';
                });
                this.classList.add('sf-expanded');
                this.querySelector('.sf-toggle-text').textContent = this.dataset.hideText;
                this.querySelector('.sf-toggle-icon').textContent = '▲';
            }
        });
    }

    // -------------------------------------------------------
    // Injury Heatmap
    // -------------------------------------------------------

    /** Return the currently selected worksite from the injury site dropdown */
    function getSiteFilterValue() {
        var sel = document.getElementById('sf-injury-site-filter');
        return sel ? sel.value : '';
    }

    /** Fetch injury heatmap data from the API */
    function fetchInjuryData(params) {
        var card = document.getElementById('sf-injury-card');
        if (card) {
            card.style.opacity = '0.6';
            card.style.pointerEvents = 'none';
        }

        var qp = new URLSearchParams();
        if (params.period) qp.set('period', params.period);
        if (params.month)  qp.set('month',  params.month);
        if (params.year)   qp.set('year',   params.year);
        if (params.site)   qp.set('site',   params.site);

        var baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');
        fetch(baseUrl + '/app/api/injury-heatmap.php?' + qp.toString())
            .then(function (r) { return r.json(); })
            .then(function (data) {
                injuryData = data;
                applyHeatmap(data.bodyPartCounts);
                renderInjuryChart(data.bodyPartCounts);
                renderInjuryList(data.recentFlashes, activeBpFilter);
                updateSiteDropdown(data.sites);
                // If modal is open, refresh its list too
                var modal = document.getElementById('sf-injury-modal');
                if (modal && modal.style.display !== 'none') {
                    renderModalList(data.recentFlashes, activeModalBpFilter);
                }
                if (card) {
                    card.style.opacity = '1';
                    card.style.pointerEvents = 'auto';
                }
            })
            .catch(function () {
                if (card) {
                    card.style.opacity = '1';
                    card.style.pointerEvents = 'auto';
                }
            });
    }

    /** Keep the worksite dropdown options current (add new sites, don't remove existing) */
    function updateSiteDropdown(sites) {
        var sel = document.getElementById('sf-injury-site-filter');
        if (!sel || !sites) return;
        var existing = Array.from(sel.options).map(function (o) { return o.value; });
        sites.forEach(function (site) {
            if (!existing.includes(site)) {
                var opt = document.createElement('option');
                opt.value       = site;
                opt.textContent = site;
                sel.appendChild(opt);
            }
        });
    }

    /**
     * Returns the CSS fill colour for a heatmap body part.
     * intensity: 0–1 (count / maxCount)
     */
    function getHeatmapColor(intensity, count) {
        if (count === 0) return '#e5e7eb';
        if (intensity <= 0.25) return '#fde68a';
        if (intensity <= 0.5)  return '#fca5a5';
        if (intensity <= 0.75) return '#f87171';
        return '#dc2626';
    }

    /** Apply heatmap colours to both SVG figures (main dashboard + modal) */
    function applyHeatmap(bodyPartCounts) {
        if (!bodyPartCounts) return;
        var maxCount = bodyPartCounts.reduce(function (m, bp) { return Math.max(m, bp.count); }, 1);

        ['sf-heatmap-svg-front', 'sf-heatmap-svg-back',
         'sf-modal-heatmap-svg-front', 'sf-modal-heatmap-svg-back'].forEach(function (svgId) {
            var svgEl = document.getElementById(svgId);
            if (!svgEl) return;

            // Reset all parts to default first
            svgEl.querySelectorAll('[id^="bp-"]').forEach(function (el) {
                el.style.fill = '#e5e7eb';
            });

            // Apply counts
            bodyPartCounts.forEach(function (bp) {
                // Front SVG uses exact id; back SVG uses id + '-back'
                var id = (svgId === 'sf-heatmap-svg-back') ? bp.svg_id + '-back' : bp.svg_id;
                // Also try the exact id for back SVG (some parts like upper-back only exist in back SVG)
                var el = svgEl.querySelector('#' + CSS.escape(id))
                      || svgEl.querySelector('#' + CSS.escape(bp.svg_id));
                if (el) {
                    el.style.fill = getHeatmapColor(bp.count / maxCount, bp.count);
                }
            });
        });
    }

    /** Render horizontal bar chart for body-part categories */
    function renderInjuryChart(bodyPartCounts) {
        var container = document.getElementById('sf-injury-chart');
        if (!container || !bodyPartCounts) return;

        // Group by category
        var categories = {};
        bodyPartCounts.forEach(function (bp) {
            if (!categories[bp.category]) categories[bp.category] = 0;
            categories[bp.category] += bp.count;
        });

        var maxCat = Object.values(categories).reduce(function (m, v) { return Math.max(m, v); }, 1);

        container.innerHTML = '';
        Object.entries(categories).forEach(function (entry) {
            var cat   = entry[0];
            var count = entry[1];
            var barWidth = Math.round((count / maxCat) * 100);

            var barClass = count === 0
                ? 'sf-injury-chart-bar sf-injury-chart-bar--zero'
                : 'sf-injury-chart-bar';
            var row = document.createElement('div');
            row.className = 'sf-injury-chart-row';
            row.innerHTML =
                '<span class="sf-injury-chart-label' + (count === 0 ? ' sf-injury-chart-label--zero' : '') + '">' + escapeHtml(cat) + '</span>' +
                '<div class="sf-injury-chart-bar-wrap">' +
                    '<div class="' + barClass + '" style="--bar-width: ' + barWidth + '%;">' +
                        '<span class="sf-injury-chart-count">' + count + '</span>' +
                    '</div>' +
                '</div>';
            container.appendChild(row);
        });
    }

    /** Render (or re-render) the dashboard flash list, limited to 4 items */
    function renderInjuryList(recentFlashes, filterBpId) {
        var container = document.getElementById('sf-injury-flash-list');
        if (!container) return;

        container.innerHTML = '';

        var allFiltered = filterBpId
            ? (recentFlashes || []).filter(function (f) {
                return f.body_parts && f.body_parts.indexOf(filterBpId) !== -1;
              })
            : (recentFlashes || []);

        // Show-all button
        var showAllBtn = document.getElementById('sf-injury-show-all-btn');
        var showAllText = showAllBtn ? showAllBtn.querySelector('.sf-injury-show-all-text') : null;
        if (showAllBtn) {
            if (allFiltered.length > DASHBOARD_MAX_ITEMS) {
                var tpl = (I18N.showAllCount || 'Show all {n}');
                if (showAllText) showAllText.textContent = tpl.replace('{n}', allFiltered.length);
                showAllBtn.style.display = '';
            } else {
                showAllBtn.style.display = 'none';
            }
        }

        var list = allFiltered.slice(0, DASHBOARD_MAX_ITEMS);

        if (list.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'sf-pending-empty';
            empty.innerHTML = '<span>' + escapeHtml(filterBpId ? I18N.noMatch : I18N.empty) + '</span>';
            container.appendChild(empty);
            return;
        }

        var baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');

        list.forEach(function (flash) {
            var item       = document.createElement('a');
            item.href      = baseUrl + '/index.php?page=view&id=' + encodeURIComponent(flash.id);
            item.className = 'sf-recent-compact-item sf-injury-flash-item';
            item.dataset.flashType = flash.type || '';

            var dateStr = flash.updated_at ? formatDate(flash.updated_at) : '';

            item.innerHTML =
                '<span class="sf-type-dot sf-type-dot--' + escapeHtml(flash.type) + '"></span>' +
                '<div class="sf-recent-compact-content">' +
                    '<div class="sf-recent-compact-title">' + escapeHtml(flash.title) + '</div>' +
                    '<div class="sf-recent-compact-meta">' +
                        (flash.site ? '<span>' + escapeHtml(flash.site) + '</span><span>·</span>' : '') +
                        '<span class="sf-recent-compact-time">' + escapeHtml(dateStr) + '</span>' +
                    '</div>' +
                '</div>';

            container.appendChild(item);
        });
    }

    /** Render (or re-render) the modal flash list (all items) */
    function renderModalList(recentFlashes, filterBpId) {
        var container = document.getElementById('sf-injury-modal-flash-list');
        if (!container) return;

        container.innerHTML = '';

        var list = filterBpId
            ? (recentFlashes || []).filter(function (f) {
                return f.body_parts && f.body_parts.indexOf(filterBpId) !== -1;
              })
            : (recentFlashes || []);

        if (list.length === 0) {
            var empty = document.createElement('div');
            empty.className = 'sf-pending-empty';
            empty.innerHTML = '<span>' + escapeHtml(filterBpId ? I18N.noMatch : I18N.empty) + '</span>';
            container.appendChild(empty);
            return;
        }

        var baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');

        list.forEach(function (flash) {
            var item       = document.createElement('a');
            item.href      = baseUrl + '/index.php?page=view&id=' + encodeURIComponent(flash.id);
            item.className = 'sf-recent-compact-item sf-injury-flash-item';
            item.dataset.flashType = flash.type || '';

            var dateStr = flash.updated_at ? formatDate(flash.updated_at) : '';

            item.innerHTML =
                '<span class="sf-type-dot sf-type-dot--' + escapeHtml(flash.type) + '"></span>' +
                '<div class="sf-recent-compact-content">' +
                    '<div class="sf-recent-compact-title">' + escapeHtml(flash.title) + '</div>' +
                    '<div class="sf-recent-compact-meta">' +
                        (flash.site ? '<span>' + escapeHtml(flash.site) + '</span><span>·</span>' : '') +
                        '<span class="sf-recent-compact-time">' + escapeHtml(dateStr) + '</span>' +
                    '</div>' +
                '</div>';

            container.appendChild(item);
        });
    }

    /** Highlight a single body part across both SVG figures (main or modal) */
    function highlightBp(partId, svgPrefix) {
        var prefix = svgPrefix || 'sf-heatmap-svg';
        [prefix + '-front', prefix + '-back'].forEach(function (svgId) {
            var svgEl = document.getElementById(svgId);
            if (!svgEl) return;

            svgEl.querySelectorAll('.sf-bp-active').forEach(function (el) {
                el.classList.remove('sf-bp-active');
            });

            // Try canonical id and back-suffixed id
            [partId, partId + '-back'].forEach(function (id) {
                var el = svgEl.querySelector('#' + CSS.escape(id));
                if (el) el.classList.add('sf-bp-active');
            });
        });
    }

    /** Remove all active highlights (main or modal) */
    function clearBpHighlight(svgPrefix) {
        var prefix = svgPrefix || 'sf-heatmap-svg';
        document.querySelectorAll(
            '#' + prefix + '-front .sf-bp-active, #' + prefix + '-back .sf-bp-active'
        ).forEach(function (el) { el.classList.remove('sf-bp-active'); });
    }

    /** Update the active-filter badge (main or modal) */
    function updateActiveFilterBadge(partId, badgeId) {
        var badge = document.getElementById(badgeId || 'sf-injury-active-filter');
        if (!badge) return;

        if (!partId || !injuryData) {
            badge.style.display = 'none';
            badge.textContent   = '';
            return;
        }

        var bp   = (injuryData.bodyPartCounts || []).find(function (b) { return b.svg_id === partId; });
        var name = bp ? bp.name : partId;
        badge.textContent = I18N.activeFilter + ' ' + name;
        badge.style.display = 'inline';
    }

    /** Normalise a body-part element id to the canonical (front) id */
    function canonicalBpId(rawId) {
        return rawId.endsWith('-back') ? rawId.slice(0, -5) : rawId;
    }

    /** Initialise injury heatmap interactions */
    function initInjuryHeatmap() {
        // Bootstrap with server-side data
        if (typeof window.SF_INJURY_INITIAL_DATA === 'object' && window.SF_INJURY_INITIAL_DATA) {
            injuryData = window.SF_INJURY_INITIAL_DATA;
            applyHeatmap(injuryData.bodyPartCounts);
            renderInjuryChart(injuryData.bodyPartCounts);
            renderInjuryList(injuryData.recentFlashes, null);
        }

        // SVG click handlers (both front and back) – main dashboard
        ['sf-heatmap-svg-front', 'sf-heatmap-svg-back'].forEach(function (svgId) {
            var svgEl = document.getElementById(svgId);
            if (!svgEl) return;

            svgEl.addEventListener('click', function (e) {
                var target = e.target.closest('[id^="bp-"]');

                if (!target) {
                    // Click on empty area – clear filter
                    if (activeBpFilter) {
                        activeBpFilter = null;
                        clearBpHighlight('sf-heatmap-svg');
                        renderInjuryList(injuryData ? injuryData.recentFlashes : [], null);
                        updateActiveFilterBadge(null, 'sf-injury-active-filter');
                    }
                    return;
                }

                var partId = canonicalBpId(target.id);

                if (activeBpFilter === partId) {
                    // Toggle off
                    activeBpFilter = null;
                    clearBpHighlight('sf-heatmap-svg');
                    renderInjuryList(injuryData ? injuryData.recentFlashes : [], null);
                    updateActiveFilterBadge(null, 'sf-injury-active-filter');
                } else {
                    activeBpFilter = partId;
                    highlightBp(partId, 'sf-heatmap-svg');
                    renderInjuryList(injuryData ? injuryData.recentFlashes : [], partId);
                    updateActiveFilterBadge(partId, 'sf-injury-active-filter');
                }
            });
        });

        // Click on the active-filter badge clears the filter
        var badge = document.getElementById('sf-injury-active-filter');
        if (badge) {
            badge.addEventListener('click', function () {
                activeBpFilter = null;
                clearBpHighlight('sf-heatmap-svg');
                renderInjuryList(injuryData ? injuryData.recentFlashes : [], null);
                updateActiveFilterBadge(null, 'sf-injury-active-filter');
            });
        }

        // Worksite dropdown change
        var siteFilter = document.getElementById('sf-injury-site-filter');
        if (siteFilter) {
            siteFilter.addEventListener('change', function () {
                fetchInjuryData(Object.assign({}, currentTimeParams, { site: this.value }));
            });
        }

        // Show-all button opens modal
        var showAllBtn = document.getElementById('sf-injury-show-all-btn');
        if (showAllBtn) {
            showAllBtn.addEventListener('click', function () {
                openInjuryModal();
            });
        }

        initInjuryModal();
    }

    /** Open the injury modal */
    function openInjuryModal() {
        var modal = document.getElementById('sf-injury-modal');
        if (!modal) return;

        // Reset modal filter
        activeModalBpFilter = null;
        clearBpHighlight('sf-modal-heatmap-svg');
        updateActiveFilterBadge(null, 'sf-injury-modal-active-filter');

        // Apply heatmap to modal SVGs
        if (injuryData) {
            applyHeatmap(injuryData.bodyPartCounts);
            renderModalList(injuryData.recentFlashes, null);
        }

        modal.style.display = 'flex';
        document.body.classList.add('sf-modal-open');

        // Brief delay so the modal is visible before focus moves (avoids layout-shift artefacts)
        var closeBtn = document.getElementById('sf-injury-modal-close');
        if (closeBtn) setTimeout(function () { closeBtn.focus(); }, 50);
    }

    /** Close the injury modal */
    function closeInjuryModal() {
        var modal = document.getElementById('sf-injury-modal');
        if (!modal) return;
        modal.style.display = 'none';
        document.body.classList.remove('sf-modal-open');
        activeModalBpFilter = null;
    }

    /** Initialise modal interactions */
    function initInjuryModal() {
        var closeBtn  = document.getElementById('sf-injury-modal-close');
        var backdrop  = document.getElementById('sf-injury-modal-backdrop');
        var modalBadge = document.getElementById('sf-injury-modal-active-filter');

        if (closeBtn) {
            closeBtn.addEventListener('click', closeInjuryModal);
        }
        if (backdrop) {
            backdrop.addEventListener('click', closeInjuryModal);
        }

        // Escape key closes modal (use a named function so it can be checked)
        if (!document._sfInjuryModalEscBound) {
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') closeInjuryModal();
            });
            document._sfInjuryModalEscBound = true;
        }

        // Modal SVG click handlers
        ['sf-modal-heatmap-svg-front', 'sf-modal-heatmap-svg-back'].forEach(function (svgId) {
            var svgEl = document.getElementById(svgId);
            if (!svgEl) return;

            svgEl.addEventListener('click', function (e) {
                var target = e.target.closest('[id^="bp-"]');

                if (!target) {
                    if (activeModalBpFilter) {
                        activeModalBpFilter = null;
                        clearBpHighlight('sf-modal-heatmap-svg');
                        renderModalList(injuryData ? injuryData.recentFlashes : [], null);
                        updateActiveFilterBadge(null, 'sf-injury-modal-active-filter');
                    }
                    return;
                }

                var partId = canonicalBpId(target.id);

                if (activeModalBpFilter === partId) {
                    activeModalBpFilter = null;
                    clearBpHighlight('sf-modal-heatmap-svg');
                    renderModalList(injuryData ? injuryData.recentFlashes : [], null);
                    updateActiveFilterBadge(null, 'sf-injury-modal-active-filter');
                } else {
                    activeModalBpFilter = partId;
                    highlightBp(partId, 'sf-modal-heatmap-svg');
                    renderModalList(injuryData ? injuryData.recentFlashes : [], partId);
                    updateActiveFilterBadge(partId, 'sf-injury-modal-active-filter');
                }
            });
        });

        // Modal active-filter badge clears modal filter
        if (modalBadge) {
            modalBadge.addEventListener('click', function () {
                activeModalBpFilter = null;
                clearBpHighlight('sf-modal-heatmap-svg');
                renderModalList(injuryData ? injuryData.recentFlashes : [], null);
                updateActiveFilterBadge(null, 'sf-injury-modal-active-filter');
            });
        }
    }

    // -------------------------------------------------------
    // Format a date string as DD.MM.YYYY
    // -------------------------------------------------------
    function formatDate(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dateStr;
        var day   = String(d.getDate()).padStart(2, '0');
        var month = String(d.getMonth() + 1).padStart(2, '0');
        var year  = d.getFullYear();
        return day + '.' + month + '.' + year;
    }

    // -------------------------------------------------------
    // Simple JS time-ago (fallback for dynamically rendered items)
    // -------------------------------------------------------
    function jsTimeAgo(dateStr) {
        if (!dateStr) return '';
        var d = new Date(dateStr.replace(' ', 'T'));
        if (isNaN(d.getTime())) return dateStr;
        var diffMs   = Date.now() - d.getTime();
        var diffDays = Math.floor(diffMs / 86400000);
        if (diffDays === 0) return I18N.today     || '';
        if (diffDays === 1) return I18N.yesterday || '';
        if (diffDays < 30) {
            var tpl = I18N.daysAgo || '{n}';
            return tpl.replace('{n}', diffDays);
        }
        var months = Math.floor(diffDays / 30);
        if (months < 12) return months + ' kk';
        return Math.floor(months / 12) + ' v';
    }

    // Escape HTML to prevent XSS
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // Initialize on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initTimeFilter();
            initWorksiteToggle();
            initInjuryHeatmap();
        });
    } else {
        initTimeFilter();
        initWorksiteToggle();
        initInjuryHeatmap();
    }
})();