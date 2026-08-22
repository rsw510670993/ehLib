<?php
$scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/index.php';
$base = rtrim(dirname($scriptName), '/');
$projectPath = realpath(__DIR__ . '/..');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:,">
    <title>ehLib 管理面板</title>
    <link href="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.1/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=30">
</head>
<body>

<div class="sidebar">
    <div class="brand"><i class="fas fa-book-open me-2"></i>ehLib</div>
    <ul class="nav flex-column mt-2">
        <li class="nav-group-label">配置</li>
        <li class="nav-item"><a class="nav-link active" href="#" data-page="settings"><i class="fas fa-sliders"></i>系统设置</a></li>
        <li class="nav-group-label">爬取</li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="sync"><i class="fas fa-arrows-rotate"></i>定时同步</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="crawl"><i class="fas fa-cloud-download-alt"></i>手动爬取</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="download"><i class="fas fa-download"></i>下载中心</a></li>
        <li class="nav-group-label">库</li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="gallery"><i class="fas fa-images"></i>图库</a></li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="cache"><i class="fas fa-database"></i>缓存</a></li>
        <li class="nav-group-label">维护</li>
        <li class="nav-item"><a class="nav-link" href="#" data-page="tools"><i class="fas fa-toolbox"></i>工具 & 维护</a></li>
    </ul>
</div>

<div class="main">
    <div class="main-header">
        <div><span id="page_title">系统设置</span> <small class="text-muted ms-2" id="page_subtitle">Cookie & 系统参数</small></div>
        <div class="download-pill d-none" id="global_dl_pill" onclick="toggleDownloadsTray()" role="button" title="点击查看下载进度">
            <i class="fas fa-download me-1"></i>
            <span class="pill-count me-1"><span id="global_dl_count">0</span> 个</span>
            <div class="pill-bar"><div id="global_dl_bar" style="width:0%"></div></div>
        </div>
    </div>

    <div class="main-content">

        <!-- ═══ Toast ═══ -->
        <div class="toast-container" id="toast_container"></div>

        <div class="modal fade" id="confirm_modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content" data-confirm-clickable="1">
                    <div class="modal-header">
                        <h5 class="modal-title" id="confirm_modal_title">确认操作</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" id="confirm_modal_body" data-confirm-clickable="1"></div>
                    <div class="modal-footer" data-confirm-clickable="1">
                        <button type="button" class="btn btn-outline-secondary" id="confirm_modal_cancel" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-danger" id="confirm_modal_ok" data-confirm-clickable="1">确认</button>
                    </div>
                </div>
            </div>
        </div>

        <div class="modal fade" id="prompt_modal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
            <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
                <div class="modal-content" data-prompt-clickable="1">
                    <div class="modal-header">
                        <h5 class="modal-title" id="prompt_modal_title">输入</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body" data-prompt-clickable="1">
                        <div id="prompt_modal_message" class="mb-2" data-prompt-clickable="1"></div>
                        <input type="text" class="form-control form-control-sm" id="prompt_modal_input" data-prompt-clickable="1">
                    </div>
                    <div class="modal-footer" data-prompt-clickable="1">
                        <button type="button" class="btn btn-outline-secondary" id="prompt_modal_cancel" data-bs-dismiss="modal">取消</button>
                        <button type="button" class="btn btn-primary" id="prompt_modal_ok" data-prompt-clickable="1">确定</button>
                    </div>
                </div>
            </div>
        </div>

