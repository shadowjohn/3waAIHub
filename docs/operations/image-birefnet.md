# BiRefNet Operations Runbook

Run commands from the 3waAIHub checkout. Model provisioning is the only step
that needs network access and write access to model storage. The inference
service runs as UID/GID `65532:65532`, mounts the model read-only, and keeps
Hugging Face and application scratch data under container `/tmp`.

The approved model source is `ZhengPeng7/BiRefNet` at revision
`e2bf8e4460fc8fa32bba5ea4d94b3233d367b0e4`. Treat the pinned snapshot as
executable code because the loader uses the repository's local custom model
code. Do not replace it with an unreviewed snapshot.

## Build And Provision

Build the image before provisioning so the downloader and checksum writer are
the same versions used by the runtime:

```bash
docker build -t 3waaihub-image-birefnet:test packs/image-birefnet/service
sudo install -d -m 0755 /DATA/models/birefnet
sudo docker run --rm --user 0:0 \
  -e BIREFNET_MODEL_DIR=/models/birefnet \
  -v /DATA/models/birefnet:/models/birefnet \
  3waaihub-image-birefnet:test python3 /app/provision_offline_assets.py
```

The command must report the pinned revision. At the time of L5 acceptance the
snapshot contained 9 runtime files totaling 444,584,498 bytes. Inspect the
marker and verify every recorded size and SHA-256 before starting a service:

```bash
jq '{repository, revision, created_at, files: (.files | length), total_bytes: ([.files[].size] | add)}' \
  /DATA/models/birefnet/ready.json
sudo docker run --rm \
  -e BIREFNET_MODEL_DIR=/models/birefnet \
  -v /DATA/models/birefnet:/models/birefnet:ro \
  3waaihub-image-birefnet:test python3 -c \
  'import json; from model_runtime import verify_ready; print(json.dumps(verify_ready(), sort_keys=True))'
```

`verify_ready()` performs the full checksum pass. `/health` deliberately checks
only the pinned marker so ordinary health polling does not hash 444 MB.

## Install The Pack

The default Pack installation is GPU-first. This creates or refreshes the
`birefnet-main` service on loopback port `18112`:

```bash
php <<'PHP'
<?php
require 'app/bootstrap.php';
$db = hub_db();
hub_migrate($db);
$result = hub_install_pack($db, 'image-birefnet', [
    'service_key' => 'birefnet-main',
    'name' => 'BiRefNet GPU',
    'mode' => 'background_remove',
    'port_mode' => 'manual',
    'local_port' => 18112,
    'environment' => 'production',
    'idempotent' => true,
]);
echo $result['service']['compose_file'], PHP_EOL;
PHP
```

For an explicit CPU instance, use a separate service key, mode, and port. The
generated Compose omits `gpus: all` only when `BIREFNET_USE_GPU=0`:

```bash
php <<'PHP'
<?php
require 'app/bootstrap.php';
$db = hub_db();
hub_migrate($db);
$result = hub_install_pack($db, 'image-birefnet', [
    'service_key' => 'birefnet-cpu',
    'name' => 'BiRefNet CPU',
    'mode' => 'background_remove_cpu',
    'port_mode' => 'manual',
    'local_port' => 18113,
    'environment' => 'production',
    'idempotent' => true,
    'env' => ['BIREFNET_USE_GPU' => '0', 'BIREFNET_DEVICE' => 'cpu'],
]);
echo $result['service']['compose_file'], PHP_EOL;
PHP
```

Start installed instances through the Hub service controls. For a direct
operator smoke, the checked-in Compose starts the GPU service:

```bash
AIHUB_MODELS_DIR=/DATA/models BIREFNET_LOCAL_PORT=18112 \
  docker compose -f packs/image-birefnet/docker-compose.yml up -d --build
```

## GPU Acceptance

Health must be ready before model initialization. A missing or mismatched model
marker returns `ok=false` and `ready=false`:

```bash
curl --fail --silent --show-error http://127.0.0.1:18112/health | jq
docker compose -f packs/image-birefnet/docker-compose.yml exec -T image-birefnet \
  python3 /app/storage_smoke.py
docker compose -f packs/image-birefnet/docker-compose.yml exec -T image-birefnet \
  python3 /app/model_smoke.py
```

The model smoke must report `device=cuda`. Run a real HTTP inference smoke and
require the `X-3waAIHub-Device: cuda` response metadata:

