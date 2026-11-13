# OwnTalk Extensions API (v1)

Base path: `/ocs/v2.php/apps/owntalk_extensions/api/v1`. Every request sends
`OCS-APIRequest: true` and `Accept: application/json`, and authenticates like any
other Nextcloud OCS call (app password via Basic auth). Responses use the
standard OCS envelope `{"ocs": {"meta": {...}, "data": ...}}`.

This document describes 0.3.0. Nicknames, personal preferences and `/revisions`
are planned and not available yet.

## Capability

`GET /ocs/v2.php/cloud/capabilities` contains, when the app is enabled and Talk
is available for the user:

```json
"owntalk_extensions": {
  "version": "0.3.0",
  "api": 1,
  "features": ["appearance.colors", "appearance.bubbles", "appearance.presets",
               "appearance.image", "composer.quickEmoji"],
  "acl": "roles",
  "roomTypes": ["one2one", "group", "public"],
  "presets": ["aurora", "sunset", "forest", "ocean", "dots", "grid"],
  "limits": { "maxImageBytes": 10485760, "maxImageEdge": 2560 }
}
```

- No `owntalk_extensions` key: the app is not installed or not enabled. Clients
  hide every extension feature.
- `features` lists only what the server supports right now. `composer.quickEmoji`
  is missing when the server has no emoji support (PHP `intl`); `appearance.image`
  is missing when the server cannot decode images (PHP `gd`).
- `presets` are the background preset IDs the server accepts. The client draws
  them itself; a client that does not know an ID falls back to the theme.
- `limits.maxImageBytes` is the largest upload the server accepts;
  `limits.maxImageEdge` the longest edge after the server scales the image down.
  A reverse proxy in front of Nextcloud may set a lower body limit.
- `acl`: `roles` when the server can read Talk roles through the Talk OCS API,
  `membership` when it cannot. With `membership`, group and public conversations
  are read-only for everybody; one-to-one conversations stay editable. Without
  role data the server cannot tell a former one-to-one conversation (the other
  account was deleted) from a normal one, so the remaining participant can still
  edit it.

## Settings document

```json
{
  "schemaVersion": 1,
  "roomToken": "abc123xy",
  "revision": 3,
  "settings": {
    "chat.backgroundColor": "#1E3A5F",
    "chat.accentColor": null,
    "bubble.ownColor": "#2F6FEB",
    "bubble.ownTextColor": "#FFFFFF",
    "bubble.otherColor": null,
    "bubble.otherTextColor": null,
    "chat.backgroundPreset": null,
    "chat.backgroundImage": "9f2c…64 hex…",
    "composer.quickEmoji": "👍"
  },
  "fieldRevisions": { "chat.backgroundColor": 3, "bubble.ownColor": 2 },
  "permissions": { "canEdit": true, "aclSource": "talk-ocs" },
  "updatedBy": "alice",
  "updatedAt": 1794500000
}
```

- Colours are exactly `#RRGGBB`, upper case. `null` means "not set, use the
  client theme".
- `chat.backgroundPreset` is one of `presets` or `null`.
- `chat.backgroundImage` is the SHA-256 (64 lower-case hex) of the current
  background image or `null`. It is set only through the upload endpoint; `PATCH`
  can only clear it (`null`).
- Shared background precedence on the client: image, then preset, then colour.
- A conversation without stored settings returns `revision: 0`, all values
  `null` and empty `fieldRevisions`.
- `permissions.canEdit` is computed by the server for the caller.
- `updatedBy` is the user ID of the last writer or `null`.

## Endpoints

### `GET /room/{token}/settings`

- `200` with the document and header `ETag: "r{revision}-e1"` when the caller may
  edit, `"r{revision}-e0"` otherwise. The ETag covers the caller's permission, so a
  role change produces a new ETag even when the settings did not change. Clients
  treat it as opaque and send it back unchanged.
- `If-None-Match` with the current ETag → `304` without body. A weak `W/"…"`
  form (added by compressing proxies) is accepted.
- Role data used for `canEdit` on reads may be up to 15 s old; writes always ask
  Talk again.
