# UI navigation

Two pages were reorganised so the common path stays short and the reference
material moves out of the way without disappearing.

## Realizer (`/`)

Three panes behind a tab bar, all rendered server-side and switched by
`public/js/tabs.js`:

| Tab | Pane id | Contents |
|-----|---------|----------|
| Realize | `#tab-realize` | Input card (compose / import), Score Viewer, comment form |
| Advanced settings | `#tab-settings` | The voice-leading rules from the database, with their source code |
| Documentation | `#tab-docs` | Algorithm, pipeline, figured-bass table, bibliography |

**Why client-side tabs rather than routes:** switching keeps the realization,
the Verovio renderings and the playback state alive. Reading the documentation
mid-session and coming back costs nothing. The active pane is mirrored in the
hash (`/#settings`, `/#docs`) so links are shareable and Back works; the
default pane leaves the URL clean.

Panes are switched with the `hidden` attribute. This is safe for the scores:
every Verovio toolkit in this app engraves at a fixed `pageWidth` and is scaled
by CSS, so a pane laid out while hidden renders identically (`scoreWrapWidth()`
in `score-viewer.js` is dead code and measures nothing).

### Condensed main flow

- **Score Viewer comes first**; the summary, chord pills, XML preview and
  download button moved below it into a collapsed `<details id="summary-fold">`.
- **Detected phrases** (`#passages-panel`) is a collapsed `<details>`.
  `selectPassage()` opens it, so clicking a phrase on the score still reveals
  its analysis card.
- **Voice count** left the `⚙ Params` popover and became one shared control,
  `#cfg-voices`, in the input card. It is the single source of truth: `app.js`
  exposes `selectedVoices()`, used by `upload.js`, `bass-editor.js` and
  `chord-inspector.js`. The upload form appends it explicitly (`fd.set`)
  because the control now lives outside the `<form>`.

The Chord Inspector is unchanged — still a modal, opened from the Realize pane.

## IMSLP browser (`/imslp`)

The AI search box stays expanded at the top. Below it, only **Composer / title
+ Search** is permanently visible; the ten remaining filters fold into
`<details id="adv-filters">`, grouped into three fieldsets:

| Group | Fields |
|-------|--------|
| Musical context | Period, Genre, Key, Year from / to |
| Forces | Instrumentation, Parts, Registers (SATB steppers) |
| Precision | Exact register match, Include manuscripts |

*Exact match* drives the Registers filter but is filed under Precision; a note
under the steppers points at it.

**A folded filter is never a hidden filter.** Three things guarantee that:

1. The panel is rendered `open` whenever `filters.isEmpty()` is false, so a
   search arriving with filters in the URL shows them.
2. The summary carries a live count badge (`#adv-count`), recomputed on every
   `input`/`change`.
3. Active filters are also chips above the results, each with a ✕ that clears
   that one filter and re-submits.

Manuscripts are *included* by default, so it is unchecking that box which
counts as an active filter — both the badge and the chips follow that rule, and
"Reset filters" restores it to checked.

The AI search calls `window.openAdvancedFilters()` after filling the form, so
whatever it decided is visible and correctable.

The form is still a single POST; `ImslpController` was not touched.

## Mobile (≤ 640 px)

Audited at 390×844 with `isMobile`/`hasTouch`. What was broken and what replaced it:

| Was | Now |
|-----|-----|
| Third tab ran to 445 px on a 390 px screen, scrollable but with no affordance | `.main-tabs` becomes a 3-column grid; short labels (`.tab-short`) swap in |
| Duration / accidental buttons 20–30 × **21 px** | 5-up grid, 44 px minimum; the keyboard legend (`.ed-help`) is hidden — it describes keys a touch keyboard has not got |
| Note entry opened first, though it is driven by arrow keys and shortcuts | `upload.js` clicks the **Import** tab when `(max-width: 640px)` matches |
| Score SVG squeezed to 263 px, illegible | Full-bleed: negative margins escape the card padding, `svg { width: 760px }`, horizontal pan inside the wrapper, pan hint below |
| Four viewer checkboxes wrapped into three lines and overflowed | Folded into `<details class="display-fold">` |
| `.abbrev-table` forced the **whole `/imslp` page** to 433 px wide | Wrapped in `.abbrev-scroll` (`overflow-x: auto`) |
| Four fixed language pills overhung the right edge and sat on the sticky tab bar | Compact `FR ▾` `<details>` menu, `position: absolute` so it scrolls away instead of overlapping |
| Chord Inspector centred with 1 rem gutters | Bottom sheet: full width, `max-height: 85vh`, 16 px top radius, grab handle |
| Register steppers 32×34, checkboxes 18×18 | 52×48 and 24×24 |

Two techniques worth knowing before editing this CSS:

- **One `<details>`, two presentations.** The language menu and the display-options
  fold are real disclosures on a phone; on a wide screen the summary is
  `display: none` and the body is shown unconditionally. Setting `display`
  explicitly on the body overrides the UA's closed-details hiding — verified in
  Chromium — so no markup is duplicated and no link appears twice in the a11y tree.
- **Tab labels swap, the accessible name does not.** Each tab button carries an
  `aria-label` with the full wording, so hiding `.tab-full` on a phone cannot
  leave the button unnamed.

Text inputs are set to `font-size: 16px` on mobile: anything smaller makes iOS
Safari zoom the page on focus.

The playback knobs were left as knobs — they already use pointer events, so touch
worked; what they lacked was `touch-action: none`, without which the page panned
away under the finger.

`.adv-actions` on `/imslp` is deliberately **not** sticky: a sticky bar overlaid
the Genre field while scrolling, and the simple search bar above already keeps a
Search button in view at all times.

Verified 0 px horizontal overflow on both pages at 360, 390, 768 and 1100 px.
