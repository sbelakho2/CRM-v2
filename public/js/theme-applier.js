/**
 * Theme Applier - applies user theme/accent/font-size/density/reduced-motion
 * CSS variables from the <body> data-* attributes.
 *
 * Runs synchronously (no defer) so appearance settings are applied before
 * first paint. Shared by base.html.twig and base_login.html.twig.
 */
(function() {
    const root = document.documentElement;
    const body = document.body;
    const userTheme = body.getAttribute('data-user-theme') || 'system';
    const accent = body.getAttribute('data-user-accent') || 'orange';
    const fontSize = body.getAttribute('data-user-font-size') || 'medium';
    const density = body.getAttribute('data-user-density') || 'comfortable';
    const reducedMotion = body.getAttribute('data-user-reduced-motion') === 'true';

    // Accent color definitions (matching Management-Software)
    const accentColors = {
        orange: { hsl: '45 100% 50%', hex: '#FFBE00' },
        blue: { hsl: '217 91% 60%', hex: '#3B82F6' },
        green: { hsl: '142 71% 45%', hex: '#22C55E' },
        purple: { hsl: '258 90% 66%', hex: '#8B5CF6' },
        red: { hsl: '0 84% 60%', hex: '#EF4444' }
    };

    // Font size mapping
    const fontSizes = {
        small: '14px',
        medium: '16px',
        large: '18px'
    };

    function applyTheme(theme) {
        if (theme === 'dark') {
            root.setAttribute('data-theme', 'dark');
            root.classList.add('dark');
        } else if (theme === 'light') {
            root.setAttribute('data-theme', 'light');
            root.classList.remove('dark');
        } else {
            root.removeAttribute('data-theme');
            root.classList.remove('dark');
        }
    }

    function applySystemTheme() {
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(prefersDark ? 'dark' : 'light');
    }

    function applyAccentColor(colorName) {
        const color = accentColors[colorName] || accentColors.orange;
        root.style.setProperty('--rams-accent', color.hex);
        root.style.setProperty('--accent', color.hsl);
        root.style.setProperty('--primary', color.hsl);
        root.style.setProperty('--ring', color.hsl);
    }

    function applyFontSize(size) {
        const sizeValue = fontSizes[size] || fontSizes.medium;
        root.style.setProperty('--base-font-size', sizeValue);
        root.style.fontSize = sizeValue;
    }

    function applyDensity(densityValue) {
        root.classList.remove('density-comfortable', 'density-compact');
        root.classList.add(`density-${densityValue}`);
    }

    function applyReducedMotion(enabled) {
        if (enabled) {
            root.classList.add('reduce-motion');
            root.style.setProperty('--transition-duration', '0ms');
        } else {
            root.classList.remove('reduce-motion');
            root.style.removeProperty('--transition-duration');
        }
    }

    // Apply theme
    if (userTheme === 'system') {
        applySystemTheme();
        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', applySystemTheme);
        }
    } else {
        applyTheme(userTheme);
    }

    // Apply all appearance settings
    applyAccentColor(accent);
    applyFontSize(fontSize);
    applyDensity(density);
    applyReducedMotion(reducedMotion);
})();
