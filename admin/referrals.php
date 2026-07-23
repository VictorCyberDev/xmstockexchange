<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);

session_start();
require __DIR__ . '/../db.php';

/* =========================
   ADMIN AUTH
========================= */
if (!isset($_SESSION['admin_id'])) {
    header("Location: admin-login.php");
    exit();
}

/* =========================
   HANDLE BONUS ACTIONS (AJAX)
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {

    $referrer_id = (int)$_POST['referrer_id'];
    $referred_id = (int)$_POST['referred_id'];
    $amount      = (float)$_POST['amount'];
    $status      = $_POST['status'];

    if (!in_array($status, ['Pending', 'Active', 'Paid'])) {
        echo json_encode(['ok' => false]);
        exit;
    }

    // Check if reward exists
    $check = $conn->prepare("
        SELECT id FROM referral_rewards
        WHERE referrer_id=? AND referred_id=?
    ");
    $check->bind_param("ii", $referrer_id, $referred_id);
    $check->execute();
    $existing = $check->get_result()->fetch_assoc();

    if ($existing) {
        $stmt = $conn->prepare("
            UPDATE referral_rewards
            SET bonus_amount=?, status=?
            WHERE referrer_id=? AND referred_id=?
        ");
        $stmt->bind_param("dsii", $amount, $status, $referrer_id, $referred_id);
    } else {
        $stmt = $conn->prepare("
            INSERT INTO referral_rewards (referrer_id, referred_id, bonus_amount, status)
            VALUES (?, ?, ?, ?)
        ");
        $stmt->bind_param("iids", $referrer_id, $referred_id, $amount, $status);
    }

    $stmt->execute();
    echo json_encode(['ok' => true]);
    exit;
}

/* =========================
   FETCH ALL REFERRALS
========================= */
$sql = "
SELECT 
    r.referrer_id,
    r.referred_id,
    r.created_at AS referral_date,

    ref.name  AS referrer_name,
    ref.email AS referrer_email,

    u.name    AS referred_name,
    u.email   AS referred_email,

    rr.bonus_amount,
    rr.status AS bonus_status

FROM referrals r
JOIN users ref ON ref.id = r.referrer_id
JOIN users u   ON u.id = r.referred_id
LEFT JOIN referral_rewards rr 
    ON rr.referrer_id = r.referrer_id
    AND rr.referred_id = r.referred_id
ORDER BY r.created_at DESC
";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin Referrals</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
body{
    margin:0;
    font-family:Inter,Arial,sans-serif;
    background:#f4f6f9;
}
.wrapper{
    max-width:1200px;
    margin:40px auto;
    padding:20px;
}
.card{
    background:#fff;
    border-radius:14px;
    padding:25px;
    box-shadow:0 10px 30px rgba(0,0,0,.08);
}
h1{margin-bottom:25px}

table{
    width:100%;
    border-collapse:collapse;
}
th,td{
    padding:14px;
    border-bottom:1px solid #eee;
    text-align:center;
    font-size:14px;
}
th{
    background:#f1f4f8;
}

.badge{
    padding:6px 12px;
    border-radius:20px;
    font-size:.85rem;
    font-weight:600;
}
.Pending{background:#fff3cd;color:#856404}
.Active{background:#d4edda;color:#155724}
.Paid{background:#cce5ff;color:#004085}

input[type=number]{
    width:90px;
    padding:8px;
    border-radius:8px;
    border:1px solid #ccc;
}

select{
    padding:8px;
    border-radius:8px;
}

button{
    padding:8px 14px;
    border:none;
    border-radius:8px;
    background:#0d6efd;
    color:#fff;
    font-weight:600;
    cursor:pointer;
}
</style>
</head>

<body>
<div class="wrapper">
<div class="card">

<h1>Referral Management</h1>

<table>
<tr>
    <th>Referrer</th>
    <th>Referred User</th>
    <th>Joined</th>
    <th>Bonus ($)</th>
    <th>Status</th>
    <th>Action</th>
</tr>

<?php while($row = $result->fetch_assoc()): ?>
<tr id="row-<?= $row['referrer_id'].'-'.$row['referred_id'] ?>">
    <td>
        <?= htmlspecialchars($row['referrer_name']) ?><br>
        <small><?= htmlspecialchars($row['referrer_email']) ?></small>
    </td>
    <td>
        <?= htmlspecialchars($row['referred_name']) ?><br>
        <small><?= htmlspecialchars($row['referred_email']) ?></small>
    </td>
    <td><?= date('d M Y', strtotime($row['referral_date'])) ?></td>

    <td>
        <input 
            type="number" 
            step="0.01"
            id="amount-<?= $row['referrer_id'].'-'.$row['referred_id'] ?>"
            value="<?= (float)$row['bonus_amount'] ?>">
    </td>

    <td>
        <select id="status-<?= $row['referrer_id'].'-'.$row['referred_id'] ?>">
            <?php foreach(['Pending','Active','Paid'] as $s): ?>
            <option value="<?= $s ?>" <?= $row['bonus_status']===$s?'selected':'' ?>>
                <?= $s ?>
            </option>
            <?php endforeach; ?>
        </select>
    </td>

    <td>
        <button onclick="saveReward(
            <?= $row['referrer_id'] ?>,
            <?= $row['referred_id'] ?>
        )">Save</button>
    </td>
</tr>
<?php endwhile; ?>

</table>

</div>
</div>

<script>
function saveReward(referrer,referred){
    const key = referrer+'-'+referred;
    const amount = document.getElementById('amount-'+key).value;
    const status = document.getElementById('status-'+key).value;

    fetch('referrals.php',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`ajax=1&referrer_id=${referrer}&referred_id=${referred}&amount=${amount}&status=${status}`
    })
    .then(r=>r.json())
    .then(d=>{
        if(d.ok) alert('Referral reward updated');
    });
}
</script>
</body>
</html>
