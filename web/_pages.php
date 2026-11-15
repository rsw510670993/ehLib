<?php
/**
 * ehLib 新前端 · 系统设置 与 工具&维护（重写版）
 * 契约：所有 id 与 onclick 与原版一致；tab 仍走 Bootstrap JS 的 data-bs-toggle，仅换样式。
 */
$projectPath = realpath(__DIR__ . '/..');
?>
        <!-- ═══ 系统设置（重写） ═══ -->
        <div id="page_settings" class="page-section">

            <div class="card">
                <div class="card-head">
                    <h2>Cookie &amp; 系统设置<span class="hint">保存后写入 config.yaml</span></h2>
                    <div class="actions">
                        <button class="btn ghost sm" type="button" onclick="saveCookies()"><i class="fas fa-floppy-disk"></i><span>保存 Cookie</span></button>
                        <button class="btn primary sm" type="button" onclick="saveSettings()"><i class="fas fa-floppy-disk"></i><span>保存设置</span></button>
                    </div>
                </div>

                <div class="card-body">
                    <ul class="nav nav-tabs tabs" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab_nh_cookies">nhentai Cookie</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_ex_cookies">ExHentai Cookie</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_sys_settings">系统设置</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_blacklist_tags"><i class="fas fa-ban me-1"></i>排除标签</button></li>
                    </ul>

                    <div class="tab-content">

                        <!-- nhentai Cookie -->
                        <div class="tab-pane fade show active" id="tab_nh_cookies">
                            <div class="form-grid">
                                <label class="field span-all">
                                    <span class="field-label">cf_clearance</span>
                                    <input type="text" class="input" id="cookie_nh_cf_clearance" placeholder="可选：访问 nhentai.net 后从浏览器 DevTools → Application → Cookies 复制">
                                    <span class="panel-note" style="margin:6px 0 0">nhentai v2 API 为公开接口，无需 Cookie 即可访问。如遇到 Cloudflare 拦截（403），可通过登录助手自动获取或在此填写 cf_clearance。</span>
                                </label>
                            </div>
                        </div>

                        <!-- ExHentai Cookie -->
                        <div class="tab-pane fade" id="tab_ex_cookies">
                            <p class="panel-note"><i class="fas fa-paste me-1"></i>在浏览器中用 Cookie-Editor 插件导出完整 Cookie 字符串，粘贴到下方点「解析」自动填入。</p>
                            <div class="form-row" style="margin-bottom:14px">
                                <input type="text" class="input grow" id="cookie_ex_raw" placeholder="ipb_member_id=3000860;ipb_pass_hash=xxxx;sk=xxxx;..."
                                       onkeydown="if(event.key==='Enter')parseExCookieString()">
                                <button class="btn ghost sm" type="button" onclick="parseExCookieString()"><i class="fas fa-wand-magic-sparkles"></i><span>解析</span></button>
                            </div>

                            <div class="form-grid">
                                <label class="field">
                                    <span class="field-label">ipb_member_id <span class="text-danger">*</span></span>
                                    <input type="text" class="input mono" id="cookie_ex_ipb_member_id" placeholder="登录 exhentai 后的会员 ID">
                                </label>
                                <label class="field">
                                    <span class="field-label">ipb_pass_hash <span class="text-danger">*</span></span>
                                    <input type="text" class="input mono" id="cookie_ex_ipb_pass_hash" placeholder="登录 exhentai 后的密码哈希">
                                </label>
                                <label class="field">
                                    <span class="field-label">cf_clearance（可选）</span>
                                    <input type="text" class="input mono" id="cookie_ex_cf_clearance" placeholder="Cloudflare 放行 Cookie">
                                </label>
                            </div>
                        </div>

                        <!-- 系统设置 -->
                        <div class="tab-pane fade" id="tab_sys_settings">
                            <h3 class="section-title">下载设置</h3>
                            <div class="form-grid cols-4">
                                <label class="field">
                                    <span class="field-label">下载路径</span>
                                    <input type="text" class="input" id="set_dl_path" placeholder="./downloads">
                                </label>
                                <label class="field">
                                    <span class="field-label">最大并发数</span>
                                    <input type="number" class="input" id="set_dl_concurrent" min="1" max="10">
                                </label>
                                <label class="field">
                                    <span class="field-label">重试次数</span>
                                    <input type="number" class="input" id="set_dl_retry" min="0" max="10">
                                </label>
                                <label class="field">
                                    <span class="field-label">重试延迟（秒）</span>
                                    <input type="number" class="input" id="set_dl_retry_delay" min="1" max="60">
                                </label>
                            </div>

                            <div class="subpanel">
                                <div class="subpanel-head">
                                    <div>
                                        <div class="subpanel-title">AVIF 图片压缩</div>
                                        <div class="panel-note" style="margin:2px 0 0">用于新下载的正文图片和缓存封面，不改变分辨率。</div>
                                    </div>
                                    <label class="switch">
                                        <input class="form-check-input" type="checkbox" role="switch" id="set_dl_avif_enabled" onchange="updateAvifSettingsState()">
                                        <span>启用</span>
                                    </label>
                                </div>
                                <div class="form-grid">
                                    <label class="field">
                                        <span class="field-label">有损质量</span>
                                        <input type="number" class="input" id="set_dl_avif_quality" min="1" max="100" step="1">
                                        <span class="panel-note" style="margin:4px 0 0">默认 65；越高越接近原图，体积也越大。</span>
                                    </label>
                                    <label class="field">
                                        <span class="field-label">编码速度</span>
                                        <input type="number" class="input" id="set_dl_avif_speed" min="0" max="10" step="1">
                                        <span class="panel-note" style="margin:4px 0 0">默认 8；越低压缩更慢，可能得到更小体积。</span>
                                    </label>
                                    <label class="field">
                                        <span class="field-label">最低节省比例（%）</span>
                                        <input type="number" class="input" id="set_dl_avif_min_savings" min="0" max="100" step="0.5">
                                        <span class="panel-note" style="margin:4px 0 0">推荐 5；不足此比例时保留服务器返回格式。</span>
                                    </label>
                                </div>
                                <p class="panel-note" style="margin:12px 0 0">JPEG、PNG、WebP 会尝试转为 AVIF；已是 AVIF 或动态图时不会二次有损。现有本地图片不会自动转换或删除。</p>
                            </div>

                            <h3 class="section-title">请求设置</h3>
                            <div class="form-grid">
                                <label class="field" style="grid-column:span 2">
                                    <span class="field-label">User-Agent</span>
                                    <input type="text" class="input mono" id="set_req_ua">
                                </label>
                                <label class="field">
                                    <span class="field-label">请求间隔（秒）</span>
                                    <input type="number" class="input" id="set_req_delay" min="0.5" max="30" step="0.5">
                                </label>
                            </div>

                            <h3 class="section-title">浏览器设置</h3>
                            <div class="form-grid">
                                <label class="field">
                                    <span class="field-label">无头模式（Headless）</span>
                                    <select class="input" id="set_browser_headless">
                                        <option value="false">关闭（显示浏览器）</option>
                                        <option value="true">开启（无头模式）</option>
                                    </select>
                                    <span class="panel-note" style="margin:4px 0 0">服务器部署时建议开启</span>
                                </label>
                            </div>
                        </div>

                        <!-- 排除标签 -->
                        <div class="tab-pane fade" id="tab_blacklist_tags">
                            <p class="panel-note"><i class="fas fa-triangle-exclamation me-1"></i>命中下方任意（类型, 值）的图库会自动从<strong>缓存浏览</strong>与<strong>已下载图库</strong>中隐藏（画廊、tag 维度均生效）。</p>
                            <div class="form-grid cols-4" style="margin-bottom:14px">
                                <label class="field">
                                    <span class="field-label">类型（tag_type）</span>
                                    <select id="bl_tag_type" class="input">
                                        <option value="*">* 通配（任意类型，慎用）</option>
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
                                </label>
                                <label class="field" style="grid-column:span 2">
                                    <span class="field-label">值（支持空格多词，如 males only / 巨乳）</span>
                                    <input type="text" id="bl_tag_value" class="input" placeholder="如：males only / 巨乳 / someartist"
                                           onkeydown="if(event.key==='Enter')addBlacklistTag()">
                                </label>
                                <div class="actions" style="align-self:end">
                                    <button class="btn primary sm" type="button" onclick="addBlacklistTag()"><i class="fas fa-plus"></i><span>加入排除</span></button>
                                    <button class="btn ghost sm" type="button" onclick="loadBlacklistTags()"><i class="fas fa-rotate"></i></button>
                                </div>
                            </div>
                            <div class="table-wrap">
                                <table class="table">
                                    <thead>
                                        <tr><th style="width:60px">#</th><th style="width:160px">类型</th><th>值</th><th style="width:170px">加入时间</th><th style="width:80px"></th></tr>
                                    </thead>
                                    <tbody id="bl_tags_tbody">
                                        <tr><td colspan="5" class="muted-cell"><i class="fas fa-spinner fa-spin me-1"></i>加载中…</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="note-block">
                        <strong>如何获取 Cookie？</strong>
                        <ol>
                            <li>在 Chrome/Firefox 中访问对应网站并登录（exhentai 需要）</li>
                            <li>按 F12 打开开发者工具 → Application（或存储）→ Cookies</li>
                            <li>找到对应的网站域名，复制所需的 Cookie 值粘贴到上方</li>
                            <li>点击「保存」按钮，Cookie 会持久化到 config.yaml</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ 工具 &amp; 维护（重写） ═══ -->
        <div id="page_tools" class="page-section section-hidden">

            <ul class="nav nav-tabs tabs" role="tablist">
                <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab_tools_dash">仪表盘</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_tools_verify">校对</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_tools_compression"><i class="fas fa-compress me-1"></i>图片压缩</button></li>
                <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_tools_export">数据导出</button></li>
            </ul>

            <div class="tab-content">

                <!-- 仪表盘 -->
                <div class="tab-pane fade show active" id="tab_tools_dash">
                    <div class="stat-grid" id="stats_cards">
                        <div class="stat-cell"><div class="stat-value" id="stat_galleries">-</div><div class="stat-label">本地画廊总数</div></div>
                        <div class="stat-cell"><div class="stat-value" id="stat_db">-</div><div class="stat-label">数据库状态</div></div>
                        <div class="stat-cell"><div class="stat-value" id="stat_venv">-</div><div class="stat-label">虚拟环境</div></div>
                        <div class="stat-cell"><div class="stat-value" id="stat_config">-</div><div class="stat-label">配置文件</div></div>
                        <div class="stat-cell"><div class="stat-value" id="stat_cache_count">-</div><div class="stat-label"><i class="fas fa-database"></i>本地缓存数量</div></div>
                        <div class="stat-cell"><div class="stat-value" id="stat_db_size">-</div><div class="stat-label"><i class="fas fa-hard-drive"></i>DB 占用容量</div></div>
                        <div class="stat-cell"><div class="stat-value" id="stat_thumbs_size">-</div><div class="stat-label"><i class="fas fa-images"></i>封面占用容量</div></div>
                        <div class="stat-cell"><div class="stat-value" id="stat_downloads_size">-</div><div class="stat-label"><i class="fas fa-download"></i>下载漫画占用容量</div></div>
                    </div>

                    <div class="card">
                        <div class="card-head"><h2>快捷操作<span class="hint">维护动作都会记入下载托盘日志</span></h2></div>
                        <div class="card-body">
                            <div class="quick-actions">
                                <button class="btn ghost" type="button" onclick="switchPage('download')"><i class="fas fa-download"></i><span>下载画廊</span></button>
                                <button class="btn ghost" type="button" onclick="switchPage('settings')"><i class="fas fa-cookie-bite"></i><span>配置 Cookie</span></button>
                                <button class="btn ghost" type="button" onclick="switchPage('gallery')"><i class="fas fa-images"></i><span>浏览图库</span></button>
                            </div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-head"><h2>关于 ehLib</h2></div>
                        <div class="card-body">
                            <p style="margin:0 0 6px">ehLib 是一个命令行工具，用于从 <strong>nhentai</strong> 和 <strong>exhentai</strong> 下载漫画画廊，并完整提取标签元数据存储到本地 SQLite 数据库中。</p>
                            <p class="panel-note" style="margin:0">项目路径：<?= htmlspecialchars($projectPath) ?></p>
                        </div>
                    </div>
                </div>

                <!-- 校对 -->
                <div class="tab-pane fade" id="tab_tools_verify">
                    <div class="card">
                        <div class="card-head">
                            <h2>批量校对</h2>
                            <div class="actions">
                                <button class="btn ghost sm icon-only" type="button" onclick="pollVerifyStatus()" title="刷新校对状态"><i class="fas fa-rotate"></i></button>
                                <button class="btn primary sm" type="button" id="verify_start_btn" onclick="startVerify()"><i class="fas fa-check-double"></i><span>开始校对</span></button>
                                <button class="btn danger sm d-none" type="button" id="verify_stop_btn" onclick="stopVerify()"><i class="fas fa-stop"></i><span>终止校对</span></button>
                            </div>
                        </div>
                        <div class="card-body">
                            <p class="panel-note">对比 ExHentai 与本地缓存的一致性，自动修复元数据并重新下载封面。</p>
                            <div id="verify_progress" class="output-box"></div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-head"><h2>单本校对</h2></div>
                        <div class="card-body">
                            <label class="field">
                                <span class="field-label">搜索画廊</span>
                                <div class="search-row">
                                    <input type="text" class="input grow" id="tv_search_input" placeholder="输入标题、作者或 ID 检索…" autocomplete="off">
                                    <button class="btn primary" type="button" id="tv_verify_btn" onclick="tvVerify()" disabled><i class="fas fa-play"></i><span>开始校对</span></button>
                                </div>
                            </label>
                            <div id="tv_search_results" class="verify-search-results" style="display:none"></div>
                            <div id="tv_result" class="output-box" style="margin-top:12px"></div>
                        </div>
                    </div>
                </div>

                <!-- 图片压缩 -->
                <div class="tab-pane fade" id="tab_tools_compression">
                    <div class="stat-grid cols-5" id="compression_stats">
                        <div class="stat-cell"><div class="stat-value" id="compress_stat_total">-</div><div class="stat-label">可压缩漫画</div></div>
                        <div class="stat-cell"><div class="stat-value is-muted" id="compress_stat_not_started">-</div><div class="stat-label">尚未压缩</div></div>
                        <div class="stat-cell"><div class="stat-value is-warn" id="compress_stat_running">-</div><div class="stat-label">排队 / 运行中</div></div>
                        <div class="stat-cell"><div class="stat-value is-warn" id="compress_stat_review">-</div><div class="stat-label">等待审核</div></div>
                        <div class="stat-cell"><div class="stat-value is-danger" id="compress_stat_failed">-</div><div class="stat-label">失败</div></div>
                    </div>

                    <div class="card">
                        <div class="card-head">
                            <h2>发起压缩与任务进度</h2>
                            <button class="btn ghost sm icon-only" type="button" onclick="loadCompressionPage()" title="立即刷新"><i class="fas fa-rotate"></i></button>
                        </div>
                        <div class="card-body">
                            <div class="form-grid cols-4">
                                <label class="field">
                                    <span class="field-label">Gallery ID</span>
                                    <input type="number" min="1" class="input" id="compress_gallery_id" placeholder="从下方列表选择，或直接输入">
                                </label>
                                <label class="field">
                                    <span class="field-label">Quality</span>
                                    <input type="number" min="1" max="100" value="65" class="input" id="compress_quality">
                                </label>
                                <label class="field">
                                    <span class="field-label">Speed</span>
                                    <select class="input" id="compress_speed">
                                        <option value="0">0（最慢）</option><option value="1">1</option><option value="2">2</option>
                                        <option value="3">3</option><option value="4">4</option><option value="5" selected>5（默认）</option>
                                        <option value="6">6</option><option value="7">7</option><option value="8">8</option>
                                        <option value="9">9</option><option value="10">10（最快）</option>
                                    </select>
                                </label>
                                <label class="field">
                                    <span class="field-label">最小节省率 %</span>
                                    <input type="number" min="0" max="100" step="0.1" value="5" class="input" id="compress_min_savings">
                                </label>
                                <div class="actions" style="grid-column:1 / -1">
                                    <button class="btn primary" type="button" id="compress_start_btn" onclick="startCompressionTask()"><i class="fas fa-play"></i><span>单本压缩</span></button>
                                    <button class="btn ghost" type="button" id="compress_batch_start_btn" onclick="startBatchCompression()"><i class="fas fa-layer-group"></i><span>批量压缩</span></button>
                                    <label class="switch">
                                        <input class="form-check-input" type="checkbox" id="compress_force_candidates">
                                        <span>忽略最小节省率，保留所有变小候选</span>
                                    </label>
                                </div>
                                <div class="span-all panel-note" id="compress_selected_hint">尚未选择漫画。</div>
                            </div>

                            <hr class="divider">
                            <div id="compression_batch_progress"><span class="panel-note">尚未启动批量任务。</span></div>
                            <div class="d-none" id="compression_active_tasks" style="margin-top:12px"></div>
                        </div>
                    </div>

                    <div class="card">
                        <div class="card-head">
                            <div class="search-row" style="flex:1">
                                <input class="input grow" id="compress_search" placeholder="搜索标题、作者、source_id 或 Gallery ID"
                                       onkeydown="if(event.key==='Enter'){compressionApplyFilter()}">
                                <button class="btn ghost sm" type="button" onclick="compressionApplyFilter()"><i class="fas fa-magnifying-glass"></i><span>搜索</span></button>
                                <select class="input sm" id="compress_status_filter" onchange="compressionApplyFilter()">
                                    <option value="all">全部待处理</option>
                                    <option value="queued">已排队</option>
                                    <option value="compressing">压缩中</option>
                                    <option value="user_review_required">等待审核</option>
                                    <option value="failed">失败</option>
                                </select>
                                <button class="btn primary sm" type="button" id="compress_batch_approve_btn" onclick="approveFilteredCompressionReviews()" disabled><i class="fas fa-check-double"></i><span>批量审核通过</span></button>
                            </div>
                            <span class="panel-note" id="compression_result_count" style="margin:0"></span>
                        </div>
                        <div class="table-wrap">
                            <table class="table compression-table">
                                <thead><tr><th>ID / 来源</th><th>标题</th><th>页数</th><th>状态</th><th>候选 / 节省率</th><th>更新时间</th><th class="ta-r">操作</th></tr></thead>
                                <tbody id="compression_table_body"><tr><td colspan="7" class="muted-cell">加载中…</td></tr></tbody>
                            </table>
                        </div>
                        <div class="card-foot" id="compression_pagination"></div>
                    </div>
                </div>

                <!-- 数据导出 -->
                <div class="tab-pane fade" id="tab_tools_export">
                    <div class="card">
                        <div class="card-head"><h2>导出数据</h2></div>
                        <div class="card-body">
                            <p class="panel-note">导出为 ZIP 包，包含元数据 JSON、数据库文件、所有封面图（不含已下载漫画）。</p>
                            <button class="btn primary" type="button" onclick="doExport()"><i class="fas fa-file-export"></i><span>导出 ZIP</span></button>
                            <div id="export_output" class="output-box"></div>
                            <div id="export_table_wrapper" style="display:none;margin-top:14px">
                                <div class="table-wrap" style="max-height:400px;overflow-y:auto">
                                    <table class="table">
                                        <thead><tr><th>来源</th><th>ID</th><th>标题</th><th>作者</th><th>页数</th></tr></thead>
                                        <tbody id="export_table_body"></tbody>
                                    </table>
                                </div>
                                <div style="margin-top:8px"><span class="panel-note" id="export_count"></span></div>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
