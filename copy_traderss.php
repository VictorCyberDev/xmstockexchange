<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

if (session_status() === PHP_SESSION_NONE) session_start();
include __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];

$user = [];
$uq = $conn->query("SELECT name, email, balance FROM users WHERE id = $user_id LIMIT 1");
if ($uq && $uq->num_rows > 0) $user = $uq->fetch_assoc();
$wallet     = (float)($user['balance'] ?? 0);
$user_name  = htmlspecialchars($user['name']  ?? 'Account Holder');
$user_email = htmlspecialchars($user['email'] ?? '');

$traders = [];
$q = $conn->query("SELECT id, display_name, profile_image, category, monthly_return, annual_return,
    risk_score, win_rate, min_deposit, trading_fee, duration_days, followers, verified
    FROM copy_traders WHERE status='active' ORDER BY monthly_return DESC");
if ($q) $traders = $q->fetch_all(MYSQLI_ASSOC);

$active = [];
$a = $conn->query("
    SELECT ct.id, ct.leader_id, t.display_name, t.profile_image, t.monthly_return, t.win_rate,
           ct.invested_amount, ct.manual_profit, ct.status, ct.created_at, ct.trading_fee, t.duration_days
    FROM copy_trades ct
    JOIN copy_traders t ON ct.leader_id = t.id
    WHERE ct.user_id = $user_id AND ct.status = 'active'
    ORDER BY ct.created_at DESC
");
if ($a) $active = $a->fetch_all(MYSQLI_ASSOC);

// ── All math done in PHP once, stored safely ──
foreach ($active as &$inv) {
    $inv_amt     = round((float)($inv['invested_amount'] ?? 0), 2);
    $monthly_ret = (float)($inv['monthly_return'] ?? 0);
    $days        = max(1, (int)floor((time() - strtotime($inv['created_at'])) / 86400));
    $total_days  = max(1, (int)($inv['duration_days'] ?? 30));
    $auto_profit = $inv_amt * ($monthly_ret / 100) * ($days / 30);
    $manual      = $inv['manual_profit'];
    // Use manual_profit only if it's explicitly set and >= 0
    $profit      = ($manual !== null && $manual !== '' && (float)$manual >= 0)
                   ? round((float)$manual, 2)
                   : round($auto_profit, 2);
    $fee20       = round($profit * 0.20, 2);
    $full_total  = round($inv_amt + $profit + $fee20, 2);
    $progress    = min(100, (int)round(($days / $total_days) * 100));

    $inv['_inv_amt']    = $inv_amt;
    $inv['_profit']     = $profit;
    $inv['_fee20']      = $fee20;
    $inv['_full_total'] = $full_total;
    $inv['_days']       = $days;
    $inv['_total_days'] = $total_days;
    $inv['_progress']   = $progress;
}
unset($inv);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Copy Trading — SwiftTrade</title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=JetBrains+Mono:wght@400;500;700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{
  --bg:#03080f;--bg2:#060e1a;--card:#091422;--card2:#0b1a2c;
  --brd:#112236;--brd2:#1a3350;
  --cyan:#00d4f5;--cyan2:#0099bb;
  --grn:#00e676;--grn2:#00a854;
  --gold:#f5c842;--red:#ff4060;
  --txt:#cde4f5;--mut:#4e7a9a;--mut2:#2a4a66;
  --r:14px;--r2:20px;
}
*{box-sizing:border-box;margin:0;padding:0;}
body{background:var(--bg);color:var(--txt);font-family:'Space Grotesk',sans-serif;min-height:100vh;overflow-x:hidden;}
.wrap{max-width:1240px;margin:0 auto;padding:0 18px 80px;}

/* PAGE HEADER */
.pg-head{padding:36px 0 28px;display:flex;justify-content:space-between;align-items:flex-end;flex-wrap:wrap;gap:16px;border-bottom:1px solid var(--brd);margin-bottom:28px;}
.pg-eye{font-family:'JetBrains Mono',monospace;font-size:.62rem;letter-spacing:3px;color:var(--cyan);text-transform:uppercase;margin-bottom:7px;display:flex;align-items:center;gap:7px;}
.pg-eye::before{content:'';width:7px;height:7px;border-radius:50%;background:var(--cyan);box-shadow:0 0 10px var(--cyan);animation:blink 2s infinite;}
@keyframes blink{0%,100%{opacity:1;}50%{opacity:.2;}}
.pg-title{font-size:clamp(1.7rem,4vw,2.8rem);font-weight:700;letter-spacing:-1px;line-height:1;}
.pg-title span{color:var(--cyan);}
.pg-sub{color:var(--mut);font-size:.88rem;margin-top:7px;}
.wallet-chip{background:var(--card);border:1px solid rgba(0,230,118,.2);border-radius:12px;padding:12px 20px;display:flex;align-items:center;gap:14px;min-width:210px;}
.wc-ico{width:40px;height:40px;border-radius:9px;background:rgba(0,230,118,.1);display:flex;align-items:center;justify-content:center;font-size:1.15rem;color:var(--grn);flex-shrink:0;}
.wc-lbl{font-family:'JetBrains Mono',monospace;font-size:.6rem;color:var(--mut);text-transform:uppercase;letter-spacing:1px;margin-bottom:3px;}
.wc-val{font-family:'JetBrains Mono',monospace;font-size:1.22rem;font-weight:700;color:var(--grn);}

/* TOOLBAR */
.toolbar{display:flex;gap:10px;margin-bottom:26px;flex-wrap:wrap;align-items:center;}
.sbox{flex:1;min-width:220px;position:relative;}
.sbox input{width:100%;background:var(--card);border:1px solid var(--brd);color:var(--txt);border-radius:11px;padding:10px 14px 10px 40px;font-size:.86rem;font-family:'Space Grotesk',sans-serif;outline:none;transition:border-color .2s;}
.sbox input:focus{border-color:var(--cyan);}
.sbox input::placeholder{color:var(--mut2);}
.sbox i{position:absolute;left:13px;top:50%;transform:translateY(-50%);color:var(--mut);font-size:.95rem;}
.fp{background:var(--card);border:1px solid var(--brd);color:var(--mut);border-radius:11px;padding:9px 16px;font-size:.8rem;font-weight:600;cursor:pointer;transition:all .18s;font-family:'Space Grotesk',sans-serif;white-space:nowrap;}
.fp:hover,.fp.on{background:rgba(0,212,245,.08);border-color:var(--cyan);color:var(--cyan);}

/* SEC HEAD */
.sec-h{display:flex;align-items:center;gap:10px;margin-bottom:18px;}
.sec-h h3{font-size:1rem;font-weight:700;}
.sec-ln{flex:1;height:1px;background:var(--brd);}
.sec-bd{font-family:'JetBrains Mono',monospace;font-size:.65rem;color:var(--cyan);background:rgba(0,212,245,.08);border:1px solid rgba(0,212,245,.18);padding:3px 9px;border-radius:20px;}

/* TRADERS GRID */
.tgrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(288px,1fr));gap:16px;margin-bottom:48px;}
.tc{background:var(--card);border:1px solid var(--brd);border-radius:var(--r2);overflow:hidden;transition:transform .22s,box-shadow .22s,border-color .22s;}
.tc:hover{transform:translateY(-4px);box-shadow:0 14px 40px rgba(0,212,245,.1);border-color:var(--cyan2);}
.tc-img{position:relative;height:148px;overflow:hidden;background:linear-gradient(135deg,#060f1c,#0a1e35);}
.tc-img img{width:100%;height:100%;object-fit:cover;opacity:.9;}
.tc-ph{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:3.2rem;color:var(--cyan2);opacity:.4;}
.tc-bdg{position:absolute;top:9px;left:9px;font-family:'JetBrains Mono',monospace;font-size:.6rem;font-weight:700;padding:3px 9px;border-radius:20px;backdrop-filter:blur(8px);}
.b-top{background:rgba(0,230,118,.12);border:1px solid rgba(0,230,118,.35);color:var(--grn);}
.b-ver{background:rgba(245,200,66,.1);border:1px solid rgba(245,200,66,.3);color:var(--gold);}
.b-cat{background:rgba(0,212,245,.1);border:1px solid rgba(0,212,245,.25);color:var(--cyan);}
.tc-ret{position:absolute;bottom:9px;right:9px;background:rgba(0,0,0,.72);border:1px solid rgba(0,230,118,.28);border-radius:8px;padding:4px 9px;text-align:right;backdrop-filter:blur(6px);}
.tc-ret .rv{font-family:'JetBrains Mono',monospace;font-size:.9rem;font-weight:700;color:var(--grn);}
.tc-ret .rl{font-size:.58rem;color:var(--mut);text-transform:uppercase;letter-spacing:.5px;}
.tc-body{padding:15px;}
.tc-name{font-size:.96rem;font-weight:700;margin-bottom:2px;}
.tc-cat{font-size:.7rem;color:var(--mut);text-transform:uppercase;letter-spacing:.5px;font-family:'JetBrains Mono',monospace;margin-bottom:12px;}
.tc-sts{display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;margin-bottom:12px;}
.tst{background:var(--bg2);border:1px solid var(--brd);border-radius:8px;padding:7px 5px;text-align:center;}
.tst-v{font-family:'JetBrains Mono',monospace;font-size:.82rem;font-weight:700;}
.tst-l{font-size:.58rem;color:var(--mut);text-transform:uppercase;letter-spacing:.3px;margin-top:2px;}
.cg{color:var(--grn);}.cc{color:var(--cyan);}.cgd{color:var(--gold);}.cr{color:var(--red);}
.tc-tags{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:13px;}
.tc-tag{font-size:.65rem;color:var(--mut);background:var(--bg2);border:1px solid var(--brd);border-radius:20px;padding:3px 8px;font-family:'JetBrains Mono',monospace;}
.copy-btn{width:100%;background:linear-gradient(90deg,var(--cyan),var(--cyan2));border:none;color:#020810;font-weight:700;font-family:'Space Grotesk',sans-serif;font-size:.84rem;padding:10px;border-radius:9px;cursor:pointer;transition:opacity .18s,transform .15s;}
.copy-btn:hover{opacity:.86;transform:scale(1.01);}

/* ACTIVE INVESTMENTS */
.igrid{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:18px;margin-bottom:48px;}
.ic{background:var(--card);border:1px solid var(--brd);border-radius:var(--r2);overflow:hidden;position:relative;}
.ic::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:linear-gradient(90deg,var(--grn),var(--cyan));}
.ic-hdr{padding:14px 16px;border-bottom:1px solid var(--brd);display:flex;align-items:center;gap:11px;}
.ic-av{width:46px;height:46px;border-radius:9px;object-fit:cover;flex-shrink:0;}
.ic-av-ph{width:46px;height:46px;border-radius:9px;background:linear-gradient(135deg,#060f1c,#0a1e35);display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:var(--cyan2);flex-shrink:0;}
.ic-name{font-size:.94rem;font-weight:700;}
.ic-sub{font-size:.7rem;color:var(--mut);font-family:'JetBrains Mono',monospace;margin-top:2px;}
.live-chip{margin-left:auto;display:flex;align-items:center;gap:5px;font-size:.65rem;font-weight:700;color:var(--grn);background:rgba(0,230,118,.07);border:1px solid rgba(0,230,118,.2);border-radius:20px;padding:4px 10px;font-family:'JetBrains Mono',monospace;white-space:nowrap;}
.live-chip::before{content:'';width:6px;height:6px;border-radius:50%;background:var(--grn);box-shadow:0 0 7px var(--grn);animation:blink 1.5s infinite;}
.ic-body{padding:15px 16px;}
.ic-sts{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-bottom:14px;}
.ist{background:var(--bg2);border:1px solid var(--brd);border-radius:9px;padding:9px 11px;}
.ist-v{font-family:'JetBrains Mono',monospace;font-size:.9rem;font-weight:700;margin-bottom:2px;}
.ist-l{font-size:.6rem;color:var(--mut);text-transform:uppercase;letter-spacing:.4px;}
.prog-w{margin-bottom:14px;}
.prog-l{display:flex;justify-content:space-between;font-size:.7rem;color:var(--mut);margin-bottom:5px;font-family:'JetBrains Mono',monospace;}
.prog-t{height:5px;background:var(--brd);border-radius:5px;overflow:hidden;}
.prog-f{height:100%;background:linear-gradient(90deg,var(--cyan),var(--grn));border-radius:5px;}
.ic-acts{display:grid;grid-template-columns:1fr 1fr 1fr;gap:7px;}
.ia{padding:9px 4px;border-radius:8px;border:1px solid;cursor:pointer;font-weight:600;font-size:.7rem;text-align:center;transition:background .18s;line-height:1.3;background:transparent;font-family:'Space Grotesk',sans-serif;}
.ia i{display:block;font-size:1rem;margin-bottom:3px;}
.ia-add{border-color:rgba(0,230,118,.3);color:var(--grn);}
.ia-add:hover{background:rgba(0,230,118,.1);}
.ia-wdl{border-color:rgba(0,212,245,.28);color:var(--cyan);}
.ia-wdl:hover{background:rgba(0,212,245,.08);}
.ia-stop{border-color:rgba(255,64,96,.3);color:var(--red);}
.ia-stop:hover{background:rgba(255,64,96,.08);}

/* OVERLAYS */
.ov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.88);z-index:2000;align-items:center;justify-content:center;backdrop-filter:blur(8px);padding:16px;}
.ov.show{display:flex;}
.mb{background:var(--card);border:1px solid var(--brd2);border-radius:var(--r2);padding:26px;width:100%;max-width:420px;position:relative;animation:pop .28s cubic-bezier(.175,.885,.32,1.275);max-height:92vh;overflow-y:auto;scrollbar-width:thin;scrollbar-color:var(--brd2) transparent;}
@keyframes pop{from{opacity:0;transform:scale(.88) translateY(16px);}to{opacity:1;transform:scale(1) translateY(0);}}
.mclose{position:absolute;top:13px;right:13px;width:28px;height:28px;background:var(--bg2);border:1px solid var(--brd);color:var(--mut);border-radius:50%;font-size:.8rem;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:color .2s;}
.mclose:hover{color:var(--txt);}
.mtit{font-size:1.05rem;font-weight:700;color:var(--cyan);margin-bottom:18px;display:flex;align-items:center;gap:7px;}
.mtr-row{background:var(--bg2);border:1px solid var(--brd);border-radius:11px;padding:12px;display:flex;align-items:center;gap:11px;margin-bottom:16px;}
.mtr-av{width:44px;height:44px;border-radius:9px;background:linear-gradient(135deg,#060f1c,#0a1e35);display:flex;align-items:center;justify-content:center;font-size:1.4rem;color:var(--cyan2);flex-shrink:0;overflow:hidden;}
.mtr-av img{width:100%;height:100%;object-fit:cover;}
.istrip{border-radius:9px;padding:10px 13px;margin-bottom:11px;display:flex;justify-content:space-between;align-items:center;}
.is-g{background:rgba(0,230,118,.07);border:1px solid rgba(0,230,118,.18);}
.is-b{background:rgba(0,212,245,.06);border:1px solid rgba(0,212,245,.18);}
.is-lbl{font-size:.65rem;color:var(--mut);text-transform:uppercase;letter-spacing:.6px;font-family:'JetBrains Mono',monospace;margin-bottom:3px;}
.is-val{font-family:'JetBrains Mono',monospace;font-size:.98rem;font-weight:700;}
.ivg{color:var(--grn);}.ivb{color:var(--cyan);}
.fg{margin-bottom:13px;}
.fl{font-size:.65rem;color:var(--mut);display:block;margin-bottom:5px;text-transform:uppercase;letter-spacing:.6px;font-family:'JetBrains Mono',monospace;}
.fi{width:100%;background:var(--bg2);border:1px solid var(--brd);color:var(--txt);border-radius:9px;padding:11px 13px;font-size:.92rem;font-family:'Space Grotesk',sans-serif;outline:none;transition:border-color .2s;}
.fi:focus{border-color:var(--cyan);}
.fhint{font-size:.67rem;color:var(--mut);margin-top:4px;}
.fhint span{color:var(--cyan);}
.mbtn{width:100%;padding:13px;border:none;border-radius:10px;font-weight:700;font-family:'Space Grotesk',sans-serif;font-size:.9rem;cursor:pointer;margin-top:4px;transition:opacity .18s,transform .15s;letter-spacing:.2px;}
.mbtn:hover{opacity:.86;transform:scale(1.01);}
.mbtn:disabled{opacity:.4;cursor:not-allowed;transform:none;}
.mb-cy{background:linear-gradient(90deg,var(--cyan),var(--cyan2));color:#020810;}
.mb-gn{background:linear-gradient(90deg,var(--grn),var(--grn2));color:#020810;}
.mb-rd{background:var(--red);color:#fff;}

/* Result */
.rc{text-align:center;padding:6px 0;}
.ri{width:66px;height:66px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.8rem;margin:0 auto 16px;}
.ri-ok{background:rgba(0,230,118,.1);color:var(--grn);border:2px solid rgba(0,230,118,.35);}
.ri-er{background:rgba(255,64,96,.1);color:var(--red);border:2px solid rgba(255,64,96,.35);}
.ri-wn{background:rgba(245,200,66,.1);color:var(--gold);border:2px solid rgba(245,200,66,.35);}
.rc h4{font-size:1.1rem;font-weight:700;margin-bottom:7px;}
.rc p{color:var(--mut);font-size:.86rem;line-height:1.6;margin-bottom:20px;}
.dual{display:flex;gap:9px;}
.dual button{flex:1;padding:11px;border-radius:10px;border:none;font-weight:700;font-family:'Space Grotesk',sans-serif;cursor:pointer;font-size:.85rem;transition:opacity .18s;}
.dc{background:var(--bg2);border:1px solid var(--brd)!important;color:var(--mut);}
.dc:hover{color:var(--txt);}
.dd{background:var(--red);color:#fff;}
.dd:hover{opacity:.88;}

/* WITHDRAWAL */
.step-lbl{display:inline-flex;align-items:center;gap:5px;background:rgba(0,212,245,.08);border:1px solid rgba(0,212,245,.2);color:var(--cyan);font-size:.6rem;font-weight:700;padding:3px 10px;border-radius:20px;margin-bottom:11px;font-family:'JetBrains Mono',monospace;letter-spacing:.5px;}
.hl-box{border-radius:10px;padding:12px 14px;margin-bottom:13px;display:flex;align-items:flex-start;gap:11px;}
.hl-g{background:linear-gradient(135deg,rgba(0,230,118,.06),rgba(0,212,245,.04));border:1px solid rgba(0,230,118,.22);}
.hl-ico{font-size:1.4rem;flex-shrink:0;margin-top:1px;}
.hl-txt{font-size:.74rem;color:var(--mut);line-height:1.6;}
.hl-txt strong{color:var(--grn);display:block;font-size:.82rem;margin-bottom:2px;}
.pct-row{display:grid;grid-template-columns:repeat(4,1fr);gap:5px;margin:9px 0 13px;}
.pb{background:var(--bg2);border:1px solid var(--brd);color:var(--mut);font-size:.72rem;font-weight:600;padding:7px 3px;border-radius:7px;cursor:pointer;text-align:center;transition:all .18s;font-family:'JetBrains Mono',monospace;}
.pb:hover,.pb.on{background:rgba(0,212,245,.1);border-color:var(--cyan);color:var(--cyan);}
.rng-w{margin:8px 0 2px;}
.rng-w input[type=range]{width:100%;accent-color:var(--cyan);cursor:pointer;height:4px;}
.rng-l{display:flex;justify-content:space-between;font-size:.65rem;color:var(--mut);margin-top:4px;margin-bottom:12px;font-family:'JetBrains Mono',monospace;}
.fee-box{background:var(--bg2);border:1px solid var(--brd);border-radius:10px;padding:12px 14px;margin-bottom:13px;}
.fbt{font-size:.6rem;text-transform:uppercase;letter-spacing:1px;color:var(--mut);margin-bottom:9px;display:flex;align-items:center;gap:5px;font-family:'JetBrains Mono',monospace;}
.fr{display:flex;justify-content:space-between;font-size:.78rem;padding:4px 0;border-bottom:1px solid rgba(255,255,255,.04);}
.fr:last-child{border-bottom:none;padding-top:8px;margin-top:3px;}
.frl{color:var(--mut);}
.frv{font-weight:700;font-family:'JetBrains Mono',monospace;}
.fv-gd{color:var(--gold);}.fv-cy{color:var(--cyan);}
.fr-tot .frl{color:var(--txt);font-weight:700;}
.adm-note{background:rgba(245,200,66,.05);border:1px solid rgba(245,200,66,.16);border-radius:7px;padding:8px 11px;font-size:.68rem;color:var(--mut);margin-bottom:12px;line-height:1.6;}
.adm-note i{color:var(--gold);}
.close-notice{display:none;background:rgba(0,230,118,.07);border:1px solid rgba(0,230,118,.22);border-radius:7px;padding:8px 11px;font-size:.72rem;color:var(--grn);margin-bottom:12px;}
.div{height:1px;background:var(--brd);margin:11px 0;}

/* CONTRACT */
.cov{display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:3000;align-items:center;justify-content:center;backdrop-filter:blur(10px);padding:16px;}
.cov.show{display:flex;}
.cbox{background:#060e1a;border:1px solid var(--brd2);border-radius:var(--r2);width:100%;max-width:540px;max-height:94vh;overflow-y:auto;animation:pop .3s cubic-bezier(.175,.885,.32,1.275);scrollbar-width:thin;scrollbar-color:var(--brd2) transparent;}
.c-head{background:linear-gradient(135deg,#071525,#0b2240);padding:20px 24px 16px;border-bottom:1px solid var(--brd);border-radius:var(--r2) var(--r2) 0 0;}
.c-bdg{display:inline-flex;align-items:center;gap:5px;background:rgba(0,212,245,.1);border:1px solid rgba(0,212,245,.22);color:var(--cyan);font-size:.58rem;font-weight:700;padding:3px 9px;border-radius:20px;margin-bottom:9px;font-family:'JetBrains Mono',monospace;letter-spacing:1px;}
.c-head h3{font-size:1.1rem;font-weight:700;color:var(--txt);margin-bottom:3px;}
.c-head p{font-size:.75rem;color:var(--mut);}
.c-body{padding:20px 24px;}
.c-sec{margin-bottom:16px;}
.c-stit{font-size:.6rem;text-transform:uppercase;letter-spacing:1.2px;color:var(--mut);font-weight:700;margin-bottom:9px;display:flex;align-items:center;gap:6px;font-family:'JetBrains Mono',monospace;}
.c-stit::after{content:'';flex:1;height:1px;background:var(--brd);}
.c-row{display:flex;justify-content:space-between;align-items:center;padding:7px 0;border-bottom:1px solid rgba(255,255,255,.04);font-size:.82rem;}
.c-row:last-child{border-bottom:none;}
.cr-l{color:var(--mut);}.cr-v{font-weight:600;font-family:'JetBrains Mono',monospace;font-size:.84rem;color:var(--txt);}
.cv-c{color:var(--cyan)!important;}.cv-g{color:var(--grn)!important;}.cv-gd{color:var(--gold)!important;}
.c-tot{background:linear-gradient(135deg,rgba(0,230,118,.06),rgba(0,212,245,.04));border:1px solid rgba(0,230,118,.18);border-radius:12px;padding:16px 18px;margin-bottom:14px;text-align:center;}
.ct-l{font-size:.6rem;text-transform:uppercase;letter-spacing:.8px;color:var(--mut);font-family:'JetBrains Mono',monospace;margin-bottom:5px;}
.ct-a{font-size:1.9rem;font-weight:700;color:var(--cyan);font-family:'JetBrains Mono',monospace;line-height:1;}
.ct-s{font-size:.7rem;color:var(--mut);margin-top:4px;}
.c-note{background:rgba(245,200,66,.04);border:1px solid rgba(245,200,66,.16);border-radius:10px;padding:11px 14px;margin-bottom:13px;font-size:.74rem;color:var(--mut);line-height:1.7;}
.c-note strong{color:var(--gold);}
.c-eml{background:rgba(0,212,245,.05);border:1px solid rgba(0,212,245,.14);border-radius:9px;padding:8px 13px;font-size:.72rem;color:var(--mut);margin-bottom:14px;display:flex;align-items:center;gap:7px;}
.c-eml i{color:var(--cyan);}
.cl-bdg{display:none;align-items:center;gap:6px;background:rgba(0,230,118,.08);border:1px solid rgba(0,230,118,.25);color:var(--grn);font-size:.67rem;font-weight:700;padding:5px 12px;border-radius:20px;margin-bottom:12px;font-family:'JetBrains Mono',monospace;}
.c-acts{display:flex;gap:9px;}
.c-acts button{flex:1;padding:13px;border-radius:11px;border:none;font-weight:700;font-family:'Space Grotesk',sans-serif;cursor:pointer;font-size:.86rem;transition:opacity .18s;}
.c-acts button:disabled{opacity:.4;cursor:not-allowed;}
.c-back{background:var(--card2);border:1px solid var(--brd)!important;color:var(--mut);flex:.5!important;}
.c-back:hover{color:var(--txt);}
.c-cfm{background:linear-gradient(90deg,var(--grn),var(--grn2));color:#020810;}
.c-cfm:hover{opacity:.88;}

/* TOAST */
.tw{position:fixed;bottom:22px;right:22px;z-index:9999;display:flex;flex-direction:column;gap:8px;}
.ti{background:var(--card);border:1px solid var(--brd2);color:var(--txt);padding:10px 16px;border-radius:10px;font-size:.83rem;display:flex;align-items:center;gap:8px;box-shadow:0 4px 20px rgba(0,0,0,.5);animation:slIn .3s ease forwards;max-width:290px;}
.ti-s{border-left:3px solid var(--grn);}.ti-e{border-left:3px solid var(--red);}
@keyframes slIn{from{opacity:0;transform:translateX(50px);}to{opacity:1;transform:translateX(0);}}
.empty{text-align:center;padding:44px 20px;color:var(--mut);grid-column:1/-1;}
.empty i{font-size:2.6rem;display:block;margin-bottom:10px;opacity:.3;}
@media(max-width:600px){.pg-head{flex-direction:column;align-items:flex-start;}.wallet-chip{width:100%;}}
</style>
</head>
<body>
<div class="wrap">

<!-- PAGE HEADER -->
<div class="pg-head">
  <div>
    <div class="pg-eye">SwiftTrade Platform — Live</div>
    <h1 class="pg-title">Copy <span>Trading</span></h1>
    <p class="pg-sub">Mirror elite traders automatically — start earning in minutes</p>
  </div>
  <div class="wallet-chip">
    <div class="wc-ico"><i class="bi bi-wallet2"></i></div>
    <div>
      <div class="wc-lbl">Available Balance</div>
      <div class="wc-val">$<?= number_format($wallet, 2) ?></div>
    </div>
  </div>
</div>

<!-- TOOLBAR -->
<div class="toolbar">
  <div class="sbox">
    <i class="bi bi-search"></i>
    <input type="text" id="searchInput" placeholder="Search traders by name or category…">
  </div>
  <div class="fp on"  onclick="doFilter('all',this)">All</div>
  <div class="fp" onclick="doFilter('forex',this)">Forex</div>
  <div class="fp" onclick="doFilter('crypto',this)">Crypto</div>
  <div class="fp" onclick="doFilter('stocks',this)">Stocks</div>
</div>

<!-- TRADERS GRID -->
<div class="sec-h">
  <h3>Top Performing Traders</h3>
  <div class="sec-ln"></div>
  <div class="sec-bd"><?= count($traders) ?> Active</div>
</div>
<div class="tgrid" id="traderList">
<?php foreach ($traders as $idx => $t):
  $tn   = htmlspecialchars($t['display_name']);
  $tcat = htmlspecialchars($t['category'] ?? 'General');
  $tret = (float)$t['monthly_return'];
  $twr  = (float)($t['win_rate'] ?? 0);
  $trisk= (int)($t['risk_score'] ?? 0);
  $tfol = (int)($t['followers'] ?? 0);
  $tmin = (float)($t['min_deposit'] ?? 10);
  $tdur = (int)($t['duration_days'] ?? 30);
  $timg = !empty($t['profile_image']) ? htmlspecialchars($t['profile_image']) : '';
  $rlbl = $trisk<=3?'Low':($trisk<=6?'Med':'High');
  $rcls = $trisk<=3?'cg':($trisk<=6?'cgd':'cr');
?>
<div class="tc trader-item" data-name="<?= strtolower($tn) ?>" data-cat="<?= strtolower($tcat) ?>">
  <div class="tc-img">
    <?php if ($timg): ?><img src="<?= $timg ?>" alt="">
    <?php else: ?><div class="tc-ph"><i class="bi bi-person-circle"></i></div><?php endif; ?>
    <?php if ($idx===0): ?><div class="tc-bdg b-top">#1 TRADER</div>
    <?php elseif (!empty($t['verified'])): ?><div class="tc-bdg b-ver">✓ VERIFIED</div>
    <?php else: ?><div class="tc-bdg b-cat"><?= strtoupper($tcat) ?></div><?php endif; ?>
    <div class="tc-ret"><div class="rv">+<?= $tret ?>%</div><div class="rl">Monthly</div></div>
  </div>
  <div class="tc-body">
    <div class="tc-name"><?= $tn ?></div>
    <div class="tc-cat"><?= $tcat ?></div>
    <div class="tc-sts">
      <div class="tst"><div class="tst-v <?= $tret>=0?'cg':'cr' ?>"><?= $tret ?>%</div><div class="tst-l">Monthly</div></div>
      <div class="tst"><div class="tst-v cgd"><?= $twr ?>%</div><div class="tst-l">Win Rate</div></div>
      <div class="tst"><div class="tst-v <?= $rcls ?>"><?= $rlbl ?></div><div class="tst-l">Risk</div></div>
    </div>
    <div class="tc-tags">
      <span class="tc-tag"><i class="bi bi-clock me-1"></i><?= $tdur ?>d</span>
      <span class="tc-tag"><i class="bi bi-people me-1"></i><?= number_format($tfol) ?></span>
      <span class="tc-tag">Min $<?= number_format($tmin,0) ?></span>
    </div>
    <button class="copy-btn"
      data-id="<?= (int)$t['id'] ?>"
      data-name="<?= htmlspecialchars($tn,ENT_QUOTES) ?>"
      data-img="<?= htmlspecialchars($timg,ENT_QUOTES) ?>"
      data-min="<?= $tmin ?>"
      onclick="openCopy(this)">
      <i class="bi bi-lightning-charge-fill me-1"></i> Copy This Trader
    </button>
  </div>
</div>
<?php endforeach; ?>
<?php if(empty($traders)): ?><div class="empty"><i class="bi bi-graph-up-arrow"></i><p>No active traders right now.</p></div><?php endif; ?>
</div>

<!-- ACTIVE INVESTMENTS -->
<?php if (!empty($active)): ?>
<div class="sec-h">
  <h3>My Active Investments</h3>
  <div class="sec-ln"></div>
  <div class="sec-bd"><?= count($active) ?> Running</div>
</div>
<div class="igrid">
<?php foreach ($active as $inv):
  $iimg = !empty($inv['profile_image']) ? htmlspecialchars($inv['profile_image']) : '';
?>
<div class="ic">
  <div class="ic-hdr">
    <?php if ($iimg): ?><img src="<?= $iimg ?>" class="ic-av" alt="">
    <?php else: ?><div class="ic-av-ph"><i class="bi bi-person-circle"></i></div><?php endif; ?>
    <div style="flex:1;min-width:0">
      <div class="ic-name"><?= htmlspecialchars($inv['display_name']) ?></div>
      <div class="ic-sub">Invested: $<?= number_format($inv['_inv_amt'],2) ?></div>
    </div>
    <div class="live-chip">LIVE</div>
  </div>
  <div class="ic-body">
    <div class="ic-sts">
      <div class="ist"><div class="ist-v cg">+$<?= number_format($inv['_profit'],2) ?></div><div class="ist-l">Current Profit</div></div>
      <div class="ist"><div class="ist-v cc"><?= $inv['_days'] ?>/<?= $inv['_total_days'] ?>d</div><div class="ist-l">Duration</div></div>
      <div class="ist"><div class="ist-v" style="font-size:.76rem"><?= date('M d, Y',strtotime($inv['created_at'])) ?></div><div class="ist-l">Opened</div></div>
      <div class="ist"><div class="ist-v cgd">$<?= number_format($inv['_fee20'],2) ?></div><div class="ist-l">Fee (20%)</div></div>
    </div>
    <div class="prog-w">
      <div class="prog-l"><span><i class="bi bi-arrow-repeat me-1"></i>Copy Trading Active</span><span><?= $inv['_progress'] ?>%</span></div>
      <div class="prog-t"><div class="prog-f" style="width:<?= $inv['_progress'] ?>%"></div></div>
    </div>
    <div class="ic-acts">

      <!-- ADD FUNDS: data attributes only, no inline math -->
      <button class="ia ia-add"
        data-tid="<?= (int)$inv['id'] ?>"
        data-inv="<?= $inv['_inv_amt'] ?>"
        onclick="openFund(this)">
        <i class="bi bi-plus-circle"></i>Add Funds
      </button>

      <!-- WITHDRAW: all values as data attributes -->
      <button class="ia ia-wdl"
        data-tid="<?= (int)$inv['id'] ?>"
        data-inv="<?= $inv['_inv_amt'] ?>"
        data-profit="<?= $inv['_profit'] ?>"
        data-fee="<?= $inv['_fee20'] ?>"
        data-total="<?= $inv['_full_total'] ?>"
        data-tname="<?= htmlspecialchars($inv['display_name'],ENT_QUOTES) ?>"
        onclick="openWdl(this)">
        <i class="bi bi-arrow-up-circle"></i>Withdraw
      </button>

      <!-- STOP: includes total so we can credit wallet -->
      <button class="ia ia-stop"
        data-tid="<?= (int)$inv['id'] ?>"
        data-total="<?= $inv['_full_total'] ?>"
        data-tname="<?= htmlspecialchars($inv['display_name'],ENT_QUOTES) ?>"
        onclick="openStop(this)">
        <i class="bi bi-stop-circle"></i>Stop Trade
      </button>

    </div>
  </div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</div><!-- /wrap -->

<!-- ════ COPY MODAL ════ -->
<div class="ov" id="ov-copy">
  <div class="mb">
    <button class="mclose" onclick="closeOv('ov-copy')">✕</button>
    <div class="mtit"><i class="bi bi-activity"></i> Start Copy Trading</div>
    <div class="mtr-row">
      <div class="mtr-av" id="copy-av"><i class="bi bi-person-circle" style="font-size:1.8rem"></i></div>
      <div>
        <div style="font-weight:700;font-size:.95rem" id="copy-name">—</div>
        <div style="font-size:.7rem;color:var(--mut);font-family:'JetBrains Mono',monospace">Copy Trader</div>
      </div>
    </div>
    <div class="istrip is-g">
      <div><div class="is-lbl">Wallet Balance</div><div class="is-val ivg">$<?= number_format($wallet,2) ?></div></div>
      <i class="bi bi-wallet2" style="color:var(--grn);font-size:1.3rem"></i>
    </div>
    <div class="fg">
      <label class="fl">Amount to Invest (USD)</label>
      <input type="number" class="fi" id="copy-amount" placeholder="0.00" min="1" step="0.01">
      <div class="fhint">Minimum: <span id="copy-min">$10.00</span></div>
    </div>
    <input type="hidden" id="copy-lid">
    <button class="mbtn mb-cy" id="copy-btn" onclick="submitCopy()">
      <i class="bi bi-lightning-charge-fill me-1"></i> Activate Trade
    </button>
  </div>
</div>

<!-- ════ RESULT MODAL ════ -->
<div class="ov" id="ov-result">
  <div class="mb rc">
    <div id="r-icon" class="ri ri-ok"><i class="bi bi-check-lg"></i></div>
    <h4 id="r-title">Done!</h4>
    <p id="r-msg">Operation completed.</p>
    <button class="mbtn mb-cy" id="r-btn" onclick="closeResult()">Got it</button>
  </div>
</div>

<!-- ════ ADD FUNDS MODAL ════ -->
<div class="ov" id="ov-fund">
  <div class="mb">
    <button class="mclose" onclick="closeOv('ov-fund')">✕</button>
    <div class="mtit"><i class="bi bi-plus-circle"></i> Add Funds</div>
    <input type="hidden" id="fund-tid">
    <div class="istrip is-g">
      <div><div class="is-lbl">Wallet Balance</div><div class="is-val ivg">$<?= number_format($wallet,2) ?></div></div>
      <i class="bi bi-wallet2" style="color:var(--grn);font-size:1.3rem"></i>
    </div>
    <div class="istrip is-b">
      <div><div class="is-lbl">Currently Invested</div><div class="is-val ivb" id="fund-inv">$0.00</div></div>
      <i class="bi bi-graph-up" style="color:var(--cyan);font-size:1.3rem"></i>
    </div>
    <div class="fg">
      <label class="fl">Amount to Add (USD)</label>
      <input type="number" class="fi" id="fund-amount" placeholder="0.00" min="0.01" step="0.01">
    </div>
    <button class="mbtn mb-gn" id="fund-btn" onclick="submitFund()">
      <i class="bi bi-plus-circle me-1"></i> Add Funds
    </button>
  </div>
</div>

<!-- ════ STOP CONFIRM ════ -->
<div class="ov" id="ov-stop">
  <div class="mb rc">
    <div class="ri ri-wn"><i class="bi bi-exclamation-triangle"></i></div>
    <h4>Stop This Trade?</h4>
    <p>Stopping <strong id="stop-name" style="color:var(--txt)"></strong> will close the trade and credit <strong id="stop-total" style="color:var(--grn)"></strong> (principal + profit + fees) back to your wallet.</p>
    <input type="hidden" id="stop-tid">
    <input type="hidden" id="stop-total-val">
    <div class="dual">
      <button class="dc" onclick="closeOv('ov-stop')">Cancel</button>
      <button class="dd" id="stop-btn" onclick="confirmStop()">Yes, Stop &amp; Receive Funds</button>
    </div>
  </div>
</div>

<!-- ════ WITHDRAWAL STEP 1 ════ -->
<div class="ov" id="ov-wdl">
  <div class="mb" style="max-width:440px">
    <button class="mclose" onclick="closeOv('ov-wdl')">✕</button>
    <div class="step-lbl"><i class="bi bi-1-circle-fill me-1"></i> STEP 1 OF 2 — CHOOSE AMOUNT</div>
    <div class="mtit"><i class="bi bi-arrow-up-circle"></i> Withdrawal Request</div>

    <div class="hl-box hl-g">
      <div class="hl-ico">💰</div>
      <div class="hl-txt">
        <strong>Withdraw Everything — Principal + Profit + Fees</strong>
        Any amount up to your total. 100% automatically closes this trade.
      </div>
    </div>

    <div class="istrip is-b">
      <div><div class="is-lbl">Total Withdrawable (Principal + Profit + Fees)</div><div class="is-val ivb" id="w-port">$0.00</div></div>
      <i class="bi bi-graph-up-arrow" style="color:var(--cyan);font-size:1.3rem"></i>
    </div>

    <div class="fg">
      <label class="fl">Amount to Withdraw (USD)</label>
      <input type="number" class="fi" id="w-amt" placeholder="0.00" min="0.01" step="0.01" oninput="wCalc()">
      <div class="fhint">Total available: <span id="w-hint">$0.00</span></div>
    </div>

    <div class="rng-w">
      <input type="range" id="w-slider" min="0" max="100" step="0.01" value="100" oninput="wSlider()">
    </div>
    <div class="rng-l"><span>$0</span><span id="w-max">$0.00</span></div>

    <div class="pct-row">
      <div class="pb" onclick="wPct(25,this)">25%</div>
      <div class="pb" onclick="wPct(50,this)">50%</div>
      <div class="pb" onclick="wPct(75,this)">75%</div>
      <div class="pb on" onclick="wPct(100,this)">100% ALL</div>
    </div>

    <div class="adm-note">
      <i class="bi bi-info-circle"></i>
      Fees below are <strong style="color:var(--gold)">for reference only</strong> — not deducted from your payout.
    </div>

    <div class="fee-box">
      <div class="fbt"><i class="bi bi-receipt" style="color:var(--gold)"></i> Settlement Summary</div>
      <div class="fr"><span class="frl">Gross Payout</span><span class="frv fv-cy" id="fp-amt">$0.00</span></div>
      <div class="fr"><span class="frl">Performance Fee (20%)</span><span class="frv fv-gd" id="fp-perf">+$0.00</span></div>
      <div class="fr"><span class="frl">Network Fee</span><span class="frv fv-gd">+$2.50</span></div>
      <div class="div"></div>
      <div class="fr fr-tot"><span class="frl">You Receive</span><span class="frv fv-cy" id="fp-net">$0.00</span></div>
    </div>

    <div class="close-notice" id="w-close-note">
      <i class="bi bi-check-circle me-1"></i>
      <strong>Full withdrawal selected</strong> — trade closes automatically after processing.
    </div>

    <!-- State hidden fields — populated by openWdl() -->
    <input type="hidden" id="w-tid">
    <input type="hidden" id="w-tname">
    <input type="hidden" id="w-total">
    <input type="hidden" id="w-profit">
    <input type="hidden" id="w-fee">

    <button class="mbtn mb-cy" onclick="wProceed()">
      <i class="bi bi-arrow-right-circle me-1"></i> Review Settlement Contract →
    </button>
  </div>
</div>

<!-- ════ CONTRACT STEP 2 ════ -->
<div class="cov" id="ov-contract">
  <div class="cbox">
    <div class="c-head">
      <div class="c-bdg"><i class="bi bi-shield-check me-1"></i> SECURE SETTLEMENT — STEP 2 OF 2</div>
      <h3>Withdrawal &amp; Settlement Summary</h3>
      <p>Review carefully before confirming. This is your official settlement record.</p>
    </div>
    <div class="c-body">

      <div class="cl-bdg" id="ct-close-bdg">
        <i class="bi bi-x-circle-fill"></i> Full Withdrawal — Trade auto-closes after processing
      </div>

      <div class="c-sec">
        <div class="c-stit"><i class="bi bi-person-badge" style="color:var(--cyan)"></i> Account Overview</div>
        <div class="c-row"><span class="cr-l">Account Holder</span><span class="cr-v" id="ct-user">—</span></div>
        <div class="c-row"><span class="cr-l">Reference</span><span class="cr-v cv-c" id="ct-ref">—</span></div>
        <div class="c-row"><span class="cr-l">Timestamp</span><span class="cr-v" id="ct-ts">—</span></div>
        <div class="c-row"><span class="cr-l">Strategy</span><span class="cr-v" id="ct-trader">—</span></div>
      </div>

      <div class="c-sec">
        <div class="c-stit"><i class="bi bi-graph-up-arrow" style="color:var(--grn)"></i> Portfolio Breakdown</div>
        <div class="c-row"><span class="cr-l">Total Withdrawable</span><span class="cr-v" id="ct-port">$0.00</span></div>
        <div class="c-row"><span class="cr-l">You Are Withdrawing</span><span class="cr-v" id="ct-req">$0.00</span></div>
        <div class="c-row"><span class="cr-l">Includes Principal</span><span class="cr-v cv-c" id="ct-prin">$0.00</span></div>
        <div class="c-row"><span class="cr-l">Includes Profit</span><span class="cr-v cv-g" id="ct-prof">$0.00</span></div>
        <div class="c-row"><span class="cr-l">Includes Trading Fees</span><span class="cr-v cv-gd" id="ct-fee">$0.00</span></div>
      </div>

      <div class="c-sec">
        <div class="c-stit"><i class="bi bi-receipt" style="color:var(--gold)"></i> Settlement Fees (Reference Only)</div>
        <div class="c-row"><span class="cr-l">Performance Fee (20%)</span><span class="cr-v cv-gd" id="ct-pfee">+$0.00</span></div>
        <div class="c-row"><span class="cr-l">Accrued Trading Fees</span><span class="cr-v cv-gd" id="ct-tfee">+$0.00</span></div>
        <div class="c-row"><span class="cr-l">Network Fee</span><span class="cr-v cv-gd">+$2.50</span></div>
      </div>

      <div class="div"></div>

      <div class="c-tot">
        <div class="ct-l">Final Withdrawal Amount</div>
        <div class="ct-a" id="ct-final">$0.00</div>
        <div class="ct-s">Full amount you receive — principal, profit, and fees included.</div>
      </div>

      <div class="c-note">
        <strong>⚠ Notice:</strong> Settlement fees are paid separately and NOT deducted from your payout. Your withdrawal will be processed upon admin verification.
      </div>

      <div class="c-eml">
        <i class="bi bi-envelope-check-fill"></i>
        Confirmation will be sent to <strong id="ct-email" style="color:var(--cyan)"></strong>
      </div>

      <input type="hidden" id="ct-tid">
      <input type="hidden" id="ct-amt">
      <input type="hidden" id="ct-isfull" value="0">

      <div class="c-acts">
        <button type="button" class="c-back" onclick="backToWdl()">
          <i class="bi bi-arrow-left me-1"></i> Back
        </button>
        <button type="button" class="c-cfm" id="ct-cfm-btn" onclick="confirmWdl()">
          <i class="bi bi-check-circle-fill me-1"></i> Confirm &amp; Submit
        </button>
      </div>
    </div>
  </div>
</div>

<div class="tw" id="toastWrap"></div>

<script>
/* ─── PHP constants passed safely via json_encode ─── */
const WALLET  = <?= json_encode((float)$wallet) ?>;
const U_NAME  = <?= json_encode($user_name) ?>;
const U_EMAIL = <?= json_encode($user_email) ?>;

/* ─── Withdrawal state object ─── */
let W = { tid:'', tname:'', inv:0, profit:0, fee:0, total:0 };

/* ─── Helpers ─── */
const el   = id => document.getElementById(id);
const fmt  = n  => {
  const v = parseFloat(n);
  return '$' + (isNaN(v) ? '0.00' : v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}));
};
const genRef = () => '#WDL-' + (Math.floor(Math.random()*90000)+10000) + '-' + new Date().getFullYear();
const fmtNow = () => {
  const d = new Date();
  return d.toLocaleDateString('en-US',{month:'long',day:'numeric',year:'numeric'})
       + ' | ' + d.toLocaleTimeString('en-US',{hour:'2-digit',minute:'2-digit'});
};

/* ─── Search ─── */
el('searchInput').addEventListener('keyup', e => {
  const t = e.target.value.toLowerCase();
  document.querySelectorAll('.trader-item').forEach(c =>
    c.style.display = (c.dataset.name.includes(t) || c.dataset.cat.includes(t)) ? '' : 'none'
  );
});

/* ─── Filter pills ─── */
function doFilter(cat, btn) {
  document.querySelectorAll('.fp').forEach(p => p.classList.remove('on'));
  btn.classList.add('on');
  document.querySelectorAll('.trader-item').forEach(c =>
    c.style.display = (cat === 'all' || c.dataset.cat.includes(cat)) ? '' : 'none'
  );
}

/* ─── Overlay helpers ─── */
function closeOv(id) { el(id).classList.remove('show'); }
document.querySelectorAll('.ov').forEach(o =>
  o.addEventListener('click', e => { if (e.target === o) o.classList.remove('show'); })
);
el('ov-contract').addEventListener('click', e => {
  if (e.target === el('ov-contract')) closeOv('ov-contract');
});

/* ══════════════════ COPY TRADE ══════════════════ */
function openCopy(btn) {
  el('copy-lid').value         = btn.dataset.id;
  el('copy-name').textContent  = btn.dataset.name;
  el('copy-min').textContent   = '$' + parseFloat(btn.dataset.min||10).toFixed(2);
  el('copy-amount').value      = '';
  el('copy-amount').min        = btn.dataset.min || 10;
  const av = el('copy-av');
  av.innerHTML = btn.dataset.img
    ? `<img src="${btn.dataset.img}" style="width:100%;height:100%;object-fit:cover;border-radius:9px">`
    : `<i class="bi bi-person-circle" style="font-size:1.8rem"></i>`;
  el('ov-copy').classList.add('show');
}

async function submitCopy() {
  const id     = el('copy-lid').value;
  const amount = parseFloat(el('copy-amount').value);
  const min    = parseFloat(el('copy-amount').min);
  if (!amount || amount < min)  { toast(`Minimum deposit is ${fmt(min)}`, 'e'); return; }
  if (amount > WALLET)          { closeOv('ov-copy'); showResult('error','Insufficient Balance',`Wallet: ${fmt(WALLET)} — Need: ${fmt(amount)}`); return; }
  const btn = el('copy-btn');
  btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Processing…';
  try {
    const r = await fetch('copy_action.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({leader_id:id,amount})});
    const d = await r.json();
    closeOv('ov-copy');
    d.success ? showResult('success','Trade Activated!','Your copy trade is now live.') : showResult('error','Trade Failed',d.message||'Something went wrong.');
  } catch(e) { closeOv('ov-copy'); showResult('error','Connection Error','Could not reach server.'); }
  btn.disabled = false; btn.innerHTML = '<i class="bi bi-lightning-charge-fill me-1"></i> Activate Trade';
}

/* ══════════════════ RESULT ══════════════════ */
function showResult(type, title, msg) {
  const map = {
    success: ['ri-ok','bi-check-lg','mb-cy'],
    error:   ['ri-er','bi-x-lg','mb-rd'],
    warning: ['ri-wn','bi-exclamation','mb-cy']
  };
  const [ic,ico,bc] = map[type] || map.error;
  el('r-icon').className       = `ri ${ic}`;
  el('r-icon').innerHTML       = `<i class="bi ${ico}"></i>`;
  el('r-title').textContent    = title;
  el('r-msg').textContent      = msg;
  el('r-btn').className        = `mbtn ${bc}`;
  el('ov-result').classList.add('show');
}
function closeResult() { closeOv('ov-result'); location.reload(); }

/* ══════════════════ ADD FUNDS ══════════════════ */
function openFund(btn) {
  el('fund-tid').value         = btn.dataset.tid;
  el('fund-inv').textContent   = fmt(parseFloat(btn.dataset.inv) || 0);
  el('fund-amount').value      = '';
  el('ov-fund').classList.add('show');
}

async function submitFund() {
  const tid    = el('fund-tid').value;
  const amount = parseFloat(el('fund-amount').value);
  if (!amount || amount <= 0)  { toast('Enter a valid amount', 'e'); return; }
  if (amount > WALLET)         { toast(`Insufficient balance (${fmt(WALLET)})`, 'e'); return; }
  const btn = el('fund-btn');
  btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Processing…';
  try {
    const r = await fetch('add_copy_funds.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({trade_id:tid,amount})});
    const d = await r.json();
    closeOv('ov-fund');
    d.success ? showResult('success','Funds Added!',d.message) : showResult('error','Failed',d.message);
  } catch(e) { closeOv('ov-fund'); showResult('error','Connection Error','Could not reach server.'); }
  btn.disabled = false; btn.innerHTML = '<i class="bi bi-plus-circle me-1"></i> Add Funds';
}

/* ══════════════════ STOP TRADE ══════════════════
   Stops trade AND credits full total to wallet
═════════════════════════════════════════════════ */
function openStop(btn) {
  const total = parseFloat(btn.dataset.total) || 0;
  el('stop-tid').value         = btn.dataset.tid;
  el('stop-total-val').value   = total;
  el('stop-name').textContent  = btn.dataset.tname;
  el('stop-total').textContent = fmt(total);
  el('ov-stop').classList.add('show');
}

async function confirmStop() {
  const tid   = el('stop-tid').value;
  const total = parseFloat(el('stop-total-val').value) || 0;
  const btn   = el('stop-btn');
  btn.disabled = true; btn.textContent = 'Processing…';

  try {
    /* Step 1: credit wallet with full total FIRST */
    if (total > 0) {
      const wr = await fetch('withdraw_copy.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({trade_id:tid, amount:total})});
      let wd;
      try { wd = await wr.json(); } catch(e) { throw new Error('Payout server error.'); }
      if (!wd.success) throw new Error(wd.message || 'Payout failed.');
    }
    /* Step 2: stop the trade */
    const sr = await fetch('stop_copy.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:tid})});
    let sd;
    try { sd = await sr.json(); } catch(e) { throw new Error('Stop server error.'); }
    if (!sd.success) throw new Error(sd.message || 'Trade stop failed.');

    closeOv('ov-stop');
    showResult('success','Trade Closed!',`${fmt(total)} (principal + profit + fees) has been credited to your wallet.`);
  } catch(e) {
    closeOv('ov-stop');
    showResult('error','Failed', e.message);
  }
  btn.disabled = false; btn.textContent = 'Yes, Stop & Receive Funds';
}

/* ══════════════════════════════════════════════════
   WITHDRAWAL FLOW
   Key fix: ALL data comes from btn.dataset (set by PHP
   via data attributes). No inline onclick math at all.
═══════════════════════════════════════════════════ */
function openWdl(btn) {
  /* Read from data attributes — PHP already computed these */
  W.tid    = btn.dataset.tid;
  W.tname  = btn.dataset.tname;
  W.inv    = parseFloat(btn.dataset.inv)    || 0;
  W.profit = parseFloat(btn.dataset.profit) || 0;
  W.fee    = parseFloat(btn.dataset.fee)    || 0;
  W.total  = parseFloat(btn.dataset.total)  || 0;

  /* Fallback: recompute if total is somehow 0 */
  if (W.total <= 0) {
    W.total = parseFloat((W.inv + W.profit + W.fee).toFixed(2));
  }

  /* Save to hidden fields */
  el('w-tid').value    = W.tid;
  el('w-tname').value  = W.tname;
  el('w-total').value  = W.total;
  el('w-profit').value = W.profit;
  el('w-fee').value    = W.fee;

  /* UI */
  el('w-port').textContent = fmt(W.total);
  el('w-hint').textContent = fmt(W.total);
  el('w-max').textContent  = fmt(W.total);
  el('w-slider').max       = W.total;
  el('w-slider').value     = W.total;

  /* Default to 100% */
  el('w-amt').value = W.total.toFixed(2);
  document.querySelectorAll('.pb').forEach(b => b.classList.remove('on'));
  document.querySelectorAll('.pb')[3].classList.add('on');

  wCalc();
  el('ov-wdl').classList.add('show');
}

function wSlider() {
  el('w-amt').value = parseFloat(el('w-slider').value).toFixed(2);
  document.querySelectorAll('.pb').forEach(b => b.classList.remove('on'));
  wCalc();
}

function wPct(pct, btn) {
  const a = parseFloat((W.total * pct / 100).toFixed(2));
  el('w-amt').value     = a;
  el('w-slider').value  = a;
  document.querySelectorAll('.pb').forEach(b => b.classList.remove('on'));
  btn.classList.add('on');
  wCalc();
}

function wCalc() {
  let a   = parseFloat(el('w-amt').value) || 0;
  const t = W.total;

  /* Hard clamp — impossible to exceed total */
  if (a > t) { a = t; el('w-amt').value = t.toFixed(2); }

  const share = t > 0 ? (a / t) : 0;
  const perf  = parseFloat((W.profit * share * 20 / 100).toFixed(2));

  el('fp-amt').textContent  = fmt(a);
  el('fp-perf').textContent = '+' + fmt(perf);
  el('fp-net').textContent  = fmt(a);  /* user gets full amount — fees paid separately */

  el('w-slider').value = a;

  /* Show "full" notice within $0.02 float tolerance */
  el('w-close-note').style.display = (t > 0 && a >= t - 0.02) ? 'block' : 'none';
}

function wProceed() {
  let a    = parseFloat(el('w-amt').value) || 0;
  const t  = W.total;

  if (a <= 0) { toast('Enter a withdrawal amount', 'e'); return; }
  if (t <= 0) { toast('No balance available to withdraw', 'e'); return; }
  if (a > t)   a = t;  /* final clamp */

  const share  = a / t;
  const perf   = parseFloat((W.profit * share * 20 / 100).toFixed(2));
  const tFee   = parseFloat((W.fee * share).toFixed(2));
  const isFull = a >= (t - 0.02);

  /* Contract hidden state */
  el('ct-tid').value    = W.tid;
  el('ct-amt').value    = a.toFixed(2);
  el('ct-isfull').value = isFull ? '1' : '0';

  /* Contract display */
  el('ct-user').textContent   = U_NAME;
  el('ct-ref').textContent    = genRef();
  el('ct-ts').textContent     = fmtNow();
  el('ct-trader').textContent = W.tname;
  el('ct-port').textContent   = fmt(t);
  el('ct-req').textContent    = fmt(a);
  el('ct-prin').textContent   = fmt(W.inv    * share);
  el('ct-prof').textContent   = fmt(W.profit * share);
  el('ct-fee').textContent    = fmt(W.fee    * share);
  el('ct-pfee').textContent   = '+' + fmt(perf);
  el('ct-tfee').textContent   = '+' + fmt(tFee);
  el('ct-final').textContent  = fmt(a);
  el('ct-email').textContent  = U_EMAIL;

  el('ct-close-bdg').style.display = isFull ? 'flex' : 'none';

  closeOv('ov-wdl');
  el('ov-contract').classList.add('show');
}

function backToWdl() {
  closeOv('ov-contract');
  el('ov-wdl').classList.add('show');
}

async function confirmWdl() {
  const tid    = el('ct-tid').value;
  const amount = parseFloat(el('ct-amt').value);
  const isFull = el('ct-isfull').value === '1';

  if (!tid || isNaN(amount) || amount <= 0) { toast('Invalid withdrawal amount', 'e'); return; }

  const btn = el('ct-cfm-btn');
  btn.disabled = true; btn.innerHTML = '<i class="bi bi-hourglass-split me-1"></i> Processing...';

  try {
    const r = await fetch('withdraw_copy.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({trade_id:tid, amount:amount})
    });

    let d;
    try { d = await r.json(); }
    catch(e) { throw new Error('Server returned invalid response.'); }

    closeOv('ov-contract');

    if (d.success) {
      if (isFull) {
        try {
          await fetch('stop_copy.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id:tid})});
        } catch(e) { /* silent */ }
        showResult('success','Trade Closed!','Full withdrawal processed and copy trade automatically closed.');
      } else {
        showResult('success','Withdrawal Submitted!', d.message || 'Your withdrawal is being processed.');
      }
      setTimeout(() => location.reload(), 2800);
    } else {
      showResult('error','Failed', d.message || 'Something went wrong.');
    }
  } catch(e) {
    showResult('error','Connection Error', e.message);
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-check-circle-fill me-1"></i> Confirm &amp; Submit';
  }
}

/* ──── TOAST ──── */
function toast(msg, type='s') {
  const w  = el('toastWrap');
  const t  = document.createElement('div');
  t.className = `ti ti-${type}`;
  t.innerHTML = `<i class="bi bi-${type==='s'?'check-circle':'exclamation-circle'}"></i> ${msg}`;
  w.appendChild(t);
  setTimeout(() => t.remove(), 3200);
}
</script>
</body>
</html>