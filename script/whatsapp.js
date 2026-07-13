// WhatsApp Module
document.addEventListener("DOMContentLoaded", function() {
    // Create WhatsApp Float Button
    const whatsappBtn = document.createElement('a');
    whatsappBtn.href = 'https://wa.me/994512055566?text=' + encodeURIComponent('Super Optik Tech xidmətləri haqqında məlumat istəyirəm');
    whatsappBtn.className = 'whatsapp-float';
    whatsappBtn.target = '_blank';
    whatsappBtn.setAttribute('aria-label', 'WhatsApp-də əlaqə');
    whatsappBtn.innerHTML = '<i class="fab fa-whatsapp"></i><span class="sr-only">WhatsApp Super Optik Tech</span>';
    
    document.body.appendChild(whatsappBtn);
    
    // Optional: WhatsApp Chat Widget
    const initWhatsAppWidget = () => {
        const widgetHTML = `
            <div class="whatsapp-widget">
                <div class="whatsapp-widget-header">
                    <span>WhatsApp-də bizimlə əlaqə</span>
                    <span class="whatsapp-close">&times;</span>
                </div>
                <div class="whatsapp-widget-body">
                    <p>Xidmətlərimiz haqqında məlumat almaq üçün yazın!</p>
                    <a href="https://wa.me/994512055566" target="_blank" class="whatsapp-chat-btn">Chat aç</a>
                </div>
            </div>
        `;
        
        document.body.insertAdjacentHTML('beforeend', widgetHTML);
        
        const widget = document.querySelector('.whatsapp-widget');
        const closeBtn = document.querySelector('.whatsapp-close');
        
        whatsappBtn.addEventListener('click', (e) => {
            e.preventDefault();
            widget.style.display = widget.style.display === 'block' ? 'none' : 'block';
        });
        
        closeBtn.addEventListener('click', () => {
            widget.style.display = 'none';
        });
    };
    
    // Uncomment to enable widget
    // initWhatsAppWidget();
});

// WhatsApp Click Tracking
function trackWhatsAppClick(source) {
    if (typeof gtag !== 'undefined') {
        gtag('event', 'whatsapp_click', {
            'event_category': 'engagement',
            'event_label': source
        });
    }
    console.log('WhatsApp click tracked:', source);
}