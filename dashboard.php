<?php

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

// Rest of your code starts here...
session_start();
require_once 'db.php';

if (!isset($_SESSION['user_id'])) { header("Location: login.php"); exit; }

$user_id = $_SESSION['user_id'];
/* --- AUTO-SETTLEMENT ENGINE --- */
$current_time = date('Y-m-d H:i:s');
$check_matured = $conn->query("
    SELECT ui.*, ip.roi, ip.duration 
    FROM user_investments ui 
    JOIN investment_plans ip ON ui.plan_id = ip.id 
    WHERE ui.status = 'active' 
    AND DATE_ADD(ui.start_date, INTERVAL ip.duration DAY) <= '$current_time'
");

while ($matured = $check_matured->fetch_assoc()) {
    $profit = $matured['amount'] * ($matured['roi'] / 100);
    $total_return = $matured['amount'] + $profit;
    
    // 1. Add funds to user balance
    $conn->query("UPDATE users SET balance = balance + $total_return WHERE id = " . $matured['user_id']);
    
    // 2. Mark investment as completed
    $conn->query("UPDATE user_investments SET status = 'completed' WHERE id = " . $matured['id']);
}
/* ======================
    ENHANCED DATA FETCHING
====================== */
// Fetch User Info
$stmt_u = $conn->prepare("SELECT name, balance, kyc_status, account_tier FROM users WHERE id = ?");
$stmt_u->bind_param("i", $user_id);
$stmt_u->execute();
$user_data = $stmt_u->get_result()->fetch_assoc();

$balance = $user_data['balance'] ?? 0.00;
$user_name = $user_data['user_name'] ?? 'Investor';
$kyc_status = $user_data['kyc_status'] ?? 'Unverified';
$tier = $user_data['account_tier'] ?? 'Standard';

// Fetch Unread Notification Count
$stmt_n = $conn->prepare("SELECT COUNT(id) as unread FROM notifications WHERE user_id = ? AND is_read = 0");
$stmt_n->bind_param("i", $user_id);
$stmt_n->execute();
$notif_data = $stmt_n->get_result()->fetch_assoc();
$unread_count = $notif_data['unread'] ?? 0;

// Calculate Portfolio Stats
$total_profit = $balance * 0.18; // Example dynamic logic
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Xmstockexchange | Private Wealth Management</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">

<style>
:root{
--bg:#030508;
--card-bg:rgba(15,22,36,0.6);
--accent:#d4af37;
--accent-soft:rgba(212,175,55,0.1);
--text-main:#f8fafc;
--text-muted:#64748b;
--border:rgba(255,255,255,0.06);
--danger:#ef4444;
}

*{box-sizing:border-box;margin:0;padding:0;font-family:'Plus Jakarta Sans',sans-serif;}
body{background:var(--bg);color:var(--text-main);overflow-x:hidden;}

/* ================= SIDEBAR ================= */
.sidebar{
width:280px;
background:#070a11;
padding:35px 24px;
position:fixed;
height:100vh;
left:0;
top:0;
border-right:1px solid var(--border);
transition:transform .35s ease;
z-index:1000;
overflow-y:auto;
}

.logo-area{margin-bottom:50px;text-align:center;}
.logo-area h2{
color:var(--accent);
letter-spacing:4px;
font-weight:800;
font-size:20px;
border:1px solid var(--accent);
display:inline-block;
padding:6px 15px;
}

.sidebar a{
display:flex;
align-items:center;
gap:15px;
padding:14px 20px;
color:var(--text-muted);
text-decoration:none;
border-radius:12px;
margin-bottom:8px;
transition:.3s;
font-size:15px;
font-weight:500;
}

.sidebar a:hover{background:var(--accent-soft);color:var(--accent);}
.sidebar a.active{
background:var(--accent);
color:#000;
}

.notif-badge{
background:var(--danger);
color:#fff;
font-size:10px;
padding:2px 7px;
border-radius:50px;
margin-left:auto;
}

/* ================= MOBILE TOGGLE ================= */
#mobile-toggle{
display:none;
position:fixed;
top:15px;
left:15px;
z-index:1100;
background:var(--accent);
border:none;
padding:10px 12px;
border-radius:8px;
cursor:pointer;
}

/* ================= OVERLAY ================= */
#sidebar-overlay{
display:none;
position:fixed;
inset:0;
background:rgba(0,0,0,0.6);
z-index:900;
}

/* ================= MAIN CONTENT ================= */
.main-content{
margin-left:280px;
padding:40px;
min-height:100vh;
}

/* ================= MOBILE RESPONSIVE ================= */
@media(max-width:992px){

#mobile-toggle{
display:block;
}

.sidebar{
transform:translateX(-100%);
}

.sidebar.active{
transform:translateX(0);
}

#sidebar-overlay.active{
display:block;
}

.main-content{
margin-left:0;
padding:20px;
padding-top:70px;
}
}
.bottom-nav{
display:none;
position:fixed;
bottom:0;
left:0;
width:100%;
background:rgba(7,10,17,0.95);
backdrop-filter:blur(10px);
border-top:1px solid var(--border);
padding:12px 10px;
justify-content:space-around; /* even spacing */
align-items:center;          /* vertical center */
z-index:1000;
}

