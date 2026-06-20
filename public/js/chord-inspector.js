'use strict';

const ciOverlay  = document.getElementById('chord-inspector');
const ciClose    = document.getElementById('ci-close');
const ciSymbol   = document.getElementById('ci-symbol');
const ciTitleEl  = document.getElementById('ci-title-text');
const ciSubtitle = document.getElementById('ci-subtitle');
const ciInfoTbl  = document.getElementById('ci-info-table');
const ciFigStack = document.getElementById('ci-fig-stack');
const ciSrcBadge = document.getElementById('ci-source-badge-wrap');
const ciContext  = document.getElementById('ci-context');
const ciSteps    = document.getElementById('ci-steps');
const ciNoteStaff = document.getElementById('ci-note-staff');

const ciEditFigBtn = document.getElementById('ci-edit-fig');
const ciFigEditor  = document.getElementById('ci-fig-editor');
const ciFigInput   = document.getElementById('ci-fig-input');
const ciFigPreview = document.getElementById('ci-fig-preview');

function openChordInspector(cd, idx) {
    if (!cd) return;

    currentEditIdx = idx;

    // Header
    ciSymbol.textContent   = cd.chordSymbol || '?';
    ciTitleEl.textContent  = (cd.chordSymbol || '?') + ' — ' + TRANS.inspector_title;
    ciSubtitle.textContent = cd.measureNum + ' / ' + (cd.noteIndex + 1);

    // Figured bass stack — rendered via Figurato font (spaces stripped)
    const figStr = (cd.figures && cd.figures !== '(none)')
        ? cd.figures.replace(/\s+/g, '')
        : '';
    ciFigStack.textContent = figStr;

    // Show edit button only when we have editable input XML
    ciEditFigBtn.style.display = currentInputXml ? '' : 'none';

    // Close figure editor if it was open
    ciFigEditor.style.display = 'none';

    // General info
    const bass = cd.notes.bass || {};
    const figSrc = cd.figuresSource === 'computed'
        ? TRANS.figures_computed
        : TRANS.figures_from_file;

    ciInfoTbl.innerHTML = [
        [TRANS.lbl_bass_pitch,     '<span class="ci-badge">' + escapeHtml(bass.pitch || '—') + '</span>'
                                   + ' &nbsp;MIDI ' + (bass.midi ?? '—')],
        [TRANS.lbl_note_type,      escapeHtml(capitalise(bass.type || '—'))],
        [TRANS.lbl_scale_degree,   escapeHtml(cd.scaleDegName || '—')],
        [TRANS.lbl_key_ins,        escapeHtml(cd.keyDisplay || '—')],
        [TRANS.lbl_figured_bass,   '<span class="ci-badge">' + escapeHtml(cd.figures || '—') + '</span>'],
        [TRANS.lbl_chord_symbol,   escapeHtml(cd.chordSymbol || '—')],
        [TRANS.lbl_figures_source, escapeHtml(figSrc)],
    ].map(([lbl, val]) =>
        '<tr><td>' + escapeHtml(lbl) + '</td><td>' + val + '</td></tr>'
    ).join('');

    // Decision tree section
    {
        ciSrcBadge.innerHTML = '<div class="ci-source-badge">'
            + escapeHtml(cd.figuresSource === 'file' ? TRANS.badge_from_file : TRANS.badge_computed)
            + '</div>';

        // Context pills (always shown)
        ciContext.innerHTML = [
            [TRANS.ctx_scale_degree, cd.scaleDegName],
            [TRANS.ctx_motion_in,    cd.motionInDisplay],
            [TRANS.ctx_motion_out,   cd.motionOutDisplay],
            [TRANS.ctx_key,          cd.keyDisplay],
        ].map(([lbl, val]) =>
            '<span class="ci-ctx-pill"><strong>' + escapeHtml(lbl) + ':</strong> ' + escapeHtml(val) + '</span>'
        ).join('');

        // Decision steps
        const steps = cd.decisionSteps || [];
        ciSteps.innerHTML = steps.length === 0
            ? '<div style="color:var(--muted);font-size:0.83rem">' + escapeHtml(TRANS.from_file_note) + '</div>'
            : steps.map(step => {
            const isDecision = !!step.isDecision;
            const passed     = !!step.passed;
            const decisionByFail = isDecision && !passed && step.rule;

            let cls = 'ci-step';
            let icon = '';
            if (isDecision && passed) {
                cls += ' passed decision';
                icon = '✓';
            } else if (decisionByFail) {
                cls += ' failed decision';
                icon = '✗';
            } else if (passed) {
                cls += ' passed';
                icon = '✓';
            } else {
                cls += ' failed';
                icon = '✗';
            }

            let inner = '<div class="ci-step-icon">' + icon + '</div>'
                      + '<div class="ci-step-body">'
                      + '<div class="ci-step-test">' + escapeHtml(step.test || '') + '</div>';

            if (isDecision && step.rule) {
                let citHtml = '';
                const cits = step.citations || [];
                if (cits.length > 0) {
                    citHtml = '<div class="ci-citations">'
                        + cits.map(c => {
                            const sameAsTranslation = c.text === c.translation;
                            return '<div class="ci-citation">'
                                + '<div class="ci-citation-header">'
                                + '<span class="ci-citation-lang">' + escapeHtml(c.lang || '') + '</span>'
                                + '<span class="ci-citation-ref">' + escapeHtml((c.author ? c.author + '. ' : '') + (c.ref || '')) + '</span>'
                                + '</div>'
                                + '<div class="ci-citation-text">\u201c' + escapeHtml(c.text || '') + '\u201d</div>'
                                + (!sameAsTranslation && c.translation
                                    ? '<div class="ci-citation-translation">' + escapeHtml(c.translation) + '</div>'
                                    : '')
                                + '</div>';
                        }).join('')
                        + '</div>';
                }

                inner += '<div class="ci-step-rule">'
                       + '<div class="ci-step-rule-name">' + escapeHtml(step.rule) + '</div>'
                       + '<div class="ci-step-rule-src">' + escapeHtml(step.source || '') + '</div>'
                       + '<div class="ci-step-reason">' + escapeHtml(step.reason || '') + '</div>'
                       + citHtml
                       + '</div>';
            }

            inner += '</div>';
            return '<div class="' + cls + '">' + inner + '</div>';
        }).join('');
    }

    // Render 3-chord grand staff (prev · current · next) via Verovio
    renderGrandStaff(cd, idx ?? -1);

    ciOverlay.classList.add('open');
    document.body.style.overflow = 'hidden';
}

