// File Location: breadly/js/script_account_management.js

// --- UI Helper Functions ---
function toggleSidebar() {
    const sidebar = document.getElementById('mobileSidebar');
    const overlay = document.getElementById('mobileSidebarOverlay');
    if (sidebar.classList.contains('-translate-x-full')) {
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
    } else {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
    }
}

function openModal(modalId) {
    const modal = document.getElementById(modalId);
    const backdrop = document.getElementById('modalBackdrop');
    if (modal) {
        modal.classList.remove('hidden');
        if(backdrop) backdrop.classList.remove('hidden');
    }
}

function closeModal(modalId) {
    const modal = document.getElementById(modalId);
    const backdrop = document.getElementById('modalBackdrop');
    if (modal) modal.classList.add('hidden');
    if(backdrop) backdrop.classList.add('hidden');
}

function closeAllModals() {
    document.querySelectorAll('.fixed.z-50').forEach(el => el.classList.add('hidden'));
    const backdrop = document.getElementById('modalBackdrop');
    if(backdrop) backdrop.classList.add('hidden');
}

function togglePassword() {
    const pwInput = document.getElementById('password');
    const pwIcon = document.getElementById('password-toggle-icon');
    if (pwInput.type === 'password') {
        pwInput.type = 'text';
        pwIcon.classList.replace('bx-show', 'bx-hide');
    } else {
        pwInput.type = 'password';
        pwIcon.classList.replace('bx-hide', 'bx-show');
    }
}

// --- Event Listeners ---
document.addEventListener('DOMContentLoaded', function() {
    const phoneInput = document.getElementById('phone');
    const phoneError = document.getElementById('phone-error');
    const passwordInput = document.getElementById('password');
    const passwordError = document.getElementById('password-error');

    if (phoneInput) {
        phoneInput.addEventListener('input', function() {
            // Enforce numeric only
            this.value = this.value.replace(/[^0-9]/g, '');

            // Show error if length is between 1 and 10. Hide if 11.
            if (this.value.length > 0 && this.value.length < 11) {
                phoneError.classList.remove('hidden');
                this.classList.add('border-red-300', 'focus:ring-red-200');
                this.classList.remove('border-gray-300', 'focus:ring-breadly-btn');
            } else {
                phoneError.classList.add('hidden');
                this.classList.remove('border-red-300', 'focus:ring-red-200');
                this.classList.add('border-gray-300', 'focus:ring-breadly-btn');
            }
        });
    }

    if (passwordInput) {
        passwordInput.addEventListener('input', function() {
            const pw = this.value;
            const isValid = pw.length >= 6 && /[a-zA-Z]/.test(pw) && /[0-9]/.test(pw);
            
            // Only show error if user has started typing and criteria not met
            if (pw.length > 0 && !isValid) {
                passwordError.classList.remove('hidden');
                this.classList.add('border-red-300', 'focus:ring-red-200');
                this.classList.remove('border-gray-300', 'focus:ring-breadly-btn');
            } else {
                passwordError.classList.add('hidden');
                this.classList.remove('border-red-300', 'focus:ring-red-200');
                this.classList.add('border-gray-300', 'focus:ring-breadly-btn');
            }
        });
    }
});

// --- AJAX LOGIC ---

function refreshTable() {
    fetch('account_management.php?ajax_action=fetch_users')
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                document.getElementById('user-table-body').innerHTML = data.html;
                document.getElementById('user-count').innerText = data.count + ' Users';
            }
        })
        .catch(err => console.error('Error refreshing table:', err));
}

function editUser(id) {
    fetch(`account_management.php?ajax_action=get_user&id=${id}`)
        .then(res => res.json())
        .then(resp => {
            if (resp.success) {
                const user = resp.data;
                document.getElementById('user_id').value = user.user_id;
                document.getElementById('username').value = user.username;
                document.getElementById('role').value = user.role;
                document.getElementById('phone').value = user.phone_number;
                document.getElementById('email').value = user.email || '';
                
                // Manually trigger input event to reset validation state if needed
                document.getElementById('phone').dispatchEvent(new Event('input'));
                
                // Update UI for Edit Mode
                document.getElementById('form-title').innerText = 'Edit Account';
                document.getElementById('form-btn').innerText = 'Update User';
                document.getElementById('form-btn').classList.replace('bg-blue-600', 'bg-orange-500');
                document.getElementById('form-btn').classList.replace('hover:bg-blue-700', 'hover:bg-orange-600');
                
                document.getElementById('form-header').classList.replace('bg-blue-50', 'bg-orange-50');
                document.getElementById('form-title').classList.replace('text-blue-800', 'text-orange-800');
                document.getElementById('form-icon').classList.replace('text-blue-600', 'text-orange-600');
                document.getElementById('form-icon').classList.replace('bx-user-plus', 'bx-edit');

                document.getElementById('password').required = false;
                document.getElementById('password').placeholder = 'New password (optional)';
                document.getElementById('password-hint').classList.remove('hidden');
                
                document.getElementById('cancel-btn-container').classList.remove('hidden');
            } else {
                Swal.fire('Error', resp.message, 'error');
            }
        })
        .catch(err => Swal.fire('Error', 'Connection failed', 'error'));
}

