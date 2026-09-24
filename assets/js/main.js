/* DAKPION IMC — 360° Marketing Solutions · v8 (Bento)
   Tiny vanilla JS: mobile menu, tile reveals, counters, FAQ, work filter, forms. */
(function () {
  "use strict";

  const root = document.documentElement;
  const $ = (s, c = document) => c.querySelector(s);
  const $$ = (s, c = document) => Array.from(c.querySelectorAll(s));
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  function setActiveNav() {
    const page = document.body.dataset.page;
    $$('[data-nav="' + page + '"]').forEach(a => a.closest('li')?.classList.add('active'));
    const y = $('#year'); if (y) y.textContent = new Date().getFullYear();
  }

  function initMenu() {
    const btn = $('.menu-btn');
    if (!btn) return;
    const setMenu = open => {
      root.classList.toggle('menu-open', open);
      btn.setAttribute('aria-expanded', String(open));
    };
    btn.addEventListener('click', () => setMenu(!root.classList.contains('menu-open')));
    document.addEventListener('keydown', e => { if (e.key === 'Escape') setMenu(false); });
    document.addEventListener('click', e => { if (!e.target.closest('.site-header')) setMenu(false); });
    window.addEventListener('resize', () => { if (window.innerWidth > 900) setMenu(false); });
    $$('.mobile-nav a').forEach(a => a.addEventListener('click', () => setMenu(false)));
  }

  function countUp(el) {
    const end = parseFloat(el.dataset.count), suf = el.dataset.suffix || '';
    if (reduced) { el.textContent = end + suf; return; }
    const t0 = performance.now(), dur = 1200;
    (function tick(t) {
      const p = Math.min(1, (t - t0) / dur);
      el.textContent = Math.round(end * (1 - Math.pow(1 - p, 3))) + suf;
      if (p < 1) requestAnimationFrame(tick);
    })(t0);
  }

  /* Stagger tiles within each grid so they rise one after another */
  function initReveal() {
    $$('.bento').forEach(grid => {
      let k = 0;
      $$(':scope > .reveal', grid).forEach(t => t.style.setProperty('--d', (k++ % 6) * 0.06 + 's'));
    });
    const els = $$('.reveal, .mini-bars'), counters = $$('[data-count]');
    if (reduced || !('IntersectionObserver' in window)) {
      els.forEach(e => e.classList.add('in'));
      counters.forEach(countUp);
      return;
    }
    const io = new IntersectionObserver(entries => entries.forEach(en => {
      if (!en.isIntersecting) return;
      en.target.classList.add('in');
      if (en.target.dataset.count) countUp(en.target);
      io.unobserve(en.target);
    }), { rootMargin: '0px 0px -8% 0px', threshold: .12 });
    els.forEach(e => io.observe(e));
    counters.forEach(e => io.observe(e));
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
      cards.forEach(c => { c.style.display = f === 'all' || c.dataset.cat.split(' ').includes(f) ? '' : 'none'; });
    });
  }

  function initForms() {
    $$('form[data-form]').forEach(form => form.addEventListener('submit', e => {
      e.preventDefault();
      if (!form.checkValidity()) { form.reportValidity(); return; }
      // TODO: connect to a form backend (e.g. Formspree, Google Sheets or a CRM webhook).
      form.reset();
      form.hidden = true;
      form.parentElement.querySelector('.form-success')?.classList.add('show');
    }));
  }

  function boot() {
    setActiveNav();
    initMenu();
    initReveal();
    initFaq();
    initFilter();
    initForms();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
  else boot();
})();