// ── Grand staff rendering ──────────────────────────────────

function buildGrandStaffXml(prevCd, currCd, nextCd, keyFifths, figures, figuresSource) {
    const COL_CURR         = '#c8a96e';
    const COL_CTX          = '#6a7a96';
    const COL_FIG_COMPUTED = '#8090a8';
    const DIV = 4;
    const TYPE_DUR = { 'whole': DIV*4, 'half': DIV*2, 'quarter': DIV,
                       'eighth': DIV/2, '16th': DIV/4, '32nd': DIV/8 };

    function chordType(cd) { return (cd && cd.notes.bass && cd.notes.bass.type) || 'quarter'; }
    function chordDur(cd)  { return TYPE_DUR[chordType(cd)] || DIV; }

    const beats = [
        { cd: prevCd, color: COL_CTX,  dur: chordDur(prevCd), type: chordType(prevCd), isCurr: false },
        { cd: currCd, color: COL_CURR, dur: chordDur(currCd), type: chordType(currCd), isCurr: true  },
        { cd: nextCd, color: COL_CTX,  dur: chordDur(nextCd), type: chordType(nextCd), isCurr: false },
    ];

    const barBefore = prevCd && prevCd.measureNum !== currCd.measureNum;
    const barAfter  = nextCd && nextCd.measureNum !== currCd.measureNum;

    let segments;
    if      ( barBefore &&  barAfter) segments = [[beats[0]], [beats[1]], [beats[2]]];
    else if ( barBefore && !barAfter) segments = [[beats[0]], [beats[1], beats[2]]];
    else if (!barBefore &&  barAfter) segments = [[beats[0], beats[1]], [beats[2]]];
    else                              segments = [[beats[0], beats[1], beats[2]]];

    function noteEl(n, b, voice, staff, isRest) {
        const colAttr = b.color ? ' color="' + b.color + '"' : '';
        const stemDir = (voice === 1 || voice === 3) ? '<stem>up</stem>' : '<stem>down</stem>';
        const dur     = '<duration>' + b.dur + '</duration><type>' + b.type + '</type>';
        if (isRest || !n) {
            return '<note' + colAttr + '><rest/>' + dur
                 + '<voice>' + voice + '</voice><staff>' + staff + '</staff></note>';
        }
        const alt = (n.alter && n.alter !== 0) ? '<alter>' + n.alter + '</alter>' : '';
        return '<note' + colAttr + '>'
             + '<pitch><step>' + n.step + '</step>' + alt + '<octave>' + n.octave + '</octave></pitch>'
             + dur + stemDir
             + '<voice>' + voice + '</voice><staff>' + staff + '</staff></note>';
    }

    const voices = [
        { key: 'soprano', v: 1, s: 1 },
        { key: 'alto',    v: 2, s: 1 },
        { key: 'tenor',   v: 3, s: 2 },
        { key: 'bass',    v: 4, s: 2 },
    ];

    const fbColor = figuresSource === 'computed' ? COL_FIG_COMPUTED : COL_CURR;
    const fbXml   = figStrToFbXml(figures, beats[1].dur, fbColor);

    let measuresXml = '';
    segments.forEach((segBeats, si) => {
        const segDur = segBeats.reduce((s, b) => s + b.dur, 0);
        const tsBeats = Math.ceil(segDur / DIV);

        measuresXml += '<measure number="' + (si + 1) + '">';

        if (si === 0) {
            measuresXml += '<attributes>'
                + '<divisions>' + DIV + '</divisions>'
                + '<key><fifths>' + keyFifths + '</fifths></key>'
                + '<time print-object="no"><beats>' + tsBeats + '</beats><beat-type>4</beat-type></time>'
                + '<staves>2</staves>'
                + '<clef number="1"><sign>G</sign><line>2</line></clef>'
                + '<clef number="2"><sign>F</sign><line>4</line></clef>'
                + '</attributes>';
        } else {
            measuresXml += '<attributes>'
                + '<time print-object="no"><beats>' + tsBeats + '</beats><beat-type>4</beat-type></time>'
                + '</attributes>';
        }

        voices.forEach((vx, vi) => {
            if (vi > 0) measuresXml += '<backup><duration>' + segDur + '</duration></backup>';
            segBeats.forEach(b => {
                if (vx.key === 'bass' && b.isCurr) measuresXml += fbXml;
                measuresXml += noteEl(b.cd && b.cd.notes[vx.key], b, vx.v, vx.s, !b.cd);
            });
        });

        measuresXml += '</measure>';
    });

    return '<?xml version="1.0" encoding="UTF-8"?>'
         + '<score-partwise version="4.0">'
         + '<part-list><score-part id="P1"><part-name print-object="no"/></score-part></part-list>'
         + '<part id="P1">' + measuresXml + '</part></score-partwise>';
}

