# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 0.1.0 - unreleased

### Added

- Shared conversation settings: chat background and accent colour, bubble
  colours, quick emoji (`GET`/`PATCH /api/v1/room/{token}/settings`).
- Membership check through the Talk collaboration resource provider, role check
  through the Talk OCS API, both on every request.
- `owntalk_extensions` capability.
