# PRD Phase 1：单本漫画 WebP 两阶段压缩（成果物放 compress_work，起步最小集）

- 版本：v1.0
- 日期：2026-08-22
- 所属分支：`feature/compress-review-rebuild-202608`（HEAD 分叉自 develop 8cd7952，排除 PR#10 压缩审核大功能）
- 目标用户：仅开发者 / 维护者（CLI 触发，无 Web UI 入口；对比审核工具留 Phase 2+）
- 依赖现有实现 / 延续既有风格：
  - 单页转码原子实现 [image_compression.py](file:///z:/ehLib/ehlib/utils/image_compression.py) 的 `_write_bytes_atomic` / `save_page_bytes` / `_IMAGE_EXTENSIONS`
  - 下载器即时钩子 [downloader.py](file:///z:/ehLib/ehlib/core/downloader.py#L475)（保持 0 改动，不破坏既有单页行为）
  - DB 迁移与 galleries 表 [database.py](file:///z:/ehLib/ehlib/models/database.py)（`user_version` 机制保留，本阶段不接入）
  - 参数风格参照其他 CLI：`argparse` + `logging` + 退出码约定（≥4 档，0/1/2/3）

---

## 1. 背景、目标、非目标

### 1.1 背景
- 2026-08-17 PRD（[2026-08-17-webp-compression-markup-design.md](file:///z:/ehLib/docs/superpowers/specs/2026-08-17-webp-compression-markup-design.md)）设计过大（6 列 DB 状态机 + 两阶段用户确认 + 书籍级/页级并发池 + 托盘进度合并 + 5 处 UI 改动 + 10+ CLI 参数），导致实际落地时「步子过大」：PHP Warning 混进 JS 字面量、`document.createElement` monkey-patch 与 bootstrap prototype setter 冲突、compare.js IIFE 因 SyntaxError 永不执行等多类故障。
- 用户要求：**小步重来**。Phase 1 先只做「单本漫画的压缩机制」，**把成果物暂存在 compress_work 目录**，不碰原图目录，后续再做 compare.php 审核器。

### 1.2 Phase 1 目标（最小集）
1. **单本入口**：提供 CLI，能通过 gallery_id 或 (source, source_id) 从 DB 取一本已下载完成的漫画，跑两阶段压缩（第一阶段只写候选 webp，不碰原图、不删任何原图文件）。
2. **成果物集中**：所有候选 webp 平铺写入 `data/downloads/compress_work/<gallery_id>/`，该目录仅作为暂存区（不做人工审核，后续由对比工具消费）。
3. **DB 记录最小状态机**：起步 2 列新增（`compression_status` / `compression_info`），幂等迁移（兼容 PR#10 残留列），状态只做 5 档起步。
4. **零回归 downloader 钩子**：`ImageCompressor.save_page_bytes` 签名 / 返回值契约 100% 不变，downloader 4 处调用点 0 改动，即时压缩行为与目前完全一致。
5. **跑失败可诊断**：`compress_work/<id>/summary.json` + DB `compression_info` 两份 JSON 字段**字节级一致**（留 1 个公共构建函数构造，避免写两份时字段漂移），失败页 / 节省率 / 原图总大小 / webp 总大小 全部可审计。

### 1.3 Phase 1 非目标（YAGNI，明确不做）
1. ❌ **任何 Web UI / PHP API**：`recompress_gallery` / `compression_user_review` / 图库卡片 chip / 阅读器 Review Bar / 工具面板按钮 — 全部留 Phase 2。
2. ❌ **批量 CLI（--all / 并发多本书）**：起步只做「单本一次 1 本」串行；书籍级 Semaphore / 页级 asyncio Semaphore 留 Phase 1.5 或 Phase 2。
3. ❌ **剩余 4 列 DB**：`compression_level / compression_disabled_reason / compression_attempts / compression_candidate_at` 4 列留 Phase 2 再加。
4. ❌ **确认 / 拒绝 / 重压用户动作闭环**：compare.php 审核器、`apply_user_confirm_delete_originals_keep_webp`、`revert_gallery_to_original` 全部留 Phase 2。
5. ❌ **压缩级别 preset（minimum/medium/aggressive）**：起步 CLI 允许 `--quality / --method / --min-savings` 三参数覆盖，但**不写 gallery.compression_level**（这列 Phase 1 还没加），只影响本次运行；preset 映射 + DB level 列留 Phase 2。
6. ❌ **画质 poor_quality 自动判定 & disabled 状态**：起步状态枚举不含这两档，超阈值仍先写 `user_review_required`，对比工具再人工判。

---

## 2. compress_work 目录布局（§1 design · 用户确认版）

```
data/downloads/           (原有下载根目录，不改)
└── compress_work/        (新增，根路径，Phase 1 新建)
    └── <gallery_id>/     (整数命名，gallery 行 id，来源于 galleries.id 主键)
        ├── 0001.webp     (每页候选 webp；不满足 min_savings 的页就没有同名 .webp 文件)
        ├── 0002.webp
        ├── ...
        └── summary.json  (书级汇总 + 页级 stats，字段见 §4.5)
```

### 2.1 三条硬规则（用户 2026-08-22 确认 §1 OK）
1. **根路径 = `data/downloads/compress_work/`**（和下载主目录平级，用户文件管理器里一起管理）。
2. **单本子目录 = `<gallery_id>/` 纯整数命名**，如 `compress_work/12345/`（不接受 source/source-id 字符串、不接受路径 hash；因此 CLI 必须先查 DB）。
3. **内部文件平铺（不分子目录）**：只剩两类文件 — 每页 `.webp`（若 used_webp=true 才存在）+ 1 个 `summary.json`；所有 `.part`、临时锁、日志一律写完即删，跑完 work 目录**不得残留第三类文件**。

### 2.2 路径原子性
- 写 `.webp` 必须走现有的 `ImageCompressor._write_bytes_atomic()`（`.part` → `replace`）。
- 写 `summary.json` 同样包一层 `_write_bytes_atomic`，避免中途进程被杀时 JSON 是半写的半截文件。

---

## 3. DB 迁移（起步 2 列 · 幂等 · 兼容 PR#10 残留）

### 3.1 新增的两列（galleries 表，Phase 1 仅这两列）

| 列名 | 类型 | 默认值 | 用途（Phase 1 起步 5 态） |
|---|---|---|---|
| `compression_status` | `TEXT NOT NULL DEFAULT ''` | `''` | 空串=''（未处理/老数据）/ `queued` / `compressing` / `user_review_required` / `failed`；禁用 / poor_quality 等 Phase 2 再加 |
| `compression_info` | `TEXT NOT NULL DEFAULT ''` | `''` | 存 JSON，字段与 `compress_work/<id>/summary.json` 的书级汇总部分**字节级一致**（公共构建函数保证） |

### 3.2 幂等迁移（用户提醒：DB 是 git ignore，PR#10 可能残留老列）
Python 函数 `ehlib.models.database.ensure_gallery_compression_columns_v1()`：

```python
import sqlite3

def ensure_gallery_compression_columns_v1(conn: sqlite3.Connection) -> None:
    # 1. PRAGMA 拿现有列名，兼容 PR#10 残留
    cur = conn.execute("PRAGMA table_info(galleries)")
    existing = {row[1] for row in cur.fetchall()}   # col_name = row[1]

    # 2. 两条独立 ALTER（SQLite 仅支持单列 ADD COLUMN），列存在就跳
    if "compression_status" not in existing:
        conn.execute(
            "ALTER TABLE galleries ADD COLUMN compression_status TEXT NOT NULL DEFAULT ''"
        )
    if "compression_info" not in existing:
        conn.execute(
            "ALTER TABLE galleries ADD COLUMN compression_info TEXT NOT NULL DEFAULT ''"
        )
    conn.commit()

    # 3. Phase 1 不建索引（起步单本调用无性能问题）
    # idx_gals_comp_status_dl_at 等复合索引留批量 CLI Phase 再加
```

### 3.3 迁移触发时机
- **CLI 入口启动第 0.5 步**：`cmd_compress.py` 的 `main()` 在解析 args 之后、定位 gallery 之前，调一次 `ensure_gallery_compression_columns_v1(db_conn)`。
- **不走 `user_version` / 不走 PHP SCHEMA_VER**：避免和现有 downloads_dir / galleries 其他迁移的 `user_version` 递增冲突；Phase 2 再加列时再换 `ensure_gallery_compression_columns_v2()`。

### 3.4 老 PR#10 残留值兼容
- 若 `compression_status` 列在 PR#10 时期就已存在，且里面有 Phase 1 不认的 8 态老值（如 `disabled / poor_quality / compressed`）：
  - Phase 1 的 `UPDATE ... WHERE status IN ('','queued')` 原子抢锁 SQL **不会命中这些行**，因此它们不会被改；
  - 等 Phase 2 再加对应状态机时再处理。

---

## 4. 核心：ImageCompressor 扩展（§3 design · 用户确认版）

### 4.0 文件与改动边界
- 所有改动**都写在现有 [image_compression.py](file:///z:/ehLib/ehlib/utils/image_compression.py)**，不新建 GalleryCompressor 独立类（起步不过度设计）。
- `save_page_bytes` 现有签名 / 返回值 / 错误处理 **100% 不变**，downloader 4 处调用点 0 改动。

### 4.1 拆私有子函数 `_encode_one_page()`（把转码与写文件解耦）

从 127 行 `save_page_bytes` 中抽出**只做转码判断、不写磁盘**的公共子函数：

```python
def _encode_one_page(
    self,
    data: bytes,
    *,
    quality: int | None = None,        # None = 用 self.quality
    method: int | None = None,         # None = 用 self.method
    min_savings_percent: float | None = None,  # None = 用 self.min_savings_percent
) -> tuple[bool, bytes | None, dict]:
    """
    Returns: (used_webp, webp_bytes_or_None, stats_dict)
    - used_webp=True  → webp_bytes 非空，且 savings ≥ min_savings
    - used_webp=False → webp_bytes=None，stats 里说明原因 (no_savings / not_available / animated / already_webp / exception)
    - stats_dict keys:
        used_webp: bool
        no_savings: bool                (True = 经过编码但 savings 不够)
        poor_ratio: bool                (True = savings_pct < 0.50, 给 Phase 2 poor_quality 判据预占位)
        orig_bytes: int
        webp_bytes: int                 (0 if not used)
        savings_pct: float              (0.0 if not used)
        src_format: str                 (WEBP/JPEG/PNG/GIF/UNKNOWN)
        has_alpha: bool
        is_animated: bool
        exception: str | None           (仅 Pillow encode 失败时非空，用于 summary 记录失败页原因)
    """
```

### 4.2 `save_page_bytes()` 重构：内部调用子函数，保持对外契约不变

```python
def save_page_bytes(self, data: bytes, path: Path) -> Path:
    # 原前置：enabled / Pillow import / webp feature check —— 全部保留原样
    # 原 try/except / logger / _warn_once —— 全部保留原样
    #
    # 原来手写的 Image.open → exif_transpose → convert RGB/RGBA → save encoded → min_savings 判断
    #  → 替换为：
    #      used, webp_bytes, _stats = self._encode_one_page(  # 不传任何 override，用 self 实例属性
    #          data,
    #          quality=None, method=None, min_savings_percent=None,
    #      )
    # 然后：
    #   used=True  → _write_bytes_atomic(path.with_suffix('.webp'), webp_bytes)
    #   used=False → _write_bytes_atomic(path, data)
    #
    # 返回值 Path 与现在完全一致（write_candidate_only 等新参数 Phase 1 完全不加）
    ...
```

**核心原则**：downloader 现在 4 处 `save_page_bytes(data, path)` 调用在这个重构后**跑一遍 pytest 或实际下载任何一本**，输出的文件字节与之前一致。

### 4.3 新增公共方法 `compress_gallery_to_workdir()`（两阶段第一阶段核心）

```python
from datetime import datetime, timedelta, timezone

def compress_gallery_to_workdir(
    self,
    gallery_id: int,
    gallery_dir: Path,
    work_root: Path,                     # = Path('data/downloads/compress_work')
    *,
    # 起步允许 CLI 的 --quality/--method/--min-savings 覆盖（Phase 1 不写 DB）
    quality_override: int | None = None,
    method_override: int | None = None,
    min_savings_override: float | None = None,
    force: bool = False,                 # False: 若 summary.json 已存在 → 直接 skip
    db_conn=None,                        # 传进来的 sqlite3.Connection，用来做原子状态机
) -> tuple[str, dict]:
    """
    Returns: (final_status, info_dict)
      final_status ∈ { 'user_review_required', 'failed', 'skipped' }
      info_dict = 最终写进 summary.json 且与 DB compression_info JSON 完全一致的那份 dict

    流程：
      1. work_dir = work_root / str(gallery_id)
         若 summary.json 存在 and not force → 直接 return ('skipped', {reason:'workdir_exists'})
         若 force → 清空 work_dir（shutil.rmtree → mkdir）
      2. 原子 DB 抢锁：
           UPDATE galleries
              SET compression_status='compressing', updated_at_iso = ?
            WHERE id=? AND compression_status IN ('', 'queued')
         若 rowCount != 1 → return ('skipped', {reason:'lock_not_acquired'})  **不做任何 CPU 工作**
      3. 扫描 gallery_dir.glob('*')：只处理 *.jpg / *.jpeg / *.png（按 _IMAGE_EXTENSIONS 过滤；*.gif / *.webp 直接 skip 不重转）
         若 0 张匹配 → 不写任何东西，status 回 '', return ('skipped', {reason:'no_pages_found'})
      4. 逐页（串行，Phase 1 起步不并发）：
           读 bytes → _encode_one_page(*overrides) → 若 used_webp=True: _write_bytes_atomic(work_dir/f'{stem}.webp', webp_bytes)
           单页整段 try/except + **自动重试 1 次**（2026-08-22 用户选择 §5 增强项②：抵抗偶发 Pillow 读损坏 / 磁盘 I/O 毛刺）
           捕获到 2 次仍失败 → page_stats['exception']=str(exc)，记入 failed_pages，不影响其他页
      5. 构建公共 info_dict = _build_compression_info_json(pages_stats_list, gallery_id, work_dir, gallery_dir, started_at, finished_at)
      6. 写 work_dir/summary.json：_write_bytes_atomic(summary_path, json.dumps(info_dict, ensure_ascii=False, indent=2).encode('utf-8'))
      7. 写 DB：
           ok = failed_count / total < 0.10   (失败页 < 10%)
           UPDATE galleries
              SET compression_status = ?,
                  compression_info = ?,
                  updated_at_iso = ?
            WHERE id = ?
         ok → status='user_review_required'；否则 → status='failed'
      8. return (final_status, info_dict)
    """
```

### 4.4 起步崩溃恢复 SQL（2026-08-22 用户 §5 增强项①，在 CLI 第 0 步执行）

在 `compress_gallery_to_workdir` 跑之前（或者 CLI 解析完 args 就先跑），先做一次全局崩溃清理：

```python
def cli_crash_recovery_cleanup(db_conn) -> int:
    """
    起步版：把 compressing 状态且 updated_at_iso > 1h 前的本子一律复位回 ''（下次可再抢锁）。
    —— 兼容性兜底（SELF-REVIEW 2026-08-22 补）：
       PR#9 之前的 galleries 表可能还没有 updated_at_iso 列（PRAGMA table_info 查不到），
       此时直接跳过 UPDATE，返回 0，避免 SQL: no such column: updated_at_iso。
       这类老 schema 用户可以手动 --force 重跑卡死的本子。
    """
    cols = {row[1] for row in db_conn.execute("PRAGMA table_info(galleries)").fetchall()}
    if "updated_at_iso" not in cols:
        return 0
    one_hour_ago_iso = (datetime.now(timezone.utc) - timedelta(hours=1)).isoformat(timespec='seconds')
    try:
        cur = db_conn.execute(
            """
            UPDATE galleries
               SET compression_status = ''
             WHERE compression_status = 'compressing'
               AND updated_at_iso < ?
            """,
            (one_hour_ago_iso,),
        )
        db_conn.commit()
        return cur.rowcount   # 返回复位了多少本，方便 CLI 日志打出来
    except sqlite3.OperationalError:
        return 0
```

> 为什么 `updated_at_iso` 用文本比较？SQLite `DATETIME DEFAULT CURRENT_TIMESTAMP` 出来的 UTC ISO 字符串，按字典序比较大小是正确的（YYYY-MM-DDTHH:MM:SS），Phase 1 不用导入 date 函数，直接文本不等式查询。

### 4.5 `summary.json` & DB `compression_info` JSON（公共构造函数构建 → 字节级一致）

**关键设计**：只写一份 dict 构造函数 `_build_compression_info_json()`，先返回 dict 给 `compress_gallery_to_workdir`；写 work 目录时 `json.dumps(..., indent=2, ensure_ascii=False)` 写 summary.json；写 DB 时**用完全相同的 `json.dumps(...)` 再序列化一遍同一份 dict 存 TEXT** — 保证 Phase 1 验收第 8 条「字节级一致校验」能通过（后续对比工具读 summary，前端读 DB 不会字段对不上）。

JSON 字段（Phase 1 起步，字段多但每项都能在一次压缩内拿到，不需要额外列）：

```jsonc
{
  // --- 书级元信息 ---
  "schema_version": 1,
  "gallery_id": 12345,
  "gallery_dir": "data/downloads/exhentai__2727811-6b53092a08",   // 绝对或相对都行，但建议统一相对 data/
  "work_dir": "data/downloads/compress_work/12345",
  "tool": "pillow-webp-phase1",

  // --- 实际跑的编码参数（便于复盘为什么这本书省得多/少）---
  "quality": 88,
  "method": 4,
  "min_savings_percent": 5.0,
  "override_used": false,   // true=CLI 传了 --quality/--method/--min-savings 中至少 1 个

  // --- 总览 ---
  "total_pages": 180,
  "used_webp_count": 173,   // 真正满足 min_savings 且写了 .webp 文件的页数
  "failed_pages_count": 2,
  "skipped_pages_count": 5, // 原本就是 .webp / .gif（跳过不重转）

  "orig_bytes_total": 209715200,
  "webp_bytes_total": 146800640,
  "savings_pct_overall": 30.0,   // 1 - webp/orig，或 0.0 如果 orig=0

  "failed_pages": [           // 只有失败过的页才在这里，页不多不会爆 JSON
    { "name": "0099.jpg", "reason": "Pillow OSError: broken data stream" },
  ],

  "work_dir_ok": true,        // 磁盘 write 失败 ≥3 页且占比 <10% → false，提醒人工看磁盘
  "started_at": "2026-08-22T12:00:00+00:00",
  "finished_at": "2026-08-22T12:03:10+00:00",

  // --- 页级详情（只有 summary.json 里才带这个大数组；DB 里 phase1 存一份同样的也可，后续 UI 读得到）---
  "pages": [
    {
      "name": "0001.jpg",
      "orig_bytes": 1200000,
      "webp_bytes": 420000,
      "savings_pct": 65.0,
      "used_webp": true,
      "src_format": "JPEG",
      "has_alpha": false,
      "is_animated": false,
      "no_savings": false,
      "poor_ratio": false,     // 预占位：savings_pct < 0.50 = true
      "exception": null
    },
    // ... 180 条
  ]
}
```

---

## 5. CLI 入口设计（§4 + 用户 §4 两项增强）

### 5.1 执行方式 / 文件位置
```bash
python -m ehlib.cmd_compress <args>     # ehlib/cmd_compress.py（新建）
```

### 5.2 argparse 结构（严格互斥定位 gallery，不接受纯目录）

```python
parser = argparse.ArgumentParser(prog="cmd_compress", description="Phase 1: 单本两阶段压缩候选 → compress_work/<id>/")

# === 互斥组：三种定位 gallery 的方式（必须选且仅选 1 种）===
grp = parser.add_mutually_exclusive_group(required=True)
grp.add_argument("--gallery-id", type=int, help="直接按 galleries.id 主键定位")
grp.add_argument("--source", type=str, help="配合 --source-id 按复合唯一键定位")

# --source-id 只在 --source 也给了时才 required，argparse 里手动 enforce（避免它和 --gallery-id 一起传也 OK）
parser.add_argument("--source-id", type=str, default=None, help="配合 --source 使用")

# === 工作目录与全局参数 ===
parser.add_argument("--work-root", type=str, default="data/downloads/compress_work", help="compress_work 根路径")
parser.add_argument("--dry-run", action="store_true", help="只读 DB + 扫描目录，打印摘要；不读图片字节 / 不写 webp / 不改 DB")
parser.add_argument("--force", action="store_true", help="work 目录存在时清空重跑；不加则直接 skip 退出码 4")

# === Phase 1 起步增强（用户 §4 多选加回）：仅影响本次运行，不写 DB ===
parser.add_argument("--quality", type=int, default=None, help="覆盖 ImageCompressor.quality (1..100)")
parser.add_argument("--method", type=int, default=None, help="覆盖 ImageCompressor.method (0..6)")
parser.add_argument("--min-savings", type=float, default=None, help="覆盖 ImageCompressor.min_savings_percent (0..100)")

# === 日志级别 ===
parser.add_argument("--log-level", choices=("DEBUG", "INFO", "WARNING", "ERROR"), default="INFO", help="Python logging 级别")
```

### 5.3 退出码约定

| 退出码 | 含义 | 对应场景 |
|---|---|---|
| 0 | 成功 | final_status ∈ {user_review_required, skipped(lock_not_acquired/workdir_exists/no_pages_found 三类 skip)} |
| 1 | 参数错误 | argparse 报错 / --source 未传 --source-id / --gallery-id 与 --source 同时传 / quality 越界 0..100 等非法参数 |
| 2 | gallery 未找到 / 路径不可用 | DB 查不到该行 / gallery_dir 不存在或不是目录 / 0 张可读图片 |
| 3 | 压缩失败 | final_status='failed'（失败页占比 ≥10%） |
| 4 | workdir_exists 且用户没给 --force | 纯提示用，和 0 的区别是 shell 脚本可以区分「真跑成功」和「没跑就跳」 |

### 5.4 main() 执行顺序
1. 配置 logging（级别按 `--log-level`）。
2. 解析 args → `enforce --source ↔ --source-id` 成对；校验 `--quality/method/min-savings` 范围，越界退出码 1。
3. 取 DB Connection，调用 `ensure_gallery_compression_columns_v1(db_conn)`（幂等迁移 2 列）。
4. 调用 `cli_crash_recovery_cleanup(db_conn)`（复位 1h 前遗留 compressing）→ 日志打「复位 X 本卡死的压缩任务」。
5. 定位 gallery：
   - `--gallery-id N` → `SELECT id, source, source_id, gallery_dir, file_path, is_complete FROM galleries WHERE id=N`
   - `--source S --source-id I` → 同查 `WHERE source=? AND source_id=?`
   - 没查到 → 中文错误退出码 2。
   - `is_complete != 1` → 警告「这本没下载完成，压缩可能只跑一部分」但**不强行阻止**（用户想压半本可以试）。
   - 解析 `gallery_dir` Path（优先读 `gallery_dir` 列，fallback `file_path` 列，二取一；两列都空 → 退出码 2）。
6. `--dry-run`：
   - 打印 4 行：`gallery_id / source+source_id / gallery_dir / 找到多少张 jpg/png/gif/webp + 总大小` → `sys.exit(0)`；磁盘/DB **0 改动**。
7. 实例化 `ImageCompressor(...)`（默认从 config 取 quality/method/min_savings；再用 `--quality/method/min-savings` 覆盖实例属性，让 `_encode_one_page` None override 时生效）。
8. 调 `compress_gallery_to_workdir(gallery_id, gallery_dir, work_root, force=args.force, db_conn=db_conn, ...overrides)`。
9. 读返回 `(final_status, info)`：
   - final_status ∈ {user_review_required, failed} → 日志打总览（总页数 / used_webp_count / savings_pct_overall / work_dir_path）。
   - skipped(workdir_exists 且无 --force) → 打印提示 → 退出码 4。
   - 其他 skipped → 退出码 0。
10. 按退出码约定 `sys.exit(N)`。

---

## 6. 错误处理 & 崩溃保护（§5 + 两项起步增强）

### 6.1 单页级
- **每页整段 try/except**：读 bytes → `_encode_one_page` → `_write_bytes_atomic(work_path)` 三步整包一次 try/except；
- **异常自动重试 1 次**（2026-08-22 增强②）：首次 exc → `time.sleep(0.05)` 再完整重跑三步；第二次仍 exc → 记 `page_stats.exception=repr(exc)`，把 name+reason 追加到全书 `failed_pages[]`，**绝不中断其他页**。

### 6.2 全书 success/fail 判定
- 失败页比例 `len(failed_pages) / len(total_matched_jpg_png) >= 0.10` → final_status = `'failed'`（退出码 3）；
- 否则 → `'user_review_required'`（退出码 0），即使有 1~2 张坏页也先当候选暂存（对比工具 Phase 2 再过滤）。

### 6.3 磁盘写失败兜底
- `_write_bytes_atomic` 抛 `OSError`（磁盘满 / 权限 / 路径含非法字符）→ 归到单页 exception；
- 若该类失败 ≥3 起且全书最终仍 user_review_required（占比 <10%）→ info_dict 里 `work_dir_ok=false` 标记，提醒人工检查磁盘；
- 磁盘满会触发 Pillow encode 阶段也炸，所有 `OSError PermissionError IsADirectoryError` 全部归同一种。

### 6.4 gallery_dir 不存在 / 0 张可读图片
- 不写 work 目录、**不写 DB**（避免把原有空串或 queued 状态改写失败）；打印中文错误「请确认漫画已下载完成且路径可读」→ 退出码 2。

### 6.5 起步崩溃恢复
- 如 §4.4，启动第 0.5 步先复位 1h 前遗留 compressing；
- **起步不做「压缩 attempts 计数器自动 disabled」**（compression_attempts 列 Phase 1 还没建），避免和老 spec 混；手动清理靠 `--force`。

---

## 7. Phase 1 验收清单（6+1+1=8 条）

按顺序跑，全部通过则 Phase 1 单本压缩机制视为完成。

### 7.1 基础 6 条（§6 用户确认版）
1. **T1 参数互斥检测**：`python -m ehlib.cmd_compress --gallery-id 1 --source exhentai --source-id 'x/y'` → argparse 报错 `argument --source: not allowed with argument --gallery-id`，退出码 **1**。
2. **T2 gallery 未找到**：`python -m ehlib.cmd_compress --gallery-id 99999999` → 中文错误「找不到 galleries.id=99999999」+ 退出码 **2**；DB `compression_status/info` 全库无新增值，`compress_work/` 无新子目录。
3. **T3 dry-run 零改动**：对一本真实存在且已下载的书跑 `--dry-run` → stdout 打印 4 行摘要；退出码 **0**；查它的 DB status 列仍是原值，`compress_work/<id>/` 不存在。
4. **T4 正常单本压缩**：`python -m ehlib.cmd_compress --source exhentai --source-id '<真实ID>'` → 退出码 **0**；① `compress_work/<id>/` 存在，`[0-9]{4}.webp` 文件数 = info_dict.used_webp_count 且全部>0 字节，目录里只剩 webp + summary.json 两类；② DB 查对应行 → `compression_status = 'user_review_required'` 且 `compression_info != ''` 且 JSON 能 parse，orig_bytes/webp_bytes/savings_pct_overall 三字段非 0。
5. **T5 重复跑无 --force 自动 skip**：再跑和 T4 同一条命令（不加 --force）→ 打印「work 目录已存在，加 --force 重跑」；退出码 **4**；`summary.json` 的 `finished_at` 没变化，DB compression_info 字节完全一致。
6. **T6 崩溃恢复 + --force 覆盖**：手动 `UPDATE galleries SET compression_status='compressing', updated_at_iso='2026-08-21T00:00:00+00:00' WHERE id=<真实ID>` → 再跑同一条不加 --force → 日志先打印「复位 1 本卡死的压缩任务」→ 抢锁成功 → 跑完 user_review_required；新 summary.json 的 `finished_at > 2026-08-21T00:00:00`。

### 7.2 用户追加两项增强验收
7. **T7 CLI override 参数生效**：`--quality 78 --method 6 --min-savings 10` 跑一本小书 → `summary.json` 内 `quality=78, method=6, min_savings_percent=10.0, override_used=true` 且实际 webp 文件大小比默认 medium(88/4/5) 时明显更小。
8. **T8 summary.json ↔ DB compression_info JSON 字节级一致校验**（§6 第 8 条）：跑完任何一本，取 `compress_work/<id>/summary.json` 文本 A，取 DB `SELECT compression_info FROM galleries WHERE id=<id>` 文本 B；`json.loads(A)` 和 `json.loads(B)` 递归比较完全相等（或直接文本相等，取决于公共构造函数是否严格同一 `json.dumps` 参数）→ **断言 true**。

---

## 8. 文件改动清单（实施 Phase 1 时逐项对）

| # | 类型 | 文件 | 改动 / 新增 | 核心职责 |
|---|---|---|---|---|
| 1 | 改 | `ehlib/utils/image_compression.py` | **重写约 200 行 / 新增约 260 行** | 拆 `_encode_one_page()` + 保持 `save_page_bytes()` 契约不变 + 新增 `compress_gallery_to_workdir()` + 新增 `_build_compression_info_json()` 公共序列化 |
| 2 | 改 | `ehlib/models/database.py` | 新增 ~35 行 `ensure_gallery_compression_columns_v1(conn)` 函数 + 导出 | 幂等 2 列 ADD COLUMN；**不碰现有 user_version / 其他迁移** |
| 3 | 新增 | `ehlib/cmd_compress.py` | 新建 ~220 行 CLI 入口（§5） | argparse 互斥组 / dry-run / force / 3 overrides / log-level / 崩溃清理 SQL 入口 / 退出码 0/1/2/3/4 |
| 4 | 新目录 | `data/downloads/compress_work/` | Phase 1 跑完时自动创建（不需要在仓库里 mkdir，CLI 用 Path.mkdir(parents=True)） | 候选 webp 暂存区；**这个目录整体加入 `.gitignore`（如果还没加）** |

### 8.1 `.gitignore` 追加 1 行（必须）
```
data/downloads/compress_work/
```
（避免后续有人误把 work 区候选 webp 几十 MB 推进 git）

---

## 9. 范围外（留给后续 Phase）

| 待办 | Phase |
|---|---|
| 批量 CLI（--all）+ 书籍级/页级并发池 | Phase 1.5 |
| galleries 加 `compression_level` + preset 映射（minimum/medium/aggressive） | Phase 2 |
| galleries 加 `compression_disabled_reason / attempts / candidate_at` 三列 + 禁用态 + 3 次失败自保护 | Phase 2 |
| `apply_user_confirm_delete_originals_keep_webp()` + `revert_gallery_to_original()` 确认/拒绝动作 | Phase 2 |
| `web/compare.php` + `compare.js` 并排对比审核器 + 3 态质量 chip + DSSIM/Sobel 最差页判定 | Phase 3 |
| 图库卡片/阅读器/工具面板 5 处 UI 入口 | Phase 4 |
| 底栏托盘下载/压缩进度合并显示 | Phase 4 |
