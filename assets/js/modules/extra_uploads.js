/**
 * Extra Images Upload Module
 *
 * Handles the UI and logic for uploading additional images to SafetyFlash reports.
 * Uses window.SF_TERMS for translations.
 */

(function () {
    'use strict';

    const MAX_EXTRA_IMAGES = 20;
    const MAX_UPLOAD_SIZE_BYTES = 20 * 1024 * 1024;
    const CONCURRENT_UPLOADS = 3;
    const ALLOWED_MIME_REGEX = /^image\/(jpeg|png|gif|webp|heic|heif)$/i;
    const ALLOWED_EXTENSION_REGEX = /\.(jpe?g|png|gif|webp|heic|heif)$/i;

    let extraImages = [];
    let initialized = false;
    let pasteListenerBound = false;
    let activeUploads = 0;
    let uploadQueue = [];
    let batchTotal = 0;
    let batchDone = 0;
    let batchFailed = 0;
    let hideProgressTimeout = null;

    // Helper to retrieve translations
    function getTerm(key, fallback) {
        if (window.SF_TERMS && window.SF_TERMS[key]) {
            return window.SF_TERMS[key];
        }

        if (window.SF_I18N && window.SF_I18N[key]) {
            return window.SF_I18N[key];
        }

        return fallback || key;
    }

    function showToast(type, message) {
        if (typeof window.sfToast === 'function') {
            window.sfToast(type, message);
            return;
        }
        alert(message);
    }

    function showUploadError(message) {
        showToast('error', message);
    }

    function getFormBaseUrl() {
        return (window.SF_BASE_URL || '').replace(/\/$/, '');
    }

    function getCsrfToken() {
        return document.querySelector('input[name="csrf_token"]')?.value || '';
    }

    function isAllowedImageFile(file) {
        if (!file) return false;
        const type = String(file.type || '').toLowerCase();
        if (ALLOWED_MIME_REGEX.test(type)) return true;
        const name = String(file.name || '').toLowerCase();
        return ALLOWED_EXTENSION_REGEX.test(name);
    }

    function isExtraImagesStepVisible() {
        const container = document.getElementById('extra-images-container');
        if (!container) return false;
        const step = container.closest('.sf-step-content[data-step="4"]');
        return !step || step.classList.contains('active');
    }

    function init() {
        const container = document.getElementById('extra-images-container');
        if (!container) return; // Not on the form page
        if (initialized) return;
        initialized = true;

        const uploadBtn = document.getElementById('extra-image-upload-btn');
        const cameraBtn = document.getElementById('extra-image-camera-btn');
        const fileInput = document.getElementById('extra-image-input');
        const cameraInput = document.getElementById('extra-image-camera-input');

        if (uploadBtn && fileInput) {
            uploadBtn.addEventListener('click', function (e) {
                e.preventDefault();
                fileInput.click();
            });
        }

        if (cameraBtn && cameraInput) {
            cameraBtn.addEventListener('click', function (e) {
                e.preventDefault();
                cameraInput.click();
            });
        }

        if (fileInput) {
            fileInput.addEventListener('change', handleFileSelect);
        }
        if (cameraInput) {
            cameraInput.addEventListener('change', handleFileSelect);
        }

        const form = document.getElementById('sf-form');
        if (form) {
            form.addEventListener('submit', function () {
                injectExtraImagesData();
            });
            bindDropPrevention(form);
        }

        initContainerDropZone(container);
        initPasteSupport();

        // Initial button state update
        updateUploadButtonState();
        updateBatchProgressUI();
    }

    function bindDropPrevention(form) {
        const preventDefaultDrop = function (e) {
            const types = Array.from((e.dataTransfer && e.dataTransfer.types) || []);
            if (types.includes('Files')) {
                e.preventDefault();
            }
        };

        form.addEventListener('dragover', preventDefaultDrop);
        form.addEventListener('drop', preventDefaultDrop);
    }

    function initContainerDropZone(container) {
        let dragDepth = 0;

        const hasFiles = function (event) {
            const types = Array.from((event.dataTransfer && event.dataTransfer.types) || []);
            return types.includes('Files');
        };

        container.addEventListener('dragenter', function (e) {
            if (!hasFiles(e) || !isExtraImagesStepVisible()) return;
            e.preventDefault();
            dragDepth += 1;
            container.classList.add('drag-over');
        });

        container.addEventListener('dragover', function (e) {
            if (!hasFiles(e) || !isExtraImagesStepVisible()) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'copy';
            container.classList.add('drag-over');
        });

        container.addEventListener('dragleave', function (e) {
            if (!hasFiles(e)) return;
            e.preventDefault();
            dragDepth = Math.max(0, dragDepth - 1);
            if (dragDepth === 0) {
                container.classList.remove('drag-over');
            }
        });

        container.addEventListener('drop', function (e) {
            if (!hasFiles(e) || !isExtraImagesStepVisible()) return;
            e.preventDefault();
            dragDepth = 0;
            container.classList.remove('drag-over');
            handleIncomingFiles(Array.from(e.dataTransfer.files || []));
        });

        document.addEventListener('dragend', function () {
            dragDepth = 0;
            container.classList.remove('drag-over');
        });
    }

    function initPasteSupport() {
        if (pasteListenerBound) return;
        pasteListenerBound = true;

        document.addEventListener('paste', function (e) {
            if (!isExtraImagesStepVisible()) return;

            const items = Array.from((e.clipboardData && e.clipboardData.items) || []);
            const files = items
                .filter(item => item.kind === 'file')
                .map(item => item.getAsFile())
                .filter(Boolean);

            if (files.length === 0) return;
            e.preventDefault();
            handleIncomingFiles(files);
        });
    }

    function handleFileSelect(e) {
        const files = Array.from((e.target && e.target.files) || []);
        if (!files || files.length === 0) return;
        handleIncomingFiles(files);
        e.target.value = '';
    }

    function handleIncomingFiles(files) {
        if (!files || files.length === 0) return;

        const queuedCount = extraImages.length + activeUploads + uploadQueue.length;
        const availableSlots = MAX_EXTRA_IMAGES - queuedCount;

        if (availableSlots <= 0) {
            const msg = getTerm('extra_img_max_limit', 'Maksimimäärä lisäkuvia on {n}').replace('{n}', MAX_EXTRA_IMAGES);
            showUploadError(msg);
            return;
        }

        const limitedFiles = files.slice(0, availableSlots);
        if (limitedFiles.length < files.length) {
            const msg = getTerm('extra_img_max_limit', 'Maksimimäärä lisäkuvia on {n}').replace('{n}', MAX_EXTRA_IMAGES);
            showUploadError(msg);
        }

        const validFiles = [];
        limitedFiles.forEach(file => {
            if (!isAllowedImageFile(file)) {
                showUploadError(getTerm('extra_img_invalid_type', 'Virheellinen tiedostomuoto. Sallitut: JPEG, PNG, GIF, WEBP, HEIC, HEIF'));
                return;
            }
            if (file.size > MAX_UPLOAD_SIZE_BYTES) {
                showUploadError(getTerm('extra_img_too_large', 'Tiedosto on liian suuri. Maksimikoko: 20MB'));
                return;
            }
            validFiles.push(file);
        });

        if (validFiles.length === 0) return;
        queueUploads(validFiles);
    }

    function queueUploads(files) {
        if (activeUploads === 0 && uploadQueue.length === 0) {
            batchTotal = 0;
            batchDone = 0;
            batchFailed = 0;
            clearTimeout(hideProgressTimeout);
            hideProgressTimeout = null;
        }

        files.forEach(file => uploadQueue.push(file));
        batchTotal += files.length;
        updateBatchProgressUI();
        updateUploadButtonState();
        runUploadQueue();
    }

    function runUploadQueue() {
        while (activeUploads < CONCURRENT_UPLOADS && uploadQueue.length > 0) {
            const nextFile = uploadQueue.shift();
            activeUploads++;
            updateUploadButtonState();

            uploadFile(nextFile)
                .catch(() => {
                    // Error is already surfaced per file
                })
                .finally(() => {
                    activeUploads = Math.max(0, activeUploads - 1);
                    batchDone++;
                    updateBatchProgressUI();
                    updateUploadButtonState();
                    runUploadQueue();
                    finalizeBatchIfFinished();
                });
        }
    }

    function finalizeBatchIfFinished() {
        if (activeUploads > 0 || uploadQueue.length > 0 || batchDone < batchTotal) {
            return;
        }

        if (batchFailed > 0) {
            const summaryTemplate = getTerm('images_upload_partial', '{failed} / {total} kuvan lataus epäonnistui');
            const summaryMessage = summaryTemplate
                .replace('{failed}', String(batchFailed))
                .replace('{total}', String(batchTotal));
            showUploadError(summaryMessage);
        }

        hideProgressTimeout = setTimeout(function () {
            batchTotal = 0;
            batchDone = 0;
            batchFailed = 0;
            updateBatchProgressUI();
        }, 1000);
    }

    function updateBatchProgressUI() {
        const progress = document.getElementById('extra-images-progress');
        const fill = document.getElementById('extra-images-progress-fill');
        const text = document.getElementById('extra-images-progress-text');
        if (!progress || !fill || !text) return;

        const isActive = batchTotal > 0 && (activeUploads > 0 || uploadQueue.length > 0 || batchDone < batchTotal);
        progress.classList.toggle('active', isActive || (batchTotal > 0 && batchDone > 0));

        if (batchTotal <= 0) {
            fill.style.width = '0%';
            text.textContent = '';
            return;
        }

        const percent = Math.round((batchDone / batchTotal) * 100);
        fill.style.width = percent + '%';
        const progressTemplate = getTerm('images_uploading', '{count} / {total} kuvaa ladattu');
        text.textContent = progressTemplate.replace('{count}', String(batchDone)).replace('{total}', String(batchTotal));
    }

    function sleep(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }

    function isTransientStatus(status) {
        return status === 0 || (status >= 500 && status < 600);
    }

    function uploadFile(file) {
        const previewId = 'preview-' + Date.now() + '-' + Math.random().toString(36).slice(2, 11);
        const pendingItem = addPreviewItem(previewId, file.name, file, true);

        return uploadFileWithRetry(file, pendingItem)
            .then(data => {
                extraImages.push({
                    id: previewId,
                    filename: data.filename,
                    original_filename: data.original_filename,
                    url: data.url,
                    thumb_url: data.thumb_url
                });
                updatePreviewItem(previewId, data.thumb_url || data.url, false, data.original_filename || file.name);
                updateUploadButtonState();
            })
            .catch(error => {
                batchFailed++;
                removePreviewItem(previewId);
                const errorMessage = (error && error.message) ? error.message : getTerm('extra_img_upload_failed', 'Lataus epäonnistui');
                showUploadError(getTerm('extra_img_upload_failed', 'Lataus epäonnistui') + ': ' + errorMessage);
                throw error;
            });
    }

    function uploadFileWithRetry(file, pendingItem) {
        return uploadTempWithXhr(file, pendingItem).catch(async function (error) {
            const status = Number(error && error.status ? error.status : 0);
            if (!isTransientStatus(status)) {
                throw error;
            }

            if (pendingItem && pendingItem.statusLabel) {
                pendingItem.statusLabel.textContent = getTerm('upload_retrying', 'Yritetään uudelleen...');
            }
            await sleep(2000);
            return uploadTempWithXhr(file, pendingItem);
        });
    }

    function uploadTempWithXhr(file, pendingItem) {
        return new Promise(function (resolve, reject) {
            const formData = new FormData();
            formData.append('image', file);

            const csrfToken = getCsrfToken();
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }

            const xhr = new XMLHttpRequest();
            xhr.open('POST', getFormBaseUrl() + '/app/api/upload_extra_image.php', true);
            xhr.responseType = 'json';
            xhr.withCredentials = true;

            if (pendingItem && pendingItem.statusLabel) {
                pendingItem.statusLabel.textContent = getTerm('extra_img_processing', 'Prosessoidaan...');
            }

            xhr.upload.onprogress = function (event) {
                if (!event.lengthComputable || !pendingItem) return;
                const percent = Math.round((event.loaded / event.total) * 100);
                pendingItem.progressFill.style.width = percent + '%';
                pendingItem.progressText.textContent = percent + '%';
            };

            xhr.onload = function () {
                let data = xhr.response;
                if (!data && xhr.responseText) {
                    try {
                        data = JSON.parse(xhr.responseText);
                    } catch (parseErr) {
                        data = null;
                    }
                }

                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    if (pendingItem) {
                        pendingItem.progressFill.style.width = '100%';
                        pendingItem.progressText.textContent = '100%';
                    }
                    resolve(data);
                    return;
                }

                const error = new Error((data && data.error) || getTerm('extra_img_upload_failed', 'Lataus epäonnistui'));
                error.status = xhr.status;
                reject(error);
            };

            xhr.onerror = function () {
                const error = new Error(getTerm('extra_img_upload_failed', 'Lataus epäonnistui'));
                error.status = 0;
                reject(error);
            };

            xhr.send(formData);
        });
    }

    function createPreviewFallback(container, filename) {
        container.innerHTML = '';
        container.classList.add('extra-image-preview-placeholder');
        const wrapper = document.createElement('div');
        wrapper.className = 'extra-image-placeholder';
        const title = document.createElement('span');
        title.className = 'extra-image-placeholder-title';
        title.textContent = 'HEIC/HEIF';
        const detail = document.createElement('span');
        detail.textContent = filename || '';
        wrapper.appendChild(title);
        wrapper.appendChild(detail);
        container.appendChild(wrapper);
    }

    function addPreviewItem(id, filename, file, isLoading) {
        const grid = document.getElementById('extra-images-grid');
        if (!grid) return;

        const item = document.createElement('div');
        item.className = 'extra-image-item';
        item.id = id;

        const imgContainer = document.createElement('div');
        imgContainer.className = 'extra-image-preview';
        const previewImg = document.createElement('img');
        const objectUrl = URL.createObjectURL(file);
        item.dataset.objectUrl = objectUrl;
        previewImg.src = objectUrl;
        previewImg.alt = filename || 'Extra image';
        previewImg.onerror = function () {
            createPreviewFallback(imgContainer, filename);
        };
        imgContainer.appendChild(previewImg);

        const overlay = document.createElement('div');
        overlay.className = 'extra-image-overlay' + (isLoading ? '' : ' hidden');

        const status = document.createElement('div');
        status.className = 'extra-image-status';
        status.setAttribute('role', 'status');

        const spinner = document.createElement('span');
        spinner.className = 'extra-image-spinner';
        spinner.setAttribute('aria-hidden', 'true');

        const statusLabel = document.createElement('span');
        statusLabel.textContent = getTerm('extra_img_processing', 'Prosessoidaan...');

        status.appendChild(spinner);
        status.appendChild(statusLabel);

        const progressLine = document.createElement('div');
        progressLine.className = 'extra-image-progress-line';
        const progressFill = document.createElement('div');
        progressFill.className = 'extra-image-progress-fill';
        progressLine.appendChild(progressFill);

        const progressText = document.createElement('div');
        progressText.className = 'extra-image-progress-text';
        progressText.textContent = '0%';

        overlay.appendChild(status);
        overlay.appendChild(progressLine);
        overlay.appendChild(progressText);

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
        item.appendChild(overlay);
        item.appendChild(removeBtn);
        item.appendChild(filenameDiv);

        grid.appendChild(item);
        updateUploadButtonState();

        return {
            item,
            statusLabel,
            progressFill,
            progressText
        };
    }

    function revokeObjectUrl(item) {
        if (!item || !item.dataset.objectUrl) return;
        URL.revokeObjectURL(item.dataset.objectUrl);
        delete item.dataset.objectUrl;
    }

    function updatePreviewItem(id, thumbUrl, isLoading, filename) {
        const item = document.getElementById(id);
        if (!item) return;

        const imgContainer = item.querySelector('.extra-image-preview');
        const overlay = item.querySelector('.extra-image-overlay');
        if (!imgContainer) return;

        if (isLoading) {
            if (overlay) overlay.classList.remove('hidden');
        } else {
            revokeObjectUrl(item);
            imgContainer.innerHTML = '';
            imgContainer.classList.remove('extra-image-preview-placeholder');

            if (thumbUrl) {
                const img = document.createElement('img');
                img.src = thumbUrl;
                img.alt = filename || 'Extra image';
                img.onerror = function () {
                    createPreviewFallback(imgContainer, filename || '');
                };
                imgContainer.appendChild(img);
            } else {
                createPreviewFallback(imgContainer, filename || '');
            }
            if (overlay) overlay.classList.add('hidden');
        }
    }

    function removePreviewItem(id) {
        const item = document.getElementById(id);
        if (item) {
            revokeObjectUrl(item);
            item.remove();
        }
        updateUploadButtonState();
    }

    function removeImage(id) {
        const index = extraImages.findIndex(img => img.id === id);
        if (index !== -1) {
            const image = extraImages[index];
            const csrfToken = getCsrfToken();
            const baseUrl = getFormBaseUrl();

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
        const cameraBtn = document.getElementById('extra-image-camera-btn');
        const count = document.getElementById('extra-images-count');
        const queuedCount = extraImages.length + activeUploads + uploadQueue.length;
        const maxReached = queuedCount >= MAX_EXTRA_IMAGES;

        if (count) {
            count.textContent = extraImages.length + '/' + MAX_EXTRA_IMAGES;
        }

        if (uploadBtn) {
            uploadBtn.disabled = maxReached;
            if (maxReached) {
                uploadBtn.textContent = getTerm('extra_img_max_reached', 'Maksimimäärä saavutettu');
            } else {
                uploadBtn.textContent = getTerm('extra_img_add_btn', 'Lisää kuvia');
            }
        }

        if (cameraBtn) {
            cameraBtn.disabled = maxReached;
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
