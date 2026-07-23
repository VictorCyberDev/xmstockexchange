<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
include __DIR__ . '/../db.php';

// Security Check
$is_admin = isset($_SESSION['admin_id']);
$user_id = $_SESSION['user_id'] ?? null;

/* ======================
    ADMIN: LOGIC
====================== */
if ($is_admin && $_SERVER['REQUEST_METHOD'] === 'POST') {

    try {

        // ADD PLAN
        if (isset($_POST['add_plan'])) {

            $name = mysqli_real_escape_string($conn, $_POST['plan_name']);
            $min = (float)$_POST['min_amount'];
            $max = (float)$_POST['max_amount'];
            $roi = (float)$_POST['roi'];
            $duration = (int)$_POST['duration'];
            $duration_unit = $_POST['duration_unit'];

            // Convert duration to hours before saving
            if ($duration_unit == "days") {
                $duration = $duration * 24;
            }

            if ($duration_unit == "weeks") {
                $duration = $duration * 24 * 7;
            }

            $stmt = $conn->prepare("INSERT INTO investment_plans (name, min_amount, max_amount, roi, duration) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param("sdddi", $name, $min, $max, $roi, $duration);

            if ($stmt->execute()) {
                $success = "Investment plan added successfully!";
            } else {
                $error = "Failed to add plan.";
            }
        }

        // DELETE PLAN
        if (isset($_POST['delete_plan'])) {

            $id = (int)$_POST['plan_id'];

            $stmt = $conn->prepare("DELETE FROM investment_plans WHERE id = ?");
            $stmt->bind_param("i", $id);

            if ($stmt->execute()) {
                $success = "Plan deleted successfully!";
            } else {
                $error = "Failed to delete plan.";
            }
        }

    } catch (Exception $e) {

        $error = "System Error: " . $e->getMessage();

    }
}


/* ======================
    USER: JOIN PLAN LOGIC
====================== */
if (!$is_admin && $user_id && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['invest_now'])) {

    $plan_id = (int)$_POST['plan_id'];
    $amount = (float)$_POST['invest_amount'];

    // Fetch user balance
    $stmt_u = $conn->prepare("SELECT balance FROM users WHERE id = ?");
    $stmt_u->bind_param("i", $user_id);
    $stmt_u->execute();
    $user = $stmt_u->get_result()->fetch_assoc();

    // Fetch plan
    $stmt_p = $conn->prepare("SELECT * FROM investment_plans WHERE id = ?");
    $stmt_p->bind_param("i", $plan_id);
    $stmt_p->execute();
    $plan = $stmt_p->get_result()->fetch_assoc();

    if ($plan && $user['balance'] >= $amount && $amount >= $plan['min_amount'] && $amount <= $plan['max_amount']) {

        $conn->begin_transaction();

        try {

            $conn->query("UPDATE users SET balance = balance - $amount WHERE id = $user_id");

            $stmt_inv = $conn->prepare("INSERT INTO user_investments (user_id, plan_id, amount, status, date_created) VALUES (?, ?, ?, 'Active', NOW())");
            $stmt_inv->bind_param("iid", $user_id, $plan_id, $amount);
            $stmt_inv->execute();

            $conn->commit();

            $success = "Investment of $" . number_format($amount, 2) . " in {$plan['name']} was successful!";

        } catch (Exception $e) {

            $conn->rollback();
            $error = "Transaction failed. Please try again.";

        }

    } else {

        $error = "Invalid amount or insufficient balance.";

    }
}


/* ======================
    FETCH PLANS
====================== */
$plans_query = $conn->query("SELECT * FROM investment_plans ORDER BY min_amount ASC");


/* ======================
    DURATION DISPLAY FUNCTION
====================== */
function formatDuration($hours) {

    if ($hours < 24) {
        return $hours . " Hours";
    }

    if ($hours < 168) {
        return ($hours / 24) . " Days";
    }

    return ($hours / 168) . " Weeks";
}
?>

<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Investment Center</title>

<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css" rel="stylesheet">

<style>

:root{
--accent:#f5b301;
--bg-dark:#06090f;
--card-bg:#0f172a;
--border:rgba(255,255,255,0.08);
--text-dim:#94a3b8;
}

body{
background:var(--bg-dark);
color:#e2e8f0;
font-family:'Inter',sans-serif;
}

.sidebar{
width:260px;
background:#020617;
position:fixed;
height:100vh;
border-right:1px solid var(--border);
}

.content-area{
padding:40px;
margin-left:260px;
}

.glass-card{
background:var(--card-bg);
border:1px solid var(--border);
border-radius:20px;
padding:25px;
transition:.3s;
}

.plan-card:hover{
border-color:var(--accent);
transform:translateY(-5px);
}

