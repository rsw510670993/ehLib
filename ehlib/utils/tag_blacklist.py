"""Tag Blacklist 匹配工具（与 api.php cache_search L1504-L1541 的正则逻辑一一对应，保证 Python 爬虫入库同步时与 PHP 前端浏览判定完全一致）

三种排除模式（来自 tag_blacklist.tag_type）：
1. tag_type = artist / group / author / '' / '*' -> 精确作者/社团排除
   - sc.artist 多值列匹配（preg 分隔符 [\\s,;&|\\/]+）
   - sc.group_name 同逻辑
   - 同时进入 * 兜底 strict 列
2. tag_type = female/male/mixed/location/parody/character/other... -> 内容标签模糊
   - 在 sc.tags / sc.tags_cn（JSON 数组）里匹配："{ 'type': TAG_TYPE, 'name'~TOKEN }" 两种字段顺序
3. tag_type = '' / '*' -> strict 匹配兜底
   - 在 sc.tags/tags_cn/sc.artist 三列整列 strict_token 都命中即排除

设计说明：
- Python 端只在「爬虫/同步入库后 1 条新条目」时调用，不会全表扫（全表扫留给前端交互时的 optional 按钮）
"""
from __future__ import annotations

import re
from dataclasses import dataclass
from typing import Iterable

_NON_CONTENT_TYPES = ("artist", "group", "cosplayer", "language")


def _has_ascii(s: str) -> bool:
    return bool(re.search(r"[A-Za-z0-9]", s or ""))


def _regexp_safe(s: str) -> str:
    special = ('\\', '^', '$', '.', '[', ']', '|', '(', ')', '?', '*', '+', '{', '}', '/')
    out = []
    for c in (s or ""):
        out.append("\\" + c if c in special else c)
    return "".join(out)


def _strict_token_safe(needle: str) -> str:
    """与 PHP _strict_token_safe 等价：ASCII token 加词边界，非 ASCII（日文/中文）子串匹配即可"""
    s = (needle or "").strip()
    safe = _regexp_safe(s)
    if not safe:
        return ""
    if _has_ascii(s):
        return rf"(?<![A-Za-z0-9_\-]){safe}(?![A-Za-z0-9_\-])"
    return safe


def _pattern_artist_col(needle: str) -> str:
    """与 PHP _pattern_artist_col 等价：artist/group_name 多值分隔列"""
    s = (needle or "").strip()
    if not s:
        return "^$"
    # 日文假名/汉字 token 直接子串匹配（不强制左右边界），与 PHP _strict_token_safe 的非 ASCII 分支保持一致
    if not _has_ascii(s):
        return _regexp_safe(s)
    safe = _regexp_safe(s)
    sep = r"(?:^|[\s,;&|\/]+)"
    return sep + safe + r"(?=[\s,;&|\/]+|$)"


def _pattern_tag_type_content(tag_type: str, needle: str) -> str:
    """与 PHP L1521-L1529 等价：内容类 tag 在 JSON {...} 里 type 与 name 命中的两种字段顺序"""
    s = (needle or "").strip()
    if not s:
        return "^$"
    safe_tag_type = _regexp_safe(tag_type or "")
    ns = _strict_token_safe(s)
    type_lit = r'"type"\s*:\s*"' + safe_tag_type + '"'
    name_hit = r'"(?:name|name_cn)"\s*:\s*"[^"]*' + ns + r'[^"]*"'
    return (
        r"\{"
        r"(?:"
        r"[^{}]{0,320}" + type_lit + r"[^{}]{0,320}" + name_hit +
        r"|"
        r"[^{}]{0,320}" + name_hit + r"[^{}]{0,320}" + type_lit +
        r")"
        r"[^{}]{0,320}"
        r"\}"
    )


def _merge_or_patterns(patterns: Iterable[str]) -> str:
    """与 PHP _merge_or_patterns 等价：过滤空串后 (?:A|B|C) OR 合并，空则返回 ''"""
    arr = [p for p in patterns if p and p.strip()]
    if not arr:
        return ""
    if len(arr) == 1:
        return arr[0]
    return "(?:" + "|".join(arr) + ")"


@dataclass
class CompiledBlacklist:
    """编译后的 tag_blacklist（所有规则合并成 ≤3 条 preg / 1 次 strict_token 集 IN 判定）"""
    artist_re: re.Pattern | None = None
    content_re: re.Pattern | None = None
    any_re: re.Pattern | None = None
    # 作者精确命中（用 set 先做 O(1) 短路，不用先跑 regex）
    artist_exact: set[str] | None = None
    group_exact: set[str] | None = None

    @property
    def is_empty(self) -> bool:
        return (
            self.artist_re is None
            and self.content_re is None
            and self.any_re is None
            and not self.artist_exact
            and not self.group_exact
        )


