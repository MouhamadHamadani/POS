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
                    50:  '#EAF5F5',
                    100: '#C9E6E5',
                    200: '#93CDC9',
                    300: '#5DB3AD',
                    400: '#2E9A92',
                    500: '#1D7D76',
                    600: '#17635E',
                    700: '#124F4A', // Primary teal (LebaSouk)
                    800: '#0C3733',
                    900: '#06211E',
                },
                accent: {
                    DEFAULT: '#E2673F', // Terracotta (LebaSouk)
                    light:   '#EF9271',
                    dark:    '#B84F2E',
                },
                success: '#27AE60',
                warning: '#E67E22',
                danger:  '#C0392B',
            },
            boxShadow: {
                card: '0 1px 3px rgba(0,0,0,0.08)',
                pop:  '0 8px 24px rgba(18,79,74,0.12)',
            },
        },
    },

    plugins: [forms],
};
