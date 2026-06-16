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

const SAMPLE_URL  = '/sample/aria_bassline.xml';
const SAMPLE_NAME = 'aria_bassline.xml';

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
            return `<div class="passage-item" style="padding:0.5rem;border:1px solid var(--border);border-radius:3px;background:var(--bg);display:flex;justify-content:space-between;align-items:center">
                <div style="flex:1">
                    <strong>Measures ${p.start_measure}–${p.end_measure}</strong>
                    <span class="passage-key" style="margin-left:0.5rem;font-family:monospace;font-size:0.85rem">${keyName}</span>
                    <span class="passage-conf ${confClass}" style="margin-left:0.5rem;font-size:0.7rem;text-transform:uppercase;opacity:0.6">${p.confidence}</span>
                </div>
                <button class="passage-edit-btn" data-idx="${idx}" style="padding:0.3rem 0.6rem;font-size:0.8rem;background:var(--accent);color:white;border:none;border-radius:2px;cursor:pointer">Edit</button>
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

function keyFifthsToName(fifths, mode) {
    const root = KEY_NAMES[fifths] || 'C';
    return mode === 'minor' ? root + 'm' : root;
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
