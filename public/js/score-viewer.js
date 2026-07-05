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

        // The realization SVG has no usable geometry while hidden, so the phrase
        // labels can only be placed once its pane becomes visible.
        if (tab.dataset.pane === 'pane-real') {
            const svgEl = document.querySelector('#wrap-real svg');
            if (svgEl) requestAnimationFrame(() => {
                drawPhraseBackgrounds(svgEl);
                drawPassageKeyLabels(svgEl);
            });
        }
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
            colorFluteByPhrase(svgEl);
            drawPhraseBackgrounds(svgEl);

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

async function initScore(key, xml, preservePage = false) {
    const s    = scores[key];
    const wrap = document.getElementById('wrap-' + key);
    // When re-rendering after an edit, remember the page the user was viewing so
    // we can return to it once the new score is loaded (clamped to its length).
    const prevPage = preservePage ? s.page : 1;
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
        s.page  = Math.min(Math.max(prevPage, 1), s.total || 1);

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

// ── Phrase key labels (detected tonality / mode per passage) ──

const PASSAGE_KEY_NAMES = {
    '-7': 'C♭', '-6': 'G♭', '-5': 'D♭', '-4': 'A♭', '-3': 'E♭',
    '-2': 'B♭', '-1': 'F', '0': 'C', '1': 'G', '2': 'D', '3': 'A', '4': 'E',
    '5': 'B', '6': 'F♯', '7': 'C♯',
};

// The same key signature names its relative minor when the mode is minor.
const PASSAGE_KEY_NAMES_MINOR = {
    '-7': 'A♭', '-6': 'E♭', '-5': 'B♭', '-4': 'F', '-3': 'C', '-2': 'G',
    '-1': 'D', '0': 'A', '1': 'E', '2': 'B', '3': 'F♯', '4': 'C♯', '5': 'G♯',
    '6': 'D♯', '7': 'A♯',
};

function passageKeyLabel(p) {
    const f = String(p.key.fifths);
    if (p.key.mode === 'minor') return (PASSAGE_KEY_NAMES_MINOR[f] || '?') + ' min';
    return (PASSAGE_KEY_NAMES[f] || '?') + ' maj';
}

// Element bbox expressed in the SVG root's user-coordinate space.
function svgBBoxInRoot(svgEl, el) {
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

// Verovio reports element times in milliseconds at its own default playback
// tempo, which is NOT guaranteed to be 120 bpm — current builds use 60 bpm
// (1000 ms per quarter). A hard-coded constant silently breaks when that default
// changes, which mis-maps every clicked chord. Instead derive the factor from
// the data: every note time equals scorePosition × k, so the k shared by the
// most notes is the right one. (Notes at scorePosition 0 carry no information.)
function deriveMsPerQuarter(noteTimes) {
    const positions = chordDataStore.map(cd => cd.scorePosition).filter(p => p > 0);
    if (!positions.length) return 1000;

    const votes = new Map();
    for (const t of noteTimes) {
        if (t <= 0) continue;
        for (const s of positions) {
            const k = Math.round((t / s) * 10) / 10;
            votes.set(k, (votes.get(k) || 0) + 1);
        }
    }

    let best = 1000, bestVotes = -1;
    for (const [k, n] of votes) if (n > bestVotes) { bestVotes = n; best = k; }
    return best || 1000;
}

// Map chordDataStore index → its .note SVG elements on the current page.
function buildChordElementMap(svgEl, tk) {
    if (!chordDataStore.length) return {};

    // Collect every note element Verovio can time, then derive the tempo factor.
    const noteEls = [];
    svgEl.querySelectorAll('.note').forEach(el => {
        if (!el.id) return;
        // Melody (flute) notes live in their own top staff and are not part of
        // the realized continuo — keep them out of chord mapping, hover, click,
        // and the tempo-factor vote.
        if (el.id.startsWith('flute-')) return;
        let timeMs;
        try { timeMs = tk.getTimeForElement(el.id); } catch (e) { return; }
        if (timeMs == null) return;
        noteEls.push({ el, timeMs });
    });
    if (!noteEls.length) return {};

    const msPerQuarter = deriveMsPerQuarter(noteEls.map(n => n.timeMs));

    const chordTimeMap = {};
    chordDataStore.forEach((cd, idx) => { chordTimeMap[Math.round(cd.scorePosition * msPerQuarter)] = idx; });

    // Exact key first, then a small tolerance (scaled to the tempo) to absorb
    // floating-point rounding between Verovio's clock and our derived factor.
    const tol = Math.max(5, Math.round(msPerQuarter * 0.02));
    const lookupTime = (ms) => {
        const t = Math.round(ms);
        if (t in chordTimeMap) return chordTimeMap[t];
        for (let d = 1; d <= tol; d++) {
            if ((t - d) in chordTimeMap) return chordTimeMap[t - d];
            if ((t + d) in chordTimeMap) return chordTimeMap[t + d];
        }
        return null;
    };

    const idxToEls = {};
    noteEls.forEach(({ el, timeMs }) => {
        const idx = lookupTime(timeMs);
        if (idx === null) return;
        (idxToEls[idx] ||= []).push(el);
    });
    return idxToEls;
}

// Draw a small bracket + key label above the first rendered measure of each
// detected phrase. Confidence drives colour/opacity. Display-only — the output
// MusicXML armature is never modified. Self-contained so it can be re-run when
// the realization tab is first shown (geometry is unavailable while hidden).
function drawPassageKeyLabels(svgEl) {
    const tk = scores['real'].tk;
    if (!tk || !passageStore.length || !chordDataStore.length) return;

    // Geometry is meaningless while the pane is display:none — getScreenCTM is
    // null in that case. Bail; the tab-switch handler redraws once visible.
    if (!svgEl.getScreenCTM()) return;

    const SVGNS = 'http://www.w3.org/2000/svg';
    const old = svgEl.querySelector('.passage-key-layer');
    if (old) old.remove();

    // Calibrate the font in ROOT/screen units (the space svgBBoxInRoot returns),
    // using a notehead/figure as a size reference. Mixing Verovio's internal
    // getBBox units with root coordinates would make the text wildly oversized.
    let baseFont = 0;
    const ref = svgEl.querySelector('.notehead') || svgEl.querySelector('.harm') || svgEl.querySelector('.note');
    if (ref) { const b = svgBBoxInRoot(svgEl, ref); if (b.h) baseFont = b.h * 1.05; }
    if (!baseFont || baseFont < 5) baseFont = 10;

    const idxToEls = buildChordElementMap(svgEl, tk);
    if (!Object.keys(idxToEls).length) return;

    const layer = document.createElementNS(SVGNS, 'g');
    layer.setAttribute('class', 'passage-key-layer');
    svgEl.appendChild(layer); // draw on top

    passageStore.forEach((p, pIdx) => {
        // First chord of this passage that is actually rendered on this page.
        let idx = -1;
        for (let i = 0; i < chordDataStore.length; i++) {
            if (chordDataStore[i].measureNum === p.start_measure && idxToEls[i]) { idx = i; break; }
        }
        if (idx < 0) return;

        const boxes = idxToEls[idx].map(el => svgBBoxInRoot(svgEl, el));
        const left  = Math.min(...boxes.map(b => b.x));

        // Anchor to the top of the staff/system so labels in a row line up,
        // rather than to each note (whose height varies with pitch and stem).
        const staffEl  = idxToEls[idx][0].closest('.staff') || idxToEls[idx][0].closest('.system');
        const staffTop = staffEl ? svgBBoxInRoot(svgEl, staffEl).y : Math.min(...boxes.map(b => b.y));

        // Keep the label inside the page; clamp so the first system isn't clipped.
        const baseline = Math.max(staffTop - baseFont * 0.6, baseFont);

        // Colour identifies which phrase this is (matches the panel legend);
        // confidence is conveyed by opacity so both signals survive.
        const colour  = passageColor(pIdx);
        const opacity = p.confidence === 'low' ? '0.65' : p.confidence === 'medium' ? '0.85' : '1';

        const tick = document.createElementNS(SVGNS, 'line');
        tick.setAttribute('x1', left);
        tick.setAttribute('x2', left);
        tick.setAttribute('y1', baseline - baseFont * 0.85);
        tick.setAttribute('y2', staffTop);
        tick.setAttribute('stroke', colour);
        tick.setAttribute('stroke-width', String(Math.max(baseFont * 0.08, 0.8)));
        tick.setAttribute('opacity', opacity);
        layer.appendChild(tick);

        const text = document.createElementNS(SVGNS, 'text');
        text.setAttribute('x', left + baseFont * 0.3);
        text.setAttribute('y', baseline);
        text.setAttribute('fill', colour);
        text.setAttribute('font-size', String(baseFont));
        text.setAttribute('font-weight', '600');
        text.setAttribute('font-family', 'system-ui, -apple-system, sans-serif');
        text.setAttribute('opacity', opacity);
        text.textContent = passageKeyLabel(p);
        layer.appendChild(text);

        // Clicking the on-score key label jumps to that phrase's analysis card
        // and opens its "Why this key?" decision trail.
        const title = document.createElementNS(SVGNS, 'title');
        title.textContent = 'Why this key? →';
        [tick, text].forEach(el => {
            el.style.cursor = 'pointer';
            el.setAttribute('pointer-events', 'all');
            el.appendChild(title.cloneNode(true));
            el.addEventListener('click', ev => { ev.stopPropagation(); focusPassageAnalysis(pIdx); });
        });
    });
}

// Scroll the passages panel to a given phrase, open its decision trail, and
// flash it — the target of a click on the on-score tonality label.
function focusPassageAnalysis(pIdx) {
    const item = document.getElementById('passage-item-' + pIdx);
    if (!item) return;

    const trace = item.querySelector('details.passage-trace');
    if (trace) trace.open = true;

    item.scrollIntoView({ behavior: 'smooth', block: 'center' });

    item.style.transition = 'box-shadow 0.25s ease';
    item.style.boxShadow  = '0 0 0 2px var(--accent)';
    setTimeout(() => { item.style.boxShadow = ''; }, 1600);
}

// Measure number → phrase colour (phrases are contiguous measure ranges).
function phraseColorByMeasure() {
    const mColor = {};
    passageStore.forEach((p, idx) => {
        const c = passageColor(idx);
        for (let m = p.start_measure; m <= p.end_measure; m++) mColor[m] = c;
    });
    return mColor;
}

// Draw a translucent background band behind each measure in the colour of the
// phrase it belongs to. Bands span the full system height so consecutive
// same-phrase measures read as one continuous band (and wrapped phrases work
// system-by-system automatically). Needs geometry, so it no-ops while the pane
// is hidden; the tab-switch handler redraws once visible.
function drawPhraseBackgrounds(svgEl) {
    if (!passageStore.length) return;
    if (!svgEl.getScreenCTM()) return;

    const old = svgEl.querySelector('.phrase-bg-layer');
    if (old) old.remove();

    const mColor = phraseColorByMeasure();
    const SVGNS  = 'http://www.w3.org/2000/svg';
    const layer  = document.createElementNS(SVGNS, 'g');
    layer.setAttribute('class', 'phrase-bg-layer');
    svgEl.insertBefore(layer, svgEl.firstChild); // behind the notes

    svgEl.querySelectorAll('.measure[id^="meas-"]').forEach(measEl => {
        const m = parseInt(measEl.id.slice(5), 10);
        const c = mColor[m];
        if (!c) return;

        const sysEl = measEl.closest('.system') || measEl;
        const mb = svgBBoxInRoot(svgEl, measEl);
        const sb = svgBBoxInRoot(svgEl, sysEl);
        if (!mb.w || !sb.h) return;

        const PAD  = sb.h * 0.08;
        const rect = document.createElementNS(SVGNS, 'rect');
        rect.setAttribute('x',      mb.x);
        rect.setAttribute('y',      sb.y - PAD);
        rect.setAttribute('width',  mb.w);
        rect.setAttribute('height', sb.h + PAD * 2);
        rect.setAttribute('fill',   c);
        rect.setAttribute('opacity', '0.12');
        layer.appendChild(rect);
    });
}

// Tint each melody (flute) note with the colour of the phrase it belongs to,
// matching the passage-key labels and the panel legend. Flute notes carry an
// SVG id "flute-{measureNumber}-{i}" (Verovio preserves the MusicXML note id).
// Purely visual; runs per rendered page so it works with pagination too.
function colorFluteByPhrase(svgEl) {
    if (!passageStore.length) return;

    const mColor = phraseColorByMeasure();
    svgEl.querySelectorAll('[id^="flute-"]').forEach(el => {
        const m = parseInt(el.id.split('-')[1], 10);
        const c = mColor[m];
        if (!c) return;
        // fill covers noteheads, accidentals and flags; stems are stroked.
        el.setAttribute('fill', c);
        el.querySelectorAll('.stem path, .stem rect').forEach(s => s.setAttribute('stroke', c));
    });
}

// ── Chord click handlers ──────────────────────────────────

function attachChordClickHandlers(svgEl) {
    const tk = scores['real'].tk;
    if (!tk || !chordDataStore.length) return;

    const idxToEls = buildChordElementMap(svgEl, tk);
    if (!Object.keys(idxToEls).length) return;

    // Graphical phrase-key labels (detected tonality per passage). No-ops while
    // the pane is hidden; the score-tab handler redraws once it is shown.
    drawPassageKeyLabels(svgEl);

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
            const boxes  = els.map(el => svgBBoxInRoot(svgEl, el));
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