```bash
docker run --rm --network host --user "$(id -u):$(id -g)" \
  -v "$PWD/packs/image-birefnet:/pack:ro" \
  3waaihub-image-birefnet:test python3 /app/inference_smoke.py \
  --base-url http://127.0.0.1:18112 \
  --fixture /pack/demo/smoke.png --expect-device cuda
```

Run the declared Gateway benchmark against the installed GPU service:

```bash
php scripts/benchmark.php --case=birefnet_real_image \
  --pack=image-birefnet --service=birefnet-main
```

It must return a PNG with the source dimensions and all five approved metadata
headers. Then run the three-fixture L5 quality acceptance:

```bash
docker run --rm --network host --user "$(id -u):$(id -g)" \
  -v "$PWD/packs/image-birefnet:/pack:ro" \
  3waaihub-image-birefnet:test python3 /app/acceptance.py \
  --base-url http://127.0.0.1:18112 \
  --fixtures /pack/demo/acceptance --expect-device cuda
```

Each fixture must independently meet `F-score >= 0.80` and `MAE <= 0.10`.
Do not lower these gates to accept a model or post-processing change.

On 2026-07-23, the RTX 5060 Ti 16 GB host reported a 7.28 second cold request
(including model load), 0.37-0.38 second warm requests, and 0.396 second through
the Gateway for a 768x768 fixture. The Python process used about 2,860 MiB of
VRAM after loading. These are observations, not hard service-level objectives;
record new figures after driver, CUDA, model, or image changes.

## CPU Smoke

CPU fallback is supported but is not accepted as a GPU smoke result. To verify
the explicit CPU path without changing the GPU service, start a temporary
loopback-only container with no GPU allocation:

```bash
docker run -d --rm --name 3waaihub-birefnet-cpu \
  -p 127.0.0.1:18113:8000 \
  -e BIREFNET_USE_GPU=0 -e BIREFNET_DEVICE=cpu \
  -e BIREFNET_CPU_FALLBACK=1 \
  -e BIREFNET_MODEL_DIR=/models/birefnet \
  -e HF_HOME=/tmp/huggingface -e HF_HUB_OFFLINE=1 \
  -e TRANSFORMERS_OFFLINE=1 -e XDG_CACHE_HOME=/tmp/xdg -e HOME=/tmp/home \
  -v /DATA/models/birefnet:/models/birefnet:ro \
  3waaihub-image-birefnet:test

docker run --rm --network host --user "$(id -u):$(id -g)" \
  -v "$PWD/packs/image-birefnet:/pack:ro" \
  3waaihub-image-birefnet:test python3 /app/inference_smoke.py \
  --base-url http://127.0.0.1:18113 \
  --fixture /pack/demo/smoke.png --expect-device cpu

docker stop 3waaihub-birefnet-cpu
```

The 2026-07-23 CPU cold smoke on the same host completed the 1280x720 fixture
in 36.46 seconds. CPU remains a compatibility and recovery path, not the
preferred production route.

## Refresh And Rollback

Model refreshes are code changes. Change `MODEL_REVISION` in both
`provision_offline_assets.py` and `model_runtime.py`, provision into a fresh
staging directory, review the downloaded custom code, and rerun the complete
L3 storage, L4a initialization, L4b GPU/CPU inference, Gateway, and L5 quality
checks. Commit the revision only after every gate passes. Never repoint
`ready.json` by hand and never allow a request to download model files.

For rollback, stop the affected service, deploy the previous known-good Pack
commit and image tag, verify that commit's pinned revision, and start it against
the matching snapshot. Retain `/DATA/models/birefnet` by default. Remove that
directory only after operator confirmation that no running or rollback image
references it; model removal is not part of routine container cleanup.

## Limits And Evidence

- Inference resizes to a fixed 1024x1024 model input and returns the original
  image dimensions.
- Requests are limited to 8,192 pixels on either axis, 10,000,000 decoded
  pixels, 50 MB per file, 101 MB aggregate, and one concurrent inference.
- There is no tiling, batch API, video workflow, interactive editor, or output
  persistence. The Playground preview stays in the request/response lifecycle.
- Runtime network model downloads are disabled. The only persistent mount is
  the read-only model snapshot; request images and generated PNGs are not
  written to model, cache, or service-data volumes.
- Keep only benchmark JSON, image dimensions, device metadata, latency, and
  aggregate VRAM figures. Do not commit uploaded customer images, outputs,
  bearer tokens, credentials, model binaries, or unredacted logs.
