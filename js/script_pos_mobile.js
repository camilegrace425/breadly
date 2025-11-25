document.addEventListener('DOMContentLoaded', () => {

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
    
    const desktopDiscountText = desktopDiscountLine ? desktopDiscountLine.querySelector('span') : null;
    const mobileDiscountText = mobileDiscountLine ? mobileDiscountLine.querySelector('span') : null;

    const desktopTotal = document.getElementById('total-price');
    const mobileTotal = document.getElementById('total-price-mobile');

    const desktopPayBtn = document.getElementById('pay-button');
    const mobilePayBtn = document.getElementById('pay-button-mobile');

    const desktopClearBtn = document.getElementById('clear-button');
    const mobileClearBtn = document.getElementById('clear-button-mobile');

    const mobileSummaryCount = document.getElementById('mobile-cart-count');
    const mobileSummaryTotal = document.getElementById('mobile-cart-total');

    // Logic to re-attach listeners after content sync
    function attachMobileListeners() {
        if (!mobileCart) return;

        const syncListener = (selector, action) => {
            mobileCart.querySelectorAll(selector).forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = parseInt(btn.dataset.id);
                    if (action === 'inc') window.updateQuantity(id, 1);
                    else if (action === 'dec') window.updateQuantity(id, -1);
                    else if (action === 'remove') window.setQuantity(id, 0);
                });
            });
        };

        syncListener('.btn-dec', 'dec');
        syncListener('.btn-inc', 'inc');
        syncListener('.btn-remove', 'remove');
        
        mobileCart.querySelectorAll('.cart-quantity-input').forEach(input => {
            input.addEventListener('change', (e) => {
                if(window.setQuantity) window.setQuantity(parseInt(e.target.dataset.id, 10), parseInt(e.target.value, 10));
            });
        });
    }

    const observer = new MutationObserver(() => {
        // Sync Cart Items and re-attach listeners
        if (mobileCart && desktopCart) {
            mobileCart.innerHTML = desktopCart.innerHTML;
            attachMobileListeners();
        }
        
        // Sync Summary Lines
        if (mobileSubtotalLine && desktopSubtotalLine) mobileSubtotalLine.style.display = desktopSubtotalLine.style.display;
        if (mobileSubtotalPrice && desktopSubtotalPrice) mobileSubtotalPrice.textContent = desktopSubtotalPrice.textContent;
        
        if (mobileDiscountLine && desktopDiscountLine) mobileDiscountLine.style.display = desktopDiscountLine.style.display;
        if (mobileDiscountAmount && desktopDiscountAmount) mobileDiscountAmount.textContent = desktopDiscountAmount.textContent;
        if (mobileDiscountText && desktopDiscountText) mobileDiscountText.innerHTML = desktopDiscountText.innerHTML;

        // Sync Totals & Buttons
        if (mobileTotal && desktopTotal) mobileTotal.textContent = desktopTotal.textContent;
        if (mobilePayBtn && desktopPayBtn) mobilePayBtn.disabled = desktopPayBtn.disabled;
        
        // Sync Mobile Summary Bar
        if (mobileSummaryTotal && desktopTotal) mobileSummaryTotal.textContent = desktopTotal.textContent;
        if (mobileSummaryCount && desktopCart) {
            const itemCount = desktopCart.querySelectorAll('.flex.justify-between').length;
            mobileSummaryCount.textContent = `${itemCount} Item${itemCount !== 1 ? 's' : ''}`;
        }
    });

    // Start Observing changes in the desktop cart and totals
    if (desktopCart) observer.observe(desktopCart, { childList: true, subtree: true });
    if (desktopTotal) observer.observe(desktopTotal, { characterData: true, childList: true, subtree: true });
    if (desktopSubtotalPrice) observer.observe(desktopSubtotalPrice, { characterData: true, childList: true, subtree: true });
    if (desktopDiscountAmount) observer.observe(desktopDiscountAmount, { characterData: true, childList: true, subtree: true });
    if (desktopDiscountText) observer.observe(desktopDiscountText, { characterData: true, childList: true, subtree: true });
    if (desktopPayBtn) observer.observe(desktopPayBtn, { attributes: true, attributeFilter: ['disabled'] });

    // Sync button clicks from mobile to desktop
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
                window.toggleModal('mobileCartModal');
                window.toggleModal('discountModal');
            }
        });
    }
});