def compile_blacklist(bl_rows: list[dict] | list[tuple]) -> CompiledBlacklist:
    """
    bl_rows = [ {'tag_type':..,'tag_value':..}, ... ] 或 [ (tag_type, tag_value), ... ]
    与 PHP L1507-L1541 完全对应：
      artist/group/author/* 进 artist_tokens
      其它 tag_type 进 content_patterns（按 _pattern_tag_type_content 做 JSON 匹配）
      '' / '*' 同时进 any_patterns（strict_token 全列兜底）
    """
    artist_tokens: list[str] = []
    content_patterns: list[str] = []
    any_patterns: list[str] = []
    artist_exact: set[str] = set()
    group_exact: set[str] = set()

    for r in bl_rows:
        if isinstance(r, dict):
            tv = (r.get("tag_value") or "").strip()
            tt = (r.get("tag_type") or "").strip()
        else:
            t0, t1 = r
            tv = (t1 or "").strip()
            tt = (t0 or "").strip()
        if not tv:
            continue
        if tt in ("", "*"):
            # 兜底 + artist 都算
            artist_tokens.append(tv)
            artist_exact.add(tv.lower())
            safe = _strict_token_safe(tv)
            if safe:
                any_patterns.append(safe)
        elif tt in ("artist", "group", "author"):
            artist_tokens.append(tv)
            if tt == "group":
                group_exact.add(tv.lower())
            else:
                artist_exact.add(tv.lower())
        else:
            p = _pattern_tag_type_content(tt, tv)
            if p and p != "^$":
                content_patterns.append(p)

    artist_re_str = _merge_or_patterns([_pattern_artist_col(a) for a in artist_tokens])
    content_re_str = _merge_or_patterns(content_patterns)
    any_re_str = _merge_or_patterns(any_patterns)

    return CompiledBlacklist(
        artist_re=re.compile(artist_re_str, re.UNICODE | re.IGNORECASE) if artist_re_str else None,
        content_re=re.compile(content_re_str, re.UNICODE | re.IGNORECASE) if content_re_str else None,
        any_re=re.compile(any_re_str, re.UNICODE | re.IGNORECASE) if any_re_str else None,
        artist_exact=(artist_exact or None),
        group_exact=(group_exact or None),
    )


def entry_matches_blacklist(
    cb: CompiledBlacklist,
    *,
    title: str = "",
    title_jp: str = "",
    artist: str = "",
    group_name: str = "",
    tags: str = "",
    tags_cn: str = "",
) -> tuple[bool, str]:
    """
    判断 1 条漫画条目是否命中 tag_blacklist 任意规则。
    返回 (命中?, 原因描述)；未命中=(False,'').
    与 PHP cache_search L1713-L1715 / L1778-L1780 的三路命中顺序一致。
    """
    if cb.is_empty:
        return False, ""

    artist_s = (artist or "").strip()
    group_s = (group_name or "").strip()
    tags_s = (tags or "")
    tags_cn_s = (tags_cn or "")

    # [短路 1] artist/group exact set（比 regex 快 O(1)）
    if cb.artist_exact and artist_s and artist_s.lower() in cb.artist_exact:
        return True, f"artist exact match: {artist_s}"
    if cb.group_exact and group_s and group_s.lower() in cb.group_exact:
        return True, f"group exact match: {group_s}"
    # 含 ,/; 多值时的模糊命中（'A, B' 里 artist_tokens 任一个子分隔命中）
    if cb.artist_re:
        if artist_s and cb.artist_re.search(artist_s):
            return True, f"artist regex hit"
        if group_s and cb.artist_re.search(group_s):
            return True, f"group regex hit"

    # [短路 2] 内容标签 fuzzy（tags / tags_cn JSON 全文 regex）
    if cb.content_re:
        if tags_s and cb.content_re.search(tags_s):
            return True, "content tags fuzzy hit"
        if tags_cn_s and cb.content_re.search(tags_cn_s):
            return True, "content tags_cn fuzzy hit"

    # [短路 3] * 通配 strict_token 兜底（tags / tags_cn / artist 三列任一 strict 命中）
    if cb.any_re:
        for col in (tags_s, tags_cn_s, artist_s):
            if col and cb.any_re.search(col):
                return True, "any strict_token hit"

    return False, ""