.bottom-nav a{
color:var(--text-muted);
text-decoration:none;
font-size:20px;
flex:1;
text-align:center;
}

.bottom-nav a.active{
color:var(--accent);
}
.security-title{
    margin-bottom:20px;
    font-size:16px;
    font-weight:700;
    letter-spacing:.5px;
}

.security-item{
    display:flex;
    align-items:center;
    gap:14px;
    padding:14px 0;
    border-bottom:1px solid var(--border);
}

.security-item:last-child{
    border-bottom:none;
}

.status-dot{
    width:10px;
    height:10px;
    border-radius:50%;
}

.status-dot.success{
    background:var(--success);
    box-shadow:0 0 8px var(--success);
}

.status-dot.warning{
    background:#eab308;
    box-shadow:0 0 8px #eab308;
}

.status-dot.danger{
    background:var(--danger);
    box-shadow:0 0 8px var(--danger);
}

.status-label{
    font-size:14px;
    font-weight:600;
    color:var(--text-main);
}

.status-text{
    font-size:12px;
    color:var(--text-muted);
    margin-top:2px;
}
.logo-area{
    text-align:center;
    margin-bottom:40px;
}

.sidebar-logo{
    height:45px;      /* controlled height */
    width:auto;       /* prevents distortion */
    max-width:100%;   /* prevents overflow */
    object-fit:contain;
    display:block;
    margin:0 auto;
}
</style>
</head>

<body>

<!-- MOBILE BUTTON -->
<button id="mobile-toggle">
<i class="fa-solid fa-bars"></i>
</button>

<!-- SIDEBAR -->
<div class="sidebar" id="sidebar">
<div id="google_translate_element"></div>
    <div class="logo-area">
        <img src="images/logo.png" alt="Xmstockexchange Logo" class="sidebar-logo">
        <p style="font-size:10px;color:var(--text-muted);margin-top:8px;text-transform:uppercase;">
            Private Wealth Management
        </p>
    </div>

    <nav>
        <a href="dashboard.php" class="active">
            <i class="fa-solid fa-house-chimney"></i> Overview
        </a>

        <a href="deposit.php">
            <i class="fa-solid fa-vault"></i> Deposit
        </a>

        <a href="withdraw.php">
            <i class="fa-solid fa-money-bill-transfer"></i> Withdraw
        </a>

        <a href="invest.php">
            <i class="fa-solid fa-chart-line"></i> Investments
        </a>

        <a href="copy_traders.php">
            <i class="fa-solid fa-users"></i> Copy Trading
        </a>

        <a href="kyc.php">
            <i class="fa-solid fa-id-card"></i> KYC
        </a>

        <a href="notification.php">
            <i class="fa-solid fa-bell"></i> Notifications
        </a>

        <a href="transactions.php">
            <i class="fa-solid fa-clock-rotate-left"></i> History
        </a>

        <div style="margin:25px 0;border-top:1px solid var(--border);"></div>

        <a href="profile.php">
            <i class="fa-solid fa-circle-user"></i> My Profile
        </a>

        <a href="logout.php" style="color:var(--danger);">
            <i class="fa-solid fa-power-off"></i> Logout
        </a>
    </nav>

</div>

<div id="sidebar-overlay"></div>
<div id="google_translate_element"></div>
<!-- ================= MAIN DASHBOARD CONTENT ================= -->
<div class="main-content">

    <div class="header-desktop" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 40px;">
        <div>
            <h1 style="font-weight: 800;">Executive Overview</h1>
            <p style="color: var(--text-muted);">Welcome back, <span style="color: var(--text-main); font-weight: 600;"><?= htmlspecialchars($user_name) ?></span></p>
        </div>
        <div style="background: var(--card-bg); padding: 10px 20px; border-radius: 50px; border: 1px solid var(--border);">
            <span style="font-size: 13px; color: var(--text-muted);"><i class="fa fa-crown text-warning"></i> Tier: </span>
            <span style="font-weight: 700; color: var(--accent);"><?= $tier ?> Member</span>
            <div id="google_translate_element"></div>
        </div>
    </div>

    <div class="top-row">
        <div class="balance-card">
            <span style="color: var(--text-muted); font-size: 14px; text-transform: uppercase; font-weight: 700; letter-spacing: 1px;">Available Liquidity</span>
            <div style="display: flex; align-items: baseline; gap: 10px; margin: 15px 0;">
                <h2 style="font-size: 48px; font-weight: 800;">$<?= number_format($balance, 2) ?></h2>
                <span style="color: var(--success); font-weight: 700;"><i class="fa fa-caret-up"></i> 4.2%</span>
            </div>
            <div style="display: flex; gap: 20px; margin-top: 30px;">
                <a href="deposit.php" style="flex: 1; background: var(--accent); color: #000; text-align: center; padding: 15px; border-radius: 15px; text-decoration: none; font-weight: 800;">TOP UP</a>
                <a href="withdraw.php" style="flex: 1; background: rgba(255,255,255,0.05); color: #fff; text-align: center; padding: 15px; border-radius: 15px; text-decoration: none; font-weight: 800; border: 1px solid var(--border);">WITHDRAW</a>
            </div>
        </div>

     <div class="security-card">
    <h4 class="security-title">Security Health</h4>

    <div class="security-item">
        <div class="status-dot success"></div>
        <div>
            <span class="status-label">SSL Encryption</span>
            <p class="status-text">Active & Secured</p>
        </div>
    </div>

    <div class="security-item">
        <div class="status-dot 
            <?= ($kyc_status == 'Verified') ? 'success' : 'warning' ?>">
        </div>
        <div>
            <span class="status-label">Identity Verification</span>
            <p class="status-text"><?= $kyc_status ?></p>
        </div>
    </div>

    <div class="security-item">
        <div class="status-dot success"></div>
        <div>
            <span class="status-label">Two-Factor Authentication</span>
            <p class="status-text">Enabled</p>
        </div>
    </div>
