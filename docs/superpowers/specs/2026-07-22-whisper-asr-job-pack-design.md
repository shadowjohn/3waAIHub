# Whisper ASR Job Pack Design

Date: 2026-07-22

Status: Proposed implementation supplement to the approved Job-first Audio Packs design

## Goal

Upgrade the existing `whisper-asr` Pack into the governed `speech_transcribe`
async capability. It replaces MyAI Voice's direct use of
`/park/conda_vm/faster-whisper` for new work; MyAI keeps its UI, job
orchestration, and artifact import logic.

The existing synchronous `asr` service endpoint remains only for health,
installation smoke, and audio at or below the configured synchronous limit.

## Reused runtime evidence

The currently working source environment is:

- Python: `/park/conda_vm/faster-whisper/bin/python` (3.11.15)
- `faster-whisper`: 1.2.1
- `ctranslate2`: 4.7.1
- verified model store: `/opt/models/faster-whisper`
- active MyAI default: `large-v3`

The Pack image pins equivalent dependencies. It does not invoke the host
conda environment at runtime. Existing models are imported or mounted through
Hub-managed Pack storage before the old service is retired.

## Route and execution

Only the fixed public mode is accepted:

```text
speech_transcribe -> whisper-asr / transcribe / job / gpu
```

The task worker uses the existing shared task queue and generic Pack-job
adapter. Before starting the one-shot Pack container, it acquires the shared
`gpu:0` lease. Container cleanup and artifact validation finish before the
fenced terminal transaction publishes task success and the callback outbox.

Clients cannot select a Pack ID, command, entrypoint, model path, environment
variable, or host path.

## Input contract

One managed audio source is accepted: an upload or an eligible same-member
audio artifact. The manifest allowlist accepts:

- `model_alias`: initially `large-v3` and `medium`; default `large-v3`
- `language`: `auto`, a supported language code, or `nan`
- `word_timestamps`: boolean, default false
- `diarization`: boolean, default false
- `min_speakers` and `max_speakers`: optional only when diarization is true;
  `min_speakers <= max_speakers`
- `output_srt` and `output_vtt`: booleans, default true

`diarization=0` loads ASR only. Alignment loads only when
`word_timestamps=1` or an output contract requires word timings. Pyannote
loads only when `diarization=1`; its token comes from a Hub secret setting and
never from task input, manifests, command lines, or logs.

Taiwanese Hokkien (`nan`) retains the proven bilingual prompt. Hub owns this
recognition preset; MyAI does not supply arbitrary prompts.

## Artifacts

Every successful task produces:

- `transcript_json`
- `transcription_report`

`subtitle_srt` and `subtitle_vtt` are conditional on their requested output
flags. `speaker_timeline` is produced only when diarization is enabled. The
runner also records the effective model version, device, compute type,
language, and applied recognition preset in `transcription_report`.

Hub validates each artifact as a regular file inside the task workspace,
detects MIME itself, recomputes size and SHA-256, probes audio input, and
parses report JSON. A missing or invalid required artifact fails the task with
`output_contract_invalid`; no partial success is published.

## Explicit boundaries

The Pack owns ASR, optional alignment/diarization, subtitle segmentation,
and output reports. It does not inherit MyAI's page code, database writes,
article/character workflows, or GPT-SoVITS/VoxCPM2 logic.

There is no CPU fallback for a scheduled GPU job. Insufficient free VRAM or
unmanaged GPU work leaves it `waiting_gpu`; it does not start competing model
processes.

## Acceptance

The smallest acceptance set is:

1. manifest validates a fixed `transcribe` job with the input/output contract;
2. invalid diarization/speaker combinations are rejected before execution;
3. a fixture run with diarization disabled loads ASR without alignment or
   pyannote and produces transcript, report, SRT, and VTT;
4. a fixture run with word timestamps loads alignment only;
5. a diarization fixture produces `speaker_timeline` only with an injected
   test secret;
6. a real RTX 5060 Ti smoke uses `large-v3`, obtains and releases `gpu:0`,
   then leaves no Pack GPU process behind.
