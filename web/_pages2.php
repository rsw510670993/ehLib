<?php
/**
 * ehLib 新前端 · 下载中心 / 定时同步 / 手动爬取（重写版）
 * 契约：id、onclick 函数名、data-cat/data-lang/--cat-color 全部沿用原版。
 */
?>
        <!-- ═══ 下载中心（重写） ═══ -->
        <div id="page_download" class="page-section section-hidden">

            <div class="card">
                <div class="card-head">
                    <h2>单一下载<span class="hint">支持 nhentai / exhentai 链接</span></h2>
                </div>
                <div class="card-body">
                    <ul class="nav nav-tabs tabs" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab_dl_url">URL 下载</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_dl_id">ID 下载</button></li>
                    </ul>

                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="tab_dl_url">
                            <div class="search-row">
                                <input type="text" class="input grow" id="dl_url" placeholder="https://nhentai.net/g/177013/ 或 https://exhentai.org/g/1234567/abc123/">
                                <button class="btn primary" type="button" onclick="doDownload()"><i class="fas fa-download"></i><span>下载</span></button>
                            </div>
                            <div class="check-row">
                                <input type="checkbox" class="form-check-input" id="dl_force_url">
                                <label for="dl_force_url">强制重新下载（清空本地文件并覆盖数据库记录）</label>
                            </div>
                        </div>

                        <div class="tab-pane fade" id="tab_dl_id">
                            <div class="form-grid cols-4">
                                <label class="field">
                                    <span class="field-label">站点</span>
                                    <select class="input" id="dl_source">
                                        <option value="nhentai">nhentai</option>
                                        <option value="exhentai">exhentai</option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span class="field-label">ID</span>
                                    <input type="text" class="input" id="dl_id" placeholder="如 177013">
                                </label>
                                <label class="field">
                                    <span class="field-label">exhentai GID</span>
                                    <input type="text" class="input" id="dl_gid" placeholder="URL 中的数字部分">
                                </label>
                                <label class="field">
                                    <span class="field-label">exhentai Token</span>
                                    <input type="text" class="input" id="dl_token" placeholder="URL 中的 hash 部分">
                                </label>
                                <div class="actions span-all">
                                    <button class="btn primary" type="button" onclick="doDownloadById()"><i class="fas fa-download"></i><span>下载</span></button>
                                </div>
                                <div class="check-row span-all">
                                    <input type="checkbox" class="form-check-input" id="dl_force_id">
                                    <label for="dl_force_id">强制重新下载（清空本地文件并覆盖数据库记录）</label>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="dl_progress" class="progress-track" style="display:none">
                        <div class="progress-fill" id="dl_progress_bar" style="width:0%"></div>
                    </div>
                    <div id="dl_output" class="output-box"></div>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>批量下载<span class="hint">每行一个 URL</span></h2>
                    <button class="btn primary sm" type="button" id="batch_download_btn" onclick="doBatchDownload()"><i class="fas fa-play"></i><span>开始批量下载</span></button>
                </div>
                <div class="card-body">
                    <label class="field">
                        <span class="field-label">URL 列表</span>
                        <textarea class="input" id="batch_urls" rows="5" placeholder="https://nhentai.net/g/177013/&#10;https://exhentai.org/g/1234567/abc123/&#10;https://nhentai.net/g/238480/"></textarea>
                    </label>
                    <div class="check-row">
                        <input type="checkbox" class="form-check-input" id="batch_force">
                        <label for="batch_force">强制重新下载全部（清空本地文件并覆盖数据库记录）</label>
                    </div>
                    <div id="batch_progress_list" style="display:none;margin-top:10px"></div>
                    <div id="batch_output" class="output-box"></div>
                </div>
            </div>

            <div class="card">
                <div class="card-head">
                    <h2>重新下载 / 目录恢复<span class="hint">处理 is_complete=0 的记录</span></h2>
                </div>
                <div class="card-body">
                    <p class="panel-note">重新尝试下载之前未完成的画廊。</p>
                    <div class="check-row" style="margin-bottom:10px">
                        <input type="checkbox" id="retry_skip_existing" class="form-check-input" checked>
                        <label for="retry_skip_existing">跳过已下载图片</label>
                    </div>
                    <div class="actions">
                        <button class="btn primary" type="button" id="retry_btn" onclick="doRetry()"><i class="fas fa-rotate-right"></i><span>重试未完成下载</span></button>
                        <button class="btn ghost" type="button" onclick="recoverOrphans()" title="扫描下载目录恢复到数据库"><i class="fas fa-kit-medical"></i><span>恢复孤儿目录</span></button>
                    </div>
                    <div id="retry_progress_list" style="display:none;margin-top:10px"></div>
                    <div id="retry_output" class="output-box"></div>
                </div>
            </div>
        </div>

        <!-- ═══ 定时同步（重写） ═══ -->
        <div id="page_sync" class="page-section section-hidden">

            <ul class="nav nav-tabs tabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab_rt_list">定期刷新对象</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_rt_history">已结束任务</button></li>
            </ul>

            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab_rt_list">
                    <div class="card">
                        <div class="card-head">
                            <h2>定时同步<span class="hint">每周只把已开启项加入普通爬取队列</span></h2>
                            <div class="actions">
                                <button class="btn ghost sm" type="button" id="sync_fav_btn" onclick="syncFavoriteAuthors()" title="从远程收藏夹扫描并注册作者刷新对象（带节流）"><i class="fas fa-cloud-arrow-down"></i><span>从远程收藏同步作者</span></button>
                                <button class="btn danger sm d-none" type="button" id="sync_fav_cancel_btn" onclick="cancelSyncFavoriteAuthors()" title="终止当前同步任务"><i class="fas fa-stop"></i><span>终止同步</span></button>
                                <button class="btn ghost sm icon-only" type="button" onclick="loadRefreshTargets()" title="刷新列表"><i class="fas fa-rotate"></i></button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="refresh_targets_list" class="panel-note">加载中…</div>
                            <div id="refresh_targets_pagination"></div>

                            <div id="sync_fav_progress" class="d-none" style="margin-top:14px">
                                <div class="progress-head">
                                    <span id="sync_fav_stage" class="panel-note" style="margin:0">准备中…</span>
                                    <span id="sync_fav_pct" class="panel-note" style="margin:0;color:var(--text)">0%</span>
                                </div>
                                <div class="progress-track">
                                    <div id="sync_fav_bar" class="progress-fill" style="width:0%"></div>
                                </div>
                                <div id="sync_fav_stats" class="panel-note" style="margin-top:8px;display:flex;flex-wrap:wrap;gap:14px"></div>
                            </div>
                            <div id="sync_fav_output" class="output-box"></div>
                        </div>
                    </div>
                </div>

                <div class="tab-pane fade" id="tab_rt_history">
                    <div class="card">
                        <div class="card-head">
                            <h2>已结束的爬取任务</h2>
                            <div class="actions">
                                <button class="btn ghost sm" type="button" onclick="clearCrawlHistory('failed')"><i class="fas fa-eraser"></i><span>清空失败/取消</span></button>
                                <button class="btn danger sm" type="button" onclick="clearCrawlHistory('all')"><i class="fas fa-trash-can"></i><span>清空全部</span></button>
                                <button class="btn ghost sm icon-only" type="button" onclick="loadCrawlHistory()" title="刷新历史"><i class="fas fa-rotate"></i></button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="crawl_history_list" class="panel-note">加载中…</div>
                            <div id="crawl_history_pagination"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ 手动爬取（重写） ═══ -->
        <div id="page_crawl" class="page-section section-hidden">

            <div class="card">
                <div class="card-head">
                    <h2>手动爬取<span class="hint">ExHentai 检索语法</span></h2>
                    <button class="btn ghost sm" type="button" onclick="loadCrawlQueue()"><i class="fas fa-rotate"></i><span>刷新队列</span></button>
                </div>

                <div class="card-body">
                    <div class="search-row">
                        <input type="text" class="input grow" id="crawl_keyword" placeholder='例如 artist:"iruma kamiri$" 或 tags'
                               onkeydown="if(event.key==='Enter')startCrawl()">
                        <button class="btn primary" type="button" onclick="startCrawl()" title="使用关键词 + 分类爬取"><i class="fas fa-cloud-arrow-down"></i><span>开始爬取</span></button>
                        <button class="btn ghost sm" type="button" onclick="saveSearchPreset()" title="保存当前检索条件"><i class="fas fa-bookmark"></i></button>
                        <button class="btn ghost sm" type="button" id="crawl_force" onclick="this.classList.toggle('active')" title="强制模式">强制</button>
                        <button class="btn ghost sm icon-only" type="button" onclick="clearCrawlLog()" title="清理工作文件"><i class="fas fa-trash-can"></i></button>
                    </div>

                    <div class="toolbar-row" style="margin-top:12px">
                        <span class="toolbar-label">爬取分类</span>
                        <div class="chips" id="crawl_category_tags">
                            <button class="cat-tag active" data-cat="all" onclick="crawlToggleCategory(this)" style="--cat-color:#6d8bff">全部</button>
                            <button class="cat-tag" data-cat="Doujinshi" onclick="crawlToggleCategory(this)" style="--cat-color:#e2565f">Doujinshi</button>
                            <button class="cat-tag" data-cat="Manga" onclick="crawlToggleCategory(this)" style="--cat-color:#4a72c4">Manga</button>
                            <button class="cat-tag" data-cat="Artist CG" onclick="crawlToggleCategory(this)" style="--cat-color:#8b5cc7">Artist CG</button>
                            <button class="cat-tag" data-cat="Game CG" onclick="crawlToggleCategory(this)" style="--cat-color:#c07f34">Game CG</button>
                            <button class="cat-tag" data-cat="Western" onclick="crawlToggleCategory(this)" style="--cat-color:#3f8a63">Western</button>
                            <button class="cat-tag" data-cat="Non-H" onclick="crawlToggleCategory(this)" style="--cat-color:#7c8598">Non-H</button>
                            <button class="cat-tag" data-cat="Image Set" onclick="crawlToggleCategory(this)" style="--cat-color:#3d8f9c">Image Set</button>
                            <button class="cat-tag" data-cat="Cosplay" onclick="crawlToggleCategory(this)" style="--cat-color:#b7568f">Cosplay</button>
                            <button class="cat-tag" data-cat="Asian Porn" onclick="crawlToggleCategory(this)" style="--cat-color:#8a6a4f">Asian Porn</button>
                            <button class="cat-tag" data-cat="Misc" onclick="crawlToggleCategory(this)" style="--cat-color:#5b6b86">Misc</button>
                        </div>
                    </div>

                    <div class="toolbar-row">
                        <span class="toolbar-label">爬取语言</span>
                        <div class="chips" id="crawl_language_tags">
                            <button class="cat-tag active" data-lang="all" onclick="crawlToggleLanguage(this)" style="--cat-color:#6d8bff">全部</button>
                        </div>
                    </div>

                    <div class="bookmark-block" id="saved_presets_section">
                        <div class="bookmark-header" onclick="toggleBookmarkCollapse()">
                            <span><i class="far fa-bookmark"></i>书签 <span id="bookmark_count" class="count-pill">0</span></span>
                            <i class="fas fa-chevron-up" id="bookmark_collapse_icon"></i>
                        </div>
                        <div id="saved_presets_row" class="bookmark-content"></div>
                    </div>

                    <div id="crawl_queue_panel" class="queue-block">
                        <div class="queue-head">
                            <span class="panel-note" style="margin:0"><i class="fas fa-list-ol"></i>爬取队列</span>
                            <button class="btn ghost sm icon-only" type="button" onclick="loadCrawlQueue()" title="刷新队列"><i class="fas fa-rotate"></i></button>
                        </div>
                        <div id="crawl_queue_list" class="panel-note" style="margin:0">暂无任务</div>
                    </div>
                </div>

                <div class="card-body" style="padding-top:0">
                    <div class="card-head" style="padding:12px 0 10px;border:0"><h2 style="font-size:12px">爬取日志输出</h2></div>
                    <div id="crawl_output" class="output-box"></div>
                </div>
            </div>
        </div>
