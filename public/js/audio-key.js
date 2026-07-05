'use strict';

// Audio tonality detection: upload an audio file, POST it to /detect-audio-key,
// and render the global key plus a local-key timeline. Independent of the
// MusicXML realization flow above it.
(function () {
    const form        = document.getElementById('audio-key-form');
    if (!form) return;

    const dropZone    = document.getElementById('audio-drop-zone');
    const fileInput   = document.getElementById('audio-file-input');
    const detectBtn   = document.getElementById('audio-detect-btn');
    const nameDisplay = document.getElementById('audio-file-name-display');
    const nameText    = document.getElementById('audio-file-name-text');
    const progress    = document.getElementById('audio-key-progress');
    const errorBox    = document.getElementById('audio-key-error');
    const resultBox   = document.getElementById('audio-key-result');
    const globalKey   = document.getElementById('audio-global-key');
    const globalMeta  = document.getElementById('audio-global-meta');
    const timelineBody= document.getElementById('audio-timeline-body');

    function setFile(file) {
        if (!file) return;
        const dt = new DataTransfer();
        dt.items.add(file);
        fileInput.files = dt.files;
        nameText.textContent = file.name;
        nameDisplay.classList.add('visible');
        dropZone.classList.add('has-file');
        detectBtn.disabled = false;
    }

    fileInput.addEventListener('change', () => setFile(fileInput.files[0]));

    ['dragover', 'dragenter'].forEach(ev =>
        dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.add('drag-over'); }));
    ['dragleave', 'drop'].forEach(ev =>
        dropZone.addEventListener(ev, e => { e.preventDefault(); dropZone.classList.remove('drag-over'); }));
    dropZone.addEventListener('drop', e => {
        if (e.dataTransfer.files.length) setFile(e.dataTransfer.files[0]);
    });

    // Colour a confidence value the same way the rest of the app reads them.
    function confClass(conf) {
        return conf === 'high' ? 'ak-high' : conf === 'medium' ? 'ak-med' : 'ak-low';
    }

    function render(data) {
        globalKey.textContent  = data.global.label;
        globalKey.className    = 'ak-key ' + confClass(data.global.confidence);
        globalMeta.textContent =
            `r = ${data.global.correlation.toFixed(3)} · ${data.global.confidence} · ${data.duration.toFixed(0)}s`;

        timelineBody.innerHTML = '';
        for (const seg of data.timeline) {
            const tr = document.createElement('tr');
            const cells = [
                `${seg.start.toFixed(1)}–${seg.end.toFixed(1)}`,
                seg.key.label,
                seg.key.correlation.toFixed(3),
                seg.key.confidence,
            ];
            cells.forEach((text, i) => {
                const td = document.createElement('td');
                td.textContent = text;
                if (i === 1) td.className = confClass(seg.key.confidence);
                tr.appendChild(td);
            });
            timelineBody.appendChild(tr);
        }
        resultBox.style.display = 'block';
    }

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        if (!fileInput.files.length) return;

        errorBox.style.display  = 'none';
        resultBox.style.display = 'none';
        progress.style.display  = 'flex';
        detectBtn.disabled      = true;

        try {
            const resp = await fetch(form.action, { method: 'POST', body: new FormData(form) });
            const data = await resp.json();
            if (!resp.ok || data.error) {
                throw new Error(data.error || `HTTP ${resp.status}`);
            }
            render(data);
        } catch (err) {
            errorBox.textContent   = err.message;
            errorBox.style.display = 'block';
        } finally {
            progress.style.display = 'none';
            detectBtn.disabled     = false;
        }
    });
})();
