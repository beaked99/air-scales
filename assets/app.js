// Import main CSS (Tailwind + Flowbite)
import './styles/app.css';

// Import Flowbite (optional, if you want its JS features like modals, tooltips, etc.)
import 'flowbite';

// Optional: import Stimulus if you're using it
import './bootstrap.js';

if (window.location.pathname.startsWith('/app')) {
  import('./app/register-sw.js');
}
console.log('Tailwind + Flowbite loaded via Encore ✅');