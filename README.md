# PRTG Integration for EspoCRM

An EspoCRM extension that pulls sensor data and charts from PRTG Network Monitor into Espo records. It adds a configuration screen for PRTG credentials, a custom `Prtg` entity to mirror sensors, and UI actions to test connectivity and sync sensor details on demand.

## What it does
- Adds a **PRTG Config** admin panel entry (`PrtgConfig`) to store endpoint, username, passhash, TLS verification flag, timeout, and sensor mapping.
- Adds a **PRTG Sensor** entity (`Prtg`) with fields for status, uptime/downtime, last check/value, raw details, and embedded charts (2h/2d/30d/365d).
- **Test Connection** action from the config detail view to validate credentials and log the last test status/message/timestamp.
- **Sync from PRTG** action on a sensor record; also auto-syncs when creating a `Prtg` record if `sensorId` is provided.
- Server-side chart fetcher that returns data URIs to avoid mixed-content/SSL issues; reusable panel for other entities via `sensorEntity`/`sensorField` mapping.

## Installation
This repository is already structured as an EspoCRM extension (see `manifest.json`). To install in EspoCRM:

1) Zip the repository contents (keep `manifest.json` at the root).  
2) In EspoCRM, go to Admin ➜ Extensions and upload the zip.  
3) After installation, clear Espo cache if needed (the extension also clears it via `Extension.php`).

### Uninstall
Remove via Admin ➜ Extensions. The bundled scripts handle file cleanup (`scripts/uninstall.php`) and there is a SQL helper to drop entities/tables (`scripts/uninstall.sql`).

## Configuration
1) Open Admin ➜ PRTG ➜ **Configurações do PRTG** (`PrtgConfig`).  
2) Fill **Endpoint** (e.g., `https://prtg.example.com`), **Username**, and **Passhash** (or password).  
3) Set **Verify TLS** off if using self-signed certs; adjust **Timeout** as needed.  
4) If you want to embed charts on another entity, set **Sensor Entity** (defaults to `CCircuitoDesiginao`) and **Sensor Field** (defaults to `idSensor`).  
5) Click **Test Connection** to verify; the last result is stored in the record.

## Usage
- Create a **PRTG** record and set `sensorId`; click **Sync from PRTG** to fetch the sensor’s status, values, message, and charts.  
- The detail view shows identification, health metrics, raw PRTG payload, and embedded charts (2h/2d/30d/365d).  
- The generic charts endpoint can be used by other entities via the `custom:views/prtg/circuit-charts` panel, using the configured `sensorEntity`/`sensorField` mapping.

## Backend endpoints
- `POST /PrtgIntegration/TestConnection` — validates credentials, saves last test result.  
- `GET /PrtgIntegration/Sync/:id` — syncs a `Prtg` entity by ID.  
- `GET /PrtgIntegration/Charts/:scope/:id` — returns chart images (data URIs) for any entity with a sensor field.

## Logs
- Install/uninstall actions: `data/logs/prtg_install.log`.  
- Chart fetch failures: `data/logs/prtg-charts.log`.

## Source layout
- Backend module: `files/custom/Espo/Modules/PrtgIntegration/...` (API, services, metadata, layouts, i18n).  
- Frontend views: `files/client/custom/src/views/prtg*` (detail views and chart panel).  
- Install/uninstall scripts: `scripts/`.
