<p align="center">
  <img src="https://cdn.usework.space/screenshots/app-screenshot.png" alt="usework.space app screenshot" width="900" />
</p>

<h1 align="center">usework.space</h1>

<p align="center">
  Tasks, secrets, and spend — one encrypted workspace.
</p>

<p align="center">
  <a href="https://usework.space"><img alt="Website" src="https://img.shields.io/badge/website-usework.space-7c3aed"></a>
  <a href="./LICENSE.md"><img alt="License: FSL-1.1-Apache-2.0" src="https://img.shields.io/badge/license-FSL--1.1--Apache--2.0-blue"></a>
  <img alt="Status" src="https://img.shields.io/badge/status-production-success">
</p>

---

This repository contains the source for the usework.space backend — the
self-hosted edition, shipped as a Docker image.

## Self-hosting

The fastest path is the bootstrap installer. On a fresh server with Docker +
Compose v2 installed:

```sh
curl -fsSL https://raw.githubusercontent.com/BEAR-TECH-SOLUTIONS/useworkspace/main/selfhost/install.sh | sh
```

The installer prompts for your domain, license key, and initial admin
credentials, generates local secrets, runs `docker compose up -d`, and tails
the boot license check until it passes.

Day-2 operations are handled by the `usework` wrapper:

```sh
usework status            # container health + license expiry
usework logs app          # tail application logs
usework upgrade           # pull new image, migrate, restart
usework reset-password EMAIL
```

See [`selfhost/`](./selfhost/) for the Dockerfile, Compose bundle, Caddy
config, and entrypoint scripts.

## Auditing

The source visible here is the source running in production. Mirroring from
our private repo is automated on every release, with the modules not shipped
in the self-hosted edition (billing, license issuance, central admin tooling)
intentionally stripped.

Things worth reading if you're auditing:

- [`app/Modules/SelfHosted/Services/Licensing/LicenseValidator.php`](./app/Modules/SelfHosted/Services/Licensing/LicenseValidator.php) —
  the offline verifier.
- [`app/Modules/SelfHosted/Console/Commands/PhoneHomeCommand.php`](./app/Modules/SelfHosted/Console/Commands/PhoneHomeCommand.php) —
  the hourly telemetry payload. If the allow-list ever changes, it shows up
  in this file's diff.

## Licensing

- **Source code**: [Functional Source License v1.1, Apache 2.0 Future](./LICENSE.md)
  (each release auto-converts to Apache 2.0 two years after publication).
- **Operational use**: [End User License Agreement](./EULA.md). The installer
  requires explicit acceptance.
- **Trademarks**: [TRADEMARK.md](./TRADEMARK.md).

## Contributing

This repository is a read-only mirror. See
[CONTRIBUTING.md](./CONTRIBUTING.md) for where to report bugs, request
features, and disclose security issues.

## Stack

- PHP 8.4 / Laravel 13
- PostgreSQL 16
- Laravel Reverb for WebSocket broadcasting
