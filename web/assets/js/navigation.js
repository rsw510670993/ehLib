// ─── Navigation alias map (old → new) ───
const PAGE_ALIASES = {
    dashboard:      'tools',
    config:         'settings',
    cookies:        'settings',
    'crawl-history':'refresh_targets',
    'test-verify':  'tools',
    export:         'tools',
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
        settings:        ['系统设置',         'Cookie & 系统参数'],
        refresh_targets: ['作者同步 & 刷新',  '远程收藏作者 & 爬取队列管理'],
        cache:           ['发现 & 检索',       '爬取 exhentai & 本地缓存检索'],
        download:        ['下载中心',         '单本 / 批量 / 重试未完成'],
        gallery:         ['本地图库',         '已下载的画廊列表'],
        tools:           ['工具 & 维护',      '仪表盘 / 校对 / 数据导出'],
    };
    const t = titles[page] || ['页面', ''];
    const titleEl = document.getElementById('page_title');
    const subEl = document.getElementById('page_subtitle');
    if (titleEl) titleEl.textContent = t[0];
    if (subEl) subEl.textContent = t[1];

    // page-enter initializers
    if (page === 'settings')        { loadCookies(); loadSettings(); }
    if (page === 'refresh_targets') { loadCrawlHistoryPage(); }
    if (page === 'cache')           { loadCacheLanguages(); loadCrawlLanguages(); loadCachePage(1); loadCrawlQueue(); }
    if (page === 'gallery')         { loadGalleries(); }
    if (page === 'tools')           {
        loadDashboard();
        tvLoadList(); pollVerifyStatus();
    }
}

document.querySelectorAll('.sidebar .nav-link').forEach(a => {
    a.addEventListener('click', e => { e.preventDefault(); switchPage(a.dataset.page); });
});

// ─── Dashboard (inside Tools tab) ───
async function loadDashboard() {
    const cards = document.getElementById('stats_cards');
    if (!cards) return;
    const data = await api('get_stats');
    const g = document.getElementById('stat_galleries'); if (g) g.textContent = data.gallery_count ?? '-';
    const db = document.getElementById('stat_db'); if (db) { db.textContent = data.db_exists ? '正常' : '未创建'; db.style.color = data.db_exists ? '#22c55e' : '#94a3b8'; }
    const v = document.getElementById('stat_venv'); if (v) { v.textContent = data.venv_exists ? '就绪' : '缺失'; v.style.color = data.venv_exists ? '#22c55e' : '#ef4444'; }
    const c = document.getElementById('stat_config'); if (c) { c.textContent = data.config_file ? '已配置' : '未配置'; c.style.color = data.config_file ? '#22c55e' : '#ef4444'; }
}

// ─── Back-compat: old entrypoints ───
function loadCrawlHistoryPage() {
    loadRefreshTargets ? loadRefreshTargets() : (typeof loadCrawlHistory === 'function' && loadCrawlHistory());
}

// ─── Auto-load on page enter ───
document.addEventListener('DOMContentLoaded', () => {
    switchPage('settings');
});
