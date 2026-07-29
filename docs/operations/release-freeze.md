# 8/7 Release Freeze

The current development build ID is `20260729001`
(`2026.07.29.001`). Do not create the future release tag during ordinary
development.

## Freeze procedure

1. Confirm the current development ID is `20260729001`.
2. Change `HUB_VERSION` to `20260807001` only in the freeze commit.
3. Run the focused suite, the control-plane suite, and then the full suite:

   ```bash
   AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=admin-ui
   AIHUB_TEST_QUIET=1 php scripts/run_tests.php --suite=control-plane
   AIHUB_TEST_QUIET=1 php scripts/run_tests.php
   ```

4. Verify all three hosts report the same tag and commit, Pack inventory,
   runner image/digest state, and health.
5. Create immutable annotated tag `20260807001` from the verified commit.
6. Use 3wa as the normal push source.
7. On 5090 and 1080, fetch and fast-forward or check out the immutable tag;
   those execution nodes never push.
8. Keep WSL as an authoring and validation environment, not a deployment
   authority.

The admin Environment and Settings pages are report-only. They never fetch,
check out, merge, pull, reset, deploy, or otherwise change Git state.
