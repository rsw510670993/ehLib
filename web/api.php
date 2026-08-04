<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$root = realpath(__DIR__ . '/..');
if (DIRECTORY_SEPARATOR === '\\') {
    $venv_python = $root . '/venv/Scripts/python.exe';
} else {
    $venv_python = $root . '/venv/bin/python';
}
$python = is_file($venv_python) ? $venv_python : (DIRECTORY_SEPARATOR === '\\' ? 'python' : 'python3');

function json_exit($data, $ok = true) {
    $data['ok'] = $ok;
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function error_exit($msg) {
    json_exit(['error' => $msg], false);
}

// ─── Word-boundary match helpers v5 (merge REGEXP calls → 更少的 PCRE 调用) ───
//
// 核心设计 (解决之前的「每列多次 REGEXP 导致 scope=all 慢 10x 问题」):
//   ┌──────────────────────────────────────────────────────────────────────────┐
//   │ 1. 每个 helper 返回「pattern 字符串」，不直接 push 到 params              │
//   │ 2. 对「同一个字段」收集所有条件：                                       │
//   │       * 同一 token 跨多个 scope → OR 合并为 (?:p_a)|(?:p_b)              │
//   │       * 多个 token AND  → 前瞻合并为 (?s)(?=.*P1)(?=.*P2) ... .*         │
//   │ 3. 最终每个字段只 push 1 个 REGEXP 调用，SQLite 每行只回调 1 次 PCRE     │
//   └──────────────────────────────────────────────────────────────────────────┘
//
//  词边界 (strict_token):
//    * 字母/数字/罗马音 token：  (?<![A-Za-z0-9_-])X(?![A-Za-z0-9_-])
//    * 日文假名/汉字 token：无词边界，直接包含匹配即可
//
//  非内容命名空间排除 (tags/tags_cn scope)：用「负向前瞻 + 两种字段顺序」写成一个单正则
function _has_ascii($s) {
    return (bool)preg_match('/[A-Za-z0-9]/', (string)$s);
}
function _regexp_safe($s) {
    static $special = ['\\','^','$','.','[',']','|','(',')','?','*','+','{','}','/'];
    $out = '';
    foreach (str_split((string)$s) as $c) {
        $out .= in_array($c, $special, true) ? '\\' . $c : $c;
    }
    return $out;
}
function _strict_token_safe($needle) {
    $safe = _regexp_safe(trim((string)$needle));
    if ($safe === '') return '';
    if (_has_ascii($needle)) {
        return '(?<![A-Za-z0-9_\-])' . $safe . '(?![A-Za-z0-9_\-])';
    }
    return $safe;
}
/** Split query into AND tokens. Separators: [ , ; ， & ] + 双空格 */
function _split_and_tokens($kw) {
    $raw = trim((string)$kw);
    if ($raw === '') return [];
    $parts = preg_split('/[\s]*[,;，&][\s]*|[\s]{2,}/u', $raw);
    $out = [];
    foreach ($parts as $p) {
        $t = trim((string)$p);
        if ($t !== '') $out[] = $t;
    }
    if (empty($out)) $out[] = $raw;
    return array_values(array_unique($out));
}
// ——————— 单 token pattern factory (返回纯 pattern 字符串) ———————
const _NON_CONTENT_TYPES = ['artist','group','cosplayer','language'];
/**
 * 内容类 tag pattern: 在一个 JSON entry `{...}` 内，type 不是非内容命名空间 且 name 或 name_cn 命中 token
 * 支持 type 在前或 name/name_cn 在前两种字段顺序
 */
function _pattern_content_tag($needle) {
    $n = trim((string)$needle);
    if ($n === '') return '^$';
    $ns = _strict_token_safe($n);
    $alt = implode('|', array_map('_regexp_safe', _NON_CONTENT_TYPES));
    $type_ok  = '"type"\s*:\s*"(?!(?:'.$alt.')")[^"]+"';
    $name_hit    = '"name"\s*:\s*"[^"]*' . $ns . '[^"]*"';
    $name_cn_hit = '"name_cn"\s*:\s*"[^"]*' . $ns . '[^"]*"';
    $any_name = '(?:' . $name_hit . '|' . $name_cn_hit . ')';
    return
        '\{' .
        '(?:' .
          '[^{}]{0,320}' . $type_ok . '[^{}]{0,320}' . $any_name .
          '|' .
          '[^{}]{0,320}' . $any_name . '[^{}]{0,320}' . $type_ok .
        ')' .
        '[^{}]{0,320}' .
        '\}';
}
/**
 * 作者 JSON 模式：在一个 entry 内 type=artist 且 name 或 name_cn 命中 token
 */
function _pattern_json_author($needle) {
    $n = trim((string)$needle);
    if ($n === '') return '^$';
    $ns = _strict_token_safe($n);
    $type_artist = '"type"\s*:\s*"artist"';
    $name_hit    = '"name"\s*:\s*"[^"]*' . $ns . '[^"]*"';
    $name_cn_hit = '"name_cn"\s*:\s*"[^"]*' . $ns . '[^"]*"';
    $any_name = '(?:' . $name_hit . '|' . $name_cn_hit . ')';
    return
        '\{' .
        '(?:' .
          '[^{}]{0,320}' . $type_artist . '[^{}]{0,320}' . $any_name .
          '|' .
          '[^{}]{0,320}' . $any_name . '[^{}]{0,320}' . $type_artist .
        ')' .
        '[^{}]{0,320}' .
        '\}';
}
/** sc.artist 列多值分隔匹配 */
function _pattern_artist_col($needle) {
    $n = trim((string)$needle);
    if ($n === '') return '^$';
    $safe = _regexp_safe($n);
    $sep = '(?:^|[\s,;&|\/]+)';
    return $sep . $safe . '(?=[\s,;&|\/]+|$)';
}
/** title / title_jp 列单 pattern */
function _pattern_title_col($needle) {
    $n = trim((string)$needle);
    if ($n === '') return '^$';
    return _strict_token_safe($n);
}
// ——————— pattern 列表合并 (OR merge / AND lookahead merge) ———————
/** 多个 patterns 用 OR 合并 → 1 次 REGEXP 调 用 */
function _merge_or_patterns(array $patterns) {
    $patterns = array_values(array_filter($patterns, function($p){ return $p !== '' && $p !== null; }));
    if (count($patterns) === 0) return '';
    if (count($patterns) === 1)  return $patterns[0];
    $wrapped = [];
    foreach ($patterns as $p) $wrapped[] = '(?:' . $p . ')';
    return implode('|', $wrapped);
}
/** 多个 patterns 用 AND 合并 (前瞻断言) → 仍只 1 次 REGEXP 调用 */
function _merge_and_patterns(array $patterns) {
    $patterns = array_values(array_filter($patterns, function($p){ return $p !== '' && $p !== null; }));
    if (count($patterns) === 0) return '';
    if (count($patterns) === 1)  return $patterns[0];
    $lookaheads = '';
    foreach ($patterns as $p) $lookaheads .= '(?=[\s\S]*' . $p . ')';
    // 加上任意字符匹配，满足大多数 PCRE 引擎的「主表达式必须匹配内容」
    return '(?s)' . $lookaheads . '[\s\S]*';
}
/** Final SQL helper: $field REGEXP ?  → 把合并好的 pattern push 到 params */
function _sql_regexp($field, $pattern, &$params) {
    if ($pattern === '' || $pattern === null) return '';
    $params[] = $pattern;
    return "$field REGEXP ?";
}


function normalize_path($path) {
    $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string)$path);
    if (preg_match('/^[A-Za-z]:' . preg_quote(DIRECTORY_SEPARATOR, '/') . '/', $path)) {
        $prefix = strtoupper(substr($path, 0, 2));
        $rest = substr($path, 2);
        $parts = preg_split('/[\\\\\/]+/', ltrim($rest, '\\/'));
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                array_pop($normalized);
                continue;
            }
            $normalized[] = $part;
        }
        return $prefix . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $normalized);
    }
    if (substr($path, 0, strlen(DIRECTORY_SEPARATOR)) === DIRECTORY_SEPARATOR) {
        $parts = preg_split('/[\\\\\/]+/', ltrim($path, '\\/'));
        $normalized = [];
        foreach ($parts as $part) {
            if ($part === '' || $part === '.') continue;
            if ($part === '..') {
                array_pop($normalized);
                continue;
            }
            $normalized[] = $part;
        }
        return DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $normalized);
    }
    $parts = preg_split('/[\\\\\/]+/', $path);
    $normalized = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') {
            array_pop($normalized);
            continue;
        }
        $normalized[] = $part;
    }
    return implode(DIRECTORY_SEPARATOR, $normalized);
}

function resolve_download_path() {
    global $root;
    $config = read_config();
    $download_path = $config['download']['path'] ?? './downloads';
    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $download_path) || substr($download_path, 0, 1) === '/' || substr($download_path, 0, 1) === '\\') {
        return normalize_path($download_path);
    }
    return normalize_path($root . DIRECTORY_SEPARATOR . $download_path);
}

function is_path_within($child, $parent) {
    $child = rtrim(strtolower(normalize_path($child)), DIRECTORY_SEPARATOR);
    $parent = rtrim(strtolower(normalize_path($parent)), DIRECTORY_SEPARATOR);
    $parent_with_sep = $parent . DIRECTORY_SEPARATOR;
    return $child === $parent || substr($child, 0, strlen($parent_with_sep)) === $parent_with_sep;
}



function count_downloaded_pages($local_path) {
    $local_path = trim((string)$local_path);
    if ($local_path === '') return 0;
    $dir = normalize_path($local_path);
    if (!is_dir($dir)) return 0;
    $count = 0;
    $items = scandir($dir);
    if ($items === false) return 0;
    foreach ($items as $item) {
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (!is_file($path)) continue;
        $ext = strtolower(pathinfo($item, PATHINFO_EXTENSION));
        if (!in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true)) continue;
        $name = pathinfo($item, PATHINFO_FILENAME);
        if (ctype_digit($name)) $count++;
    }
    return $count;
}
function public_gallery_image_url($source, $source_id, $file) {
    if ($source === '' || $source_id === '' || $file === '') return '';
    $public_source_id = str_replace('/', '_', $source_id);
    foreach ([$source, $public_source_id, $file] as $part) {
        if (strpos($part, '/') !== false || strpos($part, '\\') !== false || $part === '.' || $part === '..') return '';
    }
    return 'ehlib_images/' . rawurlencode($source) . '/' . rawurlencode($public_source_id) . '/' . rawurlencode($file);
}

function public_image_url_from_path($path) {
    $download_base = resolve_download_path();
    $normalized = normalize_path($path);
    if (!is_path_within($normalized, $download_base)) return '';
    $base = rtrim(normalize_path($download_base), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    $relative = substr($normalized, strlen($base));
    if ($relative === false || $relative === '') return '';
    $parts = preg_split('/[\\\\\/]+/', $relative);
    $encoded = [];
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..') return '';
        $encoded[] = rawurlencode($part);
    }
    return 'ehlib_images/' . implode('/', $encoded);
}

function find_gallery_image_path($local_path, $page) {
    $exts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if ($page === 'cover') {
        foreach (['001', '1'] as $name) {
            foreach ($exts as $ext) {
                $candidate = $local_path . DIRECTORY_SEPARATOR . $name . '.' . $ext;
                if (is_file($candidate)) return $candidate;
            }
        }
        foreach ($exts as $ext) {
            $candidate = $local_path . DIRECTORY_SEPARATOR . 'cover.' . $ext;
            if (is_file($candidate)) return $candidate;
        }
        return '';
    }
    $page_num = (int)$page;
    if ($page_num < 1) return '';
    foreach ([sprintf('%03d', $page_num), (string)$page_num] as $name) {
        foreach ($exts as $ext) {
            $candidate = $local_path . DIRECTORY_SEPARATOR . $name . '.' . $ext;
            if (is_file($candidate)) return $candidate;
        }
    }
    return '';
}

function public_cover_url($source, $source_id) {
    global $root;
    $base = normalize_path($root . DIRECTORY_SEPARATOR . 'web' . DIRECTORY_SEPARATOR . 'ehlib_images');
    $public_source_id = str_replace('/', '_', $source_id);
    $dir = normalize_path($base . DIRECTORY_SEPARATOR . $source . DIRECTORY_SEPARATOR . $public_source_id);
    if (!is_path_within($dir, $base) || !is_dir($dir)) return '';
    $img_path = find_gallery_image_path($dir, 'cover');
    return $img_path ? public_gallery_image_url($source, $source_id, basename($img_path)) : '';
}

function delete_dir_recursive($dir) {
    if (!is_dir($dir)) return true;
    $items = scandir($dir);
    if ($items === false) return false;
    foreach ($items as $item) {
        if ($item === '.' || $item === '..') continue;
        $path = $dir . DIRECTORY_SEPARATOR . $item;
        if (is_dir($path)) {
            if (!delete_dir_recursive($path)) return false;
            continue;
        }
        if (!@unlink($path)) return false;
    }
    return @rmdir($dir);
}

function run_python($args, $timeout = 120) {
    global $root, $python;
    $cmd = escapeshellcmd($python) . ' -m ehlib';
    foreach ($args as $a) {
        $cmd .= ' ' . escapeshellarg($a);
    }

    $descriptors = [
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $proc = proc_open($cmd, $descriptors, $pipes, $root);

    if (!is_resource($proc)) {
        return ['ok' => false, 'error' => 'Failed to start process'];
    }

    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);

    $stdout = '';
    $stderr = '';
    $start = time();
    $done = false;
    $exit_code = -1;
    $stall_count = 0;
    $chunk_callback = isset($GLOBALS['RUN_PYTHON_CHUNK_HOOK']) && is_callable($GLOBALS['RUN_PYTHON_CHUNK_HOOK'])
        ? $GLOBALS['RUN_PYTHON_CHUNK_HOOK'] : null;

    while (!$done) {
        if (time() - $start > $timeout) {
            @proc_terminate($proc, 9);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            @proc_close($proc);
            return ['ok' => false, 'error' => 'Command timed out (' . $timeout . 's)'];
        }

        // 检测下载取消标记
        if (is_file($root . '/data/download_cancel.flag')) {
            @proc_terminate($proc, 15);
            usleep(100000);
            @proc_terminate($proc, 9);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            @proc_close($proc);
            @unlink($root . '/data/download_cancel.flag');
            return ['ok' => false, 'error' => 'Download cancelled by user'];
        }

        $r = [$pipes[1], $pipes[2]];
        $w = null;
        $e = null;
        $sel = @stream_select($r, $w, $e, 1);
        if ($sel !== false && $sel > 0) {
            $stall_count = 0;
            foreach ($r as $pipe) {
                $data = @fread($pipe, 4096);
                if ($data === false || $data === '') continue;
                if ($pipe === $pipes[1]) $stdout .= $data;
                else $stderr .= $data;
                if ($chunk_callback) {
                    try {
                        call_user_func($chunk_callback, [
                            'stream' => ($pipe === $pipes[1]) ? 'stdout' : 'stderr',
                            'chunk' => $data,
                            'stdout_so_far' => $stdout,
                            'stderr_so_far' => $stderr,
                        ]);
                    } catch (Throwable $_t) {
                    }
                }
            }
        } elseif ($sel === false) {
            $stall_count++;
        } else {
            $stall_count++;
        }

        $status = @proc_get_status($proc);
        $is_running = is_array($status) && !empty($status['running']);

        if (!$is_running) {
            $remaining1 = @stream_get_contents($pipes[1]);
            $remaining2 = @stream_get_contents($pipes[2]);
            $stdout .= ($remaining1 === false ? '' : $remaining1);
            $stderr .= ($remaining2 === false ? '' : $remaining2);
            $exit_code = is_array($status) ? ($status['exitcode'] ?? -1) : -1;
            $done = true;
            break;
        }

        if ($stall_count > 10) {
            $check1 = @feof($pipes[1]);
            $check2 = @feof($pipes[2]);
            if ($check1 && $check2) {
                $exit_code = is_array($status) ? ($status['exitcode'] ?? -1) : -1;
                $done = true;
                break;
            }
            $stall_count = 5;
        }
    }

    @fclose($pipes[1]);
    @fclose($pipes[2]);
    @proc_close($proc);

    return [
        'ok' => $exit_code === 0,
        'stdout' => trim($stdout),
        'stderr' => trim($stderr),
        'exit_code' => $exit_code,
    ];
}

