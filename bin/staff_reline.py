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
            # np.array, not np.asarray: the PIL image wraps pdfium's own
            # buffer, which comes back read-only whenever this page's skew is
            # too small to trigger the rotate() in process_page -- the one
            # place downstream that happens to force a writable copy.  Every
            # edit (erase_line, draw_tracked_line, ...) mutates this array in
            # place, so it must own writable memory regardless of that path.
            pages.append(np.array(bitmap.to_pil().convert("L")))
        return pages

    img = cv2.imread(path, cv2.IMREAD_GRAYSCALE)
    if img is None:
        raise RuntimeError(f"cannot read image: {path}")
    return [img]


def count_pages(path: str) -> int:
    """How many pages the file has: the PDF's page count, or 1 for an image.

    Kept separate from load_pages so the caller can pre-fill the page-count
    field without rendering (and paying for) a single page.
    """
    if os.path.splitext(path)[1].lower() == ".pdf":
        import pypdfium2 as pdfium

        doc = pdfium.PdfDocument(path)
        try:
            return len(doc)
        finally:
            doc.close()
    return 1


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


def _longest_run(cols: np.ndarray, max_gap: int) -> tuple[int, int]:
    """The widest contiguous stretch of ``cols``, bridging gaps up to max_gap.

    A ruling line's own ink is unbroken but for scan noise, so any gap this
    small is still the line.  A rubric or a caption sitting on the same row
    band -- "D, la, re." beside a clef, say -- is separated from the staff by
    a much wider gap, and must not be folded into the line's extent: that is
    what previously let a stray mark drag a line's measured width far past
    the system it belongs to.
    """
    breaks = np.flatnonzero(np.diff(cols) > max_gap)
    starts = np.concatenate(([0], breaks + 1))
    ends = np.concatenate((breaks, [cols.size - 1]))
    lengths = cols[ends] - cols[starts]
    best = int(np.argmax(lengths))
    return int(cols[starts[best]]), int(cols[ends[best]])


def find_staff_lines(ink: np.ndarray, thickness: float,
                     space: float) -> list[tuple[float, int, int]]:
    """Candidate ruling lines as (y centre, x0, x1).

    A staff line is a row whose ink spans most of the width it covers.  We take
    rows above a fraction of the strongest row, merge vertically adjacent rows
    into one line, and measure how far each extends horizontally -- as the
    longest unbroken run of ink in that band, not the outer bounds of whatever
    ink happens to share its height, which could belong to a caption or a
    neighbouring system's flourish nowhere near the actual ruling.
    """
    profile = ink.sum(axis=1, dtype=np.float64) / 255.0
    if profile.max() <= 0:
        return []
    threshold = 0.45 * float(np.percentile(profile[profile > 0], 99.5))
    hot = profile >= max(threshold, 0.15 * ink.shape[1])
    if not hot.any():
        return []

    # Doubled, not left at one staff space: dense passages regularly interrupt
    # a single row's ink for longer than that -- a run of beamed sixteenths
    # sitting just off the line, say -- which otherwise truncates this one
    # line's measured run well short of where the ruling actually ends, long
    # before a caption or a brace is anywhere close enough to bridge into.
    # That earlier-truncated run then has nothing in common with its four
    # siblings, so it cannot false-agree its way into group_staves's cluster
    # either -- it is what left staves with no >=3 consensus at all. Widening
    # the bridge fixes the truncation at its source; group_staves's consensus
    # step (which this width increase leans on more, not less) is still what
    # keeps a genuine brace or rubric bleeding into one line from moving the
    # staff's bounds, as long as the other four lines still agree with each
    # other -- and empirically they still heavily outvote the contaminated
    # one even at this width.
    max_gap = max(12, int(round(space))) * 2
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
        x0, x1 = _longest_run(cols, max_gap)
        lines.append(((start + y - 1) / 2.0, x0, x1))
    return lines


