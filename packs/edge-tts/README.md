# Edge TTS External Service

`edge_tts` is an experimental, third-party online text-to-speech Pack for
Microsoft Edge's online speech service. It is CPU-only: GPU is not used, GPU
leases are not expected, and it has no voice clone capability.
Do not submit confidential text.

Only an administrator can install and enable this Pack, then grant a token the
`edge_tts` mode. No Microsoft provider secret is configured or accepted. Keep
the Hub API token in the caller environment and out of shell history, source
files, request logs, and artifacts.

```bash
curl --fail --silent --show-error \
  -H "Authorization: Bearer $AIHUB_EDGE_TTS_TOKEN" \
  -H 'Content-Type: application/json' \
  --data '{"text":"\u9019\u662f\u4e00\u6bb5\u975e\u6a5f\u5bc6\u7684\u4e2d\u6587\u5408\u6210\u3002","voice":"zh-TW-HsiaoChenNeural"}' \
  "$AIHUB_EDGE_TTS_BASE_URL?mode=edge_tts"
```

The JSON Unicode escapes keep this document ASCII while submitting a short,
non-confidential Chinese sentence. The queued response supplies standard task
URLs. Poll `task_status`, read `task_result` after success, and download the
owned `generated_audio` artifact with the same token. See
[`docs/operations/edge-tts-real-smoke.md`](../../docs/operations/edge-tts-real-smoke.md)
for the complete real-station procedure.

## Network And Failures

The runner requires outbound access to Microsoft Edge's online speech service.
After its controlled resolver setup, allowed provider egress is only
`speech.platform.bing.com:443`; it does not fall back to another provider.
Provider or network failures are terminal task failures, not substitute audio.
The runner emits only these bounded error codes: `upstream_unavailable`,
`edge_tts_timeout`, `edge_tts_failed`, and `artifact_write_failed`.

## Deferred V2

`subtitle_vtt` is a deferred, additive V2 artifact only. V1 exposes MP3 audio
and synthesis metadata; it has no subtitle endpoint or subtitle artifact.
