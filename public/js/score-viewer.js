'use strict';

// ── Figure-colour helpers ─────────────────────────────────

// Force a colour onto a computed figured-bass SVG group and all its descendants.
function colourFbEl(el, colour) {
    el.classList.add('fb-computed');
    el.setAttribute('fill',   colour);
    el.setAttribute('stroke', colour);
    el.querySelectorAll('*').forEach(ch => {
        ch.setAttribute('fill',   colour);
        ch.setAttribute('stroke', colour);
    });
}

// ── Score Viewer (Verovio) ────────────────────────────────
const scoreViewerCard = document.getElementById('score-viewer-card');

// Tab switching
document.querySelectorAll('.score-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('.score-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.score-pane').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        const pane = document.getElementById(tab.dataset.pane);
        if (pane) pane.classList.add('active');
    });
});

// Per-score state
const scores = {
    orig: { tk: null, page: 1, total: 0 },
    real: { tk: null, page: 1, total: 0 },
};

['orig', 'real'].forEach(key => {
    document.getElementById('prev-' + key).addEventListener('click', () => {
        const s = scores[key];
        if (s.page > 1) { s.page--; renderScorePage(key); }
    });
    document.getElementById('next-' + key).addEventListener('click', () => {
        const s = scores[key];
        if (s.page < s.total) { s.page++; renderScorePage(key); }
    });
});

document.getElementById('cb-show-computed').addEventListener('change', function () {
    const svgEl = document.querySelector('#wrap-real svg');
    if (!svgEl) return;
    svgEl.querySelectorAll('.fb-computed').forEach(el => {
        el.style.visibility = this.checked ? '' : 'hidden';
    });
});

function renderScorePage(key) {
    const s    = scores[key];
    const wrap = document.getElementById('wrap-' + key);
    if (!s.tk || s.total < 1) return;
    try {
        wrap.innerHTML = s.tk.renderToSVG(s.page);
    } catch (e) {
        wrap.innerHTML = '<div class="score-placeholder score-error">⚠ ' + escapeHtml(e.message) + '</div>';
    }
    document.getElementById('lbl-'  + key).textContent = s.page + ' / ' + s.total;
    document.getElementById('prev-' + key).disabled = s.page <= 1;
    document.getElementById('next-' + key).disabled = s.page >= s.total;

    // Attach click handlers and colour computed figures for the realization pane
    if (key === 'real') {
        const svgEl = wrap.querySelector('svg');
        if (svgEl) {
            attachChordClickHandlers(svgEl);

            // Colour computed figured-bass elements (muted indigo).
            const fbOffset  = (s.fbPageOffsets || [])[s.page - 1] || 0;
            const showComp  = document.getElementById('cb-show-computed').checked;
            svgEl.querySelectorAll('.harm').forEach((el, i) => {
                if (fbComputedFlags[fbOffset + i]) {
                    colourFbEl(el, '#7070b0');
                    if (!showComp) el.style.visibility = 'hidden';
                }
            });
        }
    }
}

// ── Verovio lazy loader ───────────────────────────────────
let vrvState = 'idle'; // idle | loading | ready | error
const vrvQueue = [];

function ensureVerovio() {
    return new Promise((resolve, reject) => {
        if (vrvState === 'ready')   { resolve();        return; }
        if (vrvState === 'error')   { reject(new Error('Verovio unavailable')); return; }
        vrvQueue.push({ resolve, reject });
        if (vrvState === 'loading') return;
        vrvState = 'loading';

        const script = document.createElement('script');
        script.src   = 'https://www.verovio.org/javascript/latest/verovio-toolkit-wasm.js';
        script.onerror = () => {
            vrvState = 'error';
            const err = new Error('Could not load Verovio from CDN');
            vrvQueue.forEach(q => q.reject(err));
            vrvQueue.length = 0;
        };
        document.head.appendChild(script);

        // Poll until verovio.toolkit can be successfully instantiated (WASM fully compiled).
        const timer = setInterval(() => {
            try {
                if (typeof verovio === 'undefined' || typeof verovio.toolkit !== 'function') return;
                // Attempt a real instantiation; throws if WASM is still initialising.
                const probe = new verovio.toolkit();
                probe.destroy && probe.destroy();
                clearInterval(timer);
                vrvState = 'ready';
                vrvQueue.forEach(q => q.resolve());
                vrvQueue.length = 0;
            } catch (_) {}
        }, 200);
    });
}

