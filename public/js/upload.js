'use strict';

const dropZone    = document.getElementById('drop-zone');
const fileInput   = document.getElementById('file-input');

// ── Params panel toggle ───────────────────────────────────────────────────
const paramsBtn   = document.getElementById('params-btn');
const paramsPanel = document.getElementById('params-panel');
paramsBtn.addEventListener('click', () => {
    const open = paramsPanel.style.display === 'none';
    paramsPanel.style.display = open ? '' : 'none';
    paramsBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
});

const realizeBtn  = document.getElementById('realize-btn');
const clearBtn    = document.getElementById('clear-btn');
const fileNameEl  = document.getElementById('file-name-display');
const fileNameTxt = document.getElementById('file-name-text');
const progressWrap= document.getElementById('progress-wrap');
const resultPanel = document.getElementById('result-panel');
const summaryGrid = document.getElementById('summary-grid');
const xmlPreview  = document.getElementById('xml-preview');
const downloadBtn = document.getElementById('download-btn');
const newBtn      = document.getElementById('new-btn');
const chordSection= document.getElementById('chord-section');
const chordPills  = document.getElementById('chord-pills');
const form        = document.getElementById('upload-form');
const sampleHint  = document.getElementById('sample-hint');

const SAMPLE_URL  = form.dataset.sampleUrl;
const SAMPLE_NAME = form.dataset.sampleName;

// ── Load sample on page load ──────────────────────────────
async function loadSample() {
    try {
        const resp = await fetch(SAMPLE_URL);
        if (!resp.ok) return;
        const blob = await resp.blob();
        const file = new File([blob], SAMPLE_NAME, { type: 'application/xml' });
        setFile(file);
    } catch (_) { /* silently ignore */ }
}
loadSample();

// ── "Load sample file" link ───────────────────────────────
const loadSampleBtn = document.getElementById('load-sample-btn');
if (loadSampleBtn) {
    loadSampleBtn.addEventListener('click', ev => {
        ev.preventDefault();
        loadSample();
    });
}

// ── Drag & Drop ───────────────────────────────────────────
['dragenter','dragover'].forEach(e => {
    dropZone.addEventListener(e, ev => {
        ev.preventDefault();
        dropZone.classList.add('drag-over');
    });
});
['dragleave','drop'].forEach(e => {
    dropZone.addEventListener(e, ev => {
        ev.preventDefault();
        dropZone.classList.remove('drag-over');
    });
});
dropZone.addEventListener('drop', ev => {
    const file = ev.dataTransfer.files[0];
    if (file) setFile(file);
});
fileInput.addEventListener('change', () => {
    if (fileInput.files[0]) setFile(fileInput.files[0]);
});

function setFile(file) {
    currentFile = file;
    // Swap the input files via DataTransfer
    const dt = new DataTransfer();
    dt.items.add(file);
    fileInput.files = dt.files;

    dropZone.classList.add('has-file');
    fileNameTxt.textContent = file.name + ' (' + (file.size / 1024).toFixed(1) + ' KB)';
    fileNameEl.classList.add('visible');
    realizeBtn.disabled = false;
    clearBtn.style.display = '';
    if (sampleHint) sampleHint.style.display = 'none';
}

function clearFile() {
    fileInput.value = '';
    currentFile = null;
    currentXml  = null;
    dropZone.classList.remove('has-file');
    fileNameEl.classList.remove('visible');
    realizeBtn.disabled = true;
    clearBtn.style.display = 'none';
    if (sampleHint) sampleHint.style.display = '';
    resultPanel.classList.remove('visible');
    resultPanel.style.display = 'none';
}
clearBtn.addEventListener('click', clearFile);
newBtn.addEventListener('click',  clearFile);

