'use strict';

function escapeHtml(s) {
    return String(s)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function capitalise(s) {
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
}

// Build a flat boolean array: one entry per <figured-bass> element in score
// order. true = computed by decision tree, false = from the original file.
function buildFbComputedFlags() {
    fbComputedFlags = chordDataStore
        .filter(c => c.figures && c.figures !== '(none)')
        .map(c => c.figuresSource === 'computed');
}

// Convert figure string (e.g. "b7 #4") to a MusicXML <figured-bass> fragment
function figStrToFbXml(figStr, duration, color) {
    if (!figStr || figStr === '(none)') return '';
    const tokens = figStr.trim().split(/\s+/).filter(Boolean);
    if (!tokens.length) return '';
    // Longer symbols first so 'bb' is checked before 'b'
    const PFXMAP = [['bb','flat-flat'],['b','flat'],['#','sharp'],['s','sharp'],
                    ['x','double-sharp'],['n','natural']];
    let out = '<figured-bass' + (color ? ' color="' + color + '"' : '') + '>';
    out += '<duration>' + duration + '</duration>';
    for (const tok of tokens) {
        let prefix = '', rest = tok;
        for (const [sym, name] of PFXMAP) {
            if (tok.startsWith(sym) && /\d/.test(tok[sym.length] || '')) {
                prefix = name; rest = tok.slice(sym.length); break;
            }
        }
        const num = parseInt(rest, 10);
        if (!num) continue;
        out += '<figure>';
        if (prefix) out += '<prefix>' + prefix + '</prefix>';
        out += '<figure-number>' + num + '</figure-number>';
        out += '</figure>';
    }
    out += '</figured-bass>';
    return out;
}
