<?php
/**
 * ehLib 新前端 · 页面容器
 * 迁移进度：gallery / cache 已重写；settings / sync / crawl / download / tools 仍走 parts/ 旧结构。
 * 所有 section 必须保留 id=page_xxx 与 class="page-section"(+ section-hidden)，navigation.js 依赖它们。
 */
$parts = __DIR__ . '/parts';
?>
        <!-- ═══ 图库（重写） ═══ -->
        <div id="page_gallery" class="page-section section-hidden">

            <div class="view-head">
                <div class="chips" role="group" aria-label="来源">
                    <button class="chip is-on" type="button" onclick="loadGalleries()">全部</button>
                    <button class="chip" type="button" onclick="loadGalleries({source:'nhentai'})">nhentai</button>
                    <button class="chip" type="button" onclick="loadGalleries({source:'exhentai'})">exhentai</button>
                </div>
                <div class="head-right">
                    <button class="btn ghost sm" type="button" onclick="loadGalleries()">
                        <i class="fas fa-rotate"></i><span>刷新</span>
                    </button>
                </div>
            </div>

            <div class="toolbar">
                <label class="field f-tags">
                    <span class="field-label">标签（逗号分隔）</span>
                    <input type="text" class="input" id="gallery_tag_filter" placeholder="english, shindol"
                           onkeydown="if(event.key==='Enter')applyGalleryFilter()">
                </label>
                <label class="field f-mode">
                    <span class="field-label">匹配模式</span>
                    <select class="input" id="gallery_tag_mode">
                        <option value="any">任意（OR）</option>
                        <option value="all">全部（AND）</option>
                    </select>
                </label>
                <label class="field f-artist">
                    <span class="field-label">作者</span>
                    <input type="text" class="input" id="gallery_artist_filter" placeholder="artist name"
                           onkeydown="if(event.key==='Enter')applyGalleryFilter()">
                </label>
                <label class="field f-lang">
                    <span class="field-label">语言</span>
                    <input type="text" class="input" id="gallery_lang_filter" placeholder="english"
                           onkeydown="if(event.key==='Enter')applyGalleryFilter()">
                </label>
                <div class="field-actions">
                    <button class="btn primary" type="button" onclick="applyGalleryFilter()"><i class="fas fa-filter"></i><span>筛选</span></button>
                    <button class="btn ghost icon-only" type="button" onclick="clearGalleryFilter()" title="清空筛选"><i class="fas fa-xmark"></i></button>
                </div>
            </div>

            <div class="grid-host" id="gallery_grid_body">
                <div class="empty"><i class="fas fa-spinner fa-spin"></i><p>加载中…</p></div>
            </div>

            <div class="pager-host" id="gallery_pagination"></div>
        </div>

        <!-- ═══ 缓存（重写） ═══ -->
        <div id="page_cache" class="page-section section-hidden">

            <div class="view-head">
                <div class="chips" role="group" aria-label="检索范围">
                    <span class="chip-label">检索范围</span>
                    <select class="input sm" id="cache_search_scope">
                        <option value="all" selected>全部</option>
                        <option value="title">标题</option>
                        <option value="author">作者</option>
                        <option value="tags">标签</option>
                        <option value="tags_cn">中文标签</option>
                    </select>
                    <input type="text" class="input sm grow" id="cache_keyword" placeholder="搜索关键词"
                           onkeydown="if(event.key==='Enter')cacheSearch()">
                </div>
                <div class="head-right">
                    <button class="btn primary sm" type="button" onclick="cacheSearch()"><i class="fas fa-filter"></i><span>检索</span></button>
                    <button class="btn ghost sm" type="button" onclick="cacheClearFilter()"><i class="fas fa-xmark"></i></button>
                    <button class="btn ghost sm icon-only" type="button" onclick="loadCachePage(1)" title="刷新"><i class="fas fa-rotate"></i></button>
                </div>
            </div>

            <div class="toolbar stacked">
                <div class="toolbar-row">
                    <span class="toolbar-label">分类</span>
                    <div class="chips" id="cache_category_tags">
                        <button class="cat-tag active" data-cat="all" onclick="cacheToggleCategory(this)" style="--cat-color:#6d8bff">全部</button>
                        <button class="cat-tag" data-cat="Doujinshi" onclick="cacheToggleCategory(this)" style="--cat-color:#e2565f">同人志</button>
                        <button class="cat-tag" data-cat="Manga" onclick="cacheToggleCategory(this)" style="--cat-color:#4a72c4">漫画</button>
                        <button class="cat-tag" data-cat="Artist CG" onclick="cacheToggleCategory(this)" style="--cat-color:#8b5cc7">艺术家CG</button>
                        <button class="cat-tag" data-cat="Game CG" onclick="cacheToggleCategory(this)" style="--cat-color:#c07f34">游戏CG</button>
                        <button class="cat-tag" data-cat="Western" onclick="cacheToggleCategory(this)" style="--cat-color:#3f8a63">西方</button>
                        <button class="cat-tag" data-cat="Non-H" onclick="cacheToggleCategory(this)" style="--cat-color:#7c8598">非H</button>
                        <button class="cat-tag" data-cat="Image Set" onclick="cacheToggleCategory(this)" style="--cat-color:#3d8f9c">图集</button>
                        <button class="cat-tag" data-cat="Cosplay" onclick="cacheToggleCategory(this)" style="--cat-color:#b7568f">角色扮演</button>
                        <button class="cat-tag" data-cat="Asian Porn" onclick="cacheToggleCategory(this)" style="--cat-color:#8a6a4f">亚洲色情</button>
                        <button class="cat-tag" data-cat="Misc" onclick="cacheToggleCategory(this)" style="--cat-color:#5b6b86">其他</button>
                    </div>
                </div>
                <div class="toolbar-row">
                    <span class="toolbar-label">语言</span>
                    <div class="chips" id="cache_language_tags">
                        <button class="cat-tag active" data-lang="all" onclick="cacheToggleLanguage(this)" style="--cat-color:#6d8bff">全部</button>
                    </div>
                </div>
                <div class="toolbar-row" id="cache_batch_bar"></div>
            </div>

            <div class="grid-host" id="cache_grid_body">
                <div class="empty"><i class="fas fa-spinner fa-spin"></i><p>加载中…</p></div>
            </div>

            <div class="pager-host" id="cache_pagination"></div>

            <!-- 作品详情弹窗（id 与 class 契约不变） -->
            <div class="modal fade" id="cache_detail_modal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title text-truncate" id="cache_detail_title">作品详情</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" id="cache_detail_body">
                            <div class="empty"><i class="fas fa-spinner fa-spin"></i><p>加载中…</p></div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn ghost" data-bs-dismiss="modal">关闭</button>
                        </div>
                    </div>
                </div>
            </div>

        </div>

<?php include __DIR__ . '/_pages.php'; ?>
<?php include __DIR__ . '/_pages2.php'; ?>
