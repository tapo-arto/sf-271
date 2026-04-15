/**
 * Extra Images View Module
 * Fetches and displays extra images on the View page (Kuvat tab).
 */
(function () {
    'use strict';

    window.initExtraImages = function (flashId, canEdit, mainImages, canAddExtraImages) {
        const grid = document.getElementById('imagesGrid');
        const loading = document.getElementById('imagesLoading');
        const noImages = document.getElementById('noImagesMessage');
        const tabBtn = document.querySelector('.sf-activity-tab[data-tab="images"]');
        const uploadContainer = document.getElementById('imagesUploadContainer');

        if (!grid) return;

        const baseUrl = (window.SF_BASE_URL || '').replace(/\/$/, '');

        // Initialize main images array if not provided
        mainImages = mainImages || [];

        // Use canAddExtraImages if provided, otherwise fallback to canEdit for backward compatibility
        canAddExtraImages = (canAddExtraImages !== undefined) ? canAddExtraImages : canEdit;

        // Fetch extra images from API
        fetch(`${baseUrl}/app/api/get_extra_images.php?id=${flashId}`, {
            credentials: 'same-origin'
        })
            .then(res => res.json())
            .then(data => {
                if (loading) loading.style.display = 'none';

                const extraImages = (data.ok && data.images) ? data.images : [];
                const allImages = [...mainImages, ...extraImages];

                if (allImages.length > 0) {
                    grid.innerHTML = '';
                    grid.style.display = 'grid';

                    // Display main images first (not deletable)
                    mainImages.forEach(img => {
                        const item = createViewItem(img, canEdit, baseUrl, null);
                        grid.appendChild(item);
                    });

                    // Display extra images (deletable if canEdit)
                    extraImages.forEach(img => {
                        const item = createViewItem(img, canEdit, baseUrl, () => {
                            // Check if grid is empty after this item was removed
                            if (grid.querySelectorAll('.sf-gallery-item').length === 0) {
                                grid.style.display = 'none';
                                if (noImages) noImages.style.display = 'block';
                            }
                        });
                        grid.appendChild(item);
                    });

                    if (tabBtn) tabBtn.classList.remove('hidden');
                } else {
                    grid.style.display = 'none';
                    if (noImages) noImages.style.display = 'block';
                }

                // Show upload button if user can edit
                if (canAddExtraImages && uploadContainer) {
                    uploadContainer.style.display = 'block';
                    initUploadModal(flashId, baseUrl, grid, noImages);
                }
            })
            .catch(err => {
                console.error('Failed to load extra images:', err);
                const errorMsg = (window.SF_TERMS && window.SF_TERMS['images_loading_error']) || 'Kuvien lataus epäonnistui.';
                if (loading) {
                    loading.style.display = 'none';
                }
                // Still show main images even if extra images fail to load
                if (mainImages.length > 0) {
                    grid.innerHTML = '';
                    grid.style.display = 'grid';
                    mainImages.forEach(img => {
                        const item = createViewItem(img, canEdit, baseUrl, null);
                        grid.appendChild(item);
                    });
                } else {
                    if (noImages) noImages.style.display = 'block';
                }

                // Show upload button if user can edit
                if (canAddExtraImages && uploadContainer) {
                    uploadContainer.style.display = 'block';
                    initUploadModal(flashId, baseUrl, grid, noImages);
                }
            });
    };

    function createViewItem(img, canEdit, baseUrl, onDelete) {
        const div = document.createElement('div');
        div.className = 'sf-gallery-item';

        // Add a class to distinguish main images from extra images
        if (img.isMain) {
            div.classList.add('sf-gallery-item-main');
        }

        const imgEl = document.createElement('img');
        imgEl.src = img.thumb_url || img.url;
        imgEl.alt = img.isMain ? 'Main image' : 'Extra image';
        imgEl.className = 'sf-gallery-img';
        imgEl.loading = 'lazy';

        // Store full URL on the img element for navigation
        imgEl.__fullUrl = img.url;

        imgEl.onclick = () => openLightbox(img.url);

        // Handle image load errors
        imgEl.onerror = function () {
            console.error('Failed to load image:', img.url);
            // Try full URL if thumb failed
            if (this.src === img.thumb_url && img.url !== img.thumb_url) {
                this.src = img.url;
            } else {
                // If both fail, show placeholder
                div.innerHTML = '<div style="aspect-ratio: 1/1; display: flex; align-items: center; justify-content: center; color: #6c757d;">⚠️</div>';
            }
        };

        div.appendChild(imgEl);

        // Add caption display/edit functionality
        const captionDiv = document.createElement('div');
        captionDiv.className = 'image-caption-display' + (canEdit ? ' editable' : '');
        const currentCaption = img.caption || '';
        captionDiv.textContent = currentCaption || 'Lisää kuvateksti...';
        if (!currentCaption) {
            captionDiv.classList.add('image-caption-placeholder');
        }

        // Add click-to-edit functionality for captions (only if user can edit)
        if (canEdit) {
            captionDiv.onclick = (e) => {
                e.stopPropagation();
                showCaptionEditor(captionDiv, img, baseUrl);
            };
        }

        div.appendChild(captionDiv);

        // Only add delete button for extra images (not main images)
        if (canEdit && img.id) {
            const delBtn = document.createElement('button');
            delBtn.className = 'sf-gallery-delete';
            delBtn.innerHTML = '&times;';
            delBtn.onclick = (e) => {
                e.stopPropagation();
                showDeleteConfirmModal(() => {
                    deleteImage(img.id, div, baseUrl, onDelete);
                });
            };
            div.appendChild(delBtn);
        }
        return div;
    }

    function deleteImage(imageId, element, baseUrl, onDelete) {
        const csrfToken = window.SF_CSRF_TOKEN || document.querySelector('input[name="csrf_token"]')?.value;
        fetch(`${baseUrl}/app/api/delete_extra_image.php`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `id=${imageId}&csrf_token=${encodeURIComponent(csrfToken || '')}`,
            credentials: 'same-origin'
        })
            .then(res => res.json())
            .then(data => {
                if (data.ok) {
                    element.remove();
                    if (onDelete) onDelete();
                    const successMsg = (window.SF_TERMS && window.SF_TERMS['delete_success']) || 'Kuva poistettu';
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('success', successMsg);
                    }
                } else {
                    const errorMsg = (window.SF_TERMS && window.SF_TERMS['delete_error']) || 'Virhe poistettaessa';
                    const fullMsg = errorMsg + ': ' + (data.error || (window.SF_TERMS && window.SF_TERMS['unknown_error']) || 'Tuntematon virhe');
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('error', fullMsg);
                    } else {
                        alert(fullMsg);
                    }
                }
            })
            .catch(err => {
                const errorMsg = (window.SF_TERMS && window.SF_TERMS['delete_error']) || 'Virhe poistettaessa.';
                if (typeof window.sfToast === 'function') {
                    window.sfToast('error', errorMsg);
                } else {
                    alert(errorMsg);
                }
                console.error(err);
            });
    }

    function showCaptionEditor(captionDiv, img, baseUrl) {
        const currentCaption = img.caption || '';
        const parent = captionDiv.parentNode;

        // Flag to prevent duplicate saves
        let isSaving = false;

        // Create input field
        const input = document.createElement('input');
        input.type = 'text';
        input.className = 'image-caption-input';
        input.value = currentCaption;
        input.maxLength = 500;
        input.placeholder = 'Lisää kuvateksti...';

        // Save caption function
        const saveCaption = () => {
            // Prevent duplicate saves
            if (isSaving) {
                return;
            }
            isSaving = true;

            const newCaption = input.value.trim();

            // Determine image type
            let imageType = 'extra';
            let imageId = img.id || 0;
            let flashId = img.flash_id || 0;

            if (img.isMain) {
                // Main images: determine which slot (1, 2, or 3)
                imageType = img.imageType || 'main1';
                flashId = img.flash_id || 0;
            }

            // Get CSRF token
            const csrfToken = window.SF_CSRF_TOKEN || document.querySelector('input[name="csrf_token"]')?.value;

            // Validate CSRF token exists
            if (!csrfToken) {
                console.error('CSRF token not found');
                if (typeof window.sfToast === 'function') {
                    window.sfToast('error', 'Turvavirhe: yritä päivittää sivu');
                } else {
                    alert('Turvavirhe: yritä päivittää sivu');
                }
                // Restore original caption
                captionDiv.textContent = currentCaption || 'Lisää kuvateksti...';
                captionDiv.classList.toggle('image-caption-placeholder', !currentCaption);
                if (input.parentNode === parent) {
                    parent.replaceChild(captionDiv, input);
                }
                isSaving = false;
                return;
            }

            // Call API to save caption
            const formData = new URLSearchParams();
            formData.append('flash_id', flashId.toString());
            formData.append('image_type', imageType);
            if (imageType === 'extra') {
                formData.append('image_id', imageId.toString());
            }
            formData.append('caption', newCaption);
            formData.append('csrf_token', csrfToken);

            fetch(`${baseUrl}/app/api/update_image_caption.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: formData.toString(),
                credentials: 'same-origin'
            })
                .then(res => res.json())
                .then(data => {
                    if (data.ok) {
                        // Update the caption display
                        img.caption = newCaption;
                        captionDiv.textContent = newCaption || 'Lisää kuvateksti...';
                        captionDiv.classList.toggle('image-caption-placeholder', !newCaption);

                        if (typeof window.sfToast === 'function') {
                            window.sfToast('success', 'Kuvateksti tallennettu');
                        }
                    } else {
                        const errorMsg = 'Virhe tallennettaessa: ' + (data.error || 'Tuntematon virhe');
                        if (typeof window.sfToast === 'function') {
                            window.sfToast('error', errorMsg);
                        } else {
                            alert(errorMsg);
                        }
                        // Restore original caption
                        captionDiv.textContent = currentCaption || 'Lisää kuvateksti...';
                        captionDiv.classList.toggle('image-caption-placeholder', !currentCaption);
                    }
                    // Replace input with caption display after save completes
                    if (input.parentNode === parent) {
                        parent.replaceChild(captionDiv, input);
                    }
                    isSaving = false;
                })
                .catch(err => {
                    console.error('Error saving caption:', err);
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('error', 'Virhe tallennettaessa kuvatekstiä');
                    } else {
                        alert('Virhe tallennettaessa kuvatekstiä');
                    }
                    // Restore original caption
                    captionDiv.textContent = currentCaption || 'Lisää kuvateksti...';
                    captionDiv.classList.toggle('image-caption-placeholder', !currentCaption);
                    // Replace input with caption display after error
                    if (input.parentNode === parent) {
                        parent.replaceChild(captionDiv, input);
                    }
                    isSaving = false;
                });
        };

        // Replace caption display with input
        parent.replaceChild(input, captionDiv);
        input.focus();
        input.select();

        // Flag to track if we should save or cancel
        let shouldSave = true;

        // Handle blur (save on unfocus)
        input.onblur = () => {
            if (shouldSave && !isSaving) {
                saveCaption();
            }
        };

        // Handle Enter key (save directly)
        input.onkeydown = (e) => {
            if (e.key === 'Enter') {
                e.preventDefault();
                // Disable blur save to prevent duplicate
                shouldSave = false;
                saveCaption();
            } else if (e.key === 'Escape') {
                e.preventDefault();
                // Cancel editing - don't save on blur
                shouldSave = false;
                captionDiv.textContent = currentCaption || 'Lisää kuvateksti...';
                captionDiv.classList.toggle('image-caption-placeholder', !currentCaption);
                if (input.parentNode === parent) {
                    parent.replaceChild(captionDiv, input);
                }
            }
        };
    }

    // Track all images for navigation
    let allImageUrls = [];
    let currentImageIndex = 0;
    let keyboardListenerAdded = false;

    // Delete confirmation modal singleton
    let deleteConfirmModal = null;
    let deleteConfirmCallback = null;

    function createDeleteConfirmModal() {
        const modal = document.createElement('div');
        modal.className = 'sf-modal hidden';
        modal.id = 'modalDeleteExtraImage';
        modal.setAttribute('role', 'dialog');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('aria-labelledby', 'modalDeleteExtraImageTitle');

        const cancelBtnText = (window.SF_TERMS && window.SF_TERMS['btn_cancel']) || 'Peruuta';
        const deleteBtnText = (window.SF_TERMS && window.SF_TERMS['btn_delete']) || 'Poista';
        const titleText = (window.SF_TERMS && window.SF_TERMS['extra_img_delete_confirm']) || 'Haluatko varmasti poistaa tämän kuvan?';

        modal.innerHTML = `
            <div class="sf-modal-content">
                <h2 id="modalDeleteExtraImageTitle">${titleText}</h2>
                <div class="sf-modal-actions">
                    <button type="button" class="sf-btn sf-btn-secondary" data-action="cancel">
                        ${cancelBtnText}
                    </button>
                    <button type="button" class="sf-btn sf-btn-danger" data-action="confirm">
                        ${deleteBtnText}
                    </button>
                </div>
            </div>
        `;

        document.body.appendChild(modal);

        // Close on backdrop click
        modal.addEventListener('click', (e) => {
            if (e.target === modal) {
                closeDeleteConfirmModal();
            }
        });

        // Setup button event listeners once
        const cancelBtn = modal.querySelector('[data-action="cancel"]');
        const confirmBtn = modal.querySelector('[data-action="confirm"]');

        cancelBtn.addEventListener('click', () => {
            closeDeleteConfirmModal();
        });

        confirmBtn.addEventListener('click', () => {
            const callback = deleteConfirmCallback;  // Tallenna viite ENSIN
            closeDeleteConfirmModal();               // Sitten sulje (joka nollaa deleteConfirmCallback)
            if (callback) {
                callback();                          // Suorita tallennettu callback
            }
        });

        return modal;
    }

    function showDeleteConfirmModal(onConfirm) {
        if (!deleteConfirmModal) {
            deleteConfirmModal = createDeleteConfirmModal();
        }

        deleteConfirmCallback = onConfirm;
        deleteConfirmModal.classList.remove('hidden');
    }

    function closeDeleteConfirmModal() {
        if (deleteConfirmModal) {
            deleteConfirmModal.classList.add('hidden');
            deleteConfirmCallback = null;
        }
    }

    function openLightbox(url) {
        // Collect all image URLs for navigation
        const gridImgs = document.querySelectorAll('#imagesGrid .sf-gallery-img');
        allImageUrls = [];
        gridImgs.forEach(img => {
            allImageUrls.push(img.__fullUrl || img.src);
        });
        currentImageIndex = allImageUrls.indexOf(url);
        if (currentImageIndex === -1) currentImageIndex = 0;

        let lightbox = document.getElementById('sf-lightbox');
        if (!lightbox) {
            lightbox = document.createElement('div');
            lightbox.id = 'sf-lightbox';
            lightbox.className = 'sf-lightbox';
            lightbox.innerHTML = `
                <button class="sf-lightbox-close" aria-label="Close">&times;</button>
                <button class="sf-lightbox-nav sf-lightbox-prev" aria-label="Previous">&#8249;</button>
                <button class="sf-lightbox-nav sf-lightbox-next" aria-label="Next">&#8250;</button>
                <div class="sf-lightbox-content">
                    <img class="sf-lightbox-img" src="" alt="">
                    <div class="sf-lightbox-caption"></div>
                    <div class="sf-lightbox-counter"></div>
                </div>
            `;
            document.body.appendChild(lightbox);

            // Close on backdrop click
            lightbox.addEventListener('click', (e) => {
                if (e.target === lightbox) closeLightbox();
            });

            // Close button
            lightbox.querySelector('.sf-lightbox-close').addEventListener('click', closeLightbox);

            // Navigation
            lightbox.querySelector('.sf-lightbox-prev').addEventListener('click', (e) => {
                e.stopPropagation();
                navigateLightbox(-1);
            });
            lightbox.querySelector('.sf-lightbox-next').addEventListener('click', (e) => {
                e.stopPropagation();
                navigateLightbox(1);
            });

            // Swipe support for mobile
            let touchStartX = 0;
            lightbox.addEventListener('touchstart', (e) => {
                touchStartX = e.changedTouches[0].screenX;
            }, { passive: true });
            lightbox.addEventListener('touchend', (e) => {
                const touchEndX = e.changedTouches[0].screenX;
                const diff = touchStartX - touchEndX;
                if (Math.abs(diff) > 50) {
                    navigateLightbox(diff > 0 ? 1 : -1);
                }
            }, { passive: true });
        }

        // Keyboard navigation - add listener only once
        if (!keyboardListenerAdded) {
            document.addEventListener('keydown', (e) => {
                const lightbox = document.getElementById('sf-lightbox');
                if (!lightbox || !lightbox.classList.contains('active')) return;
                if (e.key === 'Escape') closeLightbox();
                if (e.key === 'ArrowLeft') navigateLightbox(-1);
                if (e.key === 'ArrowRight') navigateLightbox(1);
            });
            keyboardListenerAdded = true;
        }

        updateLightboxContent(lightbox, url);
        lightbox.classList.add('active');
        document.body.style.overflow = 'hidden';
    }

    function closeLightbox() {
        const lightbox = document.getElementById('sf-lightbox');
        if (lightbox) {
            lightbox.classList.remove('active');
            document.body.style.overflow = '';
        }
    }

    function navigateLightbox(direction) {
        if (allImageUrls.length <= 1) return;
        currentImageIndex += direction;
        if (currentImageIndex < 0) currentImageIndex = allImageUrls.length - 1;
        if (currentImageIndex >= allImageUrls.length) currentImageIndex = 0;

        const lightbox = document.getElementById('sf-lightbox');
        if (lightbox) {
            updateLightboxContent(lightbox, allImageUrls[currentImageIndex]);
        }
    }

    function updateLightboxContent(lightbox, url) {
        const img = lightbox.querySelector('.sf-lightbox-img');
        const caption = lightbox.querySelector('.sf-lightbox-caption');
        const counter = lightbox.querySelector('.sf-lightbox-counter');
        const prevBtn = lightbox.querySelector('.sf-lightbox-prev');
        const nextBtn = lightbox.querySelector('.sf-lightbox-next');

        img.src = url;

        // Show filename as caption (extract from URL, handle query params)
        let filename = '';
        try {
            // Try using URL API for robust parsing
            const urlObj = new URL(url, window.location.href);
            filename = urlObj.pathname.split('/').pop() || '';
        } catch (e) {
            // Fallback to simple parsing if URL API fails
            const urlPath = url.split('?')[0].split('#')[0];
            filename = urlPath.split('/').pop() || '';
        }
        if (caption) caption.textContent = decodeURIComponent(filename);

        // Show counter
        if (counter && allImageUrls.length > 1) {
            counter.textContent = (currentImageIndex + 1) + ' / ' + allImageUrls.length;
            counter.style.display = '';
        } else if (counter) {
            counter.style.display = 'none';
        }

        // Show/hide nav buttons
        if (prevBtn) prevBtn.style.display = allImageUrls.length > 1 ? '' : 'none';
        if (nextBtn) nextBtn.style.display = allImageUrls.length > 1 ? '' : 'none';
    }

    function initUploadModal(flashId, baseUrl, grid, noImages) {
        const uploadBtn = document.getElementById('imagesUploadBtn');
        const modal = document.getElementById('uploadModal');
        const modalClose = document.getElementById('uploadModalClose');
        const backdrop = modal ? modal.querySelector('.sf-modal-backdrop') : null;
        const dropZone = document.getElementById('uploadDropZone');
        const browseBtn = document.getElementById('uploadBrowseBtn');
        const fileInput = document.getElementById('uploadFileInput');

        if (!uploadBtn || !modal || !dropZone || !browseBtn || !fileInput) return;

        // Open modal when upload button is clicked
        uploadBtn.onclick = () => {
            modal.classList.remove('hidden');
        };

        // Close modal
        const closeModal = () => {
            modal.classList.add('hidden');
            fileInput.value = '';
        };

        if (modalClose) modalClose.onclick = closeModal;
        if (backdrop) backdrop.onclick = closeModal;

        // Browse button
        browseBtn.onclick = () => fileInput.click();

        // File input change
        fileInput.onchange = (e) => {
            const files = Array.from(e.target.files || []);
            if (files.length === 0) return;

            handleFiles(files, flashId, baseUrl, grid, noImages, modal);
            fileInput.value = '';
        };

        // Drag and drop
        dropZone.ondragover = (e) => {
            e.preventDefault();
            dropZone.classList.add('drag-over');
        };

        dropZone.ondragleave = (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');
        };

        dropZone.ondrop = (e) => {
            e.preventDefault();
            dropZone.classList.remove('drag-over');

            const files = Array.from(e.dataTransfer.files || []).filter(f => f.type.startsWith('image/'));
            if (files.length === 0) {
                const errorMsg = (window.SF_TERMS && window.SF_TERMS['select_image_files']) || 'Valitse kuvatiedostoja';
                if (typeof window.sfToast === 'function') {
                    window.sfToast('error', errorMsg);
                }
                return;
            }

            handleFiles(files, flashId, baseUrl, grid, noImages, modal);
        };
    }

    function handleFiles(files, flashId, baseUrl, grid, noImages, modal) {
        const imageFiles = files.filter(f => f.type.startsWith('image/'));

        if (imageFiles.length === 0) {
            const errorMsg = (window.SF_TERMS && window.SF_TERMS['select_image_files']) || 'Valitse kuvatiedostoja';
            if (typeof window.sfToast === 'function') {
                window.sfToast('error', errorMsg);
            }
            return;
        }

        const progress = document.getElementById('uploadProgress');
        const progressFill = document.getElementById('uploadProgressFill');
        const progressText = document.getElementById('uploadProgressText');

        let completed = 0;
        const total = imageFiles.length;

        if (progress) {
            progress.classList.add('active');
            updateProgress(0, total, progressFill, progressText);
        }

        // Upload files sequentially
        imageFiles.reduce((promise, file) => {
            return promise.then(() => {
                return uploadImage(file, flashId, baseUrl, grid, noImages).then(() => {
                    completed++;
                    updateProgress(completed, total, progressFill, progressText);
                });
            });
        }, Promise.resolve()).then(() => {
            // All uploads complete
            setTimeout(() => {
                if (progress) progress.classList.remove('active');
                modal.classList.add('hidden');
            }, 1000);
        }).catch(err => {
            console.error('Upload batch error:', err);
            if (progress) progress.classList.remove('active');
        });
    }

    function updateProgress(completed, total, progressFill, progressText) {
        const percent = Math.round((completed / total) * 100);
        if (progressFill) {
            progressFill.style.width = percent + '%';
            progressFill.textContent = percent + '%';
        }
        if (progressText) {
            const template = (window.SF_TERMS && window.SF_TERMS['images_uploading']) || '{count} / {total} kuvaa ladattu';
            const text = template.replace('{count}', completed).replace('{total}', total);
            progressText.textContent = text;
        }
    }

    function uploadImage(file, flashId, baseUrl, grid, noImages) {
        return new Promise((resolve, reject) => {
            const formData = new FormData();
            formData.append('image', file);

            // Add CSRF token - THIS IS THE FIX FOR THE 403 ERROR
            const csrfToken = window.SF_CSRF_TOKEN || document.querySelector('input[name="csrf_token"]')?.value;
            if (csrfToken) {
                formData.append('csrf_token', csrfToken);
            }

            // Show loading indicator
            const loadingItem = document.createElement('div');
            loadingItem.className = 'sf-gallery-item sf-gallery-item-loading';
            loadingItem.innerHTML = '<div class="sf-gallery-spinner"></div>';
            grid.appendChild(loadingItem);
            grid.style.display = 'grid';
            if (noImages) noImages.style.display = 'none';

            // First, upload to temp
            fetch(`${baseUrl}/app/api/upload_extra_image.php`, {
                method: 'POST',
                body: formData,
                credentials: 'same-origin'
            })
                .then(res => res.json())
                .then(data => {
                    if (!data.ok) {
                        throw new Error(data.error || 'Upload failed');
                    }

                    // Now associate the temp file with the flash
                    const addCsrfToken = window.SF_CSRF_TOKEN || document.querySelector('input[name="csrf_token"]')?.value;

                    if (!addCsrfToken) {
                        throw new Error('CSRF token not found');
                    }

                    const addData = new URLSearchParams();
                    addData.append('flash_id', flashId);
                    addData.append('temp_filename', data.filename);
                    addData.append('original_filename', data.original_filename);
                    addData.append('csrf_token', addCsrfToken);

                    return fetch(`${baseUrl}/app/api/add_extra_image.php`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: addData.toString(),
                        credentials: 'same-origin'
                    });
                })
                .then(res => res.json())
                .then(addResult => {
                    loadingItem.remove();

                    if (addResult.ok) {
                        // Add the new image to the grid dynamically
                        const newImage = {
                            id: addResult.id,
                            url: addResult.url,
                            thumb_url: addResult.thumb_url,
                            filename: addResult.filename,
                            original_filename: addResult.original_filename
                        };

                        // Create and append the new item (with delete button since it's an extra image)
                        const item = createViewItem(newImage, true, baseUrl, () => {
                            // Check if grid is empty after deletion
                            if (grid.querySelectorAll('.sf-gallery-item').length === 0) {
                                grid.style.display = 'none';
                                if (noImages) noImages.style.display = 'block';
                            }
                        });
                        grid.appendChild(item);

                        // Show success notification
                        const successMsg = (window.SF_TERMS && window.SF_TERMS['upload_success']) || 'Kuva ladattu onnistuneesti';
                        if (typeof window.sfToast === 'function') {
                            window.sfToast('success', successMsg);
                        }

                        resolve();
                    } else {
                        throw new Error(addResult.error || 'Failed to save image');
                    }
                })
                .catch(err => {
                    loadingItem.remove();
                    const errorMsg = (window.SF_TERMS && window.SF_TERMS['upload_error']) || 'Lataus epäonnistui';
                    const fullMsg = errorMsg + ': ' + err.message;
                    if (typeof window.sfToast === 'function') {
                        window.sfToast('error', fullMsg);
                    } else {
                        alert(fullMsg);
                    }
                    console.error('Upload error:', err);
                    reject(err);
                });
        });
    }
})();