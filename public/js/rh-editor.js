'use strict';
// ── Right-hand realization editor ────────────────────────────────────────────
// Click a coloured RH notehead (or arrow to it) to select it, then adjust the
// pitch with the keyboard or the popover — Finale "Speedy Entry"-flavoured.
// Edits are applied directly to the realization MusicXML (currentXml) and the
// score is re-rendered, so a manual override survives until the piece is
// re-realized. Voice identity/colour comes from the note id (chord-N[-alto|tenor]).

const RH_STEP_SEMI = { C: 0, D: 2, E: 4, F: 5, G: 7, A: 9, B: 11 };
// pitch-class → [step, alter] spelling (sharps preferred)
const RH_PC_SPELL = [
    ['C', 0], ['C', 1], ['D', 0], ['D', 1], ['E', 0], ['F', 0],
    ['F', 1], ['G', 0], ['G', 1], ['A', 0], ['A', 1], ['B', 0],
];
const RH_ACC_NAME = { '-2': 'double-flat', '-1': 'flat', '0': 'natural', '1': 'sharp', '2': 'double-sharp' };

let rhEditMode = false;
let rhSelId    = null;      // id of the selected note element, e.g. "chord-42-alto"
let rhDoc      = null;      // parsed realization MusicXML (kept in sync with currentXml)

function rhMidiOf(p) { return (p.octave + 1) * 12 + RH_STEP_SEMI[p.step] + p.alter; }
function rhSpell(midi) {
    const pc = ((midi % 12) + 12) % 12;
    const [step, alter] = RH_PC_SPELL[pc];
    return { step, alter, octave: Math.floor(midi / 12) - 1 };
}
function rhVoiceOf(id) { return (typeof voiceOfNoteId === 'function') ? voiceOfNoteId(id) : null; }

// Parse currentXml into a working DOM. Called when edit mode is enabled (fresh
// each time, so a re-realization is picked up).
function rhParseDoc() {
    if (!currentXml) { rhDoc = null; return; }
    rhDoc = new DOMParser().parseFromString(currentXml, 'application/xml');
}
function rhFindNote(id) {
    return rhDoc ? rhDoc.querySelector('note[id="' + (window.CSS && CSS.escape ? CSS.escape(id) : id) + '"]') : null;
}
function rhGetPitch(id) {
    const n = rhFindNote(id);
    const p = n && n.querySelector('pitch');
    if (!p) return null;
    return {
        step:   p.querySelector('step').textContent.trim(),
        alter:  parseInt(p.querySelector('alter')?.textContent || '0', 10),
        octave: parseInt(p.querySelector('octave').textContent, 10),
    };
}

// Write a new pitch (step/alter/octave) into the note, keeping the <accidental>
// display element in sync. Returns false for rests / missing notes.
function rhSetPitch(id, step, alter, octave) {
    const note = rhFindNote(id);
    const pitch = note && note.querySelector('pitch');
    if (!pitch) return false;

    pitch.querySelector('step').textContent = step;
    pitch.querySelector('octave').textContent = String(octave);
    let alt = pitch.querySelector('alter');
    if (alter !== 0) {
        if (!alt) { alt = rhDoc.createElement('alter'); pitch.insertBefore(alt, pitch.querySelector('octave')); }
        alt.textContent = String(alter);
    } else if (alt) {
        alt.remove();
    }

    // Accidental display element (after <type> per MusicXML order).
    let acc = note.querySelector('accidental');
    if (alter !== 0) {
        if (!acc) {
            acc = rhDoc.createElement('accidental');
            const typeEl = note.querySelector('type');
            if (typeEl && typeEl.nextSibling) note.insertBefore(acc, typeEl.nextSibling);
            else note.appendChild(acc);
        }
        acc.textContent = RH_ACC_NAME[String(alter)];
    } else if (acc) {
        acc.remove();
    }
    return true;
}

