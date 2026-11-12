#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 NKS Hub
# SPDX-License-Identifier: AGPL-3.0-or-later
"""Attack-oriented access control tests against a real Nextcloud + Talk instance.

Creates throw-away users and conversations through the public Talk OCS API and
checks the OwnTalk Extensions endpoints against Talk's own view of membership
and roles. Standard library only.

Environment:
  OT_BASE        e.g. http://192.0.2.10:8094
  OT_ADMIN_USER  admin user name
  OT_ADMIN_PASS  admin password
  OT_EXPECT_ACL  "roles" (default) or "membership"
  OT_RESET_CMD   optional shell command that resets the brute-force counter for
                 the test client IP (the suite produces many intentional 404s)
"""
from __future__ import annotations

import base64
import concurrent.futures
import json
import os
import secrets
import subprocess
import sys
import urllib.error
import urllib.request

BASE = os.environ["OT_BASE"].rstrip("/")
ADMIN = (os.environ["OT_ADMIN_USER"], os.environ["OT_ADMIN_PASS"])
EXPECT_ACL = os.environ.get("OT_EXPECT_ACL", "roles")
RUN = secrets.token_hex(3)
TALK = "/ocs/v2.php/apps/spreed/api/v4"
EXT = "/ocs/v2.php/apps/owntalk_extensions/api/v1"

results: list[tuple[str, bool, str]] = []


def call(method, path, auth=None, body=None, headers=None):
    data = None
    hdrs = {"OCS-APIRequest": "true", "Accept": "application/json"}
    if body is not None:
        data = json.dumps(body).encode()
        hdrs["Content-Type"] = "application/json"
    if auth is not None:
        hdrs["Authorization"] = "Basic " + base64.b64encode(f"{auth[0]}:{auth[1]}".encode()).decode()
    hdrs.update(headers or {})
    req = urllib.request.Request(BASE + path, data=data, method=method, headers=hdrs)
    try:
        with urllib.request.urlopen(req, timeout=60) as r:
            raw, status, resp_headers = r.read(), r.status, dict(r.headers)
    except urllib.error.HTTPError as e:
        raw, status, resp_headers = e.read(), e.code, dict(e.headers)
    try:
        parsed = json.loads(raw) if raw else None
    except json.JSONDecodeError:
        parsed = None
    data_ = parsed.get("ocs", {}).get("data") if isinstance(parsed, dict) else None
    return status, data_, resp_headers


def reset_bruteforce():
    cmd = os.environ.get("OT_RESET_CMD")
    if cmd:
        subprocess.run(["bash", "-c", cmd], check=True, capture_output=True)


def check(name, cond, detail=""):
    results.append((name, bool(cond), detail))
    print(("PASS " if cond else "FAIL ") + name + (f"  [{detail}]" if detail and not cond else ""), flush=True)


def new_user(label):
    uid = f"ot{RUN}{label}"
    pw = secrets.token_urlsafe(18)
    st, _, _ = call("POST", "/ocs/v2.php/cloud/users", ADMIN, {"userid": uid, "password": pw})
    assert st == 200, f"create user {uid}: {st}"
    return (uid, pw)


def room(owner, room_type, name=None, invite=None):
    body = {"roomType": room_type}
    if name:
        body["roomName"] = name
    if invite:
        body["invite"] = invite
    st, d, _ = call("POST", f"{TALK}/room", owner, body)
    assert st in (200, 201), f"create room: {st} {d}"
    return d["token"]


def add(owner, token, user):
    st, _, _ = call("POST", f"{TALK}/room/{token}/participants", owner, {"newParticipant": user[0], "source": "users"})
    assert st == 200, f"add {user[0]}: {st}"


def attendee_id(owner, token, user):
    _, d, _ = call("GET", f"{TALK}/room/{token}/participants", owner)
    return next(p["attendeeId"] for p in d if p["actorType"] == "users" and p["actorId"] == user[0])


def ext_get(user, token, headers=None):
    return call("GET", f"{EXT}/room/{token}/settings", user, headers=headers)


def ext_patch(user, token, settings, base):
    hdrs = {} if base is None else {"If-Match": f'"r{base}"'}
    return call("PATCH", f"{EXT}/room/{token}/settings", user, {"settings": settings}, hdrs)


def rev(user, token):
    st, d, _ = ext_get(user, token)
    return d["revision"] if st == 200 else None