function scoreWrapWidth() {
    const el = document.getElementById('wrap-orig');
    return el ? Math.max(el.clientWidth - 32, 600) : 800;
}

async function initScore(key, xml) {
    const s    = scores[key];
    const wrap = document.getElementById('wrap-' + key);
    s.tk    = null;
    s.page  = 1;
    s.total = 0;

    wrap.innerHTML = '<div class="score-placeholder">'
        + '<div class="spinner" style="width:28px;height:28px;border-width:2px"></div>'
        + '<span>' + escapeHtml(TRANS.rendering) + '</span></div>';

    try {
        await ensureVerovio();

        // WASM may still be mid-init; retry a few times if construction or setOptions throws.
        let tk = null;
        for (let attempt = 0; attempt < 8; attempt++) {
            try {
                tk = new verovio.toolkit();
                tk.setOptions({
                    pageWidth:        2100,
                    pageHeight:       2970,
                    adjustPageHeight: true,
                    scale:            45,
                    breaks:           'auto',
                });
                break;
            } catch (_) {
                tk = null;
                if (attempt === 7) throw _;
                await new Promise(r => setTimeout(r, 250));
            }
        }
        tk.loadData(xml);

        s.tk    = tk;
        s.total = tk.getPageCount();
        s.page  = 1;

        // Pre-compute figured-bass offsets per page for the realization pane.
        if (key === 'real') {
            s.fbPageOffsets    = [];
            let runningFbOffset = 0;
            for (let p = 1; p <= s.total; p++) {
                s.fbPageOffsets.push(runningFbOffset);
                try {
                    const pageSvg = s.tk.renderToSVG(p);
                    const parser  = new DOMParser();
                    const doc     = parser.parseFromString(pageSvg, 'image/svg+xml');
                    runningFbOffset += doc.querySelectorAll('.harm').length;
                } catch (_) { /* keep offsets as-is */ }
            }
        }

        renderScorePage(key);
        pbOnScoreReady();

        // Show click hint only for the realization pane
        if (key === 'real') {
            const hint = document.getElementById('click-hint');
            if (hint) hint.style.display = '';
        }

        const nav = document.getElementById('nav-' + key);
        nav.style.display = s.total > 1 ? '' : 'none';

    } catch (err) {
        wrap.innerHTML = '<div class="score-placeholder score-error">⚠ '
            + escapeHtml(err.message) + '</div>';
    }
}

function launchScoreViewer(origXml, realXml) {
    scoreViewerCard.style.display = '';
    scoreViewerCard.scrollIntoView({ behavior: 'smooth', block: 'start' });
    initScore('orig', origXml);
    initScore('real', realXml);
}

// ── Chord click handlers ──────────────────────────────────