function renderGrandStaff(cd, idx) {
    if (!ciNoteStaff) return;
    if (vrvState !== 'ready' || typeof verovio === 'undefined') {
        ciNoteStaff.style.display = 'none';
        return;
    }
    try {
        const prevCd = (idx > 0) ? chordDataStore[idx - 1] : null;
        const nextCd = (idx >= 0 && idx < chordDataStore.length - 1) ? chordDataStore[idx + 1] : null;
        const xml = buildGrandStaffXml(prevCd, cd, nextCd, cd.keyFifths ?? 0,
                                      cd.figures, cd.figuresSource);
        const miniTk = new verovio.toolkit();
        miniTk.setOptions({
            pageWidth:        1600,
            pageHeight:       2000,
            adjustPageHeight: true,
            scale:            52,
            breaks:           'none',
            noJustification:  1,
            pageMarginTop:    60,
            pageMarginBottom: 60,
            pageMarginLeft:   120,
            pageMarginRight:  120,
        });
        miniTk.loadData(xml);
        ciNoteStaff.innerHTML = miniTk.renderToSVG(1);
        ciNoteStaff.style.display = '';
    } catch (_) {
        ciNoteStaff.style.display = 'none';
    }
}

// ── Figure editor ─────────────────────────────────────────

// Parse Figurato-syntax string → [{number, alter}]
function parseFigurato(str) {
    const figures = [];
    let i = 0;
    while (i < str.length) {
        const ch = str[i];
        if (' ,\t\n\r'.includes(ch)) { i++; continue; }
        if (ch === 'i' && i + 1 < str.length && /[2-9]/.test(str[i + 1])) { i++; continue; }

        let alter = 0;
        if (str.slice(i, i + 2) === 'bb') { alter = -2; i += 2; }
        else if (ch === 'b')               { alter = -1; i++; }
        else if (ch === '#' || ch === 's') { alter =  1; i++; }
        else if (ch === 'x')               { alter =  2; i++; }
        else if (ch === 'n')               { alter =  0; i++; }
        else if (ch === '+')               { alter =  1; i++; }

        if (i < str.length) {
            const two = str.slice(i, i + 2);
            if (/^1[0-4]$/.test(two)) {
                figures.push({ number: parseInt(two, 10), alter });
                i += 2;
            } else if (/^[2-9]$/.test(str[i])) {
                figures.push({ number: parseInt(str[i], 10), alter });
                i++;
            } else {
                i++;
            }
        }

        while (i < str.length && "/\\' ".includes(str[i]) && str[i] !== ' ') i++;
    }
    return figures.filter(f => f.number >= 2);
}

