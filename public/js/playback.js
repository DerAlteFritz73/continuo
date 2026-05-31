'use strict';

// ── Playback bar ──────────────────────────────────────────────────────────
const pbBar     = document.getElementById('playback-bar');
const pbPlayBtn = document.getElementById('pb-play');
const pbStopBtn = document.getElementById('pb-stop');
const pbFill    = document.getElementById('pb-fill');
const pbTimeEl  = document.getElementById('pb-time');
const pbLoadEl  = document.getElementById('pb-loading');

// ── Playback knobs ────────────────────────────────────────────────────────
// Speed: symmetric log₄ scale so that ×0.25/×1.0/×4.0 sit at −135°/0°/+135°.
let pbSpeedLog  = 0;
let pbPitchSemi = 0;   // integer semitones, −12 … +12

const pbGetSpeed = () => Math.pow(4, pbSpeedLog);

// Build an SVG arc path (cx=cy=20, r=15); angles in degrees CW from 12-o'clock.
function knobArcPath(a1, a2) {
    const pt = a => {
        const r = (a - 90) * Math.PI / 180;
        return [20 + 15 * Math.cos(r), 20 + 15 * Math.sin(r)];
    };
    const [sx, sy] = pt(a1), [ex, ey] = pt(a2);
    return `M ${sx.toFixed(2)} ${sy.toFixed(2)} A 15 15 0 ${a2-a1>180?1:0} 1 ${ex.toFixed(2)} ${ey.toFixed(2)}`;
}

function updateSpeedKnob() {
    const angle = pbSpeedLog * 135;
    document.getElementById('pb-spd-notch').setAttribute('transform', `rotate(${angle.toFixed(1)},20,20)`);
    document.getElementById('pb-spd-fill').setAttribute('d',
        angle <= -135 + 0.5 ? '' : knobArcPath(-135, angle));
    document.getElementById('pb-spd-val').textContent = '×' + pbGetSpeed().toFixed(2);
}

function updatePitchKnob() {
    const angle = pbPitchSemi * (135 / 12);
    document.getElementById('pb-pit-notch').setAttribute('transform', `rotate(${angle.toFixed(1)},20,20)`);
    const a1 = Math.min(angle, 0), a2 = Math.max(angle, 0);
    document.getElementById('pb-pit-fill').setAttribute('d',
        pbPitchSemi === 0 ? '' : knobArcPath(a1, a2));
    const sign = pbPitchSemi > 0 ? '+' : (pbPitchSemi < 0 ? '' : '±');
    document.getElementById('pb-pit-val').textContent = sign + pbPitchSemi;
}

function initKnob(wrapId, getVal, onDrag, onWheel, onReset, drawFn) {
    const wrap = document.getElementById(wrapId);
    let dragging = false, startY = 0, startVal = null;
    wrap.addEventListener('pointerdown', e => {
        dragging = true; startY = e.clientY; startVal = getVal();
        wrap.setPointerCapture(e.pointerId); e.preventDefault();
    });
    wrap.addEventListener('pointermove', e => { if (dragging) onDrag(startY - e.clientY, startVal); });
    wrap.addEventListener('pointerup',   () => { dragging = false; });
    wrap.addEventListener('wheel', e => { e.preventDefault(); onWheel(e.deltaY < 0 ? 1 : -1); }, {passive:false});
    wrap.addEventListener('dblclick', onReset);
    drawFn();
}

initKnob('pb-spd-wrap', () => pbSpeedLog,
    (dy, s0) => { pbSpeedLog  = Math.max(-1,  Math.min(1,  s0 + dy / 200)); updateSpeedKnob(); },
    dir       => { pbSpeedLog  = Math.max(-1,  Math.min(1,  pbSpeedLog + dir / 8)); updateSpeedKnob(); },
    ()        => { pbSpeedLog  = 0; updateSpeedKnob(); },
    updateSpeedKnob);

initKnob('pb-pit-wrap', () => pbPitchSemi,
    (dy, s0) => { pbPitchSemi = Math.max(-12, Math.min(12, Math.round(s0 + dy / 15))); updatePitchKnob(); },
    dir       => { pbPitchSemi = Math.max(-12, Math.min(12, pbPitchSemi + dir)); updatePitchKnob(); },
    ()        => { pbPitchSemi = 0; updatePitchKnob(); },
    updatePitchKnob);

let pbAudioCtx   = null;
let pbInstrument = null;
let pbPlayer     = null;
let pbStatus     = 'stopped';   // stopped | loading | playing | paused
let pbActiveNodes = {};
let pbTickTimer  = null;

// Direct Web Audio scheduler (used for realization tab)
let pbSchedNodes    = [];
let pbSchedStart    = 0;
let pbSchedTotal    = 0;
let pbSchedEndTimer = null;

