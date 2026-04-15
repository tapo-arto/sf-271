// PWA Install Handler
(function () {
    'use strict';

    let deferredPrompt = null;
    let isIOS = false;
    let isInStandaloneMode = false;

    // Detect iOS
    function detectIOS() {
        const ua = window.navigator.userAgent;
        const isIOSDevice = /iPad|iPhone|iPod/.test(ua) && !window.MSStream;
        return isIOSDevice;
    }

    // Check if already installed (standalone mode)
    function isStandalone() {
        // Check for PWA display mode (standard browsers)
        const isStandaloneMode = (window.matchMedia && window.matchMedia('(display-mode: standalone)').matches);

        // Check for iOS standalone mode (Safari)
        const isIOSStandalone = (window.navigator.standalone === true);

        // Check for Android TWA (Trusted Web Activity)
        // When app is launched from Android home screen, referrer may contain 'android-app://'
        const isTWA = document.referrer.includes('android-app://');

        return isStandaloneMode || isIOSStandalone || isTWA;
    }

    // Initialize
    function init() {
        isIOS = detectIOS();
        isInStandaloneMode = isStandalone();

        const installBtn = document.getElementById('sf-install-btn');
        const installModal = document.getElementById('sfInstallModal');
        const installConfirmBtn = document.getElementById('sfInstallConfirm');

        if (!installBtn || !installModal) return;

        // Move modal to body if not already there (similar to other modals)
        if (installModal.parentElement !== document.body) {
            document.body.appendChild(installModal);
        }

        // Listen for the beforeinstallprompt event (Chrome, Edge, etc.)
        window.addEventListener('beforeinstallprompt', (e) => {
            // Prevent the default prompt
            e.preventDefault();

            // Save the event for later use
            deferredPrompt = e;

            // Show the install button
            showInstallButton();
        });

        // For iOS, show the button if not already installed
        if (isIOS && !isInStandaloneMode) {
            showInstallButton();
        }

        // Handle install button click
        installBtn.addEventListener('click', () => {
            openInstallModal();
        });

        // Handle confirm button in modal
        installConfirmBtn.addEventListener('click', () => {
            handleInstallClick();
        });

        // Listen for successful installation
        window.addEventListener('appinstalled', () => {
            console.log('PWA was installed successfully');
            hideInstallButton();
        });
    }

    function showInstallButton() {
        const installBtn = document.getElementById('sf-install-btn');
        if (installBtn) {
            installBtn.classList.remove('hidden');
        }
    }

    function hideInstallButton() {
        const installBtn = document.getElementById('sf-install-btn');
        if (installBtn) {
            installBtn.classList.add('hidden');
        }
    }

    function openInstallModal() {
        const installModal = document.getElementById('sfInstallModal');
        const installMessage = document.getElementById('sfInstallMessage');
        const installMessageIOS = document.getElementById('sfInstallMessageIOS');
        const installConfirmBtn = document.getElementById('sfInstallConfirm');

        if (!installModal) return;

        // Show appropriate message based on device
        if (isIOS) {
            // iOS: Show manual instructions
            if (installMessage) installMessage.style.display = 'none';
            if (installMessageIOS) installMessageIOS.style.display = 'block';
            if (installConfirmBtn) installConfirmBtn.style.display = 'none';
        } else {
            // Android/Desktop: Show normal message
            if (installMessage) installMessage.style.display = 'block';
            if (installMessageIOS) installMessageIOS.style.display = 'none';
            if (installConfirmBtn) installConfirmBtn.style.display = 'inline-flex';
        }

        // Open modal using existing modal system
        installModal.classList.remove('hidden');
        installModal.setAttribute('aria-hidden', 'false');
        document.body.classList.add('sf-modal-open');
    }

    function handleInstallClick() {
        if (!deferredPrompt) {
            console.log('No deferred prompt available');
            return;
        }

        // Close modal first
        const installModal = document.getElementById('sfInstallModal');
        if (installModal) {
            installModal.classList.add('hidden');
            installModal.setAttribute('aria-hidden', 'true');
            document.body.classList.remove('sf-modal-open');
        }

        // Show the install prompt
        deferredPrompt.prompt();

        // Wait for the user to respond to the prompt
        deferredPrompt.userChoice.then((choiceResult) => {
            if (choiceResult.outcome === 'accepted') {
                console.log('User accepted the install prompt');
                hideInstallButton();
            } else {
                console.log('User dismissed the install prompt');
            }

            // Clear the deferred prompt
            deferredPrompt = null;
        });
    }

    // Initialize when DOM is ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();