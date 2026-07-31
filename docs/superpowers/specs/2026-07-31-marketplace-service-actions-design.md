# Marketplace Service Actions Design

## Goal

Make the installed-service cards in `admin/marketplace.php?view=services` show valid actions and allow a safe service removal.

## Actions

- A running or starting service does not show `啟動`.
- A stopped or failed service does not show `停止`.
- A service with an active command job disables its service actions.
- The initial PHP render and the existing job-polling JavaScript use the same visibility rules.
- Remove the legacy service IP-whitelist link from the service card details and the old service-management page. The direct legacy page remains available for existing rules and troubleshooting.

## Removal

`移除` is available only for a stopped service with no active command job. The browser uses native confirmation, then queues a service removal action.

The worker runs the existing Compose shutdown first. Only after a successful shutdown does it delete the service registration and its generated Compose and environment files. A failed shutdown leaves the registration and generated files in place.

The removal does not delete the Pack definition, Docker image, model files, caches, task history, or task artifacts. Those may be shared by another service or still be useful after reinstallation.

## Verification

- Add a focused test that rejects removal of a running or busy service and confirms shutdown precedes deletion.
- Add page-contract checks for state-specific start and stop controls and the removal confirmation.
- Run focused control-plane tests and PHP lint for changed files.