function pbFmtTime(sec) {
    const m = Math.floor(sec / 60), s = Math.floor(sec % 60);
    return m + ':' + String(s).padStart(2, '0');
}

function pbUpdateUI() {
    const playing = pbStatus === 'playing';
    const active  = pbStatus === 'playing' || pbStatus === 'paused';
    pbPlayBtn.textContent = playing ? '⏸' : '▶';
    pbPlayBtn.title       = playing ? 'Pause' : (pbStatus === 'paused' ? 'Resume' : 'Play');
    pbPlayBtn.disabled    = pbStatus === 'loading';
    pbPlayBtn.classList.toggle('pb-active', playing);
    pbStopBtn.disabled    = !active;
    pbLoadEl.style.display = pbStatus === 'loading' ? '' : 'none';
    if (pbStatus === 'stopped') {
        pbFill.style.width   = '0%';
        pbTimeEl.textContent = '0:00';
    }
}

function pbStopAllNotes() {
    Object.values(pbActiveNodes).forEach(n => { try { n.osc.stop(0); } catch (_) {} });
    pbActiveNodes = {};
}

function pbSchedStop() {
    clearTimeout(pbSchedEndTimer);
    pbSchedEndTimer = null;
    pbSchedNodes.forEach(n => { try { n.stop(0); } catch (_) {} });
    pbSchedNodes = [];
}

// Schedule all realization voices directly on the Web Audio clock
function pbScheduleRealization(chordData, bpm) {
    pbSchedStop();
    const secPerBeat = 60 / bpm;
    const gainVal    = { soprano: 0.20, alto: 0.16, tenor: 0.16, bass: 0.32 };
    const t0         = pbAudioCtx.currentTime + 0.05;
    let t            = t0;

    for (const cd of chordData) {
        const dur = ((cd.notes.bass || cd.notes.soprano)?.duration || 1) * secPerBeat;
        for (const voice of ['soprano', 'alto', 'tenor', 'bass']) {
            const n = cd.notes[voice];
            if (!n || n.isRest || !n.midi) continue;
            const freq = 440 * Math.pow(2, (n.midi + pbPitchSemi - 69) / 12);
            const osc  = pbAudioCtx.createOscillator();
            const env  = pbAudioCtx.createGain();
            osc.type = 'triangle';
            osc.frequency.setValueAtTime(freq, t);
            env.gain.setValueAtTime(0, t);
            env.gain.linearRampToValueAtTime(gainVal[voice], t + 0.008);
            env.gain.setTargetAtTime(0.0001, t + 0.012, 0.35);
            osc.connect(env);
            env.connect(pbAudioCtx.destination);
            osc.start(t);
            osc.stop(t + dur - 0.01);
            pbSchedNodes.push(osc);
        }
        t += dur;
    }

    pbSchedStart = t0;
    pbSchedTotal = t - t0;
    return pbSchedTotal;
}

function pbDoStop() {
    if (pbPlayer) pbPlayer.stop();
    pbStopAllNotes();
    pbSchedStop();
    clearInterval(pbTickTimer);
    pbTickTimer = null;
    pbStatus = 'stopped';
    pbUpdateUI();
}

function pbStartTicker(useScheduler) {
    clearInterval(pbTickTimer);
    pbTickTimer = setInterval(() => {
        if (pbStatus !== 'playing') return;
        if (useScheduler) {
            const elapsed = pbAudioCtx.currentTime - pbSchedStart;
            if (pbSchedTotal > 0) {
                pbFill.style.width = Math.min(elapsed / pbSchedTotal * 100, 100).toFixed(1) + '%';
                pbTimeEl.textContent = pbFmtTime(elapsed);
            }
        } else {
            if (!pbPlayer) return;
            const total = pbPlayer.totalTicks;
            const curr  = pbPlayer.getCurrentTick();
            if (total > 0) pbFill.style.width = (curr / total * 100).toFixed(1) + '%';
            try {
                const bpm = pbPlayer.tempo || 120;
                const ppq = pbPlayer.division || 480;
                pbTimeEl.textContent = pbFmtTime((curr / ppq) * (60 / bpm));
            } catch (_) {}
        }
    }, 100);
}

// Lazy-load a CDN script (idempotent)
function loadScript(src) {
    return new Promise((resolve, reject) => {
        if (document.querySelector('script[src="' + src + '"]')) { resolve(); return; }
        const s = document.createElement('script');
        s.src = src; s.onload = resolve; s.onerror = reject;
        document.head.appendChild(s);
    });
}

