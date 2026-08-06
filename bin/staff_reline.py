#!/usr/bin/env python3
"""
Staff re-lining for French baroque facsimiles (sidecar for StaffRelineService).

Old French prints place clefs on lines modern readers do not expect: the G clef
on the first line (clef de violon francais) instead of the second, the F clef on
the third (baritone) instead of the fourth, and C clefs anywhere from the first
line (soprano) to the fourth (tenor).

The conversion a player wants is purely a change of ruling.  Every glyph on the
page -- clef, key signature, notes, ornaments -- stays exactly where the printer
put it; only the five staff lines move.  Shifting the ruling down by one line
position (drop the top line, add one below the bottom) leaves the clef sitting
on the next line up, so a G clef engraved on line 1 now reads as a line-2 treble
clef.  Because nothing but the ruling moved, the pitch each glyph denotes is
unchanged: this is a relabelling of the staff, not a transposition.

The same mechanism handles every clef whose target is reached by an integer
number of line positions, which includes all C-clef-to-C-clef normalisations.
It cannot turn a C clef into a G or F clef -- that needs a different glyph, not
a different ruling -- so those are out of scope here.

Pipeline per page:

  1. render (PDF via pdfium, or read an image file)
  2. binarise, after flattening the uneven illumination typical of scans
  3. estimate staff line thickness and staff space from vertical run lengths
     (the classic run-length estimator: Fujinaga, "Staff Detection and Removal",
     in Visual Perception of Music Notation, 2004)
  4. locate staff lines by horizontal projection, group them into staves of five
  5. apply the shift: erase the vacating lines, rule the new ones, re-extend
     barlines to the new staff height, and -- only when asked for -- rule ledger
     lines for notes the shift pushed outside the staff

Erasure is run-length aware: a pixel on a staff line is cleared only when the
vertical ink run through it is no thicker than a line, so stems, noteheads and
slurs crossing the line survive intact (Cardoso & Rebelo, "Staff Detection with
Stable Paths", IEEE TPAMI 2009, describe the family of techniques).

Usage:
    staff_reline.py INPUT --mode analyze [--dpi 300] [--pages 1-4]
    staff_reline.py INPUT --mode apply --shift 1 [--out DIR] [--pdf]

Both modes print a JSON report on stdout.
"""

from __future__ import annotations

import argparse
import json
import os
import sys
from dataclasses import dataclass, asdict, field

import cv2
import numpy as np

# --------------------------------------------------------------------------
# Clef presets: how many line positions the ruling must move so that the
# engraved clef glyph lands on its modern line.  Positive = ruling moves down
# the page (drop N lines off the top, rule N new ones below the bottom), which
# makes the clef read N lines higher.
# --------------------------------------------------------------------------
CLEF_SHIFTS = {
    "french_violin": 1,   # G on line 1 -> treble (G on 2)
    "treble": 0,          # already modern
    "baritone_f": 1,      # F on line 3 -> bass (F on 4)
    "bass": 0,            # already modern
    "soprano_c": 2,       # C on line 1 -> alto (C on 3)
    "mezzo_c": 1,         # C on line 2 -> alto
    "alto_c": 0,          # already the reference C clef
    "tenor_c": -1,        # C on line 4 -> alto
}


@dataclass
class Staff:
    """One five-line staff on a page, in pixel coordinates of the page image."""
    index: int
    lines: list[float]          # y of each line centre, top to bottom
    x0: int
    x1: int
    step: float                 # distance between adjacent lines
    thickness: float


@dataclass
class PageReport:
    index: int
    width: int
    height: int
    skew_deg: float
    staff_space: float
    staff_line: float
    staves: list[Staff] = field(default_factory=list)
    image: str | None = None
    output: str | None = None
    ledgers_added: int = 0
    barlines_adjusted: int = 0


# --------------------------------------------------------------------------
# Page loading
# --------------------------------------------------------------------------