// Serialize the working DOM back into currentXml and re-render the realization,
// then restore the selection highlight + popover on the same note id.
//
// Fast path: reuse the existing Verovio toolkit (loadData + render the current
// page only) instead of rebuilding it and re-paginating the whole score — the
// difference between a sluggish and an instant edit, so many keystrokes in a row
// stay snappy (Finale "Speedy Entry" feel). Falls back to a full initScore.
async function rhApply() {
    currentXml = new XMLSerializer().serializeToString(rhDoc);
    const s = (typeof scores !== 'undefined') ? scores.real : null;
    if (s && s.tk) {
        try {
            s.tk.loadData(currentXml);
            s.total = s.tk.getPageCount() || s.total;
            if (s.page > s.total) s.page = s.total;
            renderScorePage('real');
        } catch (e) {
            await initScore('real', currentXml, true);
        }
    } else {
        await initScore('real', currentXml, true);
    }
    requestAnimationFrame(() => { rhHighlight(); rhShowPopover(); });
}

// Key signature of the realization (first <fifths>), for in-key diatonic steps.
function rhKeyFifths() {
    const f = rhDoc && rhDoc.querySelector('key fifths');
    return f ? parseInt(f.textContent, 10) : 0;
}
// The accidental a key signature puts on a given note letter.
function rhKeyAlter(letter, fifths) {
    const sharps = ['F', 'C', 'G', 'D', 'A', 'E', 'B'];
    const flats  = ['B', 'E', 'A', 'D', 'G', 'C', 'F'];
    if (fifths > 0) return sharps.slice(0, fifths).includes(letter) ? 1 : 0;
    if (fifths < 0) return flats.slice(0, -fifths).includes(letter) ? -1 : 0;
    return 0;
}
// Move the note one diatonic step (staff position) up/down, taking the key
// signature's accidental for the new letter — the Finale arrow-key behaviour.
function rhDiatonic(dir) {
    if (!rhSelId) return;
    const p = rhGetPitch(rhSelId);
    if (!p) return;
    const letters = ['C', 'D', 'E', 'F', 'G', 'A', 'B'];
    let li = letters.indexOf(p.step), oct = p.octave;
    li += dir;
    if (li > 6) { li = 0; oct++; }
    if (li < 0) { li = 6; oct--; }
    const letter = letters[li];
    if (rhSetPitch(rhSelId, letter, rhKeyAlter(letter, rhKeyFifths()), oct)) rhApply();
}

// ── Duration editing (Finale number keys) ────────────────────────────────────
// Duration presets: number key → [type, ratio-relative-to-a-quarter].
const RH_DUR = {
    '1': ['64th', 1 / 16], '2': ['32nd', 1 / 8], '3': ['16th', 1 / 4], '4': ['eighth', 1 / 2],
    '5': ['quarter', 1], '6': ['half', 2], '7': ['whole', 4], '8': ['breve', 8],
};
// Divisions of the REALIZATION part (P1). NB the melody part (PMEL) comes first
// in the document and may use a different value, so scope the lookup to P1.
function rhDivisions() {
    const d = rhDoc && (rhDoc.querySelector('part[id="P1"] divisions') || rhDoc.querySelector('divisions'));
    return d ? parseInt(d.textContent, 10) : 8;
}