// ── Form submit → AJAX ────────────────────────────────────
form.addEventListener('submit', async ev => {
    ev.preventDefault();
    if (!currentFile) return;

    // Show spinner, hide result
    progressWrap.classList.add('visible');
    realizeBtn.disabled = true;
    resultPanel.classList.remove('visible');
    resultPanel.style.display = 'none';

    try {
        const fd = new FormData(form);

        const resp = await fetch(form.action, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        const data = await resp.json();
        progressWrap.classList.remove('visible');

        if (!resp.ok || data.error) {
            showError(data.error || 'Server error');
            realizeBtn.disabled = false;
            return;
        }

        currentXml      = data.xml;
        currentInputXml = data.inputXml || null;
        chordDataStore  = data.chordData || [];
        passageStore    = data.passages  || [];
        buildFbComputedFlags();
        showResult(data);
    } catch (err) {
        progressWrap.classList.remove('visible');
        showError('Network error: ' + err.message);
        realizeBtn.disabled = false;
    }
});

function showError(msg) {
    const existing = document.querySelector('.alert-error.dynamic');
    if (existing) existing.remove();

    const el = document.createElement('div');
    el.className = 'alert alert-error dynamic';
    el.innerHTML = '<span>⚠</span><span>' + escapeHtml(msg) + '</span>';
    form.insertBefore(el, form.firstChild);
}

function showResult(data) {
    const s = data.summary || {};

    // Summary grid
    const items = [
        { val: escapeHtml(s.title    || '—'), lbl: TRANS.lbl_title },
        { val: escapeHtml(s.key      || '—'), lbl: TRANS.lbl_key },
        { val: s.measures   || '—',           lbl: TRANS.lbl_measures },
        { val: s.totalNotes || '—',           lbl: TRANS.lbl_notes },
    ];
    summaryGrid.innerHTML = items.map(i =>
        `<div class="summary-item"><span class="val">${i.val}</span><span class="lbl">${i.lbl}</span></div>`
    ).join('');

    // Passages panel
    const passages = data.passages || [];
    const passagesPanel = document.getElementById('passages-panel');
    const passagesList = document.getElementById('passages-list');
    if (passages.length > 0) {
        passagesList.innerHTML = passages.map((p, idx) => {
            const keyName = keyFifthsToName(p.key.fifths, p.key.mode);
            const confClass = 'conf-' + p.confidence;
            // A confirmed leading-tone resolution (raised ^7 → tonic in the
            // realized voices) upgrades the label and lights up an accent marker.
            const lt = p.leadingTone
                ? `<span class="passage-lt" title="Realized leading tone resolves ♯7→1̂" style="margin-left:0.35rem;font-size:0.7rem;color:var(--accent);font-weight:600">♯7→1̂</span>`
                : '';
            const cadence = p.cadence
                ? `<span class="passage-cadence" style="margin-left:0.5rem;font-size:0.7rem;text-transform:uppercase;opacity:0.6">⟂ ${escapeHtml(p.cadence)}</span>${lt}`
                : '';
            const colour = passageColor(idx);
            return `<div class="passage-item" id="passage-item-${idx}" data-idx="${idx}" style="padding:0.5rem;border:1px solid var(--border);border-left:3px solid ${colour};border-radius:3px;background:var(--bg);scroll-margin-top:0.75rem">
                <div style="display:flex;justify-content:space-between;align-items:center">
                    <div style="flex:1">
                        <span class="passage-swatch" style="display:inline-block;width:0.7rem;height:0.7rem;border-radius:2px;background:${colour};vertical-align:middle;margin-right:0.4rem"></span>
                        <strong>Measures ${p.start_measure}–${p.end_measure}</strong>
                        <span class="passage-key" style="margin-left:0.5rem;font-family:monospace;font-size:0.85rem">${keyName}</span>
                        <span class="passage-conf ${confClass}" style="margin-left:0.5rem;font-size:0.7rem;text-transform:uppercase;opacity:0.6">${p.confidence}</span>
                        ${cadence}
                        ${passagePatternsHtml(p)}
                        ${passageSuspensionsHtml(p)}
                    </div>
                    <button class="passage-edit-btn" data-idx="${idx}" style="padding:0.3rem 0.6rem;font-size:0.8rem;background:var(--accent);color:white;border:none;border-radius:2px;cursor:pointer">Edit</button>
                </div>
                ${passageProgressionHtml(p, keyName)}
                ${passageTraceHtml(p)}
            </div>`;
        }).join('');
        passagesPanel.style.display = '';
    } else {
        passagesPanel.style.display = 'none';
    }

    // Passages edit button handlers
    document.querySelectorAll('.passage-edit-btn').forEach(btn => {
        btn.addEventListener('click', () => {
            const idx = parseInt(btn.dataset.idx);
            editPassageKey(passages[idx], idx);
        });
    });

    // Chord pills
    const topChords = s.topChords || {};
    const chordKeys = Object.keys(topChords);
    if (chordKeys.length) {
        chordSection.style.display = '';
        chordPills.innerHTML = chordKeys.map(k =>
            `<span class="chord-pill">${escapeHtml(k)}<span class="count">×${topChords[k]}</span></span>`
        ).join('');
    } else {
        chordSection.style.display = 'none';
    }

    // XML preview (first 40 lines)
    const lines = (data.xml || '').split('\n').slice(0, 40).join('\n');
    xmlPreview.textContent = lines + (data.xml.split('\n').length > 40 ? '\n…' : '');

    // Show panel
    resultPanel.style.display = 'block';
    resultPanel.classList.add('visible');
    resultPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });

    // Download button
    downloadBtn.onclick = () => {
        const blob = new Blob([data.xml], { type: 'application/vnd.recordare.musicxml+xml' });
        const url  = URL.createObjectURL(blob);
        const a    = document.createElement('a');
        a.href     = url;
        a.download = data.filename || 'continuo_realization.xml';
        a.click();
        URL.revokeObjectURL(url);
    };

    // Score viewer (runs after panel is visible so widths are available)
    if (data.inputXml && data.xml) {
        setTimeout(() => launchScoreViewer(data.inputXml, data.xml), 50);
    }
}