def _consensus_edge(values: list[int], tol: float) -> tuple[int, int]:
    """The five lines of one staff are ruled to the same physical width, so
    where their measured x0 (or x1) disagree, that is measurement failure on
    some of them, not real difference -- _longest_run bleeding into a brace
    or a rubric on one line, or losing the thread across a gap wider than it
    bridges on another.  Both failures are typically confined to a minority
    of the five, so the biggest group of lines that agree with each other
    (not necessarily with the numeric middle, which a two-versus-two split
    can still misplace) is the best estimate of where the ruling really
    starts or ends.  Ties keep the first, widening, cluster found.

    Returns (estimate, support) -- support is how many of the five landed in
    the winning cluster, so a caller can tell a confident answer from one
    that is just the least-bad of five lines that never agreed on anything.
    """
    best = [values[0]]
    for v in values:
        cluster = [u for u in values if abs(u - v) <= tol]
        if len(cluster) > len(best):
            best = cluster
    return round(sum(best) / len(best)), len(best)


def group_staves(lines: list[tuple[float, int, int]], space: float,
                 thickness: float) -> list[Staff]:
    """Collect the detected lines into groups of five evenly spaced ones."""
    expected = space + thickness
    provisional: list[tuple[list[tuple[float, int, int]], int, int, int, int, float]] = []
    i = 0
    while i + 4 < len(lines):
        window = lines[i:i + 5]
        gaps = [window[j + 1][0] - window[j][0] for j in range(4)]
        mean_gap = sum(gaps) / 4.0
        even = all(abs(g - mean_gap) <= 0.30 * mean_gap for g in gaps)
        plausible = 0.55 * expected <= mean_gap <= 1.8 * expected
        if even and plausible:
            tol = max(15, round(mean_gap))
            x0, n0 = _consensus_edge([w[1] for w in window], tol)
            x1, n1 = _consensus_edge([w[2] for w in window], tol)
            provisional.append((window, x0, n0, x1, n1, mean_gap))
            i += 5
        else:
            i += 1

    # A long enough note-sparse stretch can lose the ruling on every one of
    # the five lines, not just a minority -- nothing left on the staff to
    # take a consensus from at all.  A page's systems overwhelmingly share
    # the same left/right margins, though, so the fix is to borrow from
    # whichever other staves on this page did measure confidently, rather
    # than keep an answer no line on this staff actually agrees with.
    good_x0 = [p[1] for p in provisional if p[2] >= 3]
    good_x1 = [p[3] for p in provisional if p[4] >= 3]
    fallback_x0 = round(float(np.median(good_x0))) if good_x0 else None
    fallback_x1 = round(float(np.median(good_x1))) if good_x1 else None

    staves: list[Staff] = []
    for window, x0, n0, x1, n1, mean_gap in provisional:
        # Every line synthesised later -- new ruling drawn to replace a
        # vacated one -- is drawn across this staff-wide width, so a bound
        # thrown off by unreliable lines reappears as ink sticking out past
        # (or falling short of) where the real staff lines go.  The fallback
        # only overrides a lone, unconfirmed reading (n0/n1 == 1) though, not
        # a pair that independently agree with each other: two of five lines
        # landing on the same value is real local evidence, and this staff's
        # own margin -- a first system widened by a rubric, say -- can be the
        # one that is actually right against a page where every other system
        # shares a narrower one.
        if n0 < 2 and fallback_x0 is not None:
            x0 = fallback_x0
        if n1 < 2 and fallback_x1 is not None:
            x1 = fallback_x1
        staves.append(Staff(
            index=len(staves),
            lines=[w[0] for w in window],
            x0=x0,
            x1=x1,
            step=mean_gap,
            thickness=thickness,
        ))
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
    y is known still drifts several pixels across the width of a system.
    Erasing a line works off this path rather than a straight average, which is
    what keeps stems and slurs that cross it intact instead of nicking a corner
    a straight line would have missed.  Ruling a new line parallel to one wants
    the same drift removed again first -- see ``_straighten`` -- since a line
    that never existed on the plate has no wobble of its own to be faithful to.

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
    """Interpolate the gaps, then median-filter out the symbol-crossing jitter.

    The median is edge-preserving by design -- exactly the property that lets
    it drop a notehead's centroid without dragging the path towards it -- but
    that same property leaves the step behind wherever nearby columns were
    pulled by different amounts (a beam on one side of a barline, nothing on
    the other), instead of blending it away.  Drawn at line weight, those
    steps read as kinks rather than the gentle wobble of a hand-etched plate.
    A wide mean pass on top removes them: it cannot reintroduce an outlier the
    median already rejected, only smooth the transitions between the plateaus
    the median left behind.
    """
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
    smoothed = np.median(view, axis=1)
    padded = np.pad(smoothed, (pad, pad), mode="edge")
    view = np.lib.stride_tricks.sliding_window_view(padded, w)
    return view.mean(axis=1)