<?php readfile(__DIR__ . '/_nav_new_body.php'); ?>

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

        <!-- ═══ 压缩审核比较 Modal（单本）════ -->
        <div class="modal fade compress-compare-modal" id="compress_compare_modal" tabindex="-1" aria-hidden="true"
             data-bs-backdrop="static">
            <div class="modal-dialog modal-fullscreen-xl-down modal-dialog-centered modal-dialog-scrollable" style="max-width:98vw;">
                <div class="modal-content">
                    <div class="modal-header py-2 align-items-start">
                        <div style="flex:1;min-width:0">
                            <h5 class="modal-title d-inline-block me-2" id="cc_title">压缩审核比较</h5>
                            <span id="cc_badge" class="badge me-2"></span>
                            <div class="small text-muted mt-1" id="cc_meta"></div>
                            <div class="small text-muted" id="cc_params" style="margin-top:2px;"></div>
                            <div id="cc_upgrade_hint" class="small text-info mt-1" style="display:none">
                                <i class="fas fa-info-circle me-1"></i>
                                如需发起其他漫画的压缩或查看任务进度，请前往「工具 & 维护 → 图片压缩」。
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-1 align-items-start">
                            <button type="button" class="btn btn-sm btn-success me-1" id="cc_btn_approve" title="批准整本：compression_status -> approved_pending_apply（不立即替换磁盘，等 Phase 2）">
                                <i class="fas fa-check me-1"></i>整本批准
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-warning me-1" id="cc_btn_rerun" title="用默认参数 88/4/5% 重跑一次整本压缩">
                                <i class="fas fa-arrows-rotate me-1"></i>整本重做（默认参数）
                            </button>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                    </div>
                    <div class="modal-body p-0" style="display:flex;min-height:78vh;max-height:82vh;">
                        <div id="cc_thumbs" class="cc-thumbs"></div>
                        <div id="cc_main" class="cc-main" style="flex:1;display:flex;flex-direction:column;min-width:0;background:#111;color:#e2e8f0;">
                            <div id="cc_toolbar" class="cc-toolbar d-none">
                                <div class="cc-page-nav">
                                    <button id="cc_prev" class="btn btn-sm btn-dark"><i class="fas fa-chevron-left"></i></button>
                                    <span id="cc_page_label" class="small mx-2">1 / 7</span>
                                    <button id="cc_next" class="btn btn-sm btn-dark"><i class="fas fa-chevron-right"></i></button>
                                </div>
                                <div class="small text-muted flex-grow-1 text-center">← / → 翻页 · 1 = Fit · 2 = 100% · Esc 关闭</div>
                            </div>
                            <div id="cc_pane_row" class="d-none" style="flex:1;display:flex;gap:2px;min-height:0;border-top:1px solid #1e293b;">
                                <div class="cc-pane" data-side="orig">
                                    <div class="cc-pane-head small" style="background:#1e293b;color:#94a3b8;padding:2px 6px;display:flex;justify-content:space-between;align-items:center;">
                                        <span><i class="fas fa-file-image me-1"></i>原图 <span id="cc_orig_tag"></span></span>
                                        <span class="d-flex align-items-center gap-1">
                                            <span class="btn-group btn-group-sm">
                                                <button class="btn btn-dark cc-zoom" data-zoom="fit" data-side="orig">Fit</button>
                                                <button class="btn btn-dark cc-zoom" data-zoom="100" data-side="orig">100%</button>
                                                <button class="btn btn-dark cc-zoom" data-zoom="200" data-side="orig">200%</button>
                                            </span>
                                            <button class="btn btn-dark btn-sm cc-copy-path" data-side="orig" title="复制此图磁盘路径"><i class="far fa-copy"></i></button>
                                        </span>
                                    </div>
                                    <div class="cc-scroll" id="cc_scroll_orig"><img id="cc_img_orig" alt=""></div>
                                    <div class="cc-meta small" id="cc_meta_orig" style="background:#1e293b;color:#94a3b8;padding:2px 6px;min-height:22px;"></div>
                                </div>
                                <div class="cc-pane-divider"></div>
                                <div class="cc-pane" data-side="cmp">
                                    <div class="cc-pane-head small" style="background:#1e293b;color:#94a3b8;padding:2px 6px;display:flex;justify-content:space-between;align-items:center;">
                                        <span><i class="fas fa-compress me-1"></i>压缩候选 <span id="cc_cmp_tag"></span></span>
                                        <span class="d-flex align-items-center gap-1">
                                            <span class="btn-group btn-group-sm">
                                                <button class="btn btn-dark cc-zoom" data-zoom="fit" data-side="cmp">Fit</button>
                                                <button class="btn btn-dark cc-zoom" data-zoom="100" data-side="cmp">100%</button>
                                                <button class="btn btn-dark cc-zoom" data-zoom="200" data-side="cmp">200%</button>
                                            </span>
                                            <button class="btn btn-dark btn-sm cc-copy-path" data-side="cmp" title="复制此图磁盘路径"><i class="far fa-copy"></i></button>
                                        </span>
                                    </div>
                                    <div class="cc-scroll" id="cc_scroll_cmp">
                                        <div id="cc_cmp_placeholder" class="text-center text-muted px-3" style="display:none"></div>
                                        <img id="cc_img_cmp" alt="">
                                    </div>
                                    <div class="cc-meta small" id="cc_meta_cmp" style="background:#1e293b;color:#94a3b8;padding:2px 6px;min-height:22px;"></div>
                                </div>
                            </div>
                            <div id="cc_empty" class="d-flex align-items-center justify-content-center h-100 text-muted">
                                <div class="text-center">
                                    <i class="fas fa-hourglass-half fa-3x mb-2"></i>
                                    <div id="cc_empty_text">加载压缩审核信息…</div>
                                </div>
                            </div>
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
<script src="assets/js/navigation.js?v=7"></script>
<script src="assets/js/config.js?v=2"></script>
<script src="assets/js/download.js?v=3"></script>
<script src="assets/js/cache.js?v=29"></script>
<script src="assets/js/gallery.js?v=14"></script>
<script src="assets/js/compress_compare.js?v=3"></script>
<script src="assets/js/compression.js?v=2"></script>
<script src="assets/js/reader.js?v=2"></script>
<script src="assets/js/export.js"></script>
<script src="assets/js/refresh_targets.js?v=4"></script>
<script src="assets/js/test_verify.js"></script>
</body>
</html>
