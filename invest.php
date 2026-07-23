<?php
// Enable all error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);

if (session_status() == PHP_SESSION_NONE) session_start();
include __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) { 
    header("Location: login.php"); 
    exit(); 
}

$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Investor';


/* --- DURATION FORMAT FUNCTION --- */
function formatDuration($hours){

    if($hours < 24){
        return $hours . " Hours";
    }

    if($hours < 168){
        return ($hours / 24) . " Days";
    }

    return ($hours / 168) . " Weeks";
}


/* --- FETCH LIVE PLANS --- */
$plans_query = $conn->query("SELECT * FROM investment_plans ORDER BY min_amount ASC");

$plans = [];

while ($row = $plans_query->fetch_assoc()) {

    $plans[] = [
        'id'            => $row['id'],
        'name'          => $row['name'],
        'asset_type'    => $row['category'] ?? 'crypto',
        'description'   => $row['description'] ?? 'Expertly managed high-yield portfolio.',
        'roi_percent'   => $row['roi'],
        'duration'      => formatDuration($row['duration']), // FIXED
        'duration_hours'=> $row['duration'],
        'min_amount'    => $row['min_amount'],
        'max_amount'    => $row['max_amount']
    ];
}


/* --- AUTO-SETTLEMENT ENGINE --- */
$current_time = date('Y-m-d H:i:s');

