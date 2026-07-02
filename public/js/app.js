'use strict';
// Shared mutable state accessed across multiple modules.
// Declared at global scope so every subsequent script can read/write them.
let currentXml      = null;
let currentInputXml = null;
let currentFile     = null;
let chordDataStore  = [];
let passageStore    = [];  // detected phrases: {start_measure, end_measure, key, confidence, cadence}
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
