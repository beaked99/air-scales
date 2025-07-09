// assets/app/register-sw.js

if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/app/sw.js').then(reg => {
    console.log('Service Worker registered', reg);

    reg.onupdatefound = () => {
      const newWorker = reg.installing;
      newWorker.onstatechange = () => {
        if (newWorker.state === 'installed') {
          if (navigator.serviceWorker.controller) {
            console.log('New version available. Reloading...');
            window.location.reload();
          } else {
            console.log('App ready for offline use.');
          }
        }
      };
    };
  }).catch(err => {
    console.error('Service Worker registration failed:', err);
  });
}