// Entries of one voice in a measure, each = a primary note/rest plus its
// <chord/> members, with its onset in ticks. A global cursor tracks position
// across backup/forward so any voice's onsets come out right.
function rhVoiceEntries(measureEl, voice) {
    const entries = []; let onset = 0, cur = null;
    for (const ch of Array.from(measureEl.children)) {
        if (ch.tagName === 'backup')  { onset -= +(ch.querySelector('duration')?.textContent || 0); cur = null; continue; }
        if (ch.tagName === 'forward') { onset += +(ch.querySelector('duration')?.textContent || 0); cur = null; continue; }
        if (ch.tagName !== 'note') continue;
        const isChord = !!ch.querySelector('chord');
        const dur = +(ch.querySelector('duration')?.textContent || 0);
        const v = ch.querySelector('voice')?.textContent || '1';
        if (v === voice) {
            if (isChord && cur) cur.members.push(ch);
            else { cur = { onset, primary: ch, members: [], dur }; entries.push(cur); }
        }
        if (!isChord) onset += dur;   // primary notes of every voice advance the cursor
    }
    return entries;
}
function rhVoice1Entries(m) { return rhVoiceEntries(m, '1'); }
// Greedy binary decomposition of a tick gap into [type, ticks] rest values.
function rhDecompose(ticks, div) {
    const vals = [['half', div * 2], ['quarter', div], ['eighth', div / 2], ['16th', div / 4], ['32nd', div / 8]];
    const out = []; let rem = ticks;
    for (const [t, d] of vals) { while (d >= 1 && rem >= d) { out.push([t, d]); rem -= d; } }
    return out;
}
function rhMakeRest(typeName, ticks, voice) {
    const n = rhDoc.createElement('note');
    n.appendChild(rhDoc.createElement('rest'));
    const mk = (tag, txt) => { const e = rhDoc.createElement(tag); e.textContent = txt; n.appendChild(e); };
    mk('duration', String(ticks)); mk('voice', voice); mk('type', typeName); mk('staff', '1');
    return n;
}
// A silent gap (used by non-soprano voices, which may be under-full).
function rhMakeForward(ticks) {
    const f = rhDoc.createElement('forward');
    const d = rhDoc.createElement('duration'); d.textContent = String(ticks); f.appendChild(d);
    return f;
}
// Change the current chord ENTRY's duration (Finale changes the whole entry).
// Reflows voice 1 within the measure: shorten → fill with rest(s); lengthen →
// absorb following entries (clamped to the measure end). Beams in the measure's
// voice 1 are cleared to avoid stale/broken groups.
function rhSetDuration(numKey) {
    if (!rhSelId) return;
    const preset = RH_DUR[numKey]; if (!preset) return;
    const [typeName, ratio] = preset;
    const div = rhDivisions();
    const newTicks = div * ratio;
    if (!Number.isInteger(newTicks) || newTicks <= 0) return;   // e.g. 64th at divisions=8

    const note = rhFindNote(rhSelId); if (!note) return;
    const measure = note.closest('measure'); if (!measure) return;
    const voice = note.querySelector('voice')?.textContent || '1';

    const entries = rhVoiceEntries(measure, voice);
    const idx = entries.findIndex(e => e.primary === note || e.members.includes(note));
    if (idx < 0) return;
    const entry = entries[idx];
    const measureDur = rhVoice1Entries(measure).reduce((s, e) => s + e.dur, 0);

    // Balance rule: voice 1 (soprano) must fill the bar exactly; the other
    // voices may be UNDER-full (silent gaps) but never OVER-full. Clamp so the
    // new value can't overflow — voice 1 up to the bar end (absorbing following
    // entries), other voices only up to the next note in that voice.
    const nextOnset    = (idx + 1 < entries.length) ? entries[idx + 1].onset : measureDur;
    const followingSum = entries.slice(idx + 1).reduce((s, e) => s + e.dur, 0);
    const cap = (voice === '1') ? (entry.dur + followingSum) : (nextOnset - entry.onset);
    const finalTicks = Math.min(newTicks, cap);
    if (finalTicks <= 0) return;
    const delta = finalTicks - entry.dur;

    // Apply new duration/type to the entry's notes; clear their dots/beams.
    [entry.primary, ...entry.members].forEach(n => {
        n.querySelector('duration').textContent = String(finalTicks);
        const ty = n.querySelector('type'); if (ty) ty.textContent = typeName;
        n.querySelectorAll('dot, beam').forEach(x => x.remove());
    });
    const refEl = entry.members.length ? entry.members[entry.members.length - 1] : entry.primary;

    if (voice === '1') {
        // Soprano stays balanced: fill freed time with rest(s); when lengthening,
        // absorb following entries (overshoot → a trailing rest).
        if (delta < 0) {
            rhDecompose(-delta, div).map(([t, tk]) => rhMakeRest(t, tk, voice)).reverse()
                .forEach(rest => refEl.after(rest));
        } else if (delta > 0) {
            let need = delta;
            for (const f of entries.slice(idx + 1)) {
                if (need <= 0) break;
                [f.primary, ...f.members].forEach(x => x.remove());
                need -= f.dur;
            }
            if (need < 0) rhDecompose(-need, div).map(([t, tk]) => rhMakeRest(t, tk, voice)).reverse()
                .forEach(rest => refEl.after(rest));
        }
    } else {
        // Alto/tenor may be under-full: shorten → leave a SILENT gap (forward),
        // never a filler rest; lengthen → consume the following gap (already
        // clamped so it can't overrun the next note).
        if (delta < 0) {
            refEl.after(rhMakeForward(-delta));
        } else if (delta > 0) {
            let need = delta, sib = refEl.nextElementSibling;
            while (need > 0 && sib && sib.tagName === 'forward') {
                const d = sib.querySelector('duration');
                const have = +(d.textContent || 0);
                if (have <= need) { need -= have; const nx = sib.nextElementSibling; sib.remove(); sib = nx; }
                else { d.textContent = String(have - need); need = 0; }
            }
        }
    }
    // Clear any remaining beams in the edited voice (avoid broken groups).
    rhVoiceEntries(measure, voice).forEach(e =>
        [e.primary, ...e.members].forEach(n => n.querySelectorAll('beam').forEach(b => b.remove())));
    rhApply();
}