def _straighten(ys: np.ndarray) -> np.ndarray:
    """A dead-straight line through a tracked path's overall position and tilt.

    ``track_line`` is built to follow a real printed line's wobble faithfully,
    which is exactly right when the line being drawn over is that same line --
    erasing it, or reaching a barline up to it.  A newly ruled line has no
    printed wobble of its own to be faithful to, though: it is drawn parallel
    to a tracked reference only to inherit that reference's position and
    slope, and carrying along the reference's local jitter as well reads as a
    shaky hand rather than a ruled line.  A linear fit keeps the former and
    drops the latter.
    """
    xs = np.arange(ys.size, dtype=np.float64)
    slope, intercept = np.polyfit(xs, ys, 1)
    return slope * xs + intercept


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


# How far outside the staff a note can plausibly sit -- five ledger positions,
# i.e. two and a half staff spaces -- shared by the barline bulge search below
# and by add_ledgers further down, since both are asking the same question:
# is there a notehead out here?
MAX_LEDGERS = 5


def find_barlines(ink: np.ndarray, staff: Staff,
                  bounds: tuple[float, float]) -> list[tuple[int, int]]:
    """Column spans of the barlines running the height of this staff.

    Height and width alone cannot tell a barline from a stem: a note whose
    stem reaches from a head near one edge of the staff to a beam near the
    other is just as tall and just as thin.  What tells them apart is what
    sits beside them -- a stem feeds a notehead, which is wider than any
    barline stroke and often sits just outside the staff rather than inside
    it, as far out as a ledger note can plausibly sit -- so a candidate is
    only kept if the margin around it, extended that far above and below the
    staff (but never past the neighbouring system) to catch a head sitting
    there, stays as narrow as the stroke itself.
    """
    y0 = int(round(staff.lines[0]))
    y1 = int(round(staff.lines[-1]))
    band = ink[y0:y1 + 1, staff.x0:staff.x1 + 1] > 0
    if band.size == 0:
        return []
    height = band.shape[0]
    width = band.shape[1]
    filled = band.sum(axis=0)
    tall = filled >= 0.82 * height

    reach = int(round(staff.step * MAX_LEDGERS / 2.0))
    y_lo = max(0, y0 - reach, int(bounds[0]))
    y_hi = min(ink.shape[0] - 1, y1 + reach, int(bounds[1]))
    wide = ink[y_lo:y_hi + 1, staff.x0:staff.x1 + 1] > 0
    # A ruling line reads as ink at nearly every column across the whole
    # system; that is exactly what a real barline crosses harmlessly, but it
    # would swamp the bulge search below.  A beam sitting close to a line's
    # row only covers the handful of notes it connects, not the system's full
    # width, so filtering by fill fraction keeps the beam visible to the
    # search while dropping the line itself -- proximity to a known line's y
    # is not enough, a beam can sit right against one.
    on_a_line = wide.sum(axis=1) >= 0.6 * width
    # And the rows between y0 and y1 are excluded outright, not just filtered
    # by fill fraction: a notehead sitting in an ordinary staff position -- on
    # any of the five lines, or in a space between them -- is the normal case
    # for a piece with any density of notes, not evidence of a stem reaching
    # past the staff.  Counting it here rejected every barline on a busy
    # passage, since some note sits within the margin of nearly every column.
    # Only a bulge genuinely outside the staff, in ledger territory, means
    # this candidate is a stem rather than a barline.  The boundary needs its
    # own margin too, not just the bare line position: a notehead centred
    # exactly on the top or bottom line is still an ordinary staff note, but
    # half its own height pokes past that line by construction, which the
    # bare boundary counted as a ledger bulge and rejected right along with
    # the genuine ones.
    buf = int(round(staff.step * 0.55))
    interior = np.zeros(wide.shape[0], dtype=bool)
    interior[max(0, y0 - buf - y_lo):max(0, min(wide.shape[0], y1 + buf - y_lo + 1))] = True
    keep_rows = np.flatnonzero(~on_a_line & ~interior)

    spans: list[tuple[int, int]] = []
    x = 0
    width_limit = max(2, int(round(staff.thickness * 4)))
    margin = max(width_limit, int(round(staff.step * 0.4)))
    while x < tall.size:
        if not tall[x]:
            x += 1
            continue
        start = x
        while x < tall.size and tall[x]:
            x += 1
        if (x - start) > width_limit:
            continue
        lo = max(0, start - margin)
        hi = min(width, x + margin)
        if keep_rows.size:
            row_widths = wide[keep_rows][:, lo:hi].sum(axis=1)
            if row_widths.size and row_widths.max() > (x - start) + width_limit:
                continue  # a notehead bulges into the margin: a stem, not a barline
        # And rejected if it falls in the gap between the system's own
        # opening barline and where real notation begins.  A clef's tail, or
        # an accidental in the key signature, routinely narrows to barline
        # width for a few columns without ever bulging outside the staff --
        # its curve stays well inside the staff throughout -- which is
        # exactly what the checks above cannot see, since both only look for
        # width at this column and bulges past the staff edge.  Redrawing a
        # candidate like that as a straight stroke punches a straight segment
        # into the curve instead of leaving it alone.  Nothing but the
        # system's real opening barline, right at x0, legitimately starts
        # this early; add_ledgers uses the same "nothing before here is a
        # note" reasoning for the same reach past the clef and key signature.
        clef_zone_lo = staff.x0 + int(round(0.75 * staff.step))
        clef_zone_hi = staff.x0 + int(round(4.0 * staff.step))
        if clef_zone_lo <= staff.x0 + start < clef_zone_hi:
            continue
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
    # A stroke that keeps going past the edge being trimmed isn't this
    # barline ending cleanly -- it is something else (a brace, a system
    # sitting close above or below) sharing the barline's column.  Redrawing
    # over it either severs it (a gap between two untouched stubs) or, since
    # the redraw is a straight stroke, punches a straight segment into what
    # may be a curved connector.  Once continuation is detected the only safe
    # move is to leave this span exactly as printed -- find_barlines already
    # screens out stems this way; this is the same check kept here too, since
    # it costs little and a wrong classification anywhere upstream should not
    # be able to carve a hole in, or deface, a real barline or brace.  The
    # window starts past the ruling line's own thickness -- its halo reaches a
    # pixel or two beyond old_top on its own, which would otherwise look like
    # a stroke that "continues" on every single barline.
    half = max(1, int(round(staff.thickness / 2.0)) + 1)
    fringe = max(2, int(round(staff.thickness)) + 2)

    for xa, xb in spans:
        a = xa - staff.x0
        b = xb - staff.x0 + 1
        top = max(0, int(round(float(np.mean(top_track[a:b])))))
        bottom = min(h - 1, int(round(float(np.mean(bottom_track[a:b])))))
        if bottom <= top:
            continue
        above_lo, above_hi = max(0, old_top - half - fringe), max(0, old_top - half)
        below_lo = min(h, old_bottom + half)
        below_hi = min(h, old_bottom + half + fringe)
        stub_above = (top > old_top and above_hi > above_lo
                     and ink[above_lo:above_hi, xa:xb + 1].any())
        stub_below = (bottom < old_bottom and below_hi > below_lo
                      and ink[below_lo:below_hi, xa:xb + 1].any())
        if stub_above or stub_below:
            continue
        paper = local_paper(gray, old_top, xa, xb, staff.step)
        lo = min(old_top, top)
        hi = max(old_bottom, bottom)
        gray[lo:hi + 1, xa:xb + 1] = int(paper.max()) if paper.size else 255
        ink[lo:hi + 1, xa:xb + 1] = 0
        gray[top:bottom + 1, xa:xb + 1] = shade
        ink[top:bottom + 1, xa:xb + 1] = 255