def main() -> int:
    owner, mod, member, outsider, peer, linker = (new_user(x) for x in ("own", "mod", "mem", "out", "peer", "link"))

    # Capability
    st, d, _ = call("GET", "/ocs/v2.php/cloud/capabilities", owner)
    cap = (d or {}).get("capabilities", {}).get("owntalk_extensions")
    check("capability present", cap is not None and cap.get("api") == 1, str(cap))
    check(f"capability acl == {EXPECT_ACL}", cap and cap.get("acl") == EXPECT_ACL, str(cap))

    group = room(owner, 2, f"g{RUN}")
    for u in (mod, member):
        add(owner, group, u)
    call("POST", f"{TALK}/room/{group}/moderators", owner, {"attendeeId": attendee_id(owner, group, mod)})
    other = room(outsider, 2, f"o{RUN}")
    one = room(owner, 1, invite=peer[0])
    # Talk adds the invited one-to-one peer lazily, on the first message.
    st, _, _ = ext_get(peer, one)
    check("one-to-one peer before first message -> 404 (Talk lazy fill)", st == 404, str(st))
    call("POST", f"/ocs/v2.php/apps/spreed/api/v1/chat/{one}", owner, {"message": "hello"})
    public = room(owner, 3, f"p{RUN}")
    call("POST", f"{TALK}/room/{public}/participants/active", linker, {})

    # S0 / membership
    st, d, h = ext_get(member, group)
    check("member reads group settings 200", st == 200, str(st))
    check("ETag header r0 for empty room", h.get("ETag") == '"r0"', str(h.get("ETag")))
    st, _, _ = ext_get(member, group, {"If-None-Match": '"r0"'})
    check("If-None-Match matching -> 304", st == 304, str(st))
    st_out, d_out, _ = ext_get(outsider, group)
    st_rand, d_rand, _ = ext_get(outsider, "zzzz" + RUN + "zz")
    check("outsider reads group -> 404", st_out == 404, str(st_out))
    check("unknown token -> 404 with same body", st_rand == 404 and d_out == d_rand, f"{d_out} vs {d_rand}")
    check("invalid token -> 400", ext_get(member, "../x")[0] in (400, 404))
    st, _, _ = call("GET", f"{EXT}/room/{group}/settings")
    check("unauthenticated -> 401", st == 401, str(st))
    st, _, _ = ext_get(linker, public)
    talk_st, talk_d, _ = call("GET", f"{TALK}/room/{public}", linker)
    check("self-joined user in public room -> 404", st == 404, f"plugin {st}, talk {talk_st} type {talk_d and talk_d.get('participantType')}")
    st, _, _ = ext_get(outsider, one)
    check("outsider reads one-to-one -> 404", st == 404, str(st))

    reset_bruteforce()

    # Roles (S0b)
    st, d, _ = ext_get(member, group)
    roles = EXPECT_ACL == "roles"
    check("member canEdit false in group", st == 200 and d["permissions"]["canEdit"] is False, str(d and d["permissions"]))
    check("aclSource matches mode", d and d["permissions"]["aclSource"] == ("talk-ocs" if roles else "none"), str(d and d["permissions"]))
    st, d, _ = ext_patch(member, group, {"chat.backgroundColor": "#112233"}, 0)
    check("member PATCH group -> 403", st == 403 and d.get("error") == "field_forbidden", f"{st} {d}")
    st, d, _ = ext_patch(mod, group, {"chat.backgroundColor": "#112233"}, 0)
    check("moderator PATCH group", (st == 200) if roles else (st == 403), f"{st} {d}")
    st, d, _ = ext_patch(owner, one, {"bubble.ownColor": "#abcdef"}, 0)
    check("one-to-one owner PATCH -> 200", st == 200 and d["settings"]["bubble.ownColor"] == "#ABCDEF", f"{st} {d}")
    st, d, _ = ext_patch(peer, one, {"chat.backgroundColor": "#000000"}, 1)
    check("one-to-one peer PATCH -> 200", st == 200, f"{st} {d}")
    st, d, _ = ext_patch(outsider, one, {"chat.backgroundColor": "#000000"}, 2)
    check("outsider PATCH one-to-one -> 404", st == 404, f"{st} {d}")
    st, d, _ = ext_patch(outsider, group, {"chat.backgroundColor": "#000000"}, 0)
    check("cross-room PATCH -> 404", st == 404, f"{st} {d}")
    check("client-supplied role ignored", ext_patch(member, group, {"chat.backgroundColor": "#000000"}, 0)[0] == 403)

    if roles:
        # Demotion takes effect on the next request.
        call("DELETE", f"{TALK}/room/{group}/moderators", owner, {"attendeeId": attendee_id(owner, group, mod)})
        st, d, _ = ext_patch(mod, group, {"chat.accentColor": "#445566"}, rev(mod, group))
        check("demoted moderator PATCH -> 403 immediately", st == 403, f"{st} {d}")
        call("POST", f"{TALK}/room/{group}/moderators", owner, {"attendeeId": attendee_id(owner, group, mod)})
        # Read-only conversation
        call("PUT", f"{TALK}/room/{group}/read-only", owner, {"state": 1})
        st, _, _ = ext_patch(member, group, {"chat.accentColor": "#445566"}, rev(member, group))
        check("read-only: member -> 403", st == 403, str(st))
        st, d, _ = ext_patch(mod, group, {"chat.accentColor": "#445566"}, rev(mod, group))
        check("read-only: moderator -> 200", st == 200, f"{st} {d}")
        call("PUT", f"{TALK}/room/{group}/read-only", owner, {"state": 0})

    # Concurrency
    reset_bruteforce()
    base = rev(owner, one)
    st, d, _ = ext_patch(owner, one, {"chat.accentColor": "#010101"}, None)
    check("missing If-Match -> 428", st == 428, f"{st} {d}")
    ok1 = ext_patch(owner, one, {"chat.accentColor": "#020202"}, base)[0]
    st, d, _ = ext_patch(peer, one, {"chat.accentColor": "#030303"}, base)
    check("stale write of same field -> 412", ok1 == 200 and st == 412 and d.get("fields") == ["chat.accentColor"], f"{ok1} {st} {d}")
    st, d, _ = ext_patch(peer, one, {"bubble.otherColor": "#040404"}, base)
    check("stale write of other field -> 200", st == 200, f"{st} {d}")
    st, _, _ = ext_patch(owner, one, {"chat.accentColor": "#020202"}, base)
    check("replay of applied write -> 412", st == 412, str(st))
    base = rev(owner, one)
    with concurrent.futures.ThreadPoolExecutor(10) as pool:
        codes = list(pool.map(lambda i: ext_patch(owner if i % 2 else peer, one, {"chat.backgroundColor": f"#0000{i:02d}"}, base)[0], range(10)))
    check("10 parallel same-field writes: exactly one 200", codes.count(200) == 1 and codes.count(412) == 9, str(codes))

    # Validation
    for name, settings, exp in (
        ("unknown field -> 400", {"chat.font": "Comic"}, 400),
        ("CSS value -> 400", {"chat.backgroundColor": "red;background:url(x)"}, 400),
        ("short colour -> 400", {"chat.backgroundColor": "#fff"}, 400),
        ("two emojis -> 400", {"composer.quickEmoji": "👍👍"}, 400),
        ("text as emoji -> 400", {"composer.quickEmoji": "<b>"}, 400),
        ("number value -> 400", {"chat.backgroundColor": 123}, 400),
    ):
        st, d, _ = ext_patch(owner, one, settings, rev(owner, one))
        check(name, st == exp, f"{st} {d}")
    st, d, _ = ext_patch(owner, one, {"composer.quickEmoji": "❤️"}, rev(owner, one))
    check("valid emoji with variation selector -> 200", st == 200, f"{st} {d}")

    # Removal and ban take effect immediately
    reset_bruteforce()
    call("DELETE", f"{TALK}/room/{group}/attendees", owner, {"attendeeId": attendee_id(owner, group, member)})
    st, _, _ = ext_get(member, group)
    check("removed member -> 404 immediately", st == 404, str(st))
    add(owner, group, member)
    st, d, _ = call("POST", f"/ocs/v2.php/apps/spreed/api/v1/ban/{group}", owner, {"actorType": "users", "actorId": member[0], "internalNote": ""})
    if st == 200:
        check("banned member -> 404", ext_get(member, group)[0] == 404)
    else:
        check("ban API available", False, f"{st} {d}")
    call("DELETE", f"{TALK}/room/{one}/participants/self", peer)
    check("peer left one-to-one -> 404", ext_get(peer, one)[0] == 404)

    # Guessing tokens gets throttled by Nextcloud brute-force protection.
    reset_bruteforce()
    codes = [ext_get(outsider, f"zz{RUN}{i:04d}")[0] for i in range(15)]
    check("repeated unknown tokens end in 429", 429 in codes, str(codes))
    reset_bruteforce()

    failed = [r for r in results if not r[1]]
    print(f"\n{len(results) - len(failed)}/{len(results)} passed (run {RUN})")
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