- `404` when the conversation does not exist **or** the caller is not a regular
  participant. Both look the same.
- `400 invalid_token` when `{token}` does not match `^[a-z0-9]{4,64}$`.
- `403 source_mismatch` when the two membership sources disagree.
- `503 talk_unavailable` / `talk_incompatible` when Talk is missing or broken.

### `PATCH /room/{token}/settings`

Body (JSON merge patch over `settings` only):

```json
{ "settings": { "bubble.ownColor": "#2F6FEB", "composer.quickEmoji": null } }
```

Header `If-Match: "r{base}"` is required (`"r0"` for a conversation without
settings). `base` is the revision the user saw when they started editing, not
the latest one the client happens to have. `W/"r{base}"` is accepted.

| Status | `data.error` | Meaning |
| --- | --- | --- |
| 200 | – | Saved, new document and `ETag` |
| 400 | `invalid_json`, `unknown_field`, `invalid_value`, `invalid_token` | Rejected input |
| 403 | `field_forbidden` | Caller may not change these settings |
| 403 | `source_mismatch` | Membership sources disagree |
| 404 | `not_found` | Not a participant or no such conversation |
| 412 | `revision_conflict` | A changed field was modified after `base`; `data.current` holds the current document, `data.fields` the conflicting fields |
| 428 | `precondition_required` | `If-Match` missing or malformed |
| 503 | `talk_unavailable`, `talk_incompatible` | Talk missing or broken |

A write is all-or-nothing. Two writers changing different fields both succeed;
two writers changing the same field get a conflict.

### `PUT /room/{token}/settings/image`

Body: the raw image, `Content-Type: image/jpeg`, `image/png` or `image/webp`.
Header `If-Match: "r{base}"` as for `PATCH`; the upload changes the field
`chat.backgroundImage` and follows the same per-field conflict rule and the same
edit permission.

The server checks the type by content, rejects images over 8192 px on a side or
25 megapixels before decoding, applies the EXIF rotation, scales the longest edge
to `maxImageEdge`, and re-encodes as JPEG. Metadata such as EXIF and location is
not kept. The stored file is named by the SHA-256 of the re-encoded bytes, so the
same picture is stored once. Images no conversation uses any more are deleted by
the daily cleanup. WebP is accepted only when PHP's GD can decode it.

| Status | `data.error` | Meaning |
| --- | --- | --- |
| 200 | – | Saved; new document and `ETag` |
| 400 | `invalid_image` | Not decodable, too many pixels, or type and content differ |
| 403 | `field_forbidden` | Caller may not change these settings |
| 404 | `not_found` | Not a participant or no such conversation |
| 412 / 428 | as for `PATCH` | |
| 413 | `payload_too_large` | Body over `limits.maxImageBytes` |
| 415 | `unsupported_media_type` | Not JPEG, PNG or WebP |
| 507 | `quota_exceeded` | Background storage on this server is full (`image_quota_bytes`, default 1 GiB) |

### `GET /room/{token}/settings/image/{sha256}`

The current background image of the conversation as `image/jpeg`, for regular
participants only. `404` when `{sha256}` is not the conversation's current image
(even if the same image is used by another conversation the caller can see).
Headers: `ETag: "{sha256}"`, `Cache-Control: private, max-age=31536000,
immutable`, `X-Content-Type-Options: nosniff`. There is no public link.

## Who may edit (`acl: roles`)

| Conversation | Who may change settings |
| --- | --- |
| One-to-one | both participants |
| Former one-to-one, changelog | nobody |
| Note to self | the owner |
| Group / public | owner and moderators |
| Read-only group / public | owner and moderators |
| Federated | nobody |

With `occ config:app:set owntalk_extensions edit_policy --value=chat` any participant
with the Talk "chat" permission may also edit group and public conversations,
except while the lobby is on.

## Rate limits

`GET` 120 and `PATCH` 30 requests per user per minute, image uploads 10 per user
per hour, image downloads 60 per user per minute. Unknown or inaccessible
tokens count towards Nextcloud's brute-force protection, so clients stop polling
a conversation after `404`.
