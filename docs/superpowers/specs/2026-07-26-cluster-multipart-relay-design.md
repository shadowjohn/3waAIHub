# Cluster Multipart Relay Design

## Goal

Make every fresh, published `multipart/form-data` service usable through
`cluster_api.php`, including the 5090 node's `sam3` and `yolo` modes. The
Router must preserve its existing authentication, station selection, request
limits, response filtering, and route accounting.

## Scope

- Publish fresh multipart contracts alongside JSON contracts in the Router
  manifest, token-permission list, and public API document.
- Relay a normal browser multipart request to a selected remote child node.
- Dispatch the same request safely when the selected station is the Router's
  own host.
- Keep successful binary responses, including a SAM PNG mask, intact.
- Record inbound upload bytes on the Router route-access row.

No new endpoint, database table, dependency, or configuration setting is
needed. `cluster_api.php?mode=<mode>` remains the only customer entry.

## Request Flow

1. The Router authenticates the customer's token and selects a fresh station
   exactly as it does for JSON requests.
2. `hub_cluster_router_normalize_request()` recognizes multipart input rather
   than rejecting it. It keeps only flat scalar `$_POST` fields and flat file
   entries whose upload status is `UPLOAD_ERR_OK` and whose temporary path is a
   regular file. Nested arrays, malformed file entries, and invalid fields are
   rejected before a station request is made.
3. The existing Router request-byte limit is checked from `Content-Length`.
   The child gateway then applies its own pack-specific `max_upload_mb` limit
   after cURL has constructed the outgoing multipart request. The Router does
   not add a second size setting.
4. For a remote station, `hub_cluster_proxy_transport()` sends the scalar
   fields plus `CURLFile` entries. cURL creates the outbound multipart
   boundary, so the incoming client `Content-Type` boundary is never reused.
   The trusted station token and the safe `Accept` header are retained.
5. For the Router's self station, a narrowly scoped helper temporarily binds
   the validated form and files while calling the existing local gateway with
   the paired-node token, then restores the superglobals in `finally`.
6. Router accounting records the original request byte count. Existing
   response filtering, async task rewriting, and binary response forwarding
   continue unchanged.

## Discovery And Documentation

`hub_cluster_public_manifest()` no longer excludes multipart contracts.
The public developer portal selects examples from a contract's content type:

- JSON modes retain their existing JSON cURL, PHP, and JavaScript examples.
- Multipart modes show `curl -F`, PHP `CURLFile`, and JavaScript `FormData`
  examples, with `./image.jpg` (or the declared field name) as the file
  placeholder.

No station hostname, station token, local filesystem path, or request body is
displayed in the manifest or documentation.

## Error Handling

- Invalid form shape: `400 router_request_unsupported`.
- Router request over its existing limit: `413 router_request_too_large`.
- A child pack's own size or content validation failure is returned as the
  existing generic Router child-response failure; remote details are never
  exposed.
- Transport failure retains the existing stable `502 router_proxy_failed`.

The Router does not stream arbitrary large uploads or retain upload copies.
It relays PHP's request-scoped temporary files within the existing Router
request ceiling. `ponytail:` this is a bounded request relay; add streamed
uploads only when a supported pack needs files larger than that ceiling.

## Validation

- Unit coverage proves multipart contracts appear in the public manifest and
  token-permission mode list.
- Router tests prove valid remote multipart input becomes scalar cURL fields
  plus `CURLFile`, while invalid/nested/oversized input makes no transport
  call.
- Self-station coverage proves the local gateway receives the form/files and
  restores its original superglobals.
- Documentation coverage proves multipart modes render form-aware examples.
- Full PHP suite must pass.
- With an existing Router-authorized token, run a real 5090 smoke using
  `sam3` or `yolo` and `packs/sam3/demo/camera_cat.png`; confirm the Router
  returns the child result without revealing child credentials.
