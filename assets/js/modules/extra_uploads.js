/**
 * Extra Images Upload Module
 * 
 * Handles the UI and logic for uploading additional images to SafetyFlash reports.
 * Uses window.SF_TERMS for translations.
 */

(function () {
    'use strict';

    const MAX_EXTRA_IMAGES = 20;
    let extraImages = [];

    // Helper to retrieve translations
    function getTerm(key, fallback) {
        if (window.SF_TERMS && window.SF_TERMS[key]) {
            return window.SF_TERMS[key];
        }

        if (window.SF_I18N && window.SF_I18N[key]) {
            return window.SF_I18N[key];
        }

        const uploadBtn = document.getElementById('extra-image-upload-btn');
        const currentButtonText = uploadBtn ? String(uploadBtn.textContent || '').trim() : '';

        if (currentButtonText !== '') {
            return currentButtonText;
        }

        return fallback || key;
    }

    function init() {
        const container = document.getElementById('extra-images-container');
        if (!container) return; // Not on the form page

        const uploadBtn = document.getElementById('extra-image-upload-btn');
        const fileInput = document.getElementById('extra-image-input');

        if (uploadBtn && fileInput) {
            uploadBtn.addEventListener('click', function (e) {
                e.preventDefault();
                fileInput.click();
            });

            fileInput.addEventListener('change', handleFileSelect);
        }

        const form = document.getElementById('sf-form');
        if (form) {
            form.addEventListener('submit', function () {
                injectExtraImagesData();
            });
        }

        // Initial button state update
        updateUploadButtonState();
    }

    function handleFileSelect(e) {
        const files = e.target.files;
        if (!files || files.length === 0) return;

        if (extraImages.length + files.length > MAX_EXTRA_IMAGES) {
            const msg = getTerm('extra_img_max_limit', 'Maksimimäärä lisäkuvia on {n}').replace('{n}', MAX_EXTRA_IMAGES);
            alert(msg);
            e.target.value = '';
            return;
        }

        Array.from(files).forEach(file => {
            uploadFile(file);
        });

        e.target.value = '';
    }

    function uploadFile(file) {
        if (!file.type.match(/^image\/(jpeg|png|gif|webp)$/)) {
            alert(getTerm('extra_img_invalid_type', 'Virheellinen tiedostomuoto.'));
            return;
        }

        if (file.size > 20 * 1024 * 1024) {
            alert(getTerm('extra_img_too_large', 'Tiedosto on liian suuri.'));
            return;
        }

        const formData = new FormData();
        formData.append('image', file);

        // Add CSRF token if available
        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
        if (csrfToken) {
            formData.append('csrf_token', csrfToken);
        }

        const previewId = 'preview-' + Date.now() + '-' + Math.random().toString(36).slice(2, 11);
        addPreviewItem(previewId, file.name, null, true);

        const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');

        fetch(baseUrl + '/app/api/upload_extra_image.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.ok) {
                    extraImages.push({
                        id: previewId,
                        filename: data.filename,
                        original_filename: data.original_filename,
                        url: data.url,
                        thumb_url: data.thumb_url
                    });
                    updatePreviewItem(previewId, data.thumb_url || data.url, false);
                } else {
                    removePreviewItem(previewId);
                    alert(getTerm('extra_img_upload_failed', 'Lataus epäonnistui') + ': ' + (data.error || 'Unknown error'));
                }
            })
            .catch(error => {
                console.error('Upload error:', error);
                removePreviewItem(previewId);
                alert(getTerm('extra_img_upload_failed', 'Lataus epäonnistui'));
            });
    }

    function addPreviewItem(id, filename, thumbUrl, isLoading) {
        const grid = document.getElementById('extra-images-grid');
        if (!grid) return;

        const item = document.createElement('div');
        item.className = 'extra-image-item';
        item.id = id;

        const imgContainer = document.createElement('div');
        imgContainer.className = 'extra-image-preview';

        if (isLoading) {
            imgContainer.innerHTML = `<div class="extra-image-loading">${getTerm('extra_img_processing', 'Prosessoidaan...')}</div>`;
        } else if (thumbUrl) {
            const img = document.createElement('img');
            img.src = thumbUrl;
            img.alt = filename;
            imgContainer.appendChild(img);
        }

        const removeBtn = document.createElement('button');
        removeBtn.type = 'button';
        removeBtn.className = 'extra-image-remove';
        removeBtn.innerHTML = '&times;';
        removeBtn.setAttribute('aria-label', getTerm('extra_img_remove', 'Poista kuva'));
        removeBtn.title = getTerm('extra_img_remove', 'Poista kuva');
        removeBtn.addEventListener('click', function () {
            removeImage(id);
        });

        const filenameDiv = document.createElement('div');
        filenameDiv.className = 'extra-image-filename';
        filenameDiv.textContent = filename;
        filenameDiv.title = filename;

        item.appendChild(imgContainer);
        item.appendChild(removeBtn);
        item.appendChild(filenameDiv);

        grid.appendChild(item);
        updateUploadButtonState();
    }

    function updatePreviewItem(id, thumbUrl, isLoading) {
        const item = document.getElementById(id);
        if (!item) return;

        const imgContainer = item.querySelector('.extra-image-preview');
        if (!imgContainer) return;

        if (isLoading) {
            imgContainer.innerHTML = `<div class="extra-image-loading">${getTerm('extra_img_processing', 'Prosessoidaan...')}</div>`;
        } else {
            imgContainer.innerHTML = '';
            const img = document.createElement('img');
            img.src = thumbUrl;
            img.alt = 'Extra image';
            imgContainer.appendChild(img);
        }
    }

    function removePreviewItem(id) {
        const item = document.getElementById(id);
        if (item) item.remove();
        updateUploadButtonState();
    }

    function removeImage(id) {
        const index = extraImages.findIndex(img => img.id === id);
        if (index !== -1) {
            const image = extraImages[index];
            const csrfToken = document.querySelector('input[name="csrf_token"]')?.value;
            const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');

            if (image.filename) {
                fetch(baseUrl + '/app/api/delete_temp_image.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: `filename=${encodeURIComponent(image.filename)}&csrf_token=${encodeURIComponent(csrfToken || '')}`
                }).catch(e => console.warn('Delete temp failed', e));
            }
            extraImages.splice(index, 1);
        }
        removePreviewItem(id);
    }

    function updateUploadButtonState() {
        const uploadBtn = document.getElementById('extra-image-upload-btn');
        const count = document.getElementById('extra-images-count');

        if (count) {
            count.textContent = extraImages.length + '/' + MAX_EXTRA_IMAGES;
        }

        if (uploadBtn) {
            if (extraImages.length >= MAX_EXTRA_IMAGES) {
                uploadBtn.disabled = true;
                uploadBtn.textContent = getTerm('extra_img_max_reached', 'Maksimimäärä saavutettu');
            } else {
                uploadBtn.disabled = false;
                uploadBtn.textContent = getTerm('extra_img_add_btn', 'Lisää kuvia');
            }
        }
    }

    function injectExtraImagesData() {
        const existingInput = document.getElementById('extra_images_data');
        if (existingInput) existingInput.remove();

        const form = document.getElementById('sf-form');
        if (form && extraImages.length > 0) {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.id = 'extra_images_data';
            input.name = 'extra_images';
            input.value = JSON.stringify(extraImages);
            form.appendChild(input);
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Expose API globally for AJAX form submission (form.js / submit.js)
    window.ExtraImagesUpload = {
        init: init,
        getImages: function () { return extraImages; },
        injectData: injectExtraImagesData
    };
})();