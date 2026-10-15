/** Tailwind-Konfiguration. Build: npm install && npm run build */
module.exports = {
  darkMode: 'class',
  content: [
    '../app/Views/**/*.php',
    '../public/assets/js/**/*.js',
    '../public/setup.php',
  ],
  theme: {
    extend: {
      colors: {
        // Akzentfarbe ist im Adminbereich frei konfigurierbar (CSS-Variable --accent als "r g b")
        accent: 'rgb(var(--accent) / <alpha-value>)',
      },
      fontFamily: {
        sans: ['ui-sans-serif', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'Helvetica Neue', 'Arial', 'Noto Sans', 'sans-serif'],
      },
      boxShadow: {
        soft: '0 1px 2px rgba(16,24,40,.04), 0 8px 24px -8px rgba(16,24,40,.12)',
        pop: '0 12px 40px -8px rgba(16,24,40,.25)',
      },
      keyframes: {
        fadeIn: { from: { opacity: '0' }, to: { opacity: '1' } },
        slideUp: { from: { opacity: '0', transform: 'translateY(8px)' }, to: { opacity: '1', transform: 'translateY(0)' } },
        popIn: { from: { opacity: '0', transform: 'scale(.97)' }, to: { opacity: '1', transform: 'scale(1)' } },
        slideLeft: { from: { transform: 'translateX(100%)' }, to: { transform: 'translateX(0)' } },
      },
      animation: {
        'fade-in': 'fadeIn .15s ease-out',
        'slide-up': 'slideUp .2s ease-out',
        'pop-in': 'popIn .15s ease-out',
        'slide-left': 'slideLeft .2s ease-out',
      },
    },
  },
  plugins: [],
};
