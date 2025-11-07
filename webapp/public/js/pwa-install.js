// Add this to your existing JavaScript temporarily
console.log('🔍 PWA: Waiting for beforeinstallprompt event...');

let deferredPrompt;

window.addEventListener('beforeinstallprompt', (e) => {
    console.log('🎉 beforeinstallprompt event fired! PWA is installable!');
    e.preventDefault();
    deferredPrompt = e;
    showInstallButton();
});

function showInstallButton() {
    console.log('🔍 Showing install button...');
    const installBtn = document.getElementById('install-btn');
    if (installBtn) {
        installBtn.style.display = 'block';
        installBtn.addEventListener('click', installApp);
        console.log('✅ Install button is now visible');
    }
}

function installApp() {
    if (deferredPrompt) {
        deferredPrompt.prompt();
        deferredPrompt.userChoice.then((choiceResult) => {
            console.log('User choice:', choiceResult.outcome);
            deferredPrompt = null;
        });
    }
}

// Check if we're already in standalone mode (already installed)
if (window.matchMedia('(display-mode: standalone)').matches) {
    console.log('🔍 PWA is already installed/running in standalone mode');
}