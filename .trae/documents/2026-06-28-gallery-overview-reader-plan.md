# 本地画廊概览页 + 详情阅读器 实现计划

## 概要

1. 打通 `title_jp`（日文标题）从数据库到前端展示的完整链路
2. 用卡片网格替换现有本地图库的表格视图，展示封面缩略图 + 标题（优先日语）
3. 新增全页面详情阅读器，展示完整元数据 + 图片阅读功能

---

## 当前状态分析

| 环节 | title_jp 状态 |
|------|-------------|
| Gallery schema | 有 `title_jp` 字段 ✅ |
| DB 表结构 | 有 `title_jp TEXT` 列 ✅ |
| `save_gallery` | 正确写入 `title_jp` ✅ |
| nhentai/exhentai 解析 | 正确提取 `title_jp` ✅ |
| CLI `list` 输出 | **缺失 title_jp** ❌ |
| `get_galleries` PHP | **只解析 title** ❌ |
| `get_gallery_detail` SQL | **没查 title_jp** ❌ |
| 前端图库表格 | **没有 title_jp 列** ❌ |

当前图库页：表格视图 + 展开详情行（无图片浏览）。
目标：nhentai 风格卡片网格覆盖页 + 全页面详情阅读器。

---

## 修改范围

### 1. title_jp 管道修复

