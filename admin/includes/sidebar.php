<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<style>
* { margin: 0; padding: 0; box-sizing: border-box; }

body {
    display: flex;
    min-height: 100vh;
    background: #0f0f1a;
    font-family: 'Segoe UI', sans-serif;
}

/* Sidebar */
.sidebar {
    width: 260px;
    min-height: 100vh;
    background: linear-gradient(180deg, #0f0f1a 0%, #1a1a2e 50%, #16213e 100%);
    display: flex;
    flex-direction: column;
    padding: 0 0 20px 0;
    box-shadow: 4px 0 20px rgba(0,0,0,0.4);
    z-index: 100;
    transition: width 0.3s ease, min-width 0.3s ease;
    overflow: hidden;
    flex-shrink: 0;
}

.sidebar.collapsed {
    width: 0;
    min-width: 0;
}

.sidebar h2 {
    color: #ffffff;
    font-size: 1.1rem;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    padding: 28px 24px 24px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    margin-bottom: 12px;
    background: rgba(255,255,255,0.03);
    white-space: nowrap;
}

.sidebar h2::before {
    content: '◆ ';
    color: #4f8ef7;
}

.sidebar a {
    display: flex;
    align-items: center;
    gap: 14px;
    color: #a0aec0;
    text-decoration: none;
    padding: 13px 24px;
    font-size: 0.92rem;
    font-weight: 500;
    transition: all 0.25s ease;
    border-left: 3px solid transparent;
    margin: 2px 0;
    white-space: nowrap;
}

.sidebar a i {
    width: 18px;
    font-size: 0.95rem;
    text-align: center;
    transition: color 0.25s ease;
    flex-shrink: 0;
}

.sidebar a:hover {
    color: #ffffff;
    background: rgba(79, 142, 247, 0.12);
    border-left-color: #4f8ef7;
    padding-left: 28px;
}

.sidebar a:hover i { color: #4f8ef7; }

.sidebar a.active {
    color: #ffffff;
    background: rgba(79, 142, 247, 0.15);
    border-left-color: #4f8ef7;
}

.sidebar a.active i { color: #4f8ef7; }

.sidebar a[href="logout.php"] {
    color: #fc8181;
    border-top: 1px solid rgba(255,255,255,0.06);
    margin-top: 12px;
}

.sidebar a[href="logout.php"]:hover {
    background: rgba(252,129,129,0.1);
    border-left-color: #fc8181;
    color: #fc8181;
}

.sidebar a[href="logout.php"] i { color: #fc8181; }

.sidebar::-webkit-scrollbar { width: 4px; }
.sidebar::-webkit-scrollbar-track { background: transparent; }
.sidebar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 4px; }

/* Main content */
.main-content {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    transition: all 0.3s ease;
}

/* Top navbar with toggle */
.top-navbar {
    display: flex;
    align-items: center;
    gap: 16px;
    background: #1a1a2e;
    padding: 14px 24px;
    border-bottom: 1px solid rgba(255,255,255,0.08);
    position: sticky;
    top: 0;
    z-index: 99;
}

.toggle-btn {
    background: rgba(79,142,247,0.15);
    border: 1px solid rgba(79,142,247,0.3);
    color: #4f8ef7;
    width: 38px;
    height: 38px;
    border-radius: 8px;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1rem;
    transition: all 0.2s ease;
    flex-shrink: 0;
}

.toggle-btn:hover {
    background: rgba(79,142,247,0.3);
    border-color: #4f8ef7;
}

.top-navbar .page-title {
    color: #ffffff;
    font-size: 1rem;
    font-weight: 600;
    letter-spacing: 0.5px;
}

.page-body {
    padding: 24px;
    color: #e2e8f0;
}

/* Responsive */
@media (max-width: 768px) {
    .sidebar {
        position: fixed;
        top: 0;
        left: 0;
        height: 100vh;
        z-index: 999;
    }
    .sidebar.collapsed { width: 0; }

    .overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 998;
    }
    .overlay.active { display: block; }
}
</style>

<!-- Overlay for mobile -->
<div class="overlay" id="overlay" onclick="toggleSidebar()"></div>

<div id="sidebar" class="sidebar">
    <h2>Xmstockexchange</h2>
    <a href="admin_dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) == 'admin_dashboard.php' ? 'active' : '' ?>">
        <i class="fa fa-chart-line"></i> Dashboard
    </a>
    <a href="deposits.php" class="<?= basename($_SERVER['PHP_SELF']) == 'deposits.php' ? 'active' : '' ?>">
        <i class="fa fa-wallet"></i> Deposits
    </a>
    <a href="withdrawals.php" class="<?= basename($_SERVER['PHP_SELF']) == 'withdrawals.php' ? 'active' : '' ?>">
        <i class="fa fa-arrow-up"></i> Withdrawals
    </a>
    <a href="investments.php" class="<?= basename($_SERVER['PHP_SELF']) == 'investments.php' ? 'active' : '' ?>">
        <i class="fa fa-gem"></i> Investments
    </a>
    <a href="kyc.php" class="<?= basename($_SERVER['PHP_SELF']) == 'kyc.php' ? 'active' : '' ?>">
        <i class="fa fa-id-card"></i> KYC
    </a>
    <a href="referrals.php" class="<?= basename($_SERVER['PHP_SELF']) == 'referrals.php' ? 'active' : '' ?>">
        <i class="fa fa-users"></i> Referrals
    </a>
    <a href="messages.php" class="<?= basename($_SERVER['PHP_SELF']) == 'messages.php' ? 'active' : '' ?>">
        <i class="fa fa-bell"></i> Notifications
    </a>
    <a href="settings.php" class="<?= basename($_SERVER['PHP_SELF']) == 'settings.php' ? 'active' : '' ?>">
        <i class="fa fa-gear"></i> Settings
    </a>
    <a href="logout.php">
        <i class="fa fa-sign-out"></i> Logout
    </a>
</div>

<!-- This wraps everything to the right of the sidebar -->
<div class="main-content">

    <!-- Top navbar with toggle button -->
    <div class="top-navbar">
        <button class="toggle-btn" onclick="toggleSidebar()" title="Toggle Sidebar">
            <i class="fa fa-bars" id="toggleIcon"></i>
        </button>
        <span class="page-title"><?= ucfirst(basename($_SERVER['PHP_SELF'], '.php')) ?></span>
    </div>

    <!-- Your page content goes here -->
    <div class="page-body">
