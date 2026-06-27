# ehLib - 同人漫画下载与本地化管理工具

## 1. 摘要

构建一个 Python CLI 工具，从 **nhentai** 和 **exhentai (e-hentai)** 下载漫画（画廊），爬取关联的 tag 标签，并以本地文件系统 + SQLite 数据库的组合方式进行本地化存储管理。工具需支持手动 Cookie 导入和自动化浏览器两种反爬策略以应对 Cloudflare 保护。

## 2. 当前状态分析

- **工作目录**: `d:\wamp34\www\ehLib` — 空目录，全新项目
- **目标站点特点**:
  - **nhentai** (nhentai.net): Cloudflare 保护，有半公开 JSON API (`/api/gallery/{id}`, `/api/galleries/search`)，tag 数据可通过 API 直接获取 JSON 结构化数据
  - **exhentai** (exhentai.org): 俗称"sad panda"，需要已登录的 Cookie (`ipb_member_id`, `ipb_pass_hash`) 才能访问，同样有 Cloudflare 保护。e-hentai.org 是其公开镜像但内容有限。页面结构为 HTML，tag 信息需从页面解析
- **无现有代码**，从零构建

## 3. 技术选型

| 维度 | 选择 | 理由 |
|------|------|------|
| 语言 | Python 3.11+ | 爬虫生态最成熟，`nodriver`/`httpx`/`beautifulsoup4` 等库完善 |
| 虚拟环境 | `venv` (Python 内置) | 跨平台零依赖，隔离项目运行环境，方便 Ubuntu 服务器部署 |
| 反爬策略 | Cookie 模式优先 + 浏览器自动回退 | 轻量快速为主，复杂场景用无头浏览器 |
| 存储 | 文件系统 (图片) + SQLite (元数据) | 图片按目录存放，标签和元数据可查询 |
| 运行方式 | CLI (命令行) | 简洁高效，可脚本化，后续可加 Web 界面 |
| 包管理 | `pip` + `requirements.txt` | 标准方案 |

## 4. 项目结构

```
ehLib/
├── .gitignore                 # 排除 venv/ downloads/ __pycache__/ .env
├── setup.sh                   # Ubuntu 一键部署脚本 (创建venv、安装依赖)
├── setup.bat                  # Windows 一键部署脚本
├── run.sh                     # 启动脚本 (激活venv后运行，Linux)
├── run.bat                    # 启动脚本 (激活venv后运行，Windows)
├── requirements.txt           # Python 依赖
├── config.yaml                # 配置文件 (Cookie、下载路径等)
├── config.example.yaml        # 配置文件模板 (不含敏感信息，纳入版本管理)
├── ehlib/
│   ├── __init__.py
│   ├── main.py                # CLI 入口，argparse 命令行解析
│   ├── config.py              # 配置加载模块 (YAML)
│   ├── core/
│   │   ├── __init__.py
│   │   ├── downloader.py      # 通用下载器 (图片下载、并发控制、重试)
│   │   ├── anti_bot.py        # 反爬模块 (Cookie 注入、nodriver 浏览器)
│   │   └── session_manager.py # Session 管理 (Cookie 持久化、过期检测)
│   ├── sites/
│   │   ├── __init__.py
│   │   ├── base.py            # 站点基类 (抽象接口)
│   │   ├── nhentai.py         # nhentai 站点实现
│   │   └── exhentai.py        # exhentai 站点实现
│   ├── models/
│   │   ├── __init__.py
│   │   ├── database.py        # SQLite 数据库操作 (aiosqlite 原生 SQL)
│   │   └── schemas.py         # 数据模型定义
│   ├── storage/
│   │   ├── __init__.py
│   │   └── file_manager.py    # 文件系统管理 (目录创建、命名规范化)
│   └── utils/
│       ├── __init__.py
│       ├── logger.py          # 日志模块
│       └── helpers.py         # 辅助函数 (文件名清理、slug生成等)
└── downloads/                  # 默认下载目录 (gitignore)
    ├── nhentai/
    │   └── {gallery_id} - {title_slug}/
    │       ├── metadata.json   # 元数据副本(方便直接查看)
    │       ├── cover.{ext}
    │       ├── 001.{ext}
    │       └── ...
    └── exhentai/
        └── {gallery_id} - {title_slug}/
            └── ...
```

## 5. 数据库设计 (SQLite)

### 表: galleries

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PK | 自增主键 |
| source | TEXT | 来源: "nhentai" / "exhentai" |
| source_id | TEXT | 来源站点的 ID (如 "177013" 或 exhentai gid) |
| title | TEXT | 标题 (英文) |
| title_jp | TEXT | 日文标题 (可选) |
| artist | TEXT | 作者 |
| group_name | TEXT | 社团/团体 |
| language | TEXT | 语言 |
| category | TEXT | 分类 (doujinshi/manga/artist CG 等) |
| total_pages | INTEGER | 总页数 |
| cover_url | TEXT | 封面图远程 URL |
| cover_path | TEXT | 封面图本地路径 |
| thumbnail_url | TEXT | 缩略图远程 URL |
| uploaded_at | TEXT | 上传时间 |
| local_path | TEXT | 本地文件夹路径 |
| downloaded_at | TEXT | 下载完成时间 |
| file_size | INTEGER | 总文件大小 (bytes) |
| is_complete | INTEGER | 是否完整下载 (0/1) |
| created_at | TEXT | 记录创建时间 |
| updated_at | TEXT | 记录更新时间 |

