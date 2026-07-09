'use strict';
// Shared mutable state accessed across multiple modules.
// Declared at global scope so every subsequent script can read/write them.
let currentXml      = null;
let currentInputXml = null;
let currentFile     = null;
let chordDataStore  = [];
let passageStore    = [];  // detected phrases: {start_measure, end_measure, key, confidence, cadence}
let selectedPassageIdx = 0; // which phrase's Roman numerals are drawn under the score (one at a time)

// Right-hand voice colours (soprano/alto/tenor), shared by the on-score voice
// tinting and the RH editor's selection highlight. Chosen distinct from the
// per-phrase palette above (which tints the flute line and Roman numerals).
const VOICE_COLORS = {
    soprano: '#e11d48', // rose
    alto:    '#059669', // emerald
    tenor:   '#7c3aed', // violet
};

// Return the voice name for a realization note element id, or null.
// Soprano notes are "chord-{N}"; alto/tenor are "chord-{N}-alto|tenor".
function voiceOfNoteId(id) {
    if (!id) return null;
    if (/-alto$/.test(id))  return 'alto';
    if (/-tenor$/.test(id)) return 'tenor';
    if (/^chord-\d+$/.test(id)) return 'soprano';
    return null;
}
let fbComputedFlags = [];  // one boolean per figured-bass element, in score order
let currentEditIdx  = null;

// Colour code for detected phrases: each phrase gets a distinct hue, cycled by
// its position in passageStore. Shared by the on-score labels (score-viewer.js)
// and the passages panel legend (upload.js) so the two line up visually.
const PASSAGE_COLORS = [
    '#2563eb', // blue
    '#dc2626', // red
    '#16a34a', // green
    '#9333ea', // purple
    '#ea580c', // orange
    '#0891b2', // teal
    '#ca8a04', // amber
    '#db2777', // pink
];
function passageColor(idx) {
    return PASSAGE_COLORS[((idx % PASSAGE_COLORS.length) + PASSAGE_COLORS.length) % PASSAGE_COLORS.length];
}