def _system_groups(staves: list[Staff]) -> list[list[Staff]]:
    """Cluster staves into systems: consecutive staves close enough together
    to be read as one system (and so share a connecting brace or bracket),
    as opposed to the wider gap down to the next system.

    There is no fixed distance that means "same system" across pages at
    different staff sizes, but within a page the two kinds of gap cluster
    tightly apart from each other -- so the split is found adaptively, from
    the gap sizes themselves, rather than guessed.
    """
    if len(staves) < 2:
        return [[st] for st in staves]
    gaps = [staves[i + 1].lines[0] - staves[i].lines[-1] for i in range(len(staves) - 1)]
    sorted_gaps = sorted(gaps)
    threshold = -1.0  # no confident split found: every staff is its own system
    if len(sorted_gaps) > 1:
        # The first jump big enough to mean anything, scanning from the
        # smallest gap up -- not the single biggest jump anywhere in the
        # sequence, which one outsized gap (a rubric-widened system, a
        # stretched-out final line) can plant well past the real boundary,
        # pulling every in-between gap into "same system" along with it and
        # merging several unrelated systems into one.  The within-system
        # cluster is the tight one at the low end; the first real break out
        # of it is the boundary this is looking for, wherever a second,
        # third, or outlier cluster sits beyond that.
        for i in range(len(sorted_gaps) - 1):
            jump = sorted_gaps[i + 1] - sorted_gaps[i]
            if jump >= 8.0 and jump >= 0.10 * sorted_gaps[i]:
                threshold = (sorted_gaps[i] + sorted_gaps[i + 1]) / 2.0
                break
    groups = [[staves[0]]]
    for prev, cur, gap in zip(staves, staves[1:], gaps):
        if gap < threshold:
            groups[-1].append(cur)
        else:
            groups.append([cur])
    return groups


