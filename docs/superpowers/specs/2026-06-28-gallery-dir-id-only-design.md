# 本地画廊目录改为仅保留唯一 ID 设计

## 目标

将 `downloads` 下的画廊目录名从当前的“唯一 ID + 标题”改为仅保留唯一 ID，并支持把已有旧目录迁移到新格式。

目标效果：

- `nhentai` 目录格式变为 `downloads/nhentai/<id>`
- `exhentai` 目录格式变为 `downloads/exhentai/<gid_token>`
- 目录名不再包含标题，避免过长、特殊字符和重命名噪音

## 现状

- 当前目录名由 `format_gallery_dir(source, source_id, title)` 生成，目录名包含标题。
- 数据库中的 `galleries.local_path` 保存当前实际目录路径。
- 下载、删除、本地列表等能力均依赖 `local_path`，不直接重新推导旧目录路径。

## 方案

### 新目录规则

- `nhentai`：直接使用 `source_id`
- `exhentai`：使用将 `/` 转换为安全文件名后的 `source_id`
- 目录命名不再拼接 `title`

### 迁移时机

实现显式批量迁移，而不是仅在下载时被动迁移：

- 新增一个迁移入口，遍历数据库中的已有画廊记录
- 对每条记录计算目标新目录
- 若当前 `local_path` 已是新目录，则跳过
- 若旧目录存在且目标目录不存在，则执行重命名并更新数据库 `local_path`

### 冲突处理

- 若旧目录不存在：跳过并记录
- 若目标新目录已存在：不自动覆盖，不合并文件，记录冲突并跳过
- 若数据库记录不存在本地路径：跳过并记录

### 运行时行为

- 新下载直接创建新格式目录
- 删除、本地图库、强制重下载沿用数据库里的 `local_path`，无需额外兼容旧命名

## 修改范围

- `ehlib/utils/helpers.py`
  - 修改 `format_gallery_dir()`，去掉标题拼接
- `ehlib/storage/file_manager.py`
  - 新增目录迁移辅助方法
- `ehlib/models/database.py`
  - 新增更新 `local_path` 的能力，供迁移流程调用
- `ehlib/main.py`
  - 新增目录迁移 CLI 入口，例如 `migrate-dirs`
- `web/api.php`
  - 如需要，可增加触发迁移的 API；若本轮只做 CLI，可暂不增加

## 安全边界

- 迁移仅处理数据库中已有记录指向的目录
- 不接受任意路径输入，不扫描库外目录
- 遇到目标目录冲突时停止该条迁移，不做覆盖

## 验证

- `nhentai` 旧目录迁移后仅保留 ID，数据库 `local_path` 正确更新
- `exhentai` 旧目录迁移后仅保留安全化后的 `gid_token`
- 迁移后执行删除功能，确认仍能正确删除目录和数据库记录
- 新下载的画廊直接创建短目录名
