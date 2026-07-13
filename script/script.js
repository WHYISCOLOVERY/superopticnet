document.addEventListener("DOMContentLoaded", function () {
    const menuBtn = document.querySelector(".tb__right");
    const offCanvas = document.querySelector(".offCanvas");
    const closeBtn = document.querySelector(".closeBtn");
    const problemBtn = document.getElementById("problemImagine");
    const problemModal = document.querySelector(".problemImagine");
    const problemClose = document.querySelector(".problemClose");

    const sectionTitles = {
        "#HomePG": "ANA SƏHİFƏ",
        "#TariffPG": "TARİFLƏR",
        "#ServicePG": "XİDMƏTLƏR",
        "#AboutPG": "HAQQIMIZDA",
        "#MapPG": "ÜNVANLARIMIZ",
        "#SupportPG": "DƏSTƏK"
    };

    const navItems = document.querySelectorAll(".ocRi");

    function safeDisableScroll() {
        if (typeof fullpage_api !== "undefined") {
            fullpage_api.setAllowScrolling(false);
        }
    }

    function safeEnableScroll() {
        if (typeof fullpage_api !== "undefined") {
            fullpage_api.setAllowScrolling(true);
        }
    }

    function updateTopButtonTitle() {
        const hash = window.location.hash || "#HomePG";
        if (menuBtn) {
            menuBtn.textContent = sectionTitles[hash] || "ANA SƏHİFƏ";
        }

        navItems.forEach(item => item.classList.remove("pageName"));
        navItems.forEach(item => {
            const href = item.getAttribute("href");
            if (href === hash) {
                item.classList.add("pageName");
            }
        });
    }

    if (menuBtn && offCanvas) {
        menuBtn.addEventListener("click", function () {
            offCanvas.style.right = "0";
            safeDisableScroll();
        });
    }

    if (closeBtn && offCanvas) {
        closeBtn.addEventListener("click", function () {
            offCanvas.style.right = "-100vw";
            safeEnableScroll();
        });
    }

    navItems.forEach(item => {
        item.addEventListener("click", function () {
            if (offCanvas) offCanvas.style.right = "-100vw";
            safeEnableScroll();
        });
    });

    if (problemBtn && problemModal) {
        problemBtn.addEventListener("click", function () {
            problemModal.style.display = "flex";
            safeDisableScroll();
        });
    }

    if (problemClose && problemModal) {
        problemClose.addEventListener("click", function () {
            problemModal.style.display = "none";
            safeEnableScroll();
        });
    }

    const serviceBoxes = document.querySelectorAll(".serviceBox");
    const serviceDescriptions = document.querySelectorAll(".serviceBoxDescript");
    const closeServiceBoxes = document.querySelectorAll(".closeSBD");

    serviceBoxes.forEach((box, i) => {
        box.addEventListener("click", function () {
            if (serviceDescriptions[i]) {
                serviceDescriptions[i].style.display = "flex";
                setTimeout(() => {
                    serviceDescriptions[i].style.opacity = "1";
                }, 10);
            }
        });
    });

    closeServiceBoxes.forEach((btn, i) => {
        btn.addEventListener("click", function () {
            if (serviceDescriptions[i]) {
                serviceDescriptions[i].style.opacity = "0";
                setTimeout(() => {
                    serviceDescriptions[i].style.display = "none";
                }, 300);
            }
        });
    });

    const aboutBoxOpen = document.querySelectorAll(".textContent");
    const aboutBox = document.querySelectorAll(".aboutOverlay");
    const aboutBoxClose = document.querySelectorAll(".closeAboutBox");

    aboutBoxOpen.forEach((item, i) => {
        item.addEventListener("click", function () {
            if (aboutBox[i]) {
                aboutBox[i].style.display = "flex";
                setTimeout(() => {
                    aboutBox[i].style.opacity = "1";
                    aboutBox[i].style.transform = "scale(1)";
                }, 10);
            }
        });
    });

    aboutBoxClose.forEach((item, i) => {
        item.addEventListener("click", function () {
            if (aboutBox[i]) {
                aboutBox[i].style.opacity = "0";
                aboutBox[i].style.transform = "scale(0.95)";
                setTimeout(() => {
                    aboutBox[i].style.display = "none";
                }, 300);
            }
        });
    });

    if (document.querySelector(".swiper")) {
        new Swiper(".swiper", {
            effect: "coverflow",
            grabCursor: true,
            centeredSlides: true,
            slidesPerView: "auto",
            spaceBetween: 40,
            loop: true,
            coverflowEffect: {
                rotate: 0,
                stretch: 0,
                depth: 100,
                modifier: 2,
                slideShadows: true
            },
            pagination: {
                el: ".swiper-pagination",
                clickable: true
            }
        });
    }

    const myDots = document.querySelectorAll(".dot");
    const swipeDots = document.querySelectorAll(".swiper-pagination-bullet");

    myDots.forEach((dot, i) => {
        dot.addEventListener("click", function () {
            if (swipeDots[i]) swipeDots[i].click();
        });
    });

    updateTopButtonTitle();
    window.addEventListener("hashchange", updateTopButtonTitle);
});