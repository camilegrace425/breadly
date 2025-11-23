document.addEventListener('DOMContentLoaded', () => {

    const body = document.body;
    const switchBtn = document.getElementById('switch-btn');
    const adminToggleBtn = document.getElementById('admin-toggle-btn');
    const cashierToggleBtn = document.getElementById('cashier-toggle-btn');

    function showAdminForm() {
        body.classList.remove('cashier-mode');
        
        if (adminToggleBtn) adminToggleBtn.classList.add('active');
        if (cashierToggleBtn) cashierToggleBtn.classList.remove('active');
        if (switchBtn) switchBtn.textContent = 'Cashier Login?';
    }

    function showCashierForm() {
        body.classList.add('cashier-mode');
        
        if (cashierToggleBtn) cashierToggleBtn.classList.add('active');
        if (adminToggleBtn) adminToggleBtn.classList.remove('active');
        if (switchBtn) switchBtn.textContent = 'Manager Login?';
    }

    // Password Toggle Logic
    document.querySelectorAll('.password-toggle').forEach(toggle => {
        toggle.addEventListener('click', () => {
            const inputContainer = toggle.closest('.input-box'); 
            const input = inputContainer ? inputContainer.querySelector('input') : null;

            if (input) {
                const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                input.setAttribute('type', type);
                
                toggle.classList.toggle('bx-show-alt'); 
                toggle.classList.toggle('bx-hide');
            }
        });
    });

    // Desktop Switch Button Logic
    if (switchBtn) {
        switchBtn.addEventListener('click', () => {
            if (body.classList.contains('cashier-mode')) {
                showAdminForm();
            } else {
                showCashierForm();
            }
        });
    }
    
    // Mobile Toggle Logic
    if (adminToggleBtn) {
        adminToggleBtn.addEventListener('click', showAdminForm);
    }
    
    if (cashierToggleBtn) {
        cashierToggleBtn.addEventListener('click', showCashierForm);
    }

});