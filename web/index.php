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
                                <label class="form-label">cf_clearance <span class="text-danger">*</span></label>
                                <input type="text" class="form-control font-monospace" id="cookie_nh_cf_clearance" placeholder="访问 nhentai.net 后从浏览器 DevTools → Application → Cookies → nhentai.net 复制">
                                <div class="form-text">Cloudflare 验证 Cookie，访问 nhentai.net 后浏览器自动生成。这是 nhentai 唯一需要的 Cookie。</div>
                            </div>
                        </div>
                        <div class="tab-pane fade" id="tab_ex_cookies">
                            <div class="mb-3">
                                <label class="form-label">ipb_member_id</label>
                                <input type="text" class="form-control font-monospace" id="cookie_ex_ipb_member_id" placeholder="登录 exhentai 后的会员 ID">
                                <div class="form-text">从 exhentai.org 的 Cookie 中获取，登录后自动生成</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">ipb_pass_hash</label>
                                <input type="text" class="form-control font-monospace" id="cookie_ex_ipb_pass_hash" placeholder="登录 exhentai 后的密码哈希">
                                <div class="form-text">从 exhentai.org 的 Cookie 中获取，与 ipb_member_id 配对</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label">cf_clearance</label>
                                <input type="text" class="form-control font-monospace" id="cookie_ex_cf_clearance" placeholder="Cloudflare 放行 Cookie">
                                <div class="form-text">访问 exhentai.org 后浏览器自动生成</div>
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

            <div class="card">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span><i class="fas fa-robot text-primary me-1"></i>登录助手（自动获取 Cookie）</span>
                </div>
                <div class="card-body">
                    <p class="text-muted mb-3">自动打开一个浏览器窗口，你登录完成后 Cookie 自动写入配置文件。支持自动轮询检测和手动同步两种方式。</p>

                    <div class="row g-3 mb-3">
                        <div class="col-md-6">
                            <div class="card border h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="mb-0"><span class="badge bg-danger me-1">nhentai</span> 登录助手</h6>
                                        <span id="lh_nh_status" class="badge bg-secondary">就绪</span>
                                    </div>
                                    <p class="small text-muted mb-2" id="lh_nh_msg">点击启动浏览器，在打开的窗口中完成登录</p>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-danger" id="lh_nh_start" onclick="startLoginHelper('nhentai')"><i class="fas fa-play me-1"></i>启动</button>
                                        <button class="btn btn-sm btn-outline-warning" id="lh_nh_sync" onclick="doSyncCookies('nhentai')" disabled><i class="fas fa-sync me-1"></i>立即同步</button>
                                        <button class="btn btn-sm btn-outline-secondary" id="lh_nh_refresh" onclick="checkLoginStatus('nhentai')"><i class="fas fa-redo me-1"></i>刷新</button>
                                    </div>
                                    <div id="lh_nh_poll_indicator" class="mt-2 small d-none text-info"><i class="fas fa-spinner fa-spin me-1"></i>轮询中...</div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="card border h-100">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="mb-0"><span class="badge bg-info me-1">exhentai</span> 登录助手</h6>
                                        <span id="lh_ex_status" class="badge bg-secondary">就绪</span>
                                    </div>
                                    <p class="small text-muted mb-2" id="lh_ex_msg">点击启动浏览器，在打开的窗口中完成登录</p>
                                    <div class="d-flex gap-2">
                                        <button class="btn btn-sm btn-outline-info" id="lh_ex_start" onclick="startLoginHelper('exhentai')"><i class="fas fa-play me-1"></i>启动</button>
                                        <button class="btn btn-sm btn-outline-warning" id="lh_ex_sync" onclick="doSyncCookies('exhentai')" disabled><i class="fas fa-sync me-1"></i>立即同步</button>
                                        <button class="btn btn-sm btn-outline-secondary" id="lh_ex_refresh" onclick="checkLoginStatus('exhentai')"><i class="fas fa-redo me-1"></i>刷新</button>
                                    </div>
                                    <div id="lh_ex_poll_indicator" class="mt-2 small d-none text-info"><i class="fas fa-spinner fa-spin me-1"></i>轮询中...</div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="alert alert-primary mb-0">
                        <i class="fas fa-lightbulb me-1"></i>
                        <strong>使用说明</strong><br>
                        1. 点击「启动」按钮，会自动打开一个浏览器窗口<br>
                        2. 在打开的浏览器中访问对应网站并登录（如果还没登录）<br>
                        3. 等待 Cloudflare 验证通过（如需要）<br>
                        4. 系统会自动检测到 Cookie 并写入配置文件（轮询模式，无需手动操作）<br>
                        5. 也可以点击「立即同步」手动触发同步<br>
                        6. 完成后浏览器窗口会自动关闭
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
                            </div>
                        </div>
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
                <div class="card-header d-flex justify-content-between align-items-center">
                    <span>本地画廊列表</span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary" onclick="loadGalleries('')">全部</button>
                        <button class="btn btn-outline-danger" onclick="loadGalleries('nhentai')">nhentai</button>
                        <button class="btn btn-outline-info" onclick="loadGalleries('exhentai')">exhentai</button>
                        <button class="btn btn-outline-primary" onclick="loadGalleries()"><i class="fas fa-sync"></i></button>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>站点</th>
                                    <th>ID</th>
                                    <th>标题</th>
                                    <th>页数</th>
                                    <th>下载时间</th>
                                </tr>
                            </thead>
                            <tbody id="gallery_table_body">
                                <tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</td></tr>
                            </tbody>
                        </table>
                    </div>
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
        cf_clearance: document.getElementById('cookie_ex_cf_clearance').value,
    };
    const res = await api('save_config', { body: cfg });
    if (res.ok) showToast('Cookie 已保存', 'success');
    else showToast('保存失败: ' + (res.error || ''), 'danger');
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
async function doDownload() {
    const url = document.getElementById('dl_url').value.trim();
    if (!url) { showToast('请输入 URL', 'warning'); return; }
    clearOutput('dl_output');
    document.getElementById('dl_output').classList.add('show');
    document.getElementById('dl_output').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>下载中，请稍候...';

    const res = await api('download', { form: { action: 'download', url: url } });
    showOutput('dl_output', res.output || '下载完成', !res.ok);
    if (res.ok) showToast('下载成功!', 'success');
}

