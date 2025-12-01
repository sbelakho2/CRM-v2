/*
 * Welcome to your app's main JavaScript file!
 *
 * This file will be included onto the page via the importmap() Twig function,
 * which should already be in your base.html.twig.
 */
import './styles/app.css';

// Start the Stimulus application
import './bootstrap.js';

// Import Alpine.js for reactive UI components
import Alpine from 'alpinejs';
window.Alpine = Alpine;
Alpine.start();

console.log('Starz CRM application loaded!');
