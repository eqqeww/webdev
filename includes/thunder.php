<?php
/**
 * ตรวจสลิปโอนเงินอัตโนมัติผ่าน Thunder Solution API (POST /v2/verify/bank — อัปโหลดรูป)
 *
 * หลักการ: อนุมัติอัตโนมัติ "เฉพาะ" เมื่อผ่านทุกข้อ ถ้าไม่ผ่านข้อไหนจะตกไปให้แอดมินตรวจเองเหมือนเดิม
 *  1) อ่าน QR จากสลิปได้ และไม่ใช่สลิปซ้ำ (Thunder + ตรวจ transRef ซ้ำในฐานข้อมูลเราอีกชั้น)
 *  2) บัญชีผู้รับตรงกับบัญชีร้านที่ลงทะเบียนไว้ใน Thunder (matchAccount)
 *  3) สลิปไม่เก่าเกินกำหนด และยอดอยู่ในช่วงที่อนุญาต
 * เติมเงินตาม "ยอดจริงในสลิป" ไม่ใช่ยอดที่ผู้ใช้กรอก
 *
 * ตั้งค่าใน config/config.php (ดู README ท้ายไฟล์นี้หรือคำอธิบายที่ส่งให้)
 */

if (!defined('THUNDER_ENABLED'))             define('THUNDER_ENABLED', false);
if (!defined('THUNDER_API_KEY'))             define('THUNDER_API_KEY', '');
if (!defined('THUNDER_API_URL'))             define('THUNDER_API_URL', 'https://api.thunder.in.th/v2/verify/bank');
if (!defined('THUNDER_MAX_SLIP_AGE_HOURS'))  define('THUNDER_MAX_SLIP_AGE_HOURS', 24);
if (!defined('TOPUP_MIN_AMOUNT'))            define('TOPUP_MIN_AMOUNT', 10);
if (!defined('TOPUP_MAX_AMOUNT'))            define('TOPUP_MAX_AMOUNT', 100000);

function thunderEnabled() {
    return THUNDER_ENABLED && THUNDER_API_KEY !== '';
}

// ---------- เรียก API ----------
function thunderError($code, $message = '', $http = 0) {
    return ['ok' => false, 'http' => $http, 'error_code' => $code, 'error_message' => $message];
}

function thunderVerifySlip($filePath) {
    if (!function_exists('curl_init')) {
        return thunderError('NO_CURL', 'PHP cURL extension is not enabled');
    }
    if (!is_file($filePath)) {
        return thunderError('NO_FILE', 'slip file not found');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $filePath) ?: 'image/jpeg';
    finfo_close($finfo);

    $ch = curl_init(THUNDER_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => [
            'image'          => new CURLFile($filePath, $mime, basename($filePath)),
            'checkDuplicate' => 'true',
            'matchAccount'   => 'true',
        ],
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . THUNDER_API_KEY],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 25,
    ]);
    $body = curl_exec($ch);
    $errno = curl_errno($ch);
    $err = curl_error($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false) {
        // errno 60 = SSL certificate problem (พบบ่อยบน XAMPP Windows ที่ยังไม่ตั้ง curl.cainfo ใน php.ini)
        error_log("thunderVerifySlip curl error #$errno: $err");
        return thunderError('CURL_' . $errno, $err);
    }

    $json = json_decode($body, true);
    if (!is_array($json)) {
        error_log("thunderVerifySlip bad response HTTP $http");
        return thunderError('BAD_RESPONSE', '', $http);
    }
    if (($json['success'] ?? false) === true) {
        return ['ok' => true, 'http' => $http, 'data' => $json['data'] ?? []];
    }

    $code = $json['error']['code'] ?? ('HTTP_' . $http);
    $msg = $json['error']['message'] ?? '';
    error_log("thunderVerifySlip API error: HTTP $http $code");
    return thunderError($code, $msg, $http);
}

// ข้อความภาษาไทยสำหรับ error ของ API (แสดงให้แอดมินเห็น)
function thunderErrorReason($res) {
    $map = [
        'SLIP_NOT_FOUND'       => 'ไม่พบ QR Code ในรูปสลิป (อาจครอปรูปตัด QR หรือรูปไม่ชัด)',
        'INVALID_IMAGE_FORMAT' => 'ไฟล์รูปไม่ถูกต้อง',
        'IMAGE_SIZE_TOO_LARGE' => 'รูปสลิปใหญ่เกิน 4MB',
    ];
    return $map[$res['error_code'] ?? ''] ?? ('ระบบตรวจสลิปอัตโนมัติใช้งานไม่ได้ชั่วคราว (' . ($res['error_code'] ?? 'unknown') . ')');
}

// ---------- ตัดสินผลจาก response (แยกเป็นฟังก์ชันล้วน ๆ เพื่อทดสอบง่าย) ----------
/**
 * @return array ['decision' => 'approve'|'reject'|'manual', 'reason' => string,
 *                'trans_ref' => string, 'slip_amount' => float, 'detail' => array]
 */
