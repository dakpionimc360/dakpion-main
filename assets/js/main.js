/* DAKPION IMC — 360° Marketing Solutions
   Site logic: partials, navigation, reveals, counters, FAQ, filters, forms */
(function () {
  "use strict";

  const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ── Partial includes ─────────────────────────── */
  function includePartials(done) {
    const nodes = document.querySelectorAll('[data-include]');
    if (!nodes.length) { done(); return; }
    let remaining = nodes.length;
    nodes.forEach(el => {
      fetch(el.getAttribute('data-include'))
        .then(r => r.text())
        .then(html => { el.outerHTML = html; })
        .catch(() => { el.remove(); })
        .finally(() => { if (--remaining === 0) done(); });
    });
  }

  /* ── Active nav ───────────────────────────────── */
  function setActiveNav() {
    const page = document.body.getAttribute('data-page');
    if (!page) return;
    document.querySelectorAll('[data-nav="' + page + '"]').forEach(a => {
      a.closest('li')?.classList.add('active');
    });
  }

  /* ── Header ───────────────────────────────────── */
  function initHeader() {
    const header = document.querySelector('.site-header');
    if (!header) return;
    const onScroll = () => header.classList.toggle('is-scrolled', window.scrollY > 10);
    onScroll();
    window.addEventListener('scroll', onScroll, { passive: true });

    const toggle = document.querySelector('.nav-toggle');
    const mobile = document.querySelector('.nav-mobile');
    if (!toggle || !mobile) return;

    const setOpen = open => {
      mobile.classList.toggle('is-open', open);
      toggle.classList.toggle('is-active', open);
      toggle.setAttribute('aria-expanded', String(open));
      document.body.style.overflow = open ? 'hidden' : '';
    };
    toggle.addEventListener('click', () => setOpen(!mobile.classList.contains('is-open')));
    window.addEventListener('resize', () => { if (window.innerWidth > 980) setOpen(false); });
    document.addEventListener('keydown', e => { if (e.key === 'Escape') setOpen(false); });

    mobile.querySelectorAll('.has-sub > a').forEach(a => {
      a.addEventListener('click', e => {
        e.preventDefault();
        a.closest('li').classList.toggle('is-open');
      });
    });
    mobile.querySelectorAll('.sub a, li:not(.has-sub) > a').forEach(a => {
      a.addEventListener('click', () => setOpen(false));
    });
  }

  /* ── Counters ─────────────────────────────────── */
  function animateCounter(el) {
    const target = parseFloat(el.getAttribute('data-count'));
    const suffix = el.getAttribute('data-suffix') || '';
    if (prefersReduced) { el.textContent = target + suffix; return; }
    const dur = 1600;
    const start = performance.now();
    (function tick(now) {
      const p = Math.min(1, (now - start) / dur);
      el.textContent = Math.round(target * (1 - Math.pow(1 - p, 3))) + suffix;
      if (p < 1) requestAnimationFrame(tick);
    })(start);
  }

  /* ── Reveal on scroll ─────────────────────────── */
  function initReveal() {
    const els = document.querySelectorAll('.reveal');
    const counters = document.querySelectorAll('[data-count]');
    if (!('IntersectionObserver' in window)) {
      els.forEach(el => el.classList.add('in'));
      counters.forEach(animateCounter);
      return;
    }
    const io = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        entry.target.classList.add('in');
        io.unobserve(entry.target);
      });
    }, { threshold: .12, rootMargin: '0px 0px -40px 0px' });
    els.forEach(el => io.observe(el));

    const co = new IntersectionObserver(entries => {
      entries.forEach(entry => {
        if (!entry.isIntersecting) return;
        animateCounter(entry.target);
        co.unobserve(entry.target);
      });
    }, { threshold: .6 });
    counters.forEach(el => co.observe(el));
  }

  /* ── FAQ ──────────────────────────────────────── */
  function initFaq() {
    document.querySelectorAll('.faq-q').forEach(q => {
      q.addEventListener('click', () => {
        const item = q.closest('.faq-item');
        const wasOpen = item.classList.contains('open');
        item.parentElement.querySelectorAll('.faq-item').forEach(i => {
          i.classList.remove('open');
          i.querySelector('.faq-q')?.setAttribute('aria-expanded', 'false');
        });
        if (!wasOpen) {
          item.classList.add('open');
          q.setAttribute('aria-expanded', 'true');
        }
      });
    });
  }

  /* ── Work filter ──────────────────────────────── */
  function initFilter() {
    const bar = document.querySelector('.filter-bar');
    if (!bar) return;
    const cards = document.querySelectorAll('[data-cat]');
    bar.querySelectorAll('button').forEach(btn => {
      btn.addEventListener('click', () => {
        bar.querySelectorAll('button').forEach(b => {
          b.classList.toggle('active', b === btn);
          b.setAttribute('aria-pressed', String(b === btn));
        });
        const filter = btn.getAttribute('data-filter');
        cards.forEach(card => {
          const cats = card.getAttribute('data-cat').split(' ');
          card.style.display = filter === 'all' || cats.includes(filter) ? '' : 'none';
        });
      });
    });
  }

  /* ── Forms ────────────────────────────────────── */
  function initForms() {
    document.querySelectorAll('form[data-form]').forEach(form => {
      form.addEventListener('submit', e => {
        e.preventDefault();
        if (!form.checkValidity()) { form.reportValidity(); return; }
        const success = form.parentElement.querySelector('.form-success');
        form.reset();
        form.style.display = 'none';
        success?.classList.add('show');
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
      initReveal();
      initFaq();
      initFilter();
      initForms();
    });
  });
})();
