<?php

// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

session_start();
require_once __DIR__ . '/../db.php';


if (!isset($_SESSION['admin_id'])) {
    header("Location: login.php"); // admin login page
    exit;
}

// Admin is authenticated
$admin_id = $_SESSION['admin_id'];


/* =============================
   FETCH USER FINANCIAL DATA
   APPROVED ONLY – READ ONLY
============================= */

$stmt = $conn->prepare("
SELECT 
    u.name,
    u.total_invested,
    u.total_profit,
    COALESCE(SUM(d.amount),0) AS balance
FROM users u
LEFT JOIN deposits d 
    ON u.id = d.user_id 
    AND d.status='approved'
WHERE u.id = ?
GROUP BY u.id
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$data = $stmt->get_result()->fetch_assoc();

$user_name      = $data['name'] ?? 'Investor';
$balance        = (float) ($data['balance'] ?? 0);
$total_invested = (float) ($data['total_invested'] ?? 0);
$total_profit   = (float) ($data['total_profit'] ?? 0);


// Total users (COUNT rows in users table)
$result = $conn->query("SELECT COUNT(*) AS total FROM users");
$data = $result->fetch_assoc();
$total_users = (int) $data['total'];

$result = $conn->query("
    SELECT COUNT(*) AS total 
    FROM deposits 
    WHERE status='pending'
");
$row = $result->fetch_assoc();
$pending_deps = (int) $row['total'];

// Pending withdrawals
$result = $conn->query("
    SELECT COUNT(*) AS total 
    FROM withdrawals 
    WHERE status='pending'
");
$row = $result->fetch_assoc();
$pending_with = (int) $row['total'];

// Total volume (approved deposits)
$result = $conn->query("
    SELECT COALESCE(SUM(amount), 0) AS total
    FROM deposits
    WHERE status = 'approved'
");
$row = $result->fetch_assoc();
$total_vol = (float) $row['total'];


/* =============================
   FETCH TRANSACTIONS (LEDGER)
============================= */
$tx = $conn->prepare("
    SELECT type, amount, reference, created_at 
    FROM transactions 
    WHERE user_id = ?
    ORDER BY id DESC LIMIT 10
");
$tx->bind_param("i", $user_id);
$tx->execute();
$transactions = $tx->get_result();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>XmStockexchange Admin | Premium Control</title>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/apexcharts"></script>
    <style>
        :root { --bg: #0b0e1a; --sidebar: #0f1326; --card: rgba(255,255,255,0.03); --accent: #f5b301; --text: #f8fafc; }
        * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
        body { background: var(--bg); color: var(--text); overflow-x: hidden; }

        /* SIDEBAR - All Links Included */
        .sidebar { width: 280px; background: var(--sidebar); height: 100vh; position: fixed; border-right: 1px solid rgba(255,255,255,0.05); padding: 25px; z-index: 100; }
        .sidebar-brand { color: var(--accent); font-size: 24px; font-weight: 800; margin-bottom: 40px; display: flex; align-items: center; gap: 10px; }
        .sidebar a { display: flex; align-items: center; gap: 15px; padding: 14px 18px; color: #94a3b8; text-decoration: none; border-radius: 12px; margin-bottom: 5px; transition: 0.3s; font-size: 14px; }
        .sidebar a:hover, .sidebar a.active { background: rgba(245, 179, 1, 0.1); color: var(--accent); }
        .sidebar hr { opacity: 0.05; margin: 20px 0; }

        /* CONTENT AREA */
        .main { margin-left: 280px; padding: 40px; }
        .glass-card { background: var(--card); border: 1px solid rgba(255,255,255,0.05); border-radius: 24px; backdrop-filter: blur(10px); padding: 25px; }

        /* METRICS */
        .metrics-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .metric-item { background: linear-gradient(145deg, #161b31, #0f1326); border-radius: 20px; padding: 25px; border: 1px solid rgba(255,255,255,0.05); }
        .metric-item i { font-size: 30px; color: var(--accent); opacity: 0.8; margin-bottom: 15px; display: block; }
        .metric-item span { color: #94a3b8; font-size: 13px; font-weight: 600; text-transform: uppercase; }
        .metric-item h2 { font-size: 28px; margin-top: 5px; }

        /* TWO COLUMN LAYOUT */
        .content-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 25px; }

        /* RECENT DEPOSITS TABLE */
        .custom-table { width: 100%; border-collapse: collapse; margin-top: 15px; }
        .custom-table th { text-align: left; color: #64748b; font-size: 12px; padding: 15px; border-bottom: 1px solid rgba(255,255,255,0.05); }
        .custom-table td { padding: 15px; font-size: 14px; border-bottom: 1px solid rgba(255,255,255,0.03); }
        
        .badge { padding: 5px 10px; border-radius: 6px; font-size: 11px; font-weight: 700; text-transform: uppercase; }
        .pending { background: rgba(245, 179, 1, 0.1); color: var(--accent); }
        .approved { background: rgba(34, 197, 94, 0.1); color: #22c55e; }

        .btn-approve { background: var(--accent); color: #000; border: none; padding: 8px 15px; border-radius: 8px; font-weight: 700; cursor: pointer; transition: 0.2s; }
        .btn-approve:hover { transform: scale(1.05); }

        @media (max-width: 1200px) { .metrics-grid { grid-template-columns: repeat(2, 1fr); } .content-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>

<aside class="sidebar">
    <div class="sidebar-brand"><i class="fa fa-shield-halved"></i> XMstockexchange</div>
    
    <a href="admin_dashboard.php" class="active"><i class="fa fa-chart-pie"></i> Overview</a>
    <a href="users.php"><i class="fa fa-users"></i> Users Management</a>
    <a href="deposits.php"><i class="fa fa-wallet"></i> Deposits Queue</a>
    <a href="withdrawals.php"><i class="fa fa-money-bill-transfer"></i> Withdrawals</a>
    <a href="investments.php"><i class="fa fa-vault"></i> Active Investments</a>
     <a href="copy_trading.php"><i class="fa fa-vault"></i> copy_Trading</a>
    <a href="kyc.php"><i class="fa fa-user-check"></i> KYC Verification</a>
    
    <hr>
    
    <a href="messages.php"><i class="fa fa-envelope"></i> Support Tickets</a>
    <a href="referrals.php"><i class="fa fa-sitemap"></i> Referral Tree</a>
    <a href="settings.php"><i class="fa fa-sliders"></i> Global Settings</a>
    
    <div style="position: absolute; bottom: 30px; width: calc(100% - 50px);">
        <a href="logout.php" style="color: #ef4444;"><i class="fa fa-power-off"></i> Logout System</a>
    </div>
</aside>

<main class="main">
    <header style="display: flex; justify-content: space-between; margin-bottom: 30px;">
        <div>
            <h1>Admin Executive Dashboard</h1>
            <p style="color: #64748b;">Welcome back, System Overseer.</p>
        </div>
        <div style="text-align: right;">
            <div id="date" style="font-weight: 600; color: var(--accent);">Feb 04, 2026</div>
            <div style="font-size: 12px; color: #64748b;">Server Status: <span style="color: #22c55e;">● Online</span></div>
        </div>
    </header>
   

    <div class="metrics-grid">
        <div class="metric-item">
            <i class="fa fa-users"></i>
            <span>Total Clients</span>
            <h2><?= number_format($total_users) ?></h2>
        </div>
        <div class="metric-item">
            <i class="fa fa-hand-holding-dollar"></i>
            <span>Pending Deposits</span>
            <h2><?= $pending_deps ?></h2>
        </div>
        <div class="metric-item">
            <i class="fa fa-clock-rotate-left"></i>
            <span>Pending Withdrawals</span>
            <h2 style="color: #ef4444;"><?= $pending_with ?></h2>
        </div>
        <div class="metric-item">
            <i class="fa fa-globe"></i>
            <span>Platform Volume</span>
            <h2>$<?= number_format($total_vol, 0) ?></h2>
        </div>
    </div>

    <div class="content-grid">
        <div class="left-col">
            <div class="glass-card" style="margin-bottom: 25px;">
                <h3>Platform Growth Analytics</h3>
                <div id="growthChart"></div>
            </div>

            <div class="glass-card">
                <div style="display: flex; justify-content: space-between; align-items: center;">
                    <h3>Recent Financial Activity</h3>
                    <a href="deposits.php" style="color: var(--accent); font-size: 12px; text-decoration: none;">View All</a>
                </div>
                <table class="custom-table">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Operation</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $recent = $conn->query("SELECT d.id, d.amount, d.status, u.email FROM deposits d JOIN users u ON u.id = d.user_id ORDER BY d.id DESC LIMIT 5");
                        while($r = $recent->fetch_assoc()): ?>
                        <tr>
                            <td><?= $r['email'] ?></td>
                            <td><strong>$<?= number_format($r['amount'], 2) ?></strong></td>
                            <td><span class="badge <?= $r['status'] ?>"><?= $r['status'] ?></span></td>
                            <td>
                                <?php if($r['status'] == 'pending'): ?>
                                <form method="POST"><input type="hidden" name="deposit_id" value="<?=$r['id']?>"><button name="quick_approve" class="btn-approve">APPROVE</button></form>
                                <?php else: ?>
                                <i class="fa fa-circle-check" style="color:#22c55e;"></i>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="right-col">
            <div class="glass-card" style="margin-bottom: 25px;">
                <h4 style="margin-bottom: 15px;">System Integrity</h4>
                <div style="display: flex; gap: 10px; margin-bottom: 10px;">
                    <div style="flex: 1; background: rgba(34, 197, 94, 0.1); padding: 15px; border-radius: 12px; text-align: center;">
                        <span style="font-size: 11px; color: #22c55e;">SSL Status</span><br><strong>Secure</strong>
                    </div>
                    <div style="flex: 1; background: rgba(59, 130, 246, 0.1); padding: 15px; border-radius: 12px; text-align: center;">
                        <span style="font-size: 11px; color: #3b82f6;">Database</span><br><strong>Optimized</strong>
                    </div>
                </div>
            </div>

            <div class="glass-card">
                <h4 style="margin-bottom: 20px;">Platform Distribution</h4>
                <div id="distributionChart"></div>
            </div>
        </div>
    </div>
</main>

<script>
    // Growth Area Chart
    var growthOptions = {
        series: [{ name: 'Volume', data: [31, 40, 28, 51, 42, 109, 100] }],
        chart: { type: 'area', height: 300, toolbar: { show: false }, background: 'transparent' },
        colors: ['#f5b301'],
        dataLabels: { enabled: false },
        stroke: { curve: 'smooth', width: 2 },
        theme: { mode: 'dark' },
        xaxis: { categories: ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] },
        grid: { borderColor: 'rgba(255,255,255,0.05)' }
    };
    new ApexCharts(document.querySelector("#growthChart"), growthOptions).render();

    // Distribution Pie Chart
    var distOptions = {
        series: [44, 55, 13],
        chart: { type: 'donut', height: 300 },
        labels: ['BTC', 'USDT', 'ETH'],
        colors: ['#f5b301', '#22c55e', '#3b82f6'],
        theme: { mode: 'dark' },
        legend: { position: 'bottom' },
        stroke: { show: false }
    };
    new ApexCharts(document.querySelector("#distributionChart"), distOptions).render();
</script>

</body>
</html>