def load_pages(path: str, dpi: int, page_range: str | None) -> list[np.ndarray]:
    """Return the requested pages as 8-bit greyscale arrays."""
    ext = os.path.splitext(path)[1].lower()
    if ext == ".pdf":
        import pypdfium2 as pdfium

        doc = pdfium.PdfDocument(path)
        wanted = parse_page_range(page_range, len(doc))
        pages = []
        for i in wanted:
            bitmap = doc[i].render(scale=dpi / 72.0, grayscale=True)
            pages.append(np.asarray(bitmap.to_pil().convert("L")))
        return pages

    img = cv2.imread(path, cv2.IMREAD_GRAYSCALE)
    if img is None:
        raise RuntimeError(f"cannot read image: {path}")
    return [img]


def parse_page_range(spec: str | None, total: int) -> list[int]:
    """'1-4', '2', '1,3-5' -> zero-based indices, clamped to the document."""
    if not spec:
        return list(range(total))
    out: list[int] = []
    for chunk in spec.split(","):
        chunk = chunk.strip()
        if not chunk:
            continue
        if "-" in chunk:
            a, b = chunk.split("-", 1)
            out.extend(range(int(a) - 1, int(b)))
        else:
            out.append(int(chunk) - 1)
    return [i for i in out if 0 <= i < total]


# --------------------------------------------------------------------------
# Binarisation and skew
# --------------------------------------------------------------------------

def binarize(gray: np.ndarray, dpi: int) -> np.ndarray:
    """Ink mask (255 = ink) with the scan's uneven lighting flattened out.

    Closing with a kernel wider than any dark stroke leaves an estimate of the
    paper; dividing by it removes yellowing and shadow gradients before Otsu.
    """
    k = max(3, int(dpi / 8) | 1)
    paper = cv2.morphologyEx(gray, cv2.MORPH_CLOSE, np.ones((k, k), np.uint8))
    flat = cv2.divide(gray, paper, scale=255)
    _, ink = cv2.threshold(flat, 0, 255, cv2.THRESH_BINARY_INV | cv2.THRESH_OTSU)
    return ink


def estimate_skew(ink: np.ndarray, limit: float = 3.0, step: float = 0.1) -> float:
    """Skew in degrees, found by maximising the sharpness of the row profile.

    Staff lines make the horizontal projection spiky; the angle whose profile
    has the greatest variance is the one that has them horizontal.
    """
    small = cv2.resize(ink, None, fx=0.25, fy=0.25, interpolation=cv2.INTER_AREA)
    h, w = small.shape
    centre = (w / 2, h / 2)
    best_angle, best_score = 0.0, -1.0
    angle = -limit
    while angle <= limit + 1e-9:
        m = cv2.getRotationMatrix2D(centre, angle, 1.0)
        rot = cv2.warpAffine(small, m, (w, h), flags=cv2.INTER_NEAREST, borderValue=0)
        profile = rot.sum(axis=1, dtype=np.float64)
        score = float(np.var(profile))
        if score > best_score:
            best_angle, best_score = angle, score
        angle += step
    return best_angle


def rotate(img: np.ndarray, angle: float, border: int) -> np.ndarray:
    h, w = img.shape[:2]
    m = cv2.getRotationMatrix2D((w / 2, h / 2), angle, 1.0)
    return cv2.warpAffine(img, m, (w, h), flags=cv2.INTER_CUBIC,
                          borderMode=cv2.BORDER_CONSTANT, borderValue=border)


# --------------------------------------------------------------------------
# Staff geometry
# --------------------------------------------------------------------------

