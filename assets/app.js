// Import main CSS (Tailwind only - Flowbite styles come via plugin)
import './styles/app.css';

// Import Flowbite JavaScript for interactive components
import 'flowbite';

// Optional: import Stimulus if you're using it
import './bootstrap.js';

if (window.location.pathname.startsWith('/app')) {
  import('./app/register-sw.js');
}

console.log('Tailwind + Flowbite loaded via Encore ✅');