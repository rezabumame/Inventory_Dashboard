<?php
if (!function_exists('logBookingHistory')) {
    function logBookingHistory($conn, $booking_id, $action, $changes = [], $notes = '') {
        $user_id = $_SESSION['user_id'] ?? 0;
        $user_name = $_SESSION['user_name'] ?? 'Unknown';
        
        // Get user name from DB if not in session
        if ($user_name === 'Unknown' && $user_id > 0) {
            $u_stmt = $conn->prepare("SELECT nama_lengkap FROM inventory_users WHERE id = ?");
            $u_stmt->bind_param("i", $user_id);
            $u_stmt->execute();
            $u_res = $u_stmt->get_result()->fetch_assoc();
            if ($u_res) $user_name = $u_res['nama_lengkap'];
        }

        $changes_json = !empty($changes) ? json_encode($changes) : null;
        
        $stmt = $conn->prepare("INSERT INTO inventory_booking_history (booking_id, user_id, user_name, action, changes, notes) VALUES (?, ?, ?, ?, ?, ?)");
        $stmt->bind_param("iissss", $booking_id, $user_id, $user_name, $action, $changes_json, $notes);
        return $stmt->execute();
    }
}
?>
