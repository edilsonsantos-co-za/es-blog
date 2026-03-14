(function ($) {
    'use strict';

    $(function () {
        var $sidebar = $('.nav-sidebar');
        var $treeItems = $sidebar.find('.nav-item.has-treeview');

        function closeItem($item) {
            $item.removeClass('menu-open');
            $item.children('.nav-treeview').stop(true, true).slideUp(150);
        }

        function openItem($item) {
            $treeItems.not($item).each(function () {
                closeItem($(this));
            });

            $item.addClass('menu-open');
            $item.children('.nav-treeview').stop(true, true).slideDown(150);
        }

        $treeItems.each(function () {
            var $item = $(this);
            var shouldOpen = $item.hasClass('menu-open') || $item.find('.nav-treeview .nav-link.active').length > 0;

            if (shouldOpen) {
                $item.addClass('menu-open');
                $item.children('.nav-treeview').show();
            } else {
                $item.children('.nav-treeview').hide();
            }
        });

        $sidebar.on('click', '.nav-item.has-treeview > .nav-link', function (event) {
            event.preventDefault();

            var $item = $(this).parent('.nav-item.has-treeview');

            if ($item.hasClass('menu-open')) {
                closeItem($item);
                return;
            }

            openItem($item);
        });
    });
})(jQuery);