#### 1a. CLI 输出增加 title_jp
**文件**: [ehlib/main.py](file:///d:/wamp34/www/ehLib/ehlib/main.py) - `cmd_list()`
- 输出格式从 `[source/source_id] title (pagesp) - time`
- 改为：`[source/source_id] title | title_jp | local_path (pagesp) - time`
- 无 title_jp 时输出空位 `title | | local_path (pagesp) - time`

#### 1b. PHP get_galleries 解析增加 title_jp
**文件**: [web/api.php](file:///d:/wamp34/www/ehLib/web/api.php) - `get_galleries` 正则
- 增加 title_jp 和 local_path 提取
- JSON 返回增加 `title_jp` 和 `local_path` 字段

#### 1c. PHP get_gallery_detail SQL 增加 title_jp
**文件**: [web/api.php](file:///d:/wamp34/www/ehLib/web/api.php) - `get_gallery_detail` SQL
- SQL 查询增加 `title_jp` 字段
- JSON 返回增加 `title_jp`

---

### 2. 图片服务 API

#### 2a. PHP serve_image 动作
**文件**: [web/api.php](file:///d:/wamp34/www/ehLib/web/api.php)
- 新增 `serve_image` action
- 参数：`source`, `source_id`, `page`（cover 或数字）
- 流程：
  1. PDO 查 `local_path`
  2. 拼文件路径：`{local_path}/{page_str}{ext}`（cover 优先 `cover.*`）
  3. 验证路径在下载目录内
  4. 读取文件，输出正确 Content-Type
- 安全：只允许已存在的数据库记录指向的路径，拒绝任意路径遍历

#### 2b. PHP get_image_list 动作
**文件**: [web/api.php](file:///d:/wamp34/www/ehLib/web/api.php)
- 新增 `get_image_list` action
- 参数：`source`, `source_id`
- 返回：页数列表和文件名，如 `[{"page":1,"file":"001.jpg"}, ...]`
- 流程：
  1. PDO 查 `local_path`
  2. 扫描目录下所有图片文件（按数字排序）
  3. 返回列表

---

### 3. 概览页：卡片网格（替换表格）

#### 3a. HTML 结构替换
**文件**: [web/index.php](file:///d:/wamp34/www/ehLib/web/index.php) - `page_gallery` 部分
- 保留过滤栏（标签、作者、语言、站点筛选）
- 删除 `<table>` 结构，替换为卡片网格容器 `<div id="gallery_grid" class="row row-cols-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3">`
- 保留筛选表单输入框 ID 不变
- 每张卡片结构：
  ```html
  <div class="col" data-source="nhentai" data-source-id="123456">
    <div class="card h-100 gallery-card" onclick="openReader('nhentai','123456')">
      <div class="card-img-wrapper" style="aspect-ratio:3/4;overflow:hidden">
        <img src="api.php?action=serve_image&source=nhentai&source_id=123456&page=cover"
             class="card-img-top" alt="cover" loading="lazy">
      </div>
      <div class="card-body p-2">
        <div class="small text-truncate mb-1" title="日语标题">日语标题</div>
        <div class="small text-muted text-truncate" title="英语标题">英语标题</div>
        <div class="d-flex justify-content-between align-items-center mt-1">
          <span class="badge bg-danger" style="font-size:.65rem">nhentai</span>
          <span class="small text-muted">51p</span>
        </div>
      </div>
    </div>
  </div>
  ```

#### 3b. loadGalleries() 重构
**文件**: [web/index.php](file:///d:/wamp34/www/ehLib/web/index.php) - JS 函数
- 从渲染表格行改为渲染卡片网格
- API 请求参数不变，但解析 `title_jp` 和 `local_path`
- 无结果时显示空状态提示
- 保留现有筛选逻辑（_galleryFilters）

#### 3c. 删除旧表格相关代码
- 移除 `toggleGalleryDetail()` 和相关 HTML
- 移除 `_galleryDetailCache`
- 表格相关的样式可以保留或复用

---

### 4. 详情阅读器页面

#### 4a. 新增 page_reader 页面段
**文件**: [web/index.php](file:///d:/wamp34/www/ehLib/web/index.php)
- 新增 `<div id="page_reader" class="page-section section-hidden">`
- 布局结构：
  - **顶部信息栏**（固定高度）：标题（日语优先）+ 元数据摘要
  - **主体区域**（flex, 剩余高度）：
    - 左侧缩略图条（160px 宽, 可滚动）：显示所有页的小缩略图，当前页高亮
    - 右侧大图区（flex-grow）：居中显示当前页大图，支持放大缩小

#### 4b. 导航
- 点击概览页卡片 → `openReader(source, sourceId)` → 切换到 reader 页面
- reader 页面顶部有返回按钮回到概览页
- 键盘：`←` 上一页、`→` 下一页、`Esc` 返回概览
- 点击缩略图跳转到对应页

#### 4c. 元数据面板
- 在左侧缩略图条上方固定区域显示：
  - 封面缩略图（小）
  - 标题（日语优先，英语辅助）
  - 作者
  - 语言
  - 分类
  - 标签列表（彩色 badge）
  - 页码指示器 `12 / 51`

#### 4d. 大图加载
- 当前页图片通过 `serve_image` API 加载
- 预加载前后 2 页
- 图片加载失败时显示占位提示

#### 4e. 切换分页
- `switchPage()` 增加 `reader` 页面路由
- 侧边栏链接高亮保持在“本地图库”

---

### 5. CSS 样式新增

**文件**: [web/index.php](file:///d:/wamp34/www/ehLib/web/index.php) - `<style>` 块
- 卡片网格：每张卡片 hover 放大效果、图片保持 3:4 比例
- 缩略图条：固定宽度、可滚动、当前页高亮边框
- 大图区：flex 居中、max-height 自适应
- reader 页面：全屏高度（100vh - 导航栏高度）
- 分页信息栏：固定高度，flex 布局

---

## 数据流

```
[数据库 SQLite]
    │
    ├─ get_galleries → CLI(python -m ehlib list) → PHP 解析 → JSON → 卡片网格
    │
    ├─ get_gallery_detail → PDO 直接查 → JSON → 详情页元数据
    │
    ├─ serve_image → PDO 查 local_path → 读文件 → 直接输出图片
    │
    └─ get_image_list → PDO 查 local_path → 扫描目录 → JSON 列表
```

---

## 安全边界

- `serve_image` 不接受任意路径参数，只接受 `source + source_id + page`
- 文件路径由数据库 `local_path` 决定，用户无法构造任意路径
- 只允许图片扩展名（.jpg, .jpeg, .png, .gif, .webp）
- 文件路径必须在下载目录内

---

## 浏览器兼容

- CSS Grid 使用 `row-cols-*`（Bootstrap 5 原生）
- `aspect-ratio: 3/4` 现代浏览器均支持
- `loading="lazy"` 现代浏览器支持
- 键盘事件绑定在 detail reader 激活时

---

## 验证步骤

1. **title_jp 链路**：
   - 执行 `python -m ehlib list`，确认输出含 title_jp
   - 调用 `get_galleries` API，确认返回含 title_jp
   - 调用 `get_gallery_detail` API，确认返回含 title_jp

2. **图片服务**：
   - 调用 `serve_image?source=nhentai&source_id=657326&page=cover`，确认返回图片
   - 调用 `serve_image?source=nhentai&source_id=657326&page=1`，确认返回第一页
   - 调用 `get_image_list?source=nhentai&source_id=657326`，确认返回图片列表

3. **概览页**：
   - 切换到本地图库页面，确认卡片网格正常渲染
   - 确认封面缩略图加载正常
   - 确认筛选（站点、标签、作者、语言）工作正常

4. **详情阅读器**：
   - 点击卡片，确认跳转到阅读器页面
   - 确认缩略图条显示所有页
   - 确认← →翻页正常
   - 确认大图加载正常
   - 确认返回概览页正常