def run_length_stats(ink: np.ndarray) -> tuple[float, float]:
    """(staff line thickness, staff space) from the modal vertical run lengths.

    Scanning columns, the commonest black run is a staff line's thickness and
    the commonest white run is the gap between two lines.
    """
    h, w = ink.shape
    black = np.zeros(64, np.int64)
    white = np.zeros(256, np.int64)
    for x in range(0, w, 3):
        col = ink[:, x] > 0
        if not col.any():
            continue
        # Boundaries between runs, then run lengths and their colour.
        idx = np.flatnonzero(np.diff(col)) + 1
        bounds = np.concatenate(([0], idx, [h]))
        lengths = np.diff(bounds)
        colours = col[bounds[:-1]]
        for length, is_ink in zip(lengths, colours):
            if is_ink and length < 64:
                black[length] += 1
            elif not is_ink and length < 256:
                white[length] += 1
    thickness = float(np.argmax(black[1:]) + 1) if black[1:].any() else 2.0
    space = float(np.argmax(white[2:]) + 2) if white[2:].any() else 16.0
    return thickness, space


def find_staff_lines(ink: np.ndarray, thickness: float) -> list[tuple[float, int, int]]:
    """Candidate ruling lines as (y centre, x0, x1).

    A staff line is a row whose ink spans most of the width it covers.  We take
    rows above a fraction of the strongest row, merge vertically adjacent rows
    into one line, and measure how far each extends horizontally.
    """
    profile = ink.sum(axis=1, dtype=np.float64) / 255.0
    if profile.max() <= 0:
        return []
    threshold = 0.45 * float(np.percentile(profile[profile > 0], 99.5))
    hot = profile >= max(threshold, 0.15 * ink.shape[1])
    if not hot.any():
        return []

    lines: list[tuple[float, int, int]] = []
    y = 0
    h = ink.shape[0]
    max_thick = max(2.0, thickness * 3.0)
    while y < h:
        if not hot[y]:
            y += 1
            continue
        start = y
        while y < h and hot[y]:
            y += 1
        if (y - start) > max_thick:
            # Too tall for a ruling line: a text block or a smear, not a stave.
            continue
        band = ink[start:y, :]
        cols = np.flatnonzero(band.max(axis=0) > 0)
        if cols.size == 0:
            continue
        lines.append(((start + y - 1) / 2.0, int(cols[0]), int(cols[-1])))
    return lines


def group_staves(lines: list[tuple[float, int, int]], space: float,
                 thickness: float) -> list[Staff]:
    """Collect the detected lines into groups of five evenly spaced ones."""
    staves: list[Staff] = []
    expected = space + thickness
    i = 0
    while i + 4 < len(lines):
        window = lines[i:i + 5]
        gaps = [window[j + 1][0] - window[j][0] for j in range(4)]
        mean_gap = sum(gaps) / 4.0
        even = all(abs(g - mean_gap) <= 0.30 * mean_gap for g in gaps)
        plausible = 0.55 * expected <= mean_gap <= 1.8 * expected
        if even and plausible:
            staves.append(Staff(
                index=len(staves),
                lines=[w[0] for w in window],
                x0=min(w[1] for w in window),
                x1=max(w[2] for w in window),
                step=mean_gap,
                thickness=thickness,
            ))
            i += 5
        else:
            i += 1
    return staves


# --------------------------------------------------------------------------
# The edit
# --------------------------------------------------------------------------

def local_paper(gray: np.ndarray, y: int, x0: int, x1: int, step: float) -> np.ndarray:
    """A strip of paper colour to erase a line with, sampled just beside it."""
    off = max(2, int(round(step * 0.35)))
    above = gray[max(0, y - off), x0:x1 + 1].astype(np.int32)
    below = gray[min(gray.shape[0] - 1, y + off), x0:x1 + 1].astype(np.int32)
    return np.maximum(above, below).astype(np.uint8)