### 表: tags

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PK | 自增主键 |
| type | TEXT | 标签类型 (parody/character/tag/artist/group/language/category) |
| name | TEXT | 标签名称 |

### 表: gallery_tags (多对多关联)

| 字段 | 类型 | 说明 |
|------|------|------|
| id | INTEGER PK | 自增主键 |
| gallery_id | INTEGER FK | 关联 galleries.id |
| tag_id | INTEGER FK | 关联 tags.id |

**索引**:
- `galleries.source_id + source` 联合唯一索引
- `tags.type + name` 联合唯一索引
- `gallery_tags.gallery_id` 索引
- `gallery_tags.tag_id` 索引

## 6. 反爬策略设计

### 6.1 Cookie 模式 (默认，优先使用)

**原理**: 用户从浏览器手动导出 Cookie 写入 `config.yaml`，程序使用 `httpx.Client` 携带这些 Cookie 发送请求。

**nhentai Cookie 要求**:
- `csrftoken` — CSRF token
- `cf_clearance` — Cloudflare 放行 cookie (有效期约 30 分钟～数小时)

**exhentai Cookie 要求**:
- `ipb_member_id` — 会员 ID (登录后获得)
- `ipb_pass_hash` — 密码哈希 (登录后获得)
- `cf_clearance` — Cloudflare 放行 cookie

**配置示例** (`config.yaml`):
```yaml
cookies:
  nhentai:
    csrftoken: "xxxxx"
    cf_clearance: "xxxxx"
  exhentai:
    ipb_member_id: "123456"
    ipb_pass_hash: "abcdef123456"
    cf_clearance: "xxxxx"

download:
  path: "./downloads"
  max_concurrent: 3
  retry_times: 3
  retry_delay: 5

request:
  user_agent: "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ..."
  delay_between_requests: 1.5  # 秒
```

### 6.2 浏览器模式 (回退方案)

当 Cookie 模式失败（返回 Cloudflare 拦截页或 403）时：

1. 使用 `nodriver` (基于 Chrome DevTools Protocol 的无头浏览器) 启动浏览器
2. 加载目标页面，等待 Cloudflare Turnstile 自动验证通过
3. 用户可在非 headless 模式下手动完成验证
4. 通过后提取页面数据
5. 自动提取新的 `cf_clearance` Cookie 并保存到配置

## 7. 命令行接口设计

```bash
# 下载 nhentai 画廊
python -m ehlib download nhentai --id 177013
python -m ehlib download nhentai --url https://nhentai.net/g/177013/

# 下载 exhentai 画廊
python -m ehlib download exhentai --url "https://exhentai.org/g/1234567/abc123def4/"
python -m ehlib download exhentai --gid 1234567 --token abc123def4

# 批量下载 (从文件读取 URL 列表)
python -m ehlib batch --file urls.txt

# 搜索
python -m ehlib search nhentai --query "tag:english"

# 查看本地库
python -m ehlib list --source nhentai --artist "shindol"

# Cookie 管理
python -m ehlib config --set-cookie nhentai cf_clearance "value"
python -m ehlib config --show-cookies

# 导出元数据
python -m ehlib export --format json --output metadata.json

# 重试未完成的下载
python -m ehlib retry
```

## 8. 核心流程

### 8.1 下载流程

```
用户输入 ID/URL
  → 解析来源和目标 ID
  → 检查本地是否已下载 (SQLite 查询)
    ├─ 已下载完整 → 提示用户，跳过
    └─ 未下载/不完整 → 继续
  → 加载 Cookies 并构建 HTTP Client
  → 请求画廊元数据/页面
    ├─ 成功 → 解析 tags、页面列表
    ├─ Cloudflare 拦截 → 切换到浏览器模式
    └─ 连接失败 → 重试/报错
  → 创建本地目录 ({source}/{id} - {title_slug})
  → 并行下载封面和所有页面 (asyncio + 限流)
    ├─ 每张图片下载后写入磁盘
    └─ 失败重试 N 次
  → 写入 SQLite 元数据和 tags
  → 写入 metadata.json 备用副本
  → 输出下载摘要
```

### 8.2 Tag 本地化存储

- nhentai: 从 API JSON 中直接提取 tags 数组，每条 tag 包含 `type` 和 `name`
- exhentai: 从 HTML 页面解析 tag 区域，提取分类和标签名
- 统一存入 `tags` 表（按 type+name 去重）和 `gallery_tags` 关联表

## 9. venv 虚拟环境与部署

### 9.1 venv 目录结构