// Modify figured-bass annotations in MusicXML string
function modifyFiguredBassInXml(xmlStr, measureNum, noteIndex, figures) {
    const doc = new DOMParser().parseFromString(xmlStr, 'application/xml');
    if (doc.querySelector('parsererror')) return xmlStr;

    let measure = null;
    for (const m of doc.querySelectorAll('measure')) {
        if (parseInt(m.getAttribute('number'), 10) === measureNum) { measure = m; break; }
    }
    if (!measure) return xmlStr;

    let nIdx = 0, targetNote = null;
    for (const el of Array.from(measure.children)) {
        if (el.tagName.toLowerCase() !== 'note') continue;
        if (el.querySelector('chord') || el.querySelector('rest')) continue;
        if (nIdx === noteIndex) { targetNote = el; break; }
        nIdx++;
    }
    if (!targetNote) return xmlStr;

    // Remove existing figured-bass siblings that immediately follow the note
    let sib = targetNote.nextElementSibling;
    while (sib && sib.tagName.toLowerCase() === 'figured-bass') {
        const next = sib.nextElementSibling;
        measure.removeChild(sib);
        sib = next;
    }

    // Insert new figured-bass
    if (figures && figures.length > 0) {
        const PREFIXES = { '-2': 'flat-flat', '-1': 'flat', '1': 'sharp', '2': 'double-sharp' };
        const fb = doc.createElement('figured-bass');
        figures.forEach(f => {
            const fig = doc.createElement('figure');
            if (f.alter !== 0 && PREFIXES[String(f.alter)]) {
                const pref = doc.createElement('prefix');
                pref.textContent = PREFIXES[String(f.alter)];
                fig.appendChild(pref);
            }
            const num = doc.createElement('figure-number');
            num.textContent = String(f.number);
            fig.appendChild(num);
            fb.appendChild(fig);
        });
        const nextSib = targetNote.nextSibling;
        if (nextSib) measure.insertBefore(fb, nextSib);
        else measure.appendChild(fb);
    }

    return new XMLSerializer().serializeToString(doc);
}

