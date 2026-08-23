// ─── Navigation alias map (old → new) ───
const PAGE_ALIASES = {
    dashboard:        'tools',
    config:           'settings',
    cookies:          'settings',
    refresh_targets:  'sync',
    'crawl-history':  'sync',
    'test-verify':    'tools',
    export:           'tools',
};

function resolvePage(name) {
    return PAGE_ALIASES[name] || name;
}

// ─── Page switching ───
function switchPage(name) {
    const page = resolvePage(name);
    document.querySelectorAll('.page-section').forEach(el => el.classList.add('section-hidden'));
    document.querySelectorAll('.sidebar .nav-link').forEach(el => el.classList.remove('active'));
    const active = document.querySelector(`[data-page="${page}"]`);
    if (active) active.classList.add('active');
    const pageEl = document.getElementById('page_' + page);
    if (pageEl) pageEl.classList.remove('section-hidden');

    const titles = {
        settings: ['系统设置',    'Cookie & 系统参数'],
        sync:     ['定时同步',    '远程收藏作者 & 已结束任务'],
        crawl:    ['手动爬取',    '关键词爬取 + 队列'],
        download: ['下载中心',    '单本 / 批量 / 重试未完成'],
        gallery:  ['图库',        '已下载的画廊列表'],
        cache:    ['缓存浏览',    '本地索引检索 + 批量下载'],
        tools:    ['工具 & 维护', '仪表盘 / 校对 / 图片压缩 / 数据导出'],
    };
    const t = titles[page] || ['页面', ''];
    const titleEl = document.getElementById('page_title');
    const subEl = document.getElementById('page_subtitle');
    if (titleEl) titleEl.textContent = t[0];
    if (subEl) subEl.textContent = t[1];

    // page-enter initializers
    if (page === 'settings') { loadCookies(); loadSettings(); }
    if (page === 'sync')     { loadCrawlHistoryPage(); }
    if (page === 'crawl')    { loadCrawlLanguages(); loadSearchPresets(); loadCrawlQueue(); }
    if (page === 'cache')    { loadCacheLanguages(); loadCachePage(1); }
    if (page === 'download') { /* 下载中心按需触发，无预加载 */ }
    if (page === 'gallery')  { loadGalleries(); }
    if (page === 'tools')    {
        loadDashboard();
        tvLoadList(); pollVerifyStatus();
        var compressionPane = document.getElementById('tab_tools_compression');
        if (compressionPane && compressionPane.classList.contains('active') && typeof loadCompressionPage === 'function') {
            loadCompressionPage();
        }
    }
}

document.querySelectorAll('.sidebar .nav-link').forEach(a => {
    a.addEventListener('click', e => { e.preventDefault(); switchPage(a.dataset.page); });
});

// ——— 容量单位自动换算：B / KB / MB / GB / TB（整数 &lt; 1KB 不带小数，其余保留 2 位小数，逗号千分位）———
function formatBytes(bytes, digits = 2) {
    if (bytes === null || bytes === undefined || isNaN(bytes)) return '-';
    let n = Number(bytes);
    if (!isFinite(n) || n <= 0) return '0 B';
    const units = ['B','KB','MB','GB','TB','PB'];
    let i = 0;
    while (n >= 1024 && i < units.length - 1) { n /= 1024; i++; }
    const useFixed = i === 0 ? 0 : (n < 10 && digits > 0 ? digits : (n < 100 ? Math.max(1, digits - 1) : 0));
    const s = n.toFixed(useFixed);
    // 千分位
    const [intPart, decPart] = s.split('.');
    const withComma = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
    return decPart ? `${withComma}.${decPart} ${units[i]}` : `${withComma} ${units[i]}`;
}
function formatInt(n) {
    if (n === null || n === undefined || isNaN(n)) return '-';
    return String(Math.floor(Number(n))).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
}

// ─── Dashboard (inside Tools tab) ───
async function loadDashboard() {
    const cards = document.getElementById('stats_cards');
    if (!cards) return;
    const data = await api('get_stats');
    const g = document.getElementById('stat_galleries'); if (g) g.textContent = formatInt(data?.gallery_count);
    const db = document.getElementById('stat_db'); if (db) { db.textContent = data?.db_exists ? '正常' : '未创建'; db.style.color = data?.db_exists ? '#22c55e' : '#94a3b8'; }
    const v = document.getElementById('stat_venv'); if (v) { v.textContent = data?.venv_exists ? '就绪' : '缺失'; v.style.color = data?.venv_exists ? '#22c55e' : '#ef4444'; }
    const c = document.getElementById('stat_config'); if (c) { c.textContent = data?.config_file ? '已配置' : '未配置'; c.style.color = data?.config_file ? '#22c55e' : '#ef4444'; }

    const sc = document.getElementById('stat_cache_count');  if (sc) sc.textContent = formatInt(data?.search_cache_count);
    const dbs = document.getElementById('stat_db_size');     if (dbs) dbs.textContent = formatBytes(data?.db_size, 2);
    const ths = document.getElementById('stat_thumbs_size'); if (ths) ths.textContent = formatBytes(data?.thumbs_size, 2);
    const dls = document.getElementById('stat_downloads_size'); if (dls) dls.textContent = formatBytes(data?.downloads_size, 2);
}

// ─── Back-compat: old entrypoints ───
function loadCrawlHistoryPage() {
    loadRefreshTargets ? loadRefreshTargets() : (typeof loadCrawlHistory === 'function' && loadCrawlHistory());
}

// ─── Auto-load on page enter ───
document.addEventListener('DOMContentLoaded', () => {
    switchPage('settings');
});
