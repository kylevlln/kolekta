/* ==========================================================================
   KOLEKTA — SIDEBAR COMPONENT
   Class toggling only (no layout logic in JS):
      .nav-open    on .sb-layout  → mobile drawer + scrim
      .is-collapsed on .sb        → desktop icon-only rail (persisted)
      .is-peeking  on .sb         → dwell-hover/focus peek over the content
   Plus the floating icon-only tooltip (.sb-tip on <body>).
   ========================================================================== */
(function () {
  var PERSIST_KEY = 'kolekta:sidebar:collapsed';
  var PEEK_DWELL = 400; /* ms hovering the icon-only rail before it peeks  */
  var TIP_DELAY = 120;   /* ms over an icon before its tooltip appears     */

  function init() {
    var layout = document.querySelector('.sb-layout');
    if (!layout) return;
    var panel = layout.querySelector('.sb');
    var burger = layout.querySelector('.sb-burger');
    var collapse = layout.querySelector('.sb-collapse');
    var scrim = layout.querySelector('.sb-scrim');
    if (!panel) return;

    var mqFine = window.matchMedia('(hover:hover)');
    var mqDrawer = window.matchMedia('(max-width:860px)');
    var mqDesktop = window.matchMedia('(min-width:1025px)');
    var mqAuto = window.matchMedia('(min-width:861px) and (max-width:1024px)');

    var tip = null, tipTimer = null, tipTarget = null, tipPending = null;
    var peekTimer = null;

    /* ------------------------------------------------------- state helpers */
    function iconOnly() {
      if (mqDrawer.matches) return false;
      if (mqDesktop.matches) return panel.classList.contains('is-collapsed');
      return mqAuto.matches;
    }
    function canPeek() {
      return mqFine.matches && !mqDrawer.matches && iconOnly();
    }

    function setDrawer(open) {
      layout.classList.toggle('nav-open', open);
      document.body.classList.toggle('nav-locked', open);
      if (burger) {
        burger.setAttribute('aria-expanded', String(open));
        burger.setAttribute('aria-label', open ? 'Close navigation' : 'Open navigation');
      }
      if (open) {
        var first = panel.querySelector('.sb-nav a');
        if (first) first.focus();
      } else if (burger && panel.contains(document.activeElement)) {
        burger.focus();
      }
    }

    function setCollapsed(collapsed, persist) {
      panel.classList.toggle('is-collapsed', collapsed);
      if (!collapsed) { cancelPeek(); closePeek(); }
      if (collapse) {
        collapse.setAttribute('aria-expanded', String(!collapsed));
        collapse.setAttribute('aria-label', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
        collapse.setAttribute('title', collapsed ? 'Expand sidebar' : 'Collapse sidebar');
      }
      if (persist) {
        try { localStorage.setItem(PERSIST_KEY, collapsed ? '1' : '0'); } catch (e) {}
      }
      hideTip();
    }

    /* ------------------------------------------------------------- peeking */
    function cancelPeek() {
      if (peekTimer) { clearTimeout(peekTimer); peekTimer = null; }
    }
    function openPeek() {
      cancelPeek();
      if (!canPeek()) return;
      panel.classList.add('is-peeking');
      hideTip();
    }
    function closePeek() { panel.classList.remove('is-peeking'); }

    panel.addEventListener('mouseenter', function () {
      cancelPeek();
      if (!canPeek()) return;
      peekTimer = setTimeout(openPeek, PEEK_DWELL);
    });
    panel.addEventListener('mouseleave', function () {
      cancelPeek(); closePeek(); hideTip();
    });
    panel.addEventListener('focusin', function () { openPeek(); });
    panel.addEventListener('focusout', function () {
      setTimeout(function () {
        if (!panel.contains(document.activeElement)) closePeek();
      }, 0);
    });

    /* ------------------------------------------------------------ tooltip */
    function ensureTip() {
      if (!tip) {
        tip = document.createElement('div');
        tip.className = 'sb-tip';
        tip.setAttribute('role', 'tooltip');
        document.body.appendChild(tip);
      }
      return tip;
    }
    function hideTip() {
      if (tipTimer) { clearTimeout(tipTimer); tipTimer = null; }
      tipTarget = null; tipPending = null;
      if (tip) tip.classList.remove('is-on');
    }
    function showTip(item) {
      var label = item.querySelector('.sb-label');
      if (!label) return;
      var el = ensureTip();
      el.textContent = label.textContent.trim();
      var pr = panel.getBoundingClientRect();
      var ir = item.getBoundingClientRect();
      el.style.left = Math.round(pr.right + 12) + 'px';
      el.style.top = Math.round((ir.top + ir.height / 2) * 10) / 10 + 'px';
      el.classList.add('is-on');
      tipTarget = item;
    }

    panel.addEventListener('mouseover', function (e) {
      var item = e.target.closest ? e.target.closest('.sb-link,.sb-mini') : null;
      if (!item || !mqFine.matches || !iconOnly() ||
          panel.classList.contains('is-peeking')) {
        hideTip();
        return;
      }
      if (tipTarget === item && tip.classList.contains('is-on')) return;
      if (tipPending === item) return;
      hideTip();
      tipPending = item;
      tipTimer = setTimeout(function () {
        tipTimer = null; tipPending = null;
        showTip(item);
      }, TIP_DELAY);
    });
    window.addEventListener('scroll', hideTip, true);
    window.addEventListener('resize', function () { hideTip(); closePeek(); });

    /* -------------------------------------------------------------- wiring */
    if (burger) {
      burger.addEventListener('click', function () {
        setDrawer(!layout.classList.contains('nav-open'));
      });
    }
    if (scrim) {
      scrim.addEventListener('click', function () { setDrawer(false); });
    }
    if (collapse) {
      collapse.addEventListener('click', function () {
        cancelPeek();
        setCollapsed(!panel.classList.contains('is-collapsed'), true);
      });
    }

    panel.querySelectorAll('.sb-nav a').forEach(function (a) {
      a.addEventListener('click', function () {
        hideTip();
        if (layout.classList.contains('nav-open')) setDrawer(false);
      });
    });

    document.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && layout.classList.contains('nav-open')) setDrawer(false);
    });

    /* restore the persisted collapse choice (script is end-of-body, so this
       lands before first paint — no expanded flash) */
    var saved = null;
    try { saved = localStorage.getItem(PERSIST_KEY); } catch (e) {}
    if (saved === '1') setCollapsed(true, false);
    else if (saved === '0') setCollapsed(false, false);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
