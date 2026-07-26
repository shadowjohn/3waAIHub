# Cluster Developer Portal Design

## Goal

Turn `cluster_public_api_docs.php` into a customer-facing developer manual that is polished enough for a demo, while remaining a live, disclosure-safe view of the Router contract.

## Audience and Scope

The page serves a customer or integrator who has received a Router Bearer Token. It is not an operator dashboard: it must not show node names, URLs, GPU figures, load, pairing state, or any child Token.

The existing local `public_api_docs.php` remains a separate document for direct single-host integrations. The Router page describes only `cluster_api.php` and only modes the Router can execute.

## Reading Path

1. A compact hero identifies the page as the unified Cluster API and shows the absolute Router endpoint plus Bearer header. Each value has a copy control.
2. A live inventory strip shows the count of available modes, the snapshot time, and a clear `Live catalog` status. It gives the reader immediate evidence that this is the current Router contract, not a static brochure.
3. A mode directory provides anchored service cards with the mode, method, content type, and a short description. The compact cards form the page's primary scan path.
4. Selecting a mode exposes its complete contract: request fields, response keys, errors, and language examples. Copying a snippet is a browser-only enhancement; all content remains visible and usable without JavaScript.
5. An empty catalog retains the same hero and clearly explains that the Router has no available mode, without revealing why a particular node is unavailable.

## Data and Safety

The page continues to render exclusively from `hub_cluster_public_manifest($db)`. It keeps the existing overdue-inventory refresh at the public endpoint, then renders only fresh, Router-compatible contracts. No station metadata is added to the manifest or HTML.

The human-facing examples use the current absolute `cluster_api.php` URL. JSON manifest values remain relative and machine-friendly. URL rewriting must preserve the existing Router-only contract and never expose a child station URL or native task id.

## Visual System

Use the established light admin palette as a family resemblance, with a clean ink/navy text base, blue as the interactive accent, green only for the live status, and neutral gray for structure. Avoid gradients, decorative imagery, and arbitrary charts.

The layout uses an unframed page shell, a practical header band, a three-column summary on large screens, and responsive service cards. On mobile it becomes a single column: endpoint, live state, mode directory, then contracts. All navigation is standard anchor navigation; copy controls are explicit icon buttons with accessible labels and tooltips.

## Implementation Boundaries

- Modify the existing `hub_cluster_public_api_docs_html()` renderer and its focused regression tests.
- Reuse existing `hub_public_api_base_url()` to create the display-only absolute Router endpoint.
- Use small inline JavaScript only for clipboard copy feedback. No new dependency, API, database table, background job, chart library, or persisted UI state.
- Keep the existing non-JavaScript HTML contract readable.

## Verification

- Unit/source tests cover the live catalog header, local-safe absolute Router endpoint, mode navigation, empty state, and absence of configured station details.
- Run PHP lint and the full test suite.
- Inspect desktop and mobile renderings against the live `cluster_manifest.json.php` response; verify that no sensitive station detail appears and no text overflows.