def track_line(ink: np.ndarray, staff: Staff, y_seed: float) -> tuple[np.ndarray, np.ndarray, np.ndarray]:
    """Follow one ruling line column by column across the staff.

    Hand-etched plates wobble and scans keep a little skew, so a line whose mean
    y is known still drifts several pixels across the width of a system.  Every
    later step -- erasing a line, ruling a new one parallel to it -- works off
    this path rather than a straight average, which is what keeps the result
    looking like the plate instead of a ruler laid over it.

    Returns (ys, tops, bottoms): the line's centre and ink extent at each column
    from x0 to x1.  Columns where nothing line-like was found carry NaN, and
    columns where the run is too tall (a stem or notehead crossing) get their
    extent recorded but are excluded from erasure by ``line_only``.
    """
    h = ink.shape[0]
    width = staff.x1 - staff.x0 + 1
    reach = max(2, int(round(staff.step * 0.45)))
    tol = max(2, int(round(staff.thickness * 2.0)))
    # The path may bend with the plate but must never wander far enough to be
    # captured by the neighbouring line -- an unbounded follower will hop across
    # at the first gap in the ruling and then track the wrong line for the rest
    # of the system.
    max_drift = staff.step * 0.6

    centres = np.full(width, np.nan)
    cur = float(y_seed)

    for xi in range(width):
        x = staff.x0 + xi
        lo = max(0, int(round(cur)) - reach)
        hi = min(h - 1, int(round(cur)) + reach)
        col = ink[lo:hi + 1, x] > 0
        if not col.any():
            continue

        # Split the window into ink runs and take the one nearest the current
        # estimate, preferring runs thin enough to be ruling rather than symbol.
        idx = np.flatnonzero(np.diff(col.astype(np.int8))) + 1
        bounds = np.concatenate(([0], idx, [col.size]))
        best = None
        for a, b in zip(bounds[:-1], bounds[1:]):
            if not col[a]:
                continue
            centre = lo + (a + b - 1) / 2.0
            height = b - a
            cost = abs(centre - cur) + (0.0 if height <= tol else staff.step)
            if abs(centre - y_seed) > max_drift:
                continue
            if best is None or cost < best[0]:
                best = (cost, centre)
        if best is None:
            continue

        centres[xi] = best[1]
        # Damped update, clamped: a notehead sitting on the line must not drag
        # the path, and no run of symbols may walk it onto its neighbour.
        cur = 0.5 * best[1] + 0.5 * cur
        cur = min(max(cur, y_seed - max_drift), y_seed + max_drift)

    ys = _smooth_path(centres, y_seed, staff.step * 2.0)
    np.clip(ys, y_seed - max_drift, y_seed + max_drift, out=ys)
    tops, bottoms = _line_extents(ink, staff, ys, tol)
    return ys, tops, bottoms


def _smooth_path(centres: np.ndarray, y_seed: float, window: float) -> np.ndarray:
    """Interpolate the gaps, then median-filter out the symbol-crossing jitter."""
    known = ~np.isnan(centres)
    if not known.any():
        return np.full(centres.size, float(y_seed))
    xs = np.arange(centres.size)
    filled = np.interp(xs, xs[known], centres[known])
    w = max(3, int(window) | 1)
    if w >= filled.size:
        return np.full(centres.size, float(np.median(filled)))
    pad = w // 2
    padded = np.pad(filled, (pad, pad), mode="edge")
    view = np.lib.stride_tricks.sliding_window_view(padded, w)
    return np.median(view, axis=1)


def _line_extents(ink: np.ndarray, staff: Staff, ys: np.ndarray,
                  tol: int) -> tuple[np.ndarray, np.ndarray]:
    """The vertical ink run sitting on the path, per column."""
    h = ink.shape[0]
    width = ys.size
    tops = np.full(width, -1, np.int32)
    bottoms = np.full(width, -1, np.int32)
    reach = tol + 2

    for xi in range(width):
        x = staff.x0 + xi
        y = int(round(ys[xi]))
        if not (0 <= y < h):
            continue
        seed = None
        for d in range(reach + 1):
            for cand in (y - d, y + d):
                if 0 <= cand < h and ink[cand, x] > 0:
                    seed = cand
                    break
            if seed is not None:
                break
        if seed is None:
            continue
        top = seed
        while top - 1 >= 0 and ink[top - 1, x] > 0 and (seed - top) < reach:
            top -= 1
        bottom = seed
        while bottom + 1 < h and ink[bottom + 1, x] > 0 and (bottom - seed) < reach:
            bottom += 1
        tops[xi] = top
        bottoms[xi] = bottom
    return tops, bottoms