```
ehLib/
└── venv/                    # 虚拟环境目录 (gitignore，不入库)
    ├── bin/                 # Linux: python, pip, activate
    │   └── activate
    ├── Scripts/             # Windows: python.exe, pip.exe, activate.bat
    │   ├── activate.bat
    │   └── Activate.ps1
    ├── Lib/                 # Windows: 安装的 Python 包
    └── lib/                 # Linux: 安装的 Python 包
```

### 9.2 Windows 环境初始化 (setup.bat)

```batch
@echo off
echo === ehLib 环境初始化 ===
python -m venv venv
call venv\Scripts\activate.bat
pip install --upgrade pip
pip install -r requirements.txt
echo === 初始化完成 ===
echo 运行: run.bat 或 venv\Scripts\activate.bat
```

### 9.3 Ubuntu 服务器部署 (setup.sh)

```bash
#!/bin/bash
set -e
echo "=== ehLib 环境初始化 ==="

# 1. 安装系统依赖 (nodriver 需要 Chromium)
sudo apt-get update
sudo apt-get install -y python3 python3-venv python3-pip chromium-browser

# 2. 创建虚拟环境
python3 -m venv venv
source venv/bin/activate

# 3. 升级 pip 并安装 Python 依赖
pip install --upgrade pip
pip install -r requirements.txt

echo "=== 初始化完成 ==="
echo "运行: ./run.sh 或 source venv/bin/activate"
```

### 9.4 启动方式

**Windows (run.bat)**:
```batch
@echo off
call venv\Scripts\activate.bat
python -m ehlib %*
```

**Ubuntu (run.sh)**:
```bash
#!/bin/bash
source venv/bin/activate
python -m ehlib "$@"
```

### 9.5 Ubuntu 服务器无头运行要点

- **nodriver 要求**: 服务器必须安装 Chromium 或 Google Chrome，设置环境变量 `CHROME_PATH` 指向浏览器可执行文件
- **无头模式**: 在 `config.yaml` 中设置 `browser.headless: true`
- **Display 依赖**: 无头模式下 `nodriver` 自动以 `--headless=new` 模式启动，无需 Xvfb
- **Cookie 导入**: 先在本地 Windows 浏览器获取 Cookie，通过 SCP/rsync 传输 `config.yaml` 到服务器
- **Systemd 服务**: 可将定时下载配置为 systemd timer 实现自动化

```yaml
# config.yaml 服务器关键配置
browser:
  headless: true
  chrome_path: "/usr/bin/chromium-browser"
request:
  delay_between_requests: 2.0  # 服务器可适当放宽间隔
```

## 10. Python 依赖清单

```txt
httpx>=0.27.0
nodriver>=0.0.1
beautifulsoup4>=4.12.0
pyyaml>=6.0
aiosqlite>=0.20.0
tqdm>=4.66.0
Pillow>=10.0.0
```

- **httpx**: 异步 HTTP 客户端，支持 HTTP/2，Cookie 管理
- **nodriver**: 新一代无头浏览器库，专为绕过 Cloudflare 检测设计（替代已弃用的 undetected-chromedriver）
- **beautifulsoup4**: HTML 解析（exhentai 页面解析）
- **pyyaml**: YAML 配置文件解析
- **aiosqlite**: SQLite 异步操作
- **tqdm**: 下载进度条
- **Pillow**: 图片处理和验证

## 11. 假设与决策

1. **Python 作为开发语言** — 爬虫生态最强，`nodriver` 是 2025-2026 年绕过 Cloudflare 的首选工具
2. **优先 Cookie 模式** — 轻量快速，对目标站点压力小。Cookie 需用户手动从浏览器导出到配置文件
3. **SQLite 本地数据库** — 零配置、便携、支持 SQL 查询，适合个人使用规模
4. **文件系统存储图片** — 图片存为独立文件方便直接浏览，数据库仅存元数据
5. **CLI 优先** — 快速实现核心功能，后续可扩展 Web 界面
6. **尊重 robots.txt 和速率限制** — 默认 1.5 秒请求间隔，可配置
7. **仅存放画廊元数据的 JSON 副本** — 方便在不查询数据库的情况下查看基本信息
8. **nhentai 使用其非官方但广泛使用的 JSON API** (`/api/gallery/{id}`)
9. **exhentai 通过 HTML 解析** — 没有公开 API，需要解析 HTML 页面

## 12. 验证步骤

1. **单元测试**: 测试各个模块独立功能（Cookie 加载、HTML 解析、URL 解析等）
2. **集成测试**: 使用已知存在的画廊 ID 测试完整下载流程
3. **反爬测试**: 验证 Cookie 模式成功后浏览器模式回退
4. **数据库测试**: 验证 tag 去重、关联表正确性
5. **断点续传测试**: 模拟中断后重新下载

## 13. 风险与注意事项

- **Cloudflare 持续更新**: `nodriver` 需要关注更新，CF 检测手段会变化
- **Cookie 有效期**: `cf_clearance` 有时效，需用户定期更新
- **exhentai 访问限制**: 需要有效的论坛账号，没有账号无法访问
- **法律合规**: 用户需自行评估下载内容的版权合规性，工具仅供个人学习研究使用
- **速率控制**: 务必控制请求频率，避免对目标站点造成负担