// Toggle a beam between the current voice-1 entry and the next (Finale "/").
function rhToggleBeam() {
    if (!rhSelId) return;
    const note = rhFindNote(rhSelId); const measure = note && note.closest('measure');
    if (!measure) return;
    const voice = note.querySelector('voice')?.textContent || '1';
    const entries = rhVoiceEntries(measure, voice);
    const idx = entries.findIndex(e => e.primary === note || e.members.includes(note));
    if (idx < 0 || idx + 1 >= entries.length) return;
    const a = entries[idx].primary, b = entries[idx + 1].primary;
    if (a.querySelector('beam') || b.querySelector('beam')) {
        a.querySelectorAll('beam').forEach(x => x.remove());
        b.querySelectorAll('beam').forEach(x => x.remove());
    } else {
        const mk = (el, val) => { const bm = rhDoc.createElement('beam'); bm.setAttribute('number', '1'); bm.textContent = val; el.appendChild(bm); };
        mk(a, 'begin'); mk(b, 'end');
    }
    rhApply();
}

// ── Editing operations ───────────────────────────────────────────────────────
function rhNudge(deltaSemitones) {
    if (!rhSelId) return;
    const p = rhGetPitch(rhSelId);
    if (!p) return;
    const np = rhSpell(rhMidiOf(p) + deltaSemitones);
    if (rhSetPitch(rhSelId, np.step, np.alter, np.octave)) rhApply();
}
function rhOctave(delta) {
    if (!rhSelId) return;
    const p = rhGetPitch(rhSelId);
    if (!p) return;
    if (rhSetPitch(rhSelId, p.step, p.alter, p.octave + delta)) rhApply();
}
function rhSetAlter(delta) {
    if (!rhSelId) return;
    const p = rhGetPitch(rhSelId);
    if (!p) return;
    const a = Math.max(-2, Math.min(2, p.alter + delta));
    if (rhSetPitch(rhSelId, p.step, a, p.octave)) rhApply();
}
// Type a letter A–G: set the note to that letter (natural) in the octave nearest
// the current pitch.
function rhSetLetter(letter) {
    if (!rhSelId) return;
    const p = rhGetPitch(rhSelId);
    if (!p) return;
    const cur = rhMidiOf(p);
    let best = null, bestD = Infinity;
    for (let oct = 1; oct <= 7; oct++) {
        const m = (oct + 1) * 12 + RH_STEP_SEMI[letter];
        const d = Math.abs(m - cur);
        if (d < bestD) { bestD = d; best = oct; }
    }
    if (rhSetPitch(rhSelId, letter, 0, best)) rhApply();
}
// Delete: chord members are removed outright; a lead voice note becomes a rest
// (preserving the beat).
function rhDelete() {
    if (!rhSelId) return;
    const note = rhFindNote(rhSelId);
    if (!note) return;
    if (note.querySelector('chord')) {
        note.remove();
    } else {
        note.querySelector('pitch')?.remove();
        note.querySelector('accidental')?.remove();
        if (!note.querySelector('rest')) note.insertBefore(rhDoc.createElement('rest'), note.firstChild);
    }
    rhSelId = null;
    rhApply();
    rhHidePopover();
}
// Move the selection to the same voice of the previous/next chord.
function rhMoveChord(dir) {
    if (!rhSelId) return;
    const m = rhSelId.match(/^chord-(\d+)(?:-(alto|tenor))?$/);
    if (!m) return;
    const suffix = m[2] ? '-' + m[2] : '';
    const svg = document.querySelector('#wrap-real svg');
    if (!svg) return;
    for (let n = parseInt(m[1], 10) + dir; n >= 0 && n < 100000; n += dir) {
        // Prefer the same voice; fall back to the soprano of that chord.
        for (const id of ['chord-' + n + suffix, 'chord-' + n]) {
            if (svg.querySelector('.note[id="' + id + '"]')) { rhSelect(id); return; }
        }
        // Stop scanning once we run past the last chord on the page.
        if (!svg.querySelector('.note[id^="chord-' + n + '"]')) break;
    }
}

