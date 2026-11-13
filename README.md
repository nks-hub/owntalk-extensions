# OwnTalk Extensions

[![Nextcloud](https://img.shields.io/badge/Nextcloud-34%20%7C%2035-0082c9)](https://nextcloud.com)
[![PHP](https://img.shields.io/badge/PHP-8.2%2B-777bb4)](https://www.php.net)
[![License](https://img.shields.io/badge/License-AGPL--3.0--or--later-blue)](LICENSE)

Nextcloud app that stores shared per-conversation settings for Nextcloud Talk (chat background colour, wallpaper or photo, bubble colours, a quick emoji button) and serves them to the [OwnTalk](https://github.com/nks-hub/nks-nextcloud-talk) client.

## Features

- 🎨 **Shared colours** - chat background, accent and bubble colours stored once per conversation and shown to every participant using OwnTalk
- 🖼️ **Wallpapers and photos** - a preset wallpaper drawn by the client, or a photo that the server checks, rotates, scales to 2560 px and re-encodes as JPEG without metadata
- 👍 **Quick emoji** - one emoji per conversation that OwnTalk offers as a one-tap reply
- 🔒 **Talk-based access control** - every request checks current Talk membership through Nextcloud's public collaboration resource API and the caller's Talk role through the Talk OCS API; nothing is cached between requests
- 🔁 **Concurrent edits** - revisions with `If-Match`; two people changing different fields both succeed, the same field returns `412`
- 🧩 **Optional** - without this app OwnTalk works as before and hides the extra settings; other Talk clients ignore the data

The app does not read or store chat messages, push tokens or credentials. Settings are stored in the Nextcloud database and are visible to the server administrator, like Talk chat itself.

## Requirements

- Nextcloud 34 or 35
- Nextcloud Talk (spreed) enabled
- PHP 8.2+ (8.3+ for Nextcloud 35); the PHP `intl` extension for the quick emoji field, `gd` (and `exif` for photo rotation) for photo backgrounds
- `overwrite.cli.url` pointing at this server (HTTPS, or `http://localhost`) so the app can read Talk roles; without it group conversations are read-only

## Installation

The app is not in the Nextcloud App Store yet. Manual installation:

```bash
cd /var/www/nextcloud/custom_apps
git clone https://github.com/nks-hub/owntalk-extensions.git owntalk_extensions
sudo -u www-data php /var/www/nextcloud/occ app:enable owntalk_extensions
```

## Usage

Nothing to configure for the default rules. Optional settings:

```bash
# Let every participant with the Talk "chat" permission edit group conversations
occ config:app:set owntalk_extensions edit_policy --value=chat

# Total size of stored background photos, in bytes (default 1 GiB)
occ config:app:set owntalk_extensions image_quota_bytes --value=2147483648 --type=integer
```

Settings of deleted conversations and photos no conversation uses are removed by
a daily background job, or at once with `occ owntalk-extensions:cleanup`. Nextcloud runs uninstall steps on every
`app:disable`, so the app keeps its data when disabled or removed; delete it
explicitly before removing the app:

```bash
occ owntalk-extensions:cleanup --all
occ app:remove owntalk_extensions
```

Who may change settings by default:

| Conversation | Editors |
| --- | --- |
| One-to-one | both participants |
| Group / public | owner and moderators |
| Note to self | the owner |
| Former one-to-one, changelog, federated | nobody |

The HTTP API is described in [docs/api.md](docs/api.md).

## Testing

```bash
composer install
composer test:unit
```

Integration tests run against a real Nextcloud with Talk and create throw-away users:

```bash
OT_BASE=http://localhost:8080 OT_ADMIN_USER=admin OT_ADMIN_PASS=... \
  python3 tests/integration/acl_test.py
```

## Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/my-change`)
3. Commit your changes (`git commit -m 'feat: describe the change'`)
4. Push to the branch (`git push origin feature/my-change`)
5. Open a Pull Request

## Support

- 📧 **Email:** dev@nks-hub.cz
- 🐛 **Bug reports:** [GitHub Issues](https://github.com/nks-hub/owntalk-extensions/issues)

## License

AGPL-3.0-or-later — see [LICENSE](LICENSE) for details.

---

<p align="center">Made with ❤️ by <a href="https://github.com/nks-hub">NKS Hub</a></p>
