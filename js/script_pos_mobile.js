document.addEventListener('DOMContentLoaded', () => {
    // --- Mobile POS UI Sync Script ---

    // Get references to all duplicated elements
    const desktopCart = document.getElementById('order-items-container');
    const mobileCart = document.getElementById('order-items-container-mobile');
    
    const desktopSubtotalLine = document.getElementById('subtotal-line');
    const mobileSubtotalLine = document.getElementById('subtotal-line-mobile');
    const desktopSubtotalPrice = document.getElementById('subtotal-price');
    const mobileSubtotalPrice = document.getElementById('subtotal-price-mobile');

    const desktopDiscountLine = document.getElementById('discount-line');
    const mobileDiscountLine = document.getElementById('discount-line-mobile');
    const desktopDiscountAmount = document.getElementById('discount-amount');
    const mobileDiscountAmount = document.getElementById('discount-amount-mobile');
    const desktopDiscountText = document.querySelector('#discount-line .text-discount');
    const mobileDiscountText = document.querySelector('#discount-line-mobile .text-discount');

    const desktopTotal = document.getElementById('total-price');
    const mobileTotal = document.getElementById('total-price-mobile');

    const desktopPayBtn = document.getElementById('pay-button');
    const mobilePayBtn = document.getElementById('pay-button-mobile');

    const desktopClearBtn = document.getElementById('clear-button');
    const mobileClearBtn = document.getElementById('clear-button-mobile');

    // Mobile-only summary bar
    const mobileSummaryCount = document.getElementById('mobile-cart-count');
    const mobileSummaryTotal = document.getElementById('mobile-cart-total');

    const observer = new MutationObserver((mutations) => {
        // Sync cart items
        if (mobileCart) mobileCart.innerHTML = desktopCart.innerHTML;
        
        // Sync summary lines
        if (mobileSubtotalLine) mobileSubtotalLine.style.display = desktopSubtotalLine.style.display;
        if (mobileSubtotalPrice) mobileSubtotalPrice.textContent = desktopSubtotalPrice.textContent;
        
        if (mobileDiscountLine) mobileDiscountLine.style.display = desktopDiscountLine.style.display;
        if (mobileDiscountAmount) mobileDiscountAmount.textContent = desktopDiscountAmount.textContent;
        if (mobileDiscountText) mobileDiscountText.textContent = desktopDiscountText.textContent;

        // Sync main total
        if (mobileTotal) mobileTotal.textContent = desktopTotal.textContent;
        
        // Sync button states
        if (mobilePayBtn) mobilePayBtn.disabled = desktopPayBtn.disabled;
        
        // Sync mobile summary bar
        if (mobileSummaryTotal) mobileSummaryTotal.textContent = desktopTotal.textContent;
        if (mobileSummaryCount) {
            // Calculate item count (this is tricky, let's just grab it from the original script's `cart` variable)
            // A simpler way: count the items in the list.
            const itemCount = desktopCart.querySelectorAll('.cart-item').length;
            mobileSummaryCount.textContent = `${itemCount} Item${itemCount === 1 ? '' : 's'}`;
        }

        // --- Re-attach event listeners for mobile cart ---
        if (mobileCart) {
            mobileCart.querySelectorAll('.btn-dec').forEach(btn => {
                btn.addEventListener('click', () => window.updateQuantity(parseInt(btn.dataset.id), -1));
            });
            mobileCart.querySelectorAll('.btn-inc').forEach(btn => {
                btn.addEventListener('click', () => window.updateQuantity(parseInt(btn.dataset.id), 1));
            });
            mobileCart.querySelectorAll('.btn-remove').forEach(btn => {
                btn.addEventListener('click', () => window.setQuantity(parseInt(btn.dataset.id), 0));
            });
            mobileCart.querySelectorAll('.cart-quantity-input').forEach(input => {
                input.addEventListener('change', (e) => {
                    window.setQuantity(parseInt(e.target.dataset.id, 10), parseInt(e.target.value, 10));
                });
            });
        }
    });

    // Start observing the desktop cart for changes
    observer.observe(desktopCart, { childList: true, subtree: true });
    
    // Also observe the summary lines for text/style changes
    observer.observe(desktopTotal, { characterData: true, childList: true });
    observer.observe(desktopSubtotalPrice, { characterData: true, childList: true });
    observer.observe(desktopDiscountAmount, { characterData: true, childList: true });
    observer.observe(desktopDiscountText, { characterData: true, childList: true });
    observer.observe(desktopPayBtn, { attributes: true, attributeFilter: ['disabled'] });

    // --- Sync button clicks from mobile to desktop ---
    // (The original script's listeners are only on the desktop buttons)
    if (mobilePayBtn) {
        mobilePayBtn.addEventListener('click', () => {
            desktopPayBtn.click(); // Trigger the original pay button
        });
    }
    if (mobileClearBtn) {
        mobileClearBtn.addEventListener('click', () => {
            desktopClearBtn.click(); // Trigger the original clear button
        });
    }

    const mobileDiscountBtn = document.getElementById('mobile-discount-btn');
    if (mobileDiscountBtn) {
        mobileDiscountBtn.addEventListener('click', () => {
            // Access the global modal instance created in script_pos.js
            if (window.bootstrapDiscountModal) {
                window.bootstrapDiscountModal.show();
            }
        });
    }
});