document.addEventListener('DOMContentLoaded', function () {

    /* ==========================================
       1. DIAPORAMA — 6 slides (1 intro + 5 images)
       ========================================== */
    const slides  = document.querySelectorAll('.slide');
    const dots    = document.querySelectorAll('.sdot');
    const prevBtn = document.getElementById('prevSlide');
    const nextBtn = document.getElementById('nextSlide');
    let current   = 0;
    let autoTimer = null;
    const INTERVAL = 5000;

    function goToSlide(index) {
        slides[current].classList.remove('active', 'tilt');
        slides[current].style.transform = '';
        dots[current].classList.remove('active');

        current = (index + slides.length) % slides.length;

        slides[current].classList.add('active');
        dots[current].classList.add('active');
    }

    function startAuto() {
        stopAuto();
        autoTimer = setInterval(function () { goToSlide(current + 1); }, INTERVAL);
    }

    function stopAuto() {
        if (autoTimer) clearInterval(autoTimer);
    }

    nextBtn.addEventListener('click', function () { goToSlide(current + 1); stopAuto(); startAuto(); });
    prevBtn.addEventListener('click', function () { goToSlide(current - 1); stopAuto(); startAuto(); });

    dots.forEach(function (dot) {
        dot.addEventListener('click', function () {
            goToSlide(parseInt(dot.dataset.index));
            stopAuto(); startAuto();
        });
    });

    startAuto();


    /* ==========================================
       2. EFFET PARALLAX SOURIS (slides images uniquement)
       ========================================== */
    const hero = document.getElementById('hero');

    hero.addEventListener('mousemove', function (e) {
        const activeSlide = slides[current];
        // Pas d'effet sur la slide intro (fond dégradé)
        if (activeSlide.classList.contains('slide-intro')) return;

        const rect = hero.getBoundingClientRect();
        const dx   = (e.clientX - rect.left  - rect.width  / 2) / (rect.width  / 2);
        const dy   = (e.clientY - rect.top   - rect.height / 2) / (rect.height / 2);
        const tx   = dx * 14;
        const ty   = dy * 10;

        activeSlide.classList.add('tilt');
        activeSlide.style.transform = 'scale(1.06) translate(' + tx + 'px, ' + ty + 'px)';
    });

    hero.addEventListener('mouseleave', function () {
        const activeSlide = slides[current];
        activeSlide.classList.remove('tilt');
        activeSlide.style.transform = '';
    });


    /* ==========================================
       3. BURGER MENU
       ========================================== */
    const burger   = document.getElementById('burger');
    const navLinks = document.getElementById('navLinks');

    burger.addEventListener('click', function () {
        burger.classList.toggle('open');
        navLinks.classList.toggle('open');
    });

    navLinks.querySelectorAll('a').forEach(function (link) {
        link.addEventListener('click', function () {
            burger.classList.remove('open');
            navLinks.classList.remove('open');
        });
    });


    /* ==========================================
       4. NAVBAR ombre au scroll
       ========================================== */
    const navbar = document.getElementById('navbar');
    window.addEventListener('scroll', function () {
        navbar.classList.toggle('scrolled', window.scrollY > 10);
    });

});