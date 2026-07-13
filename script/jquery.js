let myHash = "";
$(".tb__right").click(function () {
    $(".offCanvas").animate({ right: '0' });
    myHash = location.hash;
    fullpage_api.setAllowScrolling(false);
})

$(".closeBtn").click(function () {
    $(".offCanvas").animate({ right: '-100vw' });
    location.hash = myHash;
    fullpage_api.setAllowScrolling(true);
})

$(".ocRi").click(function () {
    $(".offCanvas").animate({ right: '-100vw' });
    fullpage_api.setAllowScrolling(true);
})

$("#problemImagine").click(function () {
    $(".problemImagine").fadeIn();
    $(".problemImagine").css("display", "flex");
    fullpage_api.setAllowScrolling(false);
})

$(".problemClose").click(function () {
    $(".problemImagine").fadeOut();
    fullpage_api.setAllowScrolling(true);
})

$(document).ready(function () {
    $(".loading-area").fadeOut("slow");
    $('.center-slider').slick({
        slidesToShow: 5,
        swipeToSlide: true,
        centerMode: true,
        arrows: false,
        dots: true,
        infinite: true,
        speed: 400,
        centerPadding: '0px',
        responsive: [
            {
                breakpoint: 1000,
                settings: {
                    slidesToShow: 3,
                }
            }, {
                breakpoint: 582,
                settings: {
                    slidesToShow: 1,
                    centerPadding: '10px',
                }
            }
        ]
    });
});

$(document).ready(function () {
    if ($(window).width() < 991) {
        $(".inputsBox .nav-link").removeClass("active");
        $(".inputsBox .nav-link").click(function(){
            $(".inputsBox").addClass("myActive");
            $(".inputsBox form").fadeIn();
        })
    }
});
