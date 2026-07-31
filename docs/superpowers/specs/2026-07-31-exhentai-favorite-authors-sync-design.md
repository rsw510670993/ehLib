# ExHentai 收藏夹作者同步到定期刷新对象 设计 Spec

- Date: 2026-07-31
- Status: Draft (pending user review)
- Target branch: `feature/exhentai-favorite-authors-sync-202607`

## 1. 背景 / 目标

用户当前已经在 ehLib 里维护了"定期刷新对象"与"保存检索条件"的命名一致性。下一步希望基于自己的 **ExHentai 远程收藏夹** 自动生成作者级的刷新对象，避免手动逐个添加。

## 2. 需求（已确认）

1. 使用现有 exhentai Cookie（`ipb_member_id / ipb_pass_hash / sk / ...`）**直接访问 `favorites.php`**。
2. **只访问 favcat ∈ {0, 1, 9} 三个分组**，其他分组不访问。
3. 不创建下载任务，也不下载图片。
4. 由于列表页拿不到完整作者列表，**允许进入画廊详情页**，把**同一本书的所有作者都抓出来**。
5. 最终产物：**按作者（artist）去重**后，为每位作者生成一条"定期刷新对象"（`refresh_targets`），命名与已保存检索条件汉化命名一致。
6. 强调低并发、抗风控、访问频率透明、摘要统计详细。

## 3. 非目标（故意不做）

- 不下载画廊。
- 不批量进入 favcat=2~8 的分组页面。
- 不向 `search_cache` / `galleries` 写入业务数据（只做同步专用表）。
- 不做收藏夹双向写回（只"读远程"，不调用"加/删收藏"接口）。
- 不实现 nhentai 侧实现（本 Spec 仅 ExHentai）。

## 4. 总体数据流

```
favorites.php?favcat=c&page=p
        │   （每页约 25 条，解析 gid/token/added/note...）
        ▼
  FavoriteListItem[]  ───►  remote_favorites（收藏快照表，仅审计/增量）
        │
        │ （每个 gid/token 访问一次画廊详情页，强节流）
        ▼
  [Gallery detail tags]  ──► 解析 artist:xxx, artist:yyy, ...
        │
        ▼
  按作者聚合（union across 0/1/9，不按分组拆分）
        │
        ▼
  refresh_targets.origin_kind='favorite_artist'：
    - query      = artist:"<name>$"
    - name       = 已保存预设名优先；否则"作者:汉化名"
    - enabled=1（默认开启）
    - 若已存在则只 UPDATE 增补来源 favcat 集合，不覆盖用户手动改的名字
```

## 5. 访问策略（抗风控重点）

