<?php

$current_user = get_current_user();
$home_dir = getenv('HOME');

$config_file = __DIR__ . '/data.json';
$pids = [];
$active_mount_points = [];
$stale_mount_points = [];
$just_mounted = [];
$just_added = null;

define('VERSION', '2.2.0-macOS');
define('RELEASE_DATE', '2026-02-26');
define('MOUNT_DIR', '/Volumes/');

function load_config()
{
    global $config_file;
    if (!file_exists($config_file)) {
        file_put_contents($config_file, '{}');
    }
    $config = file_get_contents($config_file);
    if (!$config) fatal_error('Failed to read file', $config_file);
    $array = json_decode($config, true);
    return array_values($array ?: []);
}

function save_config()
{
    global $db, $config_file;
    file_put_contents($config_file, json_encode($db, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function readln($prompt = null, $reset = false)
{
    if ($prompt) echo $prompt;
    $fp = fopen("php://stdin","r");
    $line = rtrim(fgets($fp, 1024));
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
    $cols = (int)exec('tput cols');
    if ($n) echo chr(27) . '[0G' . chr(27) . '[' . $n . 'A';
    printf("%s\r", str_repeat(' ', $cols));
}

function warning($message, $info = '')
{
    reset_line();
    printf("%s", cc(204, $message));
    if ($info) printf("%s %s\n", cc(204, ':'), $info); else echo "\n";
}

// macOS-специфичная проверка монтирования
function is_mounted_macos($mount_point)
{
    $output = shell_exec("mount 2>/dev/null | grep -F ' " . escapeshellarg($mount_point) . " '");
    return !empty(trim((string)$output));
}

function print_list()
{
    global $db, $commands, $active_mount_points, $stale_mount_points, $just_mounted, $just_added;
    $line_width = 40;

    if (!empty($db)) {
        pgrep_list();
        $i = 0;
        $stale_mount_points = [];
        printf("%s\n", str_repeat('─', $line_width));
        foreach ($db as $key => $arr) {
            $mount_point = MOUNT_DIR . $arr['mount_point'];
            $is_mounted = is_mounted_macos($mount_point);

            $default_cc = 245;
            $cc = (substr_count($arr['mount_point'], '/')) ? '220 bold underline' : '82 bold underline';
            if (!empty($just_mounted) && in_array($mount_point, $just_mounted))
                $cc = preg_replace('/(\d+)/', 'bg:$1 232', $cc);
            if (!empty($just_added) && $arr['mount_point'] == $just_added)
                $default_cc = 81;

            if (!$is_mounted) {
                if (in_array($mount_point . '/', $active_mount_points) || is_mounted_macos($mount_point . '/')) {
                    kill_mount($key);
                    $is_mounted = is_mounted_macos($mount_point);
                }
                if (!$is_mounted) {
                    $stale_mount_points[] = $mount_point;
                    $cc = 210;
                }
            }
            $color = $is_mounted ? $cc : $default_cc;
            printf(" % 2d. %s\n", ++$i, cc($color, $arr['mount_point']));
        }
        printf("%s\n", str_repeat('─', $line_width));
    }
    $just_mounted = [];
}

function pgrep_list($show_info = false, $command_index = null)
{
    global $db, $commands, $pids, $active_mount_points;

    if (!empty($command_index)) {
        $info = $db[$command_index - 1];
        $maxlength = 0;
        printf("\nEntry %s: %s\n\n", cc('underline', '#' . $command_index), cc('bold 81', $info['mount_point']));
        foreach ($info as $key => $value)
            if (strlen($key) > $maxlength) $maxlength = strlen($key);
        foreach ($info as $key => $value) {
            printf("  %s%s: %s\n", str_repeat(' ', $maxlength - strlen($key)), $key, cc(228, $value));
        }
        print("\n");
        return;
    }

    $pids = [];
    $active_mount_points = [];
    exec('ps ax -o pid,command | grep -i sshfs | grep -v grep', $pgrep_output);
    $pgrep_output = array_filter(array_map('trim', $pgrep_output));

    if (!empty($pgrep_output)) {
        if ($show_info) printf("\n%s\n\n", cc('italic', 'Active connections:'));
        foreach ($pgrep_output as $pgrep) {
            if (preg_match('/^\s*(?<pid>\d+)\s+.*?sshfs\s+(?:(?:-o\s+\S+\s+)*?)?(?<username>[^@]+)@(?<host>[^:]+):(?<path>\S+).*?(?<mount>\/Volumes\/\S+)/i', $pgrep, $m)) {
                $pid = (int)$m['pid'];
                $mount = rtrim($m['mount'], '/');
                $pids[] = $pid;
                $active_mount_points[$pid] = $mount . '/';
                if ($show_info) {
                    printf("  pid: %s\n", cc(197, $pid));
                    printf("  %s\n", cc(220, $mount));
                    printf("  %s\n\n", cc(38, $m['path']));
                }
            } elseif (preg_match('/^\s*(?<pid>\d+)\s+.*?(?<mount>\/Volumes\/\S+)/', $pgrep, $m)) {
                $pid = (int)$m['pid'];
                $mount = rtrim($m['mount'], '/');
                if (!in_array($pid, $pids)) {
                    $pids[] = $pid;
                    $active_mount_points[$pid] = $mount . '/';
                    if ($show_info) {
                        printf("  pid: %s\n", cc(197, $pid));
                        printf("  %s\n\n", cc(220, $mount));
                    }
                }
            }
        }
    } else printf("%s\n", cc('italic', 'No sshfs process found'));
}

function clear_line()
{
    $cols = intval(`tput cols`);
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
    global $db, $active_mount_points;
    $mount_point = MOUNT_DIR . rtrim($db[$index]['mount_point'], '/') . '/';
    $pid = array_search($mount_point, $active_mount_points);
    if ($pid) {
        printf("\n%s %s (%s)\n", cc('bold underline 225', 'kill:'), cc(220, $db[$index]['mount_point']), cc(210, $pid));
        $cmd = "sudo kill -9 $pid";
        printf("Running command:\n%s\n", cc([197, 'bold'], $cmd));
        exec($cmd);
    }
    $cmd = "sudo diskutil unmount force " . escapeshellarg($mount_point);
    printf("Running command:\n%s\n", cc([197, 'bold'], $cmd));
    exec($cmd, $cmd_output);
    $cmd_output = array_filter($cmd_output ?? []);
    exec("sudo umount " . escapeshellarg($mount_point) . " 2>/dev/null");
}

function sudo_mkdir($mount_point) {
    global $current_user;
    $mount_point = preg_replace('~^/volumes/~i', '/Volumes/', $mount_point);
    printf("Directory %s not exists, creating\n", cc(220, $mount_point));
    exec(sprintf('sudo mkdir -p "%1$s" && sudo chown %2$s:staff "%1$s"', $mount_point, $current_user));
}

$db = load_config();

$commands = [
    'add'      => 'Add new entry',
    'edit'     => 'Edit entry (edit <index>)',
    'delete'   => 'Delete entry (delete <index>)',
    'kill'     => 'Kill connection (kill <index>)',
    'kill all' => 'Kill all active connections',
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
            $mount_dir = MOUNT_DIR . $db[$index]['mount_point'];
            if (in_array($mount_dir, $stale_mount_points)) kill_mount($index);
            if (!file_exists($mount_dir)) sudo_mkdir($mount_dir);

            $cmd = sprintf('sshfs %s@%s:%s -p %d -o IdentityFile=~/.ssh/%s,reconnect,ServerAliveInterval=60,ServerAliveCountMax=3,allow_other,defer_permissions,volname=%s "%s" 2>&1 &',
                $db[$index]['username'], $db[$index]['host'], $db[$index]['path'],
                $db[$index]['port'], $db[$index]['rsa'], $db[$index]['mount_point'], $mount_dir);
            $mount_point = MOUNT_DIR . $db[$index]['mount_point'];
            $retries = 0;
            sshfs_retry:
            unset($cmd_output); exec($cmd, $cmd_output);
            if (!empty($cmd_output)) {
                printf("%s\n", cc(210, implode("\n", $cmd_output)));
                if (preg_match('/(Permission denied|Transport endpoint is not connected|osxfuse: the mount point is invalid)/', implode(' ', $cmd_output))) {
                    printf("Unmounting %s\n", cc(220, $mount_point));
                    unset($umount_output);
                    exec('sudo diskutil unmount force ' . escapeshellarg($mount_point) . ' 2>&1', $umount_output);
                    if (empty($umount_output)) exec('sudo umount ' . escapeshellarg($mount_point) . ' 2>&1', $umount_output);
                    if (!empty($umount_output)) printf("%s\n", cc(210, implode("\n", $umount_output)));
                    $retries++;
                    if ($retries === 3) { printf("%s\n", cc(210, 'Exceeded amount of retries (3)')); }
                    else goto sshfs_retry;
                }
            } else {
                $just_mounted[] = $mount_point;
                printf("%s: %s\n", cc(82, 'Successfully mounted'), cc(220, $mount_point));
                $success = true;
            }
        }
        if ($success) print_list();
    } else {
        $command = trim($input); $command_index = null;
        if (preg_match('/^(.+) (\d+)$/', $command, $m)) { $command = $m[1]; $command_index = $m[2]; }
        switch ($command) {
            case 'new': case 'add':
            exec("find /Users/{$current_user}/.ssh -type f -name '*.pub'", $find_output);
            $find_output = array_map(fn($x) => preg_replace('~^.+/~', '', $x), $find_output);
            clear_line(); print(PHP_EOL);
            $login = readln(sprintf("\t%s ", cc(248, 'ssh username:')));
            $host = readln(sprintf("\t%s ", cc(248, 'ssh host:')));
            $path = readln(sprintf("\t%s ", cc(248, 'ssh remote path:')));
            $mount_point = readln(sprintf("\t%s ", cc(248, 'mount point:')));
            $port = 22; $id_rsa = null;
            print(PHP_EOL);
            foreach ($find_output as $key => $pub) {
                $color = 219;
                if ($pub == 'id_rsa.pub') { $find_output[$key] = 'id_rsa'; $id_rsa = $key; $color = 82; }
                printf("\t%d. %s\n", $key, cc($color, $pub));
            }
            $pub_index = readln("\npublic key (index): ", true);
            if (!$pub_index) $pub_index = $id_rsa;
            $mount_dir = MOUNT_DIR . preg_replace('~^' . MOUNT_DIR . '~', '', $mount_point);
            if (!file_exists($mount_dir)) sudo_mkdir($mount_dir);
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
                $mount_dir = MOUNT_DIR . preg_replace('~^' . MOUNT_DIR . '~', '', $mount_point);
                if (!file_exists($mount_dir)) sudo_mkdir($mount_dir);
                if ($rsa && !file_exists($home_dir . '/.ssh/' . $rsa)) { printf("%s %s\n", cc(210, 'File not found:'), $rsa); goto edit; }
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

            case 'kill all':
                if (empty($pids)) { warning('no PIDs available'); }
                else {
                    $cmd = sprintf('sudo kill -9 %s', implode(' ', $pids));
                    printf("Running command:\n%s\n", cc([197, 'bold'], $cmd)); exec($cmd);
                    foreach ($db as $arr) {
                        $mp = MOUNT_DIR . $arr['mount_point'];
                        $cmd = "sudo diskutil unmount force " . escapeshellarg($mp);
                        printf("Running command:\n%s\n", cc([197, 'bold'], $cmd)); exec($cmd);
                        exec("sudo umount " . escapeshellarg($mp) . " 2>/dev/null");
                    }
                    print_list();
                } break;

            case 'list': print_list(); break;
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