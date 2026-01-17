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

// Select all checkboxes
function toggleSelectAll(checkbox) {
    var checkboxes = document.querySelectorAll('input[name="selected[]"]');
    checkboxes.forEach(function(cb) {
        cb.checked = checkbox.checked;
    });
}
