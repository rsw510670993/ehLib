# ehLib

ExHentai / nhentai 本地画廊管理工具。

## 标签汉化

基于 [EhTagTranslation/Database](https://github.com/EhTagTranslation/Database) 社区翻译库将英文标签自动汉化。

```bash
# 下载/更新最新翻译库（从 GitHub latest release 获取 db.text.json）
python -m ehlib update-translations

# 用翻译库批量汉化已有缓存的标签（写入 tags_cn 字段）
python -m ehlib translate-tags
```

新爬取的作品会自动翻译，无需手动运行 `translate-tags`。如需刷新已有缓存的翻译，重新运行上述两步即可。
