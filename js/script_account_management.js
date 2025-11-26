document.addEventListener('DOMContentLoaded', function() {

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
});