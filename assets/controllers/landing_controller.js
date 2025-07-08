// assets/controllers/landing_controller.js
import { Controller } from "@hotwired/stimulus"

export default class extends Controller {
    connect() {
        const installBtn = document.getElementById('installApp');
        const promptContainer = document.getElementById('installPrompt');
        const warning = document.getElementById('browserWarning');

        if (!('serviceWorker' in navigator)) {
            warning.hidden = false;
            warning.textContent = 'Your browser does not support service workers.';
            return;
        }

        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            this.deferredPrompt = e;
            promptContainer.hidden = false;

            installBtn.addEventListener('click', () => {
                this.deferredPrompt.prompt();
                this.deferredPrompt.userChoice.then(() => {
                    this.deferredPrompt = null;
                    promptContainer.hidden = true;
                });
            });
        });

        // Fallback: show warning if no install prompt after 2s
        setTimeout(() => {
            if (!this.deferredPrompt) {
                warning.hidden = false;
                warning.textContent = 'App install not supported on this browser.';
            }
        }, 2000);
    }
}