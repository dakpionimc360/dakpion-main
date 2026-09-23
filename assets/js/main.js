/* DAKPION IMC — Premium main.js v2
   GSAP + ScrollTrigger animations + site logic */
(function () {
  "use strict";

  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ── Partial includes ─────────────────────────── */
  function includePartials(done) {
    const nodes = document.querySelectorAll('[data-include]');
    if (!nodes.length) { done(); return; }
    let remaining = nodes.length;
    nodes.forEach(el => {
      const url = el.getAttribute('data-include');
      fetch(url).then(r => r.text()).then(html => {
        el.innerHTML = html;
      }).catch(() => {
        el.innerHTML = '';
      }).finally(() => {
        if (--remaining === 0) done();
      });
    });
  }

  /* ── Active nav ───────────────────────────────── */
  function setActiveNav() {
    const page = document.body.getAttribute('data-page');
    if (!page) return;
    document.querySelectorAll('[data-nav]').forEach(a => {
      if (a.getAttribute('data-nav') === page) {
        a.closest('li')?.classList.add('active');
      }
    });
  }

  /* ── Header scroll behavior ───────────────────── */
  function initHeader() {
    const header = document.querySelector('.site-header');
    if (!header) return;
    const onScroll = () => header.classList.toggle('is-scrolled', window.scrollY > 20);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    const toggle = document.querySelector('.nav-toggle');
    const mobile = document.querySelector('.nav-mobile');
    const scrim = document.querySelector('.nav-scrim');

    if (!toggle || !mobile) return;
    const close = () => {
      toggle.classList.remove('is-active');
      mobile.classList.remove('is-open');
      scrim?.classList.remove('is-open');
      document.body.style.overflow = '';
    };
    toggle.addEventListener('click', () => {
      const isOpen = mobile.classList.toggle('is-open');
      toggle.classList.toggle('is-active', isOpen);
      scrim?.classList.toggle('is-open', isOpen);
      document.body.style.overflow = isOpen ? 'hidden' : '';
    });
    scrim?.addEventListener('click', close);
    window.addEventListener('resize', () => { if (window.innerWidth > 980) close(); });
    mobile.querySelectorAll('.has-dropdown > a').forEach(a => {
      a.addEventListener('click', e => {
        e.preventDefault();
        a.closest('li').classList.toggle('is-open');
      });
    });
  }

  /* ── Counter animation ────────────────────────── */
  function animateCounter(el) {
    const target = parseFloat(el.getAttribute('data-count'));
    const suffix = el.getAttribute('data-suffix') || '';
    const dur = 1600;
    const start = performance.now();
    function tick(now) {
      const p = Math.min(1, (now - start) / dur);
      const eased = 1 - Math.pow(1 - p, 3);
      el.textContent = Math.round(target * eased) + suffix;
      if (p < 1) requestAnimationFrame(tick);
    }
    requestAnimationFrame(tick);
  }

  /* ── FAQ accordion ────────────────────────────── */
  function initFaq() {
    document.querySelectorAll('.faq-q').forEach(q => {
      q.addEventListener('click', () => {
        const item = q.closest('.faq-item');
        const wasOpen = item.classList.contains('open');
        item.parentElement.querySelectorAll('.faq-item').forEach(i => i.classList.remove('open'));
        if (!wasOpen) item.classList.add('open');
      });
    });
  }

  /* ── Portfolio filter ─────────────────────────── */
  function initFilter() {
    const bar = document.querySelector('.filter-bar');
    if (!bar) return;
    const cards = document.querySelectorAll('[data-cat]');
    bar.querySelectorAll('button').forEach(btn => {
      btn.addEventListener('click', () => {
        bar.querySelectorAll('button').forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const filter = btn.getAttribute('data-filter');
        cards.forEach(card => {
          const show = filter === 'all' || card.getAttribute('data-cat') === filter;
          card.style.display = show ? '' : 'none';
        });
      });
    });
  }

  /* ── Forms ────────────────────────────────────── */
  function initForms() {
    document.querySelectorAll('form[data-form]').forEach(form => {
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        if (!form.checkValidity()) { form.reportValidity(); return; }
        const successBox = form.parentElement.querySelector('.form-success')
          || form.querySelector('.form-success');
        form.reset();
        form.style.display = 'none';
        if (successBox) successBox.classList.add('show');
      });
    });
  }

  /* ── Fallback reveal (no GSAP) ────────────────── */
  function initFallbackReveal() {
    const els = document.querySelectorAll('.js-reveal, .js-reveal-up, .js-reveal-scale, .js-reveal-left, .js-reveal-right');
    if (!('IntersectionObserver' in window)) {
      els.forEach(el => el.style.cssText = 'opacity:1;transform:none;');
      return;
    }
    const io = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.style.cssText = 'opacity:1;transform:none;transition:opacity .7s ease,transform .7s ease;';
          io.unobserve(entry.target);
        }
      });
    }, { threshold: .12, rootMargin: '0px 0px -40px 0px' });
    els.forEach(el => io.observe(el));
  }

  /* ── GSAP Animations ──────────────────────────── */
  function initGSAP() {
    if (typeof gsap === 'undefined') {
      initFallbackReveal();
      // still run counters
      document.querySelectorAll('[data-count]').forEach(el => {
        const io = new IntersectionObserver(entries => {
          if (entries[0].isIntersecting) { animateCounter(el); io.disconnect(); }
        }, { threshold: .5 });
        io.observe(el);
      });
      return;
    }

    gsap.registerPlugin(ScrollTrigger);

    /* Global defaults */
    gsap.defaults({ ease: 'power3.out', duration: 0.9 });

    /* ── Hero page-load sequence ── */
    if (document.querySelector('.hero')) {
      /* Use .to() so GSAP animates TO visible states */
      const heroTl = gsap.timeline({ delay: 0.15 });
      heroTl
        .to('.hero-label', { opacity: 1, y: 0, duration: 0.7 })
        .to('.title-inner', { y: '0%', duration: 0.8, stagger: 0.12, ease: 'power4.out' }, '-=0.4')
        .to('.hero-desc', { opacity: 1, y: 0, duration: 0.8 }, '-=0.5')
        .to('.hero-actions', { opacity: 1, y: 0, duration: 0.7 }, '-=0.55')
        .to('.hero-stats', { opacity: 1, y: 0, duration: 0.6 }, '-=0.5')
        .to('.hero-visual', {
          opacity: 1, y: 0, scale: 1, duration: 1.0, ease: 'power2.out'
        }, '-=0.75')
        .to('.hero-badge', {
          opacity: 1, y: 0, scale: 1, duration: 0.7, stagger: 0.2, ease: 'back.out(1.4)'
        }, '-=0.5');

      /* Hero parallax on scroll */
      gsap.to('.hero-visual', {
        y: -50,
        ease: 'none',
        scrollTrigger: {
          trigger: '.hero',
          start: 'top top',
          end: 'bottom top',
          scrub: 1.5
        }
      });
      gsap.to('.hero-bg-text', {
        y: 80,
        ease: 'none',
        scrollTrigger: {
          trigger: '.hero',
          start: 'top top',
          end: 'bottom top',
          scrub: 1
        }
      });

      /* Animate hero counters once visible */
      document.querySelectorAll('[data-count]').forEach(el => {
        const io = new IntersectionObserver(entries => {
          if (entries[0].isIntersecting) { animateCounter(el); io.disconnect(); }
        }, { threshold: .6 });
        io.observe(el);
      });
    }

    /* ── Logo strip ── */
    gsap.from('.logo-strip', {
      opacity: 0, y: 20,
      duration: 0.8,
      scrollTrigger: { trigger: '.logo-strip', start: 'top 88%' }
    });

    /* ── Services Bento ── */
    gsap.utils.toArray('.bento-card').forEach((card, i) => {
      gsap.from(card, {
        y: 50, opacity: 0, scale: 0.96,
        duration: 0.8,
        delay: (i % 4) * 0.07,
        ease: 'power2.out',
        scrollTrigger: { trigger: card, start: 'top 88%', once: true }
      });
    });

    /* ── Section heads ── */
    document.querySelectorAll('.section-head').forEach(head => {
      const tl = gsap.timeline({
        scrollTrigger: { trigger: head, start: 'top 85%', once: true }
      });
      tl.from(head.querySelector('.eyebrow'), { y: 16, opacity: 0, duration: 0.6 })
        .from(head.querySelector('h2'), { y: 24, opacity: 0, duration: 0.8 }, '-=0.4')
        .from(head.querySelector('p'), { y: 16, opacity: 0, duration: 0.7 }, '-=0.5');
    });

    /* ── Feature section ── */
    if (document.querySelector('.feature-section')) {
      const ftl = gsap.timeline({
        scrollTrigger: { trigger: '.feature-section', start: 'top 70%', once: true }
      });
      ftl
        .from('.feature-card', { x: -50, opacity: 0, scale: 0.95, duration: 1.0, ease: 'power2.out' })
        .from('.feature-floating', { x: -30, opacity: 0, duration: 0.7, ease: 'back.out(1.4)' }, '-=0.4')
        .from('.feature-content .eyebrow', { y: 16, opacity: 0, duration: 0.6 }, '-=0.8')
        .from('.feature-content h2', { y: 24, opacity: 0, duration: 0.8 }, '-=0.55')
        .from('.feature-content p', { y: 16, opacity: 0, duration: 0.7 }, '-=0.5')
        .from('.feature-item', { y: 16, opacity: 0, duration: 0.55, stagger: 0.1 }, '-=0.4')
        .from('.feature-content .btn', { y: 12, opacity: 0, duration: 0.6, ease: 'back.out(1.4)' }, '-=0.2');
    }

    /* ── Process steps ── */
    gsap.utils.toArray('.process-step').forEach((step, i) => {
      gsap.from(step, {
        x: 30, opacity: 0, duration: 0.7,
        delay: i * 0.1,
        ease: 'power2.out',
        scrollTrigger: { trigger: step, start: 'top 88%', once: true }
      });
    });
    if (document.querySelector('.process-intro')) {
      gsap.from('.process-intro', {
        y: 30, opacity: 0, duration: 0.9,
        scrollTrigger: { trigger: '.process-intro', start: 'top 80%', once: true }
      });
    }

    /* ── Work cards ── */
    gsap.utils.toArray('.work-card').forEach((card, i) => {
      gsap.from(card, {
        y: 40, opacity: 0, duration: 0.75,
        delay: (i % 3) * 0.1,
        ease: 'power2.out',
        scrollTrigger: { trigger: card, start: 'top 88%', once: true }
      });
    });

    /* ── Results ── */
    gsap.utils.toArray('.result-card').forEach((card, i) => {
      gsap.from(card, {
        y: 40, opacity: 0, duration: 0.8,
        delay: i * 0.12,
        scrollTrigger: { trigger: card, start: 'top 85%', once: true }
      });
    });

    /* ── Testimonials ── */
    gsap.utils.toArray('.testi-card').forEach((card, i) => {
      gsap.from(card, {
        y: 40, opacity: 0, scale: 0.97, duration: 0.8,
        delay: i * 0.1,
        ease: 'power2.out',
        scrollTrigger: { trigger: card, start: 'top 88%', once: true }
      });
    });

    /* ── Blog cards ── */
    gsap.utils.toArray('.blog-card').forEach((card, i) => {
      gsap.from(card, {
        y: 40, opacity: 0, duration: 0.75,
        delay: i * 0.1,
        scrollTrigger: { trigger: card, start: 'top 88%', once: true }
      });
    });

    /* ── CTA band ── */
    if (document.querySelector('.cta-band')) {
      gsap.from('.cta-band', {
        y: 40, opacity: 0, scale: 0.97, duration: 1.0,
        ease: 'power2.out',
        scrollTrigger: { trigger: '.cta-band', start: 'top 85%', once: true }
      });
    }

    /* ── Any generic .js-reveal elements ── */
    gsap.utils.toArray('.js-reveal').forEach(el => {
      gsap.to(el, {
        opacity: 1, duration: 0.8,
        scrollTrigger: { trigger: el, start: 'top 88%', once: true }
      });
    });
    gsap.utils.toArray('.js-reveal-up').forEach(el => {
      gsap.to(el, {
        y: 0, opacity: 1, duration: 0.8, ease: 'power2.out',
        scrollTrigger: { trigger: el, start: 'top 88%', once: true }
      });
    });
  }

  /* ── Magnetic CTA effect (desktop only) ──────── */
  function initMagnetic() {
    if (window.innerWidth < 980) return;
    document.querySelectorAll('.btn--primary, .btn--dark').forEach(btn => {
      btn.addEventListener('mousemove', e => {
        const r = btn.getBoundingClientRect();
        const dx = e.clientX - (r.left + r.width / 2);
        const dy = e.clientY - (r.top + r.height / 2);
        btn.style.transform = `translate(${dx * 0.18}px, ${dy * 0.18}px) translateY(-2px)`;
      });
      btn.addEventListener('mouseleave', () => {
        btn.style.transform = '';
      });
    });
  }

  /* ── Boot ─────────────────────────────────────── */
  document.addEventListener('DOMContentLoaded', () => {
    includePartials(() => {
      const yearEl = document.getElementById('year');
      if (yearEl) yearEl.textContent = new Date().getFullYear();

      setActiveNav();
      initHeader();
      initFaq();
      initFilter();
      initForms();

      if (!prefersReduced) {
        initGSAP();
        initMagnetic();
      } else {
        /* Expose all animated elements immediately */
        document.querySelectorAll(
          '.js-reveal,.js-reveal-up,.js-reveal-scale,.js-reveal-left,.js-reveal-right'
        ).forEach(el => {
          el.style.opacity = '1';
          el.style.transform = 'none';
        });
        document.querySelectorAll('[data-count]').forEach(el => {
          el.textContent = el.getAttribute('data-count') + (el.getAttribute('data-suffix') || '');
        });
      }
    });
  });
})();
