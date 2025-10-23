
const body = document.body;
const switchBtn = document.getElementById('switch-btn');

document.querySelectorAll('.password-toggle').forEach(toggle => {
    toggle.addEventListener('click', () => {
        const input = toggle.previousElementSibling; // Get the input field
        const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
        input.setAttribute('type', type);
        
        toggle.classList.toggle('bx-show-alt');
        toggle.classList.toggle('bx-hide');
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