function attachChordClickHandlers(svgEl) {
    const tk = scores['real'].tk;
    if (!tk || !chordDataStore.length) return;

    // Build timeMs → chordDataStore index map.
    const chordTimeMap = {};
    chordDataStore.forEach((cd, idx) => {
        const t = Math.round(cd.scorePosition * 500);
        chordTimeMap[t] = idx;
    });

    // Tolerance lookup: try exact first, then ±5 ms to absorb rounding.
    function lookupTime(ms) {
        const t = Math.round(ms);
        if (t in chordTimeMap) return chordTimeMap[t];
        for (let d = 1; d <= 5; d++) {
            if ((t - d) in chordTimeMap) return chordTimeMap[t - d];
            if ((t + d) in chordTimeMap) return chordTimeMap[t + d];
        }
        return null;
    }

    // Returns element bbox in SVG root coordinate space using screen CTM.
    function svgBBoxInRoot(el) {
        const bb     = el.getBBox();
        const elCTM  = el.getScreenCTM();
        const svgCTM = svgEl.getScreenCTM();
        if (!elCTM || !svgCTM) return { x: bb.x, y: bb.y, w: bb.width, h: bb.height };
        const m   = svgCTM.inverse().multiply(elCTM);
        const pt1 = svgEl.createSVGPoint(); pt1.x = bb.x;            pt1.y = bb.y;
        const pt2 = svgEl.createSVGPoint(); pt2.x = bb.x + bb.width; pt2.y = bb.y + bb.height;
        const tp1 = pt1.matrixTransform(m);
        const tp2 = pt2.matrixTransform(m);
        return {
            x: Math.min(tp1.x, tp2.x),
            y: Math.min(tp1.y, tp2.y),
            w: Math.abs(tp2.x - tp1.x),
            h: Math.abs(tp2.y - tp1.y),
        };
    }

    // Group .note elements by chordDataStore index using getTimeForElement.
    const idxToEls = {};

    svgEl.querySelectorAll('.note').forEach(el => {
        if (!el.id) return;
        let timeMs;
        try { timeMs = tk.getTimeForElement(el.id); } catch (e) { return; }
        if (timeMs == null) return;
        const idx = lookupTime(timeMs);
        if (idx === null) return;
        if (!idxToEls[idx]) idxToEls[idx] = [];
        idxToEls[idx].push(el);
    });

    if (!Object.keys(idxToEls).length) return;

    // Hover-rect layer inserted behind all notes
    const hoverLayer = document.createElementNS('http://www.w3.org/2000/svg', 'g');
    svgEl.insertBefore(hoverLayer, svgEl.firstChild);

    Object.entries(idxToEls).forEach(([idxStr, els]) => {
        const idx = parseInt(idxStr, 10);
        let rect       = null;
        let leaveTimer = null;

        // Lazily build the rect on first hover
        const ensureRect = () => {
            if (rect) return rect;
            const boxes  = els.map(svgBBoxInRoot);
            const PAD_X  = 20;
            const PAD_Y  = 40;
            const left   = Math.min(...boxes.map(b => b.x))       - PAD_X;
            const top    = Math.min(...boxes.map(b => b.y))       - PAD_Y;
            const right  = Math.max(...boxes.map(b => b.x + b.w)) + PAD_X;
            const bottom = Math.max(...boxes.map(b => b.y + b.h)) + PAD_Y;

            rect = document.createElementNS('http://www.w3.org/2000/svg', 'rect');
            rect.setAttribute('x',      left);
            rect.setAttribute('y',      top);
            rect.setAttribute('width',  right - left);
            rect.setAttribute('height', bottom - top);
            rect.setAttribute('fill',   'rgba(140, 145, 160, 0.22)');
            rect.setAttribute('rx',     '6');
            rect.style.display = 'none';
            rect.style.cursor  = 'pointer';
            hoverLayer.appendChild(rect);
            rect.addEventListener('mouseenter', on);
            rect.addEventListener('mouseleave', off);
            rect.addEventListener('click', click);
            return rect;
        };

        const on    = () => { clearTimeout(leaveTimer); ensureRect().style.display = ''; };
        const off   = () => { leaveTimer = setTimeout(() => { if (rect) rect.style.display = 'none'; }, 50); };
        const click = (e) => { e.stopPropagation(); off(); openChordInspector(chordDataStore[idx], idx); };

        els.forEach(el => {
            el.classList.add('chord-clickable');
            el.addEventListener('mouseenter', on);
            el.addEventListener('mouseleave', off);
            el.addEventListener('click', click);
        });
    });
}
