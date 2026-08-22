
        <!-- ═══ 1. 系统设置 ═══ -->
        <div id="page_settings" class="page-section">

            <!-- Cookie + 系统设置 合并 3 tab -->
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Cookie & 系统设置</span>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-success" onclick="saveCookies()"><i class="fas fa-save me-1"></i>保存 Cookie</button>
                        <button class="btn btn-sm btn-success" onclick="saveSettings()"><i class="fas fa-save me-1"></i>保存设置</button>
                    </div>
                </div>
                <div class="card-body">
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab_nh_cookies">nhentai Cookie</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_ex_cookies">ExHentai Cookie</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_sys_settings">系统设置</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_blacklist_tags"><i class="fas fa-ban me-1 text-danger"></i>排除标签</button></li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="tab_nh_cookies">
                            <div class="mb-3">
                                <label class="form-label">cf_clearance</label>
                                <input type="text" class="form-control font-monospace" id="cookie_nh_cf_clearance" placeholder="可选：访问 nhentai.net 后从浏览器 DevTools → Application → Cookies 复制">
                                <div class="form-text">nhentai v2 API 为公开接口，无需 Cookie 即可访问。<br>如遇到 Cloudflare 拦截（403），可通过登录助手自动获取或在此填写 cf_clearance。</div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="tab_ex_cookies">
                            <div class="alert alert-success mb-3 py-2 small">
                                <i class="fas fa-paste me-1"></i>
                                在浏览器中通过 Cookie-Editor 插件导出后，将完整 Cookie 字符串粘贴到下方，点击「解析」自动填入。
                            </div>
                            <div class="input-group mb-3">
                                <input type="text" class="form-control form-control-sm font-monospace" id="cookie_ex_raw" placeholder="ipb_member_id=3000860;ipb_pass_hash=xxxx;sk=xxxx;..." onkeydown="if(event.key==='Enter')parseExCookieString()">
                                <button class="btn btn-sm btn-outline-success" onclick="parseExCookieString()"><i class="fas fa-wand-magic-invert me-1"></i>解析</button>
                            </div>
                            <hr class="my-3">
                            <div class="mb-3">
                                <label class="form-label">ipb_member_id <span class="text-danger">*</span></label>
                                <input type="text" class="form-control font-monospace" id="cookie_ex_ipb_member_id" placeholder="登录 exhentai 后的会员 ID">
                                <div class="form-text">从 exhentai.org 的 Cookie 中获取，登录后自动生成</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">ipb_pass_hash <span class="text-danger">*</span></label>
                                <input type="text" class="form-control font-monospace" id="cookie_ex_ipb_pass_hash" placeholder="登录 exhentai 后的密码哈希">
                                <div class="form-text">从 exhentai.org 的 Cookie 中获取，与 ipb_member_id 配对</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">cf_clearance <span class="text-muted">(可选)</span></label>
                                <input type="text" class="form-control font-monospace" id="cookie_ex_cf_clearance" placeholder="Cloudflare 放行 Cookie（如有 Cloudflare 拦载时填写）">
                                <div class="form-text">访问 exhentai.org 后浏览器自动生成，有效期有限。没有遇到 Cloudflare 拦载时可留空</div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="tab_sys_settings">
                            <h6 class="border-bottom pb-2 mb-3">下载设置</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label">下载路径</label>
                                    <input type="text" class="form-control" id="set_dl_path" placeholder="./downloads">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">最大并发数</label>
                                    <input type="number" class="form-control" id="set_dl_concurrent" min="1" max="10">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">重试次数</label>
                                    <input type="number" class="form-control" id="set_dl_retry" min="0" max="10">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">重试延迟 (秒)</label>
                                    <input type="number" class="form-control" id="set_dl_retry_delay" min="1" max="60">
                                </div>
                            </div>

                            <div class="border rounded p-3 mb-4 bg-light-subtle">
                                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                    <div>
                                        <div class="fw-semibold">WebP 图片压缩</div>
                                        <div class="small text-muted">用于新下载的正文图片和缓存封面，不改变分辨率。</div>
                                    </div>
                                    <div class="form-check form-switch mb-0">
                                        <input class="form-check-input" type="checkbox" role="switch" id="set_dl_webp_enabled" onchange="updateWebpSettingsState()">
                                        <label class="form-check-label" for="set_dl_webp_enabled">启用</label>
                                    </div>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label" for="set_dl_webp_quality">有损质量</label>
                                        <input type="number" class="form-control" id="set_dl_webp_quality" min="1" max="100" step="1">
                                        <div class="form-text">推荐 88；越高越接近原图，体积也越大。</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="set_dl_webp_method">编码强度</label>
                                        <input type="number" class="form-control" id="set_dl_webp_method" min="0" max="6" step="1">
                                        <div class="form-text">推荐 4；越高压缩稍好，但更占用 CPU。</div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label" for="set_dl_webp_min_savings">最低节省比例 (%)</label>
                                        <input type="number" class="form-control" id="set_dl_webp_min_savings" min="0" max="100" step="0.5">
                                        <div class="form-text">推荐 5；不足此比例时保留服务器返回格式。</div>
                                    </div>
                                </div>
                                <div class="alert alert-secondary py-2 px-3 mt-3 mb-0 small">
                                    已是 WebP 或动态图时不会二次有损；现有本地图片不会自动转换或删除。
                                </div>
                            </div>

                            <h6 class="border-bottom pb-2 mb-3">请求设置</h6>
                            <div class="row g-3 mb-4">
                                <div class="col-md-8">
                                    <label class="form-label">User-Agent</label>
                                    <input type="text" class="form-control font-monospace" id="set_req_ua">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">请求间隔 (秒)</label>
                                    <input type="number" class="form-control" id="set_req_delay" min="0.5" max="30" step="0.5">
                                </div>
                            </div>

                            <h6 class="border-bottom pb-2 mb-3">浏览器设置</h6>
                            <div class="row g-3">
                                <div class="col-md-4">
                                    <label class="form-label">无头模式 (Headless)</label>
                                    <select class="form-select" id="set_browser_headless">
                                        <option value="false">关闭 (显示浏览器)</option>
                                        <option value="true">开启 (无头模式)</option>
                                    </select>
                                    <div class="form-text">Ubuntu 服务器部署时建议开启</div>
                                </div>
                            </div>
                        </div>

                        <!-- 排除标签黑名单 -->
                        <div class="tab-pane fade" id="tab_blacklist_tags">
                            <div class="alert alert-warning py-2 small mb-3">
                                <i class="fas fa-triangle-exclamation me-1"></i>
                                命中下方任意 (类型, 值) 的图库会自动从 <strong>缓存浏览</strong> 与 <strong>已下载图库</strong> 中隐藏（画廊、tag 维度均生效）。
                            </div>
                            <div class="row g-2 align-items-end mb-3">
                                <div class="col-md-3">
                                    <label class="form-label small text-muted mb-1">类型 (tag_type)</label>
                                    <select id="bl_tag_type" class="form-select form-select-sm">
                                        <option value="*">* 通配（任意类型，慎用：值里所有词都会被匹配）</option>
                                        <option value="artist">artist 作者</option>
                                        <option value="group">group 社团</option>
                                        <option value="male">male</option>
                                        <option value="female">female</option>
                                        <option value="parody">parody 原作</option>
                                        <option value="character">character 角色</option>
                                        <option value="mixed">mixed</option>
                                        <option value="other">other</option>
                                        <option value="language">language 语言</option>
                                        <option value="category">category 分类</option>
                                        <option value="cosplayer">cosplayer</option>
                                        <option value="reclass">reclass</option>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label small text-muted mb-1">值 (tag_value, 支持空格多词，如 males only / 巨乳)</label>
                                    <input type="text" id="bl_tag_value" class="form-control form-control-sm" placeholder="如：males only  /  巨乳  /  someartist" onkeydown="if(event.key==='Enter')addBlacklistTag()">
                                </div>
                                <div class="col-md-3 d-flex gap-2">
                                    <button class="btn btn-sm btn-success flex-grow-1" onclick="addBlacklistTag()"><i class="fas fa-plus me-1"></i>加入排除</button>
                                    <button class="btn btn-sm btn-outline-secondary" onclick="loadBlacklistTags()"><i class="fas fa-rotate me-1"></i>刷新</button>
                                </div>
                            </div>
                            <div class="table-responsive">
                                <table class="table table-sm align-middle">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width:60px">#</th>
                                            <th style="width:160px">类型</th>
                                            <th>值</th>
                                            <th style="width:180px">加入时间</th>
                                            <th style="width:100px"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="bl_tags_tbody">
                                        <tr><td colspan="5" class="text-muted text-center py-4"><i class="fas fa-spinner fa-spin me-1"></i>加载中…</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        <strong>如何获取 Cookie？</strong><br>
                        1. 在 Chrome/Firefox 中访问对应网站并登录（exhentai 需要）<br>
                        2. 按 F12 打开开发者工具 → Application（或存储）→ Cookies<br>
                        3. 找到对应的网站域名，复制所需的 Cookie 值粘贴到上方<br>
                        4. 点击「保存」按钮。Cookie 会持久化到 config.yaml 文件
                    </div>
                </div>
            </div>

        </div>

        <!-- ═══ 2. 定时同步 ═══ -->
        <div id="page_sync" class="page-section section-hidden">

            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab_rt_list">定期刷新对象</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_rt_history">已结束任务</button></li>
            </ul>
            <div class="tab-content">
                <div class="tab-pane fade show active" id="tab_rt_list">
                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-arrows-rotate me-1"></i>定时同步</span>
                            <div class="d-flex gap-1">
                                <button class="btn btn-sm btn-outline-info" id="sync_fav_btn" onclick="syncFavoriteAuthors()" title="从远程收藏夹扫描，将作者批量注册为刷新对象（节流：列表×2+抖动、详情×3+抖动，每20本详情再批次间隔30~60s）"><i class="fas fa-cloud-arrow-down me-1"></i>从远程收藏同步作者刷新</button>
                                <button class="btn btn-sm btn-outline-danger d-none" id="sync_fav_cancel_btn" onclick="cancelSyncFavoriteAuthors()" title="终止当前同步任务（写 download_cancel.flag，约 1s 内生效）"><i class="fas fa-stop me-1"></i>终止同步</button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="loadRefreshTargets()" title="刷新列表"><i class="fas fa-sync"></i></button>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small">定时任务只会将已开启的项目加入普通爬取队列，由单一 worker 顺序执行。</p>
                            <div id="refresh_targets_list" class="text-muted">加载中...</div>
                            <div id="refresh_targets_pagination" class="mt-2"></div>
                            <div id="sync_fav_progress" class="d-none mt-3">
                                <div class="d-flex justify-content-between align-items-center small mb-1">
                                    <span id="sync_fav_stage" class="text-muted">准备中…</span>
                                    <span id="sync_fav_pct" class="text-muted fw-bold">0%</span>
                                </div>
                                <div class="progress" style="height:8px;background:#475569;border-radius:4px;overflow:hidden">
                                    <div id="sync_fav_bar" class="progress-bar bg-info" role="progressbar" style="width:0%;transition:width .3s"></div>
                                </div>
                                <div id="sync_fav_stats" class="d-flex flex-wrap gap-3 small text-muted mt-2"></div>
                            </div>
                            <div id="sync_fav_output" class="output-box mt-2"></div>
                        </div>
                    </div>
                </div>
                <div class="tab-pane fade" id="tab_rt_history">
                    <div class="card">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-clock-rotate-left me-1"></i>已结束的爬取任务</span>
                            <div class="d-flex gap-1">
                                <button class="btn btn-sm btn-outline-warning" onclick="clearCrawlHistory('failed')" title="清空失败/取消记录"><i class="fas fa-eraser me-1"></i>清空失败/取消</button>
                                <button class="btn btn-sm btn-outline-danger" onclick="clearCrawlHistory('all')" title="清空全部历史记录"><i class="fas fa-trash-alt me-1"></i>清空全部</button>
                                <button class="btn btn-sm btn-outline-secondary" onclick="loadCrawlHistory()" title="刷新历史"><i class="fas fa-sync"></i></button>
                            </div>
                        </div>
                        <div class="card-body">
                            <div id="crawl_history_list" class="text-muted">加载中...</div>
                            <div id="crawl_history_pagination" class="mt-2"></div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ═══ 3. 手动爬取（原 page_cache 上半）═══ -->
        <div id="page_crawl" class="page-section section-hidden">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="fas fa-cloud-download-alt me-1"></i>手动爬取</span>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-secondary" onclick="loadCrawlQueue()"><i class="fas fa-sync me-1"></i>刷新队列</button>
                    </div>
                </div>
                <div class="card-body border-bottom bg-light py-2">
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-md-5">
                            <label class="form-label small mb-1 text-info"><i class="fas fa-cloud-download-alt me-1"></i>爬取关键词（ExHentai 语法）</label>
                            <input type="text" class="form-control form-control-sm" id="crawl_keyword" placeholder='例如 artist:"iruma kamiri$" 或 tags' onkeydown="if(event.key==='Enter')startCrawl()">
                        </div>
                        <div class="col-md-1">
                            <button class="btn btn-sm btn-outline-info w-100" onclick="startCrawl()" title="使用关键词+分类爬取 ExHentai"><i class="fas fa-cloud-download-alt"></i></button>
                        </div>
                        <div class="col-md-1">
                            <button class="btn btn-sm btn-outline-success w-100" onclick="saveSearchPreset()" title="保存当前检索条件"><i class="fas fa-bookmark"></i></button>
                        </div>
                        <div class="col-md-1 d-flex align-items-center justify-content-center">
                            <button class="btn btn-sm btn-outline-warning w-100" id="crawl_force" onclick="this.classList.toggle('active')">强制</button>
                        </div>
                        <div class="col-md-1 d-flex align-items-center justify-content-center">
                            <button class="btn btn-sm btn-outline-secondary w-100" onclick="clearCrawlLog()" title="清理工作文件"><i class="fas fa-trash-alt"></i></button>
                        </div>
                    </div>
                    <div class="mb-1">
                        <span class="small text-info me-2"><i class="fas fa-cloud-download-alt me-1"></i>爬取分类:</span>
                        <div id="crawl_category_tags" class="d-inline-flex flex-wrap gap-1 align-middle">
                            <button class="cat-tag active" data-cat="all" onclick="crawlToggleCategory(this)" style="--cat-color:#0d6efd">全部</button>
                            <button class="cat-tag" data-cat="Doujinshi" onclick="crawlToggleCategory(this)" style="--cat-color:#e74c3c">Doujinshi</button>
                            <button class="cat-tag" data-cat="Manga" onclick="crawlToggleCategory(this)" style="--cat-color:#3498db">Manga</button>
                            <button class="cat-tag" data-cat="Artist CG" onclick="crawlToggleCategory(this)" style="--cat-color:#9b59b6">Artist CG</button>
                            <button class="cat-tag" data-cat="Game CG" onclick="crawlToggleCategory(this)" style="--cat-color:#e67e22">Game CG</button>
                            <button class="cat-tag" data-cat="Western" onclick="crawlToggleCategory(this)" style="--cat-color:#27ae60">Western</button>
                            <button class="cat-tag" data-cat="Non-H" onclick="crawlToggleCategory(this)" style="--cat-color:#95a5a6">Non-H</button>
                            <button class="cat-tag" data-cat="Image Set" onclick="crawlToggleCategory(this)" style="--cat-color:#1abc9c">Image Set</button>
                            <button class="cat-tag" data-cat="Cosplay" onclick="crawlToggleCategory(this)" style="--cat-color:#e91e63">Cosplay</button>
                            <button class="cat-tag" data-cat="Asian Porn" onclick="crawlToggleCategory(this)" style="--cat-color:#795548">Asian Porn</button>
                            <button class="cat-tag" data-cat="Misc" onclick="crawlToggleCategory(this)" style="--cat-color:#607d8b">Misc</button>
                        </div>
                    </div>
                    <div class="mb-1">
                        <span class="small text-info me-2">爬取语言:</span>
                        <div id="crawl_language_tags" class="d-inline-flex flex-wrap gap-1 align-middle">
                            <button class="cat-tag active" data-lang="all" onclick="crawlToggleLanguage(this)" style="--cat-color:#0d6efd">全部</button>
                        </div>
                    </div>
                    <div class="mt-1" id="saved_presets_section">
                        <div class="bookmark-header" onclick="toggleBookmarkCollapse()">
                            <span><i class="far fa-bookmark me-1"></i>书签 <span id="bookmark_count" class="badge bg-secondary">0</span></span>
                            <i class="fas fa-chevron-up" id="bookmark_collapse_icon"></i>
                        </div>
                        <div id="saved_presets_row" class="bookmark-content"></div>
                    </div>
                    <div class="mt-2" id="crawl_queue_panel">
                        <div class="d-flex justify-content-between align-items-center mb-1"><span class="small text-info"><i class="fas fa-list-ol me-1"></i>爬取队列</span><button class="btn btn-sm btn-outline-secondary py-0 px-1" onclick="loadCrawlQueue()" title="刷新队列"><i class="fas fa-sync"></i></button></div>
                        <div id="crawl_queue_list" class="small text-muted">暂无任务</div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="small text-muted mb-2"><i class="fas fa-terminal me-1"></i>爬取日志输出</div>
                    <div id="crawl_output" class="output-box"></div>
                </div>
            </div>
        </div>

        <!-- ═══ 4. 下载中心 ═══ -->
        <div id="page_download" class="page-section section-hidden">

            <!-- 单一下载 -->
            <div class="card mb-3">
                <div class="card-header">单一下载</div>
                <div class="card-body">
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab_dl_url">URL 下载</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_dl_id">ID 下载</button></li>
                    </ul>
                    <div class="tab-content">
                        <div class="tab-pane fade show active" id="tab_dl_url">
                            <div class="input-group mb-2">
                                <input type="text" class="form-control" id="dl_url" placeholder="https://nhentai.net/g/177013/ 或 https://exhentai.org/g/1234567/abc123/">
                                <button class="btn btn-primary" onclick="doDownload()"><i class="fas fa-download me-1"></i>下载</button>
                            </div>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="dl_force_url">
                                <label class="form-check-label small text-danger" for="dl_force_url">
                                    <i class="fas fa-exclamation-triangle me-1"></i>强制重新下载（清空本地文件 + 覆盖数据库记录）
                                </label>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="tab_dl_id">
                            <div class="row g-2 align-items-end">
                                <div class="col-md-3">
                                    <label class="form-label">站点</label>
                                    <select class="form-select" id="dl_source">
                                        <option value="nhentai">nhentai</option>
                                        <option value="exhentai">exhentai</option>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">ID</label>
                                    <input type="text" class="form-control" id="dl_id" placeholder="如 177013">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">exhentai GID</label>
                                    <input type="text" class="form-control" id="dl_gid" placeholder="URL 中的数字部分">
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label">exhentai Token</label>
                                    <input type="text" class="form-control" id="dl_token" placeholder="URL 中的 hash 部分">
                                </div>
                                <div class="col-12 mt-2">
                                    <button class="btn btn-primary" onclick="doDownloadById()"><i class="fas fa-download me-1"></i>下载</button>
                                </div>
                                <div class="col-12 mt-1">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" id="dl_force_id">
                                        <label class="form-check-label small text-danger" for="dl_force_id">
                                            <i class="fas fa-exclamation-triangle me-1"></i>强制重新下载（清空本地文件 + 覆盖数据库记录）
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div id="dl_progress" class="progress mb-2" style="height:8px;display:none">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="dl_progress_bar" style="width:0%"></div>
                    </div>
                    <div id="dl_output" class="output-box"></div>
                </div>
            </div>

            <!-- 批量下载 -->
            <div class="card mb-3">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>批量下载</span>
                    <button class="btn btn-sm btn-primary" id="batch_download_btn" onclick="doBatchDownload()"><i class="fas fa-play me-1"></i>开始批量下载</button>
                </div>
                <div class="card-body">
                    <div class="mb-2">
                        <label class="form-label">每行一个 URL</label>
                        <textarea class="form-control" id="batch_urls" rows="5" placeholder="https://nhentai.net/g/177013/&#10;https://exhentai.org/g/1234567/abc123/&#10;https://nhentai.net/g/238480/"></textarea>
                    </div>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="batch_force">
                        <label class="form-check-label small text-danger" for="batch_force">
                            <i class="fas fa-exclamation-triangle me-1"></i>强制重新下载全部（清空本地文件 + 覆盖数据库记录）
                        </label>
                    </div>
                    <div id="batch_progress_list" class="mb-2" style="display:none"></div>
                    <div id="batch_output" class="output-box"></div>
                </div>
            </div>

            <!-- 重新下载 / 目录恢复 -->
            <div class="card mb-3">
                <div class="card-header">重新下载 / 目录恢复</div>
                <div class="card-body">
                    <p class="mb-2 text-muted">重新尝试下载之前未完成的画廊（数据库标记为 is_complete=0 的记录）。</p>
                    <div class="d-flex align-items-center gap-3 mb-2 flex-wrap">
                        <label class="form-check-label d-flex align-items-center gap-1" style="cursor:pointer">
                            <input type="checkbox" id="retry_skip_existing" class="form-check-input mt-0" checked> 跳过已下载图片
                        </label>
                        <button class="btn btn-warning" id="retry_btn" onclick="doRetry()"><i class="fas fa-redo me-1"></i>重试未完成下载</button>
                        <button class="btn btn-outline-warning" onclick="recoverOrphans()" title="扫描下载目录恢复到数据库"><i class="fas fa-ambulance me-1"></i>恢复孤儿目录</button>
                    </div>
                    <div id="retry_progress_list" class="mt-2 mb-2" style="display:none"></div>
                    <div id="retry_output" class="output-box"></div>
                </div>
            </div>

        </div>

        <!-- ═══ 5. 图库 ═══ -->
        <div id="page_gallery" class="page-section section-hidden">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span>图库</span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary" onclick="loadGalleries()">全部</button>
                        <button class="btn btn-outline-danger" onclick="loadGalleries({source:'nhentai'})">nhentai</button>
                        <button class="btn btn-outline-info" onclick="loadGalleries({source:'exhentai'})">exhentai</button>
                        <button class="btn btn-outline-primary" onclick="loadGalleries()"><i class="fas fa-sync"></i></button>
                    </div>
                </div>
                <div class="card-body border-bottom bg-light py-2">
                    <div class="row g-2 align-items-end">
                        <div class="col-md-3">
                            <label class="form-label small mb-1">标签 (逗号分隔)</label>
                            <input type="text" class="form-control form-control-sm" id="gallery_tag_filter" placeholder="english, shindol" onkeydown="if(event.key==='Enter')applyGalleryFilter()">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">匹配模式</label>
                            <select class="form-select form-select-sm" id="gallery_tag_mode">
                                <option value="any">任意 (OR)</option>
                                <option value="all">全部 (AND)</option>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">作者</label>
                            <input type="text" class="form-control form-control-sm" id="gallery_artist_filter" placeholder="artist name" onkeydown="if(event.key==='Enter')applyGalleryFilter()">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label small mb-1">语言</label>
                            <input type="text" class="form-control form-control-sm" id="gallery_lang_filter" placeholder="english" onkeydown="if(event.key==='Enter')applyGalleryFilter()">
                        </div>
                        <div class="col-md-1">
                            <button class="btn btn-sm btn-outline-primary w-100" onclick="applyGalleryFilter()" title="筛选"><i class="fas fa-filter"></i></button>
                        </div>
                        <div class="col-md-1">
                            <button class="btn btn-sm btn-outline-secondary w-100" onclick="clearGalleryFilter()" title="清空筛选"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                </div>
                <div class="card-body" id="gallery_grid_body">
                    <div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</div>
                </div>
                <div class="card-footer" id="gallery_pagination"></div>
            </div>
        </div>

        <!-- ═══ 6. 缓存（本地浏览；原 page_cache 下半）═══ -->
        <div id="page_cache" class="page-section section-hidden">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span><i class="fas fa-database me-1"></i>缓存浏览</span>
                    <button class="btn btn-sm btn-outline-secondary" onclick="loadCachePage(1)"><i class="fas fa-sync"></i></button>
                </div>
                <div class="card-body border-bottom bg-light py-2">
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-md-2">
                            <label class="form-label small mb-1 text-muted"><i class="fas fa-crosshairs me-1"></i>检索范围</label>
                            <select id="cache_search_scope" class="form-select form-select-sm">
                                <option value="all" selected>全部</option>
                                <option value="title">标题</option>
                                <option value="author">作者</option>
                                <option value="tags">标签</option>
                                <option value="tags_cn">中文标签</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label small mb-1 text-muted"><i class="fas fa-filter me-1"></i>本地检索</label>
                            <input type="text" class="form-control form-control-sm" id="cache_keyword" placeholder="搜索关键词" onkeydown="if(event.key==='Enter')cacheSearch()">
                        </div>
                        <div class="col-md-1">
                            <button class="btn btn-sm btn-outline-primary w-100" onclick="cacheSearch()" title="筛选"><i class="fas fa-filter"></i></button>
                        </div>
                        <div class="col-md-1">
                            <button class="btn btn-sm btn-outline-secondary w-100" onclick="cacheClearFilter()" title="清空"><i class="fas fa-times"></i></button>
                        </div>
                    </div>
                    <div>
                        <span class="small text-muted me-2">分类:</span>
                        <div id="cache_category_tags" class="d-inline-flex flex-wrap gap-1 align-middle">
                            <button class="cat-tag active" data-cat="all" onclick="cacheToggleCategory(this)" style="--cat-color:#0d6efd">全部</button>
                            <button class="cat-tag" data-cat="Doujinshi" onclick="cacheToggleCategory(this)" style="--cat-color:#e74c3c">Doujinshi</button>
                            <button class="cat-tag" data-cat="Manga" onclick="cacheToggleCategory(this)" style="--cat-color:#3498db">Manga</button>
                            <button class="cat-tag" data-cat="Artist CG" onclick="cacheToggleCategory(this)" style="--cat-color:#9b59b6">Artist CG</button>
                            <button class="cat-tag" data-cat="Game CG" onclick="cacheToggleCategory(this)" style="--cat-color:#e67e22">Game CG</button>
                            <button class="cat-tag" data-cat="Western" onclick="cacheToggleCategory(this)" style="--cat-color:#27ae60">Western</button>
                            <button class="cat-tag" data-cat="Non-H" onclick="cacheToggleCategory(this)" style="--cat-color:#95a5a6">Non-H</button>
                            <button class="cat-tag" data-cat="Image Set" onclick="cacheToggleCategory(this)" style="--cat-color:#1abc9c">Image Set</button>
                            <button class="cat-tag" data-cat="Cosplay" onclick="cacheToggleCategory(this)" style="--cat-color:#e91e63">Cosplay</button>
                            <button class="cat-tag" data-cat="Asian Porn" onclick="cacheToggleCategory(this)" style="--cat-color:#795548">Asian Porn</button>
                            <button class="cat-tag" data-cat="Misc" onclick="cacheToggleCategory(this)" style="--cat-color:#607d8b">Misc</button>
                        </div>
                    </div>
                    <div class="mt-1">
                        <span class="small text-muted me-2">语言:</span>
                        <div id="cache_language_tags" class="d-inline-flex flex-wrap gap-1 align-middle">
                            <button class="cat-tag active" data-lang="all" onclick="cacheToggleLanguage(this)" style="--cat-color:#0d6efd">全部</button>
                        </div>
                    </div>
                    <div class="mt-1" id="cache_batch_bar"></div>
                </div>
                <div class="card-body" id="cache_grid_body">
                    <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</div>
                </div>
                <div class="card-footer" id="cache_pagination"></div>
            </div>

            <!-- 作品详情弹窗 -->
            <div class="modal fade" id="cache_detail_modal" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title text-truncate" id="cache_detail_title">作品详情</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body" id="cache_detail_body">
                            <div class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">关闭</button>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ═══ 7. 工具 & 维护 ═══ -->
        <div id="page_tools" class="page-section section-hidden">

            <ul class="nav nav-tabs mb-3" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab_tools_dash">仪表盘</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_tools_verify">校对</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_tools_compression"><i class="fas fa-compress me-1"></i>图片压缩</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_tools_export">数据导出</button></li>
            </ul>
            <div class="tab-content">

                <!-- 仪表盘 -->
                <div class="tab-pane fade show active" id="tab_tools_dash">
                    <div class="row g-3 mb-3" id="stats_cards">
                        <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_galleries">-</div><div class="stat-label">本地画廊总数</div></div></div>
                        <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_db">-</div><div class="stat-label">数据库状态</div></div></div>
                        <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_venv">-</div><div class="stat-label">虚拟环境</div></div></div>
                        <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_config">-</div><div class="stat-label">配置文件</div></div></div>

                        <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_cache_count">-</div><div class="stat-label"><i class="fas fa-database me-1"></i>本地缓存数量</div></div></div>
                        <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_db_size">-</div><div class="stat-label"><i class="fas fa-hdd me-1"></i>DB 占用容量</div></div></div>
                        <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_thumbs_size">-</div><div class="stat-label"><i class="fas fa-images me-1"></i>封面占用容量</div></div></div>
                        <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_downloads_size">-</div><div class="stat-label"><i class="fas fa-download me-1"></i>下载漫画占用容量</div></div></div>
                    </div>
                    <div class="card mb-3">
                        <div class="card-header">快捷操作</div>
                        <div class="card-body">
                            <div class="row g-2">
                                <div class="col-md-4">
                                    <div class="d-grid"><button class="btn btn-outline-primary" onclick="switchPage('download')"><i class="fas fa-download me-1"></i>下载画廊</button></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="d-grid"><button class="btn btn-outline-success" onclick="switchPage('settings')"><i class="fas fa-cookie-bite me-1"></i>配置 Cookie</button></div>
                                </div>
                                <div class="col-md-4">
                                    <div class="d-grid"><button class="btn btn-outline-info" onclick="switchPage('gallery')"><i class="fas fa-images me-1"></i>浏览图库</button></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="card">
                        <div class="card-header">关于 ehLib</div>
                        <div class="card-body">
                            <p class="mb-1">ehLib 是一个命令行工具，用于从 <strong>nhentai</strong> 和 <strong>exhentai</strong> 下载漫画画廊，并完整提取标签元数据存储到本地 SQLite 数据库中。</p>
                            <p class="mb-0 small text-muted">项目路径: <?= realpath(__DIR__ . '/..') ?></p>
                        </div>
                    </div>
                </div>

                <!-- 校对 -->
                <div class="tab-pane fade" id="tab_tools_verify">
                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-check-double me-1"></i>批量校对</span>
                            <div>
                                <button class="btn btn-sm btn-outline-secondary" onclick="pollVerifyStatus()" title="刷新校对状态"><i class="fas fa-sync"></i></button>
                                <button class="btn btn-sm btn-outline-info" id="verify_start_btn" onclick="startVerify()"><i class="fas fa-check-double me-1"></i>开始校对</button>
                                <button class="btn btn-sm btn-outline-danger d-none" id="verify_stop_btn" onclick="stopVerify()"><i class="fas fa-stop me-1"></i>终止校对</button>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="text-muted small mb-2">对比 ExHentai 与本地缓存的一致性，自动修复元数据并重新下载封面。</p>
                            <div id="verify_progress" class="output-box"></div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header"><i class="fas fa-search me-1"></i>单本校对</div>
                        <div class="card-body">
                            <div class="row g-3">
                                <div class="col-lg-8 col-md-10">
                                    <label class="form-label" for="tv_search_input">搜索画廊</label>
                                    <div class="position-relative">
                                        <div class="input-group">
                                            <input type="text" class="form-control" id="tv_search_input" placeholder="输入标题、作者或 ID 检索..." autocomplete="off">
                                            <button class="btn btn-primary" id="tv_verify_btn" onclick="tvVerify()" disabled>
                                                <i class="fas fa-play me-1"></i>开始校对
                                            </button>
                                        </div>
                                        <div id="tv_search_results" class="list-group verify-search-results" style="display:none"></div>
                                    </div>
                                </div>
                            </div>
                            <div id="tv_result" class="output-box mt-3"></div>
                        </div>
                    </div>
                </div>

                <!-- 图片压缩 -->
                <div class="tab-pane fade" id="tab_tools_compression">
                    <div class="row g-2 mb-3" id="compression_stats">
                        <div class="col-6 col-lg"><div class="card stat-card"><div class="stat-value" id="compress_stat_total">-</div><div class="stat-label">可压缩漫画</div></div></div>
                        <div class="col-6 col-lg"><div class="card stat-card"><div class="stat-value text-secondary" id="compress_stat_not_started">-</div><div class="stat-label">尚未压缩</div></div></div>
                        <div class="col-6 col-lg"><div class="card stat-card"><div class="stat-value text-info" id="compress_stat_running">-</div><div class="stat-label">排队 / 运行中</div></div></div>
                        <div class="col-6 col-lg"><div class="card stat-card"><div class="stat-value text-warning" id="compress_stat_review">-</div><div class="stat-label">等待审核</div></div></div>
                        <div class="col-6 col-lg"><div class="card stat-card"><div class="stat-value text-danger" id="compress_stat_failed">-</div><div class="stat-label">失败</div></div></div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-header"><i class="fas fa-play-circle me-1"></i>手动发起单本压缩</div>
                        <div class="card-body">
                            <div class="row g-3 align-items-end">
                                <div class="col-lg-4">
                                    <label class="form-label" for="compress_gallery_id">Gallery ID</label>
                                    <input type="number" min="1" class="form-control" id="compress_gallery_id" placeholder="从下方列表选择，或直接输入 ID">
                                </div>
                                <div class="col-6 col-lg-2">
                                    <label class="form-label" for="compress_quality">Quality</label>
                                    <input type="number" min="1" max="100" value="88" class="form-control" id="compress_quality">
                                </div>
                                <div class="col-6 col-lg-2">
                                    <label class="form-label" for="compress_method">Method</label>
                                    <select class="form-select" id="compress_method">
                                        <option value="0">0（最快）</option><option value="1">1</option><option value="2">2</option>
                                        <option value="3">3</option><option value="4" selected>4（默认）</option><option value="5">5</option><option value="6">6（最慢）</option>
                                    </select>
                                </div>
                                <div class="col-6 col-lg-2">
                                    <label class="form-label" for="compress_min_savings">最小节省率 %</label>
                                    <input type="number" min="0" max="100" step="0.1" value="5" class="form-control" id="compress_min_savings">
                                </div>
                                <div class="col-6 col-lg-2 d-grid">
                                    <button class="btn btn-primary" id="compress_start_btn" onclick="startCompressionTask()"><i class="fas fa-play me-1"></i>开始压缩</button>
                                </div>
                                <div class="col-12">
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox" id="compress_force_candidates">
                                        <label class="form-check-label" for="compress_force_candidates">忽略最小节省率，保留所有变小候选（相等或增大的结果仍会丢弃；不修改原图）</label>
                                    </div>
                                    <div class="small text-muted mt-1" id="compress_selected_hint">尚未选择漫画。</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="card mb-3">
                        <div class="card-header d-flex justify-content-between align-items-center">
                            <span><i class="fas fa-bars-progress me-1"></i>任务进度</span>
                            <button class="btn btn-sm btn-outline-secondary" onclick="loadCompressionPage()" title="立即刷新"><i class="fas fa-sync"></i></button>
                        </div>
                        <div class="card-body" id="compression_active_tasks">
                            <div class="text-muted small">当前没有运行中的压缩任务。</div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-header">
                            <div class="row g-2 align-items-center">
                                <div class="col-md-7"><div class="input-group input-group-sm"><span class="input-group-text"><i class="fas fa-search"></i></span><input class="form-control" id="compress_search" placeholder="搜索标题、作者、source_id 或 Gallery ID" onkeydown="if(event.key==='Enter'){compressionApplyFilter()}"><button class="btn btn-outline-primary" onclick="compressionApplyFilter()">搜索</button></div></div>
                                <div class="col-md-3"><select class="form-select form-select-sm" id="compress_status_filter" onchange="compressionApplyFilter()"><option value="all">全部状态</option><option value="not_started">尚未压缩</option><option value="queued">已排队</option><option value="compressing">压缩中</option><option value="user_review_required">等待审核</option><option value="failed">失败</option><option value="approved_pending_apply">已批准待应用</option><option value="skipped">已跳过</option></select></div>
                                <div class="col-md-2 text-md-end"><span class="small text-muted" id="compression_result_count"></span></div>
                            </div>
                        </div>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle mb-0 compression-table">
                                <thead><tr><th>ID / 来源</th><th>标题</th><th>页数</th><th>状态</th><th>候选 / 节省率</th><th>更新时间</th><th class="text-end">操作</th></tr></thead>
                                <tbody id="compression_table_body"><tr><td colspan="7" class="text-center text-muted py-4">加载中…</td></tr></tbody>
                            </table>
                        </div>
                        <div class="card-footer" id="compression_pagination"></div>
                    </div>
                </div>

                <!-- 数据导出 -->
                <div class="tab-pane fade" id="tab_tools_export">
                    <div class="card">
                        <div class="card-header">导出数据</div>
                        <div class="card-body">
                            <p class="text-muted">导出为 ZIP 包，包含元数据 JSON、数据库文件、所有封面图（不含已下载漫画）。</p>
                            <button class="btn btn-primary" onclick="doExport()"><i class="fas fa-file-export me-1"></i>导出 ZIP</button>
                            <div id="export_output" class="output-box"></div>
                            <div id="export_table_wrapper" style="display:none" class="mt-3">
                                <div class="table-responsive" style="max-height:400px;overflow-y:auto">
                                    <table class="table table-sm table-striped">
                                        <thead class="table-light"><tr><th>来源</th><th>ID</th><th>标题</th><th>作者</th><th>页数</th></tr></thead>
                                        <tbody id="export_table_body"></tbody>
                                    </table>
                                </div>
                                <div class="mt-2">
                                    <span class="text-muted small" id="export_count"></span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