function run_python_background($args, $pid_file = null) {
    global $root, $python;
    $data_dir = $root . '/data';
    if (!is_dir($data_dir)) mkdir($data_dir, 0755, true);
    if ($pid_file === null) $pid_file = $data_dir . '/retry.pid';
    $tag = basename($pid_file, '.txt');

    $cmd_parts = [$python];
    $cmd_parts[] = '-m';
    $cmd_parts[] = 'ehlib';
    foreach ($args as $a) {
        $cmd_parts[] = $a;
    }
    $cmd_str = implode(' ', array_map('escapeshellarg', $cmd_parts));

    // Write a shell script
    $script_file = $data_dir . '/bg_' . $tag . '.sh';
    $log_file = $data_dir . '/bg_' . $tag . '.log';
    $script = '#!/bin/sh' . "\n"
        . 'export TZ=Asia/Tokyo' . "\n"
        . 'export PYTHONUNBUFFERED=1' . "\n"
        . 'echo $$ > ' . escapeshellarg($pid_file) . "\n"
        . 'cd ' . escapeshellarg($root) . "\n"
        . $cmd_str . ' >> ' . escapeshellarg($log_file) . ' 2>&1' . "\n"
        . 'rm -f ' . escapeshellarg($script_file) . "\n"
        . 'rm -f ' . escapeshellarg($pid_file) . "\n";
    file_put_contents($script_file, $script);
    chmod($script_file, 0755);

    exec('nohup ' . escapeshellarg($script_file) . ' > /dev/null 2>&1 &');

    usleep(500000);
    $pid = is_file($pid_file) ? trim(file_get_contents($pid_file)) : 'unknown';
    return $pid;
}

function is_crawl_worker_process($pid) {
    $pid = (int)$pid;
    if ($pid <= 0) return false;
    if (DIRECTORY_SEPARATOR === '\\') {
        $out = [];
        exec('tasklist /FI "PID eq ' . $pid . '" /NH 2>nul', $out);
        return count($out) > 1;
    }
    $proc_dir = '/proc/' . $pid;
    if (!is_dir($proc_dir)) return false;
    $cmdline = @file_get_contents($proc_dir . '/cmdline');
    if ($cmdline === false || $cmdline === '') return true;
    $cmdline = str_replace("\0", ' ', $cmdline);
    return strpos($cmdline, 'bg_crawl_worker_pid.sh') !== false
        || (strpos($cmdline, 'ehlib') !== false && strpos($cmdline, 'crawl-worker') !== false);
}

function run_python_locked($args, $timeout = 120, $chunk_callback = null) {
    global $root;
    $data_dir = $root . '/data';
    if (!is_dir($data_dir)) mkdir($data_dir, 0755, true);
    $lock_path = $data_dir . '/download.lock';
    $lock = @fopen($lock_path, 'c');
    if (!$lock) {
        return [
            'ok' => false,
            'error' => 'Failed to open download lock',
            'stdout' => '',
            'stderr' => '',
            'exit_code' => 75,
        ];
    }
    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        @fclose($lock);
        return [
            'ok' => false,
            'error' => 'Another download task is already running',
            'stdout' => '',
            'stderr' => 'Another download task is already running',
            'exit_code' => 75,
        ];
    }
    try {
        if (is_callable($chunk_callback)) {
            $prev_hook = isset($GLOBALS['RUN_PYTHON_CHUNK_HOOK']) && is_callable($GLOBALS['RUN_PYTHON_CHUNK_HOOK']) ? $GLOBALS['RUN_PYTHON_CHUNK_HOOK'] : null;
            $GLOBALS['RUN_PYTHON_CHUNK_HOOK'] = $chunk_callback;
            try {
                return run_python($args, $timeout);
            } finally {
                if ($prev_hook !== null) {
                    $GLOBALS['RUN_PYTHON_CHUNK_HOOK'] = $prev_hook;
                } else {
                    unset($GLOBALS['RUN_PYTHON_CHUNK_HOOK']);
                }
            }
        }
        return run_python($args, $timeout);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
}

function _t_split_aliases($name) {
    $s = (string)$name;
    if ($s === '') return [];
    $parts = preg_split('/\s*\|\s*/', $s);
    if (!is_array($parts)) return [$s];
    $out = [];
    foreach ($parts as $p) {
        $p = trim((string)$p);
        if ($p !== '') $out[] = $p;
    }
    if (!$out) return [$s];
    return $out;
}
function _t_lookup_ns(&$ns_map, $lookup_ns, $name) {
    if (!isset($ns_map[$lookup_ns])) return null;
    $m = $ns_map[$lookup_ns];
    $nm = (string)$name;
    if (isset($m[strtolower($nm)])) return $m[strtolower($nm)];
    // 多 alias 轮询： 'kazuto kirigaya | kirito' 逐个查
    $aliases = _t_split_aliases($nm);
    foreach ($aliases as $a) {
        if (isset($m[strtolower((string)$a)])) return $m[strtolower((string)$a)];
    }
    return null;
}
function &_t_get_translation_static() {
    static $loaded = false;
    static $ns_map = [];
    static $ns_alias = ['category' => 'reclass'];
    if (!$loaded) {
        $loaded = true;
        global $root;
        $db_path = $root . '/data/eh_tag_translation.json';
        if (is_file($db_path)) {
            $raw = @json_decode((string)@file_get_contents($db_path), true);
            $entries = is_array($raw) && isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;
            if (is_array($entries)) {
                foreach ($entries as $entry) {
                    $ns_name = $entry['namespace'] ?? '';
                    $ns_data = $entry['data'] ?? null;
                    if (!$ns_name || !is_array($ns_data)) continue;
                    $tag_map = [];
                    foreach ($ns_data as $tag_key => $tag_val) {
                        if (is_array($tag_val) && !empty($tag_val['name'])) {
                            $tag_map[strtolower((string)$tag_key)] = (string)$tag_val['name'];
                            // 对翻译 DB 自身 tag_key 若带 | 也同步拆各别名单独存，命中率更高
                            foreach (_t_split_aliases((string)$tag_key) as $alias_key) {
                                $ak = strtolower((string)$alias_key);
                                if (!isset($tag_map[$ak])) {
                                    $tag_map[$ak] = (string)$tag_val['name'];
                                }
                            }
                        }
                    }
                    $ns_map[$ns_name] = $tag_map;
                }
            }
        }
    }
    $bundle = ['loaded' => &$loaded, 'ns_map' => &$ns_map, 'ns_alias' => &$ns_alias];
    return $bundle;
}
function _t_translate_single($ns, $name, $extra_aliases = null) {
    $bundle = &_t_get_translation_static();
    $ns_map = &$bundle['ns_map'];
    $ns_alias = &$bundle['ns_alias'];
    $lookup_ns = $ns_alias[$ns] ?? $ns;
    $cn = _t_lookup_ns($ns_map, $lookup_ns, $name);
    if ($cn !== null && $cn !== '') return $cn;
    if (is_array($extra_aliases)) {
        foreach ($extra_aliases as $a) {
            $cn = _t_lookup_ns($ns_map, $lookup_ns, (string)$a);
            if ($cn !== null && $cn !== '') return $cn;
        }
    }
    return null;
}
function _t_translate_tags_json($tags_json_str, $tags_cn_json_str = '') {
    // 输入：两列都是 JSON 数组字符串，元素形如 {type,name,match_keys?,name_cn?}
    // 逻辑：优先用 $tags_cn_json_str；若它缺 name_cn 则用 $tags_json_str 的 (type,name+match_keys) 通过 EhTagTranslation 补 name_cn
    $tags_json_str = (string)$tags_json_str;
    $tags_cn_json_str = (string)$tags_cn_json_str;
    $use_tags = ($tags_cn_json_str !== '' && $tags_cn_json_str !== '[]') ? $tags_cn_json_str : $tags_json_str;
    if ($use_tags === '' || $use_tags === '[]') return '';
    $arr = @json_decode($use_tags, true);
    if (!is_array($arr)) return $use_tags;
    // 快速扫描：全部 tag 已有 name_cn → 直接返回（不触发翻译 DB 加载，保住响应预算）
    $need_lookup = false;
    foreach ($arr as $t) {
        if (!is_array($t)) continue;
        $existing_cn = isset($t['name_cn']) ? trim((string)$t['name_cn']) : '';
        if ($existing_cn === '') { $need_lookup = true; break; }
    }
    if (!$need_lookup) {
        return ($tags_cn_json_str !== '' && $tags_cn_json_str !== '[]') ? $tags_cn_json_str : json_encode($arr, JSON_UNESCAPED_UNICODE);
    }
    // 存在缺失 → 加载翻译 DB 逐 tag 补缺（保留已有 name_cn，只补缺失的）
    $changed = false;
    foreach ($arr as $i => $t) {
        if (!is_array($t)) continue;
        $existing_cn = isset($t['name_cn']) ? trim((string)$t['name_cn']) : '';
        if ($existing_cn !== '') continue;
        $ns = isset($t['type']) ? (string)$t['type'] : '';
        $name = isset($t['name']) ? (string)$t['name'] : '';
        if ($ns === '' || $name === '') continue;
        $extra = [];
        if (isset($t['match_keys']) && is_array($t['match_keys'])) {
            foreach ($t['match_keys'] as $mk) {
                if (is_string($mk) && $mk !== '') $extra[] = $mk;
            }
        }
        // 对 name 本身先拆 |，得到的 alias 作为候选；再拼 match_keys
        $name_aliases = _t_split_aliases($name);
        $all_extra = array_values(array_unique(array_merge($name_aliases, $extra)));
        $cn = _t_translate_single($ns, $name, $all_extra);
        if ($cn !== null && $cn !== '') {
            $arr[$i]['name_cn'] = $cn;
            $changed = true;
        }
    }
    if (!$changed) return ($tags_cn_json_str !== '' && $tags_cn_json_str !== '[]') ? $tags_cn_json_str : json_encode($arr, JSON_UNESCAPED_UNICODE);
    return json_encode($arr, JSON_UNESCAPED_UNICODE);
}
function _t_translate_flat_tag_rows($flat_rows) {
    // 给 get_gallery_detail 用：直接从 tags JOIN gallery_tags 得到 PDO rows，元素 shape [type, name]（纯键值对），返回时每一项加 name_cn 键
    if (!is_array($flat_rows)) return $flat_rows;
    $out = [];
    foreach ($flat_rows as $r) {
        if (!is_array($r)) { $out[] = $r; continue; }
        $ns = isset($r['type']) ? (string)$r['type'] : '';
        $name = isset($r['name']) ? (string)$r['name'] : '';
        if ($ns === '' || $name === '') { $out[] = $r; continue; }
        $aliases = _t_split_aliases($name);
        $cn = _t_translate_single($ns, $name, $aliases);
        if ($cn !== null && $cn !== '') {
            $r['name_cn'] = $cn;
        }
        $out[] = $r;
    }
    return $out;
}
function translate_search_preset_name($query) {
    $query = trim((string)$query);
    if ($query === '') return '';
    $bundle = &_t_get_translation_static();
    $ns_map = &$bundle['ns_map'];
    $ns_alias = &$bundle['ns_alias'];
    $ns_display = [
        'artist' => '作者',
        'character' => '角色',
        'cosplayer' => 'Coser',
        'female' => '女性',
        'group' => '社团',
        'language' => '语言',
        'male' => '男性',
        'mixed' => '混合',
        'other' => '其他',
        'parody' => '原作',
        'reclass' => '分类',
        'category' => '分类',
    ];

    return preg_replace_callback('/(?<!\S)(-?)([a-zA-Z_]+):(?:"([^"]+)"|(\S+))/', function ($m) use (&$ns_map, &$ns_alias, &$ns_display) {
        $ns = $m[2];
        $raw_name = $m[3] !== '' ? $m[3] : $m[4];
        $name = substr($raw_name, -1) === '$' ? substr($raw_name, 0, -1) : $raw_name;
        $lookup_ns = $ns_alias[$ns] ?? $ns;
        $translated = _t_lookup_ns($ns_map, $lookup_ns, $name);
        $label = $ns_display[$ns] ?? ($ns_display[$lookup_ns] ?? $ns);
        if (!$translated && $label === $ns) {
            return $m[0];
        }
        return $m[1] . $label . ':' . ($translated ?: $name);
    }, $query);
}

function read_config() {
    global $root;
    $path = $root . '/config.yaml';
    if (!is_file($path)) return [];
    
    $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $config = [];
    $current_section = '';
    $current_sub = '';
    $stack = [&$config];
    
    foreach ($lines as $line) {
        if (preg_match('/^(\s*)([\w-]+):\s*(.*)$/', $line, $m)) {
            $indent = strlen($m[1]);
            $key = $m[2];
            $value = trim($m[3]);
            
            while (count($stack) - 1 > $indent / 2) {
                array_pop($stack);
            }
            
            $target = &$stack[count($stack) - 1];
            
            if ($value === '') {
                $target[$key] = [];
                $stack[] = &$target[$key];
            } else {
                $value = trim($value, '"\'');
                if ($value === 'true') $value = true;
                elseif ($value === 'false') $value = false;
                elseif (is_numeric($value)) $value = $value + 0;
                $target[$key] = $value;
            }
            unset($target);
        }
    }
    return $config;
}

function write_config($data) {
    global $root;
    $path = $root . '/config.yaml';
    
    function array_to_yaml($data, $indent = 0, $parents = []) {
        $out = '';
        $prefix = str_repeat('  ', $indent);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (empty($value)) {
                    $out .= $prefix . $key . ": {}\n";
                } else {
                    $out .= $prefix . $key . ":\n";
                    $out .= array_to_yaml($value, $indent + 1, array_merge($parents, [(string)$key]));
                }
            } elseif (is_bool($value)) {
                $out .= $prefix . $key . ': ' . ($value ? 'true' : 'false') . "\n";
            } elseif (is_numeric($value) && !in_array('cookies', $parents, true)) {
                $out .= $prefix . $key . ': ' . $value . "\n";
            } else {
                $out .= $prefix . $key . ': "' . str_replace('"', '\"', $value) . "\"\n";
            }
        }
        return $out;
    }
    
    $yaml = array_to_yaml($data);
    $dir = dirname($path);
    if (file_exists($path)) {
        if (!is_writable($path)) {
            throw new RuntimeException('Config file is not writable: ' . $path);
        }
    } elseif (!is_dir($dir) || !is_writable($dir)) {
        throw new RuntimeException('Config directory is not writable: ' . $dir);
    }
    $written = @file_put_contents($path, $yaml, LOCK_EX);
    if ($written === false) {
        $error = error_get_last();
        $message = $error['message'] ?? 'unknown error';
        throw new RuntimeException('Failed to write config file: ' . $message);
    }
    return true;
}

// ——— 工具：递归目录大小，带毫秒级超时，超时就返回目前累加值避免 408
function _dir_total_bytes($dir, $timeout_ms = 3000) {
    if (!$dir || !is_dir($dir)) return 0;
    $start = microtime(true);
    $total = 0;
    try {
        $iter = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS | RecursiveDirectoryIterator::CURRENT_AS_FILEINFO | FilesystemIterator::UNIX_PATHS),
            RecursiveIteratorIterator::LEAVES_ONLY,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );
        foreach ($iter as $f) {
            try {
                if ($f->isFile()) $total += (int)$f->getSize();
            } catch (Throwable $eI) { /* open_basedir / permission skip */ }
            if ((microtime(true) - $start) * 1000 > $timeout_ms) break;
        }
    } catch (Throwable $e) { return (int)$total; }
    return (int)$total;
}

