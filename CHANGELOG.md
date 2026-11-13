# Changelog

All notable changes to this project are documented in this file. The format is
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## 0.4.0 - 2026-11-13

### Added

- After every change the app signals all users of the conversation through the
  `notify_push` app, when it is installed: the frame
  `owntalk_room_settings {"token":"…","revision":n}` lets OwnTalk read the new
  settings at once instead of on its next poll. The capability lists
  `signal.notifyPush` when the signal is available.

## 0.3.0 - 2026-11-13

### Added

- Preset wallpapers: `chat.backgroundPreset` with the IDs listed in the
  capability.
- Background photos: `PUT /room/{token}/settings/image` and
  `GET /room/{token}/settings/image/{sha256}`. Uploads are checked by content,
  limited to 10 MiB and 25 megapixels, rotated by EXIF, scaled to 2560 px and
  re-encoded as JPEG without metadata. Storage is capped by `image_quota_bytes`.
- The daily cleanup and `occ owntalk-extensions:cleanup` delete photos no
  conversation uses; `--all` deletes every photo.
- The daily cleanup also forgets writers whose accounts were deleted while the
  app was disabled.

### Fixed

- With Talk roles configured, a failed role lookup no longer falls back to the
  membership rules, which let the remaining member of a former one-to-one
  conversation edit it.
- Removing a deleted user from "last changed by" moves the revision on, so a
  cached `ETag` no longer hides the change.
- A write that loses every retry to unrelated edits reports only the fields that
  really conflict.
- Colours with a trailing newline are rejected.
- Quick emoji are checked against the Unicode Emoji properties: symbols such as
  U+221E are rejected, emoji such as U+3030 are accepted.
- The App Store description states that the caller's `Authorization` header is
  forwarded to the server's own Talk API.

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
