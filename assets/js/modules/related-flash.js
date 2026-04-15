import { getters } from './state.js';
import { updatePreview, handleConditionalFields } from './preview-update.js';
import { markFieldsFromRelatedFlash } from './investigation-context.js';

const { getEl } = getters;

export function bindRelatedFlash() {
    const relatedFlashSelect = getEl('sf-related-flash');
    if (!relatedFlashSelect) return;

    // Bind standalone investigation checkbox
    const standaloneCheckbox = getEl('sf-standalone-investigation');
    if (standaloneCheckbox) {
        standaloneCheckbox.addEventListener('change', function () {
            const isStandalone = this.checked;

            console.log('[Standalone Toggle] isStandalone:', isStandalone);

            // Clear related flash selection when standalone is checked
            if (isStandalone && relatedFlashSelect) {
                relatedFlashSelect.value = '';
                // Trigger change event to clear any loaded data
                relatedFlashSelect.dispatchEvent(new Event('change'));
            }

            // Clear the hidden related flash ID input
            if (isStandalone) {
                const relatedFlashIdInput = getEl('sf-related-flash-id');
                if (relatedFlashIdInput) {
                    relatedFlashIdInput.value = '';
                }
            }

            // Update conditional fields to show/hide related flash field and worksite section
            handleConditionalFields();

            // Update progress indicators
            if (window.SFUpdateProgress) {
                window.SFUpdateProgress();
            }
        });
    }

    // Sulje-nappi alkuperäisen tiedotteen esikatselussa
    const closeBtn = getEl('sf-original-close');
    if (closeBtn) {
        closeBtn.addEventListener('click', function () {
            const preview = getEl('sf-original-flash-preview');
            if (preview) preview.classList.add('hidden');
        });
    }

    relatedFlashSelect.addEventListener('change', function () {
        const selectedOption = this.options[this.selectedIndex];
        const hiddenRelated = getEl('sf-related-flash-id');
        const originalPreview = getEl('sf-original-flash-preview');

        if (!selectedOption || !selectedOption.value) {
            if (hiddenRelated) hiddenRelated.value = '';
            if (originalPreview) originalPreview.classList.add('hidden');
            return;
        }

        // Uncheck standalone checkbox when a related flash is selected
        const standaloneCheckbox = getEl('sf-standalone-investigation');
        if (standaloneCheckbox && standaloneCheckbox.checked) {
            standaloneCheckbox.checked = false;
            handleConditionalFields();
        }

        if (hiddenRelated) hiddenRelated.value = selectedOption.value;

        const site = selectedOption.dataset.site || '';
        const siteDetail = selectedOption.dataset.siteDetail || '';
        const date = selectedOption.dataset.date || '';
        const title = selectedOption.dataset.title || '';
        const titleShort = selectedOption.dataset.titleShort || '';
        const description = selectedOption.dataset.description || '';
        const imageMain = selectedOption.dataset.imageMain || '';
        const image2 = selectedOption.dataset.image2 || '';
        const image3 = selectedOption.dataset.image3 || '';

        // Huom: selectedOption on jo <option>, closest('option') on turha
        const originalType = (selectedOption.textContent || '').includes('🔴') ? 'red' : 'yellow';

        // ============================================
        // HAE MERKINNÄT JA TRANSFORMIT ALKUPERÄISESTÄ
        // ============================================
        const annotationsData = selectedOption.dataset.annotationsData || '{}';
        const image1Transform = selectedOption.dataset.image1Transform || '';
        const image2Transform = selectedOption.dataset.image2Transform || '';
        const image3Transform = selectedOption.dataset.image3Transform || '';
        const gridLayout = selectedOption.dataset.gridLayout || 'grid-1';
        const gridBitmap = selectedOption.dataset.gridBitmap || '';

        // ============================================
        // NÄYTÄ ALKUPERÄINEN TIEDOTE (KOMPAKTI)
        // ============================================
        if (originalPreview) {
            originalPreview.classList.remove('hidden');

            // Päivitä tyyppiluokka ja ikoni
            originalPreview.classList.remove('type-red', 'type-yellow');
            originalPreview.classList.add('type-' + originalType);

            const icon = getEl('sf-original-icon');
            if (icon) {
                const card = getEl('sfPreviewCard') || getEl('sfPreviewCardGreen');
                const baseUrl = card?.dataset.baseUrl || window.SF_BASE_URL || '';
                icon.src = `${baseUrl}/assets/img/icon-${originalType}.png`;
            }

            // Päivitä otsikko
            const origTitle = getEl('sf-original-title');
            if (origTitle) origTitle.textContent = title || titleShort || '--';

            // Päivitä työmaa
            const origSite = getEl('sf-original-site');
            if (origSite) origSite.textContent = [site, siteDetail].filter(Boolean).join(' – ') || '--';

            // Päivitä päivämäärä
            const origDate = getEl('sf-original-date');
            if (origDate && date) {
                const dateObj = new Date(date);
                if (!isNaN(dateObj.getTime())) {
                    origDate.textContent = dateObj.toLocaleString('fi-FI', {
                        day: '2-digit',
                        month: '2-digit',
                        year: 'numeric',
                        hour: '2-digit',
                        minute: '2-digit',
                    });
                } else {
                    origDate.textContent = '--';
                }
            }
        }

        // ============================================
        // KOPIOI KENTÄT SAMOIHIN KENTTIIN (EI ERILLISIIN)
        // ============================================

        // Työmaa - käytä samaa sf-worksite-kenttää
        const worksiteField = getEl('sf-worksite');
        if (worksiteField) {
            const normalizedSite = String(site || '').trim();
            let matchedValue = '';
            let found = false;

            if (normalizedSite !== '') {
                const options = Array.from(worksiteField.options);

                const exactOption = options.find((option) => {
                    return String(option.value || '').trim() === normalizedSite;
                });

                if (exactOption) {
                    matchedValue = exactOption.value;
                    found = true;
                } else {
                    const caseInsensitiveOption = options.find((option) => {
                        return String(option.value || '').trim().toLocaleLowerCase() === normalizedSite.toLocaleLowerCase();
                    });

                    if (caseInsensitiveOption) {
                        matchedValue = caseInsensitiveOption.value;
                        found = true;
                    }
                }

                if (!found) {
                    const fallbackOption = document.createElement('option');
                    fallbackOption.value = normalizedSite;
                    fallbackOption.textContent = normalizedSite;
                    fallbackOption.selected = true;
                    worksiteField.appendChild(fallbackOption);
                    matchedValue = normalizedSite;
                    found = true;
                }

                worksiteField.value = matchedValue;
            } else {
                worksiteField.value = '';
            }

            const triggerText = getEl('sf-worksite-trigger-text');
            if (triggerText) {
                const placeholder = triggerText.dataset.placeholder || '';
                if (worksiteField.value) {
                    triggerText.textContent = worksiteField.value;
                    triggerText.classList.add('has-value');
                } else {
                    triggerText.textContent = placeholder;
                    triggerText.classList.remove('has-value');
                }
            }

            const chipList = getEl('sf-worksite-chip-list');
            if (chipList) {
                const chips = chipList.querySelectorAll('.sf-worksite-chip-option');
                chips.forEach((chip) => {
                    const isSelected = String(chip.dataset.value || '').trim() === String(worksiteField.value || '').trim();
                    chip.classList.toggle('is-selected', isSelected);
                    chip.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
                });
            }

            worksiteField.dispatchEvent(new Event('change', { bubbles: true }));
            worksiteField.dispatchEvent(new Event('input', { bubbles: true }));
        }

        // Site detail - käytä samaa sf-site-detail-kenttää
        const siteDetailField = getEl('sf-site-detail');
        if (siteDetailField) siteDetailField.value = siteDetail;

        // Päivämäärä - käytä samaa sf-date-kenttää
        // Backend palauttaa ajan muodossa "YYYY-MM-DD HH:mm:ss" (paikallinen aika)
        // joka on suoraan kelvollinen datetime-local-kentän arvo muunnettuna.
        // EI SAA käyttää new Date() + toISOString() koska se muuntaa UTC:ksi
        // ja aiheuttaa aikavyöhyke-offsetin verran virhettä (esim. -2h Suomessa).
        const dateField = getEl('sf-date');
        if (dateField && date) {
            // Normalisoi muoto: muuta välilyönti T-merkiksi ja ota 16 ensimmäistä merkkiä
            const normalizedDate = date.replace(' ', 'T').slice(0, 16);
            // Validoi että tulos on kelvollinen datetime-local-muoto (YYYY-MM-DDTHH:mm)
            if (/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(normalizedDate)) {
                dateField.value = normalizedDate;
            }
        }

        // Mark fields as coming from related flash for clearing logic
        markFieldsFromRelatedFlash();

        // Otsikko ja kuvaus
        const titleField = getEl('sf-title');
        const shortTextField = getEl('sf-short-text');
        const descriptionField = getEl('sf-description');

        if (titleField) titleField.value = title;
        if (shortTextField) shortTextField.value = titleShort;
        if (descriptionField) descriptionField.value = description;

        // ============================================
        // KUVIEN KÄSITTELY
        // ============================================
        const card = getEl('sfPreviewCard') || getEl('sfPreviewCardGreen');
        const baseUrl = card?.dataset.baseUrl || window.SF_BASE_URL || '';
        const placeholder = `${baseUrl}/assets/img/camera-placeholder.png`;
        const getImageUrl = (filename) => {
            if (!filename) return null;
            const dir = filename.startsWith('lib_') ? 'uploads/library' : 'uploads/images';
            return `${baseUrl}/${dir}/${filename}`;
        };

        const updateImage = (slot, filename) => {
            const imgUrl = filename ? getImageUrl(filename) : placeholder;

            // Päivitä thumbnail kuvakorteissa
            const thumb = getEl(`sfImageThumb${slot}`);
            if (thumb) {
                thumb.src = imgUrl;
                thumb.dataset.hasRealImage = filename ? '1' : '0';
                thumb.parentElement?.classList.toggle('has-image', !!filename);
            }

            // Päivitä myös vanhempi upload-preview jos olemassa
            const uploadPreview = getEl(`sf-upload-preview${slot}`);
            if (uploadPreview) {
                uploadPreview.src = imgUrl;
                uploadPreview.parentElement?.classList.toggle('has-image', !!filename);
            }

            // Päivitä esikatselukortit
            const cardImg = getEl(`sfPreviewImg${slot}`);
            if (cardImg) {
                cardImg.src = imgUrl;
                cardImg.dataset.hasRealImage = filename ? '1' : '0';
            }

            const cardImgGreen = getEl(`sfPreviewImg${slot}Green`);
            if (cardImgGreen) {
                cardImgGreen.src = imgUrl;
                cardImgGreen.dataset.hasRealImage = filename ? '1' : '0';
            }

            // Päivitä grid bitmap -kuva (tutkintatiedotteen esikatselu)
            if (slot === 1) {
                const gridBitmapImg = getEl('sfGridBitmapImgGreen');
                if (gridBitmapImg && filename) gridBitmapImg.src = imgUrl;

                const gridBitmapImgMain = getEl('sfGridBitmapImg');
                if (gridBitmapImgMain && filename) gridBitmapImgMain.src = imgUrl;
            }

            // Poista-nappi näkyviin
            const removeBtn = document.querySelector(`.sf-image-remove-btn[data-slot="${slot}"]`);
            if (removeBtn) {
                removeBtn.classList.toggle('hidden', !filename);
            }
        };

        updateImage(1, imageMain);
        updateImage(2, image2);
        updateImage(3, image3);

        // Tallenna kuvien tiedostonimet hidden-kenttiin
        const setExistingImage = (slot, filename) => {
            let hiddenField = document.getElementById(`sf-existing-image-${slot}`);
            if (!hiddenField) {
                hiddenField = document.createElement('input');
                hiddenField.type = 'hidden';
                hiddenField.name = `existing_image_${slot}`;
                hiddenField.id = `sf-existing-image-${slot}`;
                document.getElementById('sf-form')?.appendChild(hiddenField);
            }
            hiddenField.value = filename || '';
        };

        setExistingImage(1, imageMain);
        setExistingImage(2, image2);
        setExistingImage(3, image3);

        // ============================================
        // KOPIOI MERKINNÄT JA TRANSFORMIT
        // ============================================

        // Merkinnät (annotations)
        const annotationsField = document.getElementById('sf-edit-annotations-data');
        if (annotationsField) {
            annotationsField.value = annotationsData;
        }

        // Transform-tiedot
        const transform1 = document.getElementById('sf-image1-transform');
        const transform2 = document.getElementById('sf-image2-transform');
        const transform3 = document.getElementById('sf-image3-transform');

        if (transform1) transform1.value = image1Transform;
        if (transform2) transform2.value = image2Transform;
        if (transform3) transform3.value = image3Transform;

        // Grid-asettelu
        const gridLayoutField = document.getElementById('sf-grid-layout');
        if (gridLayoutField) gridLayoutField.value = gridLayout;

        const gridBitmapField = document.getElementById('sf-grid-bitmap');
        if (gridBitmapField) gridBitmapField.value = gridBitmap;

        // ============================================
        // PÄIVITÄ KUVAKORTTIEN UI (LATAA -> MUOKKAA)
        // ============================================
        // Map slot numbers to actual image filenames
        const imageFilenames = [imageMain, image2, image3];

        setTimeout(() => {
            [1, 2, 3].forEach((slot) => {
                const filename = imageFilenames[slot - 1];
                const hasImage = Boolean(filename && filename !== '');

                // Käytä globaalia funktiota jos saatavilla
                if (typeof window.sfUpdateImageCardUI === 'function') {
                    // Varmista että badge päivittyy oikein
                    // Badge pitäisi näkyä VAIN jos:
                    // 1. Kuva on olemassa JA
                    // 2. Sillä on transformia tai annotaatioita
                    window.sfUpdateImageCardUI(slot);
                    return;
                }

                // Fallback: päivitä CTA-napin tila manuaalisesti
                const slotCard = document.querySelector(`.sf-image-upload-card[data-slot="${slot}"]`);
                const thumb = document.getElementById(`sfImageThumb${slot}`);
                const cta = slotCard?.querySelector('.sf-image-upload-btn');
                const ctaText = cta?.querySelector('span');

                if (thumb && cta && ctaText && hasImage) {
                    cta.classList.add('sf-cta-edit');
                    cta.dataset.mode = 'edit';
                    ctaText.textContent = 'Muokkaa';

                    // Lisää has-image luokka
                    slotCard?.classList.add('has-image');
                    thumb.parentElement?.classList.add('has-image');
                }
            });
        }, 50);

        // Päivitä previewit
        setTimeout(() => {
            updatePreview();
            window.Preview?.applyGridClass?.();
            window.PreviewTutkinta?.applyGridClass?.();
            window.PreviewTutkinta?.updatePreviewContent?.();

            // Update progress indicators after all updates are complete
            if (window.SFUpdateProgress) {
                window.SFUpdateProgress();
            }
        }, 100);
    });
}