</div>
            <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid var(--border);">
                <p style="font-size: 12px; color: var(--text-muted);">Account Last Sync: Just now</p>
            </div>
        </div>
    </div>

    <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 25px; margin-bottom: 40px;">
        <div class="security-card" style="display: flex; align-items: center; gap: 25px;">
            <div style="width: 80px; height: 80px; border: 8px solid var(--accent); border-radius: 50%; border-left-color: transparent; display: flex; align-items: center; justify-content: center;">
                <i class="fa fa-pie-chart" style="color: var(--accent);"></i>
            </div>
            <div>
                <h4 style="font-size: 14px; color: var(--text-muted);">PORTFOLIO YIELD</h4>
                <span style="font-size: 24px; font-weight: 800; color: var(--success);">+$<?= number_format($total_profit, 2) ?></span>
            </div>
        </div>
        
        <div class="security-card" style="display: flex; align-items: center; gap: 25px;">
            <div style="width: 80px; height: 80px; border: 8px solid #3b82f6; border-radius: 50%; border-bottom-color: transparent; display: flex; align-items: center; justify-content: center;">
                <i class="fa fa-clock" style="color: #3b82f6;"></i>
            </div>
            <div>
                <h4 style="font-size: 14px; color: var(--text-muted);">ACTIVE PLANS</h4>
                <span style="font-size: 24px; font-weight: 800;">03 Active</span>
            </div>
        </div>
    </div>

    <div class="security-card" style="padding: 0; overflow: hidden;">
        <div style="padding: 25px; display: flex; justify-content: space-between; align-items: center;">
            <h4 style="font-weight: 800;"><i class="fa fa-chart-area text-warning me-2"></i>Live Market Pulse</h4>
            <span class="badge bg-success" style="font-size: 10px;">LIVE DATA</span>
        </div>
        
        <div id="tv-chart" style="height: 400px;"></div>
    </div>
</div>

<!-- 🔥 DO NOT REMOVE YOUR CONTENT -->
<!-- Paste your exact dashboard content here -->
<!-- It will NOT be hidden anymore -->




</div>

<script src="https://s3.tradingview.com/tv.js"></script>
<script>
    new TradingView.widget({
        "container_id": "tv-chart",
        "autosize": true,
        "symbol": "BINANCE:BTCUSDT",
        "interval": "H",
        "timezone": "Etc/UTC",
        "theme": "dark",
        "style": "3",
        "hide_top_toolbar": true,
        "save_image": false,
        "backgroundColor": "rgba(7, 10, 17, 1)",
        "gridColor": "rgba(255, 255, 255, 0.05)"
    });
</script>

<script>
document.addEventListener("DOMContentLoaded",function(){
const toggle=document.getElementById("mobile-toggle");
const sidebar=document.getElementById("sidebar");
const overlay=document.getElementById("sidebar-overlay");

toggle.addEventListener("click",function(){
sidebar.classList.toggle("active");
overlay.classList.toggle("active");
});

overlay.addEventListener("click",function(){
sidebar.classList.remove("active");
overlay.classList.remove("active");
});
});
</script>
<script src="./bit_files/scripts.js.download"></script>
<script src="./bit_files/function.js.download"></script>
<script src="pop.js"></script>
<script type="text/javascript">
var _smartsupp = _smartsupp || {};
_smartsupp.key = 'd6d9bdfa2836b0f62477742cfe47459ec0ca917e';
window.smartsupp||(function(d){var s,c,o=smartsupp=function(){o._.push(arguments)};o._=[];s=d.getElementsByTagName('script')[0];c=d.createElement('script');c.type='text/javascript';c.charset='utf-8';c.async=true;c.src='https://www.smartsuppchat.com/loader.js?';s.parentNode.insertBefore(c,s);})(document);
</script>
<script type="text/javascript">
function googleTranslateElementInit() {
  new google.translate.TranslateElement(
    {pageLanguage: 'en'},
    'google_translate_element'
  );
}
</script>

<script src="//translate.google.com/translate_a/element.js?cb=googleTranslateElementInit"></script>
</body>
</html>