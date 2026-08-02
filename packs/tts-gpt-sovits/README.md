# GPT-SoVITS Governed Voice Clone

`tts-gpt-sovits` is an explicit async route: `voice_generate_gpt_sovits`.
It only supports governed `clone` and transcript-confirmed `ultimate_clone`.
The Pack accepts a Hub-managed voice profile handle; it never accepts a host
path, a raw reference upload, or a runtime model download.

The Pack is `L5-benchmark-ready`: a real Cluster `ultimate_clone` smoke has
verified managed-profile preparation, CUDA synthesis, WAV relay, SHA-256
validation, and artifact acknowledgement.

The default execution mode is `isolated`. `resident` is an operator-selected
option and keeps the local model in GPU memory until the service is restarted
or a positive idle-unload setting has elapsed. An idle value of `0` never
unloads automatically.

Install the fixed local model layout through Model Repository before enabling
the service. Copy the upstream V2 checkpoints to
`models/gpt_sovits/checkpoints/gpt_v2.ckpt` and
`models/gpt_sovits/checkpoints/sovits_v2.pth`, then provide Chinese HuBERT
and Chinese RoBERTa below `models/gpt_sovits/pretrained_models/`. HuBERT must
include `config.json`, `pytorch_model.bin`, and `preprocessor_config.json`;
RoBERTa must include `config.json`, `pytorch_model.bin`, and `tokenizer.json`.
G2PW Chinese polyphonic-pronunciation assets and the NLTK English
pronunciation/tagger dictionaries are also required below
`models/gpt_sovits/`; provision the pinned archives once before the service is
started. The task runner and health check reject a missing asset instead of
downloading during a request.

```bash
AIHUB_MODELS_DIR=/DATA/models \
  bash packs/tts-gpt-sovits/jobs/provision_offline_models.sh
```

## Operator Smoke

Use a token that has the explicit `voice_generate_gpt_sovits` permission and a
consent-qualified managed `voice_profile_id`. The command submits a real
`clone`, waits for the task, checks the returned WAV and metadata artifacts,
then acknowledges the artifacts. It does not print the token, transcript, or
reference audio path.

```bash
php scripts/audio_packs_acceptance.php \
  --base-url=https://3wa.tw/3waAIHub/api.php \
  --token='<TOKEN>' \
  --pack=tts-gpt-sovits \
  --voice-profile-id=<PROFILE_ID> \
  --text='請檢查 RC Valve 間隙，並以清楚自然的語氣說明。' \
  --json
```

Run the same command with `--pack=tts-voxcpm2` and the same profile/text for
a practical engine comparison. With a 16 GB GPU, stop VoxCPM2 before enabling
resident GPT-SoVITS when the configured free-VRAM margin cannot be met; the
Pack never evicts another resident service automatically.