// ——— SQLite 连接通用初始化：读优化 PRAGMA + search_cache 复合索引幂等创建（缓存吞吐 1 万→100 万行都能稳在 <150ms）
function _open_sqlite($db_path) {
    $pdo = new PDO('sqlite:' . $db_path);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // ——— 纯读场景安全的 6 条 PRAGMA 读优化（写请求也安全因为只有 crawl/download  worker 并发少，synchronous=NORMAL WAL 下仍安全，极端异常最多丢最后一次提交无数据损坏）
    $pdo->exec("PRAGMA journal_mode = WAL");
    $pdo->exec("PRAGMA synchronous = NORMAL");   // FULL → NORMAL（WAL 下 ACID 仍满足；吞吐 +40%）
    $pdo->exec("PRAGMA cache_size = -16384");    // 页缓存 16MB（34.6MB DB 直接全装内存省 I/O）
    $pdo->exec("PRAGMA temp_store = MEMORY");    // ORDER BY / GROUP BY 临时表走内存，避免磁盘 TEMP B-TREE
    $pdo->exec("PRAGMA mmap_size = 268435456");  // 256MB mmap：读数据少 1 次 memcpy（纯 read 系统调用大幅减少）
    $pdo->exec("PRAGMA foreign_keys = OFF");
    // ——— 迁移守卫：用 user_version 保证 7 条复合索引 + 2 条数据修复 UPDATE 只跑 1 次，后续请求 0 成本（避免每次 cache_search 把读请求变写请求拖慢 4.7s）
    $SCHEMA_VER_TARGET = 3;
    $cur_ver = (int)$pdo->query("PRAGMA user_version")->fetchColumn();
    if ($cur_ver < $SCHEMA_VER_TARGET) {
        // ——— search_cache 专用 4 组复合索引（幂等 IF NOT EXISTS）：
        //     cache_search 典型路径 = source=? → category IN (..) → language IN (..) → ORDER BY uploaded_at DESC LIMIT N
        //     4 组覆盖搜索栏 4 种高频筛选组合，保证 ORDER BY 走 Index Ordered Scan 避免 USE TEMP B-TREE FOR ORDER BY
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sc_src_upd         ON search_cache (source,           uploaded_at DESC, crawled_at DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sc_cat_upd         ON search_cache (category,         uploaded_at DESC, crawled_at DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sc_lang_upd        ON search_cache (language,         uploaded_at DESC, crawled_at DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sc_src_cat_lang_upd ON search_cache (source, category, language, uploaded_at DESC, crawled_at DESC)");
        // 高频 author/title 等值检索辅助（tags 是 JSON 大列 LIKE 本身不做索引靠全文/两阶段 LIKE 粗筛）
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_sc_artist_upd      ON search_cache (artist, uploaded_at DESC)");
        // galleries 侧：downloaded_at 倒序浏览也是常见路径
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gals_src_upd       ON galleries (source, downloaded_at DESC)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_gals_lang_cat_upd  ON galleries (language, category, downloaded_at DESC)");
        // 幂等修复旧数据：空 uploaded_at 统一写成 '0000-00-00'，保证 ORDER BY 直接走 uploaded_at 索引（不用包 COALESCE(NULLIF()) 让索引失效）
        $pdo->exec("UPDATE search_cache SET uploaded_at = '0000-00-00' WHERE uploaded_at IS NULL OR uploaded_at = ''");
        $pdo->exec("UPDATE galleries    SET uploaded_at = '0000-00-00' WHERE uploaded_at IS NULL OR uploaded_at = ''");
        if ($cur_ver < 2) {
            // ——— V2：galleries 唯一化 + UNIQUE 守卫 —————————————————————————————
            // 1. galleries 历史上 save_gallery() 有竞态时同一 (source,source_id) 可能插入 2+ 行，
            //    会把 cache_search 的 LEFT JOIN 1 行放大为 N 行（"Amateur Coloring Practice" 重复出现 2 次就是此根因）。
            //    先去重：每组保留最小 id，把其余 DELETE；再建立 UNIQUE 索引从数据层杜绝未来复发。
            $dups = $pdo->query("SELECT source, source_id, MIN(id) AS keep_id FROM galleries GROUP BY source, source_id HAVING COUNT(*)>1")->fetchAll();
            foreach ($dups as $d) {
                $stmt = $pdo->prepare("DELETE FROM galleries WHERE source=? AND source_id=? AND id>?");
                $stmt->execute([$d['source'], $d['source_id'], (int)$d['keep_id']]);
                $stmt2 = $pdo->prepare("DELETE FROM gallery_tags WHERE gallery_id NOT IN (SELECT id FROM galleries)");
                $stmt2->execute();
            }
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_gals_src_sid ON galleries (source, source_id)");
            // 2. search_cache 同理：(source, source_id) 必须唯一，避免爬取去重失败时产生重复（虽然此表没有 JOIN 放大自己，但翻页时同一卡片会在两页都出现）。
            $sc_dups = $pdo->query("SELECT source, source_id FROM search_cache GROUP BY source, source_id HAVING COUNT(*)>1")->fetchAll();
            if (!empty($sc_dups)) {
                // 用隐式 rowid 去重保留最小 rowid 那一行（SQLite 每个表必有 rowid）
                foreach ($sc_dups as $d) {
                    $stmt = $pdo->prepare("DELETE FROM search_cache WHERE rowid NOT IN (SELECT MIN(rowid) FROM search_cache WHERE source=? AND source_id=?) AND source=? AND source_id=?");
                    $stmt->execute([$d['source'], $d['source_id'], $d['source'], $d['source_id']]);
                }
            }
            $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_sc_src_sid ON search_cache (source, source_id)");
        }
        if ($cur_ver < 3) {
            // ——— V3：精确排除表（O(1) PK 查/删，SQL WHERE 走 NOT IN 前置索引排除，根除模糊黑名单每页 REGEXP 300ms+）
            //   excluded_sids        = 单本精确排除（(source, source_id) PK）
            //   excluded_participants = artist/group 精确排除（(kind, name) PK）
            $pdo->exec("CREATE TABLE IF NOT EXISTS excluded_sids (
                source     TEXT NOT NULL,
                source_id  TEXT NOT NULL,
                reason     TEXT DEFAULT '',
                created_at TEXT DEFAULT (datetime('now','localtime')),
                PRIMARY KEY (source, source_id)
            )");
            $pdo->exec("CREATE TABLE IF NOT EXISTS excluded_participants (
                kind       TEXT NOT NULL,        -- 'artist' | 'group'
                name       TEXT NOT NULL,
                reason     TEXT DEFAULT '',
                created_at TEXT DEFAULT (datetime('now','localtime')),
                PRIMARY KEY (kind, name)
            )");
            // 把既存 tag_blacklist 里 artist/group 精确类一次性同步到 excluded_participants（避免新表刚建好但旧条目不生效）
            $pdo->exec("INSERT OR IGNORE INTO excluded_participants (kind, name, reason)
                        SELECT 'artist', tag_value, 'migrated from tag_blacklist (v3)'
                        FROM tag_blacklist WHERE tag_type IN ('artist','group','author','*') AND tag_value!=''");
        }
        $pdo->exec("PRAGMA user_version = $SCHEMA_VER_TARGET");
    }
    return $pdo;
}

// --- Route actions ---
try {
    // 全局 PDO：所有 sqlite 连接统一走 _open_sqlite（PRAGMA + 索引初始化）
    function _pdo($path = null) {
        global $root;
        static $last_path = null, $last_pdo = null;
        if ($path === null) $path = $root . '/data/ehlib.db';
        if ($last_pdo && $last_path === $path) return $last_pdo;
        $last_path = $path;
        $last_pdo = _open_sqlite($path);
        return $last_pdo;
    }

    switch ($action) {
        // ——— 调试用：触发一次 _open_sqlite() 以便 SCHEMA v2 去重迁移立刻生效（下次访问缓存页也会自动触发，这里提供一个显式的 HTTP 入口）
        case 'db_migrate_v2':
            try {
                $pdo = _pdo();
                $cur_ver = (int)$pdo->query("PRAGMA user_version")->fetchColumn();
                $g_dup = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT 1 FROM galleries GROUP BY source,source_id HAVING COUNT(*)>1)")->fetchColumn();
                $sc_dup = (int)$pdo->query("SELECT COUNT(*) FROM (SELECT 1 FROM search_cache GROUP BY source,source_id HAVING COUNT(*)>1)")->fetchColumn();
                $g_total = (int)$pdo->query("SELECT COUNT(*) FROM galleries")->fetchColumn();
                $sc_total = (int)$pdo->query("SELECT COUNT(*) FROM search_cache")->fetchColumn();
                // 列出 galleries 所有 (source,source_id) 重复组的 前 20 组，方便核对
                $dup_list = $pdo->query("SELECT source, source_id, COUNT(*) AS c, MIN(id) AS keep_id FROM galleries GROUP BY source,source_id HAVING COUNT(*)>1 ORDER BY c DESC LIMIT 20")->fetchAll();
                json_exit([
                    'user_version_before' => $cur_ver,
                    'galleries_dup_groups' => $g_dup,
                    'search_cache_dup_groups' => $sc_dup,
                    'galleries_total' => $g_total,
                    'search_cache_total' => $sc_total,
                    'sample_dup_groups' => $dup_list,
                    'note' => '下次 GET cache_search 时将自动触发 SCHEMA v2 去重迁移（DELETE 重复 (src,sid)+UNIQUE 索引），本接口只读当前状态不执行任何修改。'
                ]);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        case 'get_config':
            $config = read_config();
            json_exit(['config' => $config]);
            break;

        case 'save_config':
            $data = json_decode(file_get_contents('php://input'), true);
            if (!$data) error_exit('Invalid JSON body');
            write_config($data);
            json_exit(['message' => 'Configuration saved']);
            break;

        case 'get_galleries':
            $source = $_GET['source'] ?? '';
            $tag_name = $_GET['tag'] ?? '';
            $tags_raw = $_GET['tags'] ?? '';
            $tag_mode = $_GET['tag_mode'] ?? 'any';
            $artist = $_GET['artist'] ?? '';
            $language = $_GET['language'] ?? '';
            $per_page = max(1, min(200, (int)($_GET['per_page'] ?? 30)));
            $page = max(1, (int)($_GET['page'] ?? 1));
            $offset = ($page - 1) * $per_page;
            $tag_names = [];
            if ($tags_raw !== '') {
                foreach (explode(',', $tags_raw) as $tag) {
                    $tag = trim($tag);
                    if ($tag !== '') $tag_names[] = $tag;
                }
            }
            // 兼容：单 tag=xxx 参数里用 , ; ， & 分隔时视为多 tag AND（自动把 tag_mode 强制 all）
            if ($tag_name !== '') {
                $parts = _split_and_tokens($tag_name);
                if (count($parts) > 1) {
                    $tag_names = array_merge($tag_names, $parts);
                    if (!isset($_GET['tag_mode'])) $tag_mode = 'all'; // 仅在用户没显式传 tag_mode 时强制
                } else {
                    $tag_names[] = $tag_name;
                }
            }
            $tag_names = array_values(array_unique(array_filter($tag_names, function($x){ return $x !== ''; })));
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $pdo->sqliteCreateFunction('regexp', function ($pattern, $subject) {
                    if ($pattern === null || $pattern === '') return 0;
                    $p = @preg_match('/' . str_replace('/', '\\/', (string)$pattern) . '/ui' , (string)$subject);
                    return $p === 1 ? 1 : 0;
                }, 2);

                // ————— tag_blacklist：下载图库浏览(get_galleries)不生效（用户不会下载被排除对象，且 REGEXP 入 SQL 拖慢），此处仅建表空占位保持一致性
                $pdo->exec("CREATE TABLE IF NOT EXISTS tag_blacklist (id INTEGER PRIMARY KEY AUTOINCREMENT, tag_type TEXT NOT NULL DEFAULT '', tag_value TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT '', UNIQUE(tag_type, tag_value))");

                $params = [];
                $has_tags = !empty($tag_names);
                $from = $has_tags ? 'galleries g' : 'galleries';
                $joins = '';
                $prefix = $has_tags ? 'g.' : '';
                $where = 'WHERE 1=1';

                if ($has_tags) {
                    $joins = ' JOIN gallery_tags gt ON g.id = gt.gallery_id JOIN tags t ON gt.tag_id = t.id';
                    $likes = [];
                    foreach ($tag_names as $tag) {
                        $pattern = _strict_token_safe($tag);
                        if ($pattern !== '') {
                            $likes[] = _sql_regexp('t.name', $pattern, $params);
                        }
                    }
                    $likes = array_filter($likes, function($x){ return $x !== ''; });
                    $where = empty($likes) ? 'WHERE 1=1' : ('WHERE (' . implode(' OR ', $likes) . ')');
                }
                if ($source) { $where .= ' AND ' . $prefix . 'source=?'; $params[] = $source; }
                if ($artist) {
                    // galleries 的 artist 列支持多作者 AND（, ; ， & 分隔）→ 用 AND-lookahead 合并为单 REGEXP
                    $artist_tokens = _split_and_tokens($artist);
                    $token_pats = [];
                    foreach ($artist_tokens as $a_tok) {
                        $p = _pattern_artist_col($a_tok);
                        if ($p !== '') $token_pats[] = $p;
                    }
                    if (!empty($token_pats)) {
                        $merged = _merge_and_patterns($token_pats);
                        if ($merged !== '') {
                            $where .= ' AND ' . _sql_regexp($prefix . 'artist', $merged, $params);
                        }
                    }
                }
                if ($language) {
                    $langs = array_filter(array_map('trim', explode(',', $language)));
                    if (!empty($langs)) {
                        $placeholders = implode(',', array_fill(0, count($langs), '?'));
                        $where .= ' AND ' . $prefix . 'language IN (' . $placeholders . ')';
                        foreach ($langs as $l) $params[] = $l;
                    }
                }

                $group_having = '';
                if ($has_tags && $tag_mode === 'all') {
                    $group_having = ' GROUP BY g.id HAVING COUNT(DISTINCT t.id) = ' . count($tag_names);
                }

                // Count query
                if ($has_tags && $tag_mode === 'all') {
                    $count_query = "SELECT COUNT(*) FROM (SELECT g.id FROM $from $joins $where $group_having) AS cnt";
                } elseif ($has_tags) {
                    $count_query = "SELECT COUNT(DISTINCT g.id) FROM $from $joins $where";
                } else {
                    $count_query = "SELECT COUNT(*) FROM $from $where";
                }
                $count_stmt = $pdo->prepare($count_query);
                $count_stmt->execute($params);
                $total = (int)$count_stmt->fetchColumn();

                // Data query with limit/offset
                $select_cols = $has_tags ? 'DISTINCT g.*' : '*';
                $order_col = $prefix . 'uploaded_at';
                $order_id_col = $prefix . 'source_id';
                $data_query = "SELECT $select_cols FROM $from $joins $where $group_having ORDER BY $order_col DESC, CAST(SUBSTR($order_id_col || '/', 1, INSTR($order_id_col || '/', '/') - 1) AS INTEGER) DESC LIMIT $per_page OFFSET $offset";
                $stmt = $pdo->prepare($data_query);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();
                $galleries = [];
                foreach ($rows as $row) {
                    $total_pages = (int)($row['total_pages'] ?? 0);
                    $downloaded_pages = count_downloaded_pages($row['local_path'] ?? '');
                    $is_complete = (int)($row['is_complete'] ?? 0) === 1;
                    $galleries[] = [
                        'source' => $row['source'] ?? '',
                        'source_id' => $row['source_id'] ?? '',
                        'title' => $row['title'] ?? '',
                        'title_jp' => $row['title_jp'] ?? '',
                        'language' => $row['language'] ?? '',
                        'pages' => $total_pages,
                        'total_pages' => $total_pages,
                        'downloaded_pages' => $downloaded_pages,
                        'is_complete' => $is_complete,
                        'downloaded_at' => $row['downloaded_at'] ?? '',
                        'uploaded_at' => $row['uploaded_at'] ?? '',
                        'cover_url' => public_cover_url($row['source'] ?? '', $row['source_id'] ?? ''),
                    ];
                }
                json_exit(['galleries' => $galleries, 'total' => $total, 'page' => $page, 'per_page' => $per_page]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;
        case 'get_gallery_detail':
            $source = $_GET['source'] ?? '';
            $source_id = $_GET['source_id'] ?? '';
            if (!$source || !$source_id) error_exit('source and source_id required');
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $stmt = $pdo->prepare('SELECT id, title, title_jp, artist, group_name, language, category, total_pages, uploaded_at, file_size, local_path, downloaded_at, tags, tags_cn FROM galleries WHERE source=? AND source_id=?');
                $stmt->execute([$source, $source_id]);
                $gallery = $stmt->fetch();
                if (!$gallery) error_exit('Gallery not found');
                $stmt2 = $pdo->prepare(
                    'SELECT t.type, t.name, COALESCE(t.match_keys,\'\') AS match_keys FROM tags t
                     JOIN gallery_tags gt ON t.id = gt.tag_id
                     JOIN galleries g ON gt.gallery_id = g.id
                     WHERE g.source=? AND g.source_id=?
                     ORDER BY t.type, t.name'
                );
                $stmt2->execute([$source, $source_id]);
                $tags = $stmt2->fetchAll();
                // 兜底 1：优先用 galleries.tags_cn JSON 列（Python 已 translate_tags 写好）
                $final_tags = '';
                $gallery_tags_cn = isset($gallery['tags_cn']) ? (string)$gallery['tags_cn'] : '';
                $gallery_tags_raw = isset($gallery['tags']) ? (string)$gallery['tags'] : '';
                if ($gallery_tags_cn !== '' || $gallery_tags_raw !== '') {
                    $final_tags = _t_translate_tags_json($gallery_tags_raw, $gallery_tags_cn);
                }
                if ($final_tags !== '') {
                    $gallery['tags'] = @json_decode($final_tags, true) ?: [];
                } else {
                    // 兜底 2：galleries 表没存 tags JSON → 用 join 的 flat rows + 对 name 拆 | 查翻译
                    $translated_rows = [];
                    foreach ($tags as $r) {
                        if (!is_array($r)) { $translated_rows[] = $r; continue; }
                        $ns = isset($r['type']) ? (string)$r['type'] : '';
                        $name = isset($r['name']) ? (string)$r['name'] : '';
                        if ($ns === '' || $name === '') { $translated_rows[] = $r; continue; }
                        $aliases = _t_split_aliases($name);
                        $extra = [];
                        if (!empty($r['match_keys'])) {
                            $parsed_mk = @json_decode((string)$r['match_keys'], true);
                            if (is_array($parsed_mk)) {
                                foreach ($parsed_mk as $mk) {
                                    if (is_string($mk) && $mk !== '') $extra[] = $mk;
                                }
                            }
                        }
                        $all_extra = array_values(array_unique(array_merge($aliases, $extra)));
                        $cn = _t_translate_single($ns, $name, $all_extra);
                        if ($cn !== null && $cn !== '') {
                            $r['name_cn'] = $cn;
                        }
                        $translated_rows[] = $r;
                    }
                    $gallery['tags'] = $translated_rows;
                }
                json_exit(['gallery' => $gallery]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'refresh_metadata':
            $source = $_POST['source'] ?? '';
            $source_id = $_POST['source_id'] ?? '';
            if (!$source || !$source_id) error_exit('source and source_id required');
            $args = ['refresh-metadata', $source, $source_id];
            $result = run_python($args, 120);
            $ok = $result['ok'] || (strpos($result['stdout'] . $result['stderr'], 'Metadata refreshed:') !== false);
            json_exit([
                'output' => $result['stdout'] ?: $result['stderr'],
                'exit_code' => $result['exit_code'],
            ], $ok);
            break;

        case 'delete_cache':
            $source = $_POST['source'] ?? $_GET['source'] ?? '';
            $source_id = $_POST['source_id'] ?? $_GET['source_id'] ?? '';
            if (!$source || !$source_id) error_exit('source and source_id required');
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $stmt = $pdo->prepare('DELETE FROM search_cache WHERE source=? AND source_id=?');
                $stmt->execute([$source, $source_id]);
                $deleted = $stmt->rowCount();
                json_exit(['message' => 'Deleted ' . $deleted . ' cache record(s)']);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'batch_delete_cache':
            $ids_raw = $_POST['ids'] ?? $_GET['ids'] ?? '';
            if (!$ids_raw) error_exit('ids required');
            $ids = json_decode($ids_raw, true);
            if (!is_array($ids) || empty($ids)) error_exit('ids must be a non-empty array');
            $source = 'exhentai';
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $pdo->exec("PRAGMA synchronous=OFF");
                $count = 0;
                $stmt = $pdo->prepare('DELETE FROM search_cache WHERE source=? AND source_id=?');
                foreach ($ids as $sid) {
                    $stmt->execute([$source, $sid]);
                    $count += $stmt->rowCount();
                }
                json_exit(['message' => 'Deleted ' . $count . ' records', 'deleted' => $count]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'delete_gallery':
            $source = $_POST['source'] ?? $_GET['source'] ?? '';
            $source_id = $_POST['source_id'] ?? $_GET['source_id'] ?? '';
            if (!$source || !$source_id) error_exit('source and source_id required');
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $pdo->exec('PRAGMA foreign_keys = ON');

                $stmt = $pdo->prepare('SELECT id, title, local_path FROM galleries WHERE source=? AND source_id=?');
                $stmt->execute([$source, $source_id]);
                $gallery = $stmt->fetch();
                if (!$gallery) error_exit('Gallery not found');

                $download_base = resolve_download_path();
                $local_path = trim((string)($gallery['local_path'] ?? ''));
                $target_dir = '';
                if ($local_path !== '') {
                    $normalized_local = normalize_path($local_path);
                    if (is_path_within($normalized_local, $download_base)) {
                        $target_dir = $normalized_local;
                    }
                }
                if ($target_dir === '' || !file_exists($target_dir)) {
                    // 尝试通过 source/source_id 推导实际路径（处理路径格式不一致的情况）
                    $expected_subdir = str_replace('/', '_', $source_id);
                    $candidate = $download_base . DIRECTORY_SEPARATOR . $source . DIRECTORY_SEPARATOR . $expected_subdir;
                    $normalized_candidate = normalize_path($candidate);
                    if (is_path_within($normalized_candidate, $download_base) && file_exists($normalized_candidate)) {
                        $target_dir = $normalized_candidate;
                        // 更新 DB 中的 local_path 为正确路径
                        $pdo->prepare('UPDATE galleries SET local_path=? WHERE id=?')->execute([$normalized_candidate, $gallery['id']]);
                    }
                }
                if ($target_dir !== '') {
                    if (!delete_dir_recursive($target_dir)) {
                        error_exit('Failed to delete local gallery directory');
                    }
                }

                $stmt = $pdo->prepare('DELETE FROM galleries WHERE id=?');
                $stmt->execute([$gallery['id']]);
                if ($stmt->rowCount() < 1) {
                    error_exit('Failed to delete gallery record');
                }

                json_exit([
                    'message' => 'Gallery deleted',
                    'deleted' => [
                        'source' => $source,
                        'source_id' => $source_id,
                        'title' => $gallery['title'] ?? '',
                    ],
                ]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'get_download_progress':
            $progress_dir = $root . '/data/progress';
            if (!is_dir($progress_dir)) {
                json_exit(['tasks' => []]);
                break;
            }
            $files = glob($progress_dir . '/*.json');
            $tasks = [];
            $now = time();
            foreach ($files as $f) {
                $content = @file_get_contents($f);
                if ($content === false) continue;
                $data = @json_decode($content, true);
                if (!is_array($data)) continue;
                $mtime = $data['updated_at'] ?? 0;
                $age = $now - $mtime;
                $source = $data['source'] ?? '';
                $stale = ($source === 'crawl') ? 7200 : 300;
                if ($age > $stale) {
                    @unlink($f);
                    continue;
                }
                $tasks[] = $data;
            }
            usort($tasks, function ($a, $b) {
                $cmp = strcmp($a['source'] ?? '', $b['source'] ?? '');
                if ($cmp !== 0) return $cmp;
                return strcmp($a['source_id'] ?? '', $b['source_id'] ?? '');
            });
            json_exit(['tasks' => $tasks]);
            break;

        case 'serve_image':
            $source = $_GET['source'] ?? '';
            $source_id = $_GET['source_id'] ?? '';
            $page = $_GET['page'] ?? 'cover';
            if (!$source || !$source_id) error_exit('source and source_id required');
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $stmt = $pdo->prepare('SELECT local_path FROM galleries WHERE source=? AND source_id=?');
                $stmt->execute([$source, $source_id]);
                $row = $stmt->fetch();
                if (!$row || empty($row['local_path'])) error_exit('Gallery path not found');
                $local_path = normalize_path($row['local_path']);
                $download_base = resolve_download_path();
                if (!is_path_within($local_path, $download_base)) error_exit('Path outside download directory');
                $img_path = '';
                if ($page === 'cover') {
                    foreach (['001', '1'] as $name) {
                        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                            $candidate = $local_path . DIRECTORY_SEPARATOR . $name . '.' . $ext;
                            if (is_file($candidate)) { $img_path = $candidate; break; }
                        }
                        if ($img_path) break;
                    }
                    if (!$img_path) {
                        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                            $candidate = $local_path . DIRECTORY_SEPARATOR . 'cover.' . $ext;
                            if (is_file($candidate)) { $img_path = $candidate; break; }
                        }
                    }
                } else {
                    $page_num = (int)$page;
                    if ($page_num < 1) error_exit('Invalid page number');
                    foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                        $candidate = $local_path . DIRECTORY_SEPARATOR . sprintf('%03d', $page_num) . '.' . $ext;
                        if (is_file($candidate)) { $img_path = $candidate; break; }
                    }
                    if (!$img_path) {
                        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                            $candidate = $local_path . DIRECTORY_SEPARATOR . $page_num . '.' . $ext;
                            if (is_file($candidate)) { $img_path = $candidate; break; }
                        }
                    }
                }
                if (!$img_path || !is_file($img_path)) error_exit('Image not found');
                $ext = strtolower(pathinfo($img_path, PATHINFO_EXTENSION));
                $mime_map = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
                $mime = $mime_map[$ext] ?? 'application/octet-stream';
                header('Content-Type: ' . $mime);
                header('Content-Length: ' . filesize($img_path));
                header('Cache-Control: public, max-age=86400');
                readfile($img_path);
                exit;
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'get_image_list':
            $source = $_GET['source'] ?? '';
            $source_id = $_GET['source_id'] ?? '';
            if (!$source || !$source_id) error_exit('source and source_id required');
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $stmt = $pdo->prepare('SELECT total_pages, local_path FROM galleries WHERE source=? AND source_id=?');
                $stmt->execute([$source, $source_id]);
                $row = $stmt->fetch();
                if (!$row || empty($row['local_path'])) error_exit('Gallery not found');
                $local_path = normalize_path($row['local_path']);
                $download_base = resolve_download_path();
                if (!is_path_within($local_path, $download_base)) error_exit('Path outside download directory');
                $total_pages = (int)$row['total_pages'];
                $images = [];
                for ($i = 1; $i <= $total_pages; $i++) {
                    $found = false;
                    foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                        $candidate = $local_path . DIRECTORY_SEPARATOR . sprintf('%03d', $i) . '.' . $ext;
                        if (is_file($candidate)) {
                            $images[] = ['page' => $i, 'file' => sprintf('%03d', $i) . '.' . $ext, 'url' => public_image_url_from_path($candidate)];
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                            $candidate = $local_path . DIRECTORY_SEPARATOR . $i . '.' . $ext;
                            if (is_file($candidate)) {
                                $images[] = ['page' => $i, 'file' => $i . '.' . $ext, 'url' => public_image_url_from_path($candidate)];
                                $found = true;
                                break;
                            }
                        }
                    }
                    if (!$found) {
                        $images[] = ['page' => $i, 'file' => ''];
                    }
                }
                json_exit([
                    'source' => $source,
                    'source_id' => $source_id,
                    'total_pages' => $total_pages,
                    'images' => $images,
                ]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'serve_cache_thumb':
            $source = $_GET['source'] ?? 'exhentai';
            $source_id = $_GET['source_id'] ?? '';
            if (!$source_id) error_exit('source_id required');
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $stmt = $pdo->prepare('SELECT thumb_path FROM search_cache WHERE source=? AND source_id=?');
                $stmt->execute([$source, $source_id]);
                $row = $stmt->fetch();
                if (!$row || empty($row['thumb_path'])) error_exit('Thumb not found');
                $thumb_path = (string)$row['thumb_path'];
                // ——— 安全：防止 DB 里被注入 .. / \ / Windows 绝对路径穿越
                //     1. realpath 解析后必须以真实的 data_dir 根开头
                //     2. 扩展名白名单 jpg/jpeg/png/gif/webp 只允许图片
                $data_dir = realpath($root . '/data') ?: $root . '/data';
                $rp = @realpath($thumb_path);
                if ($rp === false) error_exit('Thumb file not found');
                $norm_data = rtrim(str_replace('\\', '/', $data_dir), '/');
                $norm_rp   = rtrim(str_replace('\\', '/', $rp), '/');
                if (strpos($norm_rp, $norm_data . '/') !== 0 && $norm_rp !== $norm_data) {
                    error_exit('Invalid thumb path (outside data dir)');
                }
                if (!is_file($rp)) error_exit('Thumb file not found');
                $ext = strtolower(pathinfo($rp, PATHINFO_EXTENSION));
                $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
                if (!isset($mime[$ext])) error_exit('Invalid thumb extension');
                header('Content-Type: ' . $mime[$ext]);
                header('Cache-Control: max-age=86400');
                readfile($rp);
                exit;
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'recover_orphans':
            $source = $_POST['source'] ?? '';
            $args = ['recover-orphans'];
            if ($source) { $args[] = '--source'; $args[] = $source; }
            $result = run_python($args, 300);
            $ok = $result['ok'] || (strpos($result['stdout'] . $result['stderr'], 'Recovery complete') !== false);
            json_exit([
                'output' => $result['stdout'] ?: $result['stderr'],
                'exit_code' => $result['exit_code'],
            ], $ok);
            break;

        case 'download':
            $source = $_POST['source'] ?? '';
            $id = $_POST['id'] ?? '';
            $url = $_POST['url'] ?? '';
            $gid = $_POST['gid'] ?? '';
            $token = $_POST['token'] ?? '';
            $force = !empty($_POST['force']);

            if ($url) {
                $args = ['download', '--url', $url];
            } elseif ($id && $source) {
                $args = ['download', $source, '--id', $id];
            } elseif ($gid && $token) {
                $args = ['download', '--gid', $gid, '--token', $token];
            } else {
                error_exit('Provide --url, --id+source, or --gid+--token');
            }
            if ($force) $args[] = '--force';
            $result = run_python_locked($args, 900);
            json_exit([
                'output' => $result['stdout'] ?: $result['stderr'],
                'exit_code' => $result['exit_code'],
            ], $result['ok']);
            break;

        case 'batch_download':
            $urls_raw = file_get_contents('php://input');
            $lines = array_filter(explode("\n", str_replace("\r\n", "\n", $urls_raw)));
            $tmpfile = $root . '/data/batch_' . time() . '.txt';
            if (!is_dir($root . '/data')) mkdir($root . '/data', 0755, true);
            file_put_contents($tmpfile, implode("\n", $lines));
            $args = ['batch', '--file', $tmpfile];
            $force = !empty($_POST['force']);
            if ($force) $args[] = '--force';
            $result = run_python_locked($args, 600);
            @unlink($tmpfile);
            json_exit([
                'output' => $result['stdout'] ?: $result['stderr'],
                'exit_code' => $result['exit_code'],
            ], $result['ok']);
            break;

        case 'stop_download':
            $cancel_file = $root . '/data/download_cancel.flag';
            file_put_contents($cancel_file, '1');
            // 清理所有下载相关的进度文件
            $progress_dir = $root . '/data/progress';
            foreach (glob($progress_dir . '/*.json') as $f) {
                $content = @file_get_contents($f);
                $data = @json_decode($content, true);
                if (is_array($data) && isset($data['status']) && $data['status'] === 'downloading') {
                    @unlink($f);
                }
            }
            json_exit(['message' => '下载任务已终止'], true);
            break;

        case 'sync_favorite_authors':
            $favcats_raw = trim((string)($_POST['favcats'] ?? '0,1,9'));
            $pages_per_cat = max(0, (int)($_POST['pages_per_cat'] ?? 0));
            $max_detail = max(0, (int)($_POST['max_detail'] ?? 0));
            $dry_run = !empty($_POST['dry_run']);
            $no_upsert_name = !empty($_POST['no_upsert_name']);
            $force_metadata = !empty($_POST['force_metadata']);

            $favcats = [];
            if ($favcats_raw !== '') {
                foreach (explode(',', $favcats_raw) as $c) {
                    $c = (int)trim($c);
                    if ($c >= 0 && $c <= 9) $favcats[] = $c;
                }
            }
            $favcats = array_values(array_unique($favcats));
            if (empty($favcats)) $favcats = [0, 1, 9];

            $args = ['sync-exhentai-favorite-authors'];
            $args[] = '--favcats';
            $args[] = implode(',', $favcats);
            if ($pages_per_cat > 0) { $args[] = '--pages-per-cat'; $args[] = (string)$pages_per_cat; }
            if ($max_detail > 0) { $args[] = '--max-detail'; $args[] = (string)$max_detail; }
            if ($dry_run) $args[] = '--dry-run';
            if ($no_upsert_name) $args[] = '--no-upsert-name';
            if ($force_metadata) $args[] = '--force-metadata';

            $timeout = 3600;
            $snapshot_path = $root . '/data/sync_fav_snapshot.json';
            $stream = !empty($_POST['stream']);
            $chunk_hook = null;
            if ($stream) {
                @file_put_contents($snapshot_path, json_encode([
                    'started_at' => date('c'),
                    'stage' => 'starting',
                    'last_line' => null,
                    'log_lines' => [],
                    'events' => [],
                    'finished' => false,
                ], JSON_UNESCAPED_UNICODE));
                $chunk_hook = function ($info) use ($snapshot_path) {
                    $chunk = isset($info['chunk']) ? (string)$info['chunk'] : '';
                    if ($chunk === '') return;
                    $snap = @json_decode(@file_get_contents($snapshot_path), true);
                    if (!is_array($snap)) $snap = ['log_lines' => [], 'events' => [], 'stage' => 'running'];
                    if (!isset($snap['log_lines']) || !is_array($snap['log_lines'])) $snap['log_lines'] = [];
                    if (!isset($snap['events']) || !is_array($snap['events'])) $snap['events'] = [];
                    $buf = isset($snap['_buf']) ? (string)$snap['_buf'] : '';
                    $buf .= $chunk;
                    $parts = explode("\n", str_replace("\r\n", "\n", $buf));
                    $buf = (string)array_pop($parts);
                    foreach ($parts as $ln) {
                        $ln = rtrim($ln, "\r");
                        if ($ln === '') continue;
                        $snap['last_line'] = $ln;
                        $first = substr($ln, 0, 1);
                        if ($first === '{') {
                            $decoded = @json_decode($ln, true);
                            if (is_array($decoded) && isset($decoded['__kind'])) {
                                $snap['events'][] = $decoded;
                                if (count($snap['events']) > 400) {
                                    $snap['events'] = array_slice($snap['events'], -300);
                                }
                                if (isset($decoded['stage'])) $snap['stage'] = (string)$decoded['stage'];
                            }
                        }
                        $snap['log_lines'][] = $ln;
                        if (count($snap['log_lines']) > 500) {
                            $snap['log_lines'] = array_slice($snap['log_lines'], -400);
                        }
                    }
                    $snap['_buf'] = $buf;
                    @file_put_contents($snapshot_path, json_encode($snap, JSON_UNESCAPED_UNICODE));
                };
            }
            try {
                $result = $chunk_hook ? run_python_locked($args, $timeout, $chunk_hook) : run_python_locked($args, $timeout);
            } finally {
                if ($stream && is_file($snapshot_path)) {
                    $snap = @json_decode(@file_get_contents($snapshot_path), true);
                    if (!is_array($snap)) $snap = [];
                    $snap['finished'] = true;
                    $snap['finished_at'] = date('c');
                    @file_put_contents($snapshot_path, json_encode($snap, JSON_UNESCAPED_UNICODE));
                }
            }
            $stdout = (string)($result['stdout'] ?? '');
            $stderr = (string)($result['stderr'] ?? '');
            $combined = trim($stdout) !== '' ? $stdout : $stderr;
            $summary = null;
            if (trim($stdout) !== '') {
                $buf = '';
                $depth = 0;
                $inStr = false;
                $esc = false;
                $lines = explode("\n", str_replace("\r\n", "\n", $stdout));
                for ($i = count($lines) - 1; $i >= 0; $i--) {
                    $rawLine = $lines[$i];
                    for ($j = strlen($rawLine) - 1; $j >= 0; $j--) {
                        $ch = $rawLine[$j];
                        if ($esc) { $buf = $ch . $buf; $esc = false; continue; }
                        if ($ch === '\\') { $buf = $ch . $buf; $esc = true; continue; }
                        if ($inStr) { if ($ch === '"') $inStr = false; $buf = $ch . $buf; continue; }
                        if ($ch === '"') { $inStr = true; $buf = $ch . $buf; continue; }
                        if ($ch === '{' || $ch === '[') { $depth++; $buf = $ch . $buf; }
                        elseif ($ch === '}' || $ch === ']') { $depth--; $buf = $ch . $buf; }
                        else { $buf = $ch . $buf; }
                        if ($depth === 0 && ($ch === '{' || $ch === ']')) {
                            $decoded = @json_decode(trim($buf), true);
                            if (is_array($decoded) && empty($decoded['__kind'])) { $summary = $decoded; break 2; }
                            $buf = '';
                        }
                    }
                }
                if ($summary === null) {
                    for ($i = count($lines) - 1; $i >= 0; $i--) {
                        $line = trim($lines[$i]);
                        if ($line === '') continue;
                        $first = substr($line, 0, 1);
                        if ($first === '{' || $first === '[') {
                            $decoded = @json_decode($line, true);
                            if (is_array($decoded) && empty($decoded['__kind'])) { $summary = $decoded; break; }
                        }
                    }
                }
                if ($summary === null) {
                    $cleaned = preg_replace('/^__EVT__.*$/m', '', $stdout);
                    $cleaned = trim((string)$cleaned);
                    if ($cleaned !== '') {
                        $decoded = @json_decode($cleaned, true);
                        if (is_array($decoded) && empty($decoded['__kind'])) { $summary = $decoded; }
                    }
                }
            }
            $ok = !empty($result['ok']);
            $resp = [
                'ok' => $ok,
                'output' => $combined,
                'exit_code' => $result['exit_code'] ?? -1,
                'summary' => $summary,
            ];
            if (!$ok && !empty($result['error'])) $resp['error'] = $result['error'];
            json_exit($resp, $ok);
            break;

        case 'get_sync_fav_progress':
            $snapshot_path = $root . '/data/sync_fav_snapshot.json';
            $snap = is_file($snapshot_path) ? @json_decode(@file_get_contents($snapshot_path), true) : null;
            json_exit([
                'ok' => true,
                'snapshot' => is_array($snap) ? $snap : null,
                'exists' => is_file($snapshot_path),
            ], true);
            break;

        case 'clear_sync_fav_progress':
            $snapshot_path = $root . '/data/sync_fav_snapshot.json';
            if (is_file($snapshot_path)) @unlink($snapshot_path);
            json_exit(['ok' => true], true);
            break;

        case 'cache_search':
            $source = $_GET['source'] ?? 'exhentai';
            $artist = $_GET['artist'] ?? '';
            $title = $_GET['title'] ?? '';
            $category = $_GET['category'] ?? '';
            $categories_raw = $_GET['categories'] ?? '';
            $page = max(1, (int)($_GET['page'] ?? 1));
            $per_page = max(1, min(200, (int)($_GET['per_page'] ?? 25)));
            $offset = ($page - 1) * $per_page;
            $categories = [];
            if ($categories_raw !== '') {
                foreach (explode(',', $categories_raw) as $c) {
                    $c = trim($c);
                    if ($c !== '') $categories[] = $c;
                }
            }
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $pdo->sqliteCreateFunction('regexp', function ($pattern, $subject) {
                    if ($pattern === null || $pattern === '') return 0;
                    $p = @preg_match('/' . str_replace('/', '\\/', (string)$pattern) . '/ui' , (string)$subject);
                    return $p === 1 ? 1 : 0;
                }, 2);

                // ————— 加载 tag_blacklist：(type, value) 命中任意即被排除（缓存浏览）
                $pdo->exec("CREATE TABLE IF NOT EXISTS tag_blacklist (id INTEGER PRIMARY KEY AUTOINCREMENT, tag_type TEXT NOT NULL DEFAULT '', tag_value TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT '', UNIQUE(tag_type, tag_value))");
                $bl_rows = $pdo->query("SELECT DISTINCT tag_type, tag_value FROM tag_blacklist")->fetchAll();
                $bl_content_patterns = [];   // 指定 type 的内容 tag，进入 content-tag pcre
                $bl_artist_tokens = [];      // 作者黑名单 exact
                $bl_any_patterns = [];       // 通配 type 兜底 strict match
                foreach ($bl_rows as $bl) {
                    $tv = trim($bl['tag_value'] ?? '');
                    if ($tv === '') continue;
                    $tt = trim($bl['tag_type'] ?? '');
                    if ($tt === '' || $tt === '*') {
                        $bl_artist_tokens[] = $tv;
                        $p = _strict_token_safe($tv);
                        if ($p !== '') $bl_any_patterns[] = $p;
                    } elseif ($tt === 'artist' || $tt === 'group' || $tt === 'author') {
                        $bl_artist_tokens[] = $tv;
                    } else {
                        $safe = _strict_token_safe($tv);
                        if ($safe === '') continue;
                        $type_lit = '"type"\s*:\s*"' . preg_quote($tt, '/') . '"';
                        $name_hit = '"(?:name|name_cn)"\s*:\s*"[^"]*' . $safe . '[^"]*"';
                        $bl_content_patterns[] = '\{' .
                            '(?:[^{}]{0,320}' . $type_lit . '[^{}]{0,320}' . $name_hit .
                            '|[^{}]{0,320}' . $name_hit . '[^{}]{0,320}' . $type_lit .
                            ')[^{}]{0,320}\}';
                    }
                }
                $bl_artist_regexp = '';
                if (!empty($bl_artist_tokens)) {
                    $pats = [];
                    foreach ($bl_artist_tokens as $at) {
                        $p = _pattern_artist_col($at);
                        if ($p !== '') $pats[] = $p;
                    }
                    $bl_artist_regexp = _merge_or_patterns($pats);
                }
                $bl_content_regexp = _merge_or_patterns($bl_content_patterns);
                $bl_any_regexp = _merge_or_patterns($bl_any_patterns);

                // Map old scope names to new ones (B.W.C.)
                $search_fields_raw = $_GET['search_fields'] ?? 'all';
                if ($search_fields_raw !== 'all') {
                    $old = array_map('trim', explode(',', $search_fields_raw));
                    $scope_list = [];
                    foreach ($old as $s) {
                        if ($s === 'title') $scope_list[] = 'title';
                        elseif ($s === 'artist') $scope_list[] = 'author';   // BWC: old 'artist' scope = new 'author' scope
                        elseif ($s === 'tags') { $scope_list[] = 'tags'; $scope_list[] = 'tags_cn'; }
                        elseif ($s === 'tags_cn' || $s === 'author') $scope_list[] = $s;
                    }
                    $scope_list = array_values(array_unique($scope_list));
                } else {
                    $scope_list = ['title','author','tags','tags_cn'];
                }
                $scope_set = array_flip($scope_list);

                // ————— Build per-token OR patterns + global AND lookahead —————
                $needles_from_kw = [];
                if ($artist !== '') $needles_from_kw = array_merge($needles_from_kw, _split_and_tokens($artist));
                if ($title  !== '') $needles_from_kw = array_merge($needles_from_kw, _split_and_tokens($title));
                $has_keyword_cond = !empty($needles_from_kw);

                // 两阶段过滤（解决 41s 全表慢问题）：
                //   阶段1. SQL 层用 LIKE 子串做廉价粗过滤 —— C 内建实现，比跨层 REGEXP 快 5-20 倍
                //   阶段2. PHP 层用严格 strict_token + 非内容命名空间负向前瞻精确匹配，只扫阶段1的小候选集
                $php_pattern = '';
                if ($has_keyword_cond) {
                    $token_ors = [];
                    $kw_tokens = [];
                    if ($artist !== '') {
                        foreach (_split_and_tokens($artist) as $tok) $kw_tokens[] = [$tok, ['author']];
                    }
                    if ($title !== '') {
                        foreach (_split_and_tokens($title) as $tok) $kw_tokens[] = [$tok, $scope_list];
                    }
                    foreach ($kw_tokens as [$tok, $t_scopes]) {
                        $cols = [
                            't'  => [], // sc.title
                            'tj' => [], // sc.title_jp
                            'a'  => [], // sc.artist
                            'ta' => [], // sc.tags
                            'tc' => [], // sc.tags_cn
                        ];
                        $t_set = array_flip($t_scopes);
                        if (isset($t_set['title'])) {
                            $cols['t'][]  = _pattern_title_col($tok);
                            $cols['tj'][] = _pattern_title_col($tok);
                        }
                        if (isset($t_set['author'])) {
                            $cols['a'][]  = _pattern_artist_col($tok);
                            $cols['ta'][] = _pattern_json_author($tok);
                            $cols['tc'][] = _pattern_json_author($tok);
                        }
                        if (isset($t_set['tags']))     $cols['ta'][] = _pattern_content_tag($tok);
                        if (isset($t_set['tags_cn']))  $cols['tc'][] = _pattern_content_tag($tok);
                        $tok_or_patterns = [];
                        foreach ($cols as $pats) {
                            $m = _merge_or_patterns($pats);
                            if ($m !== '') $tok_or_patterns[] = $m;
                        }
                        $tok_or_patterns = array_values(array_filter($tok_or_patterns, function($p){ return $p !== ''; }));
                        if (!empty($tok_or_patterns)) {
                            $token_ors[] = _merge_or_patterns($tok_or_patterns);
                        }
                    }
                    $php_pattern = _merge_and_patterns($token_ors);
                }

                $params = [];
                $where = ' WHERE 1=1';
                // ————— 阶段 0：精确排除（O(1) NOT IN 走 Index，避免重复获取模式下 fuzzy 无法根除的 males only / dickgirl / 伪娘）
                //   加在所有 3 条查询（cand_base_query / all_query / data_query）共用的 $where 上，三条路径自动生效
                $ex_sid_count = (int)$pdo->query("SELECT COUNT(*) FROM excluded_sids")->fetchColumn();
                if ($ex_sid_count > 0) {
                    $where .= " AND (sc.source, sc.source_id) NOT IN (SELECT source, source_id FROM excluded_sids)";
                }
                $ex_part_count = (int)$pdo->query("SELECT COUNT(*) FROM excluded_participants")->fetchColumn();
                if ($ex_part_count > 0) {
                    $where .= " AND sc.artist NOT IN (SELECT name FROM excluded_participants WHERE kind='artist')";
                    $where .= " AND (sc.group_name IS NULL OR sc.group_name='' OR sc.group_name NOT IN (SELECT name FROM excluded_participants WHERE kind='group'))";
                }
                if ($source !== '') { $where .= ' AND sc.source=?'; $params[] = $source; }

                if ($category) { $where .= ' AND sc.category=?'; $params[] = $category; }
                if (!empty($categories)) {
                    $where .= ' AND sc.category IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
                    $params = array_merge($params, $categories);
                }
                $cache_lang = $_GET['language'] ?? '';
                if ($cache_lang) {
                    $langs = array_filter(array_map('trim', explode(',', $cache_lang)));
                    if (!empty($langs)) {
                        $placeholders = implode(',', array_fill(0, count($langs), '?'));
                        $where .= ' AND sc.language IN (' . $placeholders . ')';
                        foreach ($langs as $l) $params[] = $l;
                    }
                }

                // ——————— 阶段 1：SQL 廉价 LIKE 粗过滤，把无关行提前砍掉 ———————
                // 去掉 LOWER / COALESCE：SQLite LIKE 默认 ASCII 大小写不敏感，NULL LIKE X 自动 FALSE
                // 执行顺序严格保证：sc.source=? / sc.category IN ? / sc.language IN ? (等值/IN 可用索引) → LIKE AND (子串廉价 C 实现) → 绝对不把 REGEXP 放进 SQL WHERE
                if ($has_keyword_cond && $php_pattern !== '') {
                    $like_token_groups = [];
                    $kw_like_tokens = [];
                    $escapeLike = function ($s) {
                        return strtr($s, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']);
                    };
                    if ($artist !== '') {
                        foreach (_split_and_tokens($artist) as $tok) $kw_like_tokens[] = [$tok, ['author']];
                    }
                    if ($title !== '') {
                        foreach (_split_and_tokens($title) as $tok) $kw_like_tokens[] = [$tok, $scope_list];
                    }
                    foreach ($kw_like_tokens as [$tok, $t_scopes]) {
                        $needle_like = '%' . $escapeLike((string)$tok) . '%';
                        $t_set = array_flip($t_scopes);
                        $tok_likes = [];
                        if (isset($t_set['title'])) {
                            $tok_likes[] = "sc.title LIKE ?";
                            $tok_likes[] = "sc.title_jp LIKE ?";
                            $params[] = $needle_like; $params[] = $needle_like;
                        }
                        if (isset($t_set['author'])) {
                            $tok_likes[] = "sc.artist LIKE ?";
                            $tok_likes[] = "sc.tags LIKE ?";
                            $tok_likes[] = "sc.tags_cn LIKE ?";
                            $params[] = $needle_like; $params[] = $needle_like; $params[] = $needle_like;
                        }
                        if (isset($t_set['tags']) || isset($t_set['tags_cn'])) {
                            if (isset($t_set['tags']))    { $tok_likes[] = "sc.tags LIKE ?";    $params[] = $needle_like; }
                            if (isset($t_set['tags_cn'])) { $tok_likes[] = "sc.tags_cn LIKE ?"; $params[] = $needle_like; }
                        }
                        if (!empty($tok_likes)) {
                            $like_token_groups[] = '(' . implode(' OR ', $tok_likes) . ')';
                        }
                    }
                    if (!empty($like_token_groups)) {
                        // 多 token AND 关系：每个 token 至少有一列 LIKE 命中
                        $where .= ' AND (' . implode(' AND ', $like_token_groups) . ')';
                    }
                }

                // ——————— 阶段 2：PHP 层精确过滤（渐进式探测 LIMIT：避免 LIKE 粗筛命中 2000+ 行时把全部 tags/tags_cn 大列拉到 PHP 拖慢 4.7s on NAS）———————
                if ($has_keyword_cond && $php_pattern !== '') {
                    $cand_base_query =
                        "SELECT sc.source_id, sc.title, sc.title_jp, sc.category, sc.language, sc.group_name,
                                sc.tags, sc.tags_cn, sc.total_pages, sc.artist, sc.uploaded_at,
                                sc.crawled_at, sc.thumb_path, sc.searched_at,
                                CASE WHEN EXISTS (SELECT 1 FROM galleries g WHERE g.source=sc.source AND g.source_id=sc.source_id) THEN 1 ELSE 0 END as is_local
                         FROM search_cache sc
                         $where
                         ORDER BY sc.uploaded_at DESC, sc.crawled_at DESC";
                    $US = "\x1F";
                    $preg_pattern = '/' . str_replace('/', '\\/', $php_pattern) . '/ui';
                    $bl_content_preg = $bl_content_regexp !== '' ? '/' . str_replace('/', '\\/', $bl_content_regexp) . '/ui' : '';
                    $bl_artist_preg  = $bl_artist_regexp  !== '' ? '/' . str_replace('/', '\\/', $bl_artist_regexp)  . '/ui' : '';
                    $bl_any_preg     = $bl_any_regexp     !== '' ? '/' . str_replace('/', '\\/', $bl_any_regexp)     . '/ui' : '';
                    // 初始批次：够 offset + 一页即可（page1 offset0 → 取25；page3 offset60 → 取90）
                    $target_end = $offset + $per_page;
                    $batch_size = max($per_page * 2, 60);
                    $probe_limit = $target_end + $batch_size;
                    $probe_offset = 0;
                    $pages_probe = 0;
                    $filtered = [];
                    $total_raw_est = 0;
                    while (count($filtered) < $target_end && $pages_probe < 10) {
                        $q = $cand_base_query . " LIMIT $probe_limit OFFSET $probe_offset";
                        $stmt = $pdo->prepare($q);
                        $stmt->execute($params);
                        $batch = $stmt->fetchAll();
                        if (empty($batch)) break;
                        $pages_probe++;
                        $probe_offset += $probe_limit;
                        $total_raw_est += count($batch);
                        $enough = false;
                        foreach ($batch as $r) {
                            $tags    = (string)($r['tags']    ?? '');
                            $tags_cn = (string)($r['tags_cn'] ?? '');
                            $art     = (string)($r['artist']  ?? '');
                            $hits_bl = false;
                            if (!$hits_bl && $bl_artist_preg  !== '' && @preg_match($bl_artist_preg, $art) === 1) $hits_bl = true;
                            if (!$hits_bl && $bl_content_preg !== '' && (@preg_match($bl_content_preg, $tags) === 1 || @preg_match($bl_content_preg, $tags_cn) === 1)) $hits_bl = true;
                            if (!$hits_bl && $bl_any_preg     !== '' && (@preg_match($bl_any_preg, $tags) === 1 || @preg_match($bl_any_preg, $tags_cn) === 1 || @preg_match($bl_any_preg, $art) === 1)) $hits_bl = true;
                            if ($hits_bl) continue;
                            $subject =
                                ($r['title'] ?? '') . $US .
                                ($r['title_jp'] ?? '') . $US .
                                $art . $US .
                                $tags . $US .
                                $tags_cn;
                            if (@preg_match($preg_pattern, $subject) === 1) $filtered[] = $r;
                            if (count($filtered) >= $target_end) { $enough = true; break; }
                        }
                        if ($enough) break;
                    }
                    // 路径 A 不再做 usort：SQL ORDER BY sc.uploaded_at DESC, sc.crawled_at DESC 已经保证逐批次 global 顺序（上传倒序 + 爬取倒序复合主键稳定）
                    $total = count($filtered) >= $target_end ? max($offset + count($filtered), (int)($total_raw_est * 0.6)) : count($filtered);
                    $rows  = array_slice($filtered, $offset, $per_page);
                } else {
                    // 无 keyword（只按 source/category/language 翻页）：只在有黑名单条目时在 PHP 侧拉全页再切，否则保持原 LIMIT/OFFSET 高速路径
                    $count_query = "SELECT COUNT(*) FROM search_cache sc $where";
                    $count_stmt = $pdo->prepare($count_query);
                    $count_stmt->execute($params);
                    $total_raw = (int)$count_stmt->fetchColumn();
                    if (!empty($bl_rows)) {
                        // 拉 前 (offset + per_page) 行即可（SQL ORDER 已保证 uploaded_at DESC 稳定）
                        $limit_top = $offset + $per_page;
                        $all_query = "SELECT sc.source_id, sc.title, sc.title_jp, sc.category, sc.language, sc.group_name,
                                             sc.tags, sc.tags_cn, sc.total_pages, sc.artist, sc.uploaded_at,
                                             sc.crawled_at, sc.thumb_path, sc.searched_at,
                                             CASE WHEN EXISTS (SELECT 1 FROM galleries g WHERE g.source=sc.source AND g.source_id=sc.source_id) THEN 1 ELSE 0 END as is_local
                                      FROM search_cache sc
                                      $where
                                      ORDER BY sc.uploaded_at DESC, sc.crawled_at DESC
                                      LIMIT $limit_top";
                        $stmt = $pdo->prepare($all_query);
                        $stmt->execute($params);
                        $top_rows = $stmt->fetchAll();
                        $bl_content_preg = $bl_content_regexp !== '' ? '/' . str_replace('/', '\\/', $bl_content_regexp) . '/ui' : '';
                        $bl_artist_preg  = $bl_artist_regexp  !== '' ? '/' . str_replace('/', '\\/', $bl_artist_regexp)  . '/ui' : '';
                        $bl_any_preg     = $bl_any_regexp     !== '' ? '/' . str_replace('/', '\\/', $bl_any_regexp)     . '/ui' : '';
                        // 我们需要 offset..offset+per_page 这段中"剩余的非黑名单行"，但黑名单命中的会被跳过，实际可能不够一页 → 若不够再继续取下一页补充（最多补 10 页限制）
                        $kept = [];
                        $pages_probe = 0;
                        $probe_offset = 0;
                        $target_end = $offset + $per_page;
                        while (count($kept) < $target_end && $pages_probe < 10) {
                            if (!empty($top_rows)) {
                                $this_batch = $top_rows;
                                $top_rows = [];
                            } else {
                                $probe_limit = $per_page * 2;
                                $q = $all_query . " OFFSET $probe_offset";
                                $stmt2 = $pdo->prepare($q);
                                $stmt2->execute($params);
                                $this_batch = $stmt2->fetchAll();
                                $probe_offset += $probe_limit;
                            }
                            if (empty($this_batch)) break;
                            $pages_probe++;
                            foreach ($this_batch as $r) {
                                $tags = (string)($r['tags'] ?? '');
                                $tags_cn = (string)($r['tags_cn'] ?? '');
                                $art = (string)($r['artist'] ?? '');
                                $hits_bl = false;
                                if ($bl_artist_preg  !== '' && @preg_match($bl_artist_preg, $art) === 1) $hits_bl = true;
                                if (!$hits_bl && $bl_content_preg !== '' && (@preg_match($bl_content_preg, $tags) === 1 || @preg_match($bl_content_preg, $tags_cn) === 1)) $hits_bl = true;
                                if (!$hits_bl && $bl_any_preg     !== '' && (@preg_match($bl_any_preg, $tags) === 1 || @preg_match($bl_any_preg, $tags_cn) === 1 || @preg_match($bl_any_preg, $art) === 1)) $hits_bl = true;
                                if (!$hits_bl) $kept[] = $r;
                                if (count($kept) >= $target_end) break 2;
                            }
                        }
                        $total = $total_raw; // 不再做全表精确 count（REGEXP 全表代价太高）；若需要精确 total 可在下一次大翻页时做一次
                        $rows = array_slice($kept, $offset, $per_page);
                    } else {
                        $total = $total_raw;
                        $data_query = "SELECT sc.*, CASE WHEN EXISTS (SELECT 1 FROM galleries g WHERE g.source=sc.source AND g.source_id=sc.source_id) THEN 1 ELSE 0 END as is_local FROM search_cache sc $where ORDER BY sc.uploaded_at DESC, sc.crawled_at DESC LIMIT $per_page OFFSET $offset";
                        $stmt = $pdo->prepare($data_query);
                        $stmt->execute($params);
                        $rows = $stmt->fetchAll();
                    }
                }

                $results = [];
                $seen_keys = [];
                // ——— seen_keys 兜底判重 + 缺失补足机制（保证返回永远满 per_page，避免判重丢弃后缺行）
                $need_more = true;
                $extra_probe_pages = 0;
                $extra_rows_from_sql = null;   // 补拉模式下：SQL 已拉到的 extra_rows（供后续循环用）
                $extra_rows_cursor = 0;        // 遍历 extra_rows_from_sql 的游标
                $rows_original = $rows;
                while ($need_more && $extra_probe_pages < 10) {
                    if ($extra_probe_pages === 0) {
                        $iterate_rows = $rows_original;
                    } else {
                        // 第 2+ 轮补足：我们用 SQL 再 OFFSET 往后拉 per_page*2 行，从原始 LIMIT 结束的位置继续
                        if ($extra_rows_from_sql === null) {
                            // 只有无 keyword + 无黑名单的高速路径才会触发补足（因为路径 A/B 本身是 PHP 数组切片，不会因 SQL JOIN 重复导致缺行）
                            $extra_offset = ($offset + $per_page) + ($extra_probe_pages - 1) * ($per_page * 2);
                            $extra_limit = $per_page * 2;
                            $_extra_sql = "SELECT sc.*, CASE WHEN EXISTS (SELECT 1 FROM galleries g WHERE g.source=sc.source AND g.source_id=sc.source_id) THEN 1 ELSE 0 END as is_local FROM search_cache sc $where ORDER BY sc.uploaded_at DESC, sc.crawled_at DESC LIMIT $extra_limit OFFSET $extra_offset";
                            $_stmt_e = $pdo->prepare($_extra_sql);
                            $_stmt_e->execute($params);
                            $extra_rows_from_sql = $_stmt_e->fetchAll();
                            $extra_rows_cursor = 0;
                            if (empty($extra_rows_from_sql)) break;
                        }
                        $iterate_rows = array_slice($extra_rows_from_sql, $extra_rows_cursor);
                    }
                    foreach ($iterate_rows as $row) {
                        $source_id = $row['source_id'] ?? '';
                        $src = $row['source'] ?? $source;
                        $k = $src . '::' . $source_id;
                        if (isset($seen_keys[$k])) {
                            if ($extra_probe_pages > 0) $extra_rows_cursor++;
                            continue;
                        }
                        $seen_keys[$k] = true;
                        if ($extra_probe_pages > 0) $extra_rows_cursor++;
                        $thumb_url = '';
                        $thumb_path = $row['thumb_path'] ?? '';
                        if ($thumb_path && is_file($thumb_path)) {
                            $thumb_url = 'api.php?action=serve_cache_thumb&source=' . urlencode($source) . '&source_id=' . urlencode($source_id);
                        }
                        $raw_tags = (string)($row['tags'] ?? '');
                        $raw_tags_cn = (string)($row['tags_cn'] ?? '');
                        // 逐 tag 兜底：_t_translate_tags_json 内部对已有 name_cn 的 tag 短路跳过，
                        // 只补缺失的（避免整行含任一 name_cn 就跳过整行导致部分 tag 缺翻译）
                        $final_tags_cn = $raw_tags_cn;
                        if ($raw_tags !== '') {
                            $final_tags_cn = _t_translate_tags_json($raw_tags, $raw_tags_cn);
                            if ($final_tags_cn === '') $final_tags_cn = $raw_tags_cn;
                        }
                        $results[] = [
                            'source'      => $src,
                            'source_id'   => $source_id,
                            'title'       => $row['title'] ?? '',
                            'title_jp'    => $row['title_jp'] ?? '',
                            'category'    => $row['category'] ?? '',
                            'language'    => $row['language'] ?? '',
                            'group_name'  => $row['group_name'] ?? '',
                            'tags'        => $raw_tags,
                            'tags_cn'     => $final_tags_cn,
                            'total_pages' => (int)($row['total_pages'] ?? 0),
                            'artist'      => $row['artist'] ?? '',
                            'uploaded_at' => $row['uploaded_at'] ?? '',
                            'is_local'    => (int)($row['is_local'] ?? 0) === 1,
                            'thumb_url'   => $thumb_url,
                            'searched_at' => $row['searched_at'] ?? '',
                        ];
                        if (count($results) >= $per_page) { $need_more = false; break; }
                    }
                    if (count($results) >= $per_page) break;
                    // 当前批次没攒够一页 → 进入下一轮补拉
                    $extra_probe_pages++;
                    if ($extra_probe_pages > 0) {
                        // SQL 已补拉的这一批已经遍历完 → 清空让下一轮再 OFFSET 继续
                        if ($extra_rows_from_sql !== null && $extra_rows_cursor >= count($extra_rows_from_sql)) {
                            $extra_rows_from_sql = null;
                        }
                    }
                }
                json_exit(['results' => $results, 'total' => $total, 'page' => $page, 'per_page' => $per_page]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'cache_artists':
            $source = $_GET['source'] ?? 'exhentai';
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_COLUMN);
                $stmt = $pdo->prepare("SELECT DISTINCT artist FROM search_cache WHERE source=? AND artist!='' ORDER BY artist");
                $stmt->execute([$source]);
                $artists = $stmt->fetchAll();
                json_exit(['artists' => $artists]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'translate_tag':
            $ns = $_GET['ns'] ?? ($_GET['type'] ?? '');
            $name = $_GET['name'] ?? '';
            $ns = trim((string)$ns);
            $name = trim((string)$name);
            $out = ['name' => $name, 'ns' => $ns, 'name_cn' => null, 'aliases' => _t_split_aliases($name)];
            if ($ns !== '' && $name !== '') {
                $cn = _t_translate_single($ns, $name, _t_split_aliases($name));
                if ($cn !== null && $cn !== '') $out['name_cn'] = $cn;
            }
            json_exit($out);
            break;

        case 'cache_categories':
            $source = $_GET['source'] ?? 'exhentai';
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_COLUMN);
                $stmt = $pdo->prepare("SELECT DISTINCT category FROM search_cache WHERE source=? AND category!='' ORDER BY category");
                $stmt->execute([$source]);
                $categories = $stmt->fetchAll();
                json_exit(['categories' => $categories]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'cache_languages':
            $source = $_GET['source'] ?? 'exhentai';
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_COLUMN);
                $stmt = $pdo->prepare("SELECT DISTINCT language FROM search_cache WHERE source=? AND language!='' ORDER BY language");
                $stmt->execute([$source]);
                $languages = $stmt->fetchAll();
                json_exit(['languages' => $languages]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'crawl':
            $source = $_POST['source'] ?? 'exhentai';
            $query = trim($_POST['query'] ?? '');
            $force = !empty($_POST['force']);
            $categories = $_POST['categories'] ?? '';
            $languages = $_POST['languages'] ?? '';
            if (!$query) error_exit('Query required');
            $cat_map = ['Misc'=>1,'Doujinshi'=>2,'Manga'=>4,'Artist CG'=>8,'Game CG'=>16,'Image Set'=>32,'Cosplay'=>64,'Asian Porn'=>128,'Non-H'=>256,'Western'=>512];
            $category_values = [];
            if ($categories === 'all') {
                $category_values[] = array_sum(array_values($cat_map));
            } elseif ($categories !== '') {
                foreach (explode(',', $categories) as $category) {
                    $category = trim($category);
                    if (isset($cat_map[$category])) $category_values[] = $cat_map[$category];
                }
            }
            try {
                $pdo = _pdo();
                $pdo->exec("CREATE TABLE IF NOT EXISTS crawl_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL DEFAULT 'exhentai', query TEXT NOT NULL, categories TEXT DEFAULT '', languages TEXT DEFAULT '', force_crawl INTEGER DEFAULT 0, status TEXT NOT NULL DEFAULT 'pending', error TEXT DEFAULT '', created_at TEXT NOT NULL DEFAULT '', started_at TEXT DEFAULT '', finished_at TEXT DEFAULT '')");
                $stmt = $pdo->prepare("INSERT INTO crawl_jobs (source,query,categories,languages,force_crawl,status,created_at) VALUES (?,?,?,?,?,'pending',datetime('now','localtime'))");
                $stmt->execute([$source, $query, implode(',', $category_values), $languages, $force ? 1 : 0]);
                $job_id = (int)$pdo->lastInsertId();
                $stmt = $pdo->prepare("SELECT COUNT(*) FROM crawl_jobs WHERE status='pending' AND id<=?");
                $stmt->execute([$job_id]);
                $position = (int)$stmt->fetchColumn();
                $worker_pid_file = $root . '/data/crawl_worker_pid.txt';
                $worker_running = false;
                if (is_file($worker_pid_file)) {
                    $worker_pid = trim((string)file_get_contents($worker_pid_file));
                    $worker_running = is_crawl_worker_process($worker_pid);
                }
                if (!$worker_running) {
                    @unlink($worker_pid_file);
                    run_python_background(['crawl-worker'], $worker_pid_file);
                }
                json_exit(['message' => '爬取任务已加入队列', 'job_id' => $job_id, 'position' => $position]);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        case 'crawl_queue':
            try {
                $pdo = _pdo();
                $pdo->exec("CREATE TABLE IF NOT EXISTS crawl_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL DEFAULT 'exhentai', query TEXT NOT NULL, categories TEXT DEFAULT '', languages TEXT DEFAULT '', force_crawl INTEGER DEFAULT 0, status TEXT NOT NULL DEFAULT 'pending', error TEXT DEFAULT '', created_at TEXT NOT NULL DEFAULT '', started_at TEXT DEFAULT '', finished_at TEXT DEFAULT '')");
                $jobs = $pdo->query("SELECT * FROM crawl_jobs WHERE status IN ('pending','running','cancel_requested') ORDER BY id")->fetchAll();
                json_exit(['jobs' => $jobs]);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        case 'crawl_history':
            try {
                $page = max(1, (int)($_GET['page'] ?? $_POST['page'] ?? 1));
                $per_page = max(10, min(100, (int)($_GET['per_page'] ?? $_POST['per_page'] ?? 50)));
                $offset = ($page - 1) * $per_page;
                $pdo = _pdo();
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->exec("CREATE TABLE IF NOT EXISTS crawl_jobs (id INTEGER PRIMARY KEY AUTOINCREMENT, source TEXT NOT NULL DEFAULT 'exhentai', query TEXT NOT NULL, categories TEXT DEFAULT '', languages TEXT DEFAULT '', force_crawl INTEGER DEFAULT 0, status TEXT NOT NULL DEFAULT 'pending', error TEXT DEFAULT '', created_at TEXT NOT NULL DEFAULT '', started_at TEXT DEFAULT '', finished_at TEXT DEFAULT '')");
                $pdo->exec("CREATE TABLE IF NOT EXISTS refresh_targets (id INTEGER PRIMARY KEY AUTOINCREMENT, preset_id INTEGER UNIQUE, name TEXT NOT NULL, query TEXT NOT NULL, categories TEXT DEFAULT '', languages TEXT DEFAULT '', force_crawl INTEGER DEFAULT 0, completed_at TEXT NOT NULL DEFAULT '', enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT '')");
                $columns = $pdo->query("PRAGMA table_info(crawl_jobs)")->fetchAll();
                $has_refresh_target_id = false;
                foreach ($columns as $column) {
                    if (($column['name'] ?? '') === 'refresh_target_id') $has_refresh_target_id = true;
                }
                if (!$has_refresh_target_id) $pdo->exec("ALTER TABLE crawl_jobs ADD COLUMN refresh_target_id INTEGER DEFAULT NULL");
                $total = (int)$pdo->query("SELECT COUNT(*) FROM crawl_jobs WHERE status IN ('completed','failed','cancelled')")->fetchColumn();
                $stmt = $pdo->prepare(
                    "SELECT j.*,rt.name AS refresh_target_name
                     FROM crawl_jobs j
                     LEFT JOIN refresh_targets rt ON rt.id=j.refresh_target_id
                     WHERE j.status IN ('completed','failed','cancelled')
                     ORDER BY COALESCE(NULLIF(j.finished_at,''),j.created_at) DESC,j.id DESC
                     LIMIT :limit OFFSET :offset"
                );
                $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
                $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
                $stmt->execute();
                json_exit(['jobs' => $stmt->fetchAll(), 'page' => $page, 'per_page' => $per_page, 'total' => $total]);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        case 'clear_crawl_history':
            $scope = $_POST['scope'] ?? $_GET['scope'] ?? '';
            if (!in_array($scope, ['failed', 'all'], true)) error_exit('Invalid history scope');
            try {
                $pdo = _pdo();
                if ($scope === 'failed') {
                    $stmt = $pdo->prepare("DELETE FROM crawl_jobs WHERE status IN ('failed','cancelled')");
                } else {
                    $stmt = $pdo->prepare("DELETE FROM crawl_jobs WHERE status IN ('completed','failed','cancelled')");
                }
                $stmt->execute();
                $deleted = $stmt->rowCount();
                json_exit(['message' => '已清理 ' . $deleted . ' 条爬取历史', 'deleted' => $deleted]);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        case 'translate_search_preset_name':
            $query = trim($_POST['query'] ?? $_GET['query'] ?? '');
            json_exit(['name' => $query === '' ? '' : translate_search_preset_name($query)]);
            break;

        case 'stop_crawl':
            $job_id = (int)($_POST['job_id'] ?? $_GET['job_id'] ?? 0);
            if (!$job_id) error_exit('job_id required');
            try {
                $pdo = _pdo();
                $stmt = $pdo->prepare('SELECT id,source,status FROM crawl_jobs WHERE id=?');
                $stmt->execute([$job_id]);
                $job = $stmt->fetch();
                if (!$job) error_exit('Queue job not found');
                if ($job['status'] === 'pending') {
                    $pdo->prepare("UPDATE crawl_jobs SET status='cancelled',finished_at=datetime('now','localtime') WHERE id=? AND status='pending'")->execute([$job_id]);
                } elseif ($job['status'] === 'running') {
                    $pdo->prepare("UPDATE crawl_jobs SET status='cancel_requested' WHERE id=? AND status='running'")->execute([$job_id]);
                    file_put_contents($root . '/data/crawl_cancel_' . $job['source'] . '.flag', '1');
                }
                json_exit(['message' => '任务停止请求已提交', 'job_id' => $job_id]);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        case 'start_verify':
            $source = $_POST['source'] ?? $_GET['source'] ?? 'exhentai';
            $data_dir = $root . '/data';
            $pid_file = $data_dir . '/verify_pid_' . $source . '.txt';

            if (is_file($pid_file)) {
                $old_pid = trim(file_get_contents($pid_file));
                if ($old_pid && DIRECTORY_SEPARATOR !== '\\' && is_dir('/proc/' . $old_pid)) {
                    json_exit(['output' => '校对任务已在后台运行 (PID: ' . $old_pid . ')'], true);
                    break;
                }
            }

            $args = ['verify', $source];
            $pid = run_python_background($args, $pid_file);
            json_exit(['output' => '校对任务已在后台启动 (PID: ' . $pid . ')'], true);
            break;

        case 'stop_verify':
            $source = $_POST['source'] ?? $_GET['source'] ?? 'exhentai';
            $cancel_file = $root . '/data/verify_cancel_' . $source . '.flag';
            $pid_file = $root . '/data/verify_pid_' . $source . '.txt';
            file_put_contents($cancel_file, '1');
            if (is_file($pid_file)) {
                $pid = trim(file_get_contents($pid_file));
                if ($pid) {
                    if (DIRECTORY_SEPARATOR === '\\') {
                        exec('taskkill /F /PID ' . (int)$pid . ' 2>nul');
                    } else {
                        exec('kill -9 ' . (int)$pid . ' 2>/dev/null');
                    }
                }
                @unlink($pid_file);
            }
            foreach (glob($root . '/data/progress/verify__verify_' . $source . '*.json') as $f) {
                @unlink($f);
            }
            @unlink($cancel_file);
            @unlink($root . '/data/verify_checkpoint_' . $source . '.json');
            json_exit(['message' => '校对任务已终止'], true);
            break;

        case 'verify_status':
            $source = $_GET['source'] ?? $_POST['source'] ?? 'exhentai';
            $progress_dir = $root . '/data/progress';
            foreach (glob($progress_dir . '/verify__verify_' . $source . '*.json') as $f) {
                $content = @file_get_contents($f);
                if ($content === false) continue;
                $data = @json_decode($content, true);
                if (!is_array($data)) continue;
                json_exit(['running' => true, 'progress' => $data]);
                break 2;
            }
            json_exit(['running' => false]);
            break;

        case 'retry':
            $skip = !empty($_POST['skip_existing']);
            $data_dir = $root . '/data';
            $pid_file = $data_dir . '/retry.pid';

            // Check if a background retry is already running
            if (is_file($pid_file)) {
                $old_pid = trim(file_get_contents($pid_file));
                if ($old_pid && is_dir('/proc/' . $old_pid)) {
                    json_exit(['output' => '重试任务已在后台运行 (PID: ' . $old_pid . ')'], true);
                    break;
                }
            }

            $args = ['retry'];
            if ($skip) $args[] = '--skip-existing';
            $pid = run_python_background($args);
            json_exit(['output' => '重试任务已在后台启动 (PID: ' . $pid . ')'], true);
            break;

        case 'retry_status':
            $pid_file = $root . '/data/retry.pid';
            if (!is_file($pid_file)) {
                json_exit(['running' => false]);
                break;
            }
            $pid = trim(file_get_contents($pid_file));
            $running = $pid && is_dir('/proc/' . $pid);
            if (!$running) {
                @unlink($pid_file);
                // Clean up stale progress files
                foreach (glob($root . '/data/*.progress') as $f) {
                    if (time() - filemtime($f) > 3600) @unlink($f);
                }
            }
            json_exit(['running' => $running]);
            break;

        case 'export':
            $ts = date('Ymd_His');
            $output = $root . "/data/export_$ts.json";
            $zip_file = $root . "/data/export_$ts.zip";
            if (!is_dir($root . '/data')) mkdir($root . '/data', 0755, true);
            $args = ['export-package', '--output', $output, '--zip', $zip_file];
            $result = run_python($args);
            if ($result['ok'] && is_file($zip_file)) {
                json_exit(['file' => "data/export_$ts.zip", 'download_url' => "data/export_$ts.zip"]);
            }
            json_exit([
                'output' => $result['stdout'] ?: $result['stderr'],
            ], $result['ok']);
            break;

        case 'get_stats':
            $config = read_config();
            $db_path = $root . '/data/ehlib.db';
            $db_exists = is_file($db_path);
            $count = 0;
            $search_cache_count = 0;
            $db_size = 0;
            $thumbs_size = 0;
            $downloads_size = 0;
            if ($db_exists) {
                try {
                    $pdo = _pdo($db_path);
                    $stmt = $pdo->query('SELECT COUNT(*) as cnt FROM galleries');
                    $row = $stmt->fetch();
                    $count = (int)($row['cnt'] ?? 0);
                    try {
                        $stmt2 = $pdo->query('SELECT COUNT(*) as cnt FROM search_cache');
                        $row2 = $stmt2->fetch();
                        $search_cache_count = (int)($row2['cnt'] ?? 0);
                    } catch (Exception $eSC) { $search_cache_count = 0; }
                    try {
                        $stmt3 = $pdo->query('SELECT COALESCE(SUM(file_size),0) as sz FROM galleries WHERE file_size IS NOT NULL AND file_size > 0');
                        $row3 = $stmt3->fetch();
                        $downloads_size = (int)($row3['sz'] ?? 0);
                    } catch (Exception $eDS) { $downloads_size = 0; }
                } catch (Exception $e) {}
                $db_size = @filesize($db_path);
                foreach (['-wal', '-shm', '-journal'] as $suf) {
                    $extra = $db_path . $suf;
                    if (is_file($extra)) $db_size += @filesize($extra);
                }
            }
            // 封面目录大小（$root/data/thumbs 递归，3s 超时
            $thumbs_dir = $root . '/data/thumbs';
            if (is_dir($thumbs_dir)) {
                $thumbs_size = (int)_dir_total_bytes($thumbs_dir, 3000);
            }
            // 若 galleries SUM(file_size)=0 或 不可靠 -> fallback 扫描下载目录
            if ($downloads_size <= 0) {
                $dl_dir = $config['download']['path'] ?? ($root . '/downloads');
                if ($dl_dir && strpos($dl_dir, '/') !== 0 && strpos($dl_dir, ':') === false && strpos($dl_dir, '\\\\') !== 0) {
                    $dl_dir = $root . '/' . ltrim(str_replace('\\', '/', $dl_dir), '/');
                }
                if (is_dir($dl_dir)) $downloads_size = (int)_dir_total_bytes($dl_dir, 4000);
            }
            json_exit([
                'db_exists'          => $db_exists,
                'gallery_count'      => $count,
                'search_cache_count' => $search_cache_count,
                'db_size'            => $db_size,
                'thumbs_size'        => $thumbs_size,
                'downloads_size'     => $downloads_size,
                'config_file'        => is_file($root . '/config.yaml'),
                'venv_exists'        => is_dir($root . '/venv'),
                'download_path'      => $config['download']['path'] ?? './downloads',
            ]);
            break;

        case 'refresh_targets':
            try {
                $pdo = _pdo();
                $pdo->exec("CREATE TABLE IF NOT EXISTS refresh_targets (id INTEGER PRIMARY KEY AUTOINCREMENT, preset_id INTEGER UNIQUE, name TEXT NOT NULL, query TEXT NOT NULL, categories TEXT DEFAULT '', languages TEXT DEFAULT '', force_crawl INTEGER DEFAULT 0, completed_at TEXT NOT NULL DEFAULT '', enabled INTEGER NOT NULL DEFAULT 1, created_at TEXT NOT NULL DEFAULT '')");
                try { $pdo->exec("ALTER TABLE refresh_targets ADD COLUMN origin_kind TEXT DEFAULT ''"); } catch (Exception $eA) {}
                try { $pdo->exec("ALTER TABLE refresh_targets ADD COLUMN origin_artist TEXT DEFAULT ''"); } catch (Exception $eB) {}
                try { $pdo->exec("ALTER TABLE refresh_targets ADD COLUMN origin_favcats TEXT DEFAULT ''"); } catch (Exception $eC) {}

                $page = max(1, (int)($_GET['page'] ?? 1));
                $per_page = (int)($_GET['per_page'] ?? 50);
                if ($per_page <= 0) $per_page = 50;
                if ($per_page > 500) $per_page = 500;
                $offset = ($page - 1) * $per_page;

                $total_row = $pdo->query("SELECT COUNT(*) AS c FROM refresh_targets")->fetch(PDO::FETCH_ASSOC);
                $total = (int)($total_row['c'] ?? 0);
                $total_pages = (int)ceil($total / $per_page);

                $stmt = $pdo->prepare('SELECT id,preset_id,name,query,categories,languages,force_crawl,completed_at,enabled,origin_kind,origin_artist,origin_favcats FROM refresh_targets ORDER BY completed_at DESC,id DESC LIMIT ? OFFSET ?');
                $stmt->bindValue(1, $per_page, PDO::PARAM_INT);
                $stmt->bindValue(2, $offset, PDO::PARAM_INT);
                $stmt->execute();
                $targets = $stmt->fetchAll();

                $ns_alias = ['category' => 'reclass'];
                $trans_ns_map = null;
                $trans_loaded = false;
                function _ensure_translation(&$root, &$ns_alias, &$trans_ns_map, &$trans_loaded) {
                    if ($trans_loaded) return;
                    $trans_loaded = true;
                    $trans_ns_map = [];
                    $db_path = $root . '/data/eh_tag_translation.json';
                    if (!is_file($db_path)) return;
                    $raw = @json_decode((string)@file_get_contents($db_path), true);
                    $entries = is_array($raw) && isset($raw['data']) && is_array($raw['data']) ? $raw['data'] : $raw;
                    if (!is_array($entries)) return;
                    foreach ($entries as $entry) {
                        $ns_name = $entry['namespace'] ?? '';
                        $ns_data = $entry['data'] ?? null;
                        if (!$ns_name || !is_array($ns_data)) continue;
                        $tag_map = [];
                        foreach ($ns_data as $tag_key => $tag_val) {
                            if (is_array($tag_val) && !empty($tag_val['name'])) {
                                $tag_map[strtolower((string)$tag_key)] = (string)$tag_val['name'];
                                foreach (_t_split_aliases((string)$tag_key) as $alias_key) {
                                    $ak = strtolower((string)$alias_key);
                                    if (!isset($tag_map[$ak])) {
                                        $tag_map[$ak] = (string)$tag_val['name'];
                                    }
                                }
                            }
                        }
                        $trans_ns_map[$ns_name] = $tag_map;
                    }
                }
                function _translate_ns_name(&$root, &$ns_alias, &$trans_ns_map, &$trans_loaded, $ns, $name_raw) {
                    _ensure_translation($root, $ns_alias, $trans_ns_map, $trans_loaded);
                    if (!$trans_ns_map || $name_raw === null || $name_raw === '') return null;
                    $name = (string)$name_raw;
                    if (substr($name, -1) === '$') $name = substr($name, 0, -1);
                    $lookup_ns = $ns_alias[$ns] ?? $ns;
                    $ns_map = $trans_ns_map[$lookup_ns] ?? null;
                    if (!$ns_map) return null;
                    $lc = strtolower($name);
                    if (isset($ns_map[$lc])) return $ns_map[$lc];
                    foreach (_t_split_aliases($name) as $a) {
                        $alc = strtolower((string)$a);
                        if (isset($ns_map[$alc])) return $ns_map[$alc];
                    }
                    return null;
                }

                $QUERY_TOKEN_RE = '/(?<!\S)(-?)([a-zA-Z_]+):(?:"([^"]+)"|(\S+))/';
                $NS_DISPLAY = [
                    'artist' => '作者', 'character' => '角色', 'cosplayer' => 'Coser',
                    'female' => '女性', 'group' => '社团', 'language' => '语言',
                    'male' => '男性', 'mixed' => '混合', 'other' => '其他',
                    'parody' => '原作', 'reclass' => '分类', 'category' => '分类',
                ];
                foreach ($targets as &$t) {
                    $query_label = null;
                    if (!empty($t['query'])) {
                        $query_label = preg_replace_callback($QUERY_TOKEN_RE, function ($m) use (&$root, &$ns_alias, &$trans_ns_map, &$trans_loaded, &$NS_DISPLAY) {
                            $neg = $m[1];
                            $ns = $m[2];
                            $raw_name = $m[3] !== '' ? $m[3] : $m[4];
                            $name = substr($raw_name, -1) === '$' ? substr($raw_name, 0, -1) : $raw_name;
                            $lookup_ns = $ns_alias[$ns] ?? $ns;
                            $translated = _translate_ns_name($root, $ns_alias, $trans_ns_map, $trans_loaded, $ns, $name);
                            $label = $NS_DISPLAY[$ns] ?? ($NS_DISPLAY[$lookup_ns] ?? $ns);
                            $any = $translated !== null || $label !== $ns;
                            if (!$any) return $m[0];
                            return $neg . $label . ':' . ($translated ?? $name);
                        }, $t['query']);
                    }
                    $t['query_label'] = $query_label !== null ? (string)$query_label : '';
                    if (!empty($t['origin_kind']) && $t['origin_kind'] === 'favorite_artist' && !empty($t['origin_artist'])) {
                        $cn = _translate_ns_name($root, $ns_alias, $trans_ns_map, $trans_loaded, 'artist', $t['origin_artist']);
                        $t['origin_artist_cn'] = $cn !== null ? (string)$cn : '';
                        if (empty($t['query_label'])) {
                            $label = $cn !== null ? ('作者:' . $cn) : ('作者:' . $t['origin_artist']);
                            $t['query_label'] = $label;
                        }
                    } else {
                        $t['origin_artist_cn'] = '';
                    }
                    if (empty($t['name']) && !empty($t['query_label'])) {
                        $t['name'] = $t['query_label'];
                    }
                }
                unset($t);

                json_exit([
                    'targets' => $targets,
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => $total,
                    'total_pages' => $total_pages,
                ]);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        case 'toggle_refresh_target':
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            $enabled = !empty($_POST['enabled']) ? 1 : 0;
            if (!$id) error_exit('id required');
            try {
                $pdo = _pdo();
                $stmt = $pdo->prepare('UPDATE refresh_targets SET enabled=? WHERE id=?');
                $stmt->execute([$enabled, $id]);
                if ($stmt->rowCount() < 1) error_exit('Refresh target not found or unchanged');
                json_exit(['message' => $enabled ? '已开启定期刷新' : '已关闭定期刷新']);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        case 'parse_cookie_string':
            $cookie_string = $_POST['cookie_string'] ?? $_GET['cookie_string'] ?? '';
            if (!$cookie_string) error_exit('cookie_string required');
            $pairs = explode(';', $cookie_string);
            $result = [];
            foreach ($pairs as $pair) {
                $pair = trim($pair);
                if (strpos($pair, '=') === false) continue;
                [$key, $value] = explode('=', $pair, 2);
                $result[trim($key)] = trim($value);
            }
            $known = ['ipb_member_id', 'ipb_pass_hash', 'cf_clearance', 'sk', 'star', 'hath_perks', 'igneous'];
            $extracted = [];
            foreach ($known as $k) {
                if (isset($result[$k]) && $result[$k] !== '') {
                    $extracted[$k] = $result[$k];
                }
            }
            json_exit(['parsed' => $extracted, 'all' => $result]);
            break;

        // ————— 排除标签黑名单（tag_blacklist 表：tag_type + tag_value 联合唯一）—————
        case 'list_blacklist_tags':
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) json_exit(['tags' => []]);
            try {
                $pdo = _pdo($db_path);
                $pdo->exec("CREATE TABLE IF NOT EXISTS tag_blacklist (id INTEGER PRIMARY KEY AUTOINCREMENT, tag_type TEXT NOT NULL DEFAULT '', tag_value TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT '', UNIQUE(tag_type, tag_value))");
                $stmt = $pdo->query("SELECT id, tag_type, tag_value, created_at FROM tag_blacklist ORDER BY tag_type, tag_value");
                $tags = $stmt ? $stmt->fetchAll() : [];
                json_exit(['tags' => $tags]);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        case 'add_blacklist_tag':
            $tag_type = trim($_POST['tag_type'] ?? ($_GET['tag_type'] ?? ''));
            $tag_value = trim($_POST['tag_value'] ?? ($_GET['tag_value'] ?? ''));
            if ($tag_value === '') error_exit('tag_value 不能为空');
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $pdo->exec("CREATE TABLE IF NOT EXISTS tag_blacklist (id INTEGER PRIMARY KEY AUTOINCREMENT, tag_type TEXT NOT NULL DEFAULT '', tag_value TEXT NOT NULL, created_at TEXT NOT NULL DEFAULT '', UNIQUE(tag_type, tag_value))");
                $stmt = $pdo->prepare("INSERT OR IGNORE INTO tag_blacklist (tag_type, tag_value, created_at) VALUES (?, ?, datetime('now','localtime'))");
                $stmt->execute([$tag_type, $tag_value]);
                // ——— 精确类（artist/group/author/*）同步写入 excluded_participants，保证翻页 O(1) NOT IN 立刻生效
                if ($tag_type === '' || $tag_type === '*' || $tag_type === 'artist' || $tag_type === 'author') {
                    $s = $pdo->prepare("INSERT OR IGNORE INTO excluded_participants (kind, name, reason) VALUES ('artist', ?, 'added via tag_blacklist')");
                    $s->execute([$tag_value]);
                } elseif ($tag_type === 'group') {
                    $s = $pdo->prepare("INSERT OR IGNORE INTO excluded_participants (kind, name, reason) VALUES ('group', ?, 'added via tag_blacklist')");
                    $s->execute([$tag_value]);
                }
                json_exit(['message' => '已加入排除黑名单（精确类已同步到 O(1) 排除表）']);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        case 'delete_blacklist_tag':
            $id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
            if ($id <= 0) error_exit('id 非法');
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                // 先取出被删条目的 tag_type/tag_value，同步清理 excluded_participants 里的对应记录
                $stmt = $pdo->prepare("SELECT tag_type, tag_value FROM tag_blacklist WHERE id=?");
                $stmt->execute([$id]);
                $row = $stmt->fetch();
                $stmt2 = $pdo->prepare("DELETE FROM tag_blacklist WHERE id=?");
                $stmt2->execute([$id]);
                if ($stmt2->rowCount() < 1) error_exit('黑名单条目不存在');
                if ($row) {
                    $tt = trim($row['tag_type'] ?? '');
                    $tv = trim($row['tag_value'] ?? '');
                    if ($tv !== '') {
                        if ($tt === '' || $tt === '*' || $tt === 'artist' || $tt === 'author') {
                            $pdo->prepare("DELETE FROM excluded_participants WHERE kind='artist' AND name=?")->execute([$tv]);
                        } elseif ($tt === 'group') {
                            $pdo->prepare("DELETE FROM excluded_participants WHERE kind='group' AND name=?")->execute([$tv]);
                        }
                    }
                }
                json_exit(['message' => '已移除']);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        // ————— 精确排除表的直接管理接口（O(1) 加/删）—————
        case 'add_excluded_sid':
            $source = trim($_POST['source'] ?? ($_GET['source'] ?? 'exhentai'));
            $source_id = trim($_POST['source_id'] ?? ($_GET['source_id'] ?? ''));
            $reason = trim($_POST['reason'] ?? ($_GET['reason'] ?? 'manual'));
            if ($source_id === '') error_exit('source_id 不能为空');
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $stmt = $pdo->prepare("INSERT OR IGNORE INTO excluded_sids (source, source_id, reason) VALUES (?,?,?)");
                $stmt->execute([$source, $source_id, $reason]);
                // 顺带清掉本地 galleries 记录（根除"删了又从本地出来"循环）
                $pdo->prepare("DELETE FROM galleries WHERE source=? AND source_id=?")->execute([$source, $source_id]);
                json_exit(['ok' => true, 'message' => '已加入单本排除']);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        case 'remove_excluded_sid':
            $source = trim($_POST['source'] ?? ($_GET['source'] ?? 'exhentai'));
            $source_id = trim($_POST['source_id'] ?? ($_GET['source_id'] ?? ''));
            if ($source_id === '') error_exit('source_id 不能为空');
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = _pdo($db_path);
                $stmt = $pdo->prepare("DELETE FROM excluded_sids WHERE source=? AND source_id=?");
                $stmt->execute([$source, $source_id]);
                json_exit(['ok' => true, 'removed' => $stmt->rowCount()]);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        case 'list_excluded_sids':
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) json_exit(['rows' => []]);
            try {
                $pdo = _pdo($db_path);
                $limit = max(1, min(200, (int)($_GET['limit'] ?? 100)));
                $rows = $pdo->query("SELECT source, source_id, reason, created_at FROM excluded_sids ORDER BY created_at DESC LIMIT $limit")->fetchAll();
                $cnt  = (int)$pdo->query("SELECT COUNT(*) FROM excluded_sids")->fetchColumn();
                $p_cnt = (int)$pdo->query("SELECT COUNT(*) FROM excluded_participants")->fetchColumn();
                json_exit(['rows' => $rows, 'total_excluded_sids' => $cnt, 'total_excluded_participants' => $p_cnt]);
            } catch (Exception $e) { error_exit($e->getMessage()); }
            break;

        case 'save_search_preset':
            $name = trim($_POST['name'] ?? '');
            $keyword = trim($_POST['keyword'] ?? '');
            $categories_raw = $_POST['categories'] ?? '';
            $languages_raw = $_POST['languages'] ?? '';
            $force = !empty($_POST['force']);
            if (!$name) error_exit('名称不能为空');
            if ($keyword !== '' && ($name === $keyword || $name === '未命名')) {
                $name = translate_search_preset_name($keyword);
            }
            $categories = '';
            if ($categories_raw !== '') {
                $parsed = json_decode($categories_raw, true);
                if (is_array($parsed)) {
                    $categories = implode(',', $parsed);
                } else {
                    $categories = $categories_raw;
                }
            }
            $languages = '';
            if ($languages_raw !== '') {
                $parsed = json_decode($languages_raw, true);
                if (is_array($parsed)) {
                    $languages = implode(',', $parsed);
                } else {
                    $languages = $languages_raw;
                }
            }
            $db_path = $root . '/data/ehlib.db';
            try {
                $pdo = _pdo($db_path);
                $pdo->exec("CREATE TABLE IF NOT EXISTS search_presets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    keyword TEXT DEFAULT '',
                    categories TEXT DEFAULT '',
                    languages TEXT DEFAULT NULL,
                    force_crawl INTEGER DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT ''
                )");
                try { $pdo->exec("ALTER TABLE search_presets ADD COLUMN languages TEXT DEFAULT NULL"); } catch (Exception $e) {}
                $stmt = $pdo->prepare('INSERT OR REPLACE INTO search_presets (name, keyword, categories, languages, force_crawl, created_at) VALUES (?, ?, ?, ?, ?, datetime(\'now\', \'localtime\'))');
                $stmt->execute([$name, $keyword, $categories, $languages, $force ? 1 : 0]);
                json_exit(['message' => '已保存']);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'list_search_presets':
            $db_path = $root . '/data/ehlib.db';
            try {
                $pdo = _pdo($db_path);
                try { $pdo->exec("ALTER TABLE search_presets ADD COLUMN languages TEXT DEFAULT NULL"); } catch (Exception $e) {}
                $stmt = $pdo->query("SELECT id, name, keyword, categories, languages, force_crawl, created_at FROM search_presets ORDER BY created_at DESC");
                $presets = $stmt->fetchAll();
                json_exit(['presets' => $presets]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'delete_search_preset':
            $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
            if (!$id) error_exit('id required');
            $db_path = $root . '/data/ehlib.db';
            try {
                $pdo = _pdo($db_path);
                $stmt = $pdo->prepare('DELETE FROM search_presets WHERE id=?');
                $stmt->execute([$id]);
                json_exit(['message' => '已删除']);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'clear_log':
            $data_dir = $root . '/data';
            // check for running tasks
            $running_tasks = [];
            $pid_patterns = ['*_pid_*.txt', '*.pid'];
            foreach ($pid_patterns as $pp) {
                foreach (glob($data_dir . '/' . $pp) as $f) {
                    $pid = trim(@file_get_contents($f));
                    if (!$pid) continue;
                    $task = basename($f);
                    if (DIRECTORY_SEPARATOR === '\\') {
                        $out = [];
                        exec('tasklist /FI "PID eq ' . (int)$pid . '" /NH 2>nul', $out);
                        if (count($out) > 1) $running_tasks[] = $task;
                    } else {
                        if (is_dir('/proc/' . $pid)) $running_tasks[] = $task;
                    }
                }
            }
            if (!empty($running_tasks)) {
                json_exit(['error' => '有任务正在运行，无法清理: ' . implode(', ', $running_tasks)], false);
                break;
            }
            // clean working files (keep ehlib.db and thumbs/)
            $cleared = 0;
            $patterns = ['bg_*.log', 'bg_*.sh', '*.pid', '*_pid_*.txt', '*.flag', '*.lock', '*_progress_*.json', 'progress/*.json', 'verify_*.json', 'crawl_*.json', 'retry_*.json', 'download.lock'];
            foreach ($patterns as $pattern) {
                foreach (glob($data_dir . '/' . $pattern) as $f) {
                    if (is_file($f) && @unlink($f)) $cleared++;
                }
            }
            json_exit(['message' => "已清理 $cleared 个工作文件"]);
            break;

        case 'list_cached':
            $source = $_POST['source'] ?? $_GET['source'] ?? 'exhentai';
            $q = $_POST['q'] ?? $_GET['q'] ?? '';
            $db_path = $root . '/data/ehlib.db';
            try {
                $pdo = _pdo($db_path);
                if ($q !== '') {
                    $like = '%' . $q . '%';
                    $stmt = $pdo->prepare("SELECT source_id, COALESCE(NULLIF(title,''), NULLIF(title_jp,'')) AS title, artist FROM search_cache WHERE source=? AND (title LIKE ? OR title_jp LIKE ? OR artist LIKE ? OR source_id LIKE ?) ORDER BY uploaded_at DESC, source_id DESC LIMIT 50");
                    $stmt->execute([$source, $like, $like, $like, $like]);
                } else {
                    $stmt = $pdo->prepare("SELECT source_id, COALESCE(NULLIF(title,''), NULLIF(title_jp,'')) AS title, artist FROM search_cache WHERE source=? ORDER BY uploaded_at DESC, source_id DESC LIMIT 200");
                    $stmt->execute([$source]);
                }
                json_exit(['galleries' => $stmt->fetchAll()]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'verify_single':
            $source = $_POST['source'] ?? 'exhentai';
            $sid = $_POST['source_id'] ?? '';
            if (!$sid) error_exit('source_id required');
            $result = run_python(['verify-single', $source, $sid], 60);
            $result_file = $root . '/data/verify_single_result.json';
            if (is_file($result_file)) {
                $data = json_decode(file_get_contents($result_file), true);
                @unlink($result_file);
                json_exit($data ?: ['error' => 'parse failed']);
            } else {
                json_exit([
                    'error' => ($result['stderr'] ?: $result['stdout'] ?: 'unknown error'),
                    'exit_code' => $result['exit_code'] ?? -1,
                ], false);
            }
            break;

        default:
            error_exit('Unknown action: ' . $action);
    }
} catch (Exception $e) {
    error_exit($e->getMessage());
}
