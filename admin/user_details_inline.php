<?php
session_start();
include __DIR__ . '/../db.php';

if(!isset($_SESSION['admin_id'])){
    echo "Access denied";
    exit();
}

if(!isset($_GET['id'])){
    echo "User ID not provided";
    exit();
}

$user_id = (int)$_GET['id'];

// Fetch user basic info
$stmt = $conn->prepare("SELECT id, name, email, status, created_at FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if(!$user){
    echo "User not found";
    exit();
}

// Fetch KYC status
$stmt = $conn->prepare("SELECT front_doc, back_doc, proof_address, kyc_status FROM kyc WHERE user_id=? LIMIT 1");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$kyc = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch referrals
$stmt = $conn->prepare("SELECT COUNT(*) as total_referrals, IFNULL(SUM(bonus_amount),0) as total_bonus FROM referral_rewards WHERE referrer_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$ref = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch deposits
$stmt = $conn->prepare("SELECT COUNT(*) as total_deposits, IFNULL(SUM(amount),0) as total_amount FROM deposits WHERE user_id=? AND status='Approved'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$deposits = $stmt->get_result()->fetch_assoc();
$stmt->close();

// Fetch withdrawals
$stmt = $conn->prepare("SELECT COUNT(*) as total_withdrawals, IFNULL(SUM(amount),0) as total_amount FROM withdrawals WHERE user_id=? AND status='Approved'");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$withdrawals = $stmt->get_result()->fetch_assoc();
$stmt->close();
?>

<div style="display:flex;flex-direction:column;gap:15px;">
    <p><span>Name:</span> <?php echo htmlspecialchars($user['name']); ?></p>
    <p><span>Email:</span> <?php echo htmlspecialchars($user['email']); ?></p>
    <p><span>Status:</span> 
        <?php 
        if($user['status']=='active') echo '<span style="color:green;font-weight:bold;">Active</span>';
        elseif($user['status']=='inactive') echo '<span style="color:red;font-weight:bold;">Inactive</span>';
        else echo '<span style="color:orange;font-weight:bold;">Pending</span>';
        ?>
    </p>
    <p><span>Joined:</span> <?php echo date('d M Y', strtotime($user['created_at'])); ?></p>

    <hr>

    <h3>KYC Documents</h3>
    <?php if($kyc): ?>
        <p><span>Status:</span> <?php echo ucfirst($kyc['kyc_status']); ?></p>
        <p>Front ID: <?php echo $kyc['front_doc'] ? "<a href='../uploads/kyc/".$kyc['front_doc']."' target='_blank'>View</a>" : "Not uploaded"; ?></p>
        <p>Back ID: <?php echo $kyc['back_doc'] ? "<a href='../uploads/kyc/".$kyc['back_doc']."' target='_blank'>View</a>" : "Not uploaded"; ?></p>
        <p>Proof of Address: <?php echo $kyc['proof_address'] ? "<a href='../uploads/kyc/".$kyc['proof_address']."' target='_blank'>View</a>" : "Not uploaded"; ?></p>
    <?php else: ?>
        <p>User has not uploaded KYC documents yet.</p>
    <?php endif; ?>

    <hr>

    <h3>Referrals & Bonuses</h3>
    <p>Total Referrals: <?php echo $ref['total_referrals']; ?></p>
    <p>Total Bonus: $<?php echo number_format($ref['total_bonus'],2); ?></p>

    <hr>

    <h3>Deposits & Withdrawals</h3>
    <p>Total Deposits: <?php echo $deposits['total_deposits']; ?> | Amount: $<?php echo number_format($deposits['total_amount'],2); ?></p>
    <p>Total Withdrawals: <?php echo $withdrawals['total_withdrawals']; ?> | Amount: $<?php echo number_format($withdrawals['total_amount'],2); ?></p>

    <hr>

    <h3>Admin Actions</h3>
    <div style="display:flex;gap:10px;flex-wrap:wrap;">
        <?php if($user['status']=='inactive'): ?>
        <a href="users.php?action=activate&user_id=<?php echo $user['id']; ?>" style="padding:8px 12px;background:green;color:#fff;border-radius:6px;text-decoration:none;">Activate</a>
        <?php else: ?>
        <a href="users.php?action=deactivate&user_id=<?php echo $user['id']; ?>" style="padding:8px 12px;background:orange;color:#fff;border-radius:6px;text-decoration:none;">Deactivate</a>
        <?php endif; ?>
        <a href="users.php?action=delete&user_id=<?php echo $user['id']; ?>" style="padding:8px 12px;background:red;color:#fff;border-radius:6px;text-decoration:none;" onclick="return confirm('Delete this user?')">Delete</a>
    </div>
</div>