$check_matured = $conn->query("
    SELECT ui.*, ip.roi, ip.duration 
    FROM user_investments ui 
    JOIN investment_plans ip ON ui.plan_id = ip.id 
    WHERE ui.status = 'active' 
    AND DATE_ADD(ui.start_date, INTERVAL ip.duration HOUR) <= '$current_time'
");

while ($matured = $check_matured->fetch_assoc()) {

    $profit = $matured['amount'] * ($matured['roi'] / 100);
    $total_return = $matured['amount'] + $profit;

    // Add funds to user balance
    $conn->query("UPDATE users SET balance = balance + $total_return WHERE id = " . $matured['user_id']);

    // Mark investment as completed
    $conn->query("UPDATE user_investments SET status = 'completed' WHERE id = " . $matured['id']);
}


/* --- FETCH REAL INVESTMENTS --- */
$my_investments_query = $conn->prepare("
    SELECT ui.*, ip.name, ip.roi as roi_percent, ip.duration 
    FROM user_investments ui 
    JOIN investment_plans ip ON ui.plan_id = ip.id 
    WHERE ui.user_id = ? 
    ORDER BY ui.start_date DESC
");

$my_investments_query->bind_param("i", $user_id);
$my_investments_query->execute();
$result_inv = $my_investments_query->get_result();

$my_investments = [];

while ($inv = $result_inv->fetch_assoc()) {

    $start = $inv['start_date'];

    // FIXED: duration is now hours
    $end = date('Y-m-d H:i:s', strtotime($start . ' + ' . $inv['duration'] . ' hours'));

    $my_investments[] = [
        'name'        => $inv['name'],
        'asset_type'  => 'shield',
        'amount'      => $inv['amount'],
        'roi_percent' => $inv['roi_percent'],
        'duration'    => formatDuration($inv['duration']),
        'status'      => strtolower($inv['status']),
        'start_date'  => $start,
        'end_date'    => $end
    ];
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Investment Portal | Dominion Funding</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        :root { --dom-navy: #0a2540; --dom-gold: #c5a059; --bg-dark: #0b0f19; --card-dark: #161c2d; }
        body { font-family: 'Inter', sans-serif; background: var(--bg-dark); color: #eaeaea; }
        
        /* Layout Sidebar */
        .sidebar { height: 100vh; background: var(--dom-navy); width: 260px; position: fixed; left: 0; top: 0; padding: 25px; border-right: 1px solid rgba(255,255,255,0.1); }
        .main-content { margin-left: 260px; padding: 40px; }
        
        /* Plan Cards */
        .plan-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 24px; }
        .plan-card { 
            background: var(--card-dark); border-radius: 20px; overflow: hidden; border: 1px solid #2d3648; 
            transition: all 0.3s ease; display: flex; flex-direction: column;
        }
        .plan-card:hover { transform: translateY(-8px); border-color: var(--dom-gold); box-shadow: 0 10px 30px rgba(0,0,0,0.5); }
        .card-img { height: 160px; background-size: cover; background-position: center; position: relative; }
        .card-img::after { content: ''; position: absolute; top:0; left:0; width:100%; height:100%; background: linear-gradient(0deg, var(--card-dark) 0%, transparent 100%); }
        .roi-badge { position: absolute; top: 15px; right: 15px; background: var(--dom-gold); color: white; padding: 4px 12px; border-radius: 50px; font-weight: bold; z-index: 2; }
        
        .card-body { padding: 20px; }
        .btn-invest { background: var(--dom-gold); color: white; border-radius: 12px; padding: 10px; font-weight: 600; width: 100%; border: none; transition: 0.3s; }
        .btn-invest:hover { background: #b08d4a; transform: scale(1.02); }

      /* Investment Table - Ultra Premium Edition */
        .table-custom { 
            background: #ffffff !important; 
            border-radius: 20px; 
            border-collapse: separate; 
            border-spacing: 0; 
            overflow: hidden; 
            border: 1px solid #e0e6ed;
            box-shadow: 0 15px 35px rgba(0,0,0,0.07);
        }

        /* Table Headers: Royal Navy with Gold Lettering */
        .table-custom th { 
            background: #0a2540; /* Your Navy color */
            color: #c5a059 !important; /* Your Gold color */
            font-weight: 700; 
            text-transform: uppercase;
            font-size: 0.72rem;
            letter-spacing: 1.5px;
            padding: 20px 15px; 
            border: none;
        }

        /* Table Data: Charcoal Black for visibility */
        .table-custom td { 
            padding: 20px 15px; 
            border-bottom: 1px solid #f0f4f8; 
            vertical-align: middle; 
            color: #1a1a1a !important; /* Deep black-charcoal for maximum contrast */
            font-size: 0.95rem;
        }

        /* FIX: Making 'Crypto' and other small muted text visible */
        .table-custom .text-muted, 
        .table-custom small,
        .text-muted { 
            color: #5d6d7e !important; /* Deep Slate Grey (Visible on white) */
            font-weight: 500;
        }

        /* Force "Strategy" name to stay bold and dark */
        .table-custom .fw-bold.text-white {
            color: #0a2540 !important; /* Overriding 'text-white' to 'Navy' since background is white */
        }

        /* Progress Bar Refinement */
        .progress-bar-custom { 
            height: 10px; 
            background: #eef2f7; 
            border-radius: 20px; 
            overflow: hidden; 
            border: 1px solid #d1d9e6;
        }

        .progress-fill { 
            height: 100%; 
            background: linear-gradient(90deg, #c5a059, #e2c08d); /* Shimmering Gold */
            border-radius: 20px;
            box-shadow: inset 0 1px 2px rgba(255,255,255,0.3);
        }

        /* Highlight the Strategy Icon */
        .bg-dark.rounded-circle {
            background-color: #0a2540 !important;
            border: 2px solid #c5a059;
        }
        @media (max-width: 992px) {
            .sidebar { display: none; }
            .main-content { margin-left: 0; padding: 20px; }
        }
    </style>
</head>
<body>

<nav class="sidebar">
    <div class="mb-5">
        <h4 class="text-white fw-bold"><i class="bi bi-bank me-2 text-gold"></i>Dominion</h4>
    </div>
    <ul class="nav flex-column gap-3">
        <li class="nav-item"><a href="dashboard.php" class="nav-link text-white-50"><i class="bi bi-speedometer2 me-2"></i> Overview</a></li>
        <li class="nav-item"><a href="invest.php" class="nav-link text-white fw-bold"><i class="bi bi-graph-up-arrow me-2 text-gold"></i> Investment Plans</a></li>
        <li class="nav-item"><a href="deposit.php" class="nav-link text-white-50"><i class="bi bi-wallet2 me-2"></i> Wallet</a></li>
        <hr class="text-white-50">
        <li class="nav-item"><a href="logout.php" class="nav-link text-danger"><i class="bi bi-box-arrow-left me-2"></i> Sign Out</a></li>
    </ul>
</nav>

<main class="main-content">
    <div class="d-flex justify-content-between align-items-center mb-5">
        <div>
            <h2 class="fw-bold text-white mb-0">Investment Opportunities</h2>
            <p class="text-muted">Managed by Pacific Asset Management | Secured by BNY Mellon</p>
        </div>
        <div class="text-end">
            <span class="text-muted small">Welcome back,</span>
            <h6 class="text-white mb-0"><?= htmlspecialchars($user_name) ?></h6>
        </div>
    </div>

    <div class="plan-grid mb-5">
        <?php foreach($plans as $plan): 
            $bg = $asset_images[$plan['asset_type']] ?? 'img/default.jpg';
        ?>
        <div class="plan-card">
            <div class="card-img" style="background-image: url('<?= $bg ?>');">
                <span class="roi-badge">+<?= $plan['roi_percent'] ?>% ROI</span>
            </div>
            <div class="card-body">
                <h5 class="fw-bold mb-2 text-white"><?= $plan['name'] ?></h5>
                <p class="text-muted small mb-3"><?= $plan['description'] ?></p>
                <div class="d-flex justify-content-between mb-2 small">
                    <span class="text-muted">Min Investment:</span>
                    <span class="text-white fw-bold">$<?= number_format($plan['min_amount']) ?></span>
                </div>
                <div class="d-flex justify-content-between mb-4 small">
                    <span class="text-muted">Duration:</span>
                    <span class="text-white fw-bold"><?= $plan['duration'] ?> </span>
                </div>
               <button class="btn-invest" onclick='investModal(<?= json_encode($plan) ?>)'>
    Commit Capital
</button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="section">
        <h4 class="fw-bold text-white mb-4"><i class="bi bi-briefcase me-2 text-gold"></i>Your Active Portfolio</h4>
        <div class="table-responsive">
            <table class="table table-custom text-white">
                <thead>
                    <tr>
                        <th>Strategy</th>
                        <th>Amount</th>
                        <th>Expected ROI</th>
                        <th>Status</th>
                        <th>Maturity Progress</th>
                    </tr>
                </thead>
               <tbody>
    <?php foreach($my_investments as $i): 
        // Logic to calculate real progress based on start_date and duration
        $start_time = strtotime($i['start_date']);
        $end_time = strtotime($i['end_date']);
        $now = time();
        
        $total_duration = $end_time - $start_time;
        $elapsed = $now - $start_time;
        
        $progress = ($total_duration > 0) ? ($elapsed / $total_duration) * 100 : 0;
        $progress = max(0, min(100, $progress)); // Keeps it between 0 and 100
        
        $status_class = ($i['status'] == 'active') ? 'text-success' : 'text-muted';
    ?>
    <tr>
        <td>
            <div class="d-flex align-items-center">
                <div class="p-2 bg-dark rounded-circle me-3"><i class="bi bi-shield-check text-gold"></i></div>
                <div>
                    <span class="d-block fw-bold text-white"><?= htmlspecialchars($i['name']) ?></span>
                    <small class="text-muted"><?= date('M d, Y', $start_time) ?></small>
                </div>
            </div>
        </td>
        <td class="fw-bold text-white">$<?= number_format($i['amount'], 2) ?></td>
        <td class="text-success fw-bold">+<?= $i['roi_percent'] ?>%</td>
        <td>
            <span class="badge bg-opacity-10 <?= $status_class ?> border border-secondary">
                <i class="bi bi-circle-fill me-1 small"></i> <?= ucfirst($i['status']) ?>
            </span>
        </td>
        <td style="width: 200px;">
            <div class="d-flex align-items-center gap-2">
                <div class="progress-bar-custom flex-grow-1" style="background: #2d3648; height: 8px; border-radius: 5px;">
                    <div class="progress-fill" style="width: <?= $progress ?>%; height: 100%; background: var(--dom-gold); border-radius: 5px;"></div>
                </div>
                <small class="text-white"><?= round($progress) ?>%</small>
            </div>
        </td>
    </tr>
    <?php endforeach; ?>
</tbody>
            </table>
        </div>
    </div>
</main>

<script>
function investModal(plan) {
    // 1. Get user balance (ensure this variable is available from your PHP)
    const userBalance = <?= $user_balance ?? 0 ?>;
    const minAmount = parseFloat(plan.min_amount);

    // 2. The logic check
    if (userBalance >= minAmount) {
        // SUCCESS POPUP
        Swal.fire({
            title: 'Investment Approved',
            text: `Successfully committed capital to ${plan.name}.`,
            icon: 'success',
            background: '#1a1d21', // Matches dark themes
            color: '#fff',
            confirmButtonColor: '#28a745',
            confirmButtonText: 'Great!'
        });
        
        // --- YOUR PREVIOUS SCRIPT EXECUTION GOES HERE ---
        // (e.g., your AJAX call or form submission)
        
    } else {
        // INSUFFICIENT BALANCE POPUP
        Swal.fire({
            title: 'Insufficient Balance',
            text: `You need at least $${minAmount.toLocaleString()} to join this plan.`,
            icon: 'error',
            background: '#1a1d21',
            color: '#fff',
            confirmButtonColor: '#dc3545',
            confirmButtonText: 'Understood'
        });
    }
}
</script>

</body>
</html>
<div class="modal fade" id="investModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary">
            <div class="modal-header border-secondary">
                <h5 class="modal-title text-white" id="modalPlanName">Confirm Investment</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form id="investForm">
                    <input type="hidden" name="plan_id" id="modalPlanId">
                    <div class="mb-3">
                        <label class="text-muted small">Investment Amount ($)</label>
                        <input type="number" name="amount" id="investAmount" class="form-control bg-transparent text-white border-secondary" required>
                        <div id="amountHelp" class="form-text text-info"></div>
                    </div>
                    
                    <div class="p-3 rounded bg-navy-light border border-secondary mb-3">
                        <div class="d-flex justify-content-between small mb-1">
                            <span>Expected Profit:</span>
                            <span class="text-success fw-bold" id="calcProfit">$0.00</span>
                        </div>
                        <div class="d-flex justify-content-between small">
                            <span>Total Return:</span>
                            <span class="text-white fw-bold" id="calcTotal">$0.00</span>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-gold w-100 py-2">Confirm & Open Trade</button>
                </form>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="investConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content bg-dark border-secondary shadow-lg">
            <div class="modal-header border-0">
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-0" id="investment-status">
                </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
let currentPlan = {};

function investModal(planJson) {
    currentPlan = planJson;
    $('#modalPlanName').text("Commit to " + currentPlan.name);
    $('#modalPlanId').val(currentPlan.id);
    $('#amountHelp').text(`Min: $${currentPlan.min_amount} - Max: $${currentPlan.max_amount}`);
    $('#investAmount').attr('min', currentPlan.min_amount).attr('max', currentPlan.max_amount);
    
    const myModal = new bootstrap.Modal(document.getElementById('investModal'));
    myModal.show();
}

// Live Calculation Logic
$('#investAmount').on('input', function() {
    let amt = parseFloat($(this).val()) || 0;
    let profit = amt * (currentPlan.roi_percent / 100);
    $('#calcProfit').text('$' + profit.toLocaleString(undefined, {minimumFractionDigits: 2}));
    $('#calcTotal').text('$' + (amt + profit).toLocaleString(undefined, {minimumFractionDigits: 2}));
});

// Updated AJAX Submission with Status Popups
$('#investForm').on('submit', function(e) {
    e.preventDefault();
    
    // Show a loading state
    Swal.fire({
        title: 'Processing Trade...',
        allowOutsideClick: false,
        didOpen: () => { Swal.showLoading(); }
    });

    $.ajax({
        url: 'process_investment.php',
        method: 'POST',
        data: $(this).serialize(),
        success: function(response) {
            let res = JSON.parse(response);
            if(res.status === 'success') {
                Swal.fire({
                    icon: 'success',
                    title: 'Capital Committed!',
                    text: 'Your trade has been opened successfully.',
                    background: '#161c2d',
                    color: '#fff',
                    confirmButtonColor: '#c5a059'
                }).then(() => {
                    location.reload(); // Refresh to update dashboard totals
                });
            } else {
                Swal.fire({
                    icon: 'error',
                    title: 'Investment Rejected',
                    text: res.message, // This will show "Insufficient Balance" from PHP
                    background: '#161c2d',
                    color: '#fff',
                    confirmButtonColor: '#dc3545'
                });
            }
        },
        error: function() {
            Swal.fire('Error', 'Connection failed. Please try again.', 'error');
        }
    });
});
</script>