.roi-badge{
background:linear-gradient(45deg,#10b981,#059669);
color:white;
padding:5px 15px;
border-radius:50px;
font-weight:700;
}

.input-glass{
background:rgba(255,255,255,0.03);
border:1px solid var(--border);
color:white;
border-radius:10px;
}

.input-glass:focus{
background:rgba(255,255,255,0.08);
color:white;
border-color:var(--accent);
box-shadow:none;
}

@media(max-width:992px){
.sidebar{display:none;}
.content-area{margin-left:0;}
}

</style>
</head>

<body>


<!-- SIDEBAR -->

<div class="sidebar p-4 d-none d-lg-block">

<h4 class="text-white fw-bold mb-5">
<i class="fa fa-gem text-warning me-2"></i>FINANCE
</h4>

<nav class="nav flex-column gap-3">

<a href="admin_dashboard.php" class="nav-link text-white opacity-50">
<i class="fa fa-chart-line me-2"></i> Dashboard
</a>

<a href="investments.php" class="nav-link text-warning fw-bold">
<i class="fa fa-pie-chart me-2"></i> Investments
</a>

<a href="logout.php" class="nav-link text-danger">
<i class="fa fa-sign-out-alt me-2"></i> Logout
</a>

</nav>

</div>


<div class="content-area">

<div class="container-fluid">

<?php if(isset($success)): ?>
<div class="alert alert-success"><?= $success ?></div>
<?php endif; ?>

<?php if(isset($error)): ?>
<div class="alert alert-danger"><?= $error ?></div>
<?php endif; ?>


<div class="d-flex justify-content-between align-items-center mb-5">

<div>
<h2 class="fw-bold">Investment <span class="text-warning">Portfolios</span></h2>
<p class="text-dim">Manage your tiers and user opportunities</p>
</div>

<?php if($is_admin): ?>

<button class="btn btn-warning fw-bold px-4 rounded-pill"
data-bs-toggle="modal"
data-bs-target="#addPlanModal">

<i class="fa fa-plus-circle me-2"></i> New Plan

</button>

<?php endif; ?>

</div>


<div class="row g-4">

<?php while($plan = $plans_query->fetch_assoc()): ?>

<?php

$duration_hours = $plan['duration'];

if($duration_hours % 168 == 0){

$duration_display = ($duration_hours / 168)." Weeks";

}
elseif($duration_hours % 24 == 0){

$duration_display = ($duration_hours / 24)." Days";

}
else{

$duration_display = $duration_hours." Hours";

}

?>

<div class="col-xl-4 col-md-6">

<div class="glass-card plan-card text-center h-100">

<div class="d-flex justify-content-between mb-4">

<span class="roi-badge"><?= $plan['roi'] ?>% ROI</span>

<i class="fa fa-rocket text-warning opacity-50"></i>

</div>

<h3 class="fw-bold"><?= htmlspecialchars($plan['name']) ?></h3>

<p class="text-dim small mb-4">
Duration: <?= $duration_display ?>
</p>

<div class="bg-black bg-opacity-25 rounded-3 p-3 mb-4">

<div class="d-flex justify-content-between small mb-1">

<span class="text-dim">Min</span>

<span class="text-success fw-bold">
$<?= number_format($plan['min_amount']) ?>
</span>

</div>

<div class="d-flex justify-content-between small">

<span class="text-dim">Max</span>

<span class="text-white fw-bold">
$<?= number_format($plan['max_amount']) ?>
</span>

</div>

</div>


<?php if(!$is_admin): ?>

<form method="POST">

<input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">

<div class="input-group mb-3">

<span class="input-group-text bg-transparent border-secondary text-white">$</span>

<input type="number"
name="invest_amount"
class="form-control input-glass"
placeholder="0.00"
required>

</div>

<button name="invest_now"
class="btn btn-warning w-100 fw-bold py-2">

INVEST NOW

</button>

</form>

<?php else: ?>

<form method="POST"
onsubmit="return confirm('Delete this plan?');">

<input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">

<button type="submit"
name="delete_plan"
class="btn btn-outline-danger w-100 fw-bold">

<i class="fa fa-trash me-2"></i> Delete Plan

</button>

</form>

<?php endif; ?>

</div>
</div>

<?php endwhile; ?>

</div>
</div>
</div>


<!-- ADD PLAN MODAL -->

<div class="modal fade" id="addPlanModal" tabindex="-1">

<div class="modal-dialog modal-dialog-centered">

<div class="modal-content glass-card p-0" style="background:#020617">

<div class="modal-header border-bottom border-secondary p-4">

<h5 class="modal-title text-warning fw-bold">Create Investment Plan</h5>

<button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>

</div>

<form method="POST">

<div class="modal-body p-4">

<div class="mb-3">

<label class="text-dim small fw-bold">PLAN NAME</label>

<input type="text" name="plan_name" class="form-control input-glass" required>

</div>

<div class="row g-3">

<div class="col-6">

<label class="text-dim small fw-bold">MIN ($)</label>

<input type="number" name="min_amount" class="form-control input-glass" required>

</div>

<div class="col-6">

<label class="text-dim small fw-bold">MAX ($)</label>

<input type="number" name="max_amount" class="form-control input-glass" required>

</div>

<div class="col-6">

<label class="text-dim small fw-bold">ROI (%)</label>

<input type="number" step="0.01" name="roi" class="form-control input-glass" required>

</div>

<div class="col-6">

<label class="text-dim small fw-bold">DURATION</label>

<input type="number" name="duration" class="form-control input-glass" required>

</div>

<div class="col-12">

<label class="text-dim small fw-bold">DURATION UNIT</label>

<select name="duration_unit" class="form-control input-glass">

<option value="hours">Hours</option>
<option value="days">Days</option>
<option value="weeks">Weeks</option>

</select>

</div>

</div>

</div>

<div class="modal-footer border-0 p-4 pt-0">

<button type="submit" name="add_plan" class="btn btn-warning w-100 fw-bold py-3">

PUBLISH TO USERS

</button>

</div>

</form>

</div>
</div>
</div>


<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>