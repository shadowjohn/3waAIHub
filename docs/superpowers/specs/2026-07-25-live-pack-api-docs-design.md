# Live Pack API Documentation Design

## Goal

Make every API documentation surface describe only the Packs that this Hub host can serve now. This keeps people and agents from selecting APIs that are merely present in the repository, and reduces the machine-readable manifest to the contracts that matter on the current host.

The change covers:

- `public_api_docs.php`
- `api_manifest.json.php`
- `admin/api_docs.php`
- the README sections that describe current capabilities and API documentation behavior

## Visibility Rule

A Pack API is documentable only when its service row:

1. has `install_status=installed`;
2. has `enabled=1`;
3. has `runtime_status=running`; and
4. passes the live health rule below.

The `services` table is the inventory source. Repository Pack discovery does not make a Pack visible by itself, and an empty result never falls back to every known Pack.

The documented mode comes from the eligible service row. The API shape, examples, errors, and runtime metadata continue to come from that service's Pack manifest.

If more than one eligible row refers to the same Pack, each distinct service mode is documented once.

## Live Health Rule

Opening any of the three documentation surfaces performs a fresh health check after the database prefilter.

HTTP services are checked concurrently with PHP cURL multi:

- only loopback HTTP URLs generated for Hub services are accepted;
- connection timeout is 250 milliseconds;
- individual request timeout is 750 milliseconds;
- the whole concurrent batch has a one-second upper bound;
- HTTP 200 through 399 is healthy unless a JSON body explicitly contains `ok: false` or `ready: false`.

No model inference or GPU work is performed. A health endpoint should only report readiness.

An `internal-task:health` service has no HTTP process to probe. It is healthy when its database row passes the installed, enabled, and running checks, matching the existing internal-task health semantics.

If cURL is unavailable, a URL is invalid, a request times out, or a response is unhealthy, that service is omitted. One failed service never prevents the documentation page or manifest from loading. Health results are reused only inside the current request; there is no persistent cache because each document open is intended to reflect current availability.

## Shared Data Flow

`app/public_api_docs.php` will own one shared eligibility function. It will:

1. read candidate service rows from the database;
2. apply the installed, enabled, and running prefilter;
3. batch-check HTTP health;
4. return only eligible service rows;
5. resolve each row to its Pack manifest and build the existing public contract shape.

The health operation accepts an optional callback for deterministic tests. Production callers use the live cURL implementation.

Both public outputs continue through `hub_public_api_services()`:

- `hub_public_api_docs_html()` renders its cards from the filtered contracts;
- `hub_public_api_manifest()` emits the same contracts in `services`.

The admin page uses the same eligible service rows and Pack IDs for its service table and Pack contract sections. It does not maintain a second interpretation of availability.

## Derived And Hard-Coded APIs

Gemma photo/audio APIs are included only when an eligible `llm-gemma4-12b` service exists. YOLO model management APIs are included only when an eligible `yolo-serving` service exists. Their visibility follows the parent service's live health result.

The current hard-coded admin sections for Hello, OCR, Translate, and SAM3 will be removed because they duplicate Pack contracts and can describe unavailable services. Generic authentication and unknown-mode guidance remain, but authentication examples use `<mode>` rather than assuming Hello is installed.

The public DocParser repair hint is shown only when DocParser is eligible.

The current public Local Jobs section is YOLO-specific. It is shown only when the eligible Pack set contains YOLO. A generic Local Jobs documentation generator is outside this change because YOLO is currently the only Pack represented by that section.

## Empty And Failure States

When no Pack passes the visibility rule:

- the agent manifest returns `"services": []`;
- public API docs show a short "no healthy installed API services" message and no service cards;
- admin API docs show the same operationally accurate empty state;
- generic authentication and unknown-mode behavior may still be documented.

The response itself remains HTTP 200. Availability is represented by the service list, not by failing the documentation endpoint.

## README Update

The README will:

- state that public docs, the agent manifest, and admin docs list only installed, enabled, running, and currently healthy Pack APIs;
- explain that health checks are local readiness probes and failures hide only the affected contract;
- update the current feature inventory for the Packs and Windows/WSL compatibility already present on `main`;
- remove or revise stale wording that implies repository Packs are always advertised.

This is a focused refresh, not a rewrite of the README.

## Testing

`tests/test_public_api_docs.php` will cover the shared rule with temporary service rows and a fake health callback:

- installed, enabled, running, and healthy is visible;
- not installed, disabled, stopped, timeout, non-success HTTP, `ok: false`, and `ready: false` are hidden;
- one unhealthy service does not hide a healthy sibling;
- a non-loopback health URL is rejected;
- the documented mode comes from the service row;
- Gemma and YOLO derived APIs follow parent visibility;
- an empty inventory produces `services: []` and the HTML empty state;
- DocParser and YOLO-only prose does not appear without its eligible parent Pack.

The admin documentation test will verify that it still requires a system administrator, uses the shared eligible inventory, and no longer contains the duplicated Pack-specific sections.

The normal PHP test suite and web self-checks will run after implementation.

## Out Of Scope

- persistent health result storage or background polling
- a health-history dashboard
- Pack installation or automatic service startup from a documentation request
- model inference as part of health checking
- a new generic Local Jobs documentation framework
- changes to API routing, authentication, Pack manifests, or service lifecycle commands
