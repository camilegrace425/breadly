document.addEventListener('DOMContentLoaded', () => {

    const body = document.body;
    
    // --- Desktop Button ---
    const switchBtn = document.getElementById('switch-btn');
    
    // --- Mobile Toggle Buttons ---
    const adminToggleBtn = document.getElementById('admin-toggle-btn');
    const cashierToggleBtn = document.getElementById('cashier-toggle-btn');

    // --- Helper function to switch to ADMIN view ---
    function showAdminForm() {
        body.classList.remove('cashier-mode'); // Show Admin form
        
        // Sync button states
        if (adminToggleBtn) adminToggleBtn.classList.add('active');
        if (cashierToggleBtn) cashierToggleBtn.classList.remove('active');
        if (switchBtn) switchBtn.textContent = 'Cashier Login?';
    }

    // --- Helper function to switch to CASHIER view ---
    function showCashierForm() {
        body.classList.add('cashier-mode'); // Show Cashier form
        
        // Sync button states
        if (cashierToggleBtn) cashierToggleBtn.classList.add('active');
        if (adminToggleBtn) adminToggleBtn.classList.remove('active');
        if (switchBtn) switchBtn.textContent = 'Admin Login?';
    }

    // --- Password Toggle Logic (works the same) ---
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

    // --- Desktop Switch Button Logic ---
    if (switchBtn) {
        switchBtn.addEventListener('click', () => {
            // Check current state and toggle
            if (body.classList.contains('cashier-mode')) {
                showAdminForm();
            } else {
                showCashierForm();
            }
        });
    }
    
    // --- Mobile Admin Toggle Logic (with touch support) ---
    if (adminToggleBtn) {
        // Add click listener
        adminToggleBtn.addEventListener('click', showAdminForm);
        
        // Add touchstart listener for iOS/mobile
        adminToggleBtn.addEventListener('touchstart', (e) => {
            // preventDefault stops the browser from firing a "ghost" click event later
            e.preventDefault(); 
            showAdminForm();
        });
    }
    
    // --- Mobile Cashier Toggle Logic (with touch support) ---
    if (cashierToggleBtn) {
        // Add click listener
        cashierToggleBtn.addEventListener('click', showCashierForm);
        
        // Add touchstart listener for iOS/mobile
        cashierToggleBtn.addEventListener('touchstart', (e) => {
            e.preventDefault();
            showCashierForm();
        });
    }

});