def erase_line(gray: np.ndarray, ink: np.ndarray, staff: Staff, y: float) -> np.ndarray:
    """Remove one ruling line, keeping every symbol that crosses it.

    A column is cleared only where the vertical ink run on the line is no taller
    than a line or two; anywhere a stem, notehead or slur passes, the run is
    longer and the ink is left alone.  The cleared band is padded by a pixel to
    take the grey antialiasing halo with it, which is what otherwise survives as
    a dashed ghost of the old line.
    """
    ys, tops, bottoms = track_line(ink, staff, y)
    tol = max(2, int(round(staff.thickness * 2.0)))
    h = gray.shape[0]

    for xi in range(ys.size):
        top, bottom = tops[xi], bottoms[xi]
        if top < 0 or (bottom - top + 1) > tol:
            continue
        x = staff.x0 + xi
        a = max(0, top - 1)
        b = min(h - 1, bottom + 1)
        # Paper colour from just outside the line, on whichever side is cleaner.
        off = max(2, int(round(staff.step * 0.35)))
        up = gray[max(0, a - off), x]
        down = gray[min(h - 1, b + off), x]
        gray[a:b + 1, x] = max(int(up), int(down))
        ink[a:b + 1, x] = 0
    return ys


def draw_tracked_line(gray: np.ndarray, ink: np.ndarray, staff: Staff, ys: np.ndarray,
                      thickness: float, shade: int) -> None:
    """Rule a new line along a tracked path, so it wobbles with the plate."""
    t = max(1, int(round(thickness)))
    h = gray.shape[0]
    for xi in range(ys.size):
        y = ys[xi]
        if not np.isfinite(y):
            continue
        top = int(round(y - (t - 1) / 2.0))
        if top < 0 or top + t > h:
            continue
        x = staff.x0 + xi
        gray[top:top + t, x] = shade
        ink[top:top + t, x] = 255


def draw_line(gray: np.ndarray, ink: np.ndarray, y: float, x0: int, x1: int,
              thickness: float, shade: int) -> None:
    """Rule a new staff line at y."""
    t = max(1, int(round(thickness)))
    top = int(round(y - (t - 1) / 2.0))
    top = max(0, min(gray.shape[0] - t, top))
    gray[top:top + t, x0:x1 + 1] = shade
    ink[top:top + t, x0:x1 + 1] = 255


def ink_shade(gray: np.ndarray, staff: Staff) -> int:
    """How dark this staff's ink is, so new lines match the old ones."""
    y0, y1 = int(staff.lines[0]), int(staff.lines[-1])
    band = gray[max(0, y0 - 2):min(gray.shape[0], y1 + 3), staff.x0:staff.x1 + 1]
    if band.size == 0:
        return 0
    return int(np.percentile(band, 5))


def find_barlines(ink: np.ndarray, staff: Staff) -> list[tuple[int, int]]:
    """Column spans of the barlines running the height of this staff."""
    y0 = int(round(staff.lines[0]))
    y1 = int(round(staff.lines[-1]))
    band = ink[y0:y1 + 1, staff.x0:staff.x1 + 1] > 0
    if band.size == 0:
        return []
    height = band.shape[0]
    filled = band.sum(axis=0)
    tall = filled >= 0.82 * height

    spans: list[tuple[int, int]] = []
    x = 0
    width_limit = max(2, int(round(staff.thickness * 4)))
    while x < tall.size:
        if not tall[x]:
            x += 1
            continue
        start = x
        while x < tall.size and tall[x]:
            x += 1
        if (x - start) <= width_limit:
            spans.append((staff.x0 + start, staff.x0 + x - 1))
    return spans


