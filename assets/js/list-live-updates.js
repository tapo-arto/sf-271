(function () {
    'use strict';

    const cfg = window.SF_LIST_LIVE_UPDATES_CONFIG || {};
    const endpoint = typeof cfg.endpoint === 'string' ? cfg.endpoint : '';
    const pollIntervalMs = Number(cfg.pollIntervalMs) > 0 ? Number(cfg.pollIntervalMs) : 20000;
    const newCommentsLabel = typeof cfg.newCommentsLabel === 'string' ? cfg.newCommentsLabel : '';

    if (!endpoint) {
        return;
    }

    let inFlight = false;

    function getVisibleCards() {
        return Array.from(document.querySelectorAll('.card[data-flash-id]'))
            .filter((card) => card.offsetParent !== null);
    }

    function parseFlashId(card) {
        const id = Number.parseInt(card.dataset.flashId || '', 10);
        return Number.isFinite(id) && id > 0 ? id : null;
    }

    function updateCommentBadge(card, count) {
        const safeCount = Math.max(0, Number.parseInt(String(count), 10) || 0);
        const title = safeCount > 0 ? `${safeCount} ${newCommentsLabel}`.trim() : '';

        card.dataset.newCommentCount = String(safeCount);

        const desktopBadge = card.querySelector('[data-role="comment-badge"]');
        const desktopCount = card.querySelector('[data-role="comment-count"]');
        if (desktopBadge && desktopCount) {
            desktopCount.textContent = String(safeCount);
            desktopBadge.title = title;
            desktopBadge.hidden = safeCount <= 0;
        }

        const mobileBadge = card.querySelector('[data-role="comment-badge-mobile"]');
        const mobileCount = card.querySelector('[data-role="comment-count-mobile"]');
        if (mobileBadge && mobileCount) {
            mobileCount.textContent = String(safeCount);
            mobileBadge.title = title;
            mobileBadge.hidden = safeCount <= 0;
        }
    }

    function updateStateBadge(card, update) {
        if (!update || typeof update !== 'object') {
            return;
        }

        if (typeof update.state === 'string' && update.state !== '') {
            card.dataset.state = update.state;
        }
        if (typeof update.updated_at === 'string') {
            card.dataset.updated = update.updated_at;
        }

        const statusEl = card.querySelector('[data-role="state-badge"]');
        if (!statusEl) {
            return;
        }

        if (typeof update.state_label === 'string' && update.state_label !== '') {
            statusEl.textContent = update.state_label;
        }

        if (typeof update.state_badge_class === 'string' && update.state_badge_class !== '') {
            statusEl.className = `status ${update.state_badge_class}`;
        }
    }

    async function pollUpdates() {
        if (inFlight || document.hidden) {
            return;
        }

        const cards = getVisibleCards();
        if (cards.length === 0) {
            return;
        }

        const idToCard = new Map();
        const ids = [];
        cards.forEach((card) => {
            const id = parseFlashId(card);
            if (!id || idToCard.has(id)) {
                return;
            }
            idToCard.set(id, card);
            ids.push(id);
        });

        if (ids.length === 0) {
            return;
        }

        inFlight = true;
        try {
            const response = await fetch(endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: JSON.stringify({ ids })
            });

            if (!response.ok) {
                throw new Error(`HTTP ${response.status}`);
            }

            const payload = await response.json();
            if (!payload || payload.ok !== true || !payload.updates || typeof payload.updates !== 'object') {
                return;
            }

            Object.keys(payload.updates).forEach((idKey) => {
                const id = Number.parseInt(idKey, 10);
                if (!Number.isFinite(id)) {
                    return;
                }

                const card = idToCard.get(id);
                if (!card) {
                    return;
                }

                const update = payload.updates[idKey];
                updateStateBadge(card, update);
                if (update && Object.prototype.hasOwnProperty.call(update, 'new_comment_count')) {
                    updateCommentBadge(card, update.new_comment_count);
                }
            });
        } catch (error) {
            console.debug('List live updates polling failed:', error);
        } finally {
            inFlight = false;
        }
    }

    function init() {
        pollUpdates();
        window.setInterval(pollUpdates, pollIntervalMs);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) {
                pollUpdates();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
