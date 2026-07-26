# Web Protection Diagnostics Design

## Goal

Show system administrators whether the Hub's non-public files are protected
when served by Apache, IIS, or Nginx, and give an actionable configuration
recommendation without changing the web server.

## Scope

1. Extend the existing queued `env_probe` snapshot with a `web_protection`
   section.
2. On Linux, report whether Apache and Nginx are active and whether the local
   Apache `.htaccess` rules required by the Hub are present.
3. On Windows, report whether the existing IIS `web.config` protection rules
   are present.
4. Render existing `hub_env_fix_suggestions()` suggestions for missing Apache
   protection and active Nginx, including the Nginx `server {}` fragment.
5. Add a same-origin browser `HEAD` probe on `admin/environment.php` for
   `data/cluster.key`, `docs/cluster-router.md`, and `scripts/init_db.php`.
   Only `403` and `404` pass; response bodies are never read or stored.

## Security Boundaries

- The queued worker never accepts a URL, performs a network request, invokes
  `sudo`, writes a web-server configuration, or reloads a web server.
- The browser probe uses fixed relative paths and same-origin credentials.
- Snapshots contain statuses and bounded recommendations only. They never
  contain key material, response bodies, configuration file contents, or
  paths outside the known Hub files.
- Nginx is advisory only because `.htaccess` has no effect there and its
  active server configuration may require root access to inspect.

## UI

The existing System Environment page receives a `Web Protection` section:

- persisted snapshot rows show server detection and static configuration
  status;
- a compact live row runs after the page loads and lists each fixed path with
  `PASS` or `FAIL`;
- a failure tells the administrator which server configuration to apply;
- Nginx guidance is a copyable config block, not an execution button.

## Verification

- Unit tests cover Apache/IIS/Nginx status and recommendation selection.
- A page contract test requires the fixed same-origin `HEAD` paths and verifies
  that no response body is consumed.
- Existing full PHP suite and Apache syntax/live `403` checks remain the
  release verification for this host.
