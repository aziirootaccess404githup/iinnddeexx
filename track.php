<?php
// ============================================================
// Exazon Research — pages/track.php
// Final status handler: complete / terminate / quotafull / qc
// Rebuilt on the proven PanelEngine architecture:
//   - SID-authority resolution (URL sid is only a hint)
//   - Status-lock (first-write-wins)
//   - Vendor postback (show_page / redirect modes)
//   - Configurable settings-driven alerts
// ============================================================
require_once __DIR__ . '/../inc_exz.php';

$db = exz_db();

// ── PARAMETERS ──────────────────────────────────────────────
$status  = trim($_GET['status'] ?? 'unknown');
$url_sid = trim($_GET['sid']    ?? 'Unknown_Project');
// Flexible param-name fallback — some client platforms send the identifier
// back under a different name.
$uid = trim($_GET['uid'] ?? $_GET['rid'] ?? $_GET['toid'] ?? $_GET['identifier'] ?? $_GET['tid'] ?? $_GET['pid'] ?? 'Unknown_User');
$sig = trim($_GET['sig'] ?? '');
$src_param = trim($_GET['src'] ?? '');
$ip  = exz_get_ip();

$sid = $url_sid; // may be corrected below once we find the real lead

// ── SID-AUTHORITY RESOLUTION + STATUS-LOCK ───────────────────
// Multi-tier lookup: the URL's sid is only a hint. Our own stored
// record (from vendor.php/entry.php) is always authoritative.
$existing = null;
try {
    $lc = $db->prepare("SELECT * FROM survey_responses WHERE respondent=? AND survey_id=? ORDER BY id DESC LIMIT 1");
    $lc->execute([$uid, $sid]);
    $existing = $lc->fetch();

    if (!$existing) {
        // Fallback: same UID, ANY project — covers the client sending a sid
        // that doesn't match what we have on file.
        $lc2 = $db->prepare("SELECT * FROM survey_responses WHERE respondent=? ORDER BY id DESC LIMIT 1");
        $lc2->execute([$uid]);
        $existing = $lc2->fetch();
        if ($existing) $sid = $existing['survey_id']; // our stored sid wins
    }
} catch (Exception $e) { error_log("track.php lookup error: ".$e->getMessage()); }

$is_repeat_locked = false;
if ($existing && !empty($existing['status_locked'])) {
    // Status already locked — ignore whatever the URL says, show the
    // respondent their original, real result.
    $status = $existing['status'];
    $is_repeat_locked = true;
}

// ── SHA-256 SIGNATURE (informational — logs mismatches, doesn't block) ──
if ($sig !== '') {
    define('EXAZON_SECRET', 'Exazon@Secret2026');
    $expected = hash_hmac('sha256', $status.'|'.$sid.'|'.$uid, EXAZON_SECRET);
    if (!hash_equals($expected, $sig)) {
        error_log("track.php: SHA-256 signature mismatch — sid=$sid uid=$uid ip=$ip");
    }
}

