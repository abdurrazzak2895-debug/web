#!/usr/bin/env python3
"""
Analyze IVAC captcha token transformation algorithm.
Fetches appointment.ivacbd.com via proxy, extracts the JS bundle,
checks for algorithm magic numbers, and detects OFFSET/LENGTH constants.
"""

import sys
import os
import re
import json
import time
import hashlib
import subprocess
from urllib.request import Request, urlopen
from urllib.error import HTTPError, URLError
from urllib.parse import urljoin
from datetime import datetime, timezone
from typing import Optional

# Page to scrape the Vite bundle <script src> from. Use /signin, not /: IVAC swaps
# "/" for a time-gated "Appointment Booking Guidelines" notice page during booking
# hours (no bundle in that HTML), while /signin always serves the real SPA.
BUNDLE_PAGE_URL = "https://appointment.ivacbd.com/signin"
WELL_KNOWN_URL = "https://appointment.ivacbd.com/.well-known/"

# Forces Cloudflare to revalidate against origin instead of serving a stale edge-cached
# copy of /signin from before the latest redeploy. Combined with NO_CACHE_PARAMS below.
NO_CACHE_HEADERS = {"Cache-Control": "no-cache", "Pragma": "no-cache"}

# Single, overwritten-each-run dump of the live IVAC JS bundle for manual inspection.
BUNDLE_DUMP_PATH = os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
    "storage", "app", "captcha", "ivac-bundle.js",
)

# Fixed token used for PHP vs JS cross-validation.
_CROSS_VALIDATION_TOKEN = '0.Abc123-_xYzDEFghijklmNOPQRSTUVWXYZ0123456789abcdefghijklmnopqrstuvwxyz'

# base64url alphabet the transform maps into. Used by the well-formedness canary to
# verify a live module produced a sane token (right length, untouched prefix/suffix,
# transformed window made only of charset chars) — a poisoned/empty output fails this.
_CHARSET = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_'

# Single-boot probe: evaluates the bundle ONCE and returns both the resolved secrets/charset
# AND the live ground-truth encrypt outputs, replacing the two separate cold Node executions.
# It reuses the shared runtime (captcha_live_runtime.cjs) internally for module exposure and
# the well-formedness guard, so that stays the single source of truth for running the bundle.
PROBE_PATH = os.path.join(os.path.dirname(os.path.abspath(__file__)), "captcha_probe.cjs")

# Per-type module/version metadata consumed by the encrypt sidecar.
ENCRYPT_META_PATH = os.path.join(
    os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))),
    "storage", "app", "captcha", "encrypt_meta.json",
)

# Content-addressed memo for the two expensive Node bundle evaluations. Both the
# secret/call-site probe and the live-module ground-truth run are pure functions of
# the bundle bytes, so their results are cached by sha256(bundle). A byte-identical
# bundle (the normal state between IVAC redeploys) then skips both ~1.2 s cold-Node
# executions of the 2 MB bundle and returns in ~ms; a genuine redeploy misses once
# and repopulates. Cache is never invalidated: different bytes => different key.
ANALYSIS_CACHE_DIR = os.path.join(os.path.dirname(ENCRYPT_META_PATH), "analysis_cache")

# Bump when the cached payload shape OR the probe's output semantics change, so stale
# entries are ignored, not misread.
# v2: single-boot probe result (charset + call sites + per-site live outputs in one payload),
#     replacing the separate consts_/live_ cache entries of the two-boot pipeline.
# v3: well-formedness canary now lets a non-alphabet input char (e.g. the "." token
#     separator inside the window when skip < 2) pass through unchanged instead of nulling
#     the output — v2 entries cached a null login output and must not be reused.
# v5: key now also carries _EXTRACTOR_FINGERPRINT (see below), so this constant only needs
#     bumping for a deliberate payload-shape change, not for every extractor fix.
ANALYSIS_CACHE_VERSION = "v5"


