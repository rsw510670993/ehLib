<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ehLib 管理面板</title>
    <link href="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.1/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root { --bs-font-sans-serif: system-ui, -apple-system, "Segoe UI", Roboto, "Noto Sans SC", sans-serif; }
        body { background: #f5f7fa; }
        .sidebar { position: fixed; top: 0; bottom: 0; left: 0; z-index: 100; width: 220px; background: #1e293b; }
        .sidebar .brand { padding: 1rem 1.25rem; font-size: 1.25rem; color: #fff; border-bottom: 1px solid #334155; }
        .sidebar .nav-link { color: #94a3b8; padding: 0.625rem 1.25rem; border-radius: 0; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: #334155; }
        .sidebar .nav-link i { width: 1.25rem; text-align: center; margin-right: 0.5rem; }
        .main { margin-left: 220px; min-height: 100vh; }
        .main-header { background: #fff; padding: 0.75rem 1.5rem; border-bottom: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: space-between; }
        .main-content { padding: 1.5rem; }
        .card { border: none; box-shadow: 0 1px 3px rgba(0,0,0,.08); margin-bottom: 1.25rem; }
        .card-header { background: #fff; border-bottom: 1px solid #e9ecef; font-weight: 600; padding: 0.75rem 1.25rem; }
        .card-header .btn-group-sm .btn { font-size: .8rem; }
        .stat-card { text-align: center; padding: 1.25rem; }
        .stat-card .stat-value { font-size: 1.75rem; font-weight: 700; color: #0d6efd; }
        .stat-card .stat-label { color: #64748b; font-size: .85rem; margin-top: .25rem; }
        .cookie-masked { font-family: "SFMono-Regular", Consolas, monospace; font-size: .85rem; color: #94a3b8; }
        .output-box { background: #1e293b; color: #e2e8f0; padding: 0.75rem 1rem; border-radius: .375rem; font-family: "SFMono-Regular", Consolas, monospace; font-size: .8rem; max-height: 400px; overflow-y: auto; white-space: pre-wrap; word-break: break-all; margin-top: .5rem; display: none; }
        .output-box.show { display: block; }
        .output-box .info { color: #22c55e; }
        .output-box .error { color: #ef4444; }
        .output-box .warn { color: #eab308; }
        .toast-container { position: fixed; top: 1rem; right: 1rem; z-index: 9999; }
        .nav-tabs .nav-link { color: #64748b; }
        .nav-tabs .nav-link.active { font-weight: 600; }
        .section-hidden { display: none; }
        .badge-nh { background: #e74c3c; }
        .badge-ex { background: #3498db; }
        .tag-badge { display: inline-block; padding: .15rem .5rem; font-size: .75rem; border-radius: .25rem; background: #e9ecef; color: #495057; margin: .15rem; }
        .checkbox-group { max-height: 250px; overflow-y: auto; border: 1px solid #dee2e6; border-radius: .375rem; padding: .5rem .75rem; }
        .gallery-actions { white-space: nowrap; }
        .gallery-card { cursor: pointer; transition: transform .15s, box-shadow .15s; }
        .gallery-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,.12); }
        .gallery-card .card-img-wrapper { background: #f0f0f0; position: relative; }
        .gallery-card .card-img-wrapper img { width: 100%; height: 100%; object-fit: cover; }
        .gallery-card .card-img-wrapper .delete-overlay { position: absolute; top: 4px; right: 4px; opacity: 0; transition: opacity .15s; z-index: 2; }
        .gallery-card .card-img-wrapper:hover .delete-overlay { opacity: 1; }
        .gallery-card .card-body { display: flex; flex-direction: column; justify-content: space-between; }
        .gallery-card .card-body .title-clamp { display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; line-height: 1.35; height: calc(1.35em * 3); flex-shrink: 0; }
        #reader_page { position: fixed; top: 0; left: 0; right: 0; bottom: 0; z-index: 1050; background: #111; display: flex; flex-direction: column; }
        #reader_page.section-hidden { display: none !important; }
        .reader-header { background: rgba(0,0,0,.85); color: #e2e8f0; flex-shrink: 0; }
        .reader-header .reader-header-top { padding: .5rem 1rem; display: flex; align-items: center; gap: .75rem; }
        .reader-header .btn-close-reader { color: #fff; background: none; border: none; font-size: 1.25rem; cursor: pointer; padding: .25rem .5rem; }
        .reader-header .reader-title { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-size: .9rem; }
        .reader-header .reader-meta { font-size: .8rem; color: #94a3b8; flex-shrink: 0; }
        .reader-header .btn-toggle-details { background: none; border: none; color: #94a3b8; cursor: pointer; font-size: .8rem; padding: .2rem .4rem; }
        .reader-header .btn-toggle-details:hover { color: #fff; }
        .reader-header-details { padding: .25rem 1rem .5rem; border-top: 1px solid rgba(255,255,255,.1); display: none; }
        .reader-header-details.show { display: block; }
        .reader-header-details .detail-row { font-size: .8rem; color: #94a3b8; margin-bottom: .15rem; }
        .reader-header-details .detail-tags { display: flex; flex-wrap: wrap; gap: .2rem; margin-top: .2rem; }
        .reader-header-details .detail-tags .tag-badge { cursor: pointer; }
        .reader-body { flex: 1; display: flex; overflow: hidden; }
        .reader-thumbstrip { width: 150px; background: rgba(0,0,0,.6); overflow-y: auto; flex-shrink: 0; padding: .5rem; }
        .reader-thumbstrip .thumb-item { display: block; width: 100%; margin-bottom: .4rem; cursor: pointer; border: 2px solid transparent; border-radius: 4px; overflow: hidden; opacity: .6; transition: opacity .15s, border-color .15s; }
        .reader-thumbstrip .thumb-item:hover { opacity: .9; }
        .reader-thumbstrip .thumb-item.active { border-color: #0d6efd; opacity: 1; }
        .reader-thumbstrip .thumb-item img { width: 100%; height: auto; display: block; }
        .reader-main { flex: 1; display: flex; align-items: center; justify-content: center; overflow: auto; padding: 1rem; position: relative; }
        .reader-main img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .reader-pagenav { display: flex; align-items: center; gap: .5rem; }
        .reader-pagenav button { background: rgba(255,255,255,.1); color: #e2e8f0; border: none; border-radius: 4px; padding: .3rem .7rem; cursor: pointer; font-size: .85rem; }
        .reader-pagenav button:hover { background: rgba(255,255,255,.2); }
        .reader-pagenav .page-input { width: 50px; text-align: center; background: rgba(255,255,255,.1); color: #e2e8f0; border: 1px solid rgba(255,255,255,.2); border-radius: 4px; padding: .2rem; }
        @media (max-width: 768px) { .reader-thumbstrip { width: 80px; } }
    </style>
</head>
<body>

<?php
$base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
?>

<div class="sidebar">
    <div class="brand"><i class="fas fa-book-open me-2"></i>ehLib</div>
    <ul class="nav flex-column mt-2">
        <li class="nav-item"><a class="nav-link active" href="#" data-page="dashboard"><i class="fas fa-tachometer-alt"></i>仪表盘</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="cookies"><i class="fas fa-cookie-bite"></i>Cookie 配置</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="settings"><i class="fas fa-cog"></i>系统设置</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="download"><i class="fas fa-download"></i>下载控制</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="gallery"><i class="fas fa-images"></i>本地图库</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="search"><i class="fas fa-search"></i>在线搜索</a></li>
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

        <!-- ═══ Dashboard ═══ -->
        <div id="page_dashboard" class="page-section">
            <div class="row g-3 mb-3" id="stats_cards">
                <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_galleries">-</div><div class="stat-label">本地画廊总数</div></div></div>
                <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_db">-</div><div class="stat-label">数据库状态</div></div></div>
                <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_venv">-</div><div class="stat-label">虚拟环境</div></div></div>
                <div class="col-6 col-lg-3"><div class="card stat-card"><div class="stat-value" id="stat_config">-</div><div class="stat-label">配置文件</div></div></div>
            </div>
            <div id="active_downloads_card" class="mb-3" style="display:none">
                <div class="card border-primary">
                    <div class="card-header bg-primary text-white py-2">
                        <i class="fas fa-download me-1"></i>活跃下载
                    </div>
                    <div class="card-body py-2" id="active_downloads_body">
                    </div>
                </div>
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
                    <button class="btn btn-sm btn-primary" onclick="doBatchDownload()"><i class="fas fa-play me-1"></i>开始批量下载</button>
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
                    <div id="batch_progress" class="progress mb-2" style="height:8px;display:none">
                        <div class="progress-bar progress-bar-striped progress-bar-animated" id="batch_progress_bar" style="width:0%"></div>
                    </div>
                    <div id="batch_output" class="output-box"></div>
                </div>
            </div>

            <div class="card">
                <div class="card-header">重新下载</div>
                <div class="card-body">
                    <p class="mb-2 text-muted">重新尝试下载之前未完成的画廊（数据库标记为 is_complete=0 的记录）。</p>
                    <button class="btn btn-warning" onclick="doRetry()"><i class="fas fa-redo me-1"></i>重试未完成下载</button>
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

        <!-- ═══ 在线搜索 ═══ -->
        <div id="page_search" class="page-section section-hidden">
            <div class="card">
                <div class="card-header">在线搜索</div>
                <div class="card-body">
                    <div class="row g-2 align-items-end mb-3">
                        <div class="col-md-3">
                            <label class="form-label">站点</label>
                            <select class="form-select" id="search_source">
                                <option value="nhentai">nhentai</option>
                                <option value="exhentai">exhentai</option>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">搜索关键词</label>
                            <input type="text" class="form-control" id="search_query" placeholder="例如: tag:english  或  artist:shindol" onkeydown="if(event.key==='Enter')doSearch()">
                        </div>
                        <div class="col-md-3">
                            <button class="btn btn-primary w-100" onclick="doSearch()"><i class="fas fa-search me-1"></i>搜索</button>
                        </div>
                    </div>
                    <div id="search_output" class="output-box"></div>
                    <div id="search_results"></div>
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
</div>

<script src="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
<script>
const API = '<?= $base ?>/api.php';

function showToast(msg, type = 'success') {
    const c = document.getElementById('toast_container');
    const el = document.createElement('div');
    el.className = `toast align-items-center text-bg-${type} border-0 show`;
    el.setAttribute('role', 'alert');
    el.innerHTML = `<div class="d-flex"><div class="toast-body">${msg}</div><button type="button" class="btn-close me-2 m-auto" data-bs-dismiss="toast"></button></div>`;
    c.appendChild(el);
    setTimeout(() => { el.remove(); }, 4000);
}

function showOutput(id, text, isError = false) {
    const el = document.getElementById(id);
    el.classList.add('show');
    el.className = 'output-box show';
    el.innerHTML = `<span class="${isError ? 'error' : 'info'}">${text.replace(/</g,'&lt;').replace(/\n/g,'<br>')}</span>`;
}

function clearOutput(id) {
    const el = document.getElementById(id);
    el.classList.remove('show');
    el.innerHTML = '';
}

async function api(method, params = {}) {
    let url = API + '?action=' + method;
    let opts = {};
    if (params.body) {
        opts.method = 'POST';
        opts.headers = { 'Content-Type': 'application/json' };
        opts.body = JSON.stringify(params.body);
    } else if (params.form) {
        opts.method = 'POST';
        opts.body = new URLSearchParams(params.form);
    }
    const resp = await fetch(url, opts);
    return await resp.json();
}

// ─── Page switching ───
function switchPage(name) {
    document.querySelectorAll('.page-section').forEach(el => el.classList.add('section-hidden'));
    document.querySelectorAll('.sidebar .nav-link').forEach(el => el.classList.remove('active'));
    document.querySelector(`[data-page="${name}"]`)?.classList.add('active');
    const page = document.getElementById('page_' + name);
    if (page) page.classList.remove('section-hidden');

    const titles = {
        dashboard: ['仪表盘', '系统概览'],
        cookies: ['Cookie 配置', '管理站点登录凭据与 Cloudflare 验证'],
        settings: ['系统设置', '下载路径、并发、User-Agent 等'],
        download: ['下载控制', '单一下载、批量下载、重试'],
        gallery: ['本地图库', '已下载的画廊列表'],
        search: ['在线搜索', '搜索 nhentai / exhentai'],
        export: ['数据导出', '导出元数据为 JSON'],
    };
    const t = titles[name] || ['页面', ''];
    document.getElementById('page_title').textContent = t[0];
    document.getElementById('page_subtitle').textContent = t[1];

    // load data on page switch
    if (name === 'dashboard') loadDashboard();
    if (name === 'gallery') loadGalleries();
}

document.querySelectorAll('.sidebar .nav-link').forEach(a => {
    a.addEventListener('click', e => { e.preventDefault(); switchPage(a.dataset.page); });
});

// ─── Clock ───
function updateClock() {
    document.getElementById('clock').textContent = new Date().toLocaleString('zh-CN');
}
setInterval(updateClock, 1000);
updateClock();

// ─── Dashboard ───
async function loadDashboard() {
    const data = await api('get_stats');
    document.getElementById('stat_galleries').textContent = data.gallery_count ?? '-';
    document.getElementById('stat_db').textContent = data.db_exists ? '正常' : '未创建';
    document.getElementById('stat_db').style.color = data.db_exists ? '#22c55e' : '#94a3b8';
    document.getElementById('stat_venv').textContent = data.venv_exists ? '就绪' : '缺失';
    document.getElementById('stat_venv').style.color = data.venv_exists ? '#22c55e' : '#ef4444';
    document.getElementById('stat_config').textContent = data.config_file ? '已配置' : '未配置';
    document.getElementById('stat_config').style.color = data.config_file ? '#22c55e' : '#ef4444';
}

// ─── Cookies ───
async function loadCookies() {
    const data = await api('get_config');
    if (!data.config || !data.config.cookies) return;
    const c = data.config.cookies;
    if (c.nhentai) {
        document.getElementById('cookie_nh_cf_clearance').value = c.nhentai.cf_clearance || '';
    }
    if (c.exhentai) {
        document.getElementById('cookie_ex_ipb_member_id').value = c.exhentai.ipb_member_id || '';
        document.getElementById('cookie_ex_ipb_pass_hash').value = c.exhentai.ipb_pass_hash || '';
        document.getElementById('cookie_ex_cf_clearance').value = c.exhentai.cf_clearance || '';
    }
}

async function saveCookies() {
    const data = await api('get_config');
    const cfg = data.config || {};
    if (!cfg.cookies) cfg.cookies = { nhentai: {}, exhentai: {} };
    cfg.cookies.nhentai = {
        cf_clearance: document.getElementById('cookie_nh_cf_clearance').value,
    };
    cfg.cookies.exhentai = {
        ipb_member_id: document.getElementById('cookie_ex_ipb_member_id').value,
        ipb_pass_hash: document.getElementById('cookie_ex_ipb_pass_hash').value,
    };
    const cf = document.getElementById('cookie_ex_cf_clearance').value;
    if (cf) cfg.cookies.exhentai.cf_clearance = cf;
    const res = await api('save_config', { body: cfg });
    if (res.ok) showToast('Cookie 已保存', 'success');
    else showToast('保存失败: ' + (res.error || ''), 'danger');
}

async function parseExCookieString() {
    const raw = document.getElementById('cookie_ex_raw').value.trim();
    if (!raw) { showToast('请先粘贴 Cookie 字符串', 'warning'); return; }
    const res = await api('parse_cookie_string', { form: { action: 'parse_cookie_string', cookie_string: raw } });
    if (!res.ok) { showToast('解析失败: ' + (res.error || ''), 'danger'); return; }
    const p = res.parsed || {};
    let filled = 0;
    if (p.ipb_member_id) { document.getElementById('cookie_ex_ipb_member_id').value = p.ipb_member_id; filled++; }
    if (p.ipb_pass_hash) { document.getElementById('cookie_ex_ipb_pass_hash').value = p.ipb_pass_hash; filled++; }
    if (p.cf_clearance) { document.getElementById('cookie_ex_cf_clearance').value = p.cf_clearance; filled++; }
    showToast(`解析成功，已填入 ${filled} 个字段`, 'success');
}

// ─── Settings ───
async function loadSettings() {
    const data = await api('get_config');
    if (!data.config) return;
    const dl = data.config.download || {};
    const req = data.config.request || {};
    const br = data.config.browser || {};
    document.getElementById('set_dl_path').value = dl.path || './downloads';
    document.getElementById('set_dl_concurrent').value = dl.max_concurrent || 3;
    document.getElementById('set_dl_retry').value = dl.retry_times || 3;
    document.getElementById('set_dl_retry_delay').value = dl.retry_delay || 5;
    document.getElementById('set_req_ua').value = req.user_agent || '';
    document.getElementById('set_req_delay').value = req.delay_between_requests || 1.5;
    document.getElementById('set_browser_headless').value = br.headless ? 'true' : 'false';
}

async function saveSettings() {
    const data = await api('get_config');
    const cfg = data.config || {};
    cfg.download = {
        path: document.getElementById('set_dl_path').value,
        max_concurrent: parseInt(document.getElementById('set_dl_concurrent').value) || 3,
        retry_times: parseInt(document.getElementById('set_dl_retry').value) || 3,
        retry_delay: parseInt(document.getElementById('set_dl_retry_delay').value) || 5,
    };
    cfg.request = {
        user_agent: document.getElementById('set_req_ua').value,
        delay_between_requests: parseFloat(document.getElementById('set_req_delay').value) || 1.5,
    };
    cfg.browser = {
        headless: document.getElementById('set_browser_headless').value === 'true',
    };
    const res = await api('save_config', { body: cfg });
    if (res.ok) showToast('设置已保存', 'success');
    else showToast('保存失败: ' + (res.error || ''), 'danger');
}

// ─── Download ───
function parseDownloadUrl(url) {
    var m = url.match(/exhentai\.org\/g\/(\d+\/[a-f0-9]+)/i);
    if (m) return { source: 'exhentai', source_id: m[1] };
    m = url.match(/nhentai\.net\/g\/(\d+)/i);
    if (m) return { source: 'nhentai', source_id: m[1] };
    return null;
}

async function doDownload() {
    const url = document.getElementById('dl_url').value.trim();
    if (!url) { showToast('请输入 URL', 'warning'); return; }
    const force = document.getElementById('dl_force_url').checked;
    if (force && !confirm('⚠ 强制重新下载将清空本地图片文件并覆盖数据库记录，确定要执行吗？')) return;
    clearOutput('dl_output');
    document.getElementById('dl_output').classList.add('show');
    document.getElementById('dl_output').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>下载中，请稍候...';

    var parsed = parseDownloadUrl(url);
    if (parsed) trackDownloadProgress(parsed.source, parsed.source_id);

    const form = { action: 'download', url: url };
    if (force) form.force = '1';
    const res = await api('download', { form: form });
    clearDownloadProgress();
    showOutput('dl_output', res.output || '下载完成', !res.ok);
    if (res.ok) showToast('下载成功!', 'success');
}

async function doDownloadById() {
    const source = document.getElementById('dl_source').value;
    const id = document.getElementById('dl_id').value.trim();
    const gid = document.getElementById('dl_gid').value.trim();
    const token = document.getElementById('dl_token').value.trim();
    const force = document.getElementById('dl_force_id').checked;
    if (force && !confirm('⚠ 强制重新下载将清空本地图片文件并覆盖数据库记录，确定要执行吗？')) return;

    clearOutput('dl_output');
    document.getElementById('dl_output').classList.add('show');
    document.getElementById('dl_output').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>下载中，请稍候...';

    let form;
    let parsedId;
    if (gid && token) {
        form = { action: 'download', gid: gid, token: token };
        parsedId = gid + '/' + token;
    } else if (id && source) {
        form = { action: 'download', source: source, id: id };
        parsedId = id;
    } else {
        showToast('请输入 ID 或 GID+Token', 'warning');
        return;
    }
    if (force) form.force = '1';
    if (parsedId && source) trackDownloadProgress(source, parsedId);
    const res = await api('download', { form: form });
    clearDownloadProgress();
    showOutput('dl_output', res.output || '下载完成', !res.ok);
    if (res.ok) showToast('下载成功!', 'success');
}

async function doBatchDownload() {
    const urls = document.getElementById('batch_urls').value.trim();
    if (!urls) { showToast('请输入 URL', 'warning'); return; }
    const force = document.getElementById('batch_force').checked;
    if (force && !confirm('⚠ 强制重新下载将清空所有本地图片文件并覆盖数据库记录，确定要执行吗？')) return;
    clearOutput('batch_output');
    document.getElementById('batch_output').classList.add('show');
    document.getElementById('batch_output').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>批量下载中，请稍候...';

    const res = await fetch(API + '?action=batch_download' + (force ? '&force=1' : ''), {
        method: 'POST',
        body: urls,
    });
    const data = await res.json();
    showOutput('batch_output', data.output || '批量下载完成', !data.ok);
    if (data.ok) showToast('批量下载完成!', 'success');
}

async function doRetry() {
    clearOutput('retry_output');
    document.getElementById('retry_output').classList.add('show');
    document.getElementById('retry_output').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>重试中...';
    const res = await api('retry');
    showOutput('retry_output', res.output || '重试完成', !res.ok);
}

// ─── Download Progress ───
let _progressPoller = null;
let _activeProgressKey = null;

function startProgressPoller() {
    if (_progressPoller) return;
    _progressPoller = setInterval(checkDownloadProgress, 2000);
    checkDownloadProgress();
}

function stopProgressPoller() {
    if (_progressPoller) {
        clearInterval(_progressPoller);
        _progressPoller = null;
    }
}

async function checkDownloadProgress() {
    const data = await api('get_download_progress');
    const tasks = data.tasks || [];

    // Update dashboard card
    const card = document.getElementById('active_downloads_card');
    const body = document.getElementById('active_downloads_body');
    if (tasks.length === 0) {
        card.style.display = 'none';
        body.innerHTML = '';
        return;
    }
    card.style.display = '';
    body.innerHTML = tasks.map(function(t) {
        var pct = t.total_pages > 0 ? Math.round(t.current / t.total_pages * 100) : 0;
        var badgeClass = t.source === 'nhentai' ? 'bg-danger' : 'bg-info';
        var msg = t.message || (t.current + '/' + t.total_pages);
        return '<div class="d-flex align-items-center mb-1 small">' +
            '<span class="badge ' + badgeClass + ' me-2 flex-shrink-0">' + t.source + '</span>' +
            '<span class="text-truncate me-2 flex-grow-1" style="max-width:320px">' + t.title + '</span>' +
            '<span class="text-muted flex-shrink-0">' + msg + '</span>' +
            '</div>' +
            '<div class="progress mb-2" style="height:5px">' +
            '<div class="progress-bar progress-bar-striped progress-bar-animated" style="width:' + pct + '%"></div>' +
            '</div>';
    }).join('');

    // Update download page progress bar
    if (_activeProgressKey && tasks.length > 0) {
        var active = tasks.find(function(t) { return t.source + '__' + t.source_id.replace(/\\//g,'_') === _activeProgressKey; });
        if (active) {
            var el = document.getElementById('dl_progress');
            var bar = document.getElementById('dl_progress_bar');
            if (el && bar) {
                el.style.display = '';
                var pct = active.total_pages > 0 ? Math.round(active.current / active.total_pages * 100) : 0;
                bar.style.width = pct + '%';
                bar.textContent = active.current + '/' + active.total_pages;
            }
        }
    }
}

function trackDownloadProgress(source, sourceId) {
    _activeProgressKey = source + '__' + sourceId.replace(/\//g, '_');
    var el = document.getElementById('dl_progress');
    var bar = document.getElementById('dl_progress_bar');
    if (el && bar) {
        el.style.display = '';
        bar.style.width = '0%';
        bar.textContent = '';
    }
    startProgressPoller();
}

function clearDownloadProgress() {
    _activeProgressKey = null;
    var el = document.getElementById('dl_progress');
    var bar = document.getElementById('dl_progress_bar');
    if (el && bar) {
        el.style.display = 'none';
        bar.style.width = '0%';
        bar.textContent = '';
    }
}

// ─── Gallery (Card Grid) ───
let _galleryFilters = {};
let _readerState = null;
let _readerKeyHandler = null;

async function loadGalleries(filters) {
    filters = filters || _galleryFilters || {};
    _galleryFilters = { ...filters };
    const body = document.getElementById('gallery_grid_body');
    body.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</div>';

    let params = '?action=get_galleries';
    if (filters.source) params += '&source=' + encodeURIComponent(filters.source);
    if (filters.tags) params += '&tags=' + encodeURIComponent(filters.tags);
    if (filters.tag_mode) params += '&tag_mode=' + encodeURIComponent(filters.tag_mode);
    if (filters.artist) params += '&artist=' + encodeURIComponent(filters.artist);
    if (filters.language) params += '&language=' + encodeURIComponent(filters.language);
    if (filters.tag) params += '&tag=' + encodeURIComponent(filters.tag);

    const resp = await fetch(API + params);
    const data = await resp.json();

    if (!data.ok || !data.galleries || data.galleries.length === 0) {
        body.innerHTML = '<div class="text-center text-muted py-5">暂无数据</div>';
        return;
    }

    body.innerHTML = '<div class="row row-cols-2 row-cols-md-3 row-cols-lg-4 row-cols-xl-5 g-3" id="gallery_grid">' +
        data.galleries.map(function(g) {
            var displayTitle = g.title_jp || g.title;
            var badgeClass = g.source === 'nhentai' ? 'bg-danger' : 'bg-info';
            var imgUrl = API + '?action=serve_image&source=' + encodeURIComponent(g.source) + '&source_id=' + encodeURIComponent(g.source_id) + '&page=cover';
            return '<div class="col" data-source="' + g.source + '" data-source-id="' + g.source_id + '">' +
                '<div class="card h-100 gallery-card" onclick="openReader(\'' + g.source + '\',\'' + g.source_id + '\')">' +
                '<div class="card-img-wrapper" style="aspect-ratio:3/4;overflow:hidden">' +
                '<img src="' + imgUrl + '" class="card-img-top" alt="cover" loading="lazy" onerror="this.style.display=\'none\'">' +
                '<div class="delete-overlay"><button class="btn btn-sm btn-dark py-0 px-1" style="font-size:.7rem;line-height:1.4" onclick="event.stopPropagation();deleteGalleryFromCard(this,\'' + g.source + '\',\'' + g.source_id + '\',\'' + escapeAttr(displayTitle) + '\')" title="删除"><i class="fas fa-trash-alt"></i></button></div>' +
                '</div>' +
                '<div class="card-body p-2">' +
                '<div class="small title-clamp" title="' + escapeAttr(displayTitle) + '">' + escapeHtml(displayTitle) + '</div>' +
                '<div class="d-flex justify-content-between align-items-center">' +
                '<span class="badge ' + badgeClass + '" style="font-size:.65rem">' + g.source + '</span>' +
                '<span class="small text-muted">' + g.pages + 'p</span>' +
                '</div>' +
                '</div>' +
                '</div>' +
                '</div>';
        }).join('') +
        '</div>';
}

function applyGalleryFilter() {
    const tags = document.getElementById('gallery_tag_filter').value.trim();
    const tagMode = document.getElementById('gallery_tag_mode').value;
    const artist = document.getElementById('gallery_artist_filter').value.trim();
    const lang = document.getElementById('gallery_lang_filter').value.trim();
    const filters = {};
    if (tags) filters.tags = tags;
    if (tagMode !== 'any') filters.tag_mode = tagMode;
    if (artist) filters.artist = artist;
    if (lang) filters.language = lang;
    loadGalleries(filters);
}

function clearGalleryFilter() {
    document.getElementById('gallery_tag_filter').value = '';
    document.getElementById('gallery_tag_mode').value = 'any';
    document.getElementById('gallery_artist_filter').value = '';
    document.getElementById('gallery_lang_filter').value = '';
    loadGalleries({});
}

async function deleteGalleryFromCard(btn, source, sourceId, title) {
    if (!confirm('⚠ 确定删除这本本地画廊吗？\n\n' + title + '\n\n这会同时删除本地图片文件和数据库记录。')) return;
    btn.disabled = true;
    var oldHtml = btn.innerHTML;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    try {
        var res = await api('delete_gallery', { form: { action: 'delete_gallery', source: source, source_id: sourceId } });
        if (!res.ok) throw new Error(res.error || '删除失败');
        showToast('已删除：' + title, 'success');
        await loadGalleries();
    } catch (err) {
        btn.disabled = false;
        btn.innerHTML = oldHtml;
        showToast(err.message, 'danger');
    }
}

function formatFileSize(bytes) {
    if (!bytes) return '';
    var units = ['B', 'KB', 'MB', 'GB'];
    var i = 0, size = bytes;
    while (size >= 1024 && i < units.length - 1) { size /= 1024; i++; }
    return size.toFixed(1) + ' ' + units[i];
}

// ─── Reader ───
function escapeHtml(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function escapeAttr(s) {
    if (!s) return '';
    return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function readerToggleDetails() {
    var el = document.getElementById('reader_header_details');
    var btn = document.getElementById('reader_toggle_details');
    el.classList.toggle('show');
    btn.innerHTML = el.classList.contains('show') ? '<i class="fas fa-chevron-up"></i>' : '<i class="fas fa-chevron-down"></i>';
}

function readerSearchTag(type, name) {
    closeReader();
    document.getElementById('gallery_tag_filter').value = name;
    document.getElementById('gallery_tag_mode').value = 'any';
    loadGalleries({ tags: name, tag_mode: 'any' });
    switchPage('gallery');
}

async function readerRefreshMetadata(source, sourceId) {
    var warningDiv = document.getElementById('reader_tag_warning');
    var btn = warningDiv ? warningDiv.querySelector('button') : null;
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>'; }
    try {
        var res = await api('refresh_metadata', { form: { action: 'refresh_metadata', source: source, source_id: sourceId } });
        if (!res.ok) throw new Error(res.error || res.output || '刷新失败');
        showToast('元数据已刷新', 'success');
        openReader(source, sourceId);
    } catch (err) {
        showToast('刷新元数据失败: ' + (err.message || ''), 'danger');
        if (btn) { btn.disabled = false; btn.innerHTML = '重下载元数据'; }
    }
}

var _readerSource, _readerSourceId;
var _readerCurrentPage = 1;
var _readerTotalPages = 0;
var _readerImageList = [];

function openReader(source, sourceId) {
    _readerSource = source;
    _readerSourceId = sourceId;
    _readerCurrentPage = 1;
    _readerTotalPages = 0;
    _readerImageList = [];
    document.getElementById('reader_page').classList.remove('section-hidden');
    document.getElementById('reader_main_img').src = '';
    document.getElementById('reader_main_img').alt = '加载中...';
    document.getElementById('reader_thumb_list').innerHTML = '<div class="text-center text-muted small py-3"><i class="fas fa-spinner fa-spin"></i></div>';

    // Load metadata
    fetch(API + '?action=get_gallery_detail&source=' + encodeURIComponent(source) + '&source_id=' + encodeURIComponent(sourceId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok || !data.gallery) return;
            var g = data.gallery;
            var displayTitle = g.title_jp || g.title;
            document.getElementById('reader_title').textContent = displayTitle;
            document.getElementById('reader_meta').textContent = g.total_pages + 'p · ' + (g.language || '-');
            document.getElementById('reader_detail_artist').textContent = '作者: ' + (g.artist || '-');
            document.getElementById('reader_detail_lang').textContent = '语言: ' + (g.language || '-');
            document.getElementById('reader_detail_category').textContent = '分类: ' + (g.category || '-');
            document.getElementById('reader_header_details').classList.remove('show');
            var tagsHtml = '';
            var tagCount = (g.tags && g.tags.length > 0) ? g.tags.length : 0;
            if (tagCount > 0) {
                var typeLabels = { artist: 'bg-danger', parody: 'bg-warning text-dark', character: 'bg-primary', group: 'bg-success', language: 'bg-secondary', category: 'bg-info text-dark', tag: 'bg-light text-dark' };
                g.tags.forEach(function(t) {
                    var cls = typeLabels[t.type] || 'bg-light text-dark';
                    tagsHtml += '<span class="tag-badge ' + cls + '" onclick="event.stopPropagation();readerSearchTag(\'' + t.type + '\',\'' + escapeAttr(t.name) + '\')">' + t.type + ': ' + t.name + '</span> ';
                });
            } else {
                tagsHtml = '<span style="color:#666">无标签</span>';
            }
            var tagWarning = '';
            if (tagCount <= 1) {
                tagWarning = '<div class="mt-1 p-1 rounded" style="background:rgba(255,193,7,.15);font-size:.75rem;color:#ffc107">' +
                    '标签数 (<strong>' + tagCount + '</strong>) 过少，可能元数据不完整' +
                    '<button class="btn btn-sm btn-warning py-0 px-1 ms-2" onclick="readerRefreshMetadata(\'' + source + '\',\'' + sourceId + '\')" style="font-size:.7rem">重下载元数据</button></div>';
            } else {
                tagWarning = '<div class="mt-1" style="font-size:.75rem;color:#6b7280">标签: ' + tagCount + '个</div>';
            }
            document.getElementById('reader_detail_tags').innerHTML = tagsHtml;
            var existingWarning = document.getElementById('reader_tag_warning');
            if (existingWarning) existingWarning.remove();
            document.getElementById('reader_detail_tags').insertAdjacentHTML('afterend', '<div id="reader_tag_warning">' + tagWarning + '</div>');
        });

    // Load image list
    fetch(API + '?action=get_image_list&source=' + encodeURIComponent(source) + '&source_id=' + encodeURIComponent(sourceId))
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok || !data.images) return;
            _readerTotalPages = data.total_pages || data.images.length;
            _readerImageList = data.images;
            document.getElementById('reader_page_total').textContent = '/ ' + _readerTotalPages;
            document.getElementById('reader_page_input').max = _readerTotalPages;

            var thumbHtml = '';
            data.images.forEach(function(img, idx) {
                var pageNum = img.page || (idx + 1);
                if (img.file) {
                    var thumbUrl = API + '?action=serve_image&source=' + encodeURIComponent(source) + '&source_id=' + encodeURIComponent(sourceId) + '&page=' + pageNum;
                    thumbHtml += '<div class="thumb-item" data-page="' + pageNum + '" onclick="readerGoToPage(' + pageNum + ')">' +
                        '<img src="' + thumbUrl + '" alt="p' + pageNum + '" loading="lazy">' +
                        '</div>';
                } else {
                    thumbHtml += '<div class="thumb-item" data-page="' + pageNum + '" onclick="readerGoToPage(' + pageNum + ')" style="text-align:center;padding:.5rem;color:#666;font-size:.75rem">' +
                        'p' + pageNum + '</div>';
                }
            });
            document.getElementById('reader_thumb_list').innerHTML = thumbHtml;
            readerGoToPage(1);
        });

    // Keyboard
    if (_readerKeyHandler) document.removeEventListener('keydown', _readerKeyHandler);
    _readerKeyHandler = function(e) {
        if (e.key === 'Escape') { closeReader(); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); readerPrevPage(); }
        else if (e.key === 'ArrowRight') { e.preventDefault(); readerNextPage(); }
    };
    document.addEventListener('keydown', _readerKeyHandler);
}

function closeReader() {
    document.getElementById('reader_page').classList.add('section-hidden');
    document.getElementById('reader_main_img').src = '';
    if (_readerKeyHandler) {
        document.removeEventListener('keydown', _readerKeyHandler);
        _readerKeyHandler = null;
    }
}

function readerGoToPage(page) {
    if (page < 1) page = 1;
    if (page > _readerTotalPages) page = _readerTotalPages;
    _readerCurrentPage = page;
    document.getElementById('reader_page_input').value = page;

    // Update thumb active
    var thumbs = document.querySelectorAll('#reader_thumb_list .thumb-item');
    thumbs.forEach(function(t) { t.classList.remove('active'); });
    var activeThumb = document.querySelector('#reader_thumb_list .thumb-item[data-page="' + page + '"]');
    if (activeThumb) {
        activeThumb.classList.add('active');
        activeThumb.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    }

    // Load main image
    var imgUrl = API + '?action=serve_image&source=' + encodeURIComponent(_readerSource) + '&source_id=' + encodeURIComponent(_readerSourceId) + '&page=' + page;
    document.getElementById('reader_main_img').src = imgUrl;

    // Preload next 2 pages
    for (var i = 1; i <= 2; i++) {
        var nextPage = page + i;
        if (nextPage <= _readerTotalPages) {
            var preloadUrl = API + '?action=serve_image&source=' + encodeURIComponent(_readerSource) + '&source_id=' + encodeURIComponent(_readerSourceId) + '&page=' + nextPage;
            var link = document.createElement('link');
            link.rel = 'preload';
            link.as = 'image';
            link.href = preloadUrl;
            document.head.appendChild(link);
            setTimeout(function(el) { document.head.removeChild(el); }, 3000, link);
        }
    }
}

function readerPrevPage() {
    if (_readerCurrentPage > 1) readerGoToPage(_readerCurrentPage - 1);
}

function readerNextPage() {
    if (_readerCurrentPage < _readerTotalPages) readerGoToPage(_readerCurrentPage + 1);
}

// ─── Search ───
async function doSearch() {
    const source = document.getElementById('search_source').value;
    const query = document.getElementById('search_query').value.trim();
    if (!query) { showToast('请输入搜索关键词', 'warning'); return; }

    document.getElementById('search_results').innerHTML = '<div class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin me-1"></i>搜索中...</div>';
    clearOutput('search_output');

    const res = await api('search', { form: { action: 'search', source: source, query: query } });
    if (!res.ok) {
        showOutput('search_output', res.error || '搜索失败', true);
        document.getElementById('search_results').innerHTML = '';
        return;
    }

    if (res.raw) showOutput('search_output', res.raw, false);

    if (!res.results || res.results.length === 0) {
        document.getElementById('search_results').innerHTML = '<div class="text-center text-muted py-3">未找到结果</div>';
        return;
    }

    document.getElementById('search_results').innerHTML =
        '<div class="table-responsive mt-2"><table class="table table-sm table-hover"><thead class="table-light"><tr><th>ID</th><th>标题</th></tr></thead><tbody>' +
        res.results.map(r => `<tr><td class="font-monospace">${r.id}</td><td>${r.title}</td></tr>`).join('') +
        '</tbody></table></div>';
}

// ─── Export ───
async function doExport() {
    clearOutput('export_output');
    document.getElementById('export_output').classList.add('show');
    document.getElementById('export_output').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>导出中...';
    document.getElementById('export_table_wrapper').style.display = 'none';

    const res = await api('export');
    if (!res.ok) {
        showOutput('export_output', res.output || '导出失败', true);
        return;
    }

    showOutput('export_output', `导出成功: ${res.file || ''}，共 ${(res.galleries || []).length} 条记录`, false);

    const galleries = res.galleries || [];
    if (galleries.length === 0) return;

    document.getElementById('export_count').textContent = `共 ${galleries.length} 条记录`;
    document.getElementById('export_table_body').innerHTML = galleries.map(g =>
        `<tr>
            <td><span class="badge ${g.source === 'nhentai' ? 'bg-danger' : 'bg-info'}">${g.source}</span></td>
            <td class="font-monospace">${g.source_id}</td>
            <td>${g.title}</td>
            <td>${g.artist || '-'}</td>
            <td>${g.total_pages}p</td>
        </tr>`
    ).join('');
    document.getElementById('export_table_wrapper').style.display = 'block';
}

// ─── Auto-load on page enter ───
document.addEventListener('DOMContentLoaded', () => {
    loadDashboard();
    loadCookies();
    loadSettings();
    startProgressPoller();
});
</script>
</body>
</html>