// ── Accordion ─────────────────────────────────────────────
document.querySelectorAll('details.accordion').forEach(details => {
    const chevron = details.querySelector('.accordion-chevron');
    const body    = details.querySelector('.accordion-body');
    details.addEventListener('toggle', () => {
        if (details.open) {
            chevron && chevron.classList.add('open');
            body    && body.classList.add('open');
        } else {
            chevron && chevron.classList.remove('open');
            body    && body.classList.remove('open');
        }
    });
});

// ── Passages helper functions ──────────────────────────────────────────────
const KEY_NAMES = {
    '-7': 'Cb', '-6': 'Gb', '-5': 'Db', '-4': 'Ab', '-3': 'Eb', '-2': 'Bb', '-1': 'F',
    '0': 'C', '1': 'G', '2': 'D', '3': 'A', '4': 'E', '5': 'B', '6': 'F#', '7': 'C#'
};

// A given signature names a different tonic in minor (its relative minor).
const KEY_NAMES_MINOR = {
    '-7': 'Ab', '-6': 'Eb', '-5': 'Bb', '-4': 'F', '-3': 'C', '-2': 'G', '-1': 'D',
    '0': 'A', '1': 'E', '2': 'B', '3': 'F#', '4': 'C#', '5': 'G#', '6': 'D#', '7': 'A#'
};

function keyFifthsToName(fifths, mode) {
    if (mode === 'minor') return (KEY_NAMES_MINOR[fifths] || 'A') + 'm';
    return KEY_NAMES[fifths] || 'C';
}

const PC_NAMES = ['C', 'C♯', 'D', 'D♯', 'E', 'F', 'F♯', 'G', 'G♯', 'A', 'A♯', 'B'];