function thunderDecide(array $data, $claimedAmount, $now = null) {
    $now = $now ?? time();
    $raw = $data['rawSlip'] ?? [];
    $transRef = (string)($raw['transRef'] ?? '');
    $slipAmount = round((float)($raw['amount']['amount'] ?? 0), 2);
    $slipTs = isset($raw['date']) ? strtotime($raw['date']) : false;
    $matched = !empty($data['matchedAccount']);

    $detail = [
        'claimed_amount' => round((float)$claimedAmount, 2),
        'slip_amount'    => $slipAmount,
        'slip_date'      => $raw['date'] ?? null,
        'sender'         => $raw['sender']['account']['name']['th'] ?? ($raw['sender']['account']['name']['en'] ?? null),
        'sender_bank'    => $raw['sender']['bank']['short'] ?? null,
        'receiver'       => $raw['receiver']['account']['name']['th'] ?? ($raw['receiver']['account']['name']['en'] ?? null),
        'receiver_bank'  => $raw['receiver']['bank']['short'] ?? null,
        'matched_account'=> $matched,
    ];
    $out = function ($decision, $reason) use ($transRef, $slipAmount, $detail) {
        $detail['reason'] = $reason;
        return ['decision' => $decision, 'reason' => $reason, 'trans_ref' => $transRef,
                'slip_amount' => $slipAmount, 'detail' => $detail];
    };

    if (!empty($data['isDuplicate'])) {
        return $out('reject', 'สลิปนี้เคยถูกใช้ตรวจสอบ/เติมเงินแล้ว (สลิปซ้ำ)');
    }
    if ($transRef === '' || $slipAmount <= 0) {
        return $out('manual', 'อ่านข้อมูลสลิปได้ไม่ครบ (ไม่มีเลขอ้างอิงหรือยอดเงิน)');
    }
    if (!$matched) {
        return $out('manual', 'บัญชีผู้รับในสลิปไม่ตรงกับบัญชีร้านที่ลงทะเบียนไว้ใน Thunder');
    }
    if ($slipTs === false) {
        return $out('manual', 'อ่านวันเวลาในสลิปไม่ได้');
    }
    if ($slipTs > $now + 600) {
        return $out('manual', 'วันเวลาในสลิปเป็นอนาคต ผิดปกติ');
    }
    if ($now - $slipTs > THUNDER_MAX_SLIP_AGE_HOURS * 3600) {
        return $out('manual', 'สลิปเก่าเกิน ' . THUNDER_MAX_SLIP_AGE_HOURS . ' ชั่วโมง');
    }
    if ($slipAmount < TOPUP_MIN_AMOUNT || $slipAmount > TOPUP_MAX_AMOUNT) {
        return $out('manual', 'ยอดในสลิปอยู่นอกช่วงที่เติมอัตโนมัติได้ (' . number_format(TOPUP_MIN_AMOUNT) . '–' . number_format(TOPUP_MAX_AMOUNT) . ' บาท)');
    }
    // ยอดที่ผู้ใช้กรอกต้องตรงกับยอดที่ API อ่านได้เท่านั้น จึงอนุมัติอัตโนมัติ
    if (abs($slipAmount - round((float)$claimedAmount, 2)) >= 0.01) {
        return $out('manual', 'ยอดเงินในสลิปไม่ตรงกับยอดที่แจ้งเติมเงิน');
    }
    return $out('approve', 'ผ่านการตรวจสอบทุกข้อ');
}