// ── Selection highlight + popover ─────────────────────────────────────────────
function rhSelect(id) { rhSelId = id; rhHighlight(); rhShowPopover(); }

function rhHighlight() {
    const svg = document.querySelector('#wrap-real svg');
    if (!svg) return;
    svg.querySelector('.rh-sel-layer')?.remove();
    if (!rhSelId) return;
    const el = svg.querySelector('.note[id="' + rhSelId + '"]');
    if (!el || typeof svgBBoxInRoot !== 'function') return;
    const b = svgBBoxInRoot(svg, el);
    const NS = 'http://www.w3.org/2000/svg';
    const layer = document.createElementNS(NS, 'g');
    layer.setAttribute('class', 'rh-sel-layer');

    // Edit box: frame the current measure (Finale's "editing frame").
    const measEl = el.closest('.measure') || el.closest('g[id^="meas-"]');
    if (measEl) {
        const mb = svgBBoxInRoot(svg, measEl);
        const mpad = b.h * 0.6;
        const frame = document.createElementNS(NS, 'rect');
        frame.setAttribute('x', mb.x - mpad); frame.setAttribute('y', mb.y - mpad);
        frame.setAttribute('width', mb.w + mpad * 2); frame.setAttribute('height', mb.h + mpad * 2);
        frame.setAttribute('rx', mpad * 0.5);
        frame.setAttribute('fill', 'rgba(37,99,235,0.05)');
        frame.setAttribute('stroke', '#2563eb');
        frame.setAttribute('stroke-width', Math.max(b.h * 0.08, 0.8));
        frame.setAttribute('stroke-dasharray', (b.h * 0.4) + ' ' + (b.h * 0.3));
        frame.setAttribute('opacity', '0.8');
        layer.appendChild(frame);
    }

    const r = document.createElementNS(NS, 'rect');
    const pad = b.h * 0.25;
    r.setAttribute('x', b.x - pad); r.setAttribute('y', b.y - pad);
    r.setAttribute('width', b.w + pad * 2); r.setAttribute('height', b.h + pad * 2);
    r.setAttribute('rx', pad);
    r.setAttribute('fill', 'none');
    r.setAttribute('stroke', VOICE_COLORS[rhVoiceOf(rhSelId)] || '#111');
    r.setAttribute('stroke-width', Math.max(b.h * 0.12, 1.2));
    r.setAttribute('opacity', '0.9');
    layer.appendChild(r);
    svg.appendChild(layer);
}

