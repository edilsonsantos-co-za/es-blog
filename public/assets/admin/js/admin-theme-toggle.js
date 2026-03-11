/**
 * Admin Theme Toggle
 * Handles dark/light mode switching for the admin panel
 */
(function() {
    'use strict';

    // Get current theme from body class on page load
    function getCurrentTheme() {
        return document.body.classList.contains('dark-mode') ? 'dark' : 'light';
    }

    // Update icon based on current theme
    function updateIcon(theme) {
        const icon = document.getElementById('theme-icon');
        if (!icon) return;

        if (theme === 'dark') {
            icon.classList.remove('fa-moon');
            icon.classList.add('fa-sun');
        } else {
            icon.classList.remove('fa-sun');
            icon.classList.add('fa-moon');
        }
    }

    // Toggle theme classes on body and navbar
    function toggleTheme() {
        const body = document.body;
        const navbar = document.getElementById('main-navbar');

        // Toggle body classes
        body.classList.toggle('dark-mode');
        body.classList.toggle('light-mode');

        // Toggle navbar classes
        if (navbar) {
            if (body.classList.contains('dark-mode')) {
                navbar.classList.remove('navbar-white', 'navbar-light');
                navbar.classList.add('navbar-gray-dark', 'navbar-dark');
            } else {
                navbar.classList.remove('navbar-gray-dark', 'navbar-dark');
                navbar.classList.add('navbar-white', 'navbar-light');
            }
        }

        // Get new theme and update icon
        const newTheme = getCurrentTheme();
        updateIcon(newTheme);

        // Save to server
        saveThemePreference(newTheme);

        return newTheme;
    }

    // Save theme preference to server via AJAX
    function saveThemePreference(theme) {
        // Get site URL from a script tag or construct it
        const siteUrl = window.location.origin + window.location.pathname.split('/admin')[0] + '/';

        fetch(siteUrl + 'admin/theme-toggle', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'theme=' + theme
        })
        .then(response => response.json())
        .then(data => {
            // Theme saved successfully
        })
        .catch(error => {
            // Handle error silently
        });
    }

    // Initialize on page load
    document.addEventListener('DOMContentLoaded', function() {
        const toggleButton = document.getElementById('theme-toggle');

        if (toggleButton) {
            // Set initial icon based on current theme
            const currentTheme = getCurrentTheme();
            updateIcon(currentTheme);

            // Add click event listener
            toggleButton.addEventListener('click', function(e) {
                e.preventDefault();
                toggleTheme();
            });
        }
    });
})();
