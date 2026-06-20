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
