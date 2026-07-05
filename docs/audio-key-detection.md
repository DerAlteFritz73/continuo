# Audio tonality detection (loudness-based chromagram)

A second key/mode detector that works directly on **audio files**, complementing
the symbolic (MusicXML) path. Motivation: far more music exists as audio than as
MusicXML, so this extends tonality detection to that much larger corpus.

## Method

Based on the **loudness-based chromagram (LBC)** of Ni, McVicar, Santos-Rodríguez
& De Bie, *"An End-to-End Machine Learning System for Harmonic Analysis of Music"*,
IEEE TASLP 20(6), Aug. 2012, Section III. We use that paper's perceptual front
end and pair it with the Krumhansl–Schmuckler key-finding already used for
symbolic input — so both detectors share the same key-finding core and output
shape; only the front end differs (clean notes vs. perceptual chromagram).

Pipeline (`bin/audio_keychroma.py`):

1. mono downmix at 11025 Hz
2. harmonic/percussive separation (HPSS); harmonic part kept
3. constant-Q transform over A1–A6 (five octaves, as in the paper)
4. A-weighting per CQT bin → perceived loudness (paper eqs. 3–4)
5. log compression of the (A-weighted) spectrum — the paper's SPL/"log of power"
   step, which emphasises low-energy but perceptually relevant pitches
6. loudness summed per pitch class → 12-bin chromagram, L2-normalised (eq. 7)

Unlike the paper's beat-synchronous chord frames, the script emits chromagrams
over fixed-length overlapping windows, so the PHP side produces a **local-key
timeline** plus a **global** estimate. We do **not** implement the paper's full
HMM chord/key/bass decoder (that needs an annotated training corpus); for
tonality the chromagram + K–S correlation is the tractable, faithful core.

## Components

- `bin/audio_keychroma.py` — Python/librosa sidecar; emits JSON.
- `App\Service\AudioChromagramExtractor` — runs the sidecar via Symfony Process,
  parses JSON. Python interpreter: `AUDIO_PYTHON_BIN` env, else `var/audio-venv`,
  else `python3` on PATH.
- `App\Service\AudioKeyDetector` — scores each window's chromagram with
  `LocalKeyEstimator` (shared K–S core) → global key + timeline.
- `App\Command\DetectAudioKeyCommand` — `app:detect-audio-key`.

## Setup

Requires **ffmpeg** on PATH (for mp3/flac/m4a decoding).

```bash
python3 -m venv var/audio-venv
var/audio-venv/bin/pip install -r bin/audio-requirements.txt
```

The venv lives under `var/` (gitignored), so it is recreated per environment.

## Usage

```bash
# Human-readable: global key + local-key timeline
php bin/console app:detect-audio-key path/to/track.mp3

# Just the global key
php bin/console app:detect-audio-key track.mp3 --no-timeline

# JSON, for batch tagging an audio library
php bin/console app:detect-audio-key track.mp3 --json

# Tune window/overlap of the local-key timeline (seconds / fraction)
php bin/console app:detect-audio-key track.mp3 --window 6 --overlap 0.5
```

## Web UI

`POST /detect-audio-key` (route `app_detect_audio_key`) accepts an `audio` file
upload and returns JSON `{success, filename, duration, global, timeline}`, where
each key is `{label, tonicPc, mode, fifths, correlation, confidence}`. The import
pane has an audio drop-zone (`templates/continuo/index.html.twig`,
`public/js/audio-key.js`, `public/css/audio-key.css`) that renders the global key
plus a collapsible local-key timeline.

Upload limits were raised to 30 MB to fit audio: `docker/nginx/default.conf`
(`client_max_body_size 30m`) and the Dockerfile PHP ini (`upload_max_filesize
30M`, `post_max_size 32M`). The CLI command reads from disk and has no such limit.

## Status / limitations

- **Validated on a real recording:** the CC0 Kimiko Ishizaka recording of Bach
  WTC I Prelude No. 1 (BWV 846), ground-truth C major, is detected as **C major,
  correlation 0.70, confidence high** (CLI, full 163 s) and 0.81 high on a 25 s
  excerpt via the HTTP endpoint. Local-key windows tonicise ii/V/vi/V-of-V, which
  matches the prelude's actual harmony.
- Also verified on synthetic I–IV–V–I and a C-major scale.
- Not yet swept over a large annotated corpus (e.g. isophonics/Beatles).
- **Docker image not yet provisioned** with python3 + librosa + ffmpeg, so the
  web endpoint only works where the venv exists (native host). Installing
  librosa/numba on the Alpine base is non-trivial and untested — open follow-up.
  Until then the endpoint fails gracefully with a JSON error.
- The full HPA HMM (joint chord/key/bass) is out of scope — see paper §IV.