Session 直接复用现有 [session_manager.py](file:///z:/ehLib/ehlib/core/session_manager.py)。
全局使用现有配置的 `delay_between_requests`，并叠加**详情页级额外节流**：

| 阶段 | 请求 | 节流策略 |
| --- | --- | --- |
| 收藏列表 | `favorites.php?favcat=c&page=p` | 2 x `delay_between_requests` + 1.0~2.0s 抖动 |
| 画廊详情 | `/g/<gid>/<token>/` | 3 x `delay_between_requests` + 2.0~5.0s 抖动 |
| 每 20 本详情 | 批次间 | 额外等待 30~60s 随机 |

如果遇到 403/503 + Cloudflare 关键词：**立即终止本次同步**，不在流程内重试死循环。

## 6. Cookie 校验

不新增配置字段。读取现有 [config.yaml](file:///z:/ehLib/config.yaml) `cookies.exhentai`：

- `ipb_member_id` 空 / `ipb_pass_hash` 空 → **直接报错**退出，摘要里写 `errors=["cookies_missing"]`。
- 如果带了 `sk / star / hath_perks / igneous`，原样注入 Cookie jar，确保收藏夹访问的"已登录态"和用户浏览器一致。

## 7. 数据库变更

### 7.1 新表：`remote_favorites`（收藏快照）

```sql
CREATE TABLE IF NOT EXISTS remote_favorites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    source TEXT NOT NULL DEFAULT 'exhentai',
    source_id TEXT NOT NULL,
    favcat INTEGER NOT NULL,
    title_en TEXT DEFAULT '',
    title_jp TEXT DEFAULT '',
    artists_json TEXT DEFAULT '',   -- JSON array of strings, 保持多作者
    category TEXT DEFAULT '',
    added_at TEXT DEFAULT '',
    note TEXT DEFAULT '',
    thumbnail_url TEXT DEFAULT '',
    synced_at TEXT NOT NULL,
    is_removed INTEGER NOT NULL DEFAULT 0,
    UNIQUE(source, source_id, favcat)
);
CREATE INDEX IF NOT EXISTS idx_remote_favorites_favcat ON remote_favorites(favcat);
CREATE INDEX IF NOT EXISTS idx_remote_favorites_synced ON remote_favorites(synced_at);
```

说明：
- `artists_json` 存"这本书在详情页实际解析出的所有作者"。
- 唯一键是 `(source, source_id, favcat)`：同一本书如果在 0 和 9 两个分组都收藏，会有两行，便于后续审计你自己的"交叉分组行为"。
- `is_removed=1` 用于"上次同步看到，这次没看到"的增量标记，**不自动 DELETE**。

### 7.2 `refresh_targets` 新增列（只追加，不动历史）

```sql
ALTER TABLE refresh_targets ADD COLUMN origin_kind TEXT DEFAULT '';
ALTER TABLE refresh_targets ADD COLUMN origin_artist TEXT DEFAULT '';
ALTER TABLE refresh_targets ADD COLUMN origin_favcats TEXT DEFAULT '';  -- CSV e.g. "0,9"
```

补充唯一去重业务键（不是 DB UNIQUE，是代码层）：

- upsert 时如果 `origin_kind='favorite_artist' AND origin_artist=?` 命中 → UPDATE。
- 否则 → INSERT。

### 7.3 索引

```sql
CREATE INDEX IF NOT EXISTS idx_refresh_targets_origin_kind_artist
ON refresh_targets(origin_kind, origin_artist);
```

## 8. 解析器实现

都放在 [exhentai.py](file:///z:/ehLib/ehlib/sites/exhentai.py)。

### 8.1 `_parse_favorites_page(soup) -> list[dict]`

只解析收藏列表结构：
- 条目选择器优先用 `div.itg > div.gl1...`（紧凑视图），失败时回退到表格 `table.itg` 的行。
- 字段：
  - `source_id = gid/token`：复用现有 `parse_exhentai_url()`。
  - `title_en` / `title_jp`：从 `.glink` 和 hover 提示解析（能拿到就拿，拿不到空）。
  - `category`：`.glcat / .gl3` 文本。
  - `added_at`：列表里每本书"Added YYYY-MM-DD HH:MM"部分。
  - `note`：每个条目的用户备注文本（有就取，没有空）。
  - `thumbnail_url`：`.glthumb img` 的 data-src/src。
- **不在这里解析作者**。

### 8.2 详情页拿作者：`_extract_artists_from_gallery_html(html) -> list[str]`

复用现有 `_parse_html()` 里的 tags 解析路径，但只挑 `type='artist'` 的 tag.name，结果按首出现序去重。

流程伪代码：
```
tags = _parse_html(...).tags
artists = [t.name for t in tags if t.type == 'artist']
dedup_keep_order(artists)
```

保证"只要作者列表"的单元职责清晰，不把整本 gallery 存到 remote_favorites。

### 8.3 翻页终止条件

每个 favcat 独立翻页：
1. `page=0` 先抓。
2. 解析分页条按钮（`a[href*='&page=']`），拿最大页号。
3. 顺序请求直到最后一页；或者当前条目 `<25` 时立刻停。

这样避免请求不存在的 `page=N+1`。

## 9. 聚合与 upsert 刷新对象规则

### 9.1 作者规范化

- 只按详情拿到的原始作者名做精确 key（暂不处理大小写 / 不同拼写合并）。
- 不做额外模糊化。后续如果有 alias 表再升级。

### 9.2 生成 refresh_targets 字段

对每个唯一 `artist`：

- `query` = 精确匹配作者：`artist:"<artist>$"`
- `categories = ''`（作者维度不限制分类；如用户需要可以手动改）
- `languages = ''`（同上）
- `force_crawl = 0`（同之前的作者刷新对象）
- `origin_kind = 'favorite_artist'`
- `origin_artist = artist`（原始 key）
- `origin_favcats = "0,1,9"` 的并集 CSV
- `name` 统一走现有 [database.py](file:///z:/ehLib/ehlib/models/database.py) 里的命名链：
  1. `_resolve_saved_search_name(query, categories, languages, force_crawl)`
  2. 否则 `build_query_display_name(query)`
- 默认 `enabled=1`

### 9.3 UPDATE 保护（避免覆盖用户手动值）

当命中 `origin_kind='favorite_artist' AND origin_artist=?` 时：

- **永远不覆盖** 用户改过的字段：
  - 如果该 refresh_target 的 `name` **当前不等于** `_resolve_saved_search_name(...)` 计算出来的系统名，就保留 `name`。
  - 如果用户手动关了 `enabled=0`，保留 enabled。
- **只更新增强信息**：
  - `origin_favcats = union(old_csv, new_favcats)`
  - 如果用户完全没改过 name（仍然是系统生成值），才同步最新汉化结果。
  - `completed_at` 不覆盖。

这样能保证"用户手工整理的刷新对象"不被自动同步弄坏。

### 9.4 移除/下线规则

- 当某本书从远程收藏夹消失时，只在 `remote_favorites` 标记 `is_removed=1`。
- **不自动删除 / 停用 refresh_targets**（用户只是移除收藏，但仍想持续追作者）。
- 如果某作者，在 `is_removed=0 AND favcat IN (0,1,9)` 里**完全不再出现**：
  - 默认不做处理。后续如果用户强烈需要"自动停更"可以加开关 `--prune-orphan-authors`。
  - 本版先明确：不自动停更。只在 summary 里输出 `orphan_authors_count`。

## 10. 命令行接口

新增 CLI（走现有 [main.py](file:///z:/ehLib/ehlib/main.py) 子命令结构）：

```
python -m ehlib sync-exhentai-favorite-authors \
  [--favcats 0,1,9] \
  [--pages-per-cat N] \
  [--max-detail N] \
  [--dry-run] \
  [--no-upsert-name]
```

参数说明：
- `--favcats`（默认 `0,1,9`）：只跑这些分组。
- `--pages-per-cat`（默认空 = ALL）：冒烟用。
- `--max-detail`（默认空 = ALL）：最多进多少本详情页做测试跑。
- `--dry-run`：不写 DB，只打印最终会产生的作者级刷新对象 & 汉化名。
- `--no-upsert-name`：完全不重算 name，只按 origin_artist 键做来源分类更新。

### 10.1 摘要统计（用户调试偏好）

命令结束必须打印结构化 JSON 摘要，至少包含：

```json
{
  "cookies_ok": true,
  "favcats_requested": [0,1,9],
  "favcats_visited": [0,1,9],
  "favorites_list_pages_fetched": 14,
  "favorite_items_seen": 356,
  "favorite_items_detail_succeeded": 349,
  "favorite_items_detail_skipped": 0,
  "favorite_items_detail_failed": 7,
  "unique_artists": 91,
  "refresh_targets_created": 62,
  "refresh_targets_updated": 4,
  "refresh_targets_name_skipped_user_custom": 10,
  "refresh_targets_skipped_unchanged": 15,
  "orphan_authors_current_run": 2,
  "removed_items_marked": 9,
  "errors": [],
  "request_timings": {
    "total_elapsed_s": 241.2,
    "list_avg_interval_s": 4.1,
    "detail_avg_interval_s": 10.9,
    "total_detail_requests": 349
  }
}
```

## 11. Web 接入（轻量）

按现有风格，不新增页面。

### 11.1 `web/api.php` 新 action

新增：
- `action=sync_favorite_authors`
  - POST 参数：`favcats` 可选（CSV），`pages_per_cat` 可选，`max_detail` 可选，`dry_run` 可选。
  - 执行：`run_python_locked(['sync-exhentai-favorite-authors', ...])`
  - 返回：`{ok:true, stdout, stderr, summary?}` 或现有任务式 job_id（沿用现有 worker 调法即可，不改调度）。

建议**不**为本次功能新增独立任务类型，就用 `run_python_locked` 同步返回摘要，符合"低频后台任务 + 摘要透明"的需求。

### 11.2 `web/index.php` UI 入口

在"定期刷新对象"卡片的按钮组加一个按钮，顺序建议：

```
[刷新]  [从收藏夹同步作者]  [批量启停]
```

按钮文案：**从远程收藏同步作者刷新**（tooltip：仅抓取 favcat=0/1/9，并按作者生成刷新对象）。

### 11.3 `web/assets/js/refresh_targets.js` 接入

新增函数 `syncFavoriteAuthors()`，点按钮时调用后端 action，并把后端返回的结构化摘要用现有 `showToast + 小弹窗` 展示出来，把 `created / updated / failed_pages` 等重点数字高亮。

不单独做面板，就沿用 refresh_targets 的列表自身作为最终展示载体。

## 12. 幂等 / 安全

- 多次运行同参数不会重复生成 refresh_targets：靠 `origin_kind='favorite_artist' + origin_artist` 精确匹配。
- `--dry-run` 保证零写入，便于先看作者列表。
- 收藏快照表每次都 upsert，不做重复插入。
- 详情页失败的条目，本轮不写 `artists_json`，下次重跑会重试（因为 `artists_json=''` 可作为条件重抓）。
- 绝不碰 favcat=2~8。

## 13. 验收标准

用户侧可直接验证：

1. 在本地装好 exhentai 登录 Cookie 后，执行 `--dry-run`，能看到"作者 → 汉化名"的映射表，且无作者被漏掉。
2. 再正式跑一次，`refresh_targets` 里出现一批 `origin_kind='favorite_artist'` 的条目，名字和"保存的检索条件"一致。
3. 同一个作者第二次再跑不重复新增，只会补 `origin_favcats`。
4. 若用户把某个刷新对象名字改了，再跑同步不会被覆盖。
5. 全程请求频率看起来像真人浏览：列表 4~5s 一页，详情页 >8~10s 一本，批次间偶发 30~60s 停顿。

## 14. 代码落点清单（最终计划改这些文件）

- Python 后端：
  - [ehlib/sites/exhentai.py](file:///z:/ehLib/ehlib/sites/exhentai.py)：`_parse_favorites_page`、`_extract_artists_from_gallery_html`、翻页辅助函数
  - [ehlib/models/database.py](file:///z:/ehLib/ehlib/models/database.py)：schema 迁移、`upsert_remote_favorites`、`upsert_favorite_artist_refresh_targets`、摘要统计聚合
  - [ehlib/main.py](file:///z:/ehLib/ehlib/main.py)：注册子命令、编排同步流程、最终打印结构化摘要
- PHP/前端：
  - [web/api.php](file:///z:/ehLib/web/api.php)：`action=sync_favorite_authors`
  - [web/index.php](file:///z:/ehLib/web/index.php)：按钮
  - [web/assets/js/refresh_targets.js](file:///z:/ehLib/web/assets/js/refresh_targets.js)：触发 + 摘要展示
