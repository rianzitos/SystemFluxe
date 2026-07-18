gsap.registerPlugin(ScrollTrigger);

const lenis = new Lenis({
    duration: 1.2,
    smoothWheel: true,
});

lenis.on('scroll', ScrollTrigger.update);

gsap.ticker.add((time) => {
    lenis.raf(time * 1000);
});

gsap.ticker.lagSmoothing(0);

let lastScrollY = window.scrollY;

window.addEventListener('scroll', () => {
    const header = document.querySelector('.containerHeader');
    const currentScrollY = window.scrollY;

    if (currentScrollY > 100) {
        header.classList.add('header-fixed');
    }

    if (currentScrollY < lastScrollY) {
        header.classList.add('header-visible');
    } else {
        header.classList.remove('header-visible');
    }

    if (currentScrollY <= 100) {
        header.classList.remove('header-fixed');
        header.classList.remove('header-visible');
    }

    lastScrollY = currentScrollY;
});