function openFigureEditor(idx) {
    const cd = chordDataStore[idx];
    if (!cd || !currentInputXml) return;

    const figStr = (cd.figures && cd.figures !== '(none)')
        ? cd.figures.replace(/\s+/g, '')
        : '';
    ciFigInput.value = figStr;
    ciFigPreview.textContent = figStr || ' ';
    ciFigEditor.style.display = '';
    ciFigInput.focus();
    ciFigInput.select();
}

async function applyFigureEdit() {
    const idx = currentEditIdx;
    const cd  = chordDataStore[idx];
    if (!cd || !currentInputXml) return;

    const figures     = parseFigurato(ciFigInput.value.trim());
    const modifiedXml = modifyFiguredBassInXml(currentInputXml, cd.measureNum, cd.noteIndex, figures);

    ciFigEditor.style.display = 'none';
    closeChordInspector();
    progressWrap.classList.add('visible');

    try {
        const blob       = new Blob([modifiedXml], { type: 'application/xml' });
        const file       = new File([blob], 'score.xml', { type: 'application/xml' });
        const fd         = new FormData();
        fd.append('musicxml', file);
        const voicesEl   = document.querySelector('input[name="voices"]:checked');
        fd.append('voices', voicesEl ? voicesEl.value : '4');

        const resp = await fetch('/realize/preview', {
            method: 'POST',
            body:   fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        const data = await resp.json();
        progressWrap.classList.remove('visible');

        if (!resp.ok || data.error) { showError(data.error || 'Server error'); return; }

        currentInputXml = modifiedXml;
        currentXml      = data.xml;
        chordDataStore  = data.chordData || [];
        passageStore    = data.passages  || [];
        buildFbComputedFlags();

        initScore('orig', modifiedXml, true);
        initScore('real', data.xml, true);

        downloadBtn.onclick = () => {
            const dlBlob = new Blob([data.xml], { type: 'application/vnd.recordare.musicxml+xml' });
            const url = URL.createObjectURL(dlBlob);
            const a = document.createElement('a');
            a.href = url;
            a.download = data.filename || 'continuo_realization.xml';
            a.click();
            URL.revokeObjectURL(url);
        };
    } catch (err) {
        progressWrap.classList.remove('visible');
        showError('Network error: ' + err.message);
    }
}

// Wire up editor controls
ciEditFigBtn.addEventListener('click', () => openFigureEditor(currentEditIdx));

ciFigInput.addEventListener('input', () => {
    ciFigPreview.textContent = ciFigInput.value || ' ';
});
ciFigInput.addEventListener('keydown', e => {
    if (e.key === 'Enter')  { e.preventDefault(); applyFigureEdit(); }
    if (e.key === 'Escape') { e.stopPropagation(); ciFigEditor.style.display = 'none'; }
});

document.getElementById('ci-fig-cancel').addEventListener('click', () => {
    ciFigEditor.style.display = 'none';
});
document.getElementById('ci-fig-apply').addEventListener('click', applyFigureEdit);

// ── Close chord inspector ─────────────────────────────────

function closeChordInspector() {
    ciOverlay.classList.remove('open');
    document.body.style.overflow = '';
}

ciClose.addEventListener('click', closeChordInspector);
ciOverlay.addEventListener('click', (e) => {
    if (e.target === ciOverlay) closeChordInspector();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { closeChordInspector(); }
});
