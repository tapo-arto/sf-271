/**
 * Safetyflash - Communications Modal (Multi-step)
 * Handles the 4-step "Send to Communications" modal workflow
 */
(function () {
    'use strict';

    // Utilities
    function qs(sel, ctx) { return (ctx || document).querySelector(sel); }
    function qsa(sel, ctx) { return Array.from((ctx || document).querySelectorAll(sel)); }

    // Get terms from window.SF_TERMS
    function getTerm(key, fallback) {
        return (window.SF_TERMS && window.SF_TERMS[key]) || fallback || key;
    }

    // Sync chip visual state with checkbox state
    function syncChipState(chip) {
        var checkbox = chip.querySelector('input[type="checkbox"]');
        if (checkbox) {
            chip.classList.toggle('selected', checkbox.checked);
        }
    }

    function initCommsModal() {
        // IMPORTANT: Check if modal exists on this page
        var modal = document.getElementById('modalToComms');
        if (!modal) return;

        var currentStep = 1;
        var totalSteps = 4;

        // Navigation buttons
        var btnStep1Next = document.getElementById('btnCommsStep1Next');
        var btnStep2Back = document.getElementById('btnCommsStep2Back');
        var btnStep2Next = document.getElementById('btnCommsStep2Next');
        var btnStep3Back = document.getElementById('btnCommsStep3Back');
        var btnStep3Next = document.getElementById('btnCommsStep3Next');
        var btnStep4Back = document.getElementById('btnCommsStep4Back');
        var btnCommsSend = document.getElementById('btnCommsSend');

        // Notify supervisors toggle
        var notifyToggle = document.getElementById('commsNotifySupervisors');
        var notifyCountEl = document.getElementById('commsNotifyCount');
        var debounceNotify = null;
        var lastNotifyCount = null;

        // Language chips toggle behavior - FIXED
        qsa('.sf-chip-toggle').forEach(function (chip) {
            var checkbox = chip.querySelector('input[type="checkbox"]');
            if (!checkbox) return;

            // Handle checkbox change to update visual state
            checkbox.addEventListener('change', function () {
                syncChipState(chip);
            });

            // Initialize state
            syncChipState(chip);
        });

        // Flag chips toggle behavior
        qsa('.sf-flag-chip').forEach(function (label) {
            label.addEventListener('click', function (e) {
                e.preventDefault();
                var input = this.querySelector('input[type="checkbox"]');
                if (input) {
                    input.checked = !input.checked;
                    // Trigger change event for any listeners
                    input.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        });

        // Toggle wider distribution label
        var widerDistribution = document.getElementById('widerDistribution');
        var widerDistributionLabel = document.getElementById('widerDistributionLabel');

        if (widerDistribution && widerDistributionLabel) {
            widerDistribution.addEventListener('change', function () {
                if (this.checked) {
                    widerDistributionLabel.textContent = getTerm('comms_wider_distribution_yes', 'Kyllä, lähetä laajempaan jakeluun');
                } else {
                    widerDistributionLabel.textContent = getTerm('comms_wider_distribution_no', 'Ei, vain valitut näytöt');
                }
            });
        }

        function showStep(step) {
            for (var i = 1; i <= totalSteps; i++) {
                var stepEl = document.getElementById('commsStep' + i);
                if (stepEl) {
                    if (i === step) {
                        stepEl.classList.remove('hidden');
                    } else {
                        stepEl.classList.add('hidden');
                    }
                }
            }
            currentStep = step;

            // Update summary when reaching step 4
            if (step === 4) {
                updateSummary();
            }

            // Fetch notification recipient count when reaching step 3
            if (step === 3) {
                updateNotifyCount();
            }
        }

        function updateSummary() {
            // Languages
            var selectedLangs = [];
            qsa('#commsForm input[name="languages[]"]:checked').forEach(function (input) {
                var chip = input.closest('.sf-chip-toggle');
                if (chip) {
                    var label = chip.querySelector('span');
                    if (label) selectedLangs.push(label.textContent);
                }
            });
            var langsSummary = document.getElementById('commsSummaryLanguages');
            if (langsSummary) {
                langsSummary.textContent = selectedLangs.length > 0 ? selectedLangs.join(', ') : getTerm('comms_summary_none', 'Ei valintoja');
            }

            // Screens summary — count selected display targets
            var screensSummary = document.getElementById('commsSummaryScreens');
            if (screensSummary) {
                var checkedDisplays = qsa('#commsStep2 .dt-display-chip-cb:checked').length;
                var totalDisplays = qsa('#commsStep2 .dt-display-chip-cb').length;
                if (checkedDisplays === 0) {
                    screensSummary.textContent = getTerm('comms_summary_none', 'Ei valintoja');
                } else if (totalDisplays > 0 && checkedDisplays === totalDisplays) {
                    screensSummary.textContent = getTerm('comms_screens_all', 'Kaikki näytöt');
                } else {
                    screensSummary.textContent = checkedDisplays + ' ' + getTerm('comms_summary_displays', 'näyttöä');
                }
            }

            // Distribution (simplified toggle)
            var distSummary = document.getElementById('commsSummaryDistribution');
            if (distSummary) {
                var widerDist = document.getElementById('widerDistribution');
                if (widerDist && widerDist.checked) {
                    distSummary.textContent = getTerm('comms_summary_yes', 'Kyllä');
                } else {
                    distSummary.textContent = getTerm('comms_summary_no', 'Ei');
                }
            }

            // Notification summary
            var notifSummary = document.getElementById('commsSummaryNotification');
            if (notifSummary) {
                if (notifyToggle && notifyToggle.checked) {
                    var countPart = lastNotifyCount !== null
                        ? ' (' + formatNotifyCount(lastNotifyCount) + ')'
                        : '';
                    notifSummary.textContent = getTerm('comms_notify_summary_on', '✉️ Ilmoitus lähetetään') + countPart;
                } else {
                    notifSummary.textContent = getTerm('comms_notify_summary_off', 'Ei ilmoitusta');
                }
            }
        }

        function formatNotifyCount(count) {
            if (count === 0) {
                return getTerm('comms_notify_recipients_none', 'Ei vastaanottajia');
            }
            var tpl = getTerm('comms_notify_recipients_count', 'Ilmoitus lähetetään %d henkilölle');
            return tpl.replace('%d', count);
        }

        function getCheckedDisplayKeyIds() {
            var ids = [];
            var commsStep2 = document.getElementById('commsStep2');
            if (!commsStep2) { return ids; }
            qsa('.dt-display-chip-cb:checked, .sf-display-chip-input:checked', commsStep2).forEach(function (cb) {
                var v = parseInt(cb.value, 10);
                if (v > 0) { ids.push(v); }
            });
            return ids;
        }

        function updateNotifyCount() {
            if (!notifyToggle || !notifyCountEl) { return; }

            if (!notifyToggle.checked) {
                notifyCountEl.textContent = '';
                lastNotifyCount = null;
                updateNotifySummary();
                return;
            }

            var ids = getCheckedDisplayKeyIds();
            if (ids.length === 0) {
                notifyCountEl.textContent = formatNotifyCount(0);
                lastNotifyCount = 0;
                updateNotifySummary();
                return;
            }

            var flashId = window.SF_FLASH_ID || new URLSearchParams(window.location.search).get('id');
            var baseUrl = window.SF_BASE_URL || '';
            var csrfInput = document.querySelector('#commsForm input[name="csrf_token"]');
            var csrfToken = csrfInput ? csrfInput.value : '';

            notifyCountEl.textContent = getTerm('comms_notify_recipients_loading', 'Lasketaan...');

            var body = new URLSearchParams();
            body.append('flash_id', flashId);
            body.append('csrf_token', csrfToken);
            ids.forEach(function (id) { body.append('display_key_ids[]', id); });

            fetch(baseUrl + '/app/api/worksite_notification_count.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            })
            .then(function (res) { return res.json(); })
            .then(function (data) {
                if (data && data.ok) {
                    lastNotifyCount = data.count;
                    notifyCountEl.textContent = formatNotifyCount(data.count);
                } else {
                    lastNotifyCount = null;
                    notifyCountEl.textContent = '';
                }
                updateNotifySummary();
            })
            .catch(function () {
                lastNotifyCount = null;
                notifyCountEl.textContent = '';
                updateNotifySummary();
            });
        }

        function updateNotifySummary() {
            var notifSummary = document.getElementById('commsSummaryNotification');
            if (!notifSummary) { return; }
            if (notifyToggle && notifyToggle.checked) {
                var countPart = lastNotifyCount !== null
                    ? ' (' + formatNotifyCount(lastNotifyCount) + ')'
                    : '';
                notifSummary.textContent = getTerm('comms_notify_summary_on', '✉️ Ilmoitus lähetetään') + countPart;
            } else {
                notifSummary.textContent = getTerm('comms_notify_summary_off', 'Ei ilmoitusta');
            }
        }

        function scheduleNotifyUpdate() {
            clearTimeout(debounceNotify);
            debounceNotify = setTimeout(updateNotifyCount, 350);
        }

        // Step navigation
        if (btnStep1Next) {
            btnStep1Next.addEventListener('click', function () {
                var selectedCount = qsa('#commsForm input[name="languages[]"]:checked').length;
                if (selectedCount === 0) {
                    alert(getTerm('comms_error_no_languages', 'Valitse vähintään yksi kieliversio'));
                    return;
                }
                showStep(2);
            });
        }

        if (btnStep2Back) {
            btnStep2Back.addEventListener('click', function () {
                showStep(1);
            });
        }

        if (btnStep2Next) {
            btnStep2Next.addEventListener('click', function () {
                showStep(3);
            });
        }

        if (btnStep3Back) {
            btnStep3Back.addEventListener('click', function () {
                showStep(2);
            });
        }

        if (btnStep3Next) {
            btnStep3Next.addEventListener('click', function () {
                showStep(4);
            });
        }

        if (btnStep4Back) {
            btnStep4Back.addEventListener('click', function () {
                showStep(3);
            });
        }

        // Notify supervisors toggle
        if (notifyToggle) {
            notifyToggle.addEventListener('change', function () {
                this.setAttribute('aria-checked', this.checked ? 'true' : 'false');
                updateNotifyCount();
            });
        }

        // Listen for display target changes in step 2 to update notification count
        var commsStep2El = document.getElementById('commsStep2');
        if (commsStep2El) {
            commsStep2El.addEventListener('change', function (e) {
                if (e.target && (
                    e.target.classList.contains('dt-display-chip-cb') ||
                    e.target.classList.contains('sf-display-chip-input')
                )) {
                    scheduleNotifyUpdate();
                }
            });
        }

        // Form submission
        if (btnCommsSend) {
            btnCommsSend.addEventListener('click', function (e) {
                e.preventDefault();

                var form = document.getElementById('commsForm');
                if (!form) return;

                // FormData now captures all fields from the form (all steps)
                var formData = new FormData(form);

                // Debug log
                console.log('=== DEBUG: Form submission ===');
                console.log('Form data being sent:');
                for (var pair of formData.entries()) {
                    console.log(pair[0] + ': ' + pair[1]);
                }

                // Get flash ID from URL or window
                var flashId = window.SF_FLASH_ID || new URLSearchParams(window.location.search).get('id');
                var baseUrl = window.SF_BASE_URL || '';

                // Show loading state
                btnCommsSend.disabled = true;
                btnCommsSend.textContent = getTerm('status_sending', 'Lähetetään...');

                // Submit via AJAX
                fetch(baseUrl + '/app/actions/send_to_comms.php?id=' + flashId, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: formData
                })
                    .then(function (response) {
                        // Check if response is ok (2xx status)
                        if (response.ok) {
                            return response.json().catch(function () {
                                // If not JSON but response is OK, treat as success
                                return { ok: true };
                            });
                        }
                        return response.json().catch(function () {
                            return { ok: false, message: getTerm('error_sending', 'Virhe lähetyksessä') };
                        });
                    })
                    .then(function (data) {
                        if (data && data.ok === true) {
                            // Success - close modal and reload
                            if (window.closeModal) {
                                window.closeModal('modalToComms');
                            }
                            if (data.redirect) {
                                window.location.href = data.redirect;
                            } else {
                                window.location.reload();
                            }
                        } else {
                            // Error
                            alert(data && data.message ? data.message : getTerm('error_sending', 'Virhe lähetyksessä'));
                            btnCommsSend.disabled = false;
                            btnCommsSend.textContent = getTerm('btn_send_comms', 'Lähetä viestintään');
                        }
                    })
                    .catch(function (err) {
                        console.error('Send to comms error:', err);
                        alert(getTerm('error_network', 'Verkkovirhe'));
                        btnCommsSend.disabled = false;
                        btnCommsSend.textContent = getTerm('btn_send_comms', 'Lähetä viestintään');
                    });
            });
        }

        // Reset to step 1 when modal opens
        var commsModal = document.getElementById('modalToComms');
        if (commsModal) {
            // Watch for modal opening
            var observer = new MutationObserver(function (mutations) {
                mutations.forEach(function (mutation) {
                    if (mutation.attributeName === 'class') {
                        var isVisible = !commsModal.classList.contains('hidden');
                        if (isVisible) {
                            showStep(1);
                            // Re-sync chip visual states
                            qsa('.sf-chip-toggle').forEach(syncChipState);
                        }
                    }
                });
            });
            observer.observe(commsModal, { attributes: true });
        }
    }

    // Initialize on DOMContentLoaded
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCommsModal);
    } else {
        initCommsModal();
    }
})();