async function doDownloadById() {
    const source = document.getElementById('dl_source').value;
    const id = document.getElementById('dl_id').value.trim();
    const gid = document.getElementById('dl_gid').value.trim();
    const token = document.getElementById('dl_token').value.trim();

    clearOutput('dl_output');
    document.getElementById('dl_output').classList.add('show');
    document.getElementById('dl_output').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>下载中，请稍候...';

    let form;
    if (gid && token) {
        form = { action: 'download', gid: gid, token: token };
    } else if (id && source) {
        form = { action: 'download', source: source, id: id };
    } else {
        showToast('请输入 ID 或 GID+Token', 'warning');
        return;
    }
    const res = await api('download', { form: form });
    showOutput('dl_output', res.output || '下载完成', !res.ok);
    if (res.ok) showToast('下载成功!', 'success');
}

async function doBatchDownload() {
    const urls = document.getElementById('batch_urls').value.trim();
    if (!urls) { showToast('请输入 URL', 'warning'); return; }
    clearOutput('batch_output');
    document.getElementById('batch_output').classList.add('show');
    document.getElementById('batch_output').innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>批量下载中，请稍候...';

    const res = await fetch(API + '?action=batch_download', {
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

// ─── Gallery ───
async function loadGalleries(source) {
    const tbody = document.getElementById('gallery_table_body');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4"><i class="fas fa-spinner fa-spin me-1"></i>加载中...</td></tr>';

    const params = source ? '?action=get_galleries&source=' + source : '?action=get_galleries';
    const resp = await fetch(API + params);
    const data = await resp.json();

    if (!data.ok || !data.galleries || data.galleries.length === 0) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-4">暂无数据</td></tr>';
        return;
    }

    tbody.innerHTML = data.galleries.map(g =>
        `<tr>
            <td><span class="badge ${g.source === 'nhentai' ? 'bg-danger' : 'bg-info'}">${g.source}</span></td>
            <td class="font-monospace">${g.source_id}</td>
            <td>${g.title}</td>
            <td>${g.pages}p</td>
            <td class="small">${g.downloaded_at || '-'}</td>
        </tr>`
    ).join('');
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

// ─── Login Helper ───
let loginPollTimers = { nhentai: null, exhentai: null };

function loginHelperEl(source) {
    const prefix = 'lh_' + source.substring(0, 2);
    return {
        status: document.getElementById(prefix + '_status'),
        msg: document.getElementById(prefix + '_msg'),
        start: document.getElementById(prefix + '_start'),
        sync: document.getElementById(prefix + '_sync'),
        refresh: document.getElementById(prefix + '_refresh'),
        poll: document.getElementById(prefix + '_poll_indicator'),
    };
}

function loginHelperSetState(source, state, msg) {
    const el = loginHelperEl(source);
    const labels = {
        idle: ['bg-secondary', '就绪'],
        starting: ['bg-info', '启动中'],
        navigating: ['bg-info', '导航中'],
        waiting: ['bg-warning text-dark', '等待登录'],
        saving: ['bg-primary', '保存中'],
        completed: ['bg-success', '已完成'],
        error: ['bg-danger', '出错'],
        timeout: ['bg-danger', '超时'],
    };
    const [cls, label] = labels[state] || ['bg-secondary', state];
    el.status.textContent = label;
    el.status.className = 'badge ' + cls;
    el.msg.textContent = msg || '';

    el.start.disabled = (state !== 'idle' && state !== 'completed' && state !== 'error' && state !== 'timeout');
    el.sync.disabled = (state !== 'waiting' && state !== 'navigating');
    el.poll.classList.toggle('d-none', state !== 'waiting' && state !== 'navigating' && state !== 'saving' && state !== 'starting');
}

async function startLoginHelper(source) {
    loginHelperSetState(source, 'starting', '启动浏览器...');
    try {
        const res = await api('launch_login_helper', { form: { action: 'launch_login_helper', source: source } });
        if (!res.ok) {
            loginHelperSetState(source, 'error', res.error || 'Failed to launch');
            showToast('启动失败: ' + (res.error || ''), 'danger');
            return;
        }
        loginHelperSetState(source, 'navigating', '浏览器已打开，请在窗口中完成登录...');
        showToast(`登录助手已启动，浏览器窗口已打开，请在 ${source === 'nhentai' ? 'nhentai.net' : 'exhentai.org'} 页面登录`, 'info');

        // Start auto-polling
        if (loginPollTimers[source]) clearInterval(loginPollTimers[source]);
        loginPollTimers[source] = setInterval(() => checkLoginStatus(source), 3000);
    } catch (e) {
        loginHelperSetState(source, 'error', 'Request failed: ' + e.message);
        showToast('请求失败', 'danger');
    }
}

async function checkLoginStatus(source) {
    try {
        const res = await api('check_login_status', { form: { action: 'check_login_status', source: source } });
        if (!res.ok) return;

        if (res.status === 'starting' || res.status === 'navigating' || res.status === 'waiting' || res.status === 'saving') {
            loginHelperSetState(source, res.status, res.message || '等待中...');
            return;
        }

        if (res.status === 'completed') {
            loginHelperSetState(source, 'completed', 'Cookie 已获取并保存');
            if (loginPollTimers[source]) {
                clearInterval(loginPollTimers[source]);
                loginPollTimers[source] = null;
            }
            showToast(`${source} Cookie 获取成功！`, 'success');
            // Reload cookie fields
            loadCookies();
            return;
        }

        if (res.status === 'error' || res.status === 'timeout') {
            loginHelperSetState(source, res.status, res.message || '');
            if (loginPollTimers[source]) {
                clearInterval(loginPollTimers[source]);
                loginPollTimers[source] = null;
            }
            showToast(`${source}: ${res.message || 'Login failed'}`, 'warning');
            return;
        }

        // idle state - check if cookies already configured
        if (res.status === 'idle') {
            if (res.is_empty) {
                loginHelperSetState(source, 'idle', '就绪');
            } else {
                loginHelperSetState(source, 'completed', 'Cookie 已配置');
            }
        }
    } catch (e) {
        // silently retry on network errors
    }
}

async function doSyncCookies(source) {
    loginHelperSetState(source, 'saving', '正在同步 Cookie...');
    try {
        const res = await api('sync_cookies', { form: { action: 'sync_cookies', source: source } });
        if (res.ok && res.status === 'completed') {
            loginHelperSetState(source, 'completed', 'Cookie 同步成功');
            showToast(`${source} Cookie 同步成功！`, 'success');
            loadCookies();
        } else if (res.status === 'waiting') {
            const missing = (res.missing || []).join(', ');
            loginHelperSetState(source, 'waiting', `缺少 Cookie: ${missing}，请在浏览器中继续登录`);
            showToast(`Cookie 不全，缺少: ${missing}`, 'warning');
        } else {
            loginHelperSetState(source, 'error', res.message || '同步失败');
            showToast('同步失败', 'danger');
        }
    } catch (e) {
        loginHelperSetState(source, 'error', 'Sync request failed');
        showToast('同步请求失败', 'danger');
    }
}

// ─── Auto-load on page enter ───
document.addEventListener('DOMContentLoaded', () => {
    loadDashboard();
    loadCookies();
    loadSettings();
});
</script>
</body>
</html>
