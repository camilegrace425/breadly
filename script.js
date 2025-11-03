// --- User-provided JavaScript ---

const body = document.body;
const switchBtn = document.getElementById('switch-btn');

// Password Toggle Logic
document.querySelectorAll('.password-toggle').forEach(toggle => {
    toggle.addEventListener('click', () => {
        const inputContainer = toggle.closest('.input-box'); 
        const input = inputContainer ? inputContainer.querySelector('input') : null;

        if (input) {
            const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
            input.setAttribute('type', type);
            
            // Toggle the icon classes to show/hide the eye
            toggle.classList.toggle('bx-show-alt'); 
            toggle.classList.toggle('bx-hide');     
        }
    });
});

switchBtn.addEventListener('click', () => {

    const isCashierMode = body.classList.toggle('cashier-mode');

    if (isCashierMode) {
        switchBtn.textContent = 'Admin Login?';
    } else {
        switchBtn.textContent = 'Cashier Login?';
    }
});