def restretch_barlines(gray: np.ndarray, ink: np.ndarray, staff: Staff,
                       spans: list[tuple[int, int]], top_track: np.ndarray,
                       bottom_track: np.ndarray, shade: int) -> None:
    """Redraw each barline between the new outer lines.

    Left alone, barlines would still span the old ruling: hanging above the new
    top line and stopping short of the new bottom one.
    """
    old_top = int(round(staff.lines[0]))
    old_bottom = int(round(staff.lines[-1]))
    h = gray.shape[0]

    for xa, xb in spans:
        a = xa - staff.x0
        b = xb - staff.x0 + 1
        top = max(0, int(round(float(np.mean(top_track[a:b])))))
        bottom = min(h - 1, int(round(float(np.mean(bottom_track[a:b])))))
        if bottom <= top:
            continue
        paper = local_paper(gray, old_top, xa, xb, staff.step)
        lo = min(old_top, top)
        hi = max(old_bottom, bottom)
        gray[lo:hi + 1, xa:xb + 1] = int(paper.max()) if paper.size else 255
        ink[lo:hi + 1, xa:xb + 1] = 0
        gray[top:bottom + 1, xa:xb + 1] = shade
        ink[top:bottom + 1, xa:xb + 1] = 255


def find_noteheads(ink: np.ndarray, staff: Staff, y_lo: int, y_hi: int,
                   x_from: int | None = None) -> list[tuple[float, float]]:
    """Notehead centroids in a horizontal band, by shape-preserving opening.

    An ellipse about a staff space across survives the opening; stems, ruling
    lines and beams do not.  The filters below are deliberately strict -- a
    false notehead here becomes a ledger line that tells the player the wrong
    pitch, which is worse than missing one.
    """
    y_lo = max(0, y_lo)
    y_hi = min(ink.shape[0] - 1, y_hi)
    if y_hi <= y_lo:
        return []
    x_start = staff.x0 if x_from is None else max(staff.x0, x_from)
    if x_start >= staff.x1:
        return []
    band = ink[y_lo:y_hi + 1, x_start:staff.x1 + 1]
    kw = max(3, int(round(staff.step * 0.85)))
    kh = max(2, int(round(staff.step * 0.55)))
    kernel = cv2.getStructuringElement(cv2.MORPH_ELLIPSE, (kw, kh))
    blobs = cv2.morphologyEx(band, cv2.MORPH_OPEN, kernel)

    count, _, stats, centroids = cv2.connectedComponentsWithStats(blobs, connectivity=8)
    heads: list[tuple[float, float]] = []
    min_area = 0.30 * staff.step * staff.step
    max_area = 2.2 * staff.step * staff.step
    for i in range(1, count):
        area = stats[i, cv2.CC_STAT_AREA]
        w = stats[i, cv2.CC_STAT_WIDTH]
        h = stats[i, cv2.CC_STAT_HEIGHT]
        if not (min_area <= area <= max_area):
            continue
        if w > staff.step * 1.8 or h > staff.step * 1.3:
            continue
        # Noteheads are solid ovals lying wider than they are tall.  The two
        # things that otherwise slip through are clef curls (not solid) and the
        # star-shaped agrement signs of French prints (solid, but square).
        if w * h == 0 or area / float(w * h) < 0.62:
            continue
        if w < h * 1.05:
            continue
        cx, cy = centroids[i]
        heads.append((cx + x_start, cy + y_lo))
    return heads


MAX_LEDGERS = 5


