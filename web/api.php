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
    if (str_starts_with($path, DIRECTORY_SEPARATOR)) {
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
    if (preg_match('/^[A-Za-z]:[\\\\\/]/', $download_path) || str_starts_with($download_path, '/') || str_starts_with($download_path, '\\')) {
        return normalize_path($download_path);
    }
    return normalize_path($root . DIRECTORY_SEPARATOR . $download_path);
}

function is_path_within($child, $parent) {
    $child = rtrim(strtolower(normalize_path($child)), DIRECTORY_SEPARATOR);
    $parent = rtrim(strtolower(normalize_path($parent)), DIRECTORY_SEPARATOR);
    return $child === $parent || str_starts_with($child, $parent . DIRECTORY_SEPARATOR);
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
        if (str_contains($part, '/') || str_contains($part, '\\') || $part === '.' || $part === '..') return '';
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
        foreach ($exts as $ext) {
            $candidate = $local_path . DIRECTORY_SEPARATOR . 'cover.' . $ext;
            if (is_file($candidate)) return $candidate;
        }
        foreach (['001', '1'] as $name) {
            foreach ($exts as $ext) {
                $candidate = $local_path . DIRECTORY_SEPARATOR . $name . '.' . $ext;
                if (is_file($candidate)) return $candidate;
            }
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

    while (!$done) {
        if (time() - $start > $timeout) {
            @proc_terminate($proc, 9);
            @fclose($pipes[1]);
            @fclose($pipes[2]);
            @proc_close($proc);
            return ['ok' => false, 'error' => 'Command timed out (' . $timeout . 's)'];
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

function run_python_locked($args, $timeout = 120) {
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
        return run_python($args, $timeout);
    } finally {
        @flock($lock, LOCK_UN);
        @fclose($lock);
    }
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

// --- Route actions ---
try {
    switch ($action) {
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
            if ($tag_name !== '' && !$tag_names) $tag_names[] = $tag_name;
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = new PDO('sqlite:' . $db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $params = [];
                $has_tags = !empty($tag_names);
                $from = $has_tags ? 'galleries g' : 'galleries';
                $joins = '';
                $prefix = $has_tags ? 'g.' : '';
                $where = 'WHERE 1=1';

                if ($has_tags) {
                    $joins = ' JOIN gallery_tags gt ON g.id = gt.gallery_id JOIN tags t ON gt.tag_id = t.id';
                    $where = 'WHERE (' . implode(' OR ', array_fill(0, count($tag_names), 't.name LIKE ?')) . ')';
                    foreach ($tag_names as $tag) $params[] = '%' . $tag . '%';
                }
                if ($source) { $where .= ' AND ' . $prefix . 'source=?'; $params[] = $source; }
                if ($artist) { $where .= ' AND ' . $prefix . 'artist LIKE ?'; $params[] = '%' . $artist . '%'; }
                if ($language) { $where .= ' AND ' . $prefix . 'language=?'; $params[] = $language; }

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
                $select_cols = $has_tags ? 'g.*' : '*';
                $order_col = $prefix . 'uploaded_at';
                $order_id_col = $prefix . 'source_id';
                $data_query = "SELECT $select_cols FROM $from $joins $where $group_having ORDER BY COALESCE(NULLIF($order_col, '') , '0000-00-00') DESC, CAST(SUBSTR($order_id_col || '/', 1, INSTR($order_id_col || '/', '/') - 1) AS INTEGER) DESC LIMIT $per_page OFFSET $offset";
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
                $pdo = new PDO('sqlite:' . $db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare('SELECT id, title, title_jp, artist, group_name, language, category, total_pages, uploaded_at, file_size, local_path, downloaded_at FROM galleries WHERE source=? AND source_id=?');
                $stmt->execute([$source, $source_id]);
                $gallery = $stmt->fetch();
                if (!$gallery) error_exit('Gallery not found');
                $stmt2 = $pdo->prepare(
                    'SELECT t.type, t.name FROM tags t
                     JOIN gallery_tags gt ON t.id = gt.tag_id
                     JOIN galleries g ON gt.gallery_id = g.id
                     WHERE g.source=? AND g.source_id=?
                     ORDER BY t.type, t.name'
                );
                $stmt2->execute([$source, $source_id]);
                $tags = $stmt2->fetchAll();
                $gallery['tags'] = $tags;
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
                $pdo = new PDO('sqlite:' . $db_path);
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
                $pdo = new PDO('sqlite:' . $db_path);
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
                $pdo = new PDO('sqlite:' . $db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->exec('PRAGMA foreign_keys = ON');

                $stmt = $pdo->prepare('SELECT id, title, local_path FROM galleries WHERE source=? AND source_id=?');
                $stmt->execute([$source, $source_id]);
                $gallery = $stmt->fetch();
                if (!$gallery) error_exit('Gallery not found');

                $download_base = resolve_download_path();
                $local_path = trim((string)($gallery['local_path'] ?? ''));
                if ($local_path !== '') {
                    $normalized_local = normalize_path($local_path);
                    if (!is_path_within($normalized_local, $download_base)) {
                        error_exit('Refusing to delete path outside download directory');
                    }
                    if (file_exists($normalized_local) && !delete_dir_recursive($normalized_local)) {
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
                $pdo = new PDO('sqlite:' . $db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare('SELECT local_path FROM galleries WHERE source=? AND source_id=?');
                $stmt->execute([$source, $source_id]);
                $row = $stmt->fetch();
                if (!$row || empty($row['local_path'])) error_exit('Gallery path not found');
                $local_path = normalize_path($row['local_path']);
                $download_base = resolve_download_path();
                if (!is_path_within($local_path, $download_base)) error_exit('Path outside download directory');
                $img_path = '';
                if ($page === 'cover') {
                    foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                        $candidate = $local_path . DIRECTORY_SEPARATOR . 'cover.' . $ext;
                        if (is_file($candidate)) { $img_path = $candidate; break; }
                    }
                    if (!$img_path) {
                        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                            $candidate = $local_path . DIRECTORY_SEPARATOR . '001.' . $ext;
                            if (is_file($candidate)) { $img_path = $candidate; break; }
                        }
                    }
                    if (!$img_path) {
                        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                            $candidate = $local_path . DIRECTORY_SEPARATOR . '1.' . $ext;
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
                $pdo = new PDO('sqlite:' . $db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
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
                $pdo = new PDO('sqlite:' . $db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $stmt = $pdo->prepare('SELECT thumb_path FROM search_cache WHERE source=? AND source_id=?');
                $stmt->execute([$source, $source_id]);
                $row = $stmt->fetch();
                if (!$row || empty($row['thumb_path'])) error_exit('Thumb not found');
                $thumb_path = $row['thumb_path'];
                if (!is_file($thumb_path)) error_exit('Thumb file not found');
                $ext = strtolower(pathinfo($thumb_path, PATHINFO_EXTENSION));
                $mime = ['jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png','gif'=>'image/gif','webp'=>'image/webp'];
                header('Content-Type: ' . ($mime[$ext] ?? 'image/webp'));
                header('Cache-Control: max-age=86400');
                readfile($thumb_path);
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
                $pdo = new PDO('sqlite:' . $db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

                $params = [];
                $where = 'WHERE 1=1';
                if ($source) { $where .= ' AND sc.source=?'; $params[] = $source; }
                if ($artist) { $where .= ' AND sc.artist LIKE ?'; $params[] = '%' . $artist . '%'; }
                if ($title) { $where .= ' AND (sc.title LIKE ? OR sc.title_jp LIKE ? OR sc.artist LIKE ?)'; $params[] = '%' . $title . '%'; $params[] = '%' . $title . '%'; $params[] = '%' . $title . '%'; }
                if ($category) { $where .= ' AND sc.category=?'; $params[] = $category; }
                if (!empty($categories)) {
                    $where .= ' AND sc.category IN (' . implode(',', array_fill(0, count($categories), '?')) . ')';
                    $params = array_merge($params, $categories);
                }

                // Count
                $count_query = "SELECT COUNT(*) FROM search_cache sc $where";
                $count_stmt = $pdo->prepare($count_query);
                $count_stmt->execute($params);
                $total = (int)$count_stmt->fetchColumn();

                // Data
                $data_query = "SELECT sc.*, CASE WHEN g.id IS NOT NULL THEN 1 ELSE 0 END as is_local FROM search_cache sc LEFT JOIN galleries g ON g.source = sc.source AND g.source_id = sc.source_id $where ORDER BY COALESCE(NULLIF(sc.uploaded_at, ''), '0000-00-00') DESC, sc.crawled_at DESC LIMIT $per_page OFFSET $offset";
                $stmt = $pdo->prepare($data_query);
                $stmt->execute($params);
                $rows = $stmt->fetchAll();

                $results = [];
                foreach ($rows as $row) {
                    $thumb_url = '';
                    $thumb_path = $row['thumb_path'] ?? '';
                    $source_id = $row['source_id'] ?? '';
                    if ($thumb_path && is_file($thumb_path)) {
                        $thumb_url = 'api.php?action=serve_cache_thumb&source=' . urlencode($source) . '&source_id=' . urlencode($source_id);
                    }
                    $results[] = [
                        'source' => $row['source'] ?? '',
                        'source_id' => $row['source_id'] ?? '',
                        'title' => $row['title'] ?? '',
                        'category' => $row['category'] ?? '',
                        'total_pages' => (int)($row['total_pages'] ?? 0),
                        'artist' => $row['artist'] ?? '',
                        'uploaded_at' => $row['uploaded_at'] ?? '',
                        'is_local' => (int)($row['is_local'] ?? 0) === 1,
                        'thumb_url' => $thumb_url,
                        'searched_at' => $row['searched_at'] ?? '',
                    ];
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
                $pdo = new PDO('sqlite:' . $db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_COLUMN);
                $stmt = $pdo->prepare("SELECT DISTINCT artist FROM search_cache WHERE source=? AND artist!='' ORDER BY artist");
                $stmt->execute([$source]);
                $artists = $stmt->fetchAll();
                json_exit(['artists' => $artists]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'cache_categories':
            $source = $_GET['source'] ?? 'exhentai';
            $db_path = $root . '/data/ehlib.db';
            if (!is_file($db_path)) error_exit('Database not found');
            try {
                $pdo = new PDO('sqlite:' . $db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_COLUMN);
                $stmt = $pdo->prepare("SELECT DISTINCT category FROM search_cache WHERE source=? AND category!='' ORDER BY category");
                $stmt->execute([$source]);
                $categories = $stmt->fetchAll();
                json_exit(['categories' => $categories]);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'crawl':
            $source = $_POST['source'] ?? 'exhentai';
            $query = $_POST['query'] ?? '';
            $force = !empty($_POST['force']);
            $categories = $_POST['categories'] ?? '';
            if (!$query) error_exit('Query required');
            // 检查是否已有爬取进程在运行
            $pid_file = $root . '/data/crawl_pid_' . $source . '.txt';
            if (is_file($pid_file)) {
                $old_pid = trim(file_get_contents($pid_file));
                if ($old_pid && is_dir('/proc/' . $old_pid)) {
                    error_exit('已有爬取任务在运行 (PID: ' . $old_pid . ')，请先终止或等待完成');
                }
            }
            $args = ['crawl', $source, '--query', $query];
            if ($force) $args[] = '--force';
            // convert category names to ExHentai bitmask
            $cat_map = ['Misc'=>1,'Doujinshi'=>2,'Manga'=>4,'Artist CG'=>8,'Game CG'=>16,'Image Set'=>32,'Cosplay'=>64,'Asian Porn'=>128,'Non-H'=>256,'Western'=>512];
            if ($categories !== '') {
                $args[] = '--categories';
                if ($categories === 'all') {
                    $args[] = (string)array_sum(array_values($cat_map));
                } else {
                    foreach (explode(',', $categories) as $c) {
                        $c = trim($c);
                        if (isset($cat_map[$c])) $args[] = (string)$cat_map[$c];
                    }
                }
            }
            $pid = run_python_background($args, $pid_file);
            json_exit(['output' => '爬取任务已在后台启动 (PID: ' . $pid . ')'], true);
            break;

        case 'stop_crawl':
            $source = $_POST['source'] ?? $_GET['source'] ?? 'exhentai';
            $pid_file = $root . '/data/crawl_pid_' . $source . '.txt';
            $cancel_file = $root . '/data/crawl_cancel_' . $source . '.flag';
            // 先写取消标记（Python 端会检测）
            file_put_contents($cancel_file, '1');
            // 再杀进程
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
            // 清理进度文件
            $progress_dir = $root . '/data/progress';
            foreach (glob($progress_dir . '/crawl__crawl_' . $source . '*.json') as $f) {
                @unlink($f);
            }
            json_exit(['message' => '爬取任务已终止'], true);
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
            $output = $root . '/data/export_' . date('Ymd_His') . '.json';
            if (!is_dir($root . '/data')) mkdir($root . '/data', 0755, true);
            $args = ['export', '--output', $output];
            $result = run_python($args);
            if ($result['ok'] && is_file($output)) {
                $content = json_decode(file_get_contents($output), true);
                json_exit(['galleries' => $content, 'file' => basename($output)]);
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
            if ($db_exists) {
                try {
                    $pdo = new PDO('sqlite:' . $db_path);
                    $stmt = $pdo->query('SELECT COUNT(*) as cnt FROM galleries');
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    $count = (int)$row['cnt'];
                } catch (Exception $e) {}
            }
            json_exit([
                'db_exists' => $db_exists,
                'gallery_count' => $count,
                'db_size' => $db_exists ? filesize($db_path) : 0,
                'config_file' => is_file($root . '/config.yaml'),
                'venv_exists' => is_dir($root . '/venv'),
                'download_path' => $config['download']['path'] ?? './downloads',
            ]);
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

        case 'save_search_preset':
            $name = $_POST['name'] ?? '';
            $keyword = $_POST['keyword'] ?? '';
            $categories_raw = $_POST['categories'] ?? '';
            $force = !empty($_POST['force']);
            if (!$name) error_exit('名称不能为空');
            $categories = '';
            if ($categories_raw !== '') {
                $parsed = json_decode($categories_raw, true);
                if (is_array($parsed)) {
                    $categories = implode(',', $parsed);
                } else {
                    $categories = $categories_raw;
                }
            }
            $db_path = $root . '/data/ehlib.db';
            try {
                $pdo = new PDO('sqlite:' . $db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $pdo->exec("CREATE TABLE IF NOT EXISTS search_presets (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL UNIQUE,
                    keyword TEXT DEFAULT '',
                    categories TEXT DEFAULT '',
                    force_crawl INTEGER DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT ''
                )");
                $stmt = $pdo->prepare('INSERT OR REPLACE INTO search_presets (name, keyword, categories, force_crawl, created_at) VALUES (?, ?, ?, ?, datetime(\'now\', \'localtime\'))');
                $stmt->execute([$name, $keyword, $categories, $force ? 1 : 0]);
                json_exit(['message' => '已保存']);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        case 'list_search_presets':
            $db_path = $root . '/data/ehlib.db';
            try {
                $pdo = new PDO('sqlite:' . $db_path);
                $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
                $stmt = $pdo->query("SELECT id, name, keyword, categories, force_crawl, created_at FROM search_presets ORDER BY created_at DESC");
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
                $pdo = new PDO('sqlite:' . $db_path);
                $stmt = $pdo->prepare('DELETE FROM search_presets WHERE id=?');
                $stmt->execute([$id]);
                json_exit(['message' => '已删除']);
            } catch (Exception $e) {
                error_exit($e->getMessage());
            }
            break;

        default:
            error_exit('Unknown action: ' . $action);
    }
} catch (Exception $e) {
    error_exit($e->getMessage());
}
