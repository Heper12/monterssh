<?php

$home_dir = getenv('HOME');

$config_file = __DIR__ . '/data.json';
$pids = [];
$active_mount_points = [];
$just_mounted = [];
$just_added = null;

define('VERSION', '2.3.0-macOS');
define('RELEASE_DATE', '2026-11-26');
define('MOUNT_DIR', '/Volumes/');

function load_config()
{
    global $config_file;
    if (!file_exists($config_file)) {
        file_put_contents($config_file, "[]\n");
    }
    $config = file_get_contents($config_file);
    if ($config === false) fatal_error('Failed to read file', $config_file);
    $array = json_decode($config, true);
    if (!is_array($array)) fatal_error('Invalid JSON configuration', json_last_error_msg());
    return array_values($array);
}

function save_config()
{
    global $db, $config_file;
    file_put_contents($config_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function readln($prompt = null, $reset = false)
{
    if ($prompt) echo $prompt;
    $input = fgets(STDIN);
    if ($input === false) return false;
    $line = rtrim($input, "\r\n");
    if ($reset) reset_line(1);
    return $line;
}

function cc($format, $text = '')
{
    if (!empty($GLOBALS['cron'])) return $text;
    if (is_numeric($format)) return "\e[38;5;".$format.'m'.$text."\e[0m";
    if (!is_array($format)) $format = explode(' ', $format);
    $codes = [
        'bold' => 1, 'italic' => 3, 'underline' => 4, 'strikethrough' => 9, 'default' => 39,
        'black' => 30, 'red' => 31, 'green' => 32, 'yellow' => 33, 'blue' => 34,
        'magenta' => 35, 'cyan' => 36, 'white' => 37, 'darkgrey' => 90, 'lightred' => 91,
        'lightgreen' => 92, 'lightyellow' => 93, 'lightmagenta' => 95, 'lightcyan' => 96,
        'white' => 97, 'blackbg' => 40, 'redbg' => 41, 'greenbg' => 42, 'yellowbg' => 44,
        'bluebg' => 44, 'magentabg' => 45, 'cyanbg' => 46, 'lightgreybg' => 47
    ];
    $formatMap = array_map(function ($code) use ($codes) {
        if (is_numeric($code)) return '38;5;' . $code;
        if (preg_match('/^bg:(\d+)$/', $code, $m)) return '48;5;' . $m[1];
        if (!array_key_exists($code, $codes)) die("\e[38;5;196m" . "cc: Invalid code: [" . $code . "]\e[0m\n");
        return $codes[$code];
    }, $format);
    return "\e[".implode(';',$formatMap).'m'.$text."\e[0m";
}

function fatal_error($message, $info = '')
{
    printf("%s", cc(196, $message));
    if ($info) printf("%s %s\n", cc(196, ':'), $info); else echo "\n";
    exit();
}

function reset_line($n = 0)
{
    $cols_output = [];
    exec('tput cols 2>/dev/null', $cols_output);
    $cols = (int)($cols_output[0] ?? 80);
    if ($n) echo chr(27) . '[0G' . chr(27) . '[' . $n . 'A';
    printf("%s\r", str_repeat(' ', $cols));
}

function warning($message, $info = '')
{
    reset_line();
    printf("%s", cc(204, $message));
    if ($info) printf("%s %s\n", cc(204, ':'), $info); else echo "\n";
}

function normalize_mount_path($mount_point)
{
    $mount_point = trim((string)$mount_point);
    $mount_point = preg_replace('~^/volumes/~i', '/Volumes/', $mount_point);
    if (str_starts_with($mount_point, '/Volumes/')) {
        $relative = substr($mount_point, strlen('/Volumes/'));
    } else {
        if ($mount_point === '' || str_starts_with($mount_point, '/')) return false;
        $relative = $mount_point;
    }
    $parts = explode('/', trim($relative, '/'));
    if (!$parts || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) return false;
    return '/Volumes/' . implode('/', $parts);
}

function mounted_filesystems()
{
    exec('mount 2>/dev/null', $lines, $status);
    return $status === 0 ? $lines : [];
}

function mount_line_path($line)
{
    if (preg_match('~ on (/Volumes/.*?) \(~', $line, $matches)) return str_replace('\\040', ' ', $matches[1]);
    return null;
}

function is_mounted_macos($mount_point)
{
    $mount_point = normalize_mount_path($mount_point);
    if (!$mount_point) return false;
    foreach (mounted_filesystems() as $line) {
        if (mount_line_path($line) === $mount_point) return true;
    }
    return false;
}

function mounted_sshfs_paths()
{
    $paths = [];
    foreach (mounted_filesystems() as $line) {
        $path = mount_line_path($line);
        $sshfs_source = preg_match('~^[^ ]+:.+ on /Volumes/~', $line);
        $fuse_filesystem = preg_match('/(sshfs|osxfuse|macfuse)/i', $line);
        if ($path && $sshfs_source && $fuse_filesystem) $paths[] = $path;
    }
    return array_values(array_unique($paths));
}

function ensure_mount_dir($mount_point)
{
    global $home_dir;
    $identity = function_exists('posix_geteuid') && function_exists('posix_getpwuid')
        ? posix_getpwuid(posix_geteuid())
        : false;
    $username = is_array($identity) ? ($identity['name'] ?? '') : basename($home_dir ?: '');
    if ($username === '') {
        warning('Could not determine current user');
        return false;
    }
    $output = [];
    $command = 'sudo mkdir -p ' . escapeshellarg($mount_point)
        . ' && sudo chown ' . escapeshellarg($username . ':staff') . ' ' . escapeshellarg($mount_point);
    exec($command . ' 2>&1', $output, $status);
    if ($status !== 0 || !is_dir($mount_point)) {
        warning('Could not create mount directory', implode("\n", $output) ?: $mount_point);
        return false;
    }
    return true;
}

function identity_file_for_key($key_name)
{
    global $home_dir;
    $key_name = trim((string)$key_name);
    if (str_ends_with($key_name, '.pub')) $key_name = substr($key_name, 0, -4);
    return str_starts_with($key_name, '/') ? $key_name : rtrim($home_dir, '/') . '/.ssh/' . $key_name;
}

function unmount_path($mount_point)
{
    $mount_point = normalize_mount_path($mount_point);
    if (!$mount_point || !is_mounted_macos($mount_point)) return true;
    $output = [];
    exec('sudo diskutil unmount force ' . escapeshellarg($mount_point) . ' 2>&1', $output, $status);
    if ($status === 0 && !is_mounted_macos($mount_point)) return true;
    $fallback = [];
    exec('sudo umount ' . escapeshellarg($mount_point) . ' 2>&1', $fallback, $fallback_status);
    if ($fallback_status === 0 && !is_mounted_macos($mount_point)) return true;
    warning('Could not unmount', implode("\n", array_filter(array_merge($output, $fallback))) ?: $mount_point);
    return false;
}

function print_list()
{
    global $db, $commands, $just_mounted, $just_added;
    $line_width = 40;

    if (!empty($db)) {
        pgrep_list();
        $i = 0;
        printf("%s\n", str_repeat('─', $line_width));
        foreach ($db as $key => $arr) {
            $mount_point = normalize_mount_path($arr['mount_point'] ?? '');
            if (!$mount_point) {
                printf(" % 2d. %s\n", ++$i, cc(210, 'Invalid mount point: ' . ($arr['mount_point'] ?? '')));
                continue;
            }
            $is_mounted = is_mounted_macos($mount_point);

            $default_cc = 245;
            $cc = (substr_count($arr['mount_point'], '/')) ? '220 bold underline' : '82 bold underline';
            if (!empty($just_mounted) && in_array($mount_point, $just_mounted))
                $cc = preg_replace('/(\d+)/', 'bg:$1 232', $cc);
            if (!empty($just_added) && $arr['mount_point'] == $just_added)
                $default_cc = 81;

            if (!$is_mounted) $cc = 210;
            $color = $is_mounted ? $cc : $default_cc;
            printf(" % 2d. %s\n", ++$i, cc($color, $arr['mount_point']));
        }
        printf("%s\n", str_repeat('─', $line_width));
    }
    $just_mounted = [];
}

function pgrep_list($show_info = false, $command_index = null)
{
    global $db, $pids, $active_mount_points;

    if (!empty($command_index)) {
        $entry_index = (int)$command_index - 1;
        if (!array_key_exists($entry_index, $db)) { warning('Invalid entry index', $command_index); return; }
        $info = $db[$entry_index];
        $maxlength = 0;
        printf("\nEntry %s: %s\n\n", cc('underline', '#' . $command_index), cc('bold 81', $info['mount_point']));
        foreach ($info as $key => $value) $maxlength = max($maxlength, strlen($key));
        foreach ($info as $key => $value) printf("  %s%s: %s\n", str_repeat(' ', $maxlength - strlen($key)), $key, cc(228, $value));
        print("\n");
        return;
    }

    $pids = [];
    $active_mount_points = mounted_sshfs_paths();
    if (!$show_info) return;
    if (empty($active_mount_points)) {
        printf("%s\n", cc('italic', 'No active SSHFS mount points'));
        return;
    }
    printf("\n%s\n\n", cc('italic', 'Active SSHFS mount points:'));
    foreach ($active_mount_points as $mount_point) printf("  %s\n", cc(220, $mount_point));
    print("\n");
}

function clear_line()
{
    $cols_output = [];
    exec('tput cols 2>/dev/null', $cols_output);
    $cols = (int)($cols_output[0] ?? 80);
    printf("\r%s\r", str_repeat(' ', $cols));
}

function print_version()
{
    printf("%s\n", cc('underline italic', 'https://github.com/Heper12/monterssh  '));
    printf("Version %s (%s)\n", cc('bold 226', VERSION), cc('italic 226', RELEASE_DATE));
}

function print_help() {
    global $commands;
    print("\nCommands:\n\n");
    $maxlength = 0;
    foreach ($commands as $cmd => $description)
        if (strlen($cmd) > $maxlength) $maxlength = strlen($cmd);
    foreach ($commands as $cmd => $description) {
        printf("  %s%s  %s\n", cc('underline 225', $cmd), str_repeat(' ', $maxlength - strlen($cmd)), $description);
    }
    printf("\n\n%s\n\n", cc('248 italic', 'Type command or number(s) of entries to mount:'));
}

function cli_table($table, $header = null, $options = [])
{
    $rows = 0; $max = []; $gap = '   ';
    if (!empty($header)) {
        $rows = count($header);
        foreach ($header as $col => $title) {
            if (empty($max[$col])) $max[$col] = 0;
            if (mb_strlen($title) > $max[$col]) $max[$col] = mb_strlen($title);
        }
    }
    foreach ($table as $key => $row) {
        if (count($row) > $rows) $rows = count($row);
        foreach ($row as $col => $cell) {
            if (empty($max[$col])) $max[$col] = 0;
            $strlen = mb_strlen($cell[0]);
            if ($strlen > $max[$col]) $max[$col] = $strlen;
            $table[$key][$col][3] = $strlen;
        }
    }
    if (!empty($header)) {
        echo ' ';
        foreach ($header as $col => $title) {
            $space = str_repeat(' ', $max[$col] - mb_strlen($title));
            print(cc('bold', $title) . $space) . $gap;
        }
        print(PHP_EOL);
        foreach ($header as $col => $title) {
            print(str_repeat('─', $max[$col])) . str_repeat('─', strlen($gap));
        }
        print(PHP_EOL);
    }
    foreach ($table as $key => $row) {
        echo ' ';
        foreach ($row as $col => $cell) {
            $space = str_repeat(' ', $max[$col] - $cell[3]);
            $text = $cell[0];
            if (!empty($cell[1]) && !empty($cell[0])) $text = cc($cell[1], $text);
            if (!empty($cell[2]) && $cell[2] == 'right') { print($space . $text); }
            else { print($text . $space); }
            print($gap);
        }
        print(PHP_EOL);
    }
    foreach ($header as $col => $title) {
        print(str_repeat('─', $max[$col])) . str_repeat('─', strlen($gap));
    }
}

function exec_timeout($cmd, $timeout) {
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open('exec ' . $cmd, $descriptors, $pipes);
    if (!is_resource($process)) throw new \Exception('Could not execute process');
    stream_set_blocking($pipes[1], 0);
    stream_set_blocking($pipes[2], 0);
    $timeout = $timeout * 1000000;
    $buffer = '';
    while ($timeout > 0) {
        $start = microtime(true);
        $read = [$pipes[1]]; $other = [];
        stream_select($read, $other, $other, 0, (int)$timeout);
        $buffer .= stream_get_contents($pipes[1]) ?? '';
        $status = proc_get_status($process);
        if (!$status['running']) break;
        $timeout -= (microtime(true) - $start) * 1000000;
    }
    $errors = stream_get_contents($pipes[2]) ?? '';
    if (!empty($errors)) throw new \Exception($errors);
    proc_terminate($process, 9);
    fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
    return $buffer;
}

function kill_mount($index) {
    global $db;
    $mount_point = normalize_mount_path($db[$index]['mount_point'] ?? '');
    if (!$mount_point) { warning('Invalid mount point', $db[$index]['mount_point'] ?? ''); return false; }
    if (!is_mounted_macos($mount_point)) {
        printf("%s %s\n", cc(245, 'Not mounted:'), $mount_point);
        return true;
    }
    printf("%s %s\n", cc('bold underline 225', 'Unmounting'), cc(220, $mount_point));
    return unmount_path($mount_point);
}

$db = load_config();

$commands = [
    'add'      => 'Add new entry',
    'edit'     => 'Edit entry (edit <index>)',
    'delete'   => 'Delete entry (delete <index>)',
    'kill'     => 'Kill connection (kill <index>)',
    'kill all' => 'Unmount all active SSHFS mount points',
    'unmount'  => 'Unmount connection (unmount <index>)',
    'mounts'   => 'Show active SSHFS mount points',
    'list'     => 'Refresh list of entries',
    'info'     => 'Show active connections or (if info <index>) show entry info',
    'help'     => 'This help',
    'version'  => 'Show version',
    'exit'     => 'Terminate program',
];

print_version();
if (empty($argv[1])) print_list();

while (true) {
    if (!empty($argv[1])) {
        $input = implode(' ', array_slice($argv, 1));
        unset($argv[1]);
        goto process;
    }
    input:
    $input = readln('monterssh> ');
    if ($input === false) exit(0);
    if ($input === '') goto input;
    process:

    if (preg_match('/^[0-9 ]+$/', $input)) {
        $indexes = [];
        if (is_numeric($input)) {
            $input = intval($input);
            if ($input < 1 || $input > count($db)) { warning('Invalid input', $input); goto input; }
            $indexes[] = $input - 1;
        } else {
            preg_match_all('/\d+/', $input, $match);
            foreach ($match[0] as $idx) $indexes[] = intval($idx) - 1;
        }
        mount_by_index:
        $success = false; $just_mounted = []; $just_added = null;
        foreach ($indexes as $index) {
            if (!array_key_exists($index, $db)) { warning('Invalid index', $index + 1); continue; }
            $mount_dir = normalize_mount_path($db[$index]['mount_point'] ?? '');
            if (!$mount_dir) { warning('Invalid mount point', $db[$index]['mount_point'] ?? ''); continue; }
            if (is_mounted_macos($mount_dir)) {
                printf("%s %s\n", cc(245, 'Already mounted:'), $mount_dir);
                continue;
            }
            if (!ensure_mount_dir($mount_dir)) continue;

            $port = filter_var($db[$index]['port'] ?? 22, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            $username = trim((string)($db[$index]['username'] ?? ''));
            $host = trim((string)($db[$index]['host'] ?? ''));
            $remote_path = (string)($db[$index]['path'] ?? '');
            $key_name = trim((string)($db[$index]['rsa'] ?? ''));
            if (!$port || !$username || !$host || !$key_name) {
                warning('Incomplete SSH configuration', $db[$index]['mount_point']);
                continue;
            }
            $identity_file = identity_file_for_key($key_name);
            if (!is_file($identity_file)) { warning('SSH key not found', $identity_file); continue; }

            $options = implode(',', [
                'IdentityFile=' . $identity_file,
                'reconnect', 'ServerAliveInterval=60', 'ServerAliveCountMax=3',
                'allow_other', 'defer_permissions', 'volname=' . basename($mount_dir),
            ]);
            $remote = $username . '@' . $host . ':' . $remote_path;
            $cmd = 'sshfs ' . escapeshellarg($remote) . ' -p ' . $port . ' -o ' . escapeshellarg($options) . ' ' . escapeshellarg($mount_dir) . ' 2>&1';
            exec($cmd, $cmd_output, $exit_code);
            if ($exit_code !== 0 || !is_mounted_macos($mount_dir)) {
                warning('SSHFS mount failed', implode("\n", $cmd_output) ?: 'sshfs exited without mounting');
                continue;
            }
            $just_mounted[] = $mount_dir;
            printf("%s: %s\n", cc(82, 'Successfully mounted'), cc(220, $mount_dir));
            $success = true;
        }
        if ($success) print_list();
    } else {
        $command = trim($input); $command_index = null;
        if (preg_match('/^(.+) (\d+)$/', $command, $m)) { $command = $m[1]; $command_index = $m[2]; }
        switch ($command) {
            case 'new': case 'add':
            $find_output = [];
            foreach (glob(rtrim($home_dir, '/') . '/.ssh/*.pub') ?: [] as $public_key) {
                $private_key = substr($public_key, 0, -4);
                if (is_file($private_key)) $find_output[] = basename($private_key);
            }
            clear_line(); print(PHP_EOL);
            $login = readln(sprintf("\t%s ", cc(248, 'ssh username:')));
            $host = readln(sprintf("\t%s ", cc(248, 'ssh host:')));
            $path = readln(sprintf("\t%s ", cc(248, 'ssh remote path:')));
            $mount_point = readln(sprintf("\t%s ", cc(248, 'mount point:')));
            $port = 22; $id_rsa = null;
            print(PHP_EOL);
            foreach ($find_output as $key => $key_name) {
                $color = ($key_name === 'id_rsa') ? 82 : 219;
                if ($key_name === 'id_rsa') $id_rsa = $key;
                printf("\t%d. %s\n", $key, cc($color, $key_name));
            }
            $pub_index = readln("\npublic key (index): ", true);
            if (!$pub_index) $pub_index = $id_rsa;
            $rsa = $find_output[$pub_index] ?? $find_output[0] ?? 'id_rsa';
            print("New entry saved!\n");
            printf("   username: %s\n       host: %s\n       path: %s\n       port: %s\n  pub_index: %s\nmount_point: %s\n\n",
                cc(228, $login), cc(228, $host), cc(228, $path), cc(228, $port), cc(228, $rsa), cc(228, $mount_dir));
            $db[] = ['username' => $login, 'host' => $host, 'path' => $path, 'port' => $port, 'rsa' => $rsa, 'mount_point' => $mount_point];
            $just_added = $mount_point; sort_list(); print_list(); break;

            case 'delete':
                if (empty($command_index)) { $input = readln(sprintf('%s index (1-%d): ', cc('bold underline 225', 'delete'), count($db))); $input = intval($input); }
                else $input = $command_index;
                if ($input < 1 || $input > count($db)) { warning('Invalid index', $input); goto input; }
                $confirm = readln(sprintf("Are you sure you want to delete %s? [Y/N]: ", cc('bold underline 81', $db[$input-1]['mount_point'])));
                if (!in_array(mb_strtolower($confirm), ['y','yes','д','да','ok','+','1'])) { printf("%s\n", cc(210, 'Aborted!')); break; }
                unset($db[$input-1]); $db = array_values($db); save_config(); print_list(); break;

            case 'edit':
                if ($command_index) { printf("\n%s index %d\n", cc('bold underline 225', 'edit'), $command_index); $input = $command_index; }
                else { $input = readln(sprintf('%s index (1-%d): ', cc('bold underline 225', 'edit'), count($db))); $input = intval($input); }
                if ($input < 1 || $input > count($db)) { warning('Invalid index', $input); goto input; }
                $index = $input - 1;
                printf("%s\nCurrent data:\n", cc(208, $db[$index]['mount_point']));
                printf("     ssh username: %s\n         ssh host: %s\n  ssh remote path: %s\n       public key: %s\n      mount point: %s\n\n",
                    cc(81, $db[$index]['username']), cc(81, $db[$index]['host']), cc(81, $db[$index]['path']), cc(81, $db[$index]['rsa']), cc(81, $db[$index]['mount_point']));
                printf("%s\n\n", cc('248 italic', "Keep blank to remain unchanged"));
                edit:
                $login = readln('ssh username: '); $host = readln('ssh host: '); $path = readln('ssh remote path: ');
                $rsa = readln('public key: '); $mount_point = readln('mount point: ');
                if ($rsa && !is_file(identity_file_for_key($rsa))) { printf("%s %s\n", cc(210, 'Private key not found:'), $rsa); goto edit; }
                print("Successful edit!\n");
                $keys = ['username','host','path','rsa','mount_point'];
                foreach ($keys as $key) {
                    $color = 245;
                    if (!empty(${$key}) && ${$key} != $db[$index][$key]) { $color = '228'; $db[$index][$key] = ${$key}; }
                    printf("%15s: %s\n", $key, cc($color, $db[$index][$key]));
                }
                printf("\n%s\n\n", cc('248 italic', "Saving config...")); sleep(1); save_config(); print_list(); break;

            case 'kill':
                if (!$command_index) $command_index = readln(sprintf('%s index (1-%d): ', cc('bold underline 225', 'kill'), count($db)));
                if ($command_index) {
                    $index = $command_index - 1;
                    if (!array_key_exists($index, $db)) { warning('Invalid index', $command_index); goto input; }
                    kill_mount($index); print_list();
                } break;

            case 'unmount':
                if (!$command_index) $command_index = readln(sprintf('%s index (1-%d): ', cc('bold underline 225', 'unmount'), count($db)));
                $index = (int)$command_index - 1;
                if (!array_key_exists($index, $db)) { warning('Invalid index', $command_index); goto input; }
                kill_mount($index); print_list(); break;

            case 'kill all':
                $mount_points = mounted_sshfs_paths();
                if (empty($mount_points)) printf("%s\n", cc('italic', 'No active SSHFS mount points'));
                foreach ($mount_points as $mount_point) {
                    printf("%s %s\n", cc('bold underline 225', 'Unmounting'), cc(220, $mount_point));
                    unmount_path($mount_point);
                }
                print_list(); break;

            case 'list': print_list(); break;
            case 'mounts':
                $mount_points = mounted_sshfs_paths();
                if (empty($mount_points)) printf("%s\n", cc('italic', 'No active SSHFS mount points'));
                else foreach ($mount_points as $mount_point) printf(" - %s\n", cc(220, $mount_point));
                break;
            case 'info': pgrep_list(true, $command_index); break;
            case 'sort': sort_list(); print_list(); break;
            case 'version': print_version(); break;
            case 'help': print_help(); break;
            case 'exit': exit();
            default:
                $results = [];
                foreach ($db as $key => $arr) if (strpos($arr['mount_point'], $command) !== false) $results[] = $key;
                if (!empty($results)) {
                    if (count($results) === 1) { $indexes = $results; goto mount_by_index; }
                    else {
                        printf("%s %s:\n", cc(210, 'Too many results for entry'), cc('214', '"' . $command .'"'));
                        foreach($results as $index) printf("  - %s\n", $db[$index]['mount_point']);
                    } break;
                }
                warning('Unregistered command', $command); goto input; break;
        }
    }
}

function sort_list() {
    global $db;
    foreach ($db as $key => $item) $db[$key]['sort'] = substr_count($item['mount_point'], '/');
    $sort_column = array_column($db, 'sort');
    $mount_point_column = array_column($db, 'mount_point');
    array_multisort($sort_column, SORT_ASC, $mount_point_column, SORT_ASC, $db);
    save_config();
}
?>