def add_ledgers(gray: np.ndarray, ink: np.ndarray, staff: Staff, new_lines: list[float],
                shade: int, above: bool, bound: float) -> int:
    """Rule the ledger lines the shift made necessary.

    Moving the ruling down leaves the topmost old line outside the staff; any
    note sitting up there now needs its own short line, and so does every line
    position between it and the staff.  (Below the staff the new ruling simply
    absorbs the old ledgers, so nothing is needed there.)

    ``bound`` is the y past which the neighbouring system starts: without it the
    search runs straight into the staff above and hangs ledger lines off notes
    that belong to another part.
    """
    step = staff.step
    edge = new_lines[0] if above else new_lines[-1]
    if above:
        y_lo = int(max(bound, edge - step * MAX_LEDGERS))
        y_hi = int(edge - step * 0.25)
    else:
        y_lo = int(edge + step * 0.25)
        y_hi = int(min(bound, edge + step * MAX_LEDGERS))
    if y_hi <= y_lo:
        return 0

    added = 0
    half = step / 2.0
    stub = max(3, int(round(step * 1.0)))
    # Nothing before the clef and key signature can be a note.
    x_from = staff.x0 + int(round(step * 3.0))
    for cx, cy in find_noteheads(ink, staff, y_lo, y_hi, x_from):
        # Diatonic distance of the head from the staff edge, in half-steps.
        positions = (edge - cy) / half if above else (cy - edge) / half
        n = int(np.floor(positions + 0.35))
        if n < 2:
            continue
        for j in range(1, min(n // 2, MAX_LEDGERS) + 1):
            y = edge - j * step if above else edge + j * step
            if not (0 <= y < gray.shape[0]):
                continue
            xa = max(0, int(round(cx - stub)))
            xb = min(gray.shape[1] - 1, int(round(cx + stub)))
            # Skip if something is already ruled there.
            if ink[int(round(y)), xa:xb + 1].mean() > 200:
                continue
            draw_line(gray, ink, y, xa, xb, staff.thickness, shade)
            added += 1
    return added


def reline_staff(gray: np.ndarray, ink: np.ndarray, staff: Staff, shift: int,
                 do_ledgers: bool, bounds: tuple[float, float]) -> tuple[int, int]:
    """Apply the ruling shift to one staff. Returns (ledgers added, barlines moved)."""
    if shift == 0:
        return 0, 0

    shade = ink_shade(gray, staff)
    step = staff.step
    barlines = find_barlines(ink, staff)

    # Track before erasing anything: the new lines are ruled parallel to the
    # surviving edge line, so they inherit the plate's wobble.
    if shift > 0:
        vacating = staff.lines[:shift]
        kept = staff.lines[shift:]
        reference = track_line(ink, staff, staff.lines[-1])[0]
        new_tracks = [reference + (i + 1) * step for i in range(shift)]
        top_track = track_line(ink, staff, staff.lines[shift])[0]
        bottom_track = new_tracks[-1]
    else:
        k = -shift
        vacating = staff.lines[5 - k:]
        kept = staff.lines[:5 - k]
        reference = track_line(ink, staff, staff.lines[0])[0]
        new_tracks = [reference - (i + 1) * step for i in range(k)]
        top_track = new_tracks[-1]
        bottom_track = track_line(ink, staff, staff.lines[4 - k])[0]

    for y in vacating:
        erase_line(gray, ink, staff, y)
    for track in new_tracks:
        draw_tracked_line(gray, ink, staff, track, staff.thickness, shade)

    new_lines = sorted(kept + [float(np.mean(t)) for t in new_tracks])
    if barlines:
        restretch_barlines(gray, ink, staff, barlines, top_track, bottom_track, shade)

    ledgers = 0
    if do_ledgers:
        above = shift > 0
        ledgers = add_ledgers(gray, ink, staff, new_lines, shade, above,
                              bounds[0] if above else bounds[1])

    staff.lines = new_lines
    return ledgers, len(barlines)


# --------------------------------------------------------------------------
# Driver
# --------------------------------------------------------------------------

def process_page(gray: np.ndarray, index: int, dpi: int, shift: int,
                 deskew: bool, do_ledgers: bool) -> tuple[np.ndarray, np.ndarray, PageReport]:
    """Returns (relined page, page as it stood before the edit, report).

    The "before" copy is taken after deskewing so that the two images line up
    pixel for pixel, which is what lets the viewer flip between them.
    """
    skew = 0.0
    ink = binarize(gray, dpi)
    if deskew:
        skew = estimate_skew(ink)
        if abs(skew) >= 0.15:
            gray = rotate(gray, skew, 255)
            ink = binarize(gray, dpi)
        else:
            skew = 0.0
    original = gray.copy()

    thickness, space = run_length_stats(ink)
    staves = group_staves(find_staff_lines(ink, thickness), space, thickness)

    report = PageReport(
        index=index,
        width=int(gray.shape[1]),
        height=int(gray.shape[0]),
        skew_deg=round(skew, 3),
        staff_space=round(space, 2),
        staff_line=round(thickness, 2),
        staves=staves,
    )

    if shift:
        bounds = staff_bounds(staves, gray.shape[0])
        for staff, bound in zip(staves, bounds):
            ledgers, bars = reline_staff(gray, ink, staff, shift, do_ledgers, bound)
            report.ledgers_added += ledgers
            report.barlines_adjusted += bars
    return gray, original, report


def staff_bounds(staves: list[Staff], height: int) -> list[tuple[float, float]]:
    """For each staff, how far above and below it the search may reach.

    Halfway to the neighbouring system: past that point any notehead belongs to
    another part, not to this one.
    """
    out: list[tuple[float, float]] = []
    for i, staff in enumerate(staves):
        above = 0.0 if i == 0 else (staves[i - 1].lines[-1] + staff.lines[0]) / 2.0
        below = float(height - 1) if i == len(staves) - 1 else \
            (staff.lines[-1] + staves[i + 1].lines[0]) / 2.0
        out.append((above, below))
    return out


def main() -> int:
    ap = argparse.ArgumentParser(description="Re-rule staves so old clefs read modern.")
    ap.add_argument("input")
    ap.add_argument("--mode", choices=("analyze", "apply"), default="analyze")
    ap.add_argument("--shift", type=int, default=None,
                    help="line positions to move the ruling (positive = downward)")
    ap.add_argument("--clef", choices=sorted(CLEF_SHIFTS), default=None,
                    help="source clef, as a shorthand for --shift")
    ap.add_argument("--dpi", type=int, default=300)
    ap.add_argument("--pages", default=None, help="e.g. 1-4 or 2,5")
    ap.add_argument("--out", default=None, help="directory for rendered pages")
    ap.add_argument("--pdf", action="store_true", help="also assemble a PDF of the result")
    ap.add_argument("--no-deskew", action="store_true")
    # Off by default: telling a notehead from an agrement sign on a 17th-century
    # plate is an OMR problem in its own right, and a ledger line drawn under an
    # ornament states a pitch that is not there.  A note left sitting one place
    # above the top line reads perfectly well without one.
    ap.add_argument("--ledgers", action="store_true",
                    help="also rule ledger lines for notes the shift left outside the staff")
    args = ap.parse_args()

    shift = args.shift
    if shift is None:
        shift = CLEF_SHIFTS[args.clef] if args.clef else 0
    if args.mode == "analyze":
        shift = 0

    pages = load_pages(args.input, args.dpi, args.pages)
    out_dir = args.out
    if out_dir:
        os.makedirs(out_dir, exist_ok=True)

    reports: list[PageReport] = []
    written: list[str] = []
    for i, gray in enumerate(pages):
        result, original, report = process_page(gray, i, args.dpi, shift,
                                                not args.no_deskew, args.ledgers)
        if out_dir:
            if shift:
                # The untouched page goes out alongside, so a viewer can flip
                # between the two without a second pass over the document.
                before = os.path.join(out_dir, f"page-{i + 1:03d}.png")
                cv2.imwrite(before, original)
                report.image = before
            path = os.path.join(out_dir, f"page-{i + 1:03d}{'-relined' if shift else ''}.png")
            cv2.imwrite(path, result)
            report.output = path
            written.append(path)
            if not shift:
                report.image = path
        reports.append(report)

    if args.pdf and written:
        from PIL import Image

        first, *rest = [Image.open(p).convert("L") for p in written]
        pdf_path = os.path.join(out_dir, "relined.pdf")
        first.save(pdf_path, save_all=True, append_images=rest, resolution=args.dpi)

    json.dump({
        "input": args.input,
        "dpi": args.dpi,
        "shift": shift,
        "pages": [asdict(r) for r in reports],
    }, sys.stdout, indent=None)
    sys.stdout.write("\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
