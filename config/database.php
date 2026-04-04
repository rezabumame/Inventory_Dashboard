<?php
$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : '';
$db   = getenv('DB_NAME') ?: 'bumame_inventory_v2';
$port = getenv('DB_PORT') ?: 3306;
$ssl  = getenv('DB_SSL') === 'true';

// Enable error reporting for mysqli to throw exceptions
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

try {
    $conn = mysqli_init();
    if ($ssl) {
        // TiDB Cloud requires SSL. We don't necessarily need to provide a CA file on Vercel 
        // as it uses system CA, but we must set the SSL flag.
        $conn->ssl_set(NULL, NULL, NULL, NULL, NULL);
        $conn->real_connect($host, $user, $pass, $db, $port, NULL, MYSQLI_CLIENT_SSL);
    } else {
        $conn->real_connect($host, $user, $pass, $db, $port);
    }
    $conn->set_charset("utf8mb4");
} catch (mysqli_sql_exception $e) {
    die("Connection failed: " . $e->getMessage());
}

if (!function_exists('ensure_enum_value')) {
    function ensure_enum_value(mysqli $conn, string $table, string $column, string $value): void {
        $t = $conn->real_escape_string($table);
        $c = $conn->real_escape_string($column);
        $res = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
        if (!$res || $res->num_rows === 0) return;
        $col = $res->fetch_assoc();
        $type = (string)($col['Type'] ?? '');
        if (stripos($type, 'enum(') !== 0) return;
        if (strpos($type, "'" . $value . "'") !== false) return;
        if (!preg_match_all("/'((?:\\\\'|[^'])*)'/", $type, $m)) return;
        $vals = $m[1] ?? [];
        if (in_array($value, $vals, true)) return;
        $vals[] = $value;
        $enum = "ENUM(" . implode(",", array_map(fn($v) => "'" . str_replace("'", "\\'", $v) . "'", $vals)) . ")";
        $null = ((string)($col['Null'] ?? 'NO')) === 'YES' ? 'NULL' : 'NOT NULL';
        $default = array_key_exists('Default', $col) && $col['Default'] !== null ? (" DEFAULT '" . $conn->real_escape_string((string)$col['Default']) . "'") : '';
        $conn->query("ALTER TABLE `$t` MODIFY `$column` $enum $null$default");
    }
}

 

if (!function_exists('safe_query')) {
    function safe_query(&$conn, $sql, $params = null, $types = "") {
        global $host, $user, $pass, $db;
        
        try {
            if ($params) {
                $stmt = $conn->prepare($sql);
                $stmt->bind_param($types, ...$params);
                $stmt->execute();
                return $stmt->get_result();
            } else {
                return $conn->query($sql);
            }
        } catch (mysqli_sql_exception $e) {
            // Check for connection errors (Gone Away, Connection Refused, Packet too large)
            if (in_array($e->getCode(), [2002, 2006, 2013])) { 
                // Close old connection
                if ($conn instanceof mysqli) {
                    @$conn->close();
                }
                
                // Single fast reconnect attempt - keep it light
                try {
                    $conn = new mysqli($host, $user, $pass, $db);
                    $conn->set_charset("utf8mb4");
                    
                    // Retry query immediately
                    if ($params) {
                        $stmt = $conn->prepare($sql);
                        $stmt->bind_param($types, ...$params);
                        $stmt->execute();
                        return $stmt->get_result();
                    } else {
                        return $conn->query($sql);
                    }
                } catch (mysqli_sql_exception $retry_e) {
                    // If retry fails, throw original error to be handled by UI
                    throw $e;
                }
            } else {
                throw $e;
            }
        }
    }
}
?>
