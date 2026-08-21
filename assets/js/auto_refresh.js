/**
 * RestoCloud Smart Auto-Refresh
 * Reloads the page every 5-10 seconds ONLY IF it is safe to do so.
 * Safe means:
 * - No SweetAlerts are open
 * - No Bootstrap Modals are open
 * - The user hasn't interacted with the page in the last 15 seconds
 * - The user hasn't typed data into any form inputs
 */

(function() {
    const REFRESH_INTERVAL = 5000; // 5 seconds
    let lastInteraction = 0; // Initialize to 0 so it can refresh immediately if no interaction

    // Track user interactions
    ['mousedown', 'touchstart', 'keydown', 'input'].forEach(evt => {
        document.addEventListener(evt, () => {
            lastInteraction = Date.now();
        }, { passive: true });
    });

    function checkAndReload() {
        // 1. Check for SweetAlert2
        if (document.querySelector('.swal2-shown') || document.querySelector('.swal2-container')) {
            return; // Don't refresh if a SweetAlert is active
        }

        // 2. Check for Bootstrap Modals
        if (document.querySelector('.modal.show') || document.body.classList.contains('modal-open')) {
            return; // Don't refresh if a modal is active
        }

        // 3. Check for recent interaction (pause refresh for 15 seconds after any click/type)
        if (Date.now() - lastInteraction < 15000) {
            return;
        }

        // 4. Check for User Typing / Active Inputs
        const activeElement = document.activeElement;
        if (activeElement) {
            const tag = activeElement.tagName.toLowerCase();
            const type = activeElement.type ? activeElement.type.toLowerCase() : '';
            if (['input', 'textarea', 'select'].includes(tag)) {
                if (type !== 'button' && type !== 'submit' && type !== 'hidden') {
                    return; // User has an input focused
                }
            }
        }

        // 5. Check if any input has modified data (dirty state)
        let hasModifiedInputs = false;
        document.querySelectorAll('input:not([type="hidden"]):not([type="submit"]):not([type="button"]):not([type="checkbox"]):not([type="radio"]), textarea').forEach(el => {
            if (el.value !== '' && el.value !== el.defaultValue) {
                hasModifiedInputs = true;
            }
        });
        if (hasModifiedInputs) {
            return; // Block refresh if there's typed data on the screen
        }

        // If all checks pass, it's safe to reload
        window.location.reload();
    }

    // Start the interval when the page loads
    document.addEventListener('DOMContentLoaded', () => {
        // Only start refresh loop if it's not disabled by a meta tag
        setInterval(checkAndReload, REFRESH_INTERVAL);
    });
})();