function rhEnsurePopover() {
    let pop = document.getElementById('rh-popover');
    if (pop) return pop;
    pop = document.createElement('div');
    pop.id = 'rh-popover';
    pop.style.cssText = 'position:absolute;z-index:60;display:none;background:var(--surface,#1b1f27);'
        + 'border:1px solid var(--border,#3a3f4b);border-radius:6px;padding:0.5rem 0.6rem;'
        + 'box-shadow:0 4px 16px rgba(0,0,0,.35);font-size:0.8rem;color:var(--text,#e5e7eb);min-width:150px';
    document.body.appendChild(pop);
    return pop;
}
function rhShowPopover() {
    if (!rhEditMode || !rhSelId) { rhHidePopover(); return; }
    const svg = document.querySelector('#wrap-real svg');
    const el = svg && svg.querySelector('.note[id="' + rhSelId + '"]');
    if (!el) { rhHidePopover(); return; }
    const voice = rhVoiceOf(rhSelId);
    const p = rhGetPitch(rhSelId);
    const label = p ? (p.step + (p.alter === 1 ? '♯' : p.alter === -1 ? '♭' : p.alter === 2 ? '𝄪' : p.alter === -2 ? '𝄫' : '') + p.octave) : '(rest)';
    const col = VOICE_COLORS[voice] || '#888';
    const pop = rhEnsurePopover();
    pop.innerHTML =
        '<div style="display:flex;align-items:center;gap:0.4rem;margin-bottom:0.4rem">'
      + '<span style="width:0.7rem;height:0.7rem;border-radius:2px;background:' + col + '"></span>'
      + '<strong style="text-transform:capitalize">' + voice + '</strong>'
      + '<span style="margin-left:auto;font-family:monospace;font-size:0.95rem">' + label + '</span></div>'
      + '<div style="display:flex;gap:0.3rem;flex-wrap:wrap">'
      + '<button data-rh="up">step ▲</button><button data-rh="down">step ▼</button>'
      + '<button data-rh="octup">8ve ▲</button><button data-rh="octdown">8ve ▼</button>'
      + '<button data-rh="sharp">♯</button><button data-rh="flat">♭</button>'
      + '<button data-rh="del" style="color:#f87171">✕ del</button></div>'
      + '<div style="margin-top:0.35rem;opacity:.6;font-size:0.68rem">←/→ note · ↑/↓ step · ⇧↑/↓ 8ve · +/− semitone · A–G · 1–8 dur (4=♪ 5=♩ 6=𝅗𝅥) · / beam · Del</div>';
    pop.querySelectorAll('button').forEach(btn => {
        btn.style.cssText = 'padding:0.2rem 0.4rem;font-size:0.75rem;background:var(--surface-alt,#2a2f3a);'
            + 'color:inherit;border:1px solid var(--border,#3a3f4b);border-radius:3px;cursor:pointer';
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            const a = btn.dataset.rh;
            if (a === 'up') rhDiatonic(1); else if (a === 'down') rhDiatonic(-1);
            else if (a === 'octup') rhOctave(1); else if (a === 'octdown') rhOctave(-1);
            else if (a === 'sharp') rhSetAlter(1); else if (a === 'flat') rhSetAlter(-1);
            else if (a === 'del') rhDelete();
        });
    });
    const rect = el.getBoundingClientRect();
    pop.style.display = 'block';
    pop.style.left = (window.scrollX + rect.left) + 'px';
    pop.style.top  = (window.scrollY + rect.bottom + 8) + 'px';
}
function rhHidePopover() { const p = document.getElementById('rh-popover'); if (p) p.style.display = 'none'; }

// ── Mode toggle + event wiring ────────────────────────────────────────────────
document.getElementById('cb-edit-rh')?.addEventListener('change', function () {
    rhEditMode = this.checked;
    document.body.classList.toggle('rh-editing', rhEditMode);
    if (rhEditMode) {
        rhParseDoc();
    } else {
        rhSelId = null; rhHighlight(); rhHidePopover();
    }
});

// Capture-phase click on the realization pane: in edit mode, select the clicked
// RH notehead and stop the chord-inspector from also firing.
document.addEventListener('click', function (e) {
    if (!rhEditMode) return;
    const wrap = document.getElementById('wrap-real');
    if (!wrap || !wrap.contains(e.target)) {
        if (!e.target.closest('#rh-popover')) { /* click elsewhere: keep selection */ }
        return;
    }
    const noteEl = e.target.closest('.note');
    if (noteEl && rhVoiceOf(noteEl.id)) {
        e.stopPropagation();
        e.preventDefault();
        if (!rhDoc) rhParseDoc();
        rhSelect(noteEl.id);
    }
}, true);

// Keyboard editing while a note is selected.
document.addEventListener('keydown', function (e) {
    if (!rhEditMode || !rhSelId) return;
    // Don't hijack typing in inputs.
    if (e.target.matches('input, textarea, select')) return;
    let handled = true;
    switch (e.key) {
        case 'ArrowUp':    e.shiftKey ? rhOctave(1)  : rhDiatonic(1);  break;
        case 'ArrowDown':  e.shiftKey ? rhOctave(-1) : rhDiatonic(-1); break;
        case 'ArrowRight': rhMoveChord(1);  break;
        case 'ArrowLeft':  rhMoveChord(-1); break;
        case 'Delete': case 'Backspace': rhDelete(); break;
        case '+': case '=': rhSetAlter(1);  break;
        case '-': case '_': rhSetAlter(-1); break;
        case '/': rhToggleBeam(); break;
        case 'Escape': rhSelId = null; rhHighlight(); rhHidePopover(); break;
        default:
            if (/^[1-8]$/.test(e.key) && !e.ctrlKey && !e.metaKey) rhSetDuration(e.key);
            else if (/^[a-gA-G]$/.test(e.key)) rhSetLetter(e.key.toUpperCase());
            else handled = false;
    }
    if (handled) e.preventDefault();
});
