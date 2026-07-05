#!/usr/bin/env python3
"""Loudness-based chromagram extraction for audio key/tonality detection.

Implements the loudness-based chromagram (LBC) of Ni, McVicar,
Santos-Rodriguez & De Bie, "An End-to-End Machine Learning System for
Harmonic Analysis of Music" (HPA), IEEE TASLP 20(6), 2012, Section III.

The pipeline mirrors the paper:

  1. mono downmix at 11025 Hz
  2. harmonic/percussive source separation (HPSS); the harmonic part is kept
  3. constant-Q transform over A1..A6 (five octaves, as in the paper)
  4. power -> sound pressure level (SPL), i.e. a log of the power spectrum
  5. A-weighting per CQT bin to approximate perceived loudness
  6. loudness summed per pitch class -> a 12-bin chromagram, then normalised

Rather than the paper's beat-synchronous frames (which target chord decoding),
this script emits chromagrams over fixed-length, overlapping windows so the PHP
side can run its Krumhansl-Schmuckler estimator per window and build a local-key
timeline, plus one global chromagram for the whole file.

Output: a single JSON object on stdout:
  {
    "sr": 11025,
    "duration": <seconds>,
    "global": [12 floats],
    "segments": [{"start": s, "end": s, "chroma": [12 floats]}, ...]
  }

The 12 bins are indexed by pitch class with C = 0, matching
App\\Service\\LocalKeyEstimator.
"""

import argparse
import json
import sys

import numpy as np

# librosa is heavy; import lazily so --help and arg errors stay fast.
SR = 11025
N_OCTAVES = 5            # A1..A6, as in the HPA paper
BINS_PER_OCTAVE = 12
FMIN_NOTE = "A1"         # 55 Hz, the paper's lower bound
LOG_COMPRESSION = 1.0    # log1p compression factor for the SPL transform


def a_weighting_db(freqs):
    """A-weighting gain in dB for an array of frequencies (IEC 61672 / [29])."""
    f2 = np.square(freqs)
    ra = (12194.0**2 * f2**2) / (
        (f2 + 20.6**2)
        * np.sqrt((f2 + 107.7**2) * (f2 + 737.9**2))
        * (f2 + 12194.0**2)
    )
    # +2 dB so A-weighting is ~0 dB at 1 kHz, per the standard.
    return 2.0 + 20.0 * np.log10(np.maximum(ra, 1e-12))


def loudness_chromagram(y, sr):
    """Return a (12, n_frames) loudness-based chromagram following HPA Sec. III."""
    import librosa

    n_bins = N_OCTAVES * BINS_PER_OCTAVE
    fmin = librosa.note_to_hz(FMIN_NOTE)

    # Constant-Q transform (eq. 2).
    cqt = librosa.cqt(
        y, sr=sr, fmin=fmin, n_bins=n_bins, bins_per_octave=BINS_PER_OCTAVE
    )
    mag = np.abs(cqt)

    # A-weighting per CQT bin (eqs. 3-4): low and high frequencies need more SPL
    # for the same perceived loudness as the mid-band. Applied as a linear gain
    # on the magnitudes so the subsequent octave sum is a true loudness sum.
    bin_freqs = fmin * (2.0 ** (np.arange(n_bins) / BINS_PER_OCTAVE))
    gain = 10.0 ** (a_weighting_db(bin_freqs) / 20.0)
    mag = mag * gain[:, np.newaxis]

    # SPL / loudness (eq. 1): a log compression of the (A-weighted) spectrum.
    # log1p is monotonic, zero at silence (so the noise floor is NOT lifted) and
    # emphasises low-energy pitches that are perceptually stronger than their raw
    # energy suggests -- the paper's motivation for using the log of the power.
    loud = np.log1p(LOG_COMPRESSION * mag)

    # Loudnesses are additive across non-adjacent frequencies (eq. 5): fold the
    # five octaves onto 12 pitch classes. CQT bin 0 = A (pc 9), so roll to C = 0.
    folded = loud.reshape(N_OCTAVES, BINS_PER_OCTAVE, -1).sum(axis=0)
    chroma = np.roll(folded, -3, axis=0)

    # Per-frame L2 normalisation (eq. 7): overall level is irrelevant to harmony.
    norm = np.linalg.norm(chroma, axis=0, keepdims=True)
    np.divide(chroma, norm, out=chroma, where=norm > 0)
    return chroma


def windowed_segments(chroma, sr, hop_length, window_s, overlap):
    """Aggregate frame chromagrams into overlapping fixed-length windows."""
    frames_per_window = max(1, int(round(window_s * sr / hop_length)))
    step = max(1, int(round(frames_per_window * (1.0 - overlap))))
    n_frames = chroma.shape[1]

    segments = []
    for start_f in range(0, max(1, n_frames - 1), step):
        end_f = min(start_f + frames_per_window, n_frames)
        block = chroma[:, start_f:end_f]
        if block.shape[1] == 0:
            continue
        profile = block.sum(axis=1)
        segments.append(
            {
                "start": round(start_f * hop_length / sr, 3),
                "end": round(end_f * hop_length / sr, 3),
                "chroma": [round(float(v), 6) for v in profile],
            }
        )
        if end_f >= n_frames:
            break
    return segments


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("audio", help="path to an audio file (wav/mp3/flac/...)")
    parser.add_argument(
        "--window", type=float, default=4.0,
        help="local-key window length in seconds (default: 4.0)",
    )
    parser.add_argument(
        "--overlap", type=float, default=0.5,
        help="window overlap fraction 0..1 (default: 0.5)",
    )
    parser.add_argument(
        "--no-hpss", action="store_true",
        help="skip harmonic/percussive separation (faster, less clean)",
    )
    args = parser.parse_args()

    try:
        import librosa
    except ImportError as exc:  # pragma: no cover - environment guard
        json.dump({"error": f"librosa not available: {exc}"}, sys.stdout)
        return 3

    try:
        y, sr = librosa.load(args.audio, sr=SR, mono=True)
    except Exception as exc:  # noqa: BLE001 - report any decode failure as JSON
        json.dump({"error": f"could not load audio: {exc}"}, sys.stdout)
        return 4

    if y.size == 0:
        json.dump({"error": "audio file is empty"}, sys.stdout)
        return 4

    if not args.no_hpss:
        y = librosa.effects.harmonic(y)

    hop_length = 512
    chroma = loudness_chromagram(y, sr)

    global_profile = chroma.sum(axis=1)
    g_norm = float(np.linalg.norm(global_profile))
    if g_norm > 0:
        global_profile = global_profile / g_norm

    result = {
        "sr": sr,
        "duration": round(float(len(y) / sr), 3),
        "global": [round(float(v), 6) for v in global_profile],
        "segments": windowed_segments(
            chroma, sr, hop_length, args.window, args.overlap
        ),
    }
    json.dump(result, sys.stdout)
    return 0


if __name__ == "__main__":
    sys.exit(main())
