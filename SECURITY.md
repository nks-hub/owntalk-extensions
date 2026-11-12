# Security policy

Report vulnerabilities privately to dev@nks-hub.cz or through
[GitHub private vulnerability reporting](https://github.com/nks-hub/owntalk-extensions/security/advisories/new).
Please do not open a public issue.

## What the app handles

- To read the caller's Talk role, the app forwards the caller's `Authorization`
  header to this server's own Talk OCS API at the origin configured in
  `overwrite.cli.url`. The header is kept only in memory for that request. It is
  not stored, logged or sent to any other host, and redirects are not followed.
- Membership is checked on every request; there is no access cache.
- Stored data: per-conversation colours and quick emoji, the user ID of the last
  writer and a timestamp. No chat content, push tokens or credentials.
