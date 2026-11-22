// The application is intentionally light-only, so the theme is never derived
// from the operating system preference or from a stored user choice.
export function initializeTheme() {
    document.documentElement.classList.remove('dark');
    document.documentElement.style.colorScheme = 'light';
    localStorage.removeItem('appearance');
}