def _sha256(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8", errors="ignore")).hexdigest()


def _extractor_fingerprint() -> str:
    """Short hash of the code that produces a cached probe payload.

    The cache is content-addressed by BUNDLE bytes only, which silently serves a stale
    result whenever the extractor itself is fixed: after a redeploy changes the config
    emission shape, the failed run is cached, and the fix that would parse it is then
    never exercised on that bundle (the Aug 2026 comma-sequence rotation hit exactly
    this — the fix looked ineffective until the entry was deleted by hand). Folding the
    extractor sources into the key makes any change to them invalidate the cache
    automatically, so a fix takes effect on the next run with no manual cleanup.
    """
    parts = []
    for path in (os.path.abspath(__file__), PROBE_PATH,
                 os.path.join(os.path.dirname(PROBE_PATH), "captcha_live_runtime.cjs")):
        try:
            with open(path, "rb") as fh:
                parts.append(hashlib.sha256(fh.read()).hexdigest())
        except OSError:
            parts.append(path)
    return hashlib.sha256("".join(parts).encode("utf-8")).hexdigest()[:12]


_EXTRACTOR_FINGERPRINT = _extractor_fingerprint()


def _bundle_identity(js_code: str) -> str:
    """Content hash of the real bundle, ignoring the analyzer's own prepended
    '// source:' bookkeeping header(s).

    dump_bundle() prepends a '// source: <url>' line on every write, so the same
    deployed bundle can carry a different number of header lines across runs. Keying
    the cache on the header-stripped body makes it stable for one deployed bundle no
    matter how many times it has been re-dumped, and still distinct across real
    redeploys (the body differs).
    """
    body = js_code
    while body.startswith("// source:"):
        newline = body.find("\n")
        if newline == -1:
            break
        body = body[newline + 1:]
    return _sha256(body)


def _analysis_cache_load(key: str):
    """Return the cached object for key, or None on miss/corrupt/unavailable."""
    try:
        with open(os.path.join(ANALYSIS_CACHE_DIR, key + ".json"), "r", encoding="utf-8") as fh:
            return json.load(fh)
    except (OSError, ValueError):
        return None


def _analysis_cache_store(key: str, obj) -> None:
    """Best-effort atomic cache write; failures (perms, disk) are silently ignored."""
    try:
        os.makedirs(ANALYSIS_CACHE_DIR, exist_ok=True)
        dest = os.path.join(ANALYSIS_CACHE_DIR, key + ".json")
        tmp = dest + ".tmp." + str(os.getpid())
        with open(tmp, "w", encoding="utf-8") as fh:
            json.dump(obj, fh)
        os.replace(tmp, dest)
        _analysis_cache_prune()
    except OSError:
        pass


def _analysis_cache_prune(keep: int = 40) -> None:
    """Keep only the newest cache files so the directory can't grow unbounded."""
    try:
        entries = [
            os.path.join(ANALYSIS_CACHE_DIR, name)
            for name in os.listdir(ANALYSIS_CACHE_DIR)
            if name.endswith(".json")
        ]
        if len(entries) <= keep:
            return
        entries.sort(key=lambda p: os.path.getmtime(p), reverse=True)
        for stale in entries[keep:]:
            try:
                os.unlink(stale)
            except OSError:
                pass
    except OSError:
        pass


def dump_bundle(js_code: str, bundle_url: str, log, source_path: Optional[str] = None) -> Optional[str]:
    """Write the downloaded JS bundle to a single fixed path, overwriting any prior dump.

    Keeps only one file on disk. Failures are non-fatal (logged, not raised).

    Edge-path no-op: when source_path IS already the dump target (the edge fetcher wrote
    the bundle straight to BUNDLE_DUMP_PATH and handed it back via --bundle), do NOT
    rewrite it. Rewriting would prepend a header that changes the on-disk bytes the
    sidecar just staged — breaking the instant /promote hash match — and pointlessly
    round-trip the bytes through a utf-8 decode/encode. Leaving the file untouched keeps
    the staged bytes byte-identical to what analysis hashes, and also avoids the header
    accumulation the local --bundle path used to cause.
    """
    try:
        if source_path and os.path.abspath(source_path) == os.path.abspath(BUNDLE_DUMP_PATH):
            log(f"[4/6] ✓ Bundle already at {BUNDLE_DUMP_PATH} (edge path — not rewritten)")
            return BUNDLE_DUMP_PATH
        os.makedirs(os.path.dirname(BUNDLE_DUMP_PATH), exist_ok=True)
        header = f"// source: {bundle_url}\n"
        with open(BUNDLE_DUMP_PATH, "w", encoding="utf-8") as f:
            f.write(header)
            f.write(js_code)
        log(f"[4/6] ✓ Bundle dumped to {BUNDLE_DUMP_PATH}")
        return BUNDLE_DUMP_PATH
    except Exception as e:
        log(f"[4/6] ⚠ Failed to dump bundle: {e}")



def _extract_module_map(js_code: str) -> dict:
    """Map algorithm version -> module variable from the bundle dispatch table.

    The dispatch table looks like wV={1:()=>Xe(()=>Promise.resolve().then(()=>XX)),...}.
    The table variable name changes across deploys, so we match the value shape
    directly rather than the table name.
    """
    mapping = {}
    for m in re.finditer(
        r'(\d+):\(\)=>[A-Za-z_$][\w$]*\(\(\)=>Promise\.resolve\(\)\.then\(\(\)=>([A-Za-z_$][\w$]*)\)\)',
        js_code,
    ):
        mapping[int(m.group(1))] = m.group(2)
    return mapping


def _read_config_int(code: str, start: int) -> tuple:
    """Read one captcha-config value expression starting at `start` and return its integer.

    Handles both the legacy bare-literal form (`startAt:1`) and the 2026-07 wrapped form
    where the digit is a quoted string argument to a wrapper call, e.g. `startAt:Number("1")`
    or `length:c[f(1486)](_0x1a54bf,"27")`. The value expression can itself contain commas
    inside parens/brackets, so the scan is paren/bracket/brace-depth aware and stops only at
    a top-level ',' or the object's closing brace.

    Returns (int_value_or_None, end_pos) where end_pos points at the stopping character.
    """
    depth = 0
    i = start
    in_str = False
    str_ch = None
    while i < len(code):
        ch = code[i]
        if in_str:
            if ch == '\\':
                i += 2
                continue
            elif ch == str_ch:
                in_str = False
        else:
            if ch in ('"', "'", '`'):
                in_str = True
                str_ch = ch
            elif ch in '([{':
                depth += 1
            elif ch in ')]}':
                if depth == 0:
                    break
                depth -= 1
            elif ch == ',' and depth == 0:
                break
        i += 1
    expr = code[start:i]
    quoted = re.search(r'"(\d{1,2})"', expr)
    if quoted:
        return int(quoted.group(1)), i
    bare = re.search(r'(\d{1,2})', expr)
    if bare:
        return int(bare.group(1)), i
    return None, i


def _match_brace(code: str, open_pos: int) -> Optional[int]:
    """Given the index of a `{`, return the index of its matching `}` (string-aware)."""
    depth = 0
    i = open_pos
    in_str = False
    str_ch = None
    while i < len(code):
        ch = code[i]
        if in_str:
            if ch == '\\':
                i += 2
                continue
            elif ch == str_ch:
                in_str = False
        else:
            if ch in ('"', "'", '`'):
                in_str = True
                str_ch = ch
            elif ch == '{':
                depth += 1
            elif ch == '}':
                depth -= 1
                if depth == 0:
                    return i
        i += 1
    return None


def _enclosing_open(code: str, pos: int, open_ch: str, close_ch: str, max_back: int = 20000) -> Optional[int]:
    """Index of the innermost UNMATCHED `open_ch` before `pos`, or None.

    Scans backward string-aware (the first quote met going backward is a string's CLOSING
    quote, so the body is skipped to its unescaped opening quote) — obfuscated bundles hide
    stray braces and parens inside string literals, which a naive scan would miscount.
    """
    depth = 0
    i = pos - 1
    limit = max(0, pos - max_back)
    while i >= limit:
        ch = code[i]
        if ch in ('"', "'", '`'):
            q = ch
            i -= 1
            while i >= 0 and not (code[i] == q and (i == 0 or code[i - 1] != '\\')):
                i -= 1
            i -= 1
            continue
        if ch == close_ch:
            depth += 1
        elif ch == open_ch:
            if depth == 0:
                return i
            depth -= 1
        i -= 1
    return None


# Guard on how many nested comma-sequence/paren wrappers _resolve_config_owner walks out
# through before giving up.
_MAX_SEQUENCE_DEPTH = 4

_CONFIG_ASSIGN_RE = re.compile(r'([A-Za-z_$][\w$]{0,9})\s*=\s*$')

# Characters that, immediately before an `=`, make it a compound/comparison operator
# (==, ===, !=, <=, >=, +=, ...) rather than the plain assignment we are looking for.
_NON_ASSIGN_PREFIX = '=!<>+-*/%&|^'


def _resolve_config_owner(code: str, obj_open: int) -> tuple:
    """Resolve the identifier a config object literal is assigned to.

    Handles both emission shapes seen in the wild:
      - direct:   `IDENT={secret:...}`
      - sequence: `IDENT=(decoy(),decoy(),...,{secret:...})`  (Aug 2026 bundles wrap the
        config as the last operand of a parenthesized comma-sequence of decoy calls, so
        the object no longer sits immediately after the `=`)

    Walks left from the object's `{`, hopping out through any enclosing comma-sequence
    parens until it reaches the `=`. Returns (var_name, assign_end) where assign_end is the
    index of the character that completes the assignment — the sequence's closing `)` for
    the wrapped shape, or None for the direct shape (the caller then uses the object's own
    closing brace). Returns (None, None) when no owning identifier can be resolved.
    """
    cur = obj_open
    for _ in range(_MAX_SEQUENCE_DEPTH):
        j = cur - 1
        while j >= 0 and code[j] in ' \t\r\n':
            j -= 1
        if j < 0:
            return None, None
        # A real assignment `=`, not part of ==, ===, !=, <=, >=, +=, =>, ...
        # j == 0 is guarded explicitly: code[j - 1] would wrap to the last char of the file.
        if (code[j] == '=' and j > 0 and code[j - 1] not in _NON_ASSIGN_PREFIX
                and code[j + 1] != '='):
            m = _CONFIG_ASSIGN_RE.search(code[max(0, j - 60):j + 1])
            if not m:
                return None, None
            assign_end = None if cur == obj_open else _match_paren_fwd(code, cur)
            return m.group(1), assign_end
        if code[j] in ',(':
            popen = _enclosing_open(code, cur, '(', ')')
            if popen is None:
                return None, None
            cur = popen
            continue
        return None, None
    return None, None


# Control-flow keywords whose `(...)` { } is a guard block, not a function body. Used by
# _enclosing_computed_method to walk out through guards to the real enclosing method.
_CONTROL_KW = {'if', 'for', 'while', 'switch', 'catch', 'with', 'do'}


def _match_paren_back(code: str, close_idx: int) -> Optional[int]:
    """Given the index of a ')', return the index of its matching '(', skipping string
    literals while scanning backward.

    String-awareness matters: an obfuscated guard like `if(this[u(797,"O(8i")]()){` holds a
    '(' INSIDE the string "O(8i", which a naive scan would count as a real paren and mismatch
    (misreading the guard as a named function). Going backward, the first quote we meet is a
    string's CLOSING quote, so we jump back to its (unescaped) opening quote and skip the body.
    """
    depth = 0
    i = close_idx
    while i >= 0:
        ch = code[i]
        if ch in ('"', "'", '`'):
            q = ch
            i -= 1
            while i >= 0 and not (code[i] == q and (i == 0 or code[i - 1] != '\\')):
                i -= 1
            i -= 1
            continue
        if ch == ')':
            depth += 1
        elif ch == '(':
            depth -= 1
            if depth == 0:
                return i
        i -= 1
    return None


def _read_value_end(code: str, start: int) -> int:
    """Return the index where an object-property value starting at `start` ends: the first
    top-level ',' or the object's closing '}' (paren/bracket/brace/string-depth aware)."""
    depth = 0
    i = start
    in_str = False
    str_ch = None
    while i < len(code):
        ch = code[i]
        if in_str:
            if ch == '\\':
                i += 2
                continue
            elif ch == str_ch:
                in_str = False
        else:
            if ch in ('"', "'", '`'):
                in_str = True
                str_ch = ch
            elif ch in '([{':
                depth += 1
            elif ch in ')]}':
                if depth == 0:
                    break
                depth -= 1
            elif ch == ',' and depth == 0:
                break
        i += 1
    return i


def _enclosing_computed_method(js_code: str, pos: int, max_back: int = 60000) -> tuple:
    """Return (body_open, body_close) of the innermost enclosing computed class method
    ( ...[expr](){...} ) around `pos`, or (None, None).

    The 2026-07 bundles moved the captcha config objects INTO obfuscated static class
    methods — `static [decoder()+...]() { ...config... }` — which never execute in a
    headless run (React never mounts), so the normal in-place `globalThis.X = VAR` capture
    injected right after the config never fires. Detecting this enclosing method lets the
    caller re-run its body at module scope under a permissive `this` to materialise the
    config's secret.

    ONLY computed-member methods qualify (the `[expr](){` signature these obfuscated class
    methods use). A named function / arrow enclosing `pos` first means we are NOT inside such
    a method (module-scope configs resolve via the normal capture) → (None, None). Plain
    `{...}` blocks and `if/for/while/...` guard blocks are transparently skipped while
    walking outward toward the method body.
    """
    depth = 0
    i = pos - 1
    limit = max(0, pos - max_back)
    while i >= limit:
        ch = js_code[i]
        # String-awareness: a config method body holds string literals that can carry stray
        # braces (e.g. `"cRVI}"` in an obfuscated secret concat). Scanning backward, the first
        # quote we meet is a string's CLOSING quote — jump back to its unescaped opening quote
        # and skip the body so those braces do not corrupt the depth count (mirrors
        # _match_paren_back). Without this the walk never balances to the method's body `{`.
        if ch in ('"', "'", '`'):
            q = ch
            i -= 1
            while i >= 0 and not (js_code[i] == q and (i == 0 or js_code[i - 1] != '\\')):
                i -= 1
            i -= 1
            continue
        if ch == '}':
            depth += 1
        elif ch == '{':
            if depth > 0:
                depth -= 1
            else:
                j = i - 1
                while j >= 0 and js_code[j] in ' \t\r\n':
                    j -= 1
                if j >= 0 and js_code[j] == ')':
                    op = _match_paren_back(js_code, j)
                    if op is not None:
                        k = op - 1
                        while k >= 0 and js_code[k] in ' \t\r\n':
                            k -= 1
                        if k >= 0 and js_code[k] == ']':
                            close = _match_brace(js_code, i)
                            if close is not None and close > pos:
                                return i, close
                            return None, None
                        end = k
                        while k >= 0 and (js_code[k].isalnum() or js_code[k] in '_$'):
                            k -= 1
                        word = js_code[k + 1:end + 1]
                        if word not in _CONTROL_KW:
                            # A named function / arrow encloses pos first — not our target.
                            return None, None
                        # else: a guard/control block — keep walking outward.
        i -= 1
    return None, None


def _match_paren_fwd(code: str, open_idx: int) -> Optional[int]:
    """Given the index of a '(', return the index of its matching ')' (string-aware)."""
    depth = 0
    i = open_idx
    in_str = False
    str_ch = None
    while i < len(code):
        ch = code[i]
        if in_str:
            if ch == '\\':
                i += 2
                continue
            elif ch == str_ch:
                in_str = False
        else:
            if ch in ('"', "'", '`'):
                in_str = True
                str_ch = ch
            elif ch == '(':
                depth += 1
            elif ch == ')':
                depth -= 1
                if depth == 0:
                    return i
        i += 1
    return None


def _extract_method_decls(body: str) -> str:
    """Return only the top-level function declarations and const/let/var declarations from a
    class-method body, dropping its guards, returns, throws and bare blocks.

    Why not just re-run the method: the 2026-07 configs are gated behind `this`-method guards
    ( `if(this[X]())return...` early-exits AND `if(this[Z]()){...config...}` gates ) whose
    real truthiness depends on the class instance, so no uniform stub `this` reaches the
    config. But the config's `secret:` expression only needs the method's decoder helpers and
    its plain locals (`const n=...`, the object it indexes) — all of which are hoisted /
    top-level declarations. Re-emitting just those and evaluating the secret expression against
    them sidesteps the guards entirely (this is exactly how the secret is recovered by hand).

    A control block ( `if(cond){...}` ) is skipped by its matching brace, NOT to the next
    newline: the obfuscated helper declarations often follow a guard block with no `;`/newline
    separator ( `...}}function W(){...}` ), so a newline-based skip would swallow them.
    """
    out = []
    i = 0
    n = len(body)
    while i < n:
        ch = body[i]
        if ch in ' \t\r\n;,':
            i += 1
            continue
        fm = re.match(r'function\s+[A-Za-z_$][\w$]*\s*\(', body[i:i + 80])
        if fm:
            bopen = body.find('{', i + fm.end() - 1)
            bclose = _match_brace(body, bopen) if bopen != -1 else None
            if bclose is None:
                break
            out.append(body[i:bclose + 1])
            i = bclose + 1
            continue
        if re.match(r'(?:const|let|var)[\s{[(]', body[i:i + 6]):
            end = _find_statement_end(body, i)
            out.append(body[i:end])
            i = end + 1
            continue
        cm = re.match(r'(?:if|for|while|switch|catch|with)\s*\(', body[i:i + 10])
        if cm:
            popen = body.find('(', i)
            pclose = _match_paren_fwd(body, popen) if popen != -1 else None
            j = (pclose + 1) if pclose is not None else (i + cm.end())
            while j < n and body[j] in ' \t\r\n':
                j += 1
            if j < n and body[j] == '{':
                bclose = _match_brace(body, j)
                i = (bclose + 1) if bclose is not None else n
            else:
                i = _find_statement_end(body, j) + 1
            continue
        if ch == '{':
            bclose = _match_brace(body, i)
            i = (bclose + 1) if bclose is not None else i + 1
            continue
        i = _find_statement_end(body, i) + 1
    return ';'.join(out)


def _config_secret_expr(js_code: str, obj_open: int, obj_close: int) -> Optional[str]:
    """Return the raw `secret:` value expression from a config object literal, or None."""
    obj = js_code[obj_open:obj_close + 1]
    sm = re.search(r'secret\s*:', obj)
    if not sm:
        return None
    colon = obj.index(':', sm.start())
    return obj[colon + 1:_read_value_end(obj, colon + 1)]


def _scan_config_literals(js_code: str) -> list:
    """Find every `{...startAt:S,length:L,version:V...}` captcha config in the bundle.

    Anchors on `startAt:` then reads the three ordered values with _read_config_int, so it
    survives BOTH the wrapping obfuscation (`Number("1")` / `wrapper(...,"27")`, minified
    bundles) AND the beautified bare-int form (`startAt: 1,\\n length: 27`). Also resolves
    the enclosing object's variable name and brace span so capture can target it by POSITION
    (the config vars are now non-unique single letters in multi-declarator statements, so
    searching for a unique `const VAR={` no longer works).

    Returns [{'pos', 'skip', 'enc_len', 'version', 'var', 'obj_open', 'obj_close'}] (unfiltered).
    """
    out = []
    for anchor in re.finditer(r'startAt\s*:', js_code):
        skip, p1 = _read_config_int(js_code, anchor.end())
        lm = re.match(r'\s*,\s*length\s*:', js_code[p1:p1 + 48])
        if skip is None or not lm:
            continue
        enc_len, p2 = _read_config_int(js_code, p1 + lm.end())
        vm = re.match(r'\s*,\s*version\s*:', js_code[p2:p2 + 48])
        if enc_len is None or not vm:
            continue
        version, _ = _read_config_int(js_code, p2 + vm.end())
        if version is None:
            continue
        sa = anchor.start()
        # The config object is, by definition, the innermost brace enclosing this `startAt:`
        # — resolve it structurally rather than by matching an `IDENT = {` prefix, which only
        # held while the object sat immediately after the `=`.
        obj_open = _enclosing_open(js_code, sa, '{', '}')
        if obj_open is None:
            continue
        obj_close = _match_brace(js_code, obj_open)
        if obj_close is None or obj_close < sa:
            continue
        var, assign_end = _resolve_config_owner(js_code, obj_open)
        if var is None:
            continue
        out.append({'pos': sa, 'skip': skip, 'enc_len': enc_len, 'version': version,
                    'var': var, 'obj_open': obj_open, 'obj_close': obj_close,
                    'assign_end': assign_end})
    return out


def _extract_config_versions(js_code: str) -> list:
    """Extract every {startAt:N,length:M,version:V} config literal from the bundle."""
    return [{'skip': c['skip'], 'enc_len': c['enc_len'], 'version': c['version']}
            for c in _scan_config_literals(js_code)]


def _version_for(site: Optional[dict], configs: list) -> Optional[int]:
    """Find the config version whose (startAt, length) matches a resolved call site."""
    if not site:
        return None
    for c in configs:
        if c['skip'] == site.get('skip') and c['enc_len'] == site.get('enc_len'):
            return c['version']
    return None


def _attribute_for_live(call_sites: list) -> tuple:
    """Attribute resolved call sites to (login_site, reserve_site) for live execution.

    Mirrors CaptchaAlgorithmService::attributeCallSites so encrypt_meta.json always
    carries both token types: prefer the component-context 'algorithm' label, then
    fill a single remaining gap by elimination over the distinct (skip, enc_len)
    groups.

    There is deliberately NO "larger enc_len = reserve" guess: it is provably wrong
    (login enc_len has exceeded reserve's, and the 9/19 config has been login in one
    bundle and reserve in others), so guessing silently ships a mis-encrypted token.
    When neither group can be labelled, both are returned None so the caller fails
    loud (needs-attention) instead of mis-attributing.

    Labelled sites (algorithm='login'/'reserve') are collected BEFORE deduplication
    so that login and reserve can share the same (skip, enc_len) pair — each keeps
    its own site dict with its own secret. Dedup by (skip, enc_len) only applies to
    unlabelled sites (used as the elimination pool).

    Returns (login_site, reserve_site); either may be None if unresolvable.
    """
    login = None
    reserve = None
    unlab_groups = []
    unlab_seen = set()

    for cs in call_sites:
        if not cs.get('secret'):
            continue
        alg = cs.get('algorithm')
        if alg == 'login' and login is None:
            login = cs
        elif alg == 'reserve' and reserve is None:
            reserve = cs
        else:
            key = (cs.get('skip'), cs.get('enc_len'))
            if key not in unlab_seen:
                unlab_seen.add(key)
                unlab_groups.append(cs)

    # Elimination is only safe when exactly one type was labelled and a single
    # unlabelled group remains: it is unambiguously the complementary type.
    if reserve is None and login is not None and len(unlab_groups) == 1:
        reserve = unlab_groups[0]
    elif login is None and reserve is not None and len(unlab_groups) == 1:
        login = unlab_groups[0]

    # Identical-configs fallback (2026-07 bundles): when the component-context markers are
    # too far from the configs to attribute either type (both algorithm=None) BUT every
    # secret-resolved site is byte-identical — same skip, enc_len, version AND secret — the
    # two token types encrypt through the exact same function, so which var maps to which
    # type is immaterial. Assign them so both types resolve instead of failing loud. This is
    # provably safe: a mis-assignment between identical configs produces identical tokens.
    # It deliberately does NOT fire when the sites differ (that would risk mis-encryption).
    if login is None and reserve is None:
        resolved = [cs for cs in call_sites if cs.get('secret')]
        if resolved:
            def _identity(cs: dict) -> tuple:
                return (cs.get('skip'), cs.get('enc_len'), cs.get('version'), cs.get('secret'))
            first = _identity(resolved[0])
            if all(_identity(cs) == first for cs in resolved):
                login = resolved[0]
                reserve = resolved[1] if len(resolved) > 1 else resolved[0]

    return login, reserve


def _wellformed_output(token: str, out: Optional[str], skip: Optional[int], enc_len: Optional[int]) -> bool:
    """Canary: is `out` a sane transform of `token` for the given skip/enc_len?

    A correct transform leaves the first `skip` chars and everything after
    `skip + enc_len` untouched, and remaps the middle window — same overall length.
    Within the window, each char whose INPUT char is in the charset is transformed and
    must map back into the charset; a char whose input is NOT in the charset (e.g. the
    "." token separator that falls inside the window whenever skip < 2) passes through
    unchanged. Requiring every window char to be in the charset would wrongly reject that
    valid "." pass-through. A poisoned/empty/garbage module output still fails this, so we
    refuse to publish it rather than ship a 400.
    """
    if not isinstance(out, str) or skip is None or enc_len is None:
        return False
    if len(out) != len(token):
        return False
    if out[:skip] != token[:skip]:
        return False
    if out[skip + enc_len:] != token[skip + enc_len:]:
        return False
    window = out[skip:skip + enc_len]
    if not window:
        return False
    in_window = token[skip:skip + enc_len]
    for out_char, in_char in zip(window, in_window):
        if in_char in _CHARSET:
            if out_char not in _CHARSET:
                return False
        elif out_char != in_char:
            return False
    return True


def _compute_live_outputs(js_code: str, bundle_path: Optional[str], login_site: Optional[dict], reserve_site: Optional[dict], logs: list, write_meta: bool = True) -> dict:
    """Run the LIVE bundle's actual encrypt modules on a fixed test token.

    This is true ground truth: the PHP transformer is compared against the output
    of the site's own code, so a version rotation (algorithm change) is detected even
    when offset/length/secret all still match. Also returns encrypt_meta describing
    which module implements each token type, for the encryption sidecar.

    The encrypt_meta written to disk carries the full per-type config the sidecar
    needs to encrypt without any DB seed: module, version, skip, enc_len AND secret.
    To keep the live-bundle the single source of truth, meta is only overwritten when
    BOTH token types fully resolve and both live module runs succeed; otherwise the
    previous (last-known-good) meta is left untouched so a partial/broken extraction
    can never corrupt a working config. The write is atomic (temp file + os.replace).

    Returns {test_token, login?, reserve?, encrypt_meta, meta_written, extraction_ok,
    extraction_reason, modules, bundle_hash}.
    """
    result = {
        'test_token': _CROSS_VALIDATION_TOKEN,
        'encrypt_meta': None,
        'meta_written': False,
        'extraction_ok': False,
        'wellformed': {'login': False, 'reserve': False},
    }

    module_map = _extract_module_map(js_code)
    configs = _extract_config_versions(js_code)

    # Versions the bundle's dispatch table can actually load. When a token type's
    # config selects a version outside this set, IVAC has referenced a module that
    # is not shipped in this bundle yet (a mid-rollout / incomplete deploy) — the
    # service uses this to tell that apart from a structural extraction failure.
    result['dispatch_versions'] = sorted(module_map.keys())

    token = _CROSS_VALIDATION_TOKEN
    jobs = []
    meta = {}

    for kind, site in (('login', login_site), ('reserve', reserve_site)):
        if not site or not site.get('secret'):
            continue
        # Prefer the version read straight off the live config object (carried on the
        # call site by the Node probe); fall back to matching (skip, enc_len) against
        # the regex-scanned config literals only when the live value is absent. The
        # live-object read is robust to config-literal key reordering by the minifier.
        version = site.get('version')
        if version is None:
            version = _version_for(site, configs)
        module = module_map.get(version) if version is not None else None
        meta[kind] = {
            'module': module,
            'version': version,
            'skip': site.get('skip'),
            'enc_len': site.get('enc_len'),
            'secret': site.get('secret'),
        }
        if module:
            jobs.append({
                'type': kind,
                'module': module,
                'token': token,
                'secret': site['secret'],
                'skip': site['skip'],
                'enc_len': site['enc_len'],
            })

    if not jobs:
        logs.append('[GT] No live modules resolved (version->module mapping failed).')
        result['encrypt_meta'] = meta or None
        result['extraction_reason'] = 'no live modules resolved (version->module mapping failed)'
        return result

    # Ground-truth outputs come from the single-boot probe (_run_bundle_once), SHARED with
    # the secret/charset resolution done in find_captcha_constants — one Node execution of
    # the ~2 MB bundle, not two. The probe runs the live encrypt module for every resolved
    # call site keyed by its var; here we pick out the two attributed (login/reserve) sites'
    # outputs. Memoized by bundle identity, so an unchanged bundle skips the boot; the
    # meta-write side effect below still runs every call so the sidecar's encrypt_meta stays
    # correct regardless of caching.
    try:
        probe = _run_bundle_once(js_code, bundle_path)
        if probe is not None:
            site_live = probe.get('live') or {}
            for kind, site in (('login', login_site), ('reserve', reserve_site)):
                var = site.get('var') if site else None
                if var is not None and var in site_live and site_live[var].get('output'):
                    result[kind] = site_live[var]['output']
            result['modules'] = probe.get('modules')
            result['bundle_hash'] = probe.get('bundle_hash')
            # Canary: each live output must be a well-formed transform of the token.
            sites = {'login': login_site, 'reserve': reserve_site}
            for kind in ('login', 'reserve'):
                st = sites[kind] or {}
                result['wellformed'][kind] = _wellformed_output(
                    token, result.get(kind), st.get('skip'), st.get('enc_len')
                )
            logs.append(
                f"[GT] Live bundle ran: login={bool(result.get('login'))}, "
                f"reserve={bool(result.get('reserve'))}, modules={len(probe.get('modules') or [])}, "
                f"wellformed={result['wellformed']}"
            )
            if probe.get('errors'):
                logs.append(f"[GT] Live errors: {json.dumps(probe['errors'])[:200]}")
        else:
            logs.append('[GT] Single-boot probe returned no output.')
    except Exception as exc:
        logs.append(f'[GT] Live execution error: {exc}')

    meta_doc = dict(meta)
    meta_doc['bundle_filename'] = os.path.basename(bundle_path) if bundle_path else None
    meta_doc['bundle_hash'] = result.get('bundle_hash')
    # Carry the live charset so the sidecar's wellformedness guard can reject a
    # transformed window with out-of-alphabet chars. Prefer the value extracted from
    # the bundle; fall back to the known base64url alphabet the canary already trusts.
    meta_doc['charset'] = extract_charset_value(js_code) or _CHARSET
    result['encrypt_meta'] = meta_doc

    # Canary: the two token types must resolve to distinct modules. The same module
    # for two different versions means attribution or dispatch is inconsistent.
    lm, lv = (meta.get('login') or {}).get('module'), (meta.get('login') or {}).get('version')
    rm, rv = (meta.get('reserve') or {}).get('module'), (meta.get('reserve') or {}).get('version')
    result['distinct_modules'] = not (lm is not None and lm == rm and lv != rv)

    # Quality gate: meta becomes the single source of truth for the sidecar, so only
    # publish it when BOTH types are fully resolved (module + secret + skip + enc_len),
    # the live module produced a WELL-FORMED output for each, and the two resolved to
    # distinct modules. Otherwise keep last-known-good.
    def _complete(kind: str) -> bool:
        m = meta.get(kind) or {}
        return (
            bool(m.get('module')) and bool(m.get('secret'))
            and m.get('skip') is not None and m.get('enc_len') is not None
            and bool(result.get(kind))
            and result['wellformed'].get(kind) is True
        )

    missing = [k for k in ('login', 'reserve') if not _complete(k)]
    if missing or not result['distinct_modules']:
        reason = f"incomplete extraction for: {', '.join(missing)}" if missing else 'login and reserve resolved to the same module'
        result['extraction_reason'] = f"{reason} (meta NOT updated, last-known-good kept)"
        logs.append(f'[GT] {result["extraction_reason"]}')
        return result

    if not write_meta:
        # Dry-run (corpus/attribution mode): extraction is clean but we must not touch
        # the live sidecar meta. Report success without persisting.
        result['extraction_ok'] = True
        logs.append('[GT] write_meta=False — clean extraction, meta NOT persisted (dry run).')
        return result

    # Atomic write so the sidecar (mtime poll) never observes a half-written meta.
    try:
        os.makedirs(os.path.dirname(ENCRYPT_META_PATH), exist_ok=True)
        tmp_path = ENCRYPT_META_PATH + '.tmp'
        with open(tmp_path, 'w', encoding='utf-8') as f:
            json.dump(meta_doc, f)
        os.replace(tmp_path, ENCRYPT_META_PATH)
        result['meta_written'] = True
        result['extraction_ok'] = True
        logs.append(f'[GT] Wrote encrypt_meta.json ({json.dumps({k: meta[k]["module"] for k in meta})}).')
    except Exception as exc:
        result['extraction_reason'] = f'failed to write encrypt_meta: {exc}'
        logs.append(f'[GT] {result["extraction_reason"]}')

    return result


def fetch_bundle_asset(proxy_url: str) -> dict:
    """Cheap redeploy probe: fetch ONLY the HTML and return the current JS bundle asset.

    Vite content-hashes the bundle filename, so the asset URL changes on every redeploy.
    This lets the scheduler detect a redeploy without downloading the ~2 MB bundle.

    Returns {bundle_asset, bundle_url, error}.
    """
    try:
        page_request = Request(WELL_KNOWN_URL, headers=NO_CACHE_HEADERS)
        with urlopen(page_request, timeout=20) as response:
            page_text = response.read().decode("utf-8", errors="ignore")
        m = re.search(r'assets/(mq[a-z0-9]+-[A-Za-z0-9_-]+\.js)', page_text)
        if not m:
            return {"error": "Could not find JS bundle URL in /.well-known/ response"}
        asset_path = f"/assets/{m.group(1)}"
        return {
            "bundle_asset": os.path.basename(asset_path),
            "bundle_url": urljoin(WELL_KNOWN_URL, asset_path),
            "error": None,
        }
    except HTTPError as e:
        return {"error": f"IVAC /.well-known/ returned HTTP {e.code}; retry during the live IVAC window"}
    except URLError as e:
        return {"error": f"IVAC bundle discovery failed: {e.reason}"}
    except Exception as e:
        return {"error": str(e)}


def main():
    if len(sys.argv) < 2:
        output = {
            "error": "Usage: python3 analyze_captcha_algo.py <proxy_url|''> [--head-only]"
        }
        print(json.dumps(output))
        return

    # Empty string means direct connection (no proxy). Useful when the server
    # itself is in Bangladesh and a proxy is not needed.
    proxy_url = sys.argv[1] if sys.argv[1] else ''
    head_only = '--head-only' in sys.argv[2:]

    # Manual recovery path: analyze a locally downloaded bundle instead of fetching
    # over the network (used when IVAC serves a notice page that hides the bundle).
    # Usage: python3 analyze_captcha_algo.py <proxy_or_dash> --bundle /path/to/bundle.js
    local_bundle_path = None
    if '--bundle' in sys.argv:
        idx = sys.argv.index('--bundle')
        if idx + 1 < len(sys.argv):
            local_bundle_path = sys.argv[idx + 1]

    # Side-effect-free corpus/attribution mode: resolve + attribute a local bundle
    # without writing the bundle dump or encrypt_meta.json. Used by the regression test.
    if '--attribute' in sys.argv:
        idx = sys.argv.index('--attribute')
        if idx + 1 >= len(sys.argv):
            print(json.dumps({'error': 'Usage: --attribute <bundle_path>'}))
            return
        print(json.dumps(attribute_only(sys.argv[idx + 1])))
        return

    if head_only:
        print(json.dumps(fetch_bundle_asset(proxy_url)))
        return

    result = analyze(proxy_url, local_bundle_path)
    print(json.dumps(result))


def attribute_only(bundle_path: str) -> dict:
    """Resolve + attribute a bundle's captcha configs WITHOUT touching live state.

    Reads a bundle from disk, resolves both config secrets via Node, attributes
    login vs reserve, and runs the live encrypt modules — but writes neither the
    bundle dump nor encrypt_meta.json. This is the side-effect-free entry the
    archived-bundle regression corpus exercises.

    Usage: python3 analyze_captcha_algo.py - --attribute /path/to/bundle.js

    Returns {login, reserve, dispatch_versions, wellformed, distinct_modules,
    extraction_ok, pending_rollout, error}. Each of login/reserve is
    {skip, enc_len, version, module, has_secret} or null.
    """
    logs: list = []
    try:
        with open(bundle_path, 'r', encoding='utf-8', errors='ignore') as fh:
            js_code = fh.read()
    except OSError as e:
        return {'error': f'Failed to read bundle: {e}'}

    constants = find_captcha_constants(js_code, bundle_path=bundle_path)
    call_sites = constants.get('call_sites', [])
    login_site, reserve_site = _attribute_for_live(call_sites)
    live = _compute_live_outputs(js_code, bundle_path, login_site, reserve_site, logs, write_meta=False)

    module_map = _extract_module_map(js_code)
    dispatch_versions = sorted(module_map.keys())

    def _describe(site: Optional[dict]) -> Optional[dict]:
        if not site:
            return None
        version = site.get('version')
        return {
            'skip': site.get('skip'),
            'enc_len': site.get('enc_len'),
            'version': version,
            'module': module_map.get(version) if version is not None else None,
            'has_secret': bool(site.get('secret')),
        }

    login_desc = _describe(login_site)
    reserve_desc = _describe(reserve_site)
    pending = []
    for kind, desc in (('login', login_desc), ('reserve', reserve_desc)):
        if desc and desc['version'] is not None and desc['module'] is None \
                and dispatch_versions and desc['version'] not in dispatch_versions:
            pending.append(kind)

    # When login and reserve share the same module/skip/enc_len (June 21 2026 bundles),
    # the ONLY thing that tells them apart is the secret. Report whether the two resolved
    # to distinct secrets so a regression that drops one type's secret (and hands it the
    # other's duplicate) is caught even when skip/enc_len/version are identical.
    login_secret = (login_site or {}).get('secret')
    reserve_secret = (reserve_site or {}).get('secret')
    secrets_distinct = (
        (login_secret != reserve_secret)
        if (login_secret and reserve_secret) else None
    )

    return {
        'bundle_path': bundle_path,
        'login': login_desc,
        'reserve': reserve_desc,
        'dispatch_versions': dispatch_versions,
        'wellformed': live.get('wellformed'),
        'distinct_modules': live.get('distinct_modules'),
        'secrets_distinct': secrets_distinct,
        'extraction_ok': live.get('extraction_ok', False),
        'pending_rollout': pending,
        'logs': logs,
        'error': None,
    }


def analyze(proxy_url: str, local_bundle_path: Optional[str] = None) -> dict:
    """Main analysis logic with debug logging."""
    logs = []

    def log(msg: str):
        """Append a debug message."""
        logs.append(msg)

    # Bundle download timing, surfaced to the monitor UI. Null on the local
    # recovery path (no network fetch happened).
    download_started_at = None
    download_duration_ms = None

    try:
        if local_bundle_path is not None:
            # Local recovery path: read the manually downloaded bundle, skip the network.
            log(f"[1/6] Reading local bundle: {local_bundle_path}")
            try:
                with open(local_bundle_path, 'r', encoding='utf-8', errors='ignore') as fh:
                    js_code = fh.read()
            except OSError as e:
                return {"error": f"Failed to read local bundle: {e}", "logs": logs}
            bundle_url = f"file://{os.path.abspath(local_bundle_path)}"
            log(f"[4/6] ✓ Bundle loaded from disk (size: {len(js_code)} bytes)")
        else:
            log("[1/6] Discovering the public bundle through /.well-known/...")
            discovery = fetch_bundle_asset(proxy_url)
            if discovery.get("error"):
                return {"error": discovery["error"], "logs": logs}
            bundle_url = discovery["bundle_url"]
            log(f"[3/6] ✓ Found public static bundle: {bundle_url}")
            log("[4/6] Downloading public static bundle...")
            download_started_at = datetime.now(timezone.utc).isoformat()
            download_start = time.perf_counter()
            with urlopen(Request(bundle_url, headers={"Accept": "application/javascript"}), timeout=120) as response:
                js_code = response.read().decode("utf-8", errors="ignore")
            download_duration_ms = round((time.perf_counter() - download_start) * 1000)
            log(f"[4/6] ✓ Bundle downloaded (size: {len(js_code)} bytes) in {download_duration_ms} ms")

        # Dump the raw bundle to a single fixed path (overwrites previous run). On the edge
        # path the source already IS that path, so this is a no-op that preserves the exact
        # bytes the sidecar staged (keeps the instant /promote hash match intact).
        dumped_bundle_path = dump_bundle(js_code, bundle_url, log, local_bundle_path)

        # Verify algorithm fingerprints.
        # LOGIN (polynomial): modulo 67 is the uniquely specific constant.
        # RESERVE (LCG + Feistel): 1103515245 is the LCG multiplier.
        log("[5/6] Verifying algorithm fingerprints...")
        has_imul = 'imul' in js_code
        has_mult = '1103515245' in js_code
        has_seed = '123456789' in js_code
        has_inc  = '12345' in js_code
        has_mod67 = ('%67' in js_code) or ('% 67' in js_code)
        charset_present = find_charset_position(js_code) >= 0
        magic_numbers_match = has_mult and has_seed  # RESERVE fingerprint (kept for compat)
        login_magic_match = has_mod67                # LOGIN fingerprint
        if magic_numbers_match:
            log(f"[5/6] ✓ RESERVE LCG signature detected (imul={has_imul}, mult={has_mult}, seed={has_seed})")
        else:
            log(f"[5/6] ✗ RESERVE LCG signature missing (mult={has_mult}, seed={has_seed}) — may have changed")
        if login_magic_match:
            log(f"[5/6] ✓ LOGIN polynomial signature detected (mod67 present, charset={charset_present})")
        else:
            log(f"[5/6] ✗ LOGIN polynomial signature missing (mod67={has_mod67}) — may have changed")

        # Try to extract OFFSET and LENGTH
        log("[6/6] Extracting OFFSET/LENGTH constants...")
        detected_offset, detected_length, js_function = extract_constants(js_code, logs)

        if detected_offset is not None and detected_length is not None:
            log(f"[6/6] ✓ Extracted: OFFSET={detected_offset}, LENGTH={detected_length}")
        else:
            log("[6/6] ⚠ Could not extract constants from minified code (magic numbers confirm algorithm intact)")

        # Find captcha token encryption code
        captcha_encryption = find_captcha_encryption_code(js_code)
        if captcha_encryption:
            # Beautify the encryption code
            captcha_encryption = beautify_extracted_function(captcha_encryption)
            log(f"[6/6] ✓ Found captcha token encryption code")
        else:
            log(f"[6/6] ⚠ Could not extract captcha encryption code")

        # Find captcha constants (search in extracted code first, then globally).
        # Pass the dumped bundle path so the single-boot probe hashes the same bytes the
        # sidecar loads, and shares its one Node execution with the ground-truth run below.
        captcha_constants = find_captcha_constants(js_code, captcha_encryption, dumped_bundle_path)
        if captcha_constants['charset']:
            log(f"[6/6] ✓ Found CAPTCHA_CHARSET ({len(captcha_constants['charset'])} chars)")
        if captcha_constants['secret']:
            log(f"[6/6] ✓ Found CAPTCHA_SECRET ({len(captcha_constants['secret'])} chars)")
        if captcha_constants['skip'] is not None:
            log(f"[6/6] ✓ Found SKIP value: {captcha_constants['skip']}")
        if captcha_constants['encrypt_len'] is not None:
            log(f"[6/6] ✓ Found ENCRYPT_LEN value: {captcha_constants['encrypt_len']}")

        # The reserve panel's OFFSET/LENGTH must come from the reserve algorithm's
        # own (skip, encrypt_len) — these are detected by anchoring on the LCG
        # constant, so they cannot be confused with the login algorithm's 4/19.
        # The generic extract_constants() above scans near the alphabet and may
        # have grabbed the login values, so reserve constants take priority here.
        if captcha_constants['skip'] is not None:
            detected_offset = captcha_constants['skip']
            log(f"[6/6] ✓ Reserve OFFSET (skip): {detected_offset}")
        if captcha_constants['encrypt_len'] is not None:
            detected_length = captcha_constants['encrypt_len']
            log(f"[6/6] ✓ Reserve LENGTH (encrypt_len): {detected_length}")

        # Split captcha_constants into reserve (parameterized) and login (polynomial)
        reserve_constants = {
            'charset': captcha_constants.get('charset'),
            'secret': captcha_constants.get('secret'),
            'skip': captcha_constants.get('skip'),
            'encrypt_len': captcha_constants.get('encrypt_len'),
        }
        login_constants = {
            'secret': captcha_constants.get('login_secret'),
            'skip': captcha_constants.get('login_skip'),    # 4 when detected
            'encrypt_len': captcha_constants.get('login_enc_len'),  # 19 when detected
        }

        # Cross-validate PHP implementation: run reference JS on a test token with extracted params.
        # PHP service compares its transformer output against these to detect wrong constants.
        log('[CV] Running PHP vs JS reference cross-validation...')
        all_call_sites = captcha_constants.get('call_sites', [])
        login_site_cv, reserve_site_cv = _attribute_for_live(all_call_sites)
        live = _compute_live_outputs(js_code, dumped_bundle_path, login_site_cv, reserve_site_cv, logs)
        impl_check = {k: live[k] for k in ('test_token', 'login', 'reserve') if k in live}

        return {
            "bundle_url": bundle_url,
            "download_started_at": download_started_at,
            "download_duration_ms": download_duration_ms,
            "magic_numbers_match": magic_numbers_match,
            "login_magic_match": login_magic_match,
            "detected_offset": detected_offset,
            "detected_length": detected_length,
            "js_function": js_function,
            "captcha_encryption": captcha_encryption,
            "captcha_constants": reserve_constants,
            "login_constants": login_constants,
            "call_sites": captcha_constants.get('call_sites', []),
            "impl_check": impl_check,
            "encrypt_meta": live.get('encrypt_meta'),
            "meta_written": live.get('meta_written', False),
            "extraction_ok": live.get('extraction_ok', False),
            "extraction_reason": live.get('extraction_reason'),
            "wellformed": live.get('wellformed'),
            "distinct_modules": live.get('distinct_modules'),
            "dispatch_versions": live.get('dispatch_versions'),
            "live_modules": live.get('modules'),
            "bundle_hash": live.get('bundle_hash'),
            "error": None,
            "logs": logs,
        }

    except Exception as e:
        log(f"[ERROR] {str(e)}")
        return {"error": str(e), "logs": logs}


def beautify_js(js_code: str) -> Optional[str]:
    """
    Attempt to beautify JS using js-beautify CLI.
    Returns beautified code or None if tool not available.
    """
    try:
        result = subprocess.run(
            ['js-beautify', '--indent-size', '2'],
            input=js_code.encode('utf-8'),
            capture_output=True,
            timeout=30
        )
        if result.returncode == 0:
            return result.stdout.decode('utf-8', errors='ignore')
    except (FileNotFoundError, subprocess.TimeoutExpired):
        pass
    return None


def beautify_extracted_function(func_code: str) -> str:
    """
    Beautify extracted function code with proper indentation and formatting.
    Works on minified code snippets.
    """
    if not func_code:
        return func_code

    # Try to use js-beautify if available
    beautified = beautify_js(func_code)
    if beautified:
        return beautified

    # Fallback: Manual formatting for minified code
    code = func_code

    # Add newlines after closing braces followed by keywords
    code = re.sub(r'\}(?=\s*(?:function|const|let|var|if|for|while|return))', '}\n', code)

    # Add newlines after semicolons (but not in strings)
    code = re.sub(r';(?=\S)', ';\n', code)

    # Add spacing around braces
    code = re.sub(r'\{(?=[^ \n])', '{ ', code)
    code = re.sub(r'([^ \n])\}', r'\1 }', code)

    # Clean up multiple newlines
    code = re.sub(r'\n\n+', '\n', code)

    # Add basic indentation for visual clarity
    lines = code.split('\n')
    formatted_lines = []
    indent_level = 0

    for line in lines:
        line = line.strip()
        if not line:
            continue

        # Adjust indent based on braces
        if line.startswith('}'):
            indent_level = max(0, indent_level - 1)

        # Add indentation
        formatted_line = '  ' * indent_level + line

        formatted_lines.append(formatted_line)

        # Count braces to adjust next line's indent
        indent_level += line.count('{') - line.count('}')

    return '\n'.join(formatted_lines)


def find_captcha_constants(js_code: str, captcha_encryption: Optional[str] = None, bundle_path: Optional[str] = None) -> dict:
    """
    Extract captcha encryption constants by executing the JS bundle in Node.js.

    The bundle assembles CHARSET (xi) and SECRET (PF) from obfuscated string arrays
    at runtime — trying to re-implement those decoders in Python is fragile.
    Instead: inject a small probe at the end of the bundle that prints the values
    as JSON, run it with Node.js (v8), and parse the output.

    Fallback: regex patterns for skip/encryptLen if Node.js extraction fails.
    """
    results = {
        'charset': None,
        'secret': None,
        'login_secret': None,
        'login_skip': None,
        'login_enc_len': None,
        'skip': None,
        'encrypt_len': None,
        'call_sites': [],
    }

    # --- Strategy 0: config-object literal detection (June 2026+ bundles) ---
    # The new compilation stores skip/encLen as literal integers inside config
    # objects: const VAR = {secret: EXPR, startAt: N, length: M, version: V}.
    # Attribution uses the 'algorithm' field set by _find_config_objects() based
    # on component-context strings. Falls back to version numbers (2=reserve,
    # 6=login, as observed in the original June 2026 bundle) and finally the
    # enc_len heuristic only when context detection yields nothing.
    config_objects = _find_config_objects(js_code)
    if config_objects:
        reserve_key = None
        login_key = None

        # Pass 1: context-string attribution (most reliable across bundle versions)
        for cfg in config_objects:
            key = (cfg['skip'], cfg['enc_len'])
            alg = cfg.get('algorithm')
            if alg == 'reserve' and reserve_key is None:
                reserve_key = key
            elif alg == 'login' and login_key is None:
                login_key = key

        # Pass 2: version-number fallback (version 2 = reserve, 6 = login)
        if reserve_key is None or login_key is None:
            by_version: dict = {}
            no_version: list = []
            for cfg in config_objects:
                v = cfg.get('version')
                key = (cfg['skip'], cfg['enc_len'])
                if v is not None:
                    by_version.setdefault(v, key)
                else:
                    no_version.append(key)

            if reserve_key is None:
                reserve_key = by_version.get(2)
            if login_key is None:
                login_key = by_version.get(6)

            # Pass 3: enc_len heuristic — last resort, unreliable when enc_lens swap
            if reserve_key is None and login_key is None:
                all_keys = list(by_version.values()) + no_version
                unique_keys = list({k for k in all_keys})
                if len(unique_keys) >= 2:
                    unique_keys.sort(key=lambda x: x[1], reverse=True)
                    reserve_key, login_key = unique_keys[0], unique_keys[1]
                elif len(unique_keys) == 1:
                    reserve_key = unique_keys[0]

        if reserve_key:
            results['skip'], results['encrypt_len'] = reserve_key
        if login_key:
            results['login_skip'], results['login_enc_len'] = login_key

    # --- Strategy 0b: algorithm-anchored structural detection ---
    # Locate each encrypt function via its shift-generator constant and read the
    # skip/encrypt_len straight from the correct function. Only overwrites if
    # Strategy 0 didn't find values.
    reserve_algo = _detect_algorithm(js_code, ['1103515245'])
    login_algo = _detect_algorithm(js_code, ['%67', '% 67'])

    if results['skip'] is None and reserve_algo['skip'] is not None:
        results['skip'] = reserve_algo['skip']
    if results['encrypt_len'] is None and reserve_algo['encrypt_len'] is not None:
        results['encrypt_len'] = reserve_algo['encrypt_len']
    if results['login_skip'] is None and login_algo['skip'] is not None:
        results['login_skip'] = login_algo['skip']
    if results['login_enc_len'] is None and login_algo['encrypt_len'] is not None:
        results['login_enc_len'] = login_algo['encrypt_len']

    # --- Strategy 1: Node.js execution (single-boot probe) ---
    node_results = _extract_constants_via_node(js_code, bundle_path)
    if node_results:
        # Don't let Node's (absent) skip/encrypt_len overwrite Strategy 0 values
        for key in ('skip', 'encrypt_len', 'login_skip', 'login_enc_len'):
            if node_results.get(key) is None:
                node_results.pop(key, None)
        results.update(node_results)

    # --- Strategy 1b: regex fallback for charset if Node didn't resolve it ---
    if results['charset'] is None:
        fallback_charset = extract_charset_value(js_code)
        if fallback_charset:
            results['charset'] = fallback_charset

    # --- Strategy 1c: regex fallback for secret if Node didn't resolve it ---
    # EZ (secret key) is assembled at runtime from obfuscated decoders — no simple
    # string literal exists in the bundle. Node.js extraction is the only reliable route.
    # Nothing to do here; leave results['secret'] as None if Node failed.

    # --- Strategy 2a: identifier-filter scan for (OFFSET, LENGTH) pair ---
    # The real production call is the ONLY site where the seed arg is a bare identifier
    # (e.g. `encrypt(token, EZ, 10, 25)`). Decoy self-tests use string literals or concat.
    # Filter: seed arg must be an identifier (letters/digits/$/_), not a keyword,
    # and that identifier must have a top-level assignment somewhere in the bundle.
    KEYWORDS = {'true', 'false', 'null', 'let', 'var', 'const', 'this', 'length', 'for', 'if'}
    if results['skip'] is None or results['encrypt_len'] is None:
        id_call_pat = re.compile(r',\s*([A-Za-z_$]\w{0,3})\s*,\s*(\d{1,2})\s*,\s*(\d{1,2})\s*\)')
        for m in id_call_pat.finditer(js_code):
            var_name = m.group(1)
            a, b = int(m.group(2)), int(m.group(3))
            if var_name in KEYWORDS:
                continue
            if a < 1 or a > 20 or b < 10 or b > 40:
                continue
            # Confirm the identifier has a top-level assignment (not just referenced)
            if re.search(r'\b' + re.escape(var_name) + r'\s*=', js_code):
                if results['skip'] is None:
                    results['skip'] = a
                if results['encrypt_len'] is None:
                    results['encrypt_len'] = b
                break

    # --- Strategy 2b: regex for skip/encryptLen near the encryption function ---
    contexts = []
    if captcha_encryption:
        contexts.append(captcha_encryption)
    contexts.append(js_code)

    if results['skip'] is None or results['encrypt_len'] is None:
        for search_context in contexts:
            # Function call: ft(token, KEY, OFFSET, LENGTH) — any two small ints
            # Matches `ft(e,PF,7,24)` / `ft(null!=e?e:"",PF,9,19)` / etc.
            param_pat = re.search(
                r'\w+\(\s*(?:[^,()]+|\([^)]*\))\s*,\s*\w+\s*,\s*(\d{1,2})\s*,\s*(\d{1,2})\s*\)',
                search_context,
            )
            if param_pat:
                skip = int(param_pat.group(1))
                enc_len = int(param_pat.group(2))
                if 1 <= skip <= 50 and 1 <= enc_len <= 50:
                    if results['skip'] is None:
                        results['skip'] = skip
                    if results['encrypt_len'] is None:
                        results['encrypt_len'] = enc_len
                    break

    return results


def _build_capture_patch(js_code: str) -> dict:
    """Patch the bundle so the charset and each call site's secret/config value land in
    globalThis, WITHOUT the DOM stub / module exposure / probe (captcha_probe.cjs adds
    those). Keeping the brace/paren-aware injection here avoids re-porting it to JS.

    Returns {source, charset_global, sites} where each site is the original call-site
    dict (var, skip, enc_len, [version], [algorithm]) augmented with:
      - 'global': the globalThis name its value is captured into
      - 'style': 'config' (module-scope config object, read as VAR.secret) |
                 'secret' (globalThis name holds the resolved secret string directly —
                  used for legacy secret vars AND method-trapped 2026-07 configs)
    """
    charset_var = _detect_charset_var(js_code)
    call_sites = _find_encrypt_call_sites(js_code)

    # Config-object style (June 2026+) carries a 'version' key; legacy call sites do not.
    using_config_style = bool(call_sites) and 'version' in call_sites[0]

    patched = js_code
    charset_global = None
    if charset_var:
        charset_global = '__charset'
        patched = _patch_var_global(patched, charset_var, charset_global)

    sites = []
    if using_config_style:
        # Re-scan on the (possibly charset-patched) string so obj offsets are current.
        scanned = _find_config_objects(patched)
        globals_by_idx = {idx: '__cfg%d_%s' % (idx, cfg['var']) for idx, cfg in enumerate(scanned)}

        # Partition configs: module-scope ones resolve via the in-place capture injected
        # after their closing brace; method-scope ones (2026-07 bundles trap the config
        # inside an obfuscated `static [expr](){...}` class method that never runs headless)
        # need their method body re-emitted at module scope under a permissive `this`.
        method_groups: dict = {}   # (body_open, body_close) -> [(idx, cfg)]
        module_scope: list = []    # [(idx, cfg)]
        for idx, cfg in enumerate(scanned):
            bo, bc = _enclosing_computed_method(patched, cfg['obj_open'])
            if bo is not None and bc is not None and bc > cfg['obj_close']:
                method_groups.setdefault((bo, bc), []).append((idx, cfg))
            else:
                module_scope.append((idx, cfg))

        # Build a re-emission trigger per method: its declarations (decoder helpers + locals)
        # followed by `globalThis.NAME=(SECRET_EXPR)` for each config it holds. Evaluating the
        # secret expression against just the declarations avoids the method's `this`-gated
        # guards (which trap the config) and the obfuscated startAt/length props (whose
        # undeclared helper params would throw). Computed from `patched` before the in-place
        # injections below shift offsets.
        triggers = []
        for (bo, bc), members in method_groups.items():
            decls = _extract_method_decls(patched[bo + 1:bc])
            captures = []
            for idx, cfg in members:
                secret_expr = _config_secret_expr(patched, cfg['obj_open'], cfg['obj_close'])
                if secret_expr is not None:
                    captures.append('globalThis.%s=(%s);' % (globals_by_idx[idx], secret_expr))
            if captures:
                triggers.append(decls + ';' + ''.join(captures))

        # In-place capture for module-scope configs only (method-scope ones are dead here).
        # Ordered by the actual injection offset (the sequence's ')' when the config is
        # comma-sequence wrapped, else its own '}') so later edits never shift earlier ones.
        for idx, cfg in sorted(
            module_scope,
            key=lambda kv: kv[1].get('assign_end') if kv[1].get('assign_end') is not None else kv[1]['obj_close'],
            reverse=True,
        ):
            patched = _inject_after_config(
                patched, cfg['obj_close'], cfg['var'], globals_by_idx[idx], cfg.get('assign_end')
            )

        # Append the trigger IIFEs just inside the bundle's outer IIFE close ('}()'), where the
        # module-scope string decoders the config secrets call are in scope.
        if triggers:
            block = ''.join(';try{(function(){' + t + '\n})();}catch(__ivacE){}' for t in triggers)
            tail = patched.rfind('}()')
            patched = (patched[:tail] + block + patched[tail:]) if tail != -1 else (patched + block)

        method_idxs = {idx for members in method_groups.values() for idx, _ in members}
        for idx, cfg in enumerate(scanned):
            site = {k: cfg.get(k) for k in ('var', 'skip', 'enc_len', 'version', 'algorithm')}
            site['global'] = globals_by_idx[idx]
            # Method-scope globals hold the resolved secret STRING; module-scope hold the
            # config OBJECT (read as .secret). The probe branches on this.
            site['style'] = 'secret' if idx in method_idxs else 'config'
            sites.append(site)
    else:
        for s in call_sites:
            gv = '__sv_' + s['var']
            patched = _patch_var_global(patched, s['var'], gv)
            site = dict(s)
            site['global'] = gv
            site['style'] = 'secret'
            sites.append(site)

    return {'source': patched, 'charset_global': charset_global, 'sites': sites}


def _run_bundle_once(js_code: str, bundle_path: Optional[str] = None) -> Optional[dict]:
    """Evaluate the bundle ONCE (via captcha_probe.cjs) and return, in a single payload,
    the resolved charset + per-call-site secrets AND each site's live ground-truth
    encrypt output.

    This is the analyzer's single most expensive step folded into ONE cold Node run: it
    replaces the two separate boots (secret probe + live-module run) that each parsed and
    executed the whole ~2 MB React bundle. Memoized by bundle identity, so an unchanged
    bundle (the normal state between IVAC redeploys) skips the boot entirely.

    Returns {charset, call_sites, live, modules, bundle_hash, errors} or None on failure:
      - call_sites: [{var, algorithm, skip, enc_len, [version], secret}] (secret-resolved only)
      - live: {var: {output: str|None, wellformed_reason: str|None}}
    """
    key = ("probe_" + ANALYSIS_CACHE_VERSION + "_" + _EXTRACTOR_FINGERPRINT
           + "_" + _bundle_identity(js_code))
    cached = _analysis_cache_load(key)
    if cached is not None:
        return cached or None

    out = _run_bundle_once_uncached(js_code, bundle_path)
    if out:
        _analysis_cache_store(key, out)
    return out


def _run_bundle_once_uncached(js_code: str, bundle_path: Optional[str] = None) -> Optional[dict]:
    """Cold single-boot bundle evaluation backing _run_bundle_once (see its docstring)."""
    import tempfile

    cap = _build_capture_patch(js_code)
    module_map = _extract_module_map(js_code)
    configs = _extract_config_versions(js_code)

    # Resolve each site's module up front (version -> module var). Config sites carry the
    # version literal; legacy sites fall back to matching (skip, enc_len) against configs.
    sites_payload = []
    for s in cap['sites']:
        version = s.get('version')
        if version is None:
            version = _version_for(s, configs)
        module = module_map.get(version) if version is not None else None
        sites_payload.append({
            'var': s['var'],
            'global': s['global'],
            'style': s['style'],
            'module': module,
            'skip': s.get('skip'),
            'enc_len': s.get('enc_len'),
        })

    tmp_path = None
    try:
        with tempfile.NamedTemporaryFile(mode='w', suffix='.js', delete=False, encoding='utf-8') as f:
            f.write(cap['source'])
            tmp_path = f.name

        payload = {
            'patched_bundle_path': tmp_path,
            'hash_bundle_path': bundle_path or BUNDLE_DUMP_PATH,
            'charset_global': cap['charset_global'],
            'token': _CROSS_VALIDATION_TOKEN,
            'sites': sites_payload,
        }

        proc = subprocess.run(
            ['node', '--max-old-space-size=512', PROBE_PATH, json.dumps(payload)],
            capture_output=True, timeout=60, text=True, encoding='utf-8', errors='replace',
        )
        if proc.returncode != 0 or not proc.stdout.strip():
            return None
        data = json.loads(proc.stdout.strip())
    except (subprocess.TimeoutExpired, FileNotFoundError, json.JSONDecodeError, OSError):
        return None
    finally:
        if tmp_path:
            try:
                os.unlink(tmp_path)
            except OSError:
                pass

    site_results = data.get('sites') or {}
    call_sites = []
    live = {}
    for s in cap['sites']:
        entry = site_results.get(s['var'])
        # Drop sites whose secret did not resolve, exactly as the previous probe did.
        if not entry or not entry.get('secret'):
            continue
        cs = {
            'var': s['var'],
            'algorithm': s.get('algorithm'),
            'skip': s.get('skip'),
            'enc_len': s.get('enc_len'),
            'secret': entry['secret'],
        }
        if s.get('version') is not None:
            cs['version'] = s['version']
        call_sites.append(cs)
        live[s['var']] = {
            'output': entry.get('output'),
            'wellformed_reason': entry.get('wellformed_reason'),
        }

    return {
        'charset': data.get('charset'),
        'call_sites': call_sites,
        'live': live,
        'modules': data.get('modules') or [],
        'bundle_hash': data.get('bundle_hash'),
        'errors': data.get('errors') or {},
    }


def _extract_constants_via_node(js_code: str, bundle_path: Optional[str] = None) -> Optional[dict]:
    """Charset + resolved call-site secrets, sourced from the single-boot probe.

    Thin adapter over _run_bundle_once so find_captcha_constants keeps its old contract
    ({charset, call_sites}) while the actual bundle execution is shared with the
    ground-truth run (_compute_live_outputs) — one Node boot, not two.
    """
    probe = _run_bundle_once(js_code, bundle_path)
    if not probe:
        return None
    out = {}
    if probe.get('charset'):
        out['charset'] = probe['charset']
    if probe.get('call_sites'):
        out['call_sites'] = probe['call_sites']
    return out or None


def _find_expr_end(code: str, start: int) -> int:
    """Find end of expression at depth 0 (stops at newline, comma, or semicolon)."""
    depth = 0
    in_str = False
    str_ch = None
    i = start
    while i < len(code):
        ch = code[i]
        if not in_str:
            if ch in ('"', "'", '`'):
                in_str = True
                str_ch = ch
            elif ch == '(':
                depth += 1
            elif ch == ')':
                depth -= 1
            elif depth == 0 and ch in (',', ';', '\n'):
                return i
        else:
            if ch == '\\':
                i += 1
            elif ch == str_ch:
                in_str = False
        i += 1
    return len(code)


def _patch_var_global(code: str, var: str, global_name: str) -> str:
    """
    Wrap the first assignment of `var` so its value also lands in
    globalThis.<global_name>:  <decl> VAR=EXPR  →  <decl> VAR=(globalThis.NAME=EXPR)
    """
    pat = re.compile(r'(?:(?:const|let|var)\s+)?\b' + re.escape(var) + r'\s*=(?!=)')
    m = pat.search(code)
    if not m:
        return code
    rhs_start = m.end()
    rhs_end = _find_expr_end(code, rhs_start)
    return (
        code[:rhs_start]
        + f'(globalThis.{global_name}='
        + code[rhs_start:rhs_end]
        + ')'
        + code[rhs_end:]
    )


# Plain-text component markers that survive minification and anchor each captcha
# config to its page. Verified across a 6-bundle corpus (2026-06): 'dateLabel' sits
# next to the reserve config in 6/6 bundles (the single most reliable signal);
# 'password' next to login in 4/6 (login falls to elimination in the other 2).
_LOGIN_CONTEXT_MARKERS = ['signIn', 'signInButton', 'password', 'Password', 'rememberMe', 'forgotPassword']
_RESERVE_CONTEXT_MARKERS = ['appointmentDate', 'dateLabel', 'reserveSlot', 'slotTime', 'visaType']
_CONTEXT_WINDOW_SIZE = 3000


def _find_config_objects(js_code: str) -> list:
    """
    Find captcha config objects introduced in the 2026-06 bundle compilation.

    The new bundle no longer calls encrypt(token, SECRET_VAR, SKIP, ENCLEN)
    directly. Instead, each call site has a config object:
        const VAR = {secret: OBFUSCATED_EXPR, startAt: N, length: M, version: V}
    passed to a loader function. The SKIP and ENCLEN are stored as literal
    integers in these config objects.

    Attribution uses component-context strings (±3000 chars) rather than
    version numbers (which change each bundle) or the enc_len heuristic
    (which broke when login enc_len exceeded reserve enc_len in June 2026).

    Returns list of {'var', 'skip', 'enc_len', 'version', 'algorithm'} — no duplicates by var.
    """
    results = []
    seen = set()
    for cfg in _scan_config_literals(js_code):
        start_at = cfg['skip']
        length = cfg['enc_len']
        version = cfg['version']
        if not (1 <= start_at <= 20 and 10 <= length <= 40):
            continue
        pos = cfg['pos']
        var_name = cfg['var']
        if var_name in seen:
            continue
        seen.add(var_name)

        # Identify algorithm by scanning component context for plain-text UI strings
        # that survive minification (i18n labels, route names). Login components
        # contain sign-in strings; reservation components contain booking strings.
        context = js_code[max(0, pos - _CONTEXT_WINDOW_SIZE):pos + _CONTEXT_WINDOW_SIZE]
        algorithm = None
        for marker in _LOGIN_CONTEXT_MARKERS:
            if marker in context:
                algorithm = 'login'
                break
        if algorithm is None:
            for marker in _RESERVE_CONTEXT_MARKERS:
                if marker in context:
                    algorithm = 'reserve'
                    break

        results.append({
            'var': var_name,
            'skip': start_at,
            'enc_len': length,
            'version': version,
            'algorithm': algorithm,
            'obj_open': cfg['obj_open'],
            'obj_close': cfg['obj_close'],
            'assign_end': cfg.get('assign_end'),
        })
    return results


def _find_statement_end(code: str, start: int) -> int:
    """
    Find the position where a JS statement ends starting at `start`.
    Tracks (), {}, [] depth so that `;` and `\\n` inside nested structures
    are ignored. Returns the position of the terminator character.
    """
    depth_p = depth_b = depth_sq = 0
    in_str = False
    str_ch = None
    i = start
    while i < len(code):
        ch = code[i]
        if in_str:
            if ch == '\\':
                i += 2
                continue
            elif ch == str_ch:
                in_str = False
        else:
            if ch in ('"', "'", '`'):
                in_str = True
                str_ch = ch
            elif ch == '(':
                depth_p += 1
            elif ch == ')':
                depth_p -= 1
            elif ch == '{':
                depth_b += 1
            elif ch == '}':
                depth_b -= 1
            elif ch == '[':
                depth_sq += 1
            elif ch == ']':
                depth_sq -= 1
            elif depth_p == 0 and depth_b == 0 and depth_sq == 0 and ch in (';', '\n'):
                return i
        i += 1
    return len(code)


def _inject_after_config(code: str, obj_close: int, var: str, global_name: str,
                         assign_end: Optional[int] = None) -> str:
    """Inject `globalThis.NAME = VAR;` right after the config object that closes at obj_close.

    Position-based (obj_close comes from _scan_config_literals) because the config vars are
    now non-unique single letters in multi-declarator statements — searching for a unique
    `const VAR={` no longer works. VAR is in scope immediately after its own declaration, so
    referencing it by name at this position is correct. Handles a trailing ',' (more
    declarators) by scanning to the true statement end so a `,OTHER=...` tail isn't orphaned.

    When the config is wrapped in a comma-sequence (`VAR=(decoy(),...,{cfg})`), `assign_end`
    carries the sequence's closing `)` and the injection goes AFTER it: at the object's own
    brace the assignment has not completed yet, so referencing VAR there would hit the
    temporal dead zone of its own `const`.
    """
    i = obj_close if assign_end is None else assign_end
    j = i + 1
    while j < len(code) and code[j] in (' ', '\t', '\n', '\r'):
        j += 1
    injection = f'\nglobalThis.{global_name}={var};'
    if j < len(code) and code[j] == ',':
        stmt_end = _find_statement_end(code, j + 1)
        return code[:stmt_end] + injection + code[stmt_end:]
    return code[:i + 1] + injection + code[i + 1:]


def _find_encrypt_call_sites(js_code: str) -> list:
    """
    Find captcha encrypt call sites, trying the new config-object pattern first
    (introduced June 2026) then falling back to the old direct-call pattern.

    New pattern: const VAR = {secret: EXPR, startAt: N, length: M, version: V}
    Old pattern: encrypt(tokenExpr, SECRET_VAR, SKIP, ENCLEN)

    Returns a de-duplicated list of {'var', 'skip', 'enc_len'} by var name.
    """
    # New primary strategy: config objects with literal startAt/length integers
    config_sites = _find_config_objects(js_code)
    if config_sites:
        return config_sites

    # Legacy fallback: bare-identifier call pattern (pre-June-2026 bundles)
    sites = {}
    pat = re.compile(r',\s*([A-Za-z_$]\w{0,5})\s*,\s*(\d{1,2})\s*,\s*(\d{1,2})\s*\)')
    for m in pat.finditer(js_code):
        var = m.group(1)
        skip, enc_len = int(m.group(2)), int(m.group(3))
        if var in _ALGO_KEYWORDS:
            continue
        if not (1 <= skip <= 20 and 10 <= enc_len <= 40):
            continue
        if not _has_top_level_assignment(js_code, var):
            continue
        if var not in sites:
            sites[var] = {'var': var, 'skip': skip, 'enc_len': enc_len}
    return list(sites.values())


_ALGO_KEYWORDS = {'true', 'false', 'null', 'this', 'length', 'for', 'if', 'of',
                  'in', 'do', 'new', 'typeof', 'let', 'var', 'const', 'return',
                  'function', 'void', 'while', 'else'}


def _has_top_level_assignment(js_code: str, var: str) -> bool:
    """True if `var` is assigned at module scope (const/let/var VAR=...)."""
    return bool(re.search(r'(?:const|let|var)\s+' + re.escape(var) + r'\s*=(?!=)', js_code))


def _find_enclosing_func_name(js_code: str, anchor_pos: int, search_back: int = 3000) -> Optional[str]:
    """
    Find the name of the nearest function defined before anchor_pos.
    Handles both `function NAME(` and `const/let/var NAME=function|arrow`.
    """
    window = js_code[max(0, anchor_pos - search_back):anchor_pos]
    name = None
    pat = re.compile(
        r'function\s+([A-Za-z_$]\w{0,9})\s*\('
        r'|(?:const|let|var)\s+([A-Za-z_$]\w{0,9})\s*=\s*(?:function|\([^)]*\)\s*=>|[A-Za-z_$]\w*\s*=>)'
    )
    for m in pat.finditer(window):
        name = m.group(1) or m.group(2)
    return name


def _extract_param_list(fn_code: str) -> str:
    """Return the raw parameter list (inside the first balanced parentheses)."""
    p = fn_code.find('(')
    if p < 0:
        return ''
    depth = 0
    i = p
    while i < len(fn_code):
        c = fn_code[i]
        if c == '(':
            depth += 1
        elif c == ')':
            depth -= 1
            if depth == 0:
                return fn_code[p + 1:i]
        i += 1
    return ''


def _detect_algorithm(js_code: str, anchors: list) -> dict:
    """
    Detect one captcha algorithm's constants by anchoring on a constant that lives
    inside its shift-generator function (e.g. '%67' for login, '1103515245' for
    reserve). Locates the encrypt function that calls that shift function and
    extracts its secret variable name, skip, and encrypt_len.

    Three structural patterns are handled:
      - Secret passed directly: shifts(SECRET_CONST, ...)  (login, old style)
      - Secret as a defaulted param: encrypt(token, key=SECRET_CONST, skip=2, enc=23)
        and shifts(key)  (reserve, old style)
      - Secret as a plain param (no default): encrypt(token, key, skip, enc)
        with the real values in an external config object (June 2026+ style).
        In this case skip/encrypt_len are sourced from config objects found by
        _find_config_objects() rather than the function signature.

    Returns {'secret_var': str|None, 'skip': int|None, 'encrypt_len': int|None}.
    """
    out = {'secret_var': None, 'skip': None, 'encrypt_len': None}

    pos = -1
    for a in anchors:
        pos = js_code.find(a)
        if pos >= 0:
            break
    if pos < 0:
        return out

    shifts_name = _find_enclosing_func_name(js_code, pos)
    if not shifts_name:
        return out

    call_pat = re.compile(r'\b' + re.escape(shifts_name) + r'\s*\(\s*([A-Za-z_$]\w{0,9})')
    for m in call_pat.finditer(js_code):
        arg = m.group(1)
        if arg in _ALGO_KEYWORDS:
            continue

        encrypt_fn = extract_function_containing(js_code, m.start(), search_back=4000)
        if not encrypt_fn or ('indexOf' not in encrypt_fn and 'slice' not in encrypt_fn):
            continue  # the shift call must sit inside the encrypt function

        params = _extract_param_list(encrypt_fn)

        # Secret resolution:
        #  - If the shift-call arg is itself a parameter with a default value
        #    (reserve old style: shifts(key) where key=SECRET is a defaulted param),
        #    resolve the default to get the real secret variable.
        #  - If it's passed directly with a top-level assignment (login old style).
        #  - June 2026+: the secret is a plain param (no default) — no static var
        #    can be resolved here; Node.js extraction handles it via config objects.
        is_param = re.search(r'(?:^|,)\s*' + re.escape(arg) + r'\s*(?:=|,|\))', params)
        if is_param:
            dm = re.search(re.escape(arg) + r'\s*=\s*([A-Za-z_$]\w*)', params)
            if dm and dm.group(1) not in _ALGO_KEYWORDS:
                out['secret_var'] = dm.group(1)
            # Plain param without default — skip/encLen come from config objects;
            # set secret_var to a sentinel so we break out of the loop.
            elif is_param and not dm:
                out['secret_var'] = None  # not resolvable statically
        elif _has_top_level_assignment(js_code, arg):
            out['secret_var'] = arg

        # skip / encrypt_len: body literals (min(SKIP,..) / min(ENC,..)) first,
        # then fall back to numeric defaults in the signature.
        nums = re.findall(r'min\(\s*(\d{1,2})\s*,', encrypt_fn)
        if len(nums) >= 2:
            out['skip'], out['encrypt_len'] = int(nums[0]), int(nums[1])
        else:
            dnums = re.findall(r'=\s*(\d{1,2})\b', params)
            if len(dnums) >= 2:
                out['skip'], out['encrypt_len'] = int(dnums[0]), int(dnums[1])

        # If we confirmed this IS the right encrypt function (has indexOf/slice),
        # stop even if we couldn't resolve the secret statically.
        break

    return out


def _detect_login_secret_var(js_code: str) -> Optional[str]:
    """Detect the login secret variable (polynomial % 67 algorithm)."""
    return _detect_algorithm(js_code, ['%67', '% 67'])['secret_var']


def _detect_secret_var(js_code: str) -> Optional[str]:
    """Detect the reserve secret variable (LCG + Feistel algorithm)."""
    return _detect_algorithm(js_code, ['1103515245'])['secret_var']


def _detect_charset_var(js_code: str) -> Optional[str]:
    """
    Detect the charset variable name. The charset is the variable accessed as
    VAR[ENCODED+"h"] (i.e. VAR.length) to get the alphabet modulus used by the
    LCG generator. Find the access site nearest to the LCG signature (1103515245)
    — multiple unrelated variables also use the [X+"h"] (.length) pattern.
    """
    # The bundle may contain multiple LCG occurrences (a decoy + the real q0).
    # The real one is paired with the charset modulus (% N0), so prefer the
    # LCG site whose nearest .length-access var is also a long concat chain.
    lcg_positions = [m.start() for m in re.finditer(r'1103515245', js_code)]
    pat = re.compile(r'=\s*([A-Za-z_$]\w{0,4})\[[^\]]{1,80}\+"h"\]')

    # Whether a var's FIRST assignment is a long concat chain (the charset is built
    # by concatenating many substrings). Equivalent to searching the whole bundle for
    # (^|[\s,;{(])(const|let|var)?\s*VAR=([^;\n]{40,800}) and counting '+' >= 5, but
    # cheap: scan for the literal "VAR=" then validate the delimiter/decl prefix and
    # the ;/newline-bounded RHS locally. Memoized per var. A full re.search per var is
    # a ~25ms backtracking pass over the 2 MB bundle; this replaces ~10 of them (the
    # single biggest chunk of the analyzer's Python-side cost).
    left_re = re.compile(r'(?:^|[\s,;{(])(?:const|let|var)?\s*$')
    qual_cache: dict = {}

    def is_concat_chain(var: str) -> bool:
        if var in qual_cache:
            return qual_cache[var]
        result = False
        for m in re.finditer(re.escape(var) + r'\s*=(?!=)', js_code):
            if not left_re.search(js_code[max(0, m.start() - 16):m.start()]):
                continue
            rhs = js_code[m.end():m.end() + 800]
            cut = len(rhs)
            for i, ch in enumerate(rhs):
                if ch == ';' or ch == '\n':
                    cut = i
                    break
            rhs = rhs[:cut]
            if len(rhs) < 40:
                continue
            result = rhs.count('+') >= 5
            break
        qual_cache[var] = result
        return result

    accesses = []
    for m in pat.finditer(js_code):
        var = m.group(1)
        if is_concat_chain(var):
            accesses.append((m.start(), var))
    if not accesses or not lcg_positions:
        return None
    # For each LCG site, find the nearest qualifying access; pick the closest pair overall.
    best = None
    for lcg in lcg_positions:
        for pos, var in accesses:
            d = abs(pos - lcg)
            if best is None or d < best[0]:
                best = (d, var)
    return best[1] if best else None


def find_charset_position(js_code: str) -> int:
    """
    Find the position of the 64-char captcha charset in the JS bundle.

    Strategy:
      1. Exact match on the canonical base64url alphabet (fastest).
      2. Regex for any 64-char quoted string using base64url characters.
      3. Regex for any 64-char quoted string that is a permutation containing
         all 10 digits, all 26 lowercase, and all 26 uppercase letters
         (covers reordered/shuffled 64-char alphabets).

    Returns -1 if nothing plausible is found.
    """
    canonical = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_'
    pos = js_code.find(canonical)
    if pos >= 0:
        return pos

    # Any 64-char base64url-compatible string
    charset_pat = re.compile(r'["\']([0-9a-zA-Z\-_]{64})["\']')
    for m in charset_pat.finditer(js_code):
        candidate = m.group(1)
        # Require it to contain all digits, all lowercase, all uppercase
        # — this filters out coincidental 64-char strings.
        if (
            all(c in candidate for c in '0123456789')
            and all(c in candidate for c in 'abcdefghijklmnopqrstuvwxyz')
            and all(c in candidate for c in 'ABCDEFGHIJKLMNOPQRSTUVWXYZ')
        ):
            return m.start()

    return -1


def extract_charset_value(js_code: str) -> Optional[str]:
    """
    Extract the 64-char charset VALUE (not just position) from the bundle.

    Used as a fallback when the Node.js probe cannot resolve `xi` at runtime —
    e.g., when the charset lives in a plain string literal that survives
    minification.
    """
    canonical = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_'
    if canonical in js_code:
        return canonical

    charset_pat = re.compile(r'["\']([0-9a-zA-Z\-_]{64})["\']')
    for m in charset_pat.finditer(js_code):
        candidate = m.group(1)
        if (
            all(c in candidate for c in '0123456789')
            and all(c in candidate for c in 'abcdefghijklmnopqrstuvwxyz')
            and all(c in candidate for c in 'ABCDEFGHIJKLMNOPQRSTUVWXYZ')
        ):
            return candidate

    return None


def extract_function_containing(js_code: str, anchor_pos: int, search_back: int = 8000) -> Optional[str]:
    """
    Walk backward from anchor_pos to find the nearest enclosing function
    (or arrow function / const assignment), then extract it with brace matching.
    """
    start = max(0, anchor_pos - search_back)
    before = js_code[start:anchor_pos]

    # Find the LAST function/arrow/const definition before anchor
    func_pat = re.compile(
        r'(?:'
        r'function\s+\w+\s*\('          # function name(
        r'|function\s*\('               # function(
        r'|(?:const|let|var)\s+\w+\s*=\s*(?:function|\([^)]*\)\s*=>|\w+\s*=>)'  # const x = function / arrow
        r')'
    )
    last_match = None
    for m in func_pat.finditer(before):
        last_match = m

    if last_match is None:
        return None

    func_start = start + last_match.start()

    # Brace-match from func_start to find the complete function body
    brace_depth = 0
    in_string = False
    string_char = None
    i = func_start

    while i < min(len(js_code), anchor_pos + 10000):
        ch = js_code[i]

        # Handle string tracking (avoid counting braces inside strings)
        if not in_string and ch in ('"', "'", '`'):
            in_string = True
            string_char = ch
        elif in_string:
            if ch == '\\':
                i += 2
                continue
            elif ch == string_char:
                in_string = False

        if not in_string:
            if ch == '{':
                brace_depth += 1
            elif ch == '}':
                brace_depth -= 1
                if brace_depth == 0 and i > func_start + 100:
                    return js_code[func_start:i + 1]

        i += 1

    return None


def find_captcha_encryption_code(js_code: str) -> Optional[str]:
    """
    Find the Turnstile captcha token encryption function (ft / encryptCaptchaToken).

    The encryption function is uniquely identified by containing the 64-char
    base64url charset: "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_"

    Strategy:
    1. Locate the charset string in the bundle (this is INSIDE the encryption function).
    2. Walk backward to find the enclosing function definition.
    3. Brace-match to extract the complete function body.
    4. Fallback: widen the search window and try again.
    5. Final fallback: look near magic numbers for the hash helper.
    """
    charset_pos = find_charset_position(js_code)

    if charset_pos >= 0:
        # Primary: charset anchors us directly inside the encryption function
        code = extract_function_containing(js_code, charset_pos, search_back=8000)
        if code and len(code) > 100:
            return code

        # If the charset is at file level (not inside a function), look for the
        # closest function that CALLS or REFERENCES it
        # Search forward/backward up to 3000 chars for a function boundary
        window_start = max(0, charset_pos - 500)
        window_end = min(len(js_code), charset_pos + 500)
        window = js_code[window_start:window_end]

        # Try to find a brace-balanced function within this window
        for m in re.finditer(r'(?:function\s*\w*\s*\(|=>\s*\{|\w+\s*=\s*function)', window):
            candidate_start = window_start + m.start()
            code = extract_function_containing(js_code, candidate_start + 10, search_back=100)
            if code and len(code) > 200:
                return code

    # Fallback: look for functions that use indexOf + slice (string manipulation of the token)
    # The encryption function always does charset.indexOf(ch) and token.slice(skip, ...)
    slice_indexOf_pat = re.compile(
        r'(?:function\s+\w+|const\s+\w+\s*=\s*(?:function|\([^)]*\)\s*=>))\s*[^{]*\{[^}]*'
        r'(?:indexOf|slice|split)[^}]{200,}(?:indexOf|slice|join)[^}]{50,}\}',
        re.DOTALL
    )
    for m in slice_indexOf_pat.finditer(js_code):
        code = m.group(0)
        if len(code) > 300 and len(code) < 8000:
            return code

    return None


def extract_mix_function(js_code: str) -> Optional[str]:
    """
    Extract the shift/hash function code from JS (~2000 chars around the anchor).

    Anchors on the LCG signature (Math.imul + constant 1103515245) for the
    current algorithm, falling back to the 64-char charset when the LCG
    pattern is not detectable in minified output.
    """
    pos = -1
    # Primary anchor: find Math.imul near 1103515245 (LCG multiplier) — that's B0()
    imul_pos = js_code.find('Math.imul')
    if imul_pos >= 0:
        window = js_code[imul_pos:imul_pos + 400]
        if '1103515245' in window or '12345' in window:
            pos = imul_pos

    if pos < 0:
        pos = find_charset_position(js_code)

    if pos < 0:
        return None

    # Extract a generous window around the magic number
    # Go back far enough to catch the function definition
    search_back = 3000
    search_forward = 3000

    func_start = max(0, pos - search_back)
    func_end = min(len(js_code), pos + search_forward)

    # Extract the raw section
    section = js_code[func_start:func_end]

    # Try to find better boundaries by looking for common function markers
    # Look backward for function keyword or assignment that might be the start
    better_start = 0
    for match in re.finditer(r'(?:function\s+\w+|const\s+\w+\s*=|\w+\s*=\s*function|^[^{}]*function)', section):
        # Use the last match before our position (closest to magic number)
        if match.start() < len(section) // 2:
            better_start = match.start()

    # Try to find a better end by looking for complete statement boundaries
    # Look for closing brace followed by comma, semicolon, or newline
    better_end = len(section)
    brace_count = 0
    in_string = False
    string_char = None

    for i in range(len(section)):
        char = section[i]

        # Handle strings
        if char in ('"', "'", '`') and (i == 0 or section[i-1] != '\\'):
            if not in_string:
                in_string = True
                string_char = char
            elif char == string_char:
                in_string = False

        if not in_string:
            if char == '{':
                brace_count += 1
            elif char == '}':
                brace_count -= 1
                # If braces are balanced and we're past the magic number area, could be end
                if brace_count == 0 and i > (pos - func_start) + 100:
                    # Look ahead for comma or semicolon
                    lookahead = section[i:i+50]
                    if any(c in lookahead for c in [',', ';', '}']):
                        better_end = i + 1
                        break

    # Extract the final code snippet
    func_code = section[better_start:better_end].strip()

    # Limit to ~2000 chars max for display, but keep it generous
    if len(func_code) > 2000:
        # Try to find a natural break point
        truncated = func_code[:2000]
        # Look for the last closing brace or semicolon
        for end_char in ['}', ';']:
            last_idx = truncated.rfind(end_char)
            if last_idx > 1000:
                func_code = func_code[:last_idx + 1]
                break
        else:
            func_code = truncated + "..."

    return func_code if func_code and len(func_code) > 50 else None


def extract_from_beautified(js_code: str) -> tuple[Optional[int], Optional[int], Optional[str]]:
    """Extract OFFSET and LENGTH from beautified code."""
    offset = None
    length = None

    # Look for OFFSET = number or OFFSET: number (case insensitive)
    offset_pattern = r'(?:OFFSET|offset)\s*[:=]\s*(\d{1,2})(?:[,;\s\)])'
    offset_match = re.search(offset_pattern, js_code)
    if offset_match:
        offset = int(offset_match.group(1))

    # Look for LENGTH = number or LENGTH: number
    length_pattern = r'(?:LENGTH|length)\s*[:=]\s*(\d{1,2})(?:[,;\s\)])'
    length_match = re.search(length_pattern, js_code)
    if length_match:
        length = int(length_match.group(1))

    # If both found, validate and extract function
    if offset is not None and length is not None:
        if 1 <= offset <= 50 and 1 <= length <= 50:
            js_func = extract_mix_function(js_code)
            return offset, length, js_func

    # Fallback: look for pairs of 2-digit numbers near alphabet
    alphabet = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_"
    alphabet_pos = js_code.find(alphabet)
    if alphabet_pos > 0:
        window_start = max(0, alphabet_pos - 2000)
        window_end = min(len(js_code), alphabet_pos + 2000)
        window = js_code[window_start:window_end]

        pattern = r'\b(\d{1,2})\b.*?\b(\d{1,2})\b'
        matches = list(re.finditer(pattern, window))
        if len(matches) >= 2:
            for i in range(len(matches) - 1):
                first = int(matches[i].group(1))
                second = int(matches[i + 1].group(1))
                if 1 <= first <= 50 and 1 <= second <= 50 and first != second:
                    js_func = extract_mix_function(js_code)
                    return first, second, js_func

    return None, None, None


def extract_constants(js_code: str, logs: list = None) -> tuple[Optional[int], Optional[int], Optional[str]]:
    """
    Extract OFFSET and LENGTH constants from JS (minified or beautified).
    Also extract JS function code even if constants aren't found.

    Strategy A: Beautify code first, then search for variable declarations
    Strategy B: Find alphabet, search nearby for various patterns (minified/obfuscated)
    Strategy C: Always try to extract the algorithm function from the code
    """
    if logs is None:
        logs = []

    offset = None
    length = None
    js_func = None

    # Try beautified version first (best chance for extraction)
    beautified = beautify_js(js_code)
    if beautified:
        offset, length, js_func = extract_from_beautified(beautified)
        if offset is not None and length is not None:
            if js_func:
                logs.append(f"  → Strategy: beautified code analysis")
            return offset, length, js_func

    # Strategy B: near-alphabet search on minified code
    alphabet = "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ-_"
    alphabet_pos = js_code.find(alphabet)

    if alphabet_pos > 0:
        # Search within ±4000 chars around alphabet
        window_start = max(0, alphabet_pos - 4000)
        window_end = min(len(js_code), alphabet_pos + 4000)
        window = js_code[window_start:window_end]

        # Pattern 1: function call with 4 args, last 2 are small ints
        pattern = r'\w+\(\w+,\s*\w+,\s*(\d{1,2}),\s*(\d{1,2})\)'
        match = re.search(pattern, window)
        if match:
            offset, length = int(match.group(1)), int(match.group(2))
            if 1 <= offset <= 50 and 1 <= length <= 50:
                js_func = extract_mix_function(js_code)
                if js_func:
                    js_func = beautify_extracted_function(js_func)
                logs.append(f"  → Strategy: 4-arg function pattern")
                return offset, length, js_func

        # Pattern 2: consecutive calls/assignments with two-digit numbers
        pattern2 = r'[,\(=](\d{1,2})[,\);\s].*?[,\(=](\d{1,2})[,\);\s]'
        match2 = re.search(pattern2, window)
        if match2:
            offset, length = int(match2.group(1)), int(match2.group(2))
            if 1 <= offset <= 50 and 1 <= length <= 50 and offset != length:
                js_func = extract_mix_function(js_code)
                if js_func:
                    js_func = beautify_extracted_function(js_func)
                logs.append(f"  → Strategy: consecutive number pattern")
                return offset, length, js_func

        # Pattern 3: look for other small number pairs near alphabet
        numbers = re.findall(r'\b(\d{1,2})\b', window)
        if len(numbers) >= 2:
            candidates = [int(n) for n in numbers if 1 <= int(n) <= 50]
            if len(candidates) >= 2:
                for i in range(len(candidates)):
                    for j in range(i + 1, len(candidates)):
                        if candidates[i] != candidates[j]:
                            js_func = extract_mix_function(js_code)
                            if js_func:
                                js_func = beautify_extracted_function(js_func)
                            logs.append(f"  → Strategy: number pair near alphabet")
                            return candidates[i], candidates[j], js_func

    # Strategy C: Even if constants not found, try to extract the algorithm function
    # This helps show the actual code structure even when values can't be determined
    if not js_func:
        js_func = extract_mix_function(js_code)
        if js_func:
            # Beautify the extracted function for better readability
            js_func = beautify_extracted_function(js_func)
            logs.append(f"  → Strategy: extracted algorithm function (constants not found)")
            return offset, length, js_func

    # Could not extract anything
    return None, None, None


if __name__ == "__main__":
    main()
