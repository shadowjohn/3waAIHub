# Web Capture Allowlist Design

Date: 2026-07-28

Status: approved; ready for implementation planning

## Goal

Make Web Screenshot usable for known public sites without changing the host
firewall. A system administrator maintains one global, exact-host allowlist.
`web_capture` accepts only a URL whose host is on that list, and its browser
cannot navigate a document to another host.

This is a controlled v1 for known sites, not a claim that arbitrary public
URLs are safe with application-layer checks alone.

## Scope

- Add a system-wide `AIHUB_WEB_CAPTURE_ALLOWED_HOSTS` setting on **設定 → API
  與安全**.
- Seed these exact default hosts, one per line:

  ```text
  3wa.tw
  fmg.wra.gov.tw
  fmgb.wra.gov.tw
  focusit.tw
  focusit.com.tw
  gis.tw
  ```

- Enforce the setting at API admission and in the Web Screenshot runner for
  document navigation.
- Keep existing public-IP, scheme, credential, and port validation for every
  browser request.
- Document how an administrator adds a host.

No new table, Pack setting, token-specific override, wildcard, external
configuration service, Docker network, iptables rule, or host firewall change
is in scope.

## Setting and validation

The existing SQLite `settings` table stores the textarea value. The normalizer
is a single PHP helper shared by the settings page and gateway admission.

The textarea is newline-delimited. Empty lines are ignored; surrounding
whitespace is removed; valid entries are lower-cased, trailing dots are
removed, and duplicates are collapsed. A saved list may be empty, which
disables new `web_capture` submissions.

Each nonempty line must be one ASCII DNS hostname: at most 253 characters,
with labels of 1--63 letters, digits, or hyphens, and no leading or trailing
hyphen. URLs, ports, paths, wildcard entries, IP literals, `localhost`,
control characters, and malformed names are rejected. There may be at most
128 hosts and 16 KiB of textarea input.

Validation is transactional from the administrator's point of view: an
invalid line produces a line-numbered message and leaves the current setting
unchanged. Existing CSRF and system-administrator checks remain in force.

`hub_ensure_default_storage_settings()` inserts the new default for both new
and existing databases when it next runs; no schema migration is needed.

## Admission and task contract

The client continues to submit only the declared `web_capture` fields. It
cannot supply an allowlist field.

At admission, the gateway normalizes the requested URL, retains the current
scheme/credential/port/public-DNS validation, then requires its normalized
host to be an exact member of the configured list. If it is not, it returns:

```json
{"status":"NO","reason":"url_not_allowed"}
```

with HTTP 200 and does not create a task. Authentication, HTTP method, and
ordinary contract failures keep their existing HTTP 4xx responses.

For an admitted task, Hub writes a normalized snapshot of the allowed hosts
into the read-only runner `request.json`. This field is Hub-generated after
the client input is filtered, so it is not part of the external API contract
and a caller attempting to send it is rejected. A queued task therefore has a
stable policy; an administrator who must revoke a queued capture cancels that
task. Changes apply to all later submissions.

## Runner enforcement

The Node runner validates the Hub-provided list before launch. An empty,
malformed, duplicate, wildcard, or noncanonical list is invalid.

Every Playwright document navigation uses the exact-host rule:

- Initial main-document navigation must be allowlisted.
- HTTP redirects and later `window.location` navigation of the main document
  must remain allowlisted; a block fails the task with `url_not_allowed`.
- Iframe document navigation follows the same host rule. A blocked iframe is
  aborted and becomes a bounded warning, so the allowed main page can still
  be captured.

Images, stylesheets, scripts, fonts, and other non-document subresources do
not require list membership. They retain the existing public-HTTP(S) policy:
only ports 80/443, no credentials, and DNS answers must be public. This keeps
CDN-backed pages usable while retaining the current private-address guard.

The runner must check for a blocked main-document navigation through the final
screenshot step, not only around `page.goto`, so delayed redirects cannot
produce a success result.

## Verification

PHP tests cover default seeding, textarea normalization, invalid line numbers,
empty-list behavior, settings write atomicity, the API's HTTP-200 rejection,
and client attempts to inject an internal allowlist.

Node tests cover runner-list validation, exact host matching, unallowlisted
redirects and iframe navigation, delayed main-document navigation, and
continued rejection of loopback/private DNS. Existing capture/crop and generic
Pack runner tests remain in the full PHP harness.

The documentation describes the admin workflow and the intentional boundary:
the allowlist enables known sites; it does not enable general-purpose arbitrary
URL capture.
