'use strict';

// ── Main navigation ───────────────────────────────────────────────────────
// Three panes (Realize / Advanced settings / Documentation) are rendered once
// server-side and swapped here, so switching away to read the documentation
// and coming back leaves the realization — and the Verovio renderings — intact.
(function () {
    const tabs  = Array.from(document.querySelectorAll('#main-tabs .main-tab'));
    if (!tabs.length) return;
    const panes = tabs.map(t => document.getElementById(t.dataset.pane));

    function activate(name, { push = true, focus = false } = {}) {
        const idx = tabs.findIndex(t => t.dataset.pane === name);
        if (idx === -1) return;

        tabs.forEach((tab, i) => {
            const on = i === idx;
            tab.classList.toggle('active', on);
            tab.setAttribute('aria-selected', on ? 'true' : 'false');
            tab.tabIndex = on ? 0 : -1;
            if (panes[i]) {
                panes[i].hidden = !on;
                panes[i].classList.toggle('active', on);
            }
        });
        if (focus) tabs[idx].focus();

        const hash = '#' + name.replace(/^tab-/, '');
        if (push && location.hash !== hash) {
            history.pushState(null, '', idx === 0 ? location.pathname + location.search : hash);
        }
        // No re-layout needed on return: every Verovio toolkit here engraves at
        // a fixed pageWidth and is scaled by CSS, so a hidden pane renders the
        // same as a visible one.
    }

    function paneFromHash() {
        const h = location.hash.replace(/^#/, '');
        if (!h) return 'tab-realize';
        return tabs.some(t => t.dataset.pane === 'tab-' + h) ? 'tab-' + h : 'tab-realize';
    }

    tabs.forEach(tab => {
        tab.addEventListener('click', () => activate(tab.dataset.pane));
    });

    // Roving focus across the tablist, per the ARIA tabs pattern.
    document.getElementById('main-tabs').addEventListener('keydown', ev => {
        const i = tabs.indexOf(document.activeElement);
        if (i === -1) return;
        let next = null;
        if (ev.key === 'ArrowRight') next = (i + 1) % tabs.length;
        else if (ev.key === 'ArrowLeft') next = (i - 1 + tabs.length) % tabs.length;
        else if (ev.key === 'Home') next = 0;
        else if (ev.key === 'End') next = tabs.length - 1;
        if (next === null) return;
        ev.preventDefault();
        activate(tabs[next].dataset.pane, { focus: true });
    });

    window.addEventListener('popstate', () => activate(paneFromHash(), { push: false }));

    // Deep links (/#documentation) and in-page anchors to the docs pane.
    activate(paneFromHash(), { push: false });
    document.addEventListener('click', ev => {
        const a = ev.target.closest('a[href^="#doc-"]');
        if (a) activate('tab-docs', { push: false });
    });
})();

// ── Advanced settings: filter the rule list ───────────────────────────────
(function () {
    const filter  = document.getElementById('rules-filter');
    const enabled = document.getElementById('rules-only-enabled');
    const list    = document.querySelector('#tab-settings .rules-list');
    const countEl = document.getElementById('rules-count');
    if (!list) return;

    const cards = Array.from(list.querySelectorAll('.rule-card'));
    const empty = document.createElement('p');
    empty.className = 'rules-empty';
    empty.textContent = (typeof TRANS !== 'undefined' && TRANS.rules_no_match) || 'No rule matches.';
    list.after(empty);

    function apply() {
        const q  = (filter?.value || '').trim().toLowerCase();
        const on = enabled?.checked;
        let shown = 0;

        cards.forEach(card => {
            const isEnabled = !!card.querySelector('.rule-enabled');
            const hit = !q || card.textContent.toLowerCase().includes(q);
            const show = hit && (!on || isEnabled);
            card.hidden = !show;
            if (show) shown++;
        });

        empty.classList.toggle('visible', shown === 0);
        if (countEl) {
            const tpl = (typeof TRANS !== 'undefined' && TRANS.rules_count) || '%shown% / %total%';
            countEl.textContent = tpl.replace('%shown%', shown).replace('%total%', cards.length);
        }
    }

    filter?.addEventListener('input', apply);
    enabled?.addEventListener('change', apply);
    apply();
})();

// ── Documentation: highlight the section being read ───────────────────────
(function () {
    const links = Array.from(document.querySelectorAll('.docs-toc a'));
    if (!links.length || !('IntersectionObserver' in window)) return;

    const targets = links
        .map(a => document.querySelector(a.getAttribute('href')))
        .filter(Boolean);

    const obs = new IntersectionObserver(entries => {
        entries.forEach(e => {
            if (!e.isIntersecting) return;
            links.forEach(a => a.classList.toggle('current', a.getAttribute('href') === '#' + e.target.id));
        });
    }, { rootMargin: '-20% 0px -70% 0px' });

    targets.forEach(t => obs.observe(t));
})();
