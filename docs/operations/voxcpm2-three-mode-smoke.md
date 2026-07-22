# VoxCPM2 three-mode voice inference smoke

This is an operator procedure, not a recorded smoke result. It does not authorize use of non-consented voice audio. Use only a WAV whose voice owner has consented to this use, and keep its content, transcript, artifacts, and API token out of tickets, shell history, and this document.

## Discover the installed services

Run from the Hub checkout. This queries the current Hub database through `app/bootstrap.php`; it does not assume a Compose location, service key, or models root.

```bash
cd "$(git rev-parse --show-toplevel)"
php -r '
require "app/bootstrap.php";
$db = hub_db();
$stmt = $db->query("SELECT pack_id, service_key, compose_file, compose_project FROM services WHERE pack_id IN (\"whisper-asr\", \"tts-voxcpm2\") ORDER BY pack_id, id");
foreach ($stmt as $service) {
    printf("pack=%s service_key=%s compose=%s compose_project=%s\\n", $service["pack_id"], $service["service_key"], hub_path((string)$service["compose_file"]), $service["compose_project"]);
}
printf("AIHUB_MODELS_DIR=%s\\n", hub_get_storage_setting($db, "AIHUB_MODELS_DIR"));
'
```

Select the ASR and TTS rows to exercise, then enter the values just printed. Do not derive either Compose path from a convention.

```bash
read -r -p 'ASR service key: ' ASR_SERVICE_KEY
read -r -p 'ASR Compose path: ' ASR_COMPOSE
read -r -p 'ASR Compose project: ' ASR_COMPOSE_PROJECT
read -r -p 'TTS service key: ' TTS_SERVICE_KEY
read -r -p 'TTS Compose path: ' TTS_COMPOSE
read -r -p 'TTS Compose project: ' TTS_COMPOSE_PROJECT
```

`AIHUB_MODELS_DIR` is the current deployment configuration for model storage. Use its discovered value when checking capacity or deployed files; do not replace it with a stale hard-coded models root.

## Start and verify the selected containers

The generated Compose files include their adjacent `.env` files. Build and start both selected services, then verify the host GPU and run the unit tests in the running containers.

```bash
docker compose --env-file "$(dirname "$ASR_COMPOSE")/.env" -f "$ASR_COMPOSE" -p "$ASR_COMPOSE_PROJECT" up -d --build
docker compose --env-file "$(dirname "$TTS_COMPOSE")/.env" -f "$TTS_COMPOSE" -p "$TTS_COMPOSE_PROJECT" up -d --build
nvidia-smi
docker compose --env-file "$(dirname "$ASR_COMPOSE")/.env" -f "$ASR_COMPOSE" -p "$ASR_COMPOSE_PROJECT" exec -T "$ASR_SERVICE_KEY" python3 -m unittest -v test_app.py
docker compose --env-file "$(dirname "$TTS_COMPOSE")/.env" -f "$TTS_COMPOSE" -p "$TTS_COMPOSE_PROJECT" exec -T "$TTS_SERVICE_KEY" python3 -m unittest -v test_app.py
```

For the GPU Compose services, the Docker NVIDIA runtime is required to start the GPU container. An ASR CPU fallback occurs only inside a successfully started container; it is not a replacement for a missing host GPU runtime. `WHISPER_REAL_INFERENCE=1` must be present in the selected ASR service configuration for this procedure.

## Authenticated Playground smoke

Use an authenticated Playground session and a TTS-permitted API token. Enter that token only in the protected Playground control; do not put it in a command, response example, or log.

1. Upload a consented WAV, or reuse its existing owner-only voice profile. The profile must belong to the authenticated token owner.
2. Expect real ASR to create an editable draft transcript. With `WHISPER_REAL_INFERENCE=1`, successful real ASR is identified by a response such as `"mock": false`; a CUDA run also reports `"effective": "cuda"`.
3. Review the draft without copying private content into operational records, correct it if needed, and explicitly confirm it. The confirmation is the gate for Ultimate Clone.
4. Before confirmation, submit an `ultimate_clone` attempt once. It must return HTTP `409` with `voice_profile_transcript_unconfirmed`. This is expected; do not bypass it or substitute a local audio/transcript value.
5. After confirmation, run each mode once and retain three independently generated owner-only WAV artifacts: Design, Basic Clone, and Ultimate Clone. For a real TTS smoke, each result must report `"mock": false`; mock output is not a real inference pass.

The inputs differ by concept:

- Design uses text plus a voice-design description and no profile.
- Basic Clone uses text with the authenticated owner's managed profile and the optional basic control concept.
- Ultimate Clone uses text with that managed profile and its confirmed transcript; the service supplies the managed reference internally, never via a host path.

If ASR first tries CUDA and then reports CPU as its effective device with fallback recorded, that is still real inference and must remain `"mock": false`. It is distinct from mock output and must be recorded as CPU fallback, not as CUDA success. A GPU-container startup failure is a Docker/runtime problem, not evidence of this in-container fallback.

## References and result handling

Use the upstream behavior references when investigating a genuine runtime issue: [faster-whisper README](https://github.com/SYSTRAN/faster-whisper/blob/master/README.md) and [VoxCPM2 documentation](https://huggingface.co/openbmb/VoxCPM2).

Do not add private audio, transcripts, tokens, generated WAV files, request identifiers, host reference paths, or model details to the smoke record. Record only the pass/fail decision and whether execution was CUDA, CPU fallback, or mock. This document intentionally contains no real smoke results.
