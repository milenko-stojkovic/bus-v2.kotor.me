import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** Brand burgundy — replaces default Tailwind `red` scale site-wide. */
const brandRed = {
    50: '#faf5f5',
    100: '#f2e1e3',
    200: '#e6c3c7',
    300: '#d4949c',
    400: '#bc5a67',
    500: '#a33344',
    600: '#9e2130',
    700: '#9e1321',
    800: '#7f101b',
    900: '#5f0c14',
    950: '#30060a',
};

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
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                red: brandRed,
            },
        },
    },

    plugins: [forms],
};