// ---------- บันทึก topup (+ เติม wallet ถ้า approved) ในทรานแซกชันเดียว ----------
// คืน id ของ topup หรือ 'duplicate' ถ้า transRef ซ้ำกับรายการเดิมในฐานข้อมูล
function thunderSaveTopup($conn, $userId, $amount, $slipFile, $note, $status, $adminNote, $transRef, $verifyResult, array $detail) {
    $detailJson = $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
    $reviewedAt = $status === 'pending' ? null : date('Y-m-d H:i:s');
    $transRef = $transRef !== '' ? $transRef : null;

    try {
        $conn->begin_transaction();
        $stmt = $conn->prepare("INSERT INTO topups (user_id, amount, slip_image, note, status, admin_note, reviewed_at, slip_trans_ref, verify_result, verify_detail)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param('idssssssss', $userId, $amount, $slipFile, $note, $status, $adminNote, $reviewedAt, $transRef, $verifyResult, $detailJson);
        if (!$stmt->execute()) throw new mysqli_sql_exception($stmt->error, $stmt->errno);
        $topupId = $conn->insert_id;

        if ($status === 'approved') {
            $stmt = $conn->prepare("UPDATE users SET wallet_balance = wallet_balance + ? WHERE id = ?");
            $stmt->bind_param('di', $amount, $userId);
            if (!$stmt->execute()) throw new mysqli_sql_exception($stmt->error, $stmt->errno);
        }
        $conn->commit();
        return $topupId;
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        if ((int)$e->getCode() === 1062) return 'duplicate';
        throw $e;
    }
}

// ---------- ตัวหลัก: เรียกจาก topup.php หลังอัปโหลดสลิปสำเร็จ ----------
/**
 * @return array ['status' => 'approved'|'pending'|'rejected'|'retry', 'message' => string, 'topup_id' => int|null]
 *   retry = ยังไม่บันทึกอะไร ให้ผู้ใช้ลองส่งใหม่ (ผู้เรียกควรลบไฟล์สลิปที่เพิ่งอัปโหลด)
 */
function processTopupSubmission($conn, $userId, $claimedAmount, $slipFile, $note) {
    $claimedAmount = round((float)$claimedAmount, 2);
    $baseLog = ['claimed_amount' => $claimedAmount, 'slip' => $slipFile];

    // บันทึกแบบรอแอดมิน (ใช้ทั้งกรณีปิดระบบอัตโนมัติ และกรณีตรวจอัตโนมัติไม่ผ่าน)
    $queueManual = function ($reason, $verifyResult, $transRef = '', array $detail = []) use ($conn, $userId, $claimedAmount, $slipFile, $note, $baseLog) {
        $adminNote = $reason ? ('ระบบตรวจสลิปอัตโนมัติ: ' . $reason) : null;
        $id = thunderSaveTopup($conn, $userId, $claimedAmount, $slipFile, $note, 'pending', $adminNote, $transRef, $verifyResult, $detail);
        if ($id === 'duplicate') {
            return null; // ให้ผู้เรียกจัดการเป็นสลิปซ้ำ
        }
        logActivity($conn, 'topup_request', 'topup', $id, $baseLog + ['verify_result' => $verifyResult, 'reason' => $reason]);
        return [
            'status' => 'pending', 'topup_id' => $id,
            'message' => $verifyResult === 'skipped'
                ? 'แจ้งเติมเงินสำเร็จ! กรุณารอแอดมินตรวจสอบและอนุมัติ'
                : 'ระบบตรวจสลิปอัตโนมัติยังยืนยันไม่ได้ (' . $reason . ') ส่งให้แอดมินตรวจสอบแล้ว กรุณารอสักครู่',
        ];
    };

    $rejectDuplicate = function ($detail = []) use ($conn, $userId, $claimedAmount, $slipFile, $note, $baseLog) {
        $reason = 'สลิปนี้เคยถูกใช้เติมเงินแล้ว (สลิปซ้ำ)';
        $detail['reason'] = $reason;
        $id = thunderSaveTopup($conn, $userId, $claimedAmount, $slipFile, $note, 'rejected',
            'ระบบปฏิเสธอัตโนมัติ: ' . $reason, '', 'auto_rejected', $detail);
        logActivity($conn, 'topup_auto_reject', 'topup', is_int($id) ? $id : null, $baseLog + ['reason' => $reason]);
        return ['status' => 'rejected', 'topup_id' => is_int($id) ? $id : null,
                'message' => 'สลิปนี้เคยถูกใช้เติมเงินแล้ว ไม่สามารถใช้ซ้ำได้'];
    };

    if (!thunderEnabled()) {
        return $queueManual('', 'skipped');
    }

    $res = thunderVerifySlip(UPLOAD_SLIP_DIR . $slipFile);

    if (!$res['ok']) {
        if (($res['error_code'] ?? '') === 'SLIP_PENDING') {
            return ['status' => 'retry', 'topup_id' => null,
                    'message' => 'สลิปธนาคารกรุงเทพยังไม่พร้อมให้ตรวจสอบ กรุณารอประมาณ 5 นาทีแล้วอัปโหลดสลิปใหม่อีกครั้ง'];
        }
        return $queueManual(thunderErrorReason($res), 'manual_review', '', ['reason' => thunderErrorReason($res)])
            ?? $rejectDuplicate();
    }

    $d = thunderDecide($res['data'], $claimedAmount);

    if ($d['decision'] === 'reject') {
        return $rejectDuplicate($d['detail']);
    }

    if ($d['decision'] === 'manual') {
        return $queueManual($d['reason'], 'manual_review', $d['trans_ref'], $d['detail']) ?? $rejectDuplicate($d['detail']);
    }

    // ผ่านทุกข้อ -> อนุมัติและเติมเงินตามยอดจริงในสลิป
    $slipAmount = $d['slip_amount'];
    $adminNote = 'ตรวจสลิปอัตโนมัติผ่าน (ref: ' . $d['trans_ref'] . ')';
    $id = thunderSaveTopup($conn, $userId, $slipAmount, $slipFile, $note, 'approved', $adminNote,
        $d['trans_ref'], 'auto_approved', $d['detail']);
    if ($id === 'duplicate') {
        return $rejectDuplicate($d['detail']);
    }

    logActivity($conn, 'topup_request', 'topup', $id, $baseLog + ['verify_result' => 'auto_approved']);
    logActivity($conn, 'topup_auto_approve', 'topup', $id, [
        'user_id' => (int)$userId, 'amount' => $slipAmount, 'claimed_amount' => $claimedAmount, 'trans_ref' => $d['trans_ref'],
    ]);

    return ['status' => 'approved', 'topup_id' => $id,
            'message' => 'เติมเงินสำเร็จ! เพิ่มยอด ' . number_format($slipAmount, 2) . ' บาทเข้า Wallet แล้ว'
                       ];
}
