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
    <link rel="stylesheet" href="assets/css/app.css?v=17">
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
<script src="assets/js/config.js"></script>
<script src="assets/js/download.js?v=3"></script>
<script src="assets/js/gallery.js?v=8"></script>
<script src="assets/js/reader.js"></script>
<script src="assets/js/export.js"></script>
<script src="assets/js/cache.js?v=23"></script>
<script src="assets/js/refresh_targets.js?v=4"></script>
<script src="assets/js/test_verify.js"></script>
</body>
</html>
