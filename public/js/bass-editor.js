'use strict';

// ── Bass Line Editor (Speedy Entry) ──────────────────────────────────────
(function initBassEditor() {
    const edScoreWrap  = document.getElementById('ed-score-wrap');
    const edFigInput   = document.getElementById('ed-fig-input');
    const edFigPreview = document.getElementById('ed-fig-preview');
    const edStatus     = document.getElementById('ed-status');
    const edAccDisp    = document.getElementById('ed-acc-display');
    const edFigSet     = document.getElementById('ed-fig-set');
    const edRealBtn    = document.getElementById('ed-realize-btn');
    const edKeySelect  = document.getElementById('ed-key');
    const edTimeSelect = document.getElementById('ed-time');
    const edDotBtn     = document.getElementById('ed-dot-btn');
    if (!edScoreWrap) return;

    // ── State ─────────────────────────────────────────────────────────────
    // DIV=8 lets us represent dotted 16ths (3 divisions) cleanly
    const DIV = 8;
    const DUR_DIVS = { 'whole': 32, 'half': 16, 'quarter': 8, 'eighth': 4, '16th': 2 };
    const STEPS    = ['C', 'D', 'E', 'F', 'G', 'A', 'B'];
    const STEP_SEM = [0, 2, 4, 5, 7, 9, 11];

    let ED = {
        notes:   [],       // {step,octave,alter,dur,dot,rest,figs}[]
        key:     0,        // fifths
        beats:   4,        // time sig numerator
        btype:   4,        // time sig denominator
        cursor:  0,        // insertion point; note "at" cursor = notes[cursor-1]
        curDur:  'quarter',
        curDot:  false,
        pendAcc: null,     // null | -1 | 0 | 1  (pending accidental for next note)
        figMode: false,
        curStep:   'D',    // cursor pitch — D3 = middle line of bass clef
        curOctave: 3,
    };

    // ── Helpers ───────────────────────────────────────────────────────────
    function noteMidi(step, oct, alter) {
        const si = STEPS.indexOf(step);
        return (oct + 1) * 12 + STEP_SEM[si] + (alter || 0);
    }
    function durDivs(dur, dot) {
        const b = DUR_DIVS[dur] || 8;
        return dot ? b + Math.floor(b / 2) : b;
    }
    function measDivs() {
        return DIV * ED.beats * (4 / ED.btype);
    }
    // Return the key-signature accidental for a given step (fifths > 0 = sharps)
    function diatonicAlter(step, fifths) {
        if (fifths > 0) {
            const sharps = ['F','C','G','D','A','E','B'];
            return sharps.slice(0, fifths).includes(step) ? 1 : 0;
        } else if (fifths < 0) {
            const flats = ['B','E','A','D','G','C','F'];
            return flats.slice(0, -fifths).includes(step) ? -1 : 0;
        }
        return 0;
    }
    function stepUp(step, octave, key) {
        const si     = STEPS.indexOf(step);
        const newSi  = (si + 1) % 7;
        const newOct = si === 6 ? octave + 1 : octave;
        const s      = STEPS[newSi];
        return { step: s, octave: Math.min(5, newOct), alter: diatonicAlter(s, key) };
    }
    function stepDown(step, octave, key) {
        const si     = STEPS.indexOf(step);
        const newSi  = (si + 6) % 7;
        const newOct = si === 0 ? octave - 1 : octave;
        const s      = STEPS[newSi];
        return { step: s, octave: Math.max(0, newOct), alter: diatonicAlter(s, key) };
    }

    // ── Mutations ─────────────────────────────────────────────────────────
    function insertNote(step, octave, alter, dur, dot) {
        ED.notes.splice(ED.cursor, 0,
            { step, octave, alter: alter ?? 0, dur, dot: !!dot, rest: false, figs: '' });
        ED.cursor++;
        schedRender();
    }
    function insertRest(dur, dot) {
        ED.notes.splice(ED.cursor, 0,
            { step: 'C', octave: 2, alter: 0, dur, dot: !!dot, rest: true, figs: '' });
        ED.cursor++;
        schedRender();
    }
    function deleteAtCursor() {
        if (ED.cursor < 1) return;
        ED.cursor--;
        ED.notes.splice(ED.cursor, 1);
        schedRender();
    }

    // ── Beaming ───────────────────────────────────────────────────────────
    // Returns [{b1, b2}] — MusicXML beam tags for each note in the array.
    // b1 = beam level 1 (all beamable notes); b2 = beam level 2 (16th and shorter).
    // Ghost cursor notes are excluded from beaming so they don't break runs.
    function computeBeams(notes) {
        const beamable = new Set(['eighth', '16th', '32nd', '64th']);
        const is16plus  = new Set(['16th', '32nd', '64th']);
        const beatDiv   = DIV * 4 / ED.btype;
        // Compound meter (6/8, 9/8, 12/8): beam in dotted-quarter groups
        const isCompound = ED.btype >= 8 && ED.beats % 3 === 0;
        const groupDiv   = isCompound ? beatDiv * 3 : beatDiv;
        const beams = notes.map(() => ({ b1: null, b2: null }));

        let pos = 0;
        const noteGroup = notes.map(n => {
            const g = Math.floor(pos / groupDiv + 1e-9);
            pos += durDivs(n.dur, n.dot);
            return g;
        });

        const byGroup = {};
        noteGroup.forEach((g, i) => { (byGroup[g] = byGroup[g] || []).push(i); });

        for (const indices of Object.values(byGroup)) {
            let runStart = -1;
            const flush = end => {
                if (runStart < 0 || end - runStart < 2) { runStart = -1; return; }
                for (let r = runStart; r < end; r++) {
                    const ni = indices[r];
                    beams[ni].b1 = r === runStart ? 'begin' : r === end - 1 ? 'end' : 'continue';
                }
                const slice = indices.slice(runStart, end);
                for (let r = 0; r < slice.length; r++) {
                    const ni = slice[r];
                    if (!is16plus.has(notes[ni].dur)) continue;
                    const pIs16 = r > 0               && is16plus.has(notes[slice[r - 1]].dur);
                    const nIs16 = r < slice.length - 1 && is16plus.has(notes[slice[r + 1]].dur);
                    if      ( pIs16 &&  nIs16) beams[ni].b2 = 'continue';
                    else if (!pIs16 &&  nIs16) beams[ni].b2 = 'begin';
                    else if ( pIs16 && !nIs16) beams[ni].b2 = 'end';
                    else beams[ni].b2 = (r === 0 || !is16plus.has(notes[slice[r - 1]].dur))
                        ? 'forward hook' : 'backward hook';
                }
                runStart = -1;
            };
            for (let k = 0; k <= indices.length; k++) {
                const i = k < indices.length ? indices[k] : -1;
                const n = i >= 0 ? notes[i] : null;
                if (n && !n.rest && !n._ghost && beamable.has(n.dur)) {
                    if (runStart < 0) runStart = k;
                } else {
                    flush(k);
                }
            }
        }
        return beams;
    }

    // ── MusicXML builder ──────────────────────────────────────────────────
    // includeGhost=true inserts a blinking cursor note at ED.cursor for preview
    function edToXml(includeGhost = true) {
        const mDiv = measDivs();
        const notes = includeGhost
            ? [
                ...ED.notes.slice(0, ED.cursor),
                { step: ED.curStep, octave: ED.curOctave,
                  alter: ED.pendAcc !== null ? ED.pendAcc : diatonicAlter(ED.curStep, ED.key),
                  dur: ED.curDur, dot: ED.curDot, rest: false, figs: '', _ghost: true,
                  color: '#c8a96e' },
                ...ED.notes.slice(ED.cursor),
              ]
            : ED.notes;

        // Group notes into measures
        const measures = [];
        let pos = 0;
        while (pos < notes.length) {
            let rem = mDiv, mNotes = [];
            while (pos < notes.length && rem > 0.001) {
                const d = durDivs(notes[pos].dur, notes[pos].dot);
                if (d > rem + 0.001 && mNotes.length > 0) break;
                mNotes.push(notes[pos]);
                rem -= d;
                if (rem < 0) rem = 0;
                pos++;
            }
            measures.push(mNotes);
        }
        if (!measures.length) measures.push([]);

        const beams = computeBeams(notes);

        let xml = '<?xml version="1.0" encoding="UTF-8"?>\n'
                + '<score-partwise version="4.0">\n'
                + '<part-list><score-part id="P1">'
                + '<part-name>Bass</part-name></score-part></part-list>\n'
                + '<part id="P1">\n';

        measures.forEach((mNotes, mi) => {
            xml += '<measure number="' + (mi + 1) + '">\n';
            if (mi === 0) {
                xml += '<attributes>'
                     + '<divisions>' + DIV + '</divisions>'
                     + '<key><fifths>' + ED.key + '</fifths></key>'
                     + '<time><beats>' + ED.beats + '</beats>'
                     + '<beat-type>' + ED.btype + '</beat-type></time>'
                     + '<clef><sign>F</sign><line>4</line></clef>'
                     + '</attributes>\n';
            }
            // Per-measure accidental state: tracks the alter currently in effect
            // for each (step+octave) slot; resets at every barline.
            const activeAcc = {};
            const accMap = {'-2':'double-flat','-1':'flat','0':'natural','1':'sharp','2':'double-sharp'};

            mNotes.forEach((n, ni) => {
                const d = durDivs(n.dur, n.dot);
                if (n.figs) xml += figStrToFbXml(n.figs, d) + '\n';
                const noteId = n._ghost ? 'cursor-note' : 'en-' + ED.notes.indexOf(n);
                const noteColor = n.color ? ' color="' + n.color + '"' : '';
                const ni_flat = notes.indexOf(n);
                const prevNote = ni_flat > 0 ? notes[ni_flat - 1] : null;
                const tieStop  = !n.rest && prevNote && !prevNote.rest && prevNote.tieStart;
                const tieStart = !n.rest && n.tieStart;
                xml += '<note xml:id="' + noteId + '"' + noteColor + '>';
                if (n.rest) {
                    xml += '<rest/>';
                } else {
                    const alt = n.alter ? '<alter>' + n.alter + '</alter>' : '';
                    xml += '<pitch><step>' + n.step + '</step>' + alt
                         + '<octave>' + n.octave + '</octave></pitch>';
                }
                xml += '<duration>' + d + '</duration>'
                     + '<type>' + n.dur + '</type>'
                     + (n.dot ? '<dot/>' : '');
                if (tieStop)  xml += '<tie type="stop"/>';
                if (tieStart) xml += '<tie type="start"/>';
                const bm = beams[ni_flat];
                if (bm && bm.b1) xml += '<beam number="1">' + bm.b1 + '</beam>';
                if (bm && bm.b2) xml += '<beam number="2">' + bm.b2 + '</beam>';
                // <accidental>: display only on first occurrence or change within a measure
                if (!n.rest) {
                    const pitchKey  = n.step + n.octave;
                    const keyAlt    = diatonicAlter(n.step, ED.key);
                    const curActive = pitchKey in activeAcc ? activeAcc[pitchKey] : keyAlt;
                    const noteAlt   = n._ghost
                        ? (ED.pendAcc !== null ? ED.pendAcc : keyAlt)
                        : n.alter;
                    if (noteAlt !== curActive) {
                        if (accMap[String(noteAlt)])
                            xml += '<accidental>' + accMap[String(noteAlt)] + '</accidental>';
                        activeAcc[pitchKey] = noteAlt;
                    }
                }
                if (tieStop || tieStart) {
                    xml += '<notations>';
                    if (tieStop)  xml += '<tied type="stop"/>';
                    if (tieStart) xml += '<tied type="start"/>';
                    xml += '</notations>';
                }
                xml += '</note>\n';
            });
            // Pad underfull measure with rests
            const used = mNotes.reduce((s, n) => s + durDivs(n.dur, n.dot), 0);
            let pad = Math.round(mDiv - used);
            const padTypes = [[32,'whole'],[16,'half'],[8,'quarter'],[4,'eighth'],[2,'16th'],[1,'32nd']];
            for (const [pd, pt] of padTypes) {
                while (pad >= pd) {
                    xml += '<note><rest/><duration>' + pd + '</duration>'
                         + '<type>' + pt + '</type></note>\n';
                    pad -= pd;
                }
            }
            xml += '</measure>\n';
        });

        xml += '</part>\n</score-partwise>';
        return xml;
    }

    // ── Rendering ─────────────────────────────────────────────────────────
    let edTk    = null;
    let edTimer = null;
    function schedRender() {
        clearTimeout(edTimer);
        edTimer = setTimeout(doRender, 80);
        updateStatusBar();
        updateDurButtons();
        syncFigBar();
    }
    async function doRender() {
        const xml = edToXml();
        try {
            await ensureVerovio();
            if (!edTk) edTk = new verovio.toolkit();
            edTk.setOptions({
                pageWidth:        1600,
                pageHeight:       3000,
                adjustPageHeight: true,
                scale:            45,
                breaks:           'auto',
                spacingLinear:    0.3,
                pageMarginTop:    80,
                pageMarginBottom: 80,
                pageMarginLeft:   80,
                pageMarginRight:  80,
            });
            edTk.loadData(xml);
            let edSvg = edTk.renderToSVG(1);
            // Inject Figurato @font-face so figured-bass glyphs render correctly in the SVG
            edSvg = edSvg.replace(
                '<defs>',
                '<defs><style>@font-face{font-family:"Figurato";src:url("/fonts/Figurato.otf") format("opentype")}'
                + '.harm,.fb-num,.figured-bass text{font-family:"Figurato",serif!important}</style>'
            );
            edScoreWrap.innerHTML = edSvg;
            highlightCursor(edScoreWrap);
        } catch (e) {
            edScoreWrap.innerHTML =
                '<div class="score-placeholder score-error">⚠ '
                + escapeHtml(e.message) + '</div>';
        }
        edRealBtn.disabled = !ED.notes.length;
    }
    function highlightCursor(wrap) {
        const el = wrap.querySelector('[id="cursor-note"]');
        if (el) el.classList.add('ed-cursor-blink');
    }

    // ── UI updates ────────────────────────────────────────────────────────
    function updateStatusBar() {
        if (!edStatus) return;
        const keyAlt  = diatonicAlter(ED.curStep, ED.key);
        const effAlt  = ED.pendAcc !== null ? ED.pendAcc : keyAlt;
        const altChr  = effAlt > 0 ? '♯' : effAlt < 0 ? '♭' : '';
        const pitch   = ED.curStep + ED.curOctave + altChr;
        const durSym  = { whole: 'whole', half: 'half', quarter: '♩', eighth: '♪', '16th': '16th' }[ED.curDur] || ED.curDur;
        const dot     = ED.curDot ? '·' : '';
        const tot     = ED.notes.length;
        let msg = pitch + '  ' + durSym + dot;
        if (tot) msg += '  ·  pos\u202f' + ED.cursor + '\u202f/\u202f' + tot;
        if (ED.pendAcc !== null)
            msg += '  ·  acc: ' + (ED.pendAcc > 0 ? '♯' : ED.pendAcc < 0 ? '♭' : '♮');
        edStatus.textContent = msg;
    }
    function updateDurButtons() {
        document.querySelectorAll('.ed-dur-btn[data-dur]').forEach(btn => {
            btn.classList.toggle('ed-active', btn.dataset.dur === ED.curDur);
        });
        if (edDotBtn) edDotBtn.classList.toggle('ed-active', ED.curDot);
        document.querySelectorAll('.ed-acc-btn[data-acc]').forEach(btn => {
            btn.classList.toggle('ed-active',
                ED.pendAcc !== null && parseInt(btn.dataset.acc, 10) === ED.pendAcc);
        });
        if (edAccDisp) {
            edAccDisp.textContent = ED.pendAcc === 1 ? '♯'
                : ED.pendAcc === -1 ? '♭'
                : ED.pendAcc === 0  ? '♮'
                : '';
        }
    }
    function syncFigBar() {
        const n = ED.notes[ED.cursor - 1];
        if (n && !edFigInput.matches(':focus')) {
            edFigInput.value         = n.figs || '';
            edFigPreview.textContent = (n.figs || '').replace(/\s+/g, '');
        } else if (!n) {
            edFigInput.value         = '';
            edFigPreview.textContent = '';
        }
    }

    // ── Figure mode ───────────────────────────────────────────────────────
    let figOrigValue = '', figSkipBlur = false;
    function enterFigMode() {
        if (ED.cursor < 1) return;
        const n = ED.notes[ED.cursor - 1];
        figOrigValue = n ? (n.figs || '') : '';
        ED.figMode   = true;
        edFigInput.value         = figOrigValue;
        edFigPreview.textContent = figOrigValue.replace(/\s+/g, '');
        edFigInput.focus();
        edFigInput.select();
    }
    function applyFigMode() {
        ED.figMode = false;
        const n = ED.notes[ED.cursor - 1];
        if (n) {
            n.figs = edFigInput.value.trim();
            edFigPreview.textContent = n.figs.replace(/\s+/g, '');
        }
        edScoreWrap.focus();
        schedRender();
    }
    function cancelFigMode() {
        figSkipBlur              = true;
        ED.figMode               = false;
        edFigInput.value         = figOrigValue;
        edFigPreview.textContent = figOrigValue.replace(/\s+/g, '');
        edScoreWrap.focus();
    }

    // ── Keyboard handler (Finale Speedy Entry style) ──────────────────────
    function handleEdKey(e) {
        if (ED.figMode) {
            if (e.key === 'Escape') { cancelFigMode(); e.preventDefault(); }
            return;
        }
        const key = e.key;
        if (e.ctrlKey || e.metaKey || e.altKey) return;

        // Duration keys 3–7 → immediately enter note at cursor pitch
        if ('34567'.includes(key) && key.length === 1) {
            const map = {'3':'16th','4':'eighth','5':'quarter','6':'half','7':'whole'};
            ED.curDur = map[key];
            const alter = ED.pendAcc !== null ? ED.pendAcc : diatonicAlter(ED.curStep, ED.key);
            insertNote(ED.curStep, ED.curOctave, alter, ED.curDur, ED.curDot);
            ED.pendAcc = null;
            updateDurButtons();
            e.preventDefault(); return;
        }
        // Dot toggle
        if (key === '.') {
            ED.curDot = !ED.curDot;
            schedRender(); e.preventDefault(); return;
        }
        // Pending accidentals  -=♭  *=♮  +=♯
        if (key === '-') {
            ED.pendAcc = ED.pendAcc === -1 ? null : -1;
            schedRender(); e.preventDefault(); return;
        }
        if (key === '+' || key === '#') {
            ED.pendAcc = ED.pendAcc === 1 ? null : 1;
            schedRender(); e.preventDefault(); return;
        }
        if (key === '*') {
            ED.pendAcc = ED.pendAcc === 0 ? null : 0;
            schedRender(); e.preventDefault(); return;
        }
        // Rest
        if (key === 'r' || key === 'R') {
            insertRest(ED.curDur, ED.curDot);
            ED.pendAcc = null;
            e.preventDefault(); return;
        }
        // Pitch cursor: diatonic step up/down
        if (key === 'ArrowUp' && !e.shiftKey) {
            const s = stepUp(ED.curStep, ED.curOctave, ED.key);
            ED.curStep = s.step; ED.curOctave = s.octave;
            schedRender(); e.preventDefault(); return;
        }
        if (key === 'ArrowDown' && !e.shiftKey) {
            const s = stepDown(ED.curStep, ED.curOctave, ED.key);
            ED.curStep = s.step; ED.curOctave = s.octave;
            schedRender(); e.preventDefault(); return;
        }
        // Octave shift (Shift+Up/Down)
        if (key === 'ArrowUp' && e.shiftKey) {
            ED.curOctave = Math.min(5, ED.curOctave + 1);
            schedRender(); e.preventDefault(); return;
        }
        if (key === 'ArrowDown' && e.shiftKey) {
            ED.curOctave = Math.max(0, ED.curOctave - 1);
            schedRender(); e.preventDefault(); return;
        }
        // Score navigation — also update cursor pitch to match note navigated to
        if (key === 'ArrowLeft') {
            if (ED.cursor > 0) {
                ED.cursor--;
                const n = ED.notes[ED.cursor - 1];
                if (n && !n.rest) { ED.curStep = n.step; ED.curOctave = n.octave; }
                schedRender();
            }
            e.preventDefault(); return;
        }
        if (key === 'ArrowRight') {
            if (ED.cursor < ED.notes.length) {
                const n = ED.notes[ED.cursor];
                ED.cursor++;
                if (n && !n.rest) { ED.curStep = n.step; ED.curOctave = n.octave; }
                schedRender();
            }
            e.preventDefault(); return;
        }
        // Delete
        if (key === 'Backspace' || key === 'Delete') {
            deleteAtCursor();
            e.preventDefault(); return;
        }
        // Tie: = ties note at cursor-1 to the next note
        if (key === '=') {
            const n = ED.notes[ED.cursor - 1];
            if (n && !n.rest) { n.tieStart = !n.tieStart; schedRender(); }
            e.preventDefault(); return;
        }
        // Tab → figure mode
        if (key === 'Tab') {
            if (ED.cursor > 0) { enterFigMode(); }
            e.preventDefault(); return;
        }
    }

    // ── Realize ───────────────────────────────────────────────────────────
    async function doEditorRealize() {
        if (!ED.notes.length) return;
        const xml  = edToXml(false); // no ghost cursor note in realized output
        const blob = new Blob([xml], { type: 'application/xml' });
        const file = new File([blob], 'composed.xml', { type: 'application/xml' });
        progressWrap.classList.add('visible');
        resultPanel.classList.remove('visible');
        resultPanel.style.display = 'none';
        try {
            const fd       = new FormData();
            fd.append('musicxml', file);
            const voicesEl = document.querySelector('input[name="voices"]:checked');
            fd.append('voices', voicesEl ? voicesEl.value : '4');
            const resp = await fetch('/realize/preview', {
                method:  'POST',
                body:    fd,
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
            });
            const data = await resp.json();
            progressWrap.classList.remove('visible');
            if (!resp.ok || data.error) { showError(data.error || 'Server error'); return; }
            currentXml      = data.xml;
            currentInputXml = data.inputXml || null;
            chordDataStore  = data.chordData || [];
            buildFbComputedFlags();
            showResult(data);
        } catch (err) {
            progressWrap.classList.remove('visible');
            showError('Network error: ' + err.message);
        }
    }

    // ── Event wiring ──────────────────────────────────────────────────────
    edScoreWrap.addEventListener('keydown', handleEdKey);
    edScoreWrap.addEventListener('click',   () => edScoreWrap.focus());

    document.querySelectorAll('.ed-dur-btn[data-dur]').forEach(btn => {
        btn.addEventListener('click', () => {
            ED.curDur = btn.dataset.dur;
            updateDurButtons();
            edScoreWrap.focus();
        });
    });
    if (edDotBtn) edDotBtn.addEventListener('click', () => {
        ED.curDot = !ED.curDot;
        updateDurButtons();
        edScoreWrap.focus();
    });
    document.querySelectorAll('.ed-acc-btn[data-acc]').forEach(btn => {
        btn.addEventListener('click', () => {
            const acc = parseInt(btn.dataset.acc, 10);
            ED.pendAcc = ED.pendAcc === acc ? null : acc;
            updateDurButtons();
            edScoreWrap.focus();
        });
    });
    if (edKeySelect) edKeySelect.addEventListener('change', () => {
        ED.key = parseInt(edKeySelect.value, 10);
        if (ED.notes.length) schedRender(); else updateStatusBar();
    });
    if (edTimeSelect) edTimeSelect.addEventListener('change', () => {
        const [b, t] = edTimeSelect.value.split('/').map(Number);
        ED.beats = b; ED.btype = t;
        if (ED.notes.length) schedRender();
    });
    if (edFigInput) {
        edFigInput.addEventListener('focus', () => { ED.figMode = true; });
        edFigInput.addEventListener('blur',  () => {
            if (figSkipBlur) { figSkipBlur = false; return; }
            applyFigMode();
        });
        edFigInput.addEventListener('keydown', e => {
            if (e.key === 'Enter')  { e.preventDefault(); applyFigMode(); }
            if (e.key === 'Escape') { e.preventDefault(); cancelFigMode(); }
        });
        edFigInput.addEventListener('input', () => {
            edFigPreview.textContent = edFigInput.value.replace(/\s+/g, '');
        });
    }
    if (edFigSet) edFigSet.addEventListener('click', () => {
        if (ED.figMode) applyFigMode();
    });
    if (edRealBtn) edRealBtn.addEventListener('click', doEditorRealize);

    // ── Init ──────────────────────────────────────────────────────────────
    updateStatusBar();
    updateDurButtons();
    schedRender(); // render the initial empty measure immediately
}()); // end initBassEditor
