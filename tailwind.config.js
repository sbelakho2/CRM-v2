/** @type {import('tailwindcss').Config} */
module.exports = {
  content: [
    "./assets/**/*.js",
    "./templates/**/*.html.twig",
  ],
  theme: {
    extend: {
      colors: {
        // Geist-inspired color palette
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
        sans: ['-apple-system', 'BlinkMacSystemFont', '"Segoe UI"', 'Roboto', '"Helvetica Neue"', 'Arial', 'sans-serif'],
        mono: ['"SF Mono"', 'Monaco', 'Menlo', 'Consolas', '"Courier New"', 'monospace'],
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
