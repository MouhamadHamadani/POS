import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Inter', 'Figtree', ...defaultTheme.fontFamily.sans],
                arabic: ['Cairo', 'Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                brand: {
                    50:  '#EEF3FA',
                    100: '#D6E2F1',
                    200: '#A8C0DF',
                    300: '#7A9DCD',
                    400: '#4F7BB8',
                    500: '#2E5BA0',
                    600: '#23467E',
                    700: '#1B3A6B', // Primary navy (spec §27.1)
                    800: '#13284A',
                    900: '#0B182C',
                },
                accent: {
                    DEFAULT: '#2980B9', // Sky blue (spec §27.1)
                    light:   '#5DADE2',
                    dark:    '#1F618D',
                },
                success: '#27AE60',
                warning: '#E67E22',
                danger:  '#C0392B',
            },
            boxShadow: {
                card: '0 1px 3px rgba(0,0,0,0.08)',
                pop:  '0 8px 24px rgba(27,58,107,0.12)',
            },
        },
    },

    plugins: [forms],
};
