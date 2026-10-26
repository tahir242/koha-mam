# Changelog

All notable changes to MAM — Media Asset Management for Koha are recorded here.
The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[Semantic Versioning](https://semver.org/). The release workflow publishes the section that matches the
tagged version as the GitHub release notes.

Companion Koha plugin: [koha-media-manager](https://github.com/tahir242/koha-media-manager).

## [1.1.0] - 2026-10-26

Use it with the Koha plugin 1.1.0, whose **Go to MAM** needs this release.

### Added

- Sign-in without a record: a hand-off with `biblio_id: null` (and no item) opens the MAM dashboard. The
  Koha plugin 1.1 uses it for **Go to MAM** on its dashboard.
- `demo/`: Koha 26.05, the MAM and the plugin 1.1.0, preconfigured, started with one command
  (`docker compose up -d --build`). For evaluation only; see `demo/README.md`.

### Changed

- Releases: `-beta` tags are published as the **Latest** release, so the repository page shows the zip.
  Only `-alpha`, `-rc` and `-dev` tags are pre-releases.

### Fixed

- The view-counting migration failed on MySQL and MariaDB: its index name was longer than 64 characters.

## [1.0.0] - 2026-10-25

First public release (beta).

### Added

- Single sign-on from Koha through the koha-media-manager plugin (code-v2), with Koha permissions as a ceiling
  on MAM roles; record and item context.
- Record and item workspaces with Koha metadata, cover and cached fallback when Koha is unreachable.
- Resumable chunked uploads up to 4 GB with content-based type detection, duplicate detection and daily quotas.
- Quarantine, malware scanning (ClamAV), checksums and private storage on local, NAS, shared or S3/MinIO backends.
- Processing for documents (viewer PDF), images (WebP sizes), video (HLS 360/720/1080p) and audio (AAC).
- Versioning with replacement that keeps the public link stable.
- Review workflow with publishing policies, approvals, rejections with reasons and a four-eyes rule.
- Publication outbox: record media as MARC 856 and item media in the item URI (952$u), with read-back
  verification, retries and conflict detection.
- Public viewer for documents, video, audio and images, with watermark, signed expiring media URLs and a strict CSP.
- YouTube-style view counting: engagement thresholds, server-verified single-use view tokens, repeat and
  daily limits per viewer, bot and staff filtering, view statistics for staff and "Most viewed".
- Administration for Koha integration, storage, policies, settings, staff roles, processing tools and audit log.
- Health checks, scheduler, Horizon support and commands (`dam:sso:doctor`, `dam:publications:relay`,
  `dam:items:republish`, `dam:cleanup`).
- GitHub Actions workflow that tests every push and publishes a release zip for every tag.
- User Guide and Developer Guide.
