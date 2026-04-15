// assets/js/modules/preview-update.js

import { state, getters } from './state.js';
import { checkAndShowSupervisorSection } from './supervisor-approval.js';

const previewTranslations = {
    fi: {
        titlePlaceholder: 'Otsikko...',
        descPlaceholder: 'Kuvaus...',
        sitePlaceholder: 'Työmaa:',
        whenPlaceholder: 'Milloin? '
    },
    sv: {
        titlePlaceholder: 'Rubrik...',
        descPlaceholder: 'Beskrivning...',
        sitePlaceholder: 'Arbetsplats:',
        whenPlaceholder: 'När?'
    },
    en: {
        titlePlaceholder: 'Title...',
        descPlaceholder: 'Description...',
        sitePlaceholder: 'Worksite:',
        whenPlaceholder: 'When?'
    },
    it: {
        titlePlaceholder: 'Titolo...',
        descPlaceholder: 'Descrizione...',
        sitePlaceholder: 'Cantiere:',
        whenPlaceholder: 'Quando?'
    },
    el: {
        titlePlaceholder: 'Τίτλος...',
        descPlaceholder: 'Περιγραφή...',
        sitePlaceholder: 'Εργοτάξιο:',
        whenPlaceholder: 'Πότε;'
    }
};

const { getEl, qs, qsa } = getters;

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

export function getPreviewText(key) {
    const lang = state.selectedLang || 'fi';
    return (previewTranslations[lang] && previewTranslations[lang][key])
        || previewTranslations.fi[key]
        || key;
}

export function updatePreviewLabels() {
    const siteLabel = getEl('sfPreviewSiteLabel');
    const whenLabel = getEl('sfPreviewDateLabel');
    if (siteLabel) siteLabel.textContent = getPreviewText('sitePlaceholder');
    if (whenLabel) whenLabel.textContent = getPreviewText('whenPlaceholder');
}

export function handleConditionalFields() {
    const isInvestigation = state.selectedType === 'green';
    const isEnsitiedote   = state.selectedType === 'red';
    const form = document.getElementById('sf-form');
    const isTranslationChild = form?.querySelector('input[name="is_translation_child"]')?.value === '1';

    const toggle = (id, show) => {
        const el = getEl(id);
        if (el) {
            el.classList.toggle('hidden', !show);
        }
    };

    toggle('sfPreviewContainerRedYellow', !isInvestigation);
    toggle('sfPreviewContainerGreen', isInvestigation);

    const standaloneCheckbox = getEl('sf-standalone-investigation');
    const standaloneOption = getEl('sf-standalone-option');
    const relatedFlashField = getEl('sf-related-flash')?.closest('.sf-field');
    const relatedFlashHelp = getEl('sf-related-flash-help');
    const incidentSection = getEl('sf-step2-incident');
    const worksiteSection = getEl('sf-step2-worksite');

    if (isInvestigation && isTranslationChild) {
        toggle('sf-step2-incident', false);

        if (incidentSection) {
            incidentSection.style.display = 'none';
        }

        if (relatedFlashField) {
            relatedFlashField.style.display = 'none';
        }

        if (relatedFlashHelp) {
            relatedFlashHelp.style.display = 'none';
        }

        if (standaloneOption) {
            standaloneOption.style.display = 'none';
        }

        if (worksiteSection) {
            worksiteSection.classList.remove('hidden');
            worksiteSection.style.display = '';
        }

        toggle('sf-investigation-extra', true);
        toggle('sf-original-flash-preview', false);
        return;
    }

    toggle('sf-step2-incident', isInvestigation);

    if (isInvestigation && standaloneCheckbox && standaloneOption) {
        const isStandalone = standaloneCheckbox.checked;

        standaloneOption.style.display = '';

        if (relatedFlashField) {
            relatedFlashField.style.display = isStandalone ? 'none' : '';
        }

        if (relatedFlashHelp) {
            relatedFlashHelp.style.display = isStandalone ? 'none' : '';
        }

        if (isStandalone) {
            toggle('sf-original-flash-preview', false);
        }
    } else if (standaloneOption) {
        standaloneOption.style.display = 'none';
    }

    if (worksiteSection) {
        if (!isInvestigation) {
            worksiteSection.classList.remove('hidden');
            worksiteSection.style.display = '';
            console.log('[ConditionalFields] Red/Yellow mode - worksite section is visible');
        }
    }

    toggle('sf-investigation-extra', isInvestigation);

    // Loukkaantuneet kehonosat — vain Ensitiedotteessa (type=red)
    toggle('sf-injury-section', isEnsitiedote);

    const standaloneChecked = standaloneCheckbox?.checked || false;
    toggle(
        'sf-original-flash-preview',
        isInvestigation && !!getEl('sf-related-flash')?.value && !standaloneChecked
    );
}