// ── PROJECT + CLIENT (DB-driven, not hardcoded) ──────────────
$proj = null;
$client_name = 'Unknown Client';
try {
    $pp = $db->prepare("SELECT ps.*, c.client_name AS cname FROM project_settings ps
        LEFT JOIN exz_clients c ON c.id = ps.client_id WHERE ps.survey_id=? LIMIT 1");
    $pp->execute([$sid]);
    $proj = $pp->fetch();
    if ($proj && !empty($proj['cname'])) $client_name = $proj['cname'];
    elseif ($proj && !empty($proj['project_name'])) $client_name = $proj['project_name'];
} catch (Exception $e) {}

// ── GEO / DEVICE ──────────────────────────────────────────────
$geo    = exz_geo($ip);
$device = exz_device();

// ── LOI CALCULATION (self-measured — priority 1; client &loi= — fallback) ──
$loi_seconds = null;
$vendor_id   = null;
try {
    $st = $db->prepare("SELECT start_time, vendor_id FROM survey_starts WHERE uid=? AND sid=? ORDER BY id DESC LIMIT 1");
    $st->execute([$uid, $sid]);
    $srow = $st->fetch();
    if ($srow) {
        if (!empty($srow['start_time'])) {
            $loi_seconds = max(0, (new DateTime())->getTimestamp() - (new DateTime($srow['start_time']))->getTimestamp());
        }
        $vendor_id = $srow['vendor_id'] ?: null;
    }
} catch (Exception $e) { error_log("track.php LOI calc error: ".$e->getMessage()); }
if ($loi_seconds === null && isset($_GET['loi']) && is_numeric($_GET['loi'])) {
    $loi_seconds = max(0, (int)$_GET['loi']);
}
// If this is a repeat hit on an already-locked lead, prefer its saved vendor_id/loi
if ($is_repeat_locked && $existing) {
    $vendor_id   = $existing['vendor_id'] ?: $vendor_id;
    $loi_seconds = $existing['loi_seconds'] ?? $loi_seconds;
}

// ── DUPLICATE CHECK ────────────────────────────────────────────
$is_duplicate = 0;
if (!$is_repeat_locked) {
    try {
        $dup = $db->prepare("SELECT COUNT(*) FROM survey_responses WHERE respondent=? AND survey_id=?");
        $dup->execute([$uid, $sid]);
        if ($dup->fetchColumn() > 0) $is_duplicate = 1;
    } catch (Exception $e) {}
}

// ── SOURCE ─────────────────────────────────────────────────────
$source = $src_param ?: 'direct';
if (!$src_param) {
    try {
        $ss = $db->prepare("SELECT source FROM survey_starts WHERE uid=? AND sid=? ORDER BY id DESC LIMIT 1");
        $ss->execute([$uid, $sid]);
        $srow2 = $ss->fetchColumn();
        if ($srow2) $source = $srow2;
    } catch (Exception $e) {}
}

// ── SAVE / UPDATE (status-lock: only write if not already locked) ────────
if (!$is_repeat_locked) {
    try {
        if ($existing) {
            $db->prepare("UPDATE survey_responses SET status=?,ip=?,country=?,city=?,device=?,source=?,client_name=?,loi_seconds=?,is_duplicate=?,vendor_id=?,status_locked=1 WHERE id=?")
               ->execute([$status,$ip,$geo['country'],$geo['city'],$device,$source,$client_name,$loi_seconds,$is_duplicate,$vendor_id,$existing['id']]);
        } else {
            $db->prepare("INSERT INTO survey_responses
                (survey_id,respondent,status,ip,country,city,device,source,client_name,loi_seconds,is_duplicate,vendor_id,status_locked,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,NOW())")
               ->execute([$sid,$uid,$status,$ip,$geo['country'],$geo['city'],$device,$source,$client_name,$loi_seconds,$is_duplicate,$vendor_id]);
        }
        if ($status === 'complete') exz_check_quota($db, $sid);
    } catch (Exception $e) { error_log("track.php save error: ".$e->getMessage()); }
}

// ── DUPLICATE ALERT (fresh writes only) ───────────────────────
if (!$is_repeat_locked && $is_duplicate === 1) {
    exz_send_telegram($db, "⚠️ Duplicate UID!\nProject: $sid\nUID: $uid\nStatus: $status | IP: $ip\nSame respondent ne dobara koshish ki!");
}

// ── VENDOR REDIRECT-MODE CHECK ─────────────────────────────────
$vendor_redirect = exz_build_vendor_redirect($db, $vendor_id, $status, $uid, $sid, $loi_seconds);
if ($vendor_redirect) {
    header("Location: $vendor_redirect");
    exit();
}

// ── REDIRECT TO OUR OWN STATUS PAGE (send this first) ─────────
$pages = ['complete'=>'complete','terminate'=>'terminate','qc'=>'quality-fail','quality'=>'quality-fail','quotafull'=>'quota-full'];
$page = $pages[$status] ?? 'complete';
header("Location: /pages/{$page}.html?status=$status&sid=$sid&uid=$uid");

// ── DEFERRED: vendor postback + speeder alert (after response sent) ──
if (!$is_repeat_locked) {
    exz_flush_and_defer($db, $vendor_id, $status, $uid, $sid, $proj, $loi_seconds);

    // Google Sheet sync (best-effort, runs after respondent's redirect)
    try {
        $ch = curl_init("https://script.google.com/macros/s/AKfycby93oXzhv0dB0evWH3Ni_KYUgqDXmlCDLyTUFSvTn8zpwe3eWiHth8aLaYJtxuX6s2-/exec");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                "timestamp"=>date("d/m/Y H:i:s"),"project_id"=>$sid,"respondent_id"=>$uid,"status"=>$status,
                "ip_address"=>$ip,"city"=>$geo['city'],"country"=>$geo['country'],"device"=>$device,
                "source"=>$source,"client"=>$client_name,
                "loi"=>$loi_seconds!==null?gmdate("i:s",$loi_seconds):'N/A',"is_duplicate"=>$is_duplicate?"YES":"NO",
            ]),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 8,
        ]);
        curl_exec($ch); curl_close($ch);
    } catch (Exception $e) {}
}
exit();
