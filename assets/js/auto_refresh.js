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
    const REFRESH_INTERVAL = 10000; // 10 seconds
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

        // If all checks pass, fetch the page content silently to prevent flickering
        fetch(window.location.href)
            .then(response => response.text())
            .then(html => {
                const parser = new DOMParser();
                const doc = parser.parseFromString(html, 'text/html');
                
                const mainSelector = 'main.main-content, main.app-content, main, .main-content, .app-content';
                const currentMain = document.querySelector(mainSelector);
                const newMain = doc.querySelector(mainSelector);
                
                if (currentMain && newMain) {
                    // Save UI states
                    const state = saveUIState(currentMain);
                    
                    // Swap content
                    currentMain.innerHTML = newMain.innerHTML;
                    
                    // Restore UI states
                    restoreUIState(currentMain, state);
                } else {
                    window.location.reload();
                }
            })
            .catch(err => {
                console.warn('Smart auto-refresh failed, retrying in next loop', err);
            });
    }

    function saveUIState(container) {
        const activeStates = [];
        container.querySelectorAll('.active').forEach(el => {
            let selector = el.className.split(' ').find(c => c !== 'active' && c.trim() !== '');
            if (selector) {
                selector = '.' + selector;
            } else if (el.id) {
                selector = '#' + el.id;
            } else {
                selector = el.tagName.toLowerCase();
            }
            
            const siblings = Array.from(container.querySelectorAll(selector));
            const index = siblings.indexOf(el);
            activeStates.push({ selector, index });
        });

        const displayStates = [];
        container.querySelectorAll('.tab-content, [id^="tab-"], [id^="panel-"]').forEach(el => {
            if (el.id) {
                displayStates.push({ id: el.id, display: el.style.display });
            }
        });

        return { activeStates, displayStates };
    }

    function restoreUIState(container, state) {
        if (!state) return;
        
        // Remove active class from fresh HTML elements before restoring previous states
        container.querySelectorAll('.active').forEach(el => el.classList.remove('active'));

        // Restore active states
        state.activeStates.forEach(s => {
            const siblings = Array.from(container.querySelectorAll(s.selector));
            if (siblings[s.index]) {
                siblings[s.index].classList.add('active');
            }
        });

        // Restore display styles
        state.displayStates.forEach(s => {
            const el = container.querySelector('#' + s.id);
            if (el) {
                el.style.display = s.display;
            }
        });
    }

    // Start the interval when the page loads
    document.addEventListener('DOMContentLoaded', () => {
        // Only start refresh loop if it's not disabled by a meta tag
        setInterval(checkAndReload, REFRESH_INTERVAL);
    });
})();
