<?php

function next_sequence(mysqli $conn, string $key, string $dateYmd): int {
    $key = trim($key);
    $dateYmd = trim($dateYmd);
    if ($key === '' || !preg_match('/^\d{4,8}$/', $dateYmd)) return 0;

    // Insert or increment, then read back the value
    $stmt = $conn->prepare("INSERT INTO inventory_app_counters (k, d, seq) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE seq = seq + 1");
    $stmt->bind_param("ss", $key, $dateYmd);
    $stmt->execute();

    $stmt2 = $conn->prepare("SELECT seq FROM inventory_app_counters WHERE k = ? AND d = ?");
    $stmt2->bind_param("ss", $key, $dateYmd);
    $stmt2->execute();
    $row = $stmt2->get_result()->fetch_assoc();
    return (int)($row['seq'] ?? 0);
}
