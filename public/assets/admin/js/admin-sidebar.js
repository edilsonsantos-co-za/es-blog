/**
 * Admin Sidebar Custom Behavior
 * Fixes sidebar navigation to prevent collapse on nav-link clicks
 * and ensures only one dropdown is open at a time
 */
(function($) {
    'use strict';

    // Run before DOM ready to capture events as early as possible
    var SIDEBAR_STATE_KEY = 'adminlte_sidebar_collapsed';

    // For child menu item clicks, save which dropdown should stay open
    $(document).on('click', '.nav-sidebar .nav-treeview .nav-link', function(e) {
        e.stopPropagation();
        localStorage.setItem(SIDEBAR_STATE_KEY, 'false');

        // Save which parent dropdown this belongs to
        var $parentDropdown = $(this).closest('.nav-item.has-treeview');
        var dropdownIndex = $('.nav-sidebar .nav-item.has-treeview').index($parentDropdown);
        localStorage.setItem('open_dropdown_index', dropdownIndex);
    });

    $(document).ready(function() {
        // Force sidebar open immediately
        $('body').removeClass('sidebar-collapse').addClass('sidebar-open');
        localStorage.setItem(SIDEBAR_STATE_KEY, 'false');

        // Restore previously open dropdown IMMEDIATELY to prevent glitch
        var savedDropdownIndex = localStorage.getItem('open_dropdown_index');
        if (savedDropdownIndex !== null) {
            var $dropdownToOpen = $('.nav-sidebar .nav-item.has-treeview').eq(parseInt(savedDropdownIndex));
            if ($dropdownToOpen.length > 0) {
                $dropdownToOpen.addClass('menu-open');
                // Use CSS display instead of slideDown to avoid animation glitch
                $dropdownToOpen.find('> .nav-treeview').css('display', 'block');
            }
        }

        setTimeout(function() {
            // Destroy only PushMenu plugin, keep Treeview working
            if (typeof $.fn.PushMenu !== 'undefined') {
                $('[data-widget="pushmenu"]').off('.lte.pushmenu');
            }

            // Check if Treeview plugin exists
            if (typeof $.fn.Treeview !== 'undefined') {
                // Manually initialize the Treeview plugin
                try {
                    $('[data-widget="treeview"]').Treeview('init');
                } catch(e) {
                    // Silently handle errors
                }
            }

            // Handle hamburger menu clicks manually
            $('[data-widget="pushmenu"]').off('click').on('click', function(e) {
                e.preventDefault();
                e.stopImmediatePropagation();

                var $body = $('body');

                if ($body.hasClass('sidebar-collapse')) {
                    $body.removeClass('sidebar-collapse').addClass('sidebar-open');
                    localStorage.setItem(SIDEBAR_STATE_KEY, 'false');
                } else {
                    $body.removeClass('sidebar-open').addClass('sidebar-collapse');
                    localStorage.setItem(SIDEBAR_STATE_KEY, 'true');
                }

                return false;
            });

            // Monitor for any unwanted sidebar collapse and immediately fix it
            var observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.attributeName === 'class') {
                        var $body = $(mutation.target);
                        var collapsed = localStorage.getItem(SIDEBAR_STATE_KEY) === 'true';

                        if (!collapsed && $body.hasClass('sidebar-collapse')) {
                            // Sidebar collapsed but shouldn't be - fix it
                            $body.removeClass('sidebar-collapse').addClass('sidebar-open');
                        }
                    }
                });
            });

            // Start observing body class changes
            observer.observe(document.body, {
                attributes: true,
                attributeFilter: ['class']
            });

            // Add a manual click handler as a fallback
            $('.nav-sidebar .nav-item.has-treeview > .nav-link').off('click.manual').on('click.manual', function(e) {
                var $link = $(this);
                var $parent = $link.parent('.nav-item');
                var $treeview = $parent.find('> .nav-treeview');

                // Check if this is a treeview parent
                if ($treeview.length > 0) {
                    e.preventDefault();

                    var isOpen = $parent.hasClass('menu-open');

                    if (isOpen) {
                        $parent.removeClass('menu-open');
                        $treeview.slideUp(300);
                        // Clear saved state when manually closing
                        localStorage.removeItem('open_dropdown_index');
                    } else {
                        $parent.addClass('menu-open');
                        $treeview.slideDown(300);
                        // Save the opened dropdown
                        var dropdownIndex = $('.nav-sidebar .nav-item.has-treeview').index($parent);
                        localStorage.setItem('open_dropdown_index', dropdownIndex);
                    }

                    return false;
                }
            });
        }, 50);
    });

})(jQuery);
