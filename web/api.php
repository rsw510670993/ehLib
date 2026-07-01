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
    
    function array_to_yaml($data, $indent = 0) {
        $out = '';
        $prefix = str_repeat('  ', $indent);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if (empty($value)) {
                    $out .= $prefix . $key . ": {}\n";
                } else {
                    $out .= $prefix . $key . ":\n";
                    $out .= array_to_yaml($value, $indent + 1);
                }
            } elseif (is_bool($value)) {
                $out .= $prefix . $key . ': ' . ($value ? 'true' : 'false') . "\n";
            } elseif (is_numeric($value)) {
                $out .= $prefix . $key . ': ' . $value . "\n";
            } else {
                $out .= $prefix . $key . ': "' . str_replace('"', '\"', $value) . "\"\n";
            }
        }
        return $out;
    }
    
    $yaml = array_to_yaml($data);
    file_put_contents($path, $yaml, LOCK_EX);
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
            $limit = (int)($_GET['limit'] ?? 50);
            $args = ['list'];
            if ($source) { $args[] = '--source'; $args[] = $source; }
            if ($tag_name) { $args[] = '--tag'; $args[] = $tag_name; }
            if ($tags_raw) { $args[] = '--tags'; $args[] = $tags_raw; }
            if ($tag_mode !== 'any') { $args[] = '--tag-mode'; $args[] = $tag_mode; }
            if ($artist) { $args[] = '--artist'; $args[] = $artist; }
            if ($language) { $args[] = '--language'; $args[] = $language; }
            if ($limit !== 50) { $args[] = '--limit'; $args[] = (string)$limit; }
            $result = run_python($args);
            if (!$result['ok']) error_exit($result['stderr'] ?: 'Command failed');
            $lines = array_filter(explode("\n", $result['stdout']));
            $galleries = [];
            foreach ($lines as $line) {
                if (preg_match('/^\[(.+?)\/(.+?)\]\s+(.+?)\s+\|\s*(.*?)\s*\((\d+)p\)\s+-\s+(.*)$/', $line, $m)) {
                    $galleries[] = [
                        'source' => $m[1],
                        'source_id' => $m[2],
                        'title' => $m[3],
                        'title_jp' => $m[4],
                        'pages' => (int)$m[5],
                        'downloaded_at' => $m[6],
                    ];
                }
            }
            json_exit(['galleries' => $galleries]);
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
                $stmt = $pdo->prepare('SELECT id, title, title_jp, artist, group_name, language, category, total_pages, file_size, local_path, downloaded_at FROM galleries WHERE source=? AND source_id=?');
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
                if ($age > 300) {
                    @unlink($f);
                    continue;
                }
                $tasks[] = $data;
            }
            usort($tasks, function ($a, $b) {
                return ($b['updated_at'] ?? 0) - ($a['updated_at'] ?? 0);
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
                            $images[] = ['page' => $i, 'file' => sprintf('%03d', $i) . '.' . $ext];
                            $found = true;
                            break;
                        }
                    }
                    if (!$found) {
                        foreach (['jpg', 'jpeg', 'png', 'gif', 'webp'] as $ext) {
                            $candidate = $local_path . DIRECTORY_SEPARATOR . $i . '.' . $ext;
                            if (is_file($candidate)) {
                                $images[] = ['page' => $i, 'file' => $i . '.' . $ext];
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
            $result = run_python($args, 900);
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
            $result = run_python($args, 600);
            @unlink($tmpfile);
            json_exit([
                'output' => $result['stdout'] ?: $result['stderr'],
                'exit_code' => $result['exit_code'],
            ], $result['ok']);
            break;

        case 'retry':
            $args = ['retry'];
            $result = run_python($args, 600);
            json_exit([
                'output' => $result['stdout'] ?: $result['stderr'],
                'exit_code' => $result['exit_code'],
            ], $result['ok']);
            break;

        case 'search':
            $source = $_POST['source'] ?? 'nhentai';
            $query = $_POST['query'] ?? '';
            if (!$query) error_exit('Search query required');
            $args = ['search', $source, '--query', $query];
            $result = run_python($args, 60);
            if (!$result['ok']) error_exit($result['stderr'] ?: 'Search failed');
            $lines = array_filter(explode("\n", $result['stdout']));
            $results = [];
            foreach ($lines as $line) {
                if (preg_match('/^\[(.+?)\]\s+(.+)$/', $line, $m)) {
                    $results[] = [
                        'id' => $m[1],
                        'title' => $m[2],
                    ];
                }
            }
            json_exit(['results' => $results, 'raw' => $result['stdout']], true);
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

        default:
            error_exit('Unknown action: ' . $action);
    }
} catch (Exception $e) {
    error_exit($e->getMessage());
}