export function updatePreview() {
    const card = getEl('sfPreviewCard');
    const cardGreen = getEl('sfPreviewCardGreen');
    const currentType = qs('input[name="type"]:checked')?.value || state.selectedType;
    const currentLang = qs('input[name="lang"]:checked')?.value || state.selectedLang || 'fi';
    const base = (card?.dataset.baseUrl || cardGreen?.dataset.baseUrl || '');

    if (!currentType) return;

    if (card) {
        card.dataset.type = currentType;
        card.dataset.lang = currentLang;
    }

    const bgImg = getEl('sfPreviewBg');
    if (bgImg && currentType !== 'green') {
        const bgUrl = `${base}/assets/img/templates/SF_bg_${currentType}_${currentLang}.jpg`;
        bgImg.src = bgUrl;
    }

    // Otsikko
    const titleEl = getEl('sfPreviewTitle');
    if (titleEl) {
        titleEl.textContent = getEl('sf-short-text')?.value || getPreviewText('titlePlaceholder');
    }

    // Kuvaus
    const descEl = getEl('sfPreviewDesc');
    if (descEl) {
        const descText = getEl('sf-description')?.value || '';
        if (descText) {
            descEl.innerHTML = escapeHtml(descText).replace(/\n/g, '<br>');
        } else {
            descEl.textContent = getPreviewText('descPlaceholder');
        }
    }

    // Työmaa (worksite + detail)
    const worksite = getEl('sf-worksite')?.value || '';
    const detail = getEl('sf-site-detail')?.value || '';
    const siteText = [worksite, detail].filter(Boolean).join(' – ');
    const previewSiteEl = getEl('sfPreviewSite');
    if (previewSiteEl) previewSiteEl.textContent = siteText || '–';

    // Päivämäärä (ensisijaisesti sf-date, fallback sf-occurred_at)
    const dateRaw = getEl('sf-date')?.value || getEl('sf-occurred_at')?.value || '';
    const dt = dateRaw ? new Date(dateRaw) : null;
    const dateFmt = dt
        ? dt.toLocaleString(
            currentLang === 'fi' ? 'fi-FI' : (currentLang === 'sv' ? 'sv-SE' : 'en-GB'),
            {
                day: '2-digit',
                month: '2-digit',
                year: 'numeric',
                hour: '2-digit',
                minute: '2-digit'
            }
        )
        : '–';

    const previewDateEl = getEl('sfPreviewDate');
    if (previewDateEl) previewDateEl.textContent = dateFmt;

    // ===== GRID BITMAP (lopullinen kuvakollaasi) =====
    const gridVal = (getEl('sf-grid-bitmap')?.value || '').trim();

    const gridSrc = (() => {
        if (!gridVal) return '';
        if (gridVal.startsWith('data:image/')) return gridVal;
        return `${base}/uploads/grids/${gridVal}`;
    })();

    const imgRY = getEl('sfGridBitmapImg');
    if (imgRY && gridSrc) imgRY.src = gridSrc;

    const imgG = getEl('sfGridBitmapImgGreen');
    if (imgG && gridSrc) imgG.src = gridSrc;

    updatePreviewLabels();

    if (currentType === 'green' && window.PreviewTutkinta?.updatePreviewContent) {
        window.PreviewTutkinta.updatePreviewContent();
    }
}


// Keskitetty preview-alustus - TÄSSÄ eikä bootstrap.js:ssä
function initializePreview(type) {
    if (type === 'green') {
        if (window.PreviewTutkinta) {
            window.PreviewTutkinta.reinit();
        }
    } else {
        if (window.Preview) {
            window.Preview.reinit();
        }
    }

    // Alusta annotaatiot aina kun preview alustetaan
    if (window.Annotations?.init) {
        window.Annotations.init();
    }
}

export function updateUIForStep(stepNumber) {
    // Note: Progress bar and step indicators are now managed by navigation.js
    // This function handles step-specific UI updates like grid initialization and preview display

    const gridSelector = getEl('sfGridSelector');
    if (gridSelector) {
        gridSelector.style.display =
            (stepNumber === state.maxSteps) ? 'block' : 'none';
    }

    // ===== GRID-VALINNAT (VAIHE 5) =====
    if (stepNumber === 5 && typeof window.SF_GRID_STEP_INIT === 'function') {
        const isPlaceholder = (src) => {
            if (!src) return true;
            const s = String(src).toLowerCase();
            if (s.includes('camera-placeholder')) return true;
            if (s.includes('camera')) return true;
            if (s.includes('placeholder')) return true;
            if (s.includes('no-image')) return true;
            if (s === '' || s === 'about:blank') return true;
            return false;
        };

        const urls = [];
        const t1 = getEl('sfImageThumb1') || getEl('sf-upload-preview1');
        const t2 = getEl('sfImageThumb2') || getEl('sf-upload-preview2');
        const t3 = getEl('sfImageThumb3') || getEl('sf-upload-preview3');

        [t1, t2, t3].forEach((el) => {
            if (!el || !el.src) return;
            if (isPlaceholder(el.src)) return;
            urls.push(el.src);
        });

        const count = urls.length || 1;
        window.SF_GRID_STEP_INIT(count, urls);
    }

    if (stepNumber === state.maxSteps) {
        const currentType = qs('input[name="type"]:checked')?.value;

        // Näytä oikea container
        const containerRY = getEl('sfPreviewContainerRedYellow');
        const containerG = getEl('sfPreviewContainerGreen');

        if (currentType === 'green') {
            if (containerRY) containerRY.classList.add('hidden');
            if (containerG) containerG.classList.remove('hidden');
        } else {
            if (containerRY) containerRY.classList.remove('hidden');
            if (containerG) containerG.classList.add('hidden');
        }

        updatePreview();

        // Alusta preview — wait longer to allow async grid bitmap upload from step 5 to complete
        setTimeout(() => {
            initializePreview(currentType);
        }, 300);

        // Näytä supervisor-osio KAIKILLE tyypeille kun tullaan vaiheeseen 6
        setTimeout(() => {
            checkAndShowSupervisorSection();
        }, 150);
    }
}