/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./assets/**/*.js",
    "./templates/**/*.html.twig",
  ],
  theme: {
    extend: {
      colors: {
        rams: {
          chassis: '#F2F2F2',
          module: '#E6E6E6',
          panel: '#D9D9D9',
          line: '#CCCCCC',
          muted: '#6F6F6F',
          orange: '#FFBE00',
          green: '#2D8C3C',
          red: '#D62D2D',
          steel: '#4A90E2',
        },
        // Geist-inspired color palette (keeping for backward compatibility)
        background: {
          DEFAULT: '#FFFFFF',
          secondary: '#FAFAFA',
          tertiary: '#F4F4F5',
        },
        border: {
          DEFAULT: '#E4E4E7',
          light: '#F4F4F5',
        },
        text: {
          primary: '#18181B',
          secondary: '#71717A',
          tertiary: '#A1A1AA',
        },
        accent: {
          DEFAULT: '#3B82F6',
          hover: '#2563EB',
          light: '#DBEAFE',
        },
      },
      fontFamily: {
        sans: ['Inter', '-apple-system', 'BlinkMacSystemFont', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'sans-serif'],
        mono: ['JetBrains Mono', 'SF Mono', 'Monaco', 'Menlo', 'Consolas', 'Courier New', 'monospace'],
      },
      borderRadius: {
        'sm': '6px',
        'md': '8px',
        'lg': '12px',
      },
      boxShadow: {
        'sm': '0 1px 2px 0 rgb(0 0 0 / 0.05)',
        'md': '0 4px 6px -1px rgb(0 0 0 / 0.05)',
        'lg': '0 10px 15px -3px rgb(0 0 0 / 0.05)',
      },
    },
  },
  plugins: [],
}
