// Lazy Load with Intersection Observer
document.addEventListener("DOMContentLoaded", function() {
    const lazyLoad = (targets) => {
        if ('IntersectionObserver' in window) {
            const io = new IntersectionObserver((entries, observer) => {
                entries.forEach(entry => {
                    if (entry.isIntersecting) {
                        const element = entry.target;
                        
                        if (element.dataset.src) {
                            element.src = element.dataset.src;
                        }
                        if (element.dataset.bg) {
                            element.style.backgroundImage = `url(${element.dataset.bg})`;
                        }
                        
                        element.classList.add("loaded");
                        observer.unobserve(element);
                    }
                });
            }, { threshold: 0.01, rootMargin: "200px" });
            
            targets.forEach(target => io.observe(target));
        } else {
            // Fallback for older browsers
            targets.forEach(target => {
                if (target.dataset.src) target.src = target.dataset.src;
                if (target.dataset.bg) target.style.backgroundImage = `url(${target.dataset.bg})`;
                target.classList.add("loaded");
            });
        }
    };
    
    // Initialize for all lazy elements
    lazyLoad([...document.querySelectorAll('[data-src], [data-bg]')]);
});