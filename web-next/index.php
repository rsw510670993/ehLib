<?php
$scriptName = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '/index.php';
$base = rtrim(dirname($scriptName), '/');
$projectPath = realpath(__DIR__ . '/..');
$v = 'dev9';
// 开发期 web-next/ 复用线上 JS；发布到 web/ 后自动走同目录 assets/js
$jsBase = is_dir(__DIR__ . '/assets/js') ? 'assets/js' : '../web/assets/js';
?>
<!DOCTYPE html>
<html lang="zh-CN" data-theme="dark">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="data:,">
    <title>ehLib</title>
    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= $v ?>">
    <link rel="stylesheet" href="assets/css/next.css?v=<?= $v ?>">
    <script>try{var t=localStorage.getItem('ehlib-theme');if(t==='dark'||t==='light'){document.documentElement.setAttribute('data-theme',t);}}catch(e){}</script>
</head>
<body>

<div class="app">

    <!-- ═══ 侧栏 ═══ -->
    <aside class="sidebar">
        <div class="brand">
            <span class="logo"><i class="fas fa-book-open"></i></span>
            <span class="brand-name">ehLib</span>
            <span class="brand-tag">dev</span>
        </div>

        <nav class="nav">
            <div class="nav-group">
                <div class="nav-label">浏览</div>
                <a class="nav-link" href="#" data-page="gallery"><i class="fas fa-images"></i><span>图库</span></a>
                <a class="nav-link" href="#" data-page="cache"><i class="fas fa-database"></i><span>缓存</span></a>
            </div>
            <div class="nav-group">
                <div class="nav-label">采集</div>
                <a class="nav-link" href="#" data-page="crawl"><i class="fas fa-cloud-download-alt"></i><span>手动爬取</span></a>
                <a class="nav-link" href="#" data-page="sync"><i class="fas fa-arrows-rotate"></i><span>定时同步</span></a>
                <a class="nav-link" href="#" data-page="download"><i class="fas fa-download"></i><span>下载中心</span></a>
            </div>
            <div class="nav-group">
                <div class="nav-label">系统</div>
                <a class="nav-link active" href="#" data-page="settings"><i class="fas fa-sliders"></i><span>系统设置</span></a>
                <a class="nav-link" href="#" data-page="tools"><i class="fas fa-toolbox"></i><span>工具 &amp; 维护</span></a>
            </div>
        </nav>

        <div class="side-foot">
            <div class="side-foot-line"><i class="fas fa-server"></i><span>本地 NAS · PHP <?= PHP_VERSION ?></span></div>
        </div>
    </aside>

    <!-- ═══ 主区 ═══ -->
    <div class="main">

        <header class="topbar">
            <div class="topbar-title">
                <h1 id="page_title">系统设置</h1>
                <p id="page_subtitle">Cookie &amp; 系统参数</p>
            </div>
            <div class="topbar-right">
                <div class="download-pill d-none" id="global_dl_pill" onclick="toggleDownloadsTray()" role="button" title="点击查看下载进度">
                    <i class="fas fa-download"></i>
                    <span class="pill-count"><span id="global_dl_count">0</span> 个</span>
                    <div class="pill-bar"><div id="global_dl_bar" style="width:0%"></div></div>
                </div>
                <button class="icon-btn" id="theme_toggle" type="button" aria-label="切换深浅主题" title="切换深浅主题">
                    <i class="fas fa-circle-half-stroke"></i>
                </button>
            </div>
        </header>

        <main class="content">

            <div class="toast-container" id="toast_container"></div>

            <?php include __DIR__ . '/_nav.php'; ?>

            <?php include __DIR__ . '/_overlays.php'; ?>

        </main>

        <!-- ═══ 常驻下载托盘 ═══ -->
        <div id="active_downloads_card" class="downloads-tray collapsed">
            <div class="downloads-tray-header" onclick="toggleDownloadsTray()">
                <span><i class="fas fa-download me-1"></i>下载进度 <span class="small text-muted" id="tray_status_label">(空闲)</span></span>
                <button class="downloads-tray-toggle" id="tray_toggle_btn" onclick="event.stopPropagation();toggleDownloadsTray()"><i class="fas fa-chevron-up"></i></button>
            </div>
            <div class="downloads-tray-body" id="active_downloads_body"></div>
        </div>

    </div>
</div>

<script src="https://cdn.bootcdn.net/ajax/libs/twitter-bootstrap/5.3.1/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    var root = document.documentElement;
    var btn = document.getElementById('theme_toggle');
    if (btn) btn.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);
        try { localStorage.setItem('ehlib-theme', next); } catch (e) {}
    });
})();
</script>
<script src="<?= $jsBase ?>/core.js?v=2"></script>
<script src="<?= $jsBase ?>/navigation.js?v=7"></script>
<script src="<?= $jsBase ?>/config.js?v=2"></script>
<script src="<?= $jsBase ?>/download.js?v=3"></script>
<script src="<?= $jsBase ?>/cache.js?v=29"></script>
<script src="<?= $jsBase ?>/gallery.js?v=17"></script>
<script src="<?= $jsBase ?>/compress_compare.js?v=5"></script>
<script src="<?= $jsBase ?>/compression.js?v=5"></script>
<script src="<?= $jsBase ?>/reader.js?v=2"></script>
<script src="<?= $jsBase ?>/export.js"></script>
<script src="<?= $jsBase ?>/refresh_targets.js?v=4"></script>
<script src="<?= $jsBase ?>/test_verify.js"></script>
</body>
</html>