// Human labels for the baroque sequential patterns.
const SEQUENCE_LABELS = {
    circle_of_fifths: 'circle of 5ths',
    descending_steps: 'descending steps',
    ascending_steps:  'ascending steps',
};

// The Roman-numeral progression read from the source figured bass, prefixed by
// the local tonic so it reads as real functional harmony (e.g. "e: i – V⁷ – i").
function passageProgressionHtml(p, keyName) {
    const prog = p.progression || [];
    if (!prog.length) return '';
    const tonic = keyName.replace(/m$/, '').toLowerCase(); // "Em" → "e", "G" → "g"
    const chords = prog.map(c => {
        const applied = c.applied ? 'color:var(--accent)' : '';
        return `<span class="rn" style="${applied}">${escapeHtml(c.roman)}</span>`;
    }).join('<span style="opacity:0.35"> – </span>');
    return `<div class="passage-progression" style="margin-top:0.4rem;font-family:'Georgia',serif;font-size:0.92rem;line-height:1.7;letter-spacing:0.02em">
        <span style="opacity:0.55;font-family:monospace">${escapeHtml(tonic)}:</span> ${chords}
    </div>`;
}

// Suspension badges (held-note dissonances resolving down by step).
function passageSuspensionsHtml(p) {
    const susp = p.suspensions || [];
    if (!susp.length) return '';
    const seen = new Set();
    return susp.filter(x => !seen.has(x.type) && seen.add(x.type)).map(x =>
        `<span class="passage-susp" title="Suspension ${escapeHtml(x.type)} (dissonance resolving down by step)" style="margin-left:0.5rem;font-size:0.7rem;padding:0.05rem 0.35rem;border:1px solid var(--border);border-radius:2px;opacity:0.75">⤵ ${escapeHtml(x.type)}</span>`
    ).join('');
}

// Sequential-pattern badges shown next to the cadence.
function passagePatternsHtml(p) {
    const pats = p.patterns || [];
    if (!pats.length) return '';
    // De-duplicate by type; a phrase rarely needs the same label twice.
    const seen = new Set();
    return pats.filter(x => !seen.has(x.type) && seen.add(x.type)).map(x =>
        `<span class="passage-pattern" style="margin-left:0.5rem;font-size:0.7rem;padding:0.05rem 0.35rem;border:1px solid var(--border);border-radius:2px;opacity:0.75">↻ ${escapeHtml(SEQUENCE_LABELS[x.type] || x.type)}</span>`
    ).join('');
}

