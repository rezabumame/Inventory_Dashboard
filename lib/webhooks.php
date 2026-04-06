<?php

/**
 * Notify Google Sheets Webhook about booking events
 * @param mysqli $conn
 * @param int $booking_id
 * @param string $event (booking_created, booking_updated, booking_cancelled, booking_completed, etc.)
 */
function notify_gsheet_booking(mysqli $conn, int $booking_id, string $event) {
    try {
        $webhook = trim((string)get_setting('gsheet_booking_webhook_url', ''));
        if ($webhook === '') return;

        // Fetch complete booking data
        $sql = "SELECT b.*, k.nama_klinik 
                FROM inventory_booking_pemeriksaan b
                LEFT JOIN inventory_klinik k ON b.klinik_id = k.id
                WHERE b.id = $booking_id LIMIT 1";
        $res = $conn->query($sql);
        if (!$res || $res->num_rows === 0) return;
        $b = $res->fetch_assoc();

        // Fetch exams summary
        $exams_text_arr = [];
        $exams_json_arr = [];
        $res_p = $conn->query("
            SELECT p.nama_pasien, g.nama_pemeriksaan 
            FROM inventory_booking_pasien p
            JOIN inventory_pemeriksaan_grup g ON p.pemeriksaan_grup_id = g.id
            WHERE p.booking_id = $booking_id
        ");
        while ($row_p = $res_p->fetch_assoc()) {
            $exams_text_arr[] = $row_p['nama_pemeriksaan'];
            $exams_json_arr[] = [
                'pasien' => $row_p['nama_pasien'],
                'pemeriksaan' => $row_p['nama_pemeriksaan']
            ];
        }

        $payload = [
            'event' => $event,
            'nomor_booking' => $b['nomor_booking'],
            'tanggal_pemeriksaan' => $b['tanggal_pemeriksaan'],
            'jam_layanan' => $b['jam_layanan'],
            'status_booking' => $b['status_booking'],
            'booking_type' => $b['booking_type'],
            'klinik_id' => (int)$b['klinik_id'],
            'klinik_nama' => $b['nama_klinik'] ?? '',
            'cs_name' => $b['cs_name'],
            'nama_pemesan' => $b['nama_pemesan'],
            'nomor_tlp' => $b['nomor_tlp'],
            'tanggal_lahir' => $b['tanggal_lahir'],
            'jumlah_pax' => (int)$b['jumlah_pax'],
            'jotform_submitted' => (int)$b['jotform_submitted'],
            'status' => $b['status'],
            'updated_at' => date('Y-m-d H:i:s'),
            'exams_text' => implode(' | ', array_unique($exams_text_arr)),
            'exams' => $exams_json_arr
        ];

        $ch = curl_init($webhook);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 2000); // 2 seconds timeout
        curl_exec($ch);
        curl_close($ch);

    } catch (\Throwable $e) {
        // Silently fail to not break the main flow
        error_log("Webhook Error: " . $e->getMessage());
    }
}
