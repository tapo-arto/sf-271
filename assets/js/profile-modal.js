
// assets/js/profile-modal.js
(function () {
    "use strict";

    const base = window.SF_BASE_URL || '';

    function showToast(message, type = 'success') {
        if (typeof window.sfToast === 'function') {
            window.sfToast(type, message);
        }
    }

    function setPasswordFeedback(message, type) {
        const feedback = document.getElementById('sfPasswordModalFeedback');
        if (!feedback) {
            showToast(message, type);
            return;
        }

        feedback.textContent = message || '';
        feedback.style.display = message ? 'block' : 'none';
        feedback.style.color = type === 'success' ? '#059669' : '#dc2626';
    }

    function clearPasswordFeedback() {
        setPasswordFeedback('', 'success');
    }

    function clearProfileUiLocks() {
        document.body.classList.remove('sf-modal-open');
        document.body.classList.remove('sf-loading');
        document.body.removeAttribute('aria-busy');
    }

    function restoreModalStateIfNeeded() {
        const visibleModal = document.querySelector('.sf-modal:not(.hidden), .sf-library-modal:not(.hidden)');
        if (visibleModal) {
            document.body.classList.add('sf-modal-open');
        }
    }

    function openProfileModalElement(modal) {
        if (!modal) return;
        modal.classList.remove('hidden');
        document.body.classList.add('sf-modal-open');
    }

    function closeProfileModalElement(modal) {
        if (!modal) return;

        modal.classList.add('hidden');

        clearProfileUiLocks();
        restoreModalStateIfNeeded();
    }

    document.addEventListener('click', function (e) {
        var tab = e.target.closest('.sf-profile-tab');
        if (!tab) return;

        var tabName = tab.dataset.tab;
        var modal = tab.closest('.sf-modal');
        if (!modal) return;

        modal.querySelectorAll('.sf-profile-tab').forEach(function (t) {
            t.classList.remove('active');
        });
        modal.querySelectorAll('.sf-profile-tab-content').forEach(function (c) {
            c.classList.remove('active');
        });

        tab.classList.add('active');
        var targetContent = modal.querySelector('[data-tab-content="' + tabName + '"]');
        if (targetContent) {
            targetContent.classList.add('active');
        }

        if (tabName === 'password') {
            clearPasswordFeedback();
        }
    });

    document.addEventListener('click', function (e) {
        const opener = e.target.closest('[data-modal-open="modalProfile"]');
        if (!opener) return;

        e.preventDefault();
        e.stopPropagation();

        const profileTab = opener.dataset.profileTab;
        openProfileModal(profileTab);
    });

    document.addEventListener('click', function (e) {
        const modal = document.getElementById('modalProfile');
        if (!modal || modal.classList.contains('hidden')) {
            return;
        }

        const closeButton = e.target.closest(
            '#modalProfile [data-modal-close], ' +
            '#modalProfile .sf-modal-close, ' +
            '#modalProfile .modal-close, ' +
            '#modalProfile .sf-close, ' +
            '#modalProfile .btn-close, ' +
            '#modalProfile [aria-label="Close"], ' +
            '#modalProfile [aria-label="Sulje"]'
        );

        if (closeButton) {
            e.preventDefault();
            e.stopPropagation();
            closeProfileModalElement(modal);
            return;
        }

        if (e.target === modal) {
            e.preventDefault();
            e.stopPropagation();
            closeProfileModalElement(modal);
        }
    }, true);

    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape') return;

        const modal = document.getElementById('modalProfile');
        if (!modal || modal.classList.contains('hidden')) {
            return;
        }

        closeProfileModalElement(modal);
    });

    document.addEventListener('click', function (e) {
        const modal = document.getElementById('modalProfile');
        if (!modal || modal.classList.contains('hidden')) return;

        const logoutOpener = e.target.closest('[data-modal-open="#sfLogoutModal"]');
        if (!logoutOpener) return;

        closeProfileModalElement(modal);
        // Allow event to propagate so modals.js can open the logout modal
    }, true);

    async function openProfileModal(tabToOpen) {
        const modal = document.getElementById('modalProfile');
        if (!modal) return;

        document.body.classList.add('sf-loading');
        document.body.setAttribute('aria-busy', 'true');

        try {
            const response = await fetch(base + '/app/api/profile_get.php', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const data = await response.json();

            if (data.ok && data.user) {
                document.getElementById('modalProfileFirst').value = data.user.first_name || '';
                document.getElementById('modalProfileLast').value = data.user.last_name || '';
                document.getElementById('modalProfileEmail').value = data.user.email || '';
                document.getElementById('modalProfileRole').textContent = data.user.role_name || '-';

                const worksiteSelect = document.getElementById('modalProfileWorksite');
                if (worksiteSelect && data.worksites) {
                    const firstOption = worksiteSelect.options[0];
                    worksiteSelect.innerHTML = '';
                    worksiteSelect.appendChild(firstOption);

                    data.worksites.forEach(function (ws) {
                        const option = document.createElement('option');
                        option.value = ws.id;
                        option.textContent = ws.name;
                        if (parseInt(ws.id, 10) === parseInt(data.user.home_worksite_id || 0, 10)) {
                            option.selected = true;
                        }
                        worksiteSelect.appendChild(option);
                    });
                }

                const emailNotifCheckbox = document.getElementById('modalProfileEmailNotifications');
                if (emailNotifCheckbox && data.user.email_notifications_enabled !== undefined) {
                    emailNotifCheckbox.checked = data.user.email_notifications_enabled == 1;
                }
            }
        } catch (err) {
            console.error('Error loading profile:', err);
        } finally {
            document.body.classList.remove('sf-loading');
            document.body.removeAttribute('aria-busy');
        }

        modal.querySelectorAll('.sf-profile-tab').forEach(function (t) {
            t.classList.remove('active');
        });
        modal.querySelectorAll('.sf-profile-tab-content').forEach(function (c) {
            c.classList.remove('active');
        });

        const targetTab = tabToOpen || 'basics';
        const validTabs = ['basics', 'settings', 'password'];
        const safeTab = validTabs.includes(targetTab) ? targetTab : 'basics';
        const tabButton = modal.querySelector('[data-tab="' + safeTab + '"]');
        const tabContent = modal.querySelector('[data-tab-content="' + safeTab + '"]');

        if (tabButton) tabButton.classList.add('active');
        if (tabContent) tabContent.classList.add('active');

        clearPasswordFeedback();

        const passwordFormElement = document.getElementById('sfPasswordModalForm');
        if (passwordFormElement) {
            passwordFormElement.reset();
        }

        openProfileModalElement(modal);
    }

    const profileForm = document.getElementById('sfProfileModalForm');
    if (profileForm) {
        profileForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const formData = new FormData(this);

            try {
                const response = await fetch(base + '/app/api/profile_update.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.ok) {
                    const modal = document.getElementById('modalProfile');
                    if (modal) {
                        closeProfileModalElement(modal);
                    }

                    window.location.reload();
                } else {
                    showToast(result.error || (window.SF_I18N?.error || 'Virhe tallennuksessa'), 'error');
                }
            } catch (err) {
                console.error('Profile update error:', err);
                showToast(window.SF_I18N?.error || 'Virhe tallennuksessa', 'error');
            }
        });
    }

    const settingsForm = document.getElementById('sfProfileSettingsForm');
    if (settingsForm) {
        settingsForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            const formData = new FormData(this);

            try {
                const response = await fetch(base + '/app/api/profile_update.php', {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                if (result.ok) {
                    const modal = document.getElementById('modalProfile');
                    if (modal) {
                        closeProfileModalElement(modal);
                    }

                    window.location.reload();
                } else {
                    showToast(result.error || (window.SF_I18N?.error || 'Virhe tallennuksessa'), 'error');
                }
            } catch (err) {
                console.error('Profile update error:', err);
                showToast(window.SF_I18N?.error || 'Virhe tallennuksessa', 'error');
            }
        });
    }

    const passwordForm = document.getElementById('sfPasswordModalForm');
    if (passwordForm) {
        passwordForm.addEventListener('submit', async function (e) {
            e.preventDefault();

            clearPasswordFeedback();

            const newPass = document.getElementById('modalNewPassword').value;
            const confirmPass = document.getElementById('modalConfirmPassword').value;
            const submitButton = this.querySelector('button[type="submit"]');

            if (newPass !== confirmPass) {
                const message = window.SF_I18N?.passwordsMismatch || 'Salasanat eivät täsmää';
                setPasswordFeedback(message, 'error');
                showToast(message, 'error');
                return;
            }

            const formData = new FormData(this);

            if (submitButton) {
                submitButton.disabled = true;
            }

            try {
                const response = await fetch(base + '/app/api/profile_password.php', {
                    method: 'POST',
                    body: formData,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                });

                const rawText = await response.text();
                let result = null;

                try {
                    result = JSON.parse(rawText);
                } catch (parseError) {
                    console.error('Password change JSON parse error:', parseError, rawText);
                    const message = 'Salasanan vaihto onnistui mahdollisesti, mutta palvelimen vastausta ei voitu lukea.';
                    setPasswordFeedback(message, 'error');
                    showToast(message, 'error');
                    return;
                }

                if (response.ok && result.ok) {
                    this.reset();

                    const successMessage = result.message || window.SF_I18N?.passwordChanged || 'Salasana vaihdettu onnistuneesti!';
                    setPasswordFeedback(successMessage, 'success');
                    showToast(successMessage, 'success');
                } else {
                    const errorMessage = result.error || window.SF_I18N?.error || 'Virhe salasanan vaihdossa';
                    setPasswordFeedback(errorMessage, 'error');
                    showToast(errorMessage, 'error');
                }
            } catch (err) {
                console.error('Password change error:', err);
                const message = window.SF_I18N?.error || 'Virhe salasanan vaihdossa';
                setPasswordFeedback(message, 'error');
                showToast(message, 'error');
            } finally {
                if (submitButton) {
                    submitButton.disabled = false;
                }
            }
        });
    }
})();