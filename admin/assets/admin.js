/**
 * webOS Catalog Admin JavaScript
 */

// Confirm delete actions
document.addEventListener('DOMContentLoaded', function() {
    // Add confirmation to delete buttons
    document.querySelectorAll('.btn-delete, [data-confirm]').forEach(function(el) {
        el.addEventListener('click', function(e) {
            var message = el.getAttribute('data-confirm') || 'Are you sure you want to delete this item?';
            if (!confirm(message)) {
                e.preventDefault();
            }
        });
    });

    // Auto-hide alerts after 5 seconds
    document.querySelectorAll('.alert').forEach(function(alert) {
        setTimeout(function() {
            alert.style.opacity = '0';
            alert.style.transition = 'opacity 0.5s';
            setTimeout(function() {
                alert.remove();
            }, 500);
        }, 5000);
    });
});

// Per-row action popup menus (.action-menu / .action-menu-toggle / .action-menu-list)
document.addEventListener('click', function (e) {
    var toggle = e.target.closest('.action-menu-toggle');
    var openMenu = document.querySelector('.action-menu.open');

    if (toggle) {
        var menu = toggle.closest('.action-menu');
        var wasOpen = menu.classList.contains('open');
        if (openMenu && openMenu !== menu) {
            openMenu.classList.remove('open');
        }
        menu.classList.toggle('open', !wasOpen);
        toggle.setAttribute('aria-expanded', !wasOpen ? 'true' : 'false');
        e.preventDefault();
        e.stopPropagation();
        return;
    }

    if (openMenu && !openMenu.contains(e.target)) {
        openMenu.classList.remove('open');
        var t = openMenu.querySelector('.action-menu-toggle');
        if (t) t.setAttribute('aria-expanded', 'false');
    }
});

document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
        var openMenu = document.querySelector('.action-menu.open');
        if (openMenu) {
            openMenu.classList.remove('open');
            var t = openMenu.querySelector('.action-menu-toggle');
            if (t) t.setAttribute('aria-expanded', 'false');
        }
    }
});

// Select all checkboxes
function toggleSelectAll(checkbox) {
    var checkboxes = document.querySelectorAll('input[name="selected[]"]');
    checkboxes.forEach(function(cb) {
        cb.checked = checkbox.checked;
    });
}

// Collapse the top nav to a hamburger only when the items don't fit.
(function () {
    var nav = document.getElementById('adminNav');
    var toggle = document.getElementById('navToggle');
    if (!nav || !toggle) {
        return;
    }

    function fit() {
        // Measure at natural width; collapse if the content overflows the bar.
        nav.classList.remove('nav-collapsed', 'nav-open');
        nav.classList.add('nav-measure');
        var overflowing = nav.scrollWidth > nav.clientWidth + 1;
        nav.classList.remove('nav-measure');
        if (overflowing) {
            nav.classList.add('nav-collapsed');
        }
        toggle.setAttribute('aria-expanded', 'false');
    }

    toggle.addEventListener('click', function (e) {
        e.stopPropagation();
        var open = nav.classList.toggle('nav-open');
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    // Close the dropdown when clicking outside it.
    document.addEventListener('click', function (e) {
        if (nav.classList.contains('nav-open') && !nav.contains(e.target)) {
            nav.classList.remove('nav-open');
            toggle.setAttribute('aria-expanded', 'false');
        }
    });

    var resizeTimer;
    window.addEventListener('resize', function () {
        clearTimeout(resizeTimer);
        resizeTimer = setTimeout(fit, 100);
    });

    fit();
})();
