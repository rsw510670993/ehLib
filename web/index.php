<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:,">
    <title>ehLib 管理面板</title>
    <link href="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.1/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=5">
</head>
<body>

<?php
$scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/index.php';
$base = rtrim(dirname($scriptName), '/');
?>

<div class="sidebar">
    <div class="brand"><i class="fas fa-book-open me-2"></i>ehLib</div>
    <ul class="nav flex-column mt-2">
        <li class="nav-item"><a class="nav-link active" href="#" data-page="dashboard"><i class="fas fa-tachometer-alt"></i>仪表盘</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="gallery"><i class="fas fa-images"></i>本地图库</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="cache"><i class="fas fa-database"></i>本地缓存</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="settings"><i class="fas fa-cog"></i>系统设置</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="download"><i class="fas fa-download"></i>下载控制</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="cookies"><i class="fas fa-cookie-bite"></i>Cookie 配置</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="export"><i class="fas fa-file-export"></i>数据导出</a></li>
    </ul>
</div>

<div class="main">
    <div class="main-header">
        <div><span id="page_title">仪表盘</span> <small class="text-muted ms-2" id="page_subtitle">系统概览</small></div>
        <div class="d-flex align-items-center gap-2">
            <span class="badge bg-secondary" id="status_indicator"><i class="fas fa-circle text-success me-1"></i>在线</span>
            <span class="text-muted small" id="clock"></span>
        </div>
    </div>

    <div class="main-content">

        <!-- ═══ Toast ═══ -->
        <div class="toast-container" id="toast_container"></div>

        <div class="modal fade" id="confirm_modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="confirm_modal_title">确认操作</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="confirm_modal_body"></div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" id="confirm_modal_cancel" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-danger" id="confirm_modal_ok">确认</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="prompt_modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title" id="prompt_modal_title">输入</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div id="prompt_modal_message" class="mb-2"></div>
                        <input type="text" class="form-control form-control-sm" id="prompt_modal_input">
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-outline-secondary" id="prompt_modal_cancel" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-primary" id="prompt_modal_ok">确定</button>
                    </div>
                </div>
            </div>
        </div>

        <!-- ═══ Dashboard ═══ -->
        <div id="page_dashboard" class="page-section">
            <div class="row g-3 mb-3" id="stats_cards">
                <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_galleries">-</div><div class="stat-label">本地画廊总数</div></div></div>
                <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_db">-</div><div class="stat-label">数据库状态</div></div></div>
                <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_venv">-</div><div class="stat-label">虚拟环境</div></div></div>
                <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_config">-</div><div class="stat-label">配置文件</div></div></div>
            </div>
            <div class="card">
                <div class="card-header">快捷操作</div>
                <div class="card-body">
                    <div class="row g-2">
                        <div class="col-md-4">
                            <div class="d-grid"><button class="btn btn-outline-primary" onclick="switchPage('download')"><i class="fas fa-download me-1"></i>下载画廊</button></div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-grid"><button class="btn btn-outline-success" onclick="switchPage('cookies')"><i class="fas fa-cookie-bite me-1"></i>配置 Cookie</button></div>
                        </div>
                        <div class="col-md-4">
                            <div class="d-grid"><button class="btn btn-outline-info" onclick="switchPage('gallery')"><i class="fas fa-images me-1"></i>浏览本地图库</button></div>
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

        <!-- ═══ Cookie 配置 ═══ -->
        <div id="page_cookies" class="page-section section-hidden">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>Cookie 配置</span>
                    <button class="btn btn-sm btn-success" onclick="saveCookies()"><i class="fas fa-save me-1"></i>保存</button>
                </div>
                <div class="card-body">
                    <ul class="nav nav-tabs mb-3" role="tablist">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab_nh_cookies">nhentai</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab_ex_cookies">exhentai</button></li>
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

        <!-- ═══ 系统设置 ═══ -->
        <div id="page_settings" class="page-section section-hidden">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>系统设置</span>
                    <button class="btn btn-sm btn-success" onclick="saveSettings()"><i class="fas fa-save me-1"></i>保存</button>
                </div>
                <div class="card-body">
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
            </div>
        </div>

        <!-- ═══ 下载控制 ═══ -->
        <div id="page_download" class="page-section section-hidden">
            <div class="card">
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

            <div class="card">
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

            <div class="card">
                <div class="card-header">重新下载</div>
                <div class="card-body">
                    <p class="mb-2 text-muted">重新尝试下载之前未完成的画廊（数据库标记为 is_complete=0 的记录）。</p>
                    <div class="d-flex align-items-center gap-3 mb-2">
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

        <!-- ═══ 本地图库 ═══ -->
        <div id="page_gallery" class="page-section section-hidden">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span>本地画廊列表</span>
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

        <!-- ═══ 本地缓存索引 ═══ -->
        <div id="page_cache" class="page-section section-hidden">
            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <span>本地缓存索引</span>
                    <button class="btn btn-sm btn-outline-secondary" onclick="loadCachePage(1)"><i class="fas fa-sync"></i></button>
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
                        <div class="col-md-1 d-flex align-items-center">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" id="crawl_force">
                                <label class="form-check-label small" for="crawl_force">强制</label>
                            </div>
                        </div>
                    </div>
                    <div class="row g-2 align-items-end mb-2">
                        <div class="col-md-5">
                            <label class="form-label small mb-1 text-muted"><i class="fas fa-filter me-1"></i>本地检索</label>
                            <input type="text" class="form-control form-control-sm" id="cache_keyword" placeholder="搜索标题/作者" onkeydown="if(event.key==='Enter')cacheSearch()">
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
                    <div class="mt-1" id="saved_presets_row"></div>
                </div>
                <div class="card-body" id="cache_grid_body">
                    <div class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</div>
                </div>
                <div class="card-footer" id="cache_pagination"></div>
            </div>
        </div>

        <!-- ═══ 阅读器 ═══ -->
        <div id="reader_page" class="section-hidden">
            <div class="reader-header">
                <div class="reader-header-top">
                    <button class="btn-close-reader" onclick="closeReader()" title="关闭 (Esc)"><i class="fas fa-arrow-left"></i></button>
                    <span class="reader-title" id="reader_title"></span>
                    <span class="reader-meta" id="reader_meta"></span>
                    <div class="reader-pagenav">
                        <button onclick="readerPrevPage()" title="上一页 (←)"><i class="fas fa-chevron-left"></i></button>
                        <input type="number" class="page-input" id="reader_page_input" min="1" onchange="readerGoToPage(parseInt(this.value))">
                        <span id="reader_page_total"></span>
                        <button onclick="readerNextPage()" title="下一页 (→)"><i class="fas fa-chevron-right"></i></button>
                    </div>
                    <button class="btn-toggle-details" id="reader_toggle_details" onclick="readerToggleDetails()" title="详细信息"><i class="fas fa-chevron-down"></i></button>
                </div>
                <div class="reader-header-details" id="reader_header_details">
                    <div class="detail-row" id="reader_detail_artist"></div>
                    <div class="detail-row" id="reader_detail_lang"></div>
                    <div class="detail-row" id="reader_detail_category"></div>
                    <div class="detail-row" id="reader_detail_uploaded"></div>
                    <div class="detail-tags" id="reader_detail_tags"></div>
                </div>
            </div>
            <div class="reader-body">
                <div class="reader-thumbstrip" id="reader_thumbstrip">
                    <div id="reader_thumb_list"></div>
                </div>
                <div class="reader-main" id="reader_main">
                    <img id="reader_main_img" src="" alt="loading...">
                </div>
            </div>
        </div>

        <!-- ═══ 数据导出 ═══ -->
        <div id="page_export" class="page-section section-hidden">
            <div class="card">
                <div class="card-header">导出元数据</div>
                <div class="card-body">
                    <p class="text-muted">将所有本地画廊的元数据导出为 JSON 格式，包含标题、作者、标签等信息。</p>
                    <button class="btn btn-primary" onclick="doExport()"><i class="fas fa-file-export me-1"></i>导出 JSON</button>
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

    <!-- ═══ Persistent Downloads Progress Tray ═══ -->
    <div id="active_downloads_card" class="downloads-tray collapsed">
        <div class="downloads-tray-header" onclick="toggleDownloadsTray()">
            <span><i class="fas fa-download me-1"></i>下载进度 <span class="small text-muted" id="tray_status_label">(空闲)</span></span>
            <button class="downloads-tray-toggle" id="tray_toggle_btn" onclick="event.stopPropagation();toggleDownloadsTray()"><i class="fas fa-chevron-up"></i></button>
        </div>
        <div class="downloads-tray-body" id="active_downloads_body"></div>
    </div>
</div>

<script src="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
<script src="assets/js/core.js"></script>
<script src="assets/js/navigation.js"></script>
<script src="assets/js/config.js"></script>
<script src="assets/js/download.js"></script>
<script src="assets/js/gallery.js"></script>
<script src="assets/js/reader.js"></script>
<script src="assets/js/export.js"></script>
<script src="assets/js/cache.js?v=2"></script>
</body>
</html>
