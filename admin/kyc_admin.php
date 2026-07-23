<?php
ini_set('display_errors',1);
ini_set('display_startup_errors',1);
error_reporting(E_ALL);
session_start();
require __DIR__ . '/../db.php';

if (!isset($_SESSION['admin_id'])) {
    header("Location: admin_login.php");
    exit();
}

// Handle Approve / Reject
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $kyc_id = (int)$_POST['kyc_id'];
    $action = $_POST['action'];

    $stmt = $conn->prepare("SELECT k.user_id, k.status, u.email, u.name 
                            FROM kyc_documents k
                            JOIN users u ON u.id = k.user_id
                            WHERE k.id=? LIMIT 1");
    $stmt->bind_param("i", $kyc_id);
    $stmt->execute();
    $kyc = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($kyc && $kyc['status'] === 'pending') {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("UPDATE kyc_documents SET status=?, updated_at=NOW() WHERE id=?");
            $stmt->bind_param("si", $action, $kyc_id);
            $stmt->execute();
            $stmt->close();

            $message = "Your KYC documents have been " . ($action === 'approved' ? "approved ✅" : "rejected ❌") . ".";
            $stmt = $conn->prepare("INSERT INTO notifications (user_id, message, status, created_at) VALUES (?, ?, 'unread', NOW())");
            $stmt->bind_param("is", $kyc['user_id'], $message);
            $stmt->execute();
            $stmt->close();

            $conn->commit();
        } catch(Exception $e) {
            $conn->rollback();
            $_SESSION['error'] = $e->getMessage();
        }
    }

    header("Location: kyc_admin.php");
    exit();
}

// Fetch all KYC records
$kycs = $conn->query("SELECT k.*, u.name, u.email FROM kyc_documents k JOIN users u ON u.id = k.user_id ORDER BY k.created_at DESC");
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>KYC Admin | Dominion Funding</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<style>
:root {
    --primary: #0b2b4a;
    --accent: #ff9900;
    --success: #28a745;
    --danger: #dc3545;
    --bg: #f4f6fb;
}
* { box-sizing: border-box; margin:0; padding:0; font-family:Inter,system-ui,sans-serif; }
body { background: var(--bg); }

/* Sidebar */
.sidebar { width:260px; background:var(--primary); color:#fff; position:fixed; height:100%; padding-top:20px; }
.sidebar h2 { text-align:center; margin-bottom:20px; }
.sidebar a { display:block; padding:15px 20px; color:#fff; text-decoration:none; }
.sidebar a:hover { background:#143d66; }
.sidebar a.active { background:var(--accent); color:#0b2b4a; font-weight:600; }

/* Main */
.main { margin-left:260px; padding:30px; }

/* Topbar */
.topbar { background:#fff; padding:18px 25px; border-radius:14px; display:flex; justify-content:space-between; align-items:center; box-shadow:0 6px 20px rgba(0,0,0,.06); }
.topbar h2 { margin:0; }

/* Card */
.card { background:#fff; margin-top:25px; border-radius:16px; padding:20px; box-shadow:0 6px 25px rgba(0,0,0,.06); overflow-x:auto; }
table { width:100%; border-collapse:collapse; min-width:900px; }
th, td { padding:15px; text-align:left; vertical-align:middle; }
th { background:#f1f3f8; font-weight:600; }
tr:not(:last-child){ border-bottom:1px solid #eee; }

/* Status */
.badge { padding:6px 14px; border-radius:20px; font-size:13px; font-weight:600; text-transform:capitalize; }
.pending { background:#fff3cd;color:#856404; }
.approved { background:#d4edda;color:#155724; }
.rejected { background:#f8d7da;color:#721c24; }

/* Actions */
.actions { display:flex; gap:10px; }
.btn { border:none; padding:10px 14px; border-radius:10px; cursor:pointer; font-weight:600; transition:0.2s; }
.approve { background:var(--success); color:#fff; }
.approve:hover { background:#218838; }
.reject { background:var(--danger); color:#fff; }
.reject:hover { background:#c82333; }

/* Image */
.kyc-img { max-width:100px; border-radius:10px; object-fit:cover; border:1px solid #ccc; cursor:pointer; transition:0.2s; }
.kyc-img:hover { transform:scale(1.1); }

/* Modal */
.modal { display:none; position:fixed; z-index:1000; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); justify-content:center; align-items:center; }
.modal-content { background:#fff; padding:20px; border-radius:12px; max-width:90%; max-height:90%; }
.modal-content img { width:100%; height:auto; border-radius:10px; }

/* Responsive */
@media(max-width:900px){ .sidebar{position:relative;width:100%;} .main{margin-left:0;} table{font-size:0.8rem;} }
</style>
</head>
<body>

<div class="sidebar">
    <h2>Admin Panel</h2>
    <a href="admin_dashboard.php"><i class="fa fa-chart-line"></i> Dashboard</a>
    <a href="users.php"><i class="fa fa-users"></i> Users</a>
    <a href="deposits.php"><i class="fa fa-wallet"></i> Deposits</a>
    <a href="withdrawals.php"><i class="fa fa-money-bill"></i> Withdrawals</a>
    <a href="kyc_admin.php" class="active"><i class="fa fa-id-card"></i> KYC Documents</a>
</div>

<div class="main">
    <div class="topbar">
        <h2>KYC Documents</h2>
        <strong><?=$_SESSION['admin_name']?></strong>
    </div>

    <?php if(isset($_SESSION['error'])): ?>
        <div style="color:red;margin-top:10px;"><?=$_SESSION['error']?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <div class="card">
        <table>
            <thead>
                <tr>
                    <th>User</th>
                    <th>Front ID</th>
                    <th>Back ID</th>
                    <th>Proof Address</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
            <?php while($k = $kycs->fetch_assoc()): ?>
                <tr>
                    <td><?=$k['name']?><br><small><?=$k['email']?></small></td>
                    <td><img src="../uploads/<?=$k['front_id']?>" class="kyc-img" onclick="openModal(this.src)"></td>
                    <td><img src="../uploads/<?=$k['back_id']?>" class="kyc-img" onclick="openModal(this.src)"></td>
                    <td><img src="../uploads/<?=$k['proof_of_address']?>" class="kyc-img" onclick="openModal(this.src)"></td>
                    <td><span class="badge <?=$k['status']?>"><?=$k['status']?></span></td>
                    <td><?=date("d M Y", strtotime($k['created_at']))?></td>
                    <td>
                        <?php if($k['status']==='pending'): ?>
                        <form method="POST" class="actions" onsubmit="return confirmAction(this)">
                            <input type="hidden" name="kyc_id" value="<?=$k['id']?>">
                            <button name="action" value="approved" class="btn approve"><i class="fa fa-check"></i> Approve</button>
                            <button name="action" value="rejected" class="btn reject"><i class="fa fa-times"></i> Reject</button>
                        </form>
                        <?php else: ?> — <?php endif; ?>
                    </td>
                </tr>
            <?php endwhile; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Image Modal -->
<div id="imgModal" class="modal" onclick="closeModal()">
    <div class="modal-content">
        <img id="modalImg" src="" alt="KYC Document">
    </div>
</div>

<script>
function openModal(src){
    document.getElementById('modalImg').src = src;
    document.getElementById('imgModal').style.display = 'flex';
}
function closeModal(){
    document.getElementById('imgModal').style.display = 'none';
}

function confirmAction(form){
    return confirm('Are you sure you want to ' + form.querySelector('button:focus').innerText + ' this KYC?');
}
</script>
</body>
</html>
