/* DAKPION IMC — 360° Marketing Solutions · v5
   Lightweight motion: GSAP + ScrollTrigger + SplitText (self-hosted in /assets/js/vendor).
   Native scrolling; every animation runs once on enter. Without GSAP or with reduced
   motion, content is simply shown. */
(function () {
  "use strict";

  const root = document.documentElement;
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const finePointer = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  const animate = !reduced && typeof window.gsap !== 'undefined' && typeof window.ScrollTrigger !== 'undefined';

  /* ── UI ───────────────────────────────────────── */
  function setActiveNav() {
    const page = document.body.dataset.page;
    $$('[data-nav="' + page + '"]').forEach(a => a.closest('li')?.classList.add('active'));
    const y = $('#year'); if (y) y.textContent = new Date().getFullYear();
  }

  function initHeader() {
    const header = $('.site-header');
    if (!header) return;
    let lastY = window.scrollY, ticking = false;
    const update = () => {
      const y = window.scrollY;
      header.classList.toggle('is-scrolled', y > 20);
      header.classList.toggle('is-hidden', y > 300 && y > lastY && !root.classList.contains('menu-open'));
      lastY = y; ticking = false;
    };
    update();
    window.addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, { passive: true });

    const btn = $('.menu-btn');
    const setMenu = open => {
      root.classList.toggle('menu-open', open);
      btn?.setAttribute('aria-expanded', String(open));
      const label = btn?.querySelector('span'); if (label) label.textContent = open ? 'Close' : 'Menu';
      document.body.style.overflow = open ? 'hidden' : '';
    };
    btn?.addEventListener('click', () => setMenu(!root.classList.contains('menu-open')));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') setMenu(false); });
    window.addEventListener('resize', () => { if (window.innerWidth > 980) setMenu(false); });
    $$('.menu a').forEach(a => a.addEventListener('click', () => setMenu(false)));
  }

  function initFaq() {
    $$('.faq-q').forEach(q => q.addEventListener('click', () => {
      const item = q.closest('.faq-item');
      const wasOpen = item.classList.contains('open');
      $$('.faq-item', item.parentElement).forEach(i => {
        i.classList.remove('open');
        $('.faq-q', i)?.setAttribute('aria-expanded', 'false');
      });
      if (!wasOpen) { item.classList.add('open'); q.setAttribute('aria-expanded', 'true'); }
    }));
  }

  function initFilter() {
    const bar = $('.filter');
    if (!bar) return;
    const cards = $$('[data-cat]');
    bar.addEventListener('click', e => {
      const btn = e.target.closest('button');
      if (!btn) return;
      $$('button', bar).forEach(b => {
        b.classList.toggle('active', b === btn);
        b.setAttribute('aria-pressed', String(b === btn));
      });
      const f = btn.dataset.filter;
      const shown = [];
      cards.forEach(c => {
        const on = f === 'all' || c.dataset.cat.split(' ').includes(f);
        c.style.display = on ? '' : 'none';
        if (on) shown.push(c);
      });
      if (animate) gsap.fromTo(shown, { opacity: 0, y: 20 }, { opacity: 1, y: 0, duration: .45, stagger: .04, ease: 'power2.out', clearProps: 'transform' });
      window.ScrollTrigger?.refresh();
    });
  }

  function initForms() {
    $$('form[data-form]').forEach(form => form.addEventListener('submit', e => {
      e.preventDefault();
      if (!form.checkValidity()) { form.reportValidity(); return; }
      form.reset();
      form.style.display = 'none';
      form.parentElement.querySelector('.form-success')?.classList.add('show');
    }));
  }

  function initRotator() {
    $$('.rotator').forEach(r => {
      const words = $$('span', r);
      if (words.length < 2) return;
      let i = 0;
      words[0].classList.add('is-on');
      if (reduced) return;
      setInterval(() => {
        if (document.hidden) return;
        const cur = words[i];
        i = (i + 1) % words.length;
        cur.classList.replace('is-on', 'is-out');
        words[i].classList.remove('is-out'); words[i].classList.add('is-on');
        setTimeout(() => cur.classList.remove('is-out'), 900);
      }, 2400);
    });
  }

  /* ── Motion (all one-shot, no scroll scrubbing) ─ */
  function heroIntro() {
    const title = $('.hero-title');
    if (title) {
      gsap.set([title, '.hero-foot'], { visibility: 'visible' });
      gsap.timeline({ defaults: { ease: 'power3.out' } })
        .from('.hero-title .lni', { yPercent: 110, duration: .8, stagger: .08 })
        .from('.hero-badge', { scale: 0, rotate: -120, duration: .7 }, .25)
        .from('.hero .ring', { opacity: 0, scale: .85, duration: .8 }, .1)
        .from('.hero-foot > *', { y: 16, opacity: 0, duration: .6, stagger: .06 }, .35);
    }
    const ph = $('.page-hero h1');
    if (ph) splitReveal(ph, true);
  }

  function splitReveal(el, immediate) {
    if (typeof window.SplitText === 'undefined') { gsap.set(el, { visibility: 'visible' }); return; }
    SplitText.create(el, {
      type: 'lines', mask: 'lines', linesClass: 'split-line', autoSplit: true,
      onSplit(self) {
        gsap.set(el, { visibility: 'visible' });
        return gsap.from(self.lines, {
          yPercent: 105, duration: .75, stagger: .07, ease: 'power3.out',
          scrollTrigger: immediate ? null : { trigger: el, start: 'top 90%', once: true }
        });
      }
    });
  }

  function initReveals() {
    $$('[data-split]').forEach(el => { if (!el.closest('.page-hero')) splitReveal(el, false); });

    ScrollTrigger.batch('[data-reveal]', {
      start: 'top 92%', once: true,
      onEnter: els => gsap.to(els, { opacity: 1, y: 0, duration: .6, stagger: .05, ease: 'power2.out', overwrite: true })
    });

    $$('.hl').forEach(h => gsap.fromTo(h, { '--hl': 0 }, {
      '--hl': 1, duration: .7, ease: 'power2.inOut', delay: .25,
      scrollTrigger: { trigger: h, start: 'top 90%', once: true }
    }));

    $$('[data-count]').forEach(el => {
      const o = { v: 0 }, end = parseFloat(el.dataset.count), suf = el.dataset.suffix || '';
      el.textContent = '0' + suf;
      gsap.to(o, { v: end, duration: 1.4, ease: 'power2.out', scrollTrigger: { trigger: el, start: 'top 92%', once: true },
        onUpdate: () => { el.textContent = Math.round(o.v) + suf; } });
    });

    const m = $('.manifesto');
    if (m && window.SplitText) {
      const s = SplitText.create(m, { type: 'words', wordsClass: 'w' });
      gsap.to(s.words, { opacity: 1, duration: .4, stagger: .025, ease: 'none',
        scrollTrigger: { trigger: m, start: 'top 80%', once: true } });
    } else if (m) { m.classList.add('no-split'); }

    const bar = $('.timeline .bar');
    if (bar) gsap.fromTo(bar, { '--p': 0 }, { '--p': 1, duration: 1.4, ease: 'power2.inOut',
      scrollTrigger: { trigger: '.timeline', start: 'top 85%', once: true } });

    const word = $$('.f-word span');
    if (word.length) gsap.from(word, { yPercent: 100, duration: .8, stagger: .05, ease: 'power3.out',
      scrollTrigger: { trigger: '.f-word', start: 'top 98%', once: true } });

    const progress = $('.progress');
    if (progress) gsap.to(progress, { scaleX: 1, ease: 'none', scrollTrigger: { start: 0, end: 'max', scrub: true } });
  }

  /* ── Hover niceties (desktop only, event-driven) ─ */
  function initMagnetic() {
    $$('[data-magnetic]').forEach(el => {
      const s = parseFloat(el.dataset.magnetic) || .3;
      const xT = gsap.quickTo(el, 'x', { duration: .5, ease: 'power3' });
      const yT = gsap.quickTo(el, 'y', { duration: .5, ease: 'power3' });
      el.addEventListener('mousemove', e => {
        const r = el.getBoundingClientRect();
        xT((e.clientX - r.left - r.width / 2) * s);
        yT((e.clientY - r.top - r.height / 2) * s);
      });
      el.addEventListener('mouseleave', () => { xT(0); yT(0); });
    });
  }

  function initTilt() {
    $$('[data-tilt]').forEach(el => {
      const card = $('.card', el) || el;
      const rx = gsap.quickTo(card, 'rotateX', { duration: .5, ease: 'power3' });
      const ry = gsap.quickTo(card, 'rotateY', { duration: .5, ease: 'power3' });
      el.addEventListener('mousemove', e => {
        const r = el.getBoundingClientRect();
        ry(((e.clientX - r.left) / r.width - .5) * 10);
        rx(((e.clientY - r.top) / r.height - .5) * -10);
      });
      el.addEventListener('mouseleave', () => { rx(0); ry(0); });
    });
  }

  function initWorkPreview() {
    const list = $('.wl'), pv = $('.preview');
    if (!list || !pv) return;
    const thumbs = $$('.thumb', pv);
    const xT = gsap.quickTo(pv, 'x', { duration: .4, ease: 'power3' });
    const yT = gsap.quickTo(pv, 'y', { duration: .4, ease: 'power3' });
    const place = e => { xT(e.clientX + 24); yT(e.clientY - pv.offsetHeight / 2); };
    list.addEventListener('mousemove', place);
    list.addEventListener('mouseenter', e => {
      gsap.set(pv, { x: e.clientX + 24, y: e.clientY - pv.offsetHeight / 2 });
      gsap.to(pv, { opacity: 1, scale: 1, duration: .3, ease: 'power2.out' });
    });
    list.addEventListener('mouseleave', () => gsap.to(pv, { opacity: 0, scale: .8, duration: .25 }));
    $$('.wl-row', list).forEach((row, i) => row.addEventListener('mouseenter', () => {
      thumbs.forEach((t, j) => t.classList.toggle('on', j === i));
    }));
  }

  /* ── Boot ─────────────────────────────────────── */
  function boot() {
    setActiveNav();
    initHeader();
    initFaq();
    initFilter();
    initForms();
    initRotator();
    window.__animReady = true;

    if (!animate) {
      root.classList.add('no-anim');
      $$('[data-count]').forEach(el => { el.textContent = el.dataset.count + (el.dataset.suffix || ''); });
      return;
    }

    gsap.registerPlugin(ScrollTrigger);
    if (window.SplitText) gsap.registerPlugin(SplitText);
    heroIntro();
    initReveals();
    if (finePointer) { initMagnetic(); initTilt(); initWorkPreview(); }
    if (document.fonts?.ready) document.fonts.ready.then(() => ScrollTrigger.refresh());
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
