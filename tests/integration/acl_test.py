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
  OT_OCC_CMD     optional shell command prefix that runs occ on the instance
  OT_RESET_CMD   optional shell command that resets the brute-force counter for
                 the test client IP (the suite produces many intentional 404s)
"""
from __future__ import annotations

import base64
import concurrent.futures
import hashlib
import json
import os
import secrets
import struct
import subprocess
import time
import sys
import urllib.error
import urllib.request
import zlib

BASE = os.environ["OT_BASE"].rstrip("/")
ADMIN = (os.environ["OT_ADMIN_USER"], os.environ["OT_ADMIN_PASS"])
EXPECT_ACL = os.environ.get("OT_EXPECT_ACL", "roles")
RUN = secrets.token_hex(3)
TALK = "/ocs/v2.php/apps/spreed/api/v4"
EXT = "/ocs/v2.php/apps/owntalk_extensions/api/v1"

results: list[tuple[str, bool, str]] = []


def call(method, path, auth=None, body=None, headers=None, raw_response=False):
    data = None
    hdrs = {"OCS-APIRequest": "true", "Accept": "application/json"}
    if isinstance(body, bytes):
        data = body
    elif body is not None:
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
    if raw_response:
        return status, raw, resp_headers
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


def png(width, height, rgb=(30, 120, 200)):
    def chunk(kind, payload):
        return struct.pack(">I", len(payload)) + kind + payload + struct.pack(">I", zlib.crc32(kind + payload))
    rows = b"".join(b"\x00" + bytes(rgb) * width for _ in range(height))
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0))
            + chunk(b"IDAT", zlib.compress(rows)) + chunk(b"IEND", b""))


def ext_upload(user, token, data, content_type, base):
    hdrs = {"Content-Type": content_type}
    if base is not None:
        hdrs["If-Match"] = f'"r{base}"'
    return call("PUT", f"{EXT}/room/{token}/settings/image", user, data, hdrs)


def ext_image(user, token, sha):
    return call("GET", f"{EXT}/room/{token}/settings/image/{sha}", user, raw_response=True)


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
    member_etag = '"r0-e0"'
    check("ETag carries revision and permission", h.get("ETag") == member_etag, str(h.get("ETag")))
    st, _, _ = ext_get(member, group, {"If-None-Match": member_etag})
    check("If-None-Match matching -> 304", st == 304, str(st))
    st, _, _ = ext_get(member, group, {"If-None-Match": "W/" + member_etag})
    check("weak If-None-Match from a compressing proxy -> 304", st == 304, str(st))
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
        # Promotion changes the ETag, so a cached 304 cannot hide the new permission.
        _, _, before = ext_get(member, group)
        call("POST", f"{TALK}/room/{group}/moderators", owner, {"attendeeId": attendee_id(owner, group, member)})
        time.sleep(16)  # reads may use role data cached for up to 15 s
        st, d, h = ext_get(member, group, {"If-None-Match": before.get("ETag", "")})
        check("promoted member: old ETag -> 200 with canEdit", st == 200 and d["permissions"]["canEdit"] is True and h.get("ETag", "").endswith('-e1"'), f"{st} {h.get('ETag')}")
        call("DELETE", f"{TALK}/room/{group}/moderators", owner, {"attendeeId": attendee_id(owner, group, member)})
        st, d, _ = ext_patch(member, group, {"chat.backgroundColor": "#222222"}, rev(member, group))
        check("demoted again -> PATCH 403 despite cached read", st == 403, f"{st} {d}")
        st, _, _ = call("PATCH", f"{EXT}/room/{group}/settings", member, {"settings": {"chat.backgroundColor": "#222222"}}, {"If-Match": f'W/"r{rev(member, group)}"'})
        check("weak If-Match is accepted as precondition", st == 403, str(st))
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

    # Presets and background image
    reset_bruteforce()
    check("capability lists presets and image limits", cap and "aurora" in cap.get("presets", []) and cap.get("limits", {}).get("maxImageBytes") == 10485760
          and {"appearance.presets", "appearance.image"} <= set(cap.get("features", [])), str(cap))
    st, d, _ = ext_patch(owner, one, {"chat.backgroundPreset": "aurora"}, rev(owner, one))
    check("listed preset -> 200", st == 200 and d["settings"]["chat.backgroundPreset"] == "aurora", f"{st} {d}")
    st, d, _ = ext_patch(owner, one, {"chat.backgroundPreset": "../aurora"}, rev(owner, one))
    check("unlisted preset -> 400", st == 400 and d.get("error") == "invalid_value", f"{st} {d}")
    st, d, _ = ext_patch(owner, one, {"chat.backgroundImage": "a" * 64}, rev(owner, one))
    check("PATCH cannot point at an image -> 400", st == 400, f"{st} {d}")
    picture = png(64, 48)
    st, d, _ = ext_upload(member, group, picture, "image/png", rev(member, group))
    check("member upload in group -> 403", st == 403 and d.get("error") == "field_forbidden", f"{st} {d}")
    st, _, _ = ext_upload(outsider, one, picture, "image/png", 0)
    check("outsider upload -> 404", st == 404, str(st))
    st, d, _ = ext_upload(owner, one, picture, "image/png", None)
    check("upload without If-Match -> 428", st == 428, f"{st} {d}")
    base = rev(owner, one)
    st, d, h = ext_upload(owner, one, picture, "image/png", base)
    sha = (d or {}).get("settings", {}).get("chat.backgroundImage") or ""
    check("owner upload -> 200 with hash and ETag", st == 200 and len(sha) == 64 and h.get("ETag", "").startswith('"r'), f"{st} {d}")
    st, d, _ = ext_upload(peer, one, png(10, 10, (200, 0, 0)), "image/png", base)
    check("stale upload -> 412 on the image field", st == 412 and d.get("fields") == ["chat.backgroundImage"], f"{st} {d}")
    for name, data, ctype, exp in (
        ("GIF -> 415", b"GIF89a\x01\x00\x01\x00\x00\x00\x00;", "image/gif", 415),
        ("SVG -> 415", b'<svg xmlns="http://www.w3.org/2000/svg"/>', "image/svg+xml", 415),
        ("PNG declared as JPEG -> 400", picture, "image/jpeg", 400),
        ("garbage -> 400", b"x" * 200, "image/png", 400),
        ("over 10 MiB -> 413", b"\x00" * (10 * 1024 * 1024 + 1), "image/png", 413),
    ):
        st, d, _ = ext_upload(peer, one, data, ctype, rev(peer, one))
        check(name, st == exp, f"{st} {d}")
    st, body, h = ext_image(peer, one, sha)
    headers = {k.lower(): v for k, v in h.items()}
    check("participant downloads re-encoded JPEG", st == 200 and body[:2] == b"\xff\xd8" and hashlib.sha256(body).hexdigest() == sha
          and headers.get("content-type", "").startswith("image/jpeg"), f"{st} {headers.get('content-type')}")
    check("image is private and immutable", "private" in headers.get("cache-control", "") and "immutable" in headers.get("cache-control", ""),
          headers.get("cache-control", ""))
    check("outsider image -> 404", ext_image(outsider, one, sha)[0] == 404)
    check("image of another room via own room -> 404", ext_image(member, group, sha)[0] == 404)
    check("malformed hash -> 404", ext_image(peer, one, "../" + sha[:61])[0] == 404)
    st, d, _ = ext_patch(peer, one, {"chat.backgroundImage": None}, rev(peer, one))
    check("PATCH clears the image", st == 200 and d["settings"]["chat.backgroundImage"] is None, f"{st} {d}")
    check("cleared image -> 404", ext_image(peer, one, sha)[0] == 404)

    # Deleting the last writer removes their user ID from the document.
    writer = new_user("wrt")
    wroom = room(owner, 1, invite=writer[0])
    call("POST", f"/ocs/v2.php/apps/spreed/api/v1/chat/{wroom}", owner, {"message": "hi"})
    ext_patch(writer, wroom, {"chat.backgroundColor": "#123456"}, 0)
    call("DELETE", f"/ocs/v2.php/cloud/users/{writer[0]}", ADMIN)
    st, d, _ = ext_get(owner, wroom)
    check("deleted user is no longer named as last writer", st == 200 and d["updatedBy"] is None and d["settings"]["chat.backgroundColor"] == "#123456", f"{st} {d and d.get('updatedBy')}")

    # Settings of a deleted conversation are removed by the cleanup.
    gone = room(owner, 1, invite=mod[0])
    call("POST", f"/ocs/v2.php/apps/spreed/api/v1/chat/{gone}", owner, {"message": "hi"})
    st, _, _ = ext_patch(owner, gone, {"chat.accentColor": "#654321"}, 0)
    check("settings stored before the conversation is deleted", st == 200, str(st))
    # A one-to-one conversation is deleted by Talk when its last participant leaves.
    call("DELETE", f"{TALK}/room/{gone}/participants/self", mod)
    call("DELETE", f"{TALK}/room/{gone}/participants/self", owner)
    occ = os.environ.get("OT_OCC_CMD")
    if occ:
        out = subprocess.run(["bash", "-c", occ + " owntalk-extensions:cleanup"], capture_output=True, text=True).stdout
        check("cleanup deletes settings of a deleted conversation", "Deleted settings of" in out and not out.strip().startswith("Deleted settings of 0"), out.strip())
        check("cleanup reports unused images", "unused background images" in out, out.strip())

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
