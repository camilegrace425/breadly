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
    
    // FIX: Target the first span inside the discount line for the text label
    const desktopDiscountText = desktopDiscountLine ? desktopDiscountLine.querySelector('span') : null;
    const mobileDiscountText = mobileDiscountLine ? mobileDiscountLine.querySelector('span') : null;

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
        if (mobileCart && desktopCart) mobileCart.innerHTML = desktopCart.innerHTML;
        
        // Sync summary lines
        if (mobileSubtotalLine && desktopSubtotalLine) mobileSubtotalLine.style.display = desktopSubtotalLine.style.display;
        if (mobileSubtotalPrice && desktopSubtotalPrice) mobileSubtotalPrice.textContent = desktopSubtotalPrice.textContent;
        
        if (mobileDiscountLine && desktopDiscountLine) mobileDiscountLine.style.display = desktopDiscountLine.style.display;
        if (mobileDiscountAmount && desktopDiscountAmount) mobileDiscountAmount.textContent = desktopDiscountAmount.textContent;
        if (mobileDiscountText && desktopDiscountText) mobileDiscountText.innerHTML = desktopDiscountText.innerHTML;

        // Sync main total
        if (mobileTotal && desktopTotal) mobileTotal.textContent = desktopTotal.textContent;
        
        // Sync button states
        if (mobilePayBtn && desktopPayBtn) mobilePayBtn.disabled = desktopPayBtn.disabled;
        
        // Sync mobile summary bar
        if (mobileSummaryTotal && desktopTotal) mobileSummaryTotal.textContent = desktopTotal.textContent;
        if (mobileSummaryCount && desktopCart) {
            const itemCount = desktopCart.querySelectorAll('.flex.justify-between').length;
            mobileSummaryCount.textContent = `${itemCount} Item${itemCount !== 1 ? 's' : ''}`;
        }

        // --- Re-attach event listeners for mobile cart ---
        if (mobileCart) {
            mobileCart.querySelectorAll('.btn-dec').forEach(btn => {
                btn.addEventListener('click', () => {
                    if(window.updateQuantity) window.updateQuantity(parseInt(btn.dataset.id), -1);
                });
            });
            mobileCart.querySelectorAll('.btn-inc').forEach(btn => {
                btn.addEventListener('click', () => {
                    if(window.updateQuantity) window.updateQuantity(parseInt(btn.dataset.id), 1);
                });
            });
            mobileCart.querySelectorAll('.btn-remove').forEach(btn => {
                btn.addEventListener('click', () => {
                    if(window.setQuantity) window.setQuantity(parseInt(btn.dataset.id), 0);
                });
            });
            mobileCart.querySelectorAll('.cart-quantity-input').forEach(input => {
                input.addEventListener('change', (e) => {
                    if(window.setQuantity) window.setQuantity(parseInt(e.target.dataset.id, 10), parseInt(e.target.value, 10));
                });
            });
        }
    });

    // FIX: Add safety checks before observing to prevent "parameter 1 is not of type 'Node'" error
    if (desktopCart) observer.observe(desktopCart, { childList: true, subtree: true });
    
    // Use optional chaining or if-checks for specific elements
    if (desktopTotal) observer.observe(desktopTotal, { characterData: true, childList: true, subtree: true });
    if (desktopSubtotalPrice) observer.observe(desktopSubtotalPrice, { characterData: true, childList: true, subtree: true });
    if (desktopDiscountAmount) observer.observe(desktopDiscountAmount, { characterData: true, childList: true, subtree: true });
    if (desktopDiscountText) observer.observe(desktopDiscountText, { characterData: true, childList: true, subtree: true });
    if (desktopPayBtn) observer.observe(desktopPayBtn, { attributes: true, attributeFilter: ['disabled'] });

    // --- Sync button clicks from mobile to desktop ---
    if (mobilePayBtn && desktopPayBtn) {
        mobilePayBtn.addEventListener('click', () => {
            desktopPayBtn.click(); 
        });
    }
    
    if (mobileClearBtn && desktopClearBtn) {
        mobileClearBtn.addEventListener('click', () => {
            desktopClearBtn.click();
        });
    }

    const mobileDiscountBtn = document.getElementById('mobile-discount-btn');
    if (mobileDiscountBtn) {
        mobileDiscountBtn.addEventListener('click', () => {
            if (window.toggleModal) {
                // Close mobile cart modal first
                window.toggleModal('mobileCartModal');
                // Open discount modal
                window.toggleModal('discountModal');
            }
        });
    }
});