def find_accolade(ink: np.ndarray, x_lo: int, x_hi: int, y0: int, y1: int,
                  thickness: float) -> tuple[int, int] | None:
    """Column span of the brace or bracket connecting a system's staves.

    It runs the full height between the group's outer staves -- taller than
    any stem or beam fragment also crossing a strip this size -- so the
    widest sufficiently-tall run in the search strip, restricted to near the
    system's left edge where a brace is always drawn, is kept.
    """
    band = ink[y0:y1 + 1, x_lo:x_hi + 1] > 0
    if band.size == 0:
        return None
    height = band.shape[0]
    filled = band.sum(axis=0)
    tall = filled >= 0.85 * height
    width_limit = max(4, int(round(thickness * 6)))
    best = None
    x = 0
    while x < tall.size:
        if not tall[x]:
            x += 1
            continue
        start = x
        while x < tall.size and tall[x]:
            x += 1
        span_width = x - start
        if span_width <= width_limit and (best is None or span_width > (best[1] - best[0] + 1)):
            best = (x_lo + start, x_lo + x - 1)
    return best


def _accolade_component(ink: np.ndarray, xa: int, xb: int, old_top: int, old_bottom: int,
                        step: float, thickness: float,
                        line_ys: list[float]) -> tuple[np.ndarray, int, int] | None:
    """The full connected shape of the brace at this column span, including
    any cap or flourish at its tips, however far that flares out sideways.

    find_accolade locates the shaft by its width alone, which is exactly the
    stroke's own width and no wider -- a flared cap or serif at the top or
    bottom tip is routinely wider than that.  A capture the width of the
    shaft only moves the shaft: the cap, sitting outside that column, is left
    behind at the old position, severed from the shaft now sitting somewhere
    else.  Connectivity finds the whole printed shape the shaft belongs to,
    whatever its width at each point -- but every one of the group's ruled
    lines runs the system's full width, so anything else that happens to
    touch the same line far away in x -- a clef's loop three staff-spaces
    over, say -- would be swept in too, connected only via the line acting as
    a bridge, not because it has anything to do with the brace.  Each line is
    blanked out here everywhere except a narrow window right around the
    shaft, where the brace genuinely does cross it, which cuts exactly that
    bridge without touching the brace's own connectivity through the gaps
    between lines (never blanked) or through the lines within that window.
    """
    # Reaches one staff-space either side of the shaft, not the several
    # staff-spaces a clef and its key signature can occupy: wide enough for
    # a cap or flourish, which prints hard against the shaft it belongs to,
    # but short of where a neighbouring glyph would sit even when it grazes
    # the shaft's own line-crossing (cut separately below).  Shape alone
    # cannot always tell a brace's own cap from a glyph it happens to touch,
    # since both are printed at the same time and can share a pixel or two;
    # bounding how far the search can reach bounds that risk directly
    # instead.
    pad_y = int(round(2 * step))
    pad_x = int(round(1.0 * step))
    y_lo = max(0, old_top - pad_y)
    y_hi = min(ink.shape[0] - 1, old_bottom + pad_y)
    x_lo = max(0, xa - pad_x)
    x_hi = min(ink.shape[1] - 1, xb + pad_x)
    sub = (ink[y_lo:y_hi + 1, x_lo:x_hi + 1] > 0).astype(np.uint8).copy()

    half = max(1, int(round(thickness / 2.0)) + 1)
    keep_lo = max(0, (xa - x_lo) - half - 2)
    keep_hi = min(sub.shape[1], (xb - x_lo) + half + 3)
    for ly in line_ys:
        rel_y = int(round(ly)) - y_lo
        band_lo, band_hi = max(0, rel_y - half), min(sub.shape[0], rel_y + half + 1)
        if band_hi <= band_lo:
            continue
        sub[band_lo:band_hi, :keep_lo] = 0
        sub[band_lo:band_hi, keep_hi:] = 0

    _, labels = cv2.connectedComponents(sub, connectivity=8)
    seed_y = min(max((old_top + old_bottom) // 2 - y_lo, 0), sub.shape[0] - 1)
    seed_x = min(max((xa + xb) // 2 - x_lo, 0), sub.shape[1] - 1)
    label = labels[seed_y, seed_x]
    if label == 0:
        column = labels[:, seed_x]
        nonzero = np.flatnonzero(column)
        if nonzero.size == 0:
            return None
        label = column[nonzero[np.argmin(np.abs(nonzero - seed_y))]]

    mask = labels == label
    ys, xs = np.nonzero(mask)
    top0, left0 = y_lo + int(ys.min()), x_lo + int(xs.min())
    local_mask = mask[ys.min():ys.max() + 1, xs.min():xs.max() + 1]
    return local_mask, top0, left0


def restretch_accolade(gray: np.ndarray, ink: np.ndarray, left0: int, top0: int,
                       mask: np.ndarray, patch_gray: np.ndarray,
                       patch_ink: np.ndarray, shift_px: int, step: float) -> None:
    """Erase a brace or bracket's old footprint and paste it back shifted.

    A barline left at its old position was still a valid barline, just the
    wrong length, and redrawing it as a straight stroke between two new
    endpoints loses nothing a barline had to begin with.  A brace or bracket
    is a different shape: often curved, tapered, or carrying a flourish at
    each tip, so redrawing it the same way -- straight, between two new
    endpoints -- would replace that shape with a plain rectangle.  Every
    staff in one system moves by the same amount for a single clef shift,
    though, so the brace does not need reshaping, only moving: its own
    pixels, captured before any staff editing touched this column, are
    pasted back translated by that shift, keeping the shape exactly as
    printed.  Both the erase and the paste act only where ``mask`` says the
    brace's own connected shape actually is, not across the whole rectangle
    it happens to fit in, so neither step touches ink -- a clef curl brushing
    the same columns, say -- that just happens to share the bounding box
    without being part of the brace.
    """
    h, w = gray.shape
    mh, mw = mask.shape
    paper = local_paper(gray, top0, left0, min(left0 + mw - 1, w - 1), step)
    fill = int(paper.max()) if paper.size else 255
    region_gray = gray[top0:top0 + mh, left0:left0 + mw]
    region_ink = ink[top0:top0 + mh, left0:left0 + mw]
    region_gray[mask] = fill
    region_ink[mask] = 0

    new_top0 = top0 + shift_px
    dst_top, dst_bottom = max(0, new_top0), min(h - 1, new_top0 + mh - 1)
    if dst_bottom < dst_top:
        return
    src_top = dst_top - new_top0
    src_bottom = src_top + (dst_bottom - dst_top)
    dst_gray = gray[dst_top:dst_bottom + 1, left0:left0 + mw]
    dst_ink = ink[dst_top:dst_bottom + 1, left0:left0 + mw]
    sub_mask = mask[src_top:src_bottom + 1, :]
    dst_gray[sub_mask] = patch_gray[src_top:src_bottom + 1, :][sub_mask]
    dst_ink[sub_mask] = patch_ink[src_top:src_bottom + 1, :][sub_mask]


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
    barlines = find_barlines(ink, staff, bounds)

    # Track before erasing anything: the new lines are ruled parallel to the
    # surviving edge line, straightened so they take its position and slope
    # but not its local wobble (see _straighten).
    if shift > 0:
        vacating = staff.lines[:shift]
        kept = staff.lines[shift:]
        reference = _straighten(track_line(ink, staff, staff.lines[-1])[0])
        new_tracks = [reference + (i + 1) * step for i in range(shift)]
        top_track = track_line(ink, staff, staff.lines[shift])[0]
        bottom_track = new_tracks[-1]
    else:
        k = -shift
        vacating = staff.lines[5 - k:]
        kept = staff.lines[:5 - k]
        reference = _straighten(track_line(ink, staff, staff.lines[0])[0])
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
    staves = group_staves(find_staff_lines(ink, thickness, space), space, thickness)

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
        old_tops = [st.lines[0] for st in staves]
        old_bottoms = [st.lines[-1] for st in staves]

        # A brace or bracket connecting two or more staves must be captured
        # now, before any staff editing below reaches this column range --
        # its own pixels are what get pasted back after the shift, further
        # down, so they need to still be exactly as printed when grabbed.
        groups = [g for g in _system_groups(staves) if len(g) >= 2]
        patches = []
        for group in groups:
            first, last = group[0], group[-1]
            old_top = old_tops[staves.index(first)]
            old_bottom = old_bottoms[staves.index(last)]
            # The brace's own solid stroke sits somewhere left of the staff
            # lines' own measured x0, by anywhere from a couple of pixels to
            # several staff spaces depending on how far the clef sits from
            # the brace in this particular engraving -- there is no reliably
            # tight bound, so the search reaches well past x0 too, generous
            # enough to still catch it after any clef in between.
            x_hi = min(st.x0 for st in group) + int(round(5 * first.step))
            x_lo = max(0, x_hi - int(round(20 * first.step)))
            span = find_accolade(ink, x_lo, x_hi, int(round(old_top)), int(round(old_bottom)),
                                 thickness)
            if span is None:
                continue
            xa, xb = span
            line_ys = [y for st in group for y in st.lines]
            component = _accolade_component(ink, xa, xb, int(round(old_top)),
                                            int(round(old_bottom)), first.step,
                                            thickness, line_ys)
            if component is None:
                continue
            mask, top0, left0 = component
            mh, mw = mask.shape
            patches.append((first, last, left0, top0, mask,
                            gray[top0:top0 + mh, left0:left0 + mw].copy(),
                            ink[top0:top0 + mh, left0:left0 + mw].copy()))

        for staff, bound in zip(staves, bounds):
            ledgers, bars = reline_staff(gray, ink, staff, shift, do_ledgers, bound)
            report.ledgers_added += ledgers
            report.barlines_adjusted += bars

        for first, last, left0, top0, mask, patch_gray, patch_ink in patches:
            old_top = old_tops[staves.index(first)]
            old_bottom = old_bottoms[staves.index(last)]
            shift_px = int(round(((first.lines[0] - old_top) + (last.lines[-1] - old_bottom)) / 2.0))
            restretch_accolade(gray, ink, left0, top0, mask, patch_gray, patch_ink,
                               shift_px, first.step)
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
    ap.add_argument("--mode", choices=("analyze", "apply", "count"), default="analyze")
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

    # Cheap page-count report for pre-filling the form; no rendering.
    if args.mode == "count":
        json.dump({"count": count_pages(args.input)}, sys.stdout, indent=None)
        sys.stdout.write("\n")
        return 0

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
