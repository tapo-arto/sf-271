// assets/js/modules/investigation-context.js
// Progressive field display for investigation context (Step 2)

import { getters } from './state.js';

const { getEl } = getters;

/**
 * Initialize investigation context UI
 * - Shows worksite/date fields only after user makes a choice
 * - Either select a base flash OR enable standalone toggle
 * - In EDIT mode for green type: locks context and shows fields directly
 */
export function initInvestigationContextUI() {
    const relatedFlashSelect = getEl('sf-related-flash');
    const standaloneToggle = getEl('sf-standalone-investigation');
    const worksiteSection = getEl('sf-step2-worksite');
    const incidentSection = getEl('sf-step2-incident');
    const relatedFlashField = relatedFlashSelect?.closest('.sf-field');
    const standaloneField = standaloneToggle?.closest('.sf-field');
    const relatedFlashHelp = getEl('sf-related-flash-help');

    if (!relatedFlashSelect || !standaloneToggle || !worksiteSection) {
        console.log('[Investigation Context] Required elements not found');
        return;
    }

    const form = document.getElementById('sf-form');
    const isTranslationChild = form?.querySelector('input[name="is_translation_child"]')?.value === '1';

    const idInput = document.querySelector('input[name="id"]');
    const checkedGreenRadio = document.querySelector('input[name="type"][value="green"]:checked');
    const hiddenGreenInput = document.querySelector('input[type="hidden"][name="type"][value="green"]');
    const isEditMode = !!(idInput && idInput.value && parseInt(idInput.value, 10) > 0);
    const isGreenType = !!(checkedGreenRadio || hiddenGreenInput);

    console.log('[Investigation Context] Initializing...', {
        isEditMode,
        isGreenType,
        isTranslationChild
    });

    function lockWorksiteForTranslation() {
        const worksiteInput = getEl('sf-worksite');
        const worksiteDropdownToggle = document.querySelector('[data-worksite-dropdown-toggle]');
        const worksiteDropdownMenu = document.querySelector('[data-worksite-dropdown-menu]');
        const worksiteSearchInput = document.querySelector('[data-worksite-search]');
        const worksiteOptionButtons = document.querySelectorAll('[data-worksite-option], .sf-worksite-option, .sf-pill-option, .sf-chip-option');
        const hiddenWorksiteInputs = document.querySelectorAll('input[name="worksite"], input[name="site"], input[name="sf-worksite"]');

        if (worksiteInput) {
            worksiteInput.setAttribute('readonly', 'readonly');
            worksiteInput.setAttribute('aria-readonly', 'true');
            worksiteInput.dataset.locked = '1';
            worksiteInput.classList.add('is-locked');
        }

        if (worksiteDropdownToggle) {
            worksiteDropdownToggle.setAttribute('disabled', 'disabled');
            worksiteDropdownToggle.setAttribute('aria-disabled', 'true');
            worksiteDropdownToggle.setAttribute('aria-expanded', 'false');
            worksiteDropdownToggle.classList.add('is-disabled');
            worksiteDropdownToggle.style.pointerEvents = 'none';
        }

        if (worksiteDropdownMenu) {
            worksiteDropdownMenu.classList.add('is-disabled');
            worksiteDropdownMenu.classList.remove('is-open', 'open', 'active', 'visible', 'show');
            worksiteDropdownMenu.setAttribute('hidden', 'hidden');
            worksiteDropdownMenu.setAttribute('aria-hidden', 'true');
            worksiteDropdownMenu.style.display = 'none';
            worksiteDropdownMenu.style.visibility = 'hidden';
            worksiteDropdownMenu.style.opacity = '0';
            worksiteDropdownMenu.style.pointerEvents = 'none';
            worksiteDropdownMenu.style.maxHeight = '0';
            worksiteDropdownMenu.style.overflow = 'hidden';
        }

        if (worksiteSearchInput) {
            worksiteSearchInput.setAttribute('readonly', 'readonly');
            worksiteSearchInput.setAttribute('aria-readonly', 'true');
            worksiteSearchInput.tabIndex = -1;
        }

        worksiteOptionButtons.forEach((button) => {
            button.setAttribute('disabled', 'disabled');
            button.setAttribute('aria-disabled', 'true');
            button.tabIndex = -1;
            button.classList.add('is-disabled');
            button.style.pointerEvents = 'none';
        });

        hiddenWorksiteInputs.forEach((input) => {
            input.dataset.locked = '1';
        });

        document.documentElement.classList.add('sf-translation-worksite-locked');
    }

    if (isTranslationChild && isGreenType) {
        console.log('[Investigation Context] Translation child + green type detected - hiding investigation source selection and locking worksite');

        if (incidentSection) {
            incidentSection.classList.add('hidden');
            incidentSection.style.display = 'none';
        }

        if (relatedFlashField) {
            relatedFlashField.style.display = 'none';
        }

        if (standaloneField) {
            standaloneField.style.display = 'none';
        }

        if (relatedFlashHelp) {
            relatedFlashHelp.style.display = 'none';
        }

        worksiteSection.classList.remove('hidden');
        worksiteSection.style.display = '';

        lockWorksiteForTranslation();

        if (typeof window.SFUpdateProgress === 'function') {
            window.SFUpdateProgress();
        }

        return;
    }

    if (isEditMode && isGreenType) {
        console.log('[Investigation Context] Edit mode + green type detected - locking context');

        if (relatedFlashField) {
            relatedFlashField.style.display = 'none';
        }

        if (standaloneField) {
            standaloneField.style.display = 'none';
        }

        if (relatedFlashHelp) {
            relatedFlashHelp.style.display = 'none';
        }

        worksiteSection.classList.remove('hidden');
        worksiteSection.style.display = '';

        if (typeof window.SFUpdateProgress === 'function') {
            window.SFUpdateProgress();
        }

        return;
    }

    console.log('[Investigation Context] Create mode or red/yellow type - normal behavior');

    function updateFieldsVisibility() {
        const hasRelatedFlash = relatedFlashSelect.value && relatedFlashSelect.value !== '';
        const isStandalone = standaloneToggle.checked;

        console.log('[Investigation Context] hasRelatedFlash:', hasRelatedFlash, 'isStandalone:', isStandalone);

        if (isStandalone) {
            if (relatedFlashField) {
                relatedFlashField.style.display = 'none';
            }

            if (relatedFlashHelp) {
                relatedFlashHelp.style.display = 'none';
            }

            clearWorksiteFields();

            worksiteSection.classList.remove('hidden');
            worksiteSection.style.display = '';
        } else if (hasRelatedFlash) {
            if (relatedFlashField) {
                relatedFlashField.style.display = '';
            }

            if (relatedFlashHelp) {
                relatedFlashHelp.style.display = '';
            }

            worksiteSection.classList.remove('hidden');
            worksiteSection.style.display = '';
        } else {
            if (relatedFlashField) {
                relatedFlashField.style.display = '';
            }

            if (relatedFlashHelp) {
                relatedFlashHelp.style.display = '';
            }

            worksiteSection.classList.add('hidden');
            worksiteSection.style.display = 'none';
        }

        if (typeof window.SFUpdateProgress === 'function') {
            window.SFUpdateProgress();
        }
    }

    function clearWorksiteFields() {
        const worksiteInput = getEl('sf-worksite');
        const siteDetailInput = getEl('sf-site-detail');
        const dateInput = getEl('sf-date');

        if (worksiteInput && worksiteInput.dataset.fromRelated === '1') {
            worksiteInput.value = '';
            worksiteInput.dataset.fromRelated = '';
        }

        if (siteDetailInput && siteDetailInput.dataset.fromRelated === '1') {
            siteDetailInput.value = '';
            siteDetailInput.dataset.fromRelated = '';
        }

        if (dateInput && dateInput.dataset.fromRelated === '1') {
            dateInput.value = '';
            dateInput.dataset.fromRelated = '';
        }
    }

    relatedFlashSelect.addEventListener('change', function () {
        console.log('[Investigation Context] Related flash changed:', this.value);
        updateFieldsVisibility();
    });

    standaloneToggle.addEventListener('change', function () {
        console.log('[Investigation Context] Standalone toggle changed:', this.checked);
        updateFieldsVisibility();
    });

    updateFieldsVisibility();
}

/**
 * Mark fields as coming from related flash (for clearing logic)
 */
export function markFieldsFromRelatedFlash() {
    const worksiteInput = getEl('sf-worksite');
    const siteDetailInput = getEl('sf-site-detail');
    const dateInput = getEl('sf-date');

    if (worksiteInput) worksiteInput.dataset.fromRelated = '1';
    if (siteDetailInput) siteDetailInput.dataset.fromRelated = '1';
    if (dateInput) dateInput.dataset.fromRelated = '1';
}