function resetForm() {
    document.getElementById('user-form').reset();
    document.getElementById('user_id').value = '0';
    
    // Reset validation UI
    document.getElementById('phone-error').classList.add('hidden');
    const phoneInput = document.getElementById('phone');
    phoneInput.classList.remove('border-red-300', 'focus:ring-red-200');
    phoneInput.classList.add('border-gray-300', 'focus:ring-breadly-btn');

    document.getElementById('password-error').classList.add('hidden');
    const passwordInput = document.getElementById('password');
    passwordInput.classList.remove('border-red-300', 'focus:ring-red-200');
    passwordInput.classList.add('border-gray-300', 'focus:ring-breadly-btn');
    
    // Reset visibility
    passwordInput.type = 'password';
    const pwIcon = document.getElementById('password-toggle-icon');
    if(pwIcon) pwIcon.classList.replace('bx-hide', 'bx-show');

    // Reset UI to Create Mode
    document.getElementById('form-title').innerText = 'Create Account';
    document.getElementById('form-btn').innerText = 'Create User';
    document.getElementById('form-btn').classList.replace('bg-orange-500', 'bg-blue-600');
    document.getElementById('form-btn').classList.replace('hover:bg-orange-600', 'hover:bg-blue-700');

    document.getElementById('form-header').classList.replace('bg-orange-50', 'bg-blue-50');
    document.getElementById('form-title').classList.replace('text-orange-800', 'text-blue-800');
    document.getElementById('form-icon').classList.replace('text-orange-600', 'text-blue-600');
    document.getElementById('form-icon').classList.replace('bx-edit', 'bx-user-plus');

    document.getElementById('password').required = true;
    document.getElementById('password').placeholder = 'Strong password';
    document.getElementById('password-hint').classList.add('hidden');
    
    document.getElementById('cancel-btn-container').classList.add('hidden');
}

function handleSaveUser(e) {
    e.preventDefault();
    
    // --- VALIDATION START ---
    const username = document.getElementById('username').value;
    const phone = document.getElementById('phone').value;
    const password = document.getElementById('password').value;
    const userId = document.getElementById('user_id').value;

    // 1. Username validation
    const alphanumericRegex = /^[a-zA-Z0-9]+$/;
    if (!alphanumericRegex.test(username)) {
        Swal.fire('Error', 'Username must contain only letters and numbers (no special characters or spaces).', 'error');
        return;
    }
    const numericRegex = /^[0-9]+$/;
    if (numericRegex.test(username)) {
        Swal.fire('Error', 'Username cannot be purely numeric.', 'error');
        return;
    }

    // 2. Phone validation (Strict 11 digits)
    if (phone.length !== 11) {
            Swal.fire('Error', 'Phone number must be exactly 11 digits.', 'error');
            document.getElementById('phone-error').classList.remove('hidden');
            return;
    }

    // 3. Password validation (If creating OR if password field is filled)
    if (userId === '0' || password.length > 0) {
            if (password.length < 6) {
            Swal.fire('Error', 'Password must be at least 6 characters long.', 'error');
            document.getElementById('password-error').classList.remove('hidden');
            return;
            }
            if (!/[a-zA-Z]/.test(password) || !/[0-9]/.test(password)) {
                Swal.fire('Error', 'Password must contain both letters and numbers.', 'error');
                document.getElementById('password-error').classList.remove('hidden');
                return;
            }
    }
    // --- VALIDATION END ---

    const formData = new FormData(e.target);
    formData.append('ajax_action', 'save_user');

    fetch('account_management.php', {
        method: 'POST',
        body: formData
    })
    .then(res => res.json())
    .then(resp => {
        if (resp.success) {
            Swal.fire({
                icon: 'success',
                title: 'Success',
                text: resp.message,
                timer: 1500,
                showConfirmButton: false
            });
            resetForm();
            refreshTable();
        } else {
            Swal.fire('Error', resp.message, 'error');
        }
    })
    .catch(err => Swal.fire('Error', 'Request failed', 'error'));
}

function deleteUser(id) {
    Swal.fire({
        title: 'Delete User?',
        text: "You won't be able to revert this!",
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#d33',
        confirmButtonText: 'Yes, delete it!'
    }).then((result) => {
        if (result.isConfirmed) {
            const formData = new FormData();
            formData.append('ajax_action', 'delete_user');
            formData.append('id', id);

            fetch('account_management.php', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(resp => {
                if (resp.success) {
                    Swal.fire('Deleted!', resp.message, 'success');
                    refreshTable();
                } else {
                    Swal.fire('Error', resp.message, 'error');
                }
            })
            .catch(err => Swal.fire('Error', 'Request failed', 'error'));
        }
    });
}