// Expandable "why this key?" decision trace for a detected passage: the phrase
// boundary, the weighted pitch-class evidence, the ranked Krumhansl–Schmuckler
// candidate correlations, and the confidence reasoning.
function passageTraceHtml(p) {
    const t = p.key_trace;
    if (!t || !t.candidates || !t.candidates.length) return '';

    const boundary = p.boundary === 'cadence'
        ? 'cadence' + (p.cadence ? ' (' + escapeHtml(p.cadence) + ')' : '')
            + (p.leadingTone ? ' — leading tone ♯7→1̂ confirmed in realization' : '')
        : p.boundary === 'key-change'  ? 'explicit key change'
        : p.boundary === 'end-of-piece' ? 'end of piece'
        : '—';

    const profile = (t.profile || [])
        .map(e => (PC_NAMES[e.pc] || '?') + ' ' + e.pct + '%')
        .join('   ');

    const cands = t.candidates.map(c => {
        const name = keyFifthsToName(c.fifths, c.mode);
        const style = c.winner ? 'font-weight:700;color:var(--accent)' : 'opacity:0.7';
        const boost = c.boosted ? ' <span style="opacity:0.6">+cadence</span>' : '';
        return `<li style="${style}">${escapeHtml(name)} — r=${c.correlation.toFixed(3)}${boost}${c.winner ? ' ✓' : ''}</li>`;
    }).join('');

    // Cadential prior: the tonic implied by the closing cadence, added on top of
    // the raw histogram correlation — the strongest baroque key cue.
    const cp = t.cadence_prior;
    const priorHtml = cp
        ? `<div style="margin-top:0.3rem"><strong>Cadential evidence:</strong> ${escapeHtml(cp.reason)} cadence implies <strong>${escapeHtml(cp.name)}</strong> — boosted by +${(cp.bonus ?? 0).toFixed(2)}</div>`
        : '';

    // Sequential patterns found in the bass of this phrase.
    const pats = (p.patterns || []);
    const seenP = new Set();
    const patList = pats.filter(x => !seenP.has(x.type) && seenP.add(x.type))
        .map(x => `${escapeHtml(SEQUENCE_LABELS[x.type] || x.type)} (mm ${x.start_measure}–${x.end_measure})`).join(', ');
    const patHtml = patList
        ? `<div style="margin-top:0.3rem"><strong>Sequential patterns:</strong> ${patList}</div>`
        : '';

    // Suspensions found in the figured bass of this phrase.
    const susp = (p.suspensions || []);
    const suspHtml = susp.length
        ? `<div style="margin-top:0.3rem"><strong>Suspensions:</strong> ${
            susp.map(s => `${escapeHtml(s.type)} (m ${s.measure})`).join(', ')}</div>`
        : '';

    return `<details class="passage-trace" style="margin-top:0.45rem">
        <summary style="cursor:pointer;font-size:0.78rem;opacity:0.75">Why this key?</summary>
        <div style="margin-top:0.4rem;font-size:0.78rem;line-height:1.55">
            <div><strong>Phrase boundary:</strong> ${boundary}</div>
            <div><strong>Weighted pitch-class profile:</strong> <span style="font-family:monospace">${escapeHtml(profile)}</span></div>
            <div style="margin-top:0.3rem"><strong>Krumhansl–Schmuckler correlation:</strong></div>
            <ul style="margin:0.2rem 0 0.25rem 1.1rem;padding:0;font-family:monospace;list-style:none">${cands}</ul>
            <div><strong>Margin over runner-up:</strong> ${(t.margin ?? 0).toFixed(3)}</div>
            ${priorHtml}
            ${patHtml}
            ${suspHtml}
            <div style="margin-top:0.3rem"><strong>Confidence:</strong> ${escapeHtml(t.confidence || '')}</div>
        </div>
    </details>`;
}

function editPassageKey(passage, idx) {
    const currentKey = keyFifthsToName(passage.key.fifths, passage.key.mode);
    const options = [
        'C major', 'G major', 'D major', 'A major', 'E major', 'B major', 'F# major', 'C# major',
        'F major', 'Bb major', 'Eb major', 'Ab major', 'Db major', 'Gb major', 'Cb major',
        'A minor', 'E minor', 'B minor', 'F# minor', 'C# minor', 'G# minor', 'D# minor',
        'D minor', 'G minor', 'C minor', 'F minor', 'Bb minor', 'Eb minor', 'Ab minor',
    ];

    const newKey = prompt(`Change key for passage ${passage.start_measure}–${passage.end_measure}?\nCurrent: ${currentKey}\n\nEnter new key:`, currentKey);
    if (newKey) {
        // User changed key - in a full implementation, would re-send to server
        alert(`Key changed to ${newKey}. (Note: This is a preview. To persist, re-upload after making changes in notation software.)`);
    }
}

// ── Input card tab switching ──────────────────────────────────────────────
document.querySelectorAll('#input-tabs .input-tab').forEach(tab => {
    tab.addEventListener('click', () => {
        document.querySelectorAll('#input-tabs .input-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('#input-card .input-pane').forEach(p => p.classList.remove('active'));
        tab.classList.add('active');
        const pane = document.getElementById(tab.dataset.pane);
        if (pane) pane.classList.add('active');
    });
});
