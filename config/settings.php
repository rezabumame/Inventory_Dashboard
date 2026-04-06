<?php
require_once __DIR__ . '/database.php';

function get_setting($key, $default = null) {
    global $conn;
    $key_esc = $conn->real_escape_string($key);
    $res = $conn->query("SELECT v FROM inventory_app_settings WHERE k = '$key_esc' LIMIT 1");
    if ($res && $res->num_rows > 0) {
        $row = $res->fetch_assoc();
        return $row['v'];
    }
    return $default;
}

function set_setting($key, $value) {
    global $conn;
    $stmt = $conn->prepare("INSERT INTO inventory_app_settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)");
    $stmt->bind_param("ss", $key, $value);
    $stmt->execute();
}
?>