// ── Web Audio synthesiser ─────────────────────────────────
// Triangle-wave oscillator with fast attack and exponential decay.
function pbNoteOn(key, noteNum, velocity) {
    pbNoteOff(key);
    const freq = 440 * Math.pow(2, (noteNum + pbPitchSemi - 69) / 12);
    const now  = pbAudioCtx.currentTime;
    const osc  = pbAudioCtx.createOscillator();
    const env  = pbAudioCtx.createGain();
    osc.type = 'triangle';
    osc.frequency.setValueAtTime(freq, now);
    env.gain.setValueAtTime(0, now);
    env.gain.linearRampToValueAtTime((velocity / 127) * 0.25, now + 0.006);
    env.gain.setTargetAtTime(0.0001, now + 0.01, 0.35);
    osc.connect(env);
    env.connect(pbAudioCtx.destination);
    osc.start(now);
    osc.stop(now + 2.5);
    pbActiveNodes[key] = { osc, env };
}

function pbNoteOff(key) {
    const node = pbActiveNodes[key];
    if (!node) return;
    const now = pbAudioCtx.currentTime;
    try {
        node.env.gain.cancelScheduledValues(now);
        node.env.gain.setValueAtTime(node.env.gain.value, now);
        node.env.gain.exponentialRampToValueAtTime(0.0001, now + 0.08);
        node.osc.stop(now + 0.08);
    } catch (_) {}
    delete pbActiveNodes[key];
}

async function pbEnsureReady() {
    if (typeof MidiPlayer === 'undefined') {
        await loadScript('/midiplayer.js');
    }
    if (!pbAudioCtx) {
        pbAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
    }
    if (pbAudioCtx.state === 'suspended') await pbAudioCtx.resume();
    if (!pbPlayer) {
        pbPlayer = new MidiPlayer.Player(event => {
            if (!pbAudioCtx) return;
            const key = (event.channel ?? 1) + '_' + event.noteNumber;
            if (event.name === 'Note on' && event.velocity > 0) {
                pbNoteOn(key, event.noteNumber, event.velocity);
            } else if (event.name === 'Note off' ||
                       (event.name === 'Note on' && event.velocity === 0)) {
                pbNoteOff(key);
            }
        });
        pbPlayer.on('endOfFile', () => {
            pbStopAllNotes();
            clearInterval(pbTickTimer);
            pbStatus = 'stopped';
            pbUpdateUI();
        });
    }
}

function pbGetActiveTk() {
    const tab = document.querySelector('.score-tab.active');
    const key = (tab && tab.dataset.pane === 'pane-real') ? 'real' : 'orig';
    return scores[key].tk || null;
}

function b64ToArrayBuffer(b64) {
    const bin = atob(b64);
    const buf = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) buf[i] = bin.charCodeAt(i);
    return buf.buffer;
}

pbPlayBtn.addEventListener('click', async () => {
    if (pbStatus === 'playing') {
        pbDoStop();
        return;
    }
    const tab    = document.querySelector('.score-tab.active');
    const isReal = tab && tab.dataset.pane === 'pane-real';
    const tk     = pbGetActiveTk();
    if (!isReal && !tk) return;
    pbStatus = 'loading';
    pbUpdateUI();
    try {
        await pbEnsureReady();
        if (isReal && chordDataStore.length > 0) {
            const totalSec = pbScheduleRealization(chordDataStore, 120 * pbGetSpeed());
            pbStatus = 'playing';
            pbStartTicker(true);
            pbSchedEndTimer = setTimeout(() => {
                pbSchedNodes = [];
                pbStatus = 'stopped';
                pbUpdateUI();
                clearInterval(pbTickTimer);
            }, Math.ceil(totalSec * 1000) + 200);
        } else if (tk) {
            await pbEnsureReady();
            pbPlayer.loadArrayBuffer(b64ToArrayBuffer(tk.renderToMIDI()));
            pbPlayer.setTempo((pbPlayer.tempo || 120) * pbGetSpeed());
            pbPlayer.play();
            pbStatus = 'playing';
            pbStartTicker(false);
        } else {
            pbStatus = 'stopped';
        }
    } catch (err) {
        console.error('Playback error:', err);
        pbStatus = 'stopped';
    }
    pbUpdateUI();
});

pbStopBtn.addEventListener('click', pbDoStop);

// Click on progress bar to seek
document.getElementById('pb-progress').addEventListener('click', e => {
    if (!pbPlayer || pbStatus === 'stopped') return;
    const rect = e.currentTarget.getBoundingClientRect();
    const pct  = (e.clientX - rect.left) / rect.width;
    pbPlayer.skipToPercent(pct * 100);
});

// Stop playback on tab switch
document.querySelectorAll('.score-tab').forEach(tab => {
    tab.addEventListener('click', pbDoStop);
});

// Called from initScore when a score finishes loading
function pbOnScoreReady() {
    pbBar.style.display = '';
    pbPlayBtn.disabled  = false;
    pbUpdateUI();
}
