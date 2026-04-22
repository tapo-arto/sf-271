(function () {
    'use strict';

    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

    const terms = window.SF_LANGUAGE_REVIEWS_TERMS || {};
    const state = {
        bundle: [],
        existing: {},
        suggestions: {},
        users: [],
        rows: {}
    };

    function t(key, fallback) {
        return terms[key] || fallback || key;
    }

    function openModal(id) {
        if (typeof window.openModal === 'function') {
            window.openModal(id);
            return;
        }
        const modal = document.getElementById(id);
        if (modal) {
            modal.classList.remove('hidden');
            document.body.classList.add('sf-modal-open');
        }
    }

    function showToast(message, type) {
        if (typeof window.sfToast === 'function') {
            window.sfToast(type || 'success', message);
            return;
        }
        alert(message);
    }

    function fullName(user) {
        const name = ((user.first_name || '') + ' ' + (user.last_name || '')).trim();
        return name || ('ID ' + user.id);
    }

    function reasonLabel(reason) {
        if (reason === 'ui_lang_match_history') return t('reasonUiLangMatchHistory', reason);
        if (reason === 'ui_lang_match_reviewer') return t('reasonUiLangMatchReviewer', reason);
        if (reason === 'ui_lang_match') return t('reasonUiLangMatch', reason);
        return t('reasonOther', reason || 'other');
    }

    function renderRows() {
        const container = qs('#sfLanguageReviewRows');
        if (!container) return;

        container.innerHTML = '';
        state.rows = {};

        state.bundle.forEach((item) => {
            const existing = state.existing[String(item.flash_id)] || null;
            const suggestion = state.suggestions[item.lang] || null;
            const selected = existing || suggestion || null;
            const enabled = Boolean(existing);

            state.rows[item.flash_id] = {
                enabled: enabled,
                selectedUser: selected,
                initialUserId: existing ? Number(existing.user_id) : 0
            };

            const row = document.createElement('div');
            row.className = 'sf-language-review-row';
            row.dataset.flashId = String(item.flash_id);
            row.innerHTML = `
                <div class="sf-language-review-row-top">
                    <div class="sf-language-review-lang">
                        ${item.icon ? `<img src="${(window.SF_BASE_URL || '') + '/assets/img/' + item.icon}" alt="${item.lang.toUpperCase()}" class="sf-language-review-flag">` : ''}
                        ${item.lang_label} (${item.lang.toUpperCase()})
                    </div>
                    <span class="sf-language-review-status-dot ${enabled && selected ? 'is-ready' : ''}"></span>
                </div>
                <div class="sf-language-review-row-main">
                    <label class="sf-language-review-toggle">
                        <input type="checkbox" class="sf-language-review-enable" ${enabled ? 'checked' : ''}>
                        <span>${t('requestToggle', 'Pyydä tarkistusta')}</span>
                    </label>
                    <div class="sf-language-review-user-wrap">
                        <input type="text" class="sf-language-review-user-input" autocomplete="off" ${enabled ? '' : 'disabled'}>
                        <input type="hidden" class="sf-language-review-user-id" value="${selected ? Number(selected.user_id) : ''}">
                        <div class="sf-language-review-user-dropdown hidden"></div>
                    </div>
                </div>
                <div class="sf-language-review-row-foot">
                    <span class="sf-language-review-existing">${existing ? `${fullName(existing)}${existing.assigned_at ? ' (' + existing.assigned_at.slice(0, 10) + ')' : ''}` : t('noReviewer', '(ei tarkistajaa)')}</span>
                    ${existing ? `
                        <button type="button" class="sf-language-review-inline-btn sf-language-review-change-btn">${t('change', 'Vaihda')}</button>
                        <button type="button" class="sf-language-review-inline-btn sf-language-review-remove-btn">${t('remove', 'Poista')}</button>
                    ` : ''}
                    ${suggestion ? `<span class="sf-language-review-suggested" title="${reasonLabel(suggestion.reason)}">${t('suggestedBadge', 'Ehdotettu')}</span>` : ''}
                </div>
            `;

            const input = qs('.sf-language-review-user-input', row);
            if (input) {
                input.value = selected ? fullName(selected) : '';
            }

            attachRowHandlers(row, item.flash_id);
            container.appendChild(row);
        });

        updateSubmitText();
    }

    function getRowUsers(flashId) {
        const rowState = state.rows[flashId] || {};
        const users = state.users.slice();
        if (rowState.initialUserId > 0) {
            const existing = state.existing[String(flashId)];
            if (existing) {
                users.push({
                    id: Number(existing.user_id),
                    first_name: existing.first_name || '',
                    last_name: existing.last_name || '',
                    ui_lang: ''
                });
            }
        }
        const seen = new Set();
        return users.filter((u) => {
            const id = Number(u.id);
            if (seen.has(id)) return false;
            seen.add(id);
            return true;
        });
    }

    function attachRowHandlers(row, flashId) {
        const enableCb = qs('.sf-language-review-enable', row);
        const input = qs('.sf-language-review-user-input', row);
        const hidden = qs('.sf-language-review-user-id', row);
        const dropdown = qs('.sf-language-review-user-dropdown', row);
        const statusDot = qs('.sf-language-review-status-dot', row);
        const changeBtn = qs('.sf-language-review-change-btn', row);
        const removeBtn = qs('.sf-language-review-remove-btn', row);

        function refreshStatus() {
            const enabled = !!enableCb.checked;
            const hasUser = !!(hidden && hidden.value);
            state.rows[flashId].enabled = enabled;
            statusDot.classList.toggle('is-ready', enabled && hasUser);
            updateSubmitText();
        }

        function pickUser(user) {
            if (!hidden || !input) return;
            hidden.value = String(user.id);
            input.value = fullName(user);
            state.rows[flashId].selectedUser = {
                user_id: Number(user.id),
                first_name: user.first_name || '',
                last_name: user.last_name || ''
            };
            dropdown.classList.add('hidden');
            refreshStatus();
        }

        function renderDropdown(query) {
            if (!dropdown) return;
            const users = getRowUsers(flashId);
            const q = (query || '').toLowerCase();
            const filtered = users.filter((u) => fullName(u).toLowerCase().includes(q)).slice(0, 20);
            dropdown.innerHTML = filtered.length
                ? filtered.map((u) => `<button type="button" class="sf-language-review-user-option" data-user-id="${Number(u.id)}">${fullName(u)}</button>`).join('')
                : `<div class="sf-language-review-user-empty">${t('noReviewer', 'Ei osumia')}</div>`;
            dropdown.classList.remove('hidden');

            qsa('.sf-language-review-user-option', dropdown).forEach((btn) => {
                btn.addEventListener('click', function () {
                    const uid = Number(this.getAttribute('data-user-id') || 0);
                    const user = users.find((u) => Number(u.id) === uid);
                    if (user) pickUser(user);
                });
            });
        }

        enableCb.addEventListener('change', function () {
            input.disabled = !this.checked;
            if (!this.checked) {
                dropdown.classList.add('hidden');
            }
            refreshStatus();
        });

        input.addEventListener('focus', function () {
            if (!enableCb.checked) return;
            renderDropdown(input.value);
        });

        input.addEventListener('input', function () {
            if (!enableCb.checked) return;
            hidden.value = '';
            state.rows[flashId].selectedUser = null;
            renderDropdown(input.value);
            refreshStatus();
        });

        document.addEventListener('click', function (e) {
            if (!row.contains(e.target)) {
                dropdown.classList.add('hidden');
            }
        });

        if (changeBtn) {
            changeBtn.addEventListener('click', function () {
                enableCb.checked = true;
                input.disabled = false;
                input.focus();
                renderDropdown(input.value);
                refreshStatus();
            });
        }

        if (removeBtn) {
            removeBtn.addEventListener('click', function () {
                enableCb.checked = false;
                input.disabled = true;
                hidden.value = '';
                state.rows[flashId].selectedUser = null;
                dropdown.classList.add('hidden');
                refreshStatus();
            });
        }

        refreshStatus();
    }

    function collectAssignments() {
        const assignments = [];
        for (const key of Object.keys(state.rows)) {
            const flashId = Number(key);
            const row = state.rows[key];
            const selectedUserId = Number(row.selectedUser && row.selectedUser.user_id ? row.selectedUser.user_id : 0);

            if (!row.enabled && row.initialUserId > 0) {
                assignments.push({ action: 'remove', flash_id: flashId });
                continue;
            }
            if (!row.enabled) continue;
            if (!selectedUserId) {
                throw new Error(t('reviewerRequired', 'Valitse käyttäjä'));
            }
            if (row.initialUserId === 0) {
                assignments.push({ action: 'add', flash_id: flashId, user_id: selectedUserId });
            } else if (row.initialUserId !== selectedUserId) {
                assignments.push({ action: 'change', flash_id: flashId, user_id: selectedUserId });
            }
        }
        return assignments;
    }

    function updateSubmitText() {
        const btn = qs('#sfLanguageReviewsSubmitBtn');
        if (!btn) return;
        let addCount = 0;
        let changeCount = 0;
        let removeCount = 0;
        Object.keys(state.rows).forEach((key) => {
            const row = state.rows[key];
            const selected = Number(row.selectedUser && row.selectedUser.user_id ? row.selectedUser.user_id : 0);
            if (!row.enabled && row.initialUserId > 0) removeCount++;
            if (row.enabled && row.initialUserId === 0 && selected > 0) addCount++;
            if (row.enabled && row.initialUserId > 0 && selected > 0 && row.initialUserId !== selected) changeCount++;
        });

        const total = addCount + changeCount + removeCount;
        if (total <= 0) {
            btn.textContent = t('submitDefault', 'Lähetä');
            btn.disabled = true;
            return;
        }
        btn.disabled = false;
        if (addCount > 0 && changeCount === 0 && removeCount === 0) {
            btn.textContent = t('submitRequests', 'Lähetä {n} pyyntöä').replace('{n}', String(addCount));
            return;
        }
        btn.textContent = t('submitUpdates', 'Päivitä {n} muutosta').replace('{n}', String(total));
    }

    async function loadData(flashId) {
        const rowsWrap = qs('#sfLanguageReviewRows');
        if (rowsWrap) rowsWrap.textContent = t('loading', 'Ladataan...');
        const baseUrl = window.SF_BASE_URL || '';
        const response = await fetch(
            baseUrl + '/app/api/suggest_language_reviewer.php?flash_id='
            + encodeURIComponent(String(flashId))
            + '&csrf_token=' + encodeURIComponent(window.SF_CSRF_TOKEN || ''),
            {
            credentials: 'same-origin'
        });
        const data = await response.json();
        if (!data || data.ok !== true) {
            throw new Error((data && data.error) || 'Failed to load');
        }
        state.bundle = Array.isArray(data.bundle) ? data.bundle : [];
        state.existing = data.existing_reviewers || {};
        state.suggestions = data.suggestions || {};
        state.users = Array.isArray(data.all_users) ? data.all_users : [];
        renderRows();
    }

    async function submitChanges() {
        const messageInput = qs('#sfLanguageReviewMessage');
        const submitBtn = qs('#sfLanguageReviewsSubmitBtn');
        const message = messageInput ? messageInput.value.trim() : '';
        const assignments = collectAssignments();
        if (!assignments.length) return;

        const payload = {
            message: message,
            assignments: assignments,
            csrf_token: window.SF_CSRF_TOKEN || ''
        };

        const baseUrl = window.SF_BASE_URL || '';
        submitBtn.disabled = true;
        const originalText = submitBtn.textContent;
        submitBtn.textContent = t('loading', 'Ladataan...');

        try {
            const response = await fetch(baseUrl + '/app/api/request_language_reviews.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify(payload)
            });
            const data = await response.json();
            if (!data || data.ok !== true) {
                throw new Error((data && data.error) || t('errorNetwork', 'Virhe'));
            }
            const added = Number(data.added || 0);
            const changed = Number(data.changed || 0);
            const removed = Number(data.removed || 0);
            if (added > 0) {
                showToast(t('toastSent', 'Lähetetty {n} uutta pyyntöä').replace('{n}', String(added)), 'success');
            } else {
                showToast(t('toastUpdated', 'Päivitetty {n} kieliversion tarkistajat').replace('{n}', String(changed + removed)), 'success');
            }
            window.location.reload();
        } catch (e) {
            showToast(e && e.message ? e.message : t('errorNetwork', 'Verkkovirhe'), 'error');
            submitBtn.disabled = false;
            submitBtn.textContent = originalText;
        }
    }

    function initCounter() {
        const message = qs('#sfLanguageReviewMessage');
        const counter = qs('#sfLanguageReviewCounter');
        if (!message || !counter) return;
        const sync = function () {
            counter.textContent = String(message.value.length) + ' / 2000';
        };
        message.addEventListener('input', sync);
        sync();
    }

    function init() {
        const openBtn = qs('#btnManageLanguageReviews');
        const submitBtn = qs('#sfLanguageReviewsSubmitBtn');
        if (!openBtn || !submitBtn) return;

        openBtn.addEventListener('click', async function () {
            openModal('modalLanguageReviewsManage');
            try {
                await loadData(Number(this.getAttribute('data-flash-id') || window.SF_FLASH_ID || 0));
            } catch (e) {
                showToast(e && e.message ? e.message : t('errorNetwork', 'Virhe'), 'error');
            }
        });

        submitBtn.addEventListener('click', function () {
            submitChanges().catch(() => {});
        });

        initCounter();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
