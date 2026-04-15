(function () {
    "use strict";

    // Swipe gesture constants
    const DRAG_RESISTANCE = 0.5;
    const MAX_DRAG_DISTANCE = 150;
    const SWIPE_CLOSE_THRESHOLD = 100;
    const CLOSE_ANIMATION_DELAY = 200;

    function getOpenModals() {
        return Array.from(document.querySelectorAll(".sf-modal:not(.hidden), .sf-library-modal:not(.hidden)"));
    }

    function openModal(modal) {
        if (!modal) return;
        modal.classList.remove("hidden");
        document.body.classList.add("sf-modal-open");

        // Focus ensimmäiseen järkevään elementtiin
        const focusable = modal.querySelector('button, [href], input, select, textarea, [tabindex]:not([tabindex="-1"])');
        if (focusable) focusable.focus({ preventScroll: true });

        // Initialize swipe-to-close for mobile
        initSwipeToClose(modal);
    }

    function closeModal(modal) {
        if (!modal) return;
        modal.classList.add("hidden");

        // Poista scroll lock jos ei jää auki muita modaaleja
        if (getOpenModals().length === 0) {
            document.body.classList.remove("sf-modal-open");
        }
    }

    // Swipe-to-close functionality
    function initSwipeToClose(modal) {
        const modalContent = modal.querySelector(".sf-modal-content, .sf-library-modal-content");
        if (!modalContent) return;

        // Skip if already initialized
        if (modalContent.dataset.swipeInitialized) return;
        modalContent.dataset.swipeInitialized = 'true';

        let touchStartY = 0;
        let touchCurrentY = 0;
        let isDragging = false;
        let rafId = null;

        const handleTouchStart = (e) => {
            // Only start if touching the modal content (not buttons, inputs, etc.)
            const target = e.target;
            if (target.tagName === 'BUTTON' || target.tagName === 'INPUT' ||
                target.tagName === 'TEXTAREA' || target.tagName === 'SELECT') {
                return;
            }

            touchStartY = e.touches[0].clientY;
            touchCurrentY = touchStartY;
            isDragging = false;
        };

        const updateTransform = () => {
            if (!isDragging) return;

            const diff = touchCurrentY - touchStartY;
            if (diff > 0) {
                // Add slight resistance effect
                const translateY = Math.min(diff * DRAG_RESISTANCE, MAX_DRAG_DISTANCE);
                modalContent.style.transform = `translateY(${translateY}px)`;
                modalContent.style.transition = 'none';
            }
            rafId = null;
        };

        const handleTouchMove = (e) => {
            if (touchStartY === 0) return;

            touchCurrentY = e.touches[0].clientY;
            const diff = touchCurrentY - touchStartY;

            // Only allow dragging downward
            if (diff > 0) {
                isDragging = true;
                // Use requestAnimationFrame for smooth rendering
                if (rafId === null) {
                    rafId = requestAnimationFrame(updateTransform);
                }
            }
        };

        const handleTouchEnd = (e) => {
            if (!isDragging) {
                touchStartY = 0;
                touchCurrentY = 0;
                return;
            }

            const diff = touchCurrentY - touchStartY;

            // Reset transform
            modalContent.style.transition = 'transform 0.3s ease';
            modalContent.style.transform = '';

            // Close if swiped down more than threshold
            if (diff > SWIPE_CLOSE_THRESHOLD) {
                setTimeout(() => {
                    closeModal(modal);
                }, CLOSE_ANIMATION_DELAY);
            }

            touchStartY = 0;
            touchCurrentY = 0;
            isDragging = false;

            if (rafId !== null) {
                cancelAnimationFrame(rafId);
                rafId = null;
            }
        };

        // Add event listeners
        modalContent.addEventListener('touchstart', handleTouchStart, { passive: true });
        modalContent.addEventListener('touchmove', handleTouchMove, { passive: true });
        modalContent.addEventListener('touchend', handleTouchEnd, { passive: true });
    }

    // Delegoitu avaus: <a data-modal-open="#sfLogoutModal">
    document.addEventListener("click", function (e) {
        const opener = e.target.closest("[data-modal-open]");
        if (opener) {
            e.preventDefault();
            const sel = opener.getAttribute("data-modal-open");
            const modal = sel ? document.querySelector(sel) : null;
            openModal(modal);
            return;
        }

        // Sulje-napit: data-modal-close
        const closer = e.target.closest("[data-modal-close]");
        if (closer) {
            e.preventDefault();
            const modal = closer.closest(".sf-modal, .sf-library-modal");
            closeModal(modal);
            return;
        }

        // Klikkaus overlayhin sulkee (jos klikataan suoraan overlayta)
        const overlay = e.target.classList && (e.target.classList.contains("sf-modal") || e.target.classList.contains("sf-library-modal"))
            ? e.target
            : null;

        if (overlay) {
            closeModal(overlay);
        }
    });

    // Escape sulkee päällimmäisen modaalin
    document.addEventListener("keydown", function (e) {
        if (e.key !== "Escape") return;
        const open = getOpenModals();
        if (open.length > 0) {
            closeModal(open[open.length - 1]);
        }
    });
})();