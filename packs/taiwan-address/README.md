# Taiwan Address Wash and Geocode Pack

This Pack is a controlled adapter for an existing, trusted Taiwan address wash and geocoding PHP service. It deliberately does **not** copy its 7.4 GiB SQLite data bundle or alter its IIS/PHP configuration.

Set `TWADDR_UPSTREAM_URL` to that service's `api.php` endpoint, then restart the Pack. The adapter accepts only its fixed operation allowlist and never accepts an upstream URL from a client request.

```json
{
  "operation": "getAddress_XY",
  "address": "台中市南區新和街1號"
}
```

Preserve upstream quality fields such as `result_label`, `quality_flag`, `include_in_coverage`, `geo_check_status`, and `geo_warning_code`; alias, POI, fallback, and approximate results are not silently promoted to official addresses.

On a Windows Control Plane host this Pack uses its explicit `windows-wsl2-linux-docker` transport. Install it from Marketplace, save `TWADDR_UPSTREAM_URL` in Service Settings, then build and start the Service Instance. For an IIS upstream on the Windows host, use the address visible inside Docker, for example `http://host.docker.internal/wash_taiwan_address_php/api.php`.

This does not enable direct `linux-docker` on Windows; other Docker Packs remain blocked unless they explicitly declare and implement a WSL transport. A future Linux-native slice may package the PHP service and a separately SHA-256 verified read-only data asset.

`acceptance/gateway_acceptance.php` verifies two real upstream calls through a token-protected, isolated Hub gateway. It requires a separately started temporary Hub HTTP server plus `AIHUB_TEST_DB` and `AIHUB_TEST_DATA_DIR`; it never uses the production Hub database.
