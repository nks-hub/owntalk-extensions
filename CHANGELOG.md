# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 0.2.0 - unreleased

### Changed

- The settings ETag includes the caller's permission, so a role change is not
  hidden behind `304`. Weak ETags are accepted.
- Role lookups bypass the outgoing proxy and are accepted only for the calling
  user; reads may reuse a role result for 15 s, writes never do.
- Editing with `edit_policy=chat` is not possible while the lobby is on.
- Quick emoji values with letters, bidi or zero-width characters are rejected.
- `GET` is rate limited.

### Added

- Deleted users are removed from "last changed by".
- Daily cleanup of settings of deleted conversations and
  `occ owntalk-extensions:cleanup [--all]`.

## 0.1.0 - unreleased

### Added

- Shared conversation settings: chat background and accent colour, bubble
  colours, quick emoji (`GET`/`PATCH /api/v1/room/{token}/settings`).
- Membership check through the Talk collaboration resource provider, role check
  through the Talk OCS API, both on every request.
- `owntalk_extensions` capability.
