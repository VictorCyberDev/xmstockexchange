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
   AJAX APPROVE / REJECT
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax'])) {

    $id     = (int)$_POST['id'];
    $status = $_POST['status'];
    $note   = trim($_POST['note']);

    if (!in_array($status, ['Approved', 'Rejected'])) {
        echo json_encode(['ok' => false]);
        exit;
    }

    $stmt = $conn->prepare("
        UPDATE kyc_requests 
        SET status = ?, admin_note = ?, reviewed_at = NOW()
        WHERE id = ?
    ");
    $stmt->bind_param("ssi", $status, $note, $id);
    $stmt->execute();

    echo json_encode(['ok' => true]);
    exit;
}

/* =========================
   FETCH KYC REQUESTS
========================= */
$sql = "
SELECT 
    k.id,
    k.status,
    k.id_front,
    k.id_back,
    k.address_proof,
    k.created_at,
    u.name,
    u.email
FROM kyc_requests k
JOIN users u ON u.id = k.user_id
ORDER BY k.created_at DESC
";
$result = $conn->query($sql);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Admin KYC Review</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<style>
body{
    margin:0;
    font-family:Inter,Arial,sans-serif;
    background:#0f172a;
    color:#e5e7eb;
}
.wrapper{
    padding:30px;
}
h1{margin-bottom:20px}

.card{
    background:#020617;
    border-radius:16px;
    padding:20px;
    margin-bottom:20px;
    box-shadow:0 15px 40px rgba(0,0,0,.6);
}

.header{
    display:flex;
    justify-content:space-between;
    flex-wrap:wrap;
    gap:10px;
}

.badge{
    padding:6px 14px;
    border-radius:999px;
    font-weight:700;
    font-size:13px;
}
.Pending{background:#facc15;color:#422006}
.Approved{background:#22c55e;color:#052e16}
.Rejected{background:#ef4444;color:#450a0a}

.docs{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
    gap:15px;
    margin-top:20px;
}
.doc{
    background:#020617;
    border:1px solid #334155;
    padding:15px;
    border-radius:12px;
}
.doc a{
    color:#60a5fa;
    font-weight:600;
    text-decoration:none;
}

.actions{
    margin-top:20px;
}
textarea{
    width:100%;
    min-height:80px;
    padding:12px;
    border-radius:10px;
    border:none;
    background:#020617;
    color:#e5e7eb;
    margin-bottom:12px;
}

button{
    padding:14px 20px;
    border:none;
    border-radius:12px;
    font-weight:700;
    cursor:pointer;
    margin-right:10px;
}
.approve{background:#22c55e;color:#052e16}
.reject{background:#ef4444;color:#450a0a}

@media(max-width:600px){
    .wrapper{padding:15px}
}
</style>
</head>

<body>
<div class="wrapper">
<h1>KYC Requests</h1>

<?php while($row = $result->fetch_assoc()): ?>
<div class="card" id="kyc-<?= $row['id'] ?>">

<div class="header">
    <div>
        <strong><?= htmlspecialchars($row['name']) ?></strong><br>
        <small><?= htmlspecialchars($row['email']) ?></small>
    </div>
    <span class="badge <?= $row['status'] ?>" id="status-<?= $row['id'] ?>">
        <?= $row['status'] ?>
    </span>
</div>

<div class="docs">
    <div class="doc">
        ID Front<br>
        <a href="../<?= $row['id_front'] ?>" target="_blank">View</a>
    </div>
    <div class="doc">
        ID Back<br>
        <a href="../<?= $row['id_back'] ?>" target="_blank">View</a>
    </div>
    <div class="doc">
        Proof of Address<br>
        <a href="../<?= $row['address_proof'] ?>" target="_blank">View</a>
    </div>
</div>

<?php if ($row['status'] === 'Pending'): ?>
<div class="actions">
    <textarea placeholder="Admin note (optional)" id="note-<?= $row['id'] ?>"></textarea>
    <button class="approve" onclick="updateKYC(<?= $row['id'] ?>,'Approved')">Approve</button>
    <button class="reject" onclick="updateKYC(<?= $row['id'] ?>,'Rejected')">Reject</button>
</div>
<?php endif; ?>

</div>
<?php endwhile; ?>

</div>

<script>
function updateKYC(id,status){
    const note = document.getElementById('note-'+id)?.value || '';

    if(!confirm(`Confirm ${status}?`)) return;

    fetch('kyc.php',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`ajax=1&id=${id}&status=${status}&note=${encodeURIComponent(note)}`
    })
    .then(r=>r.json())
    .then(d=>{
        if(d.ok){
            document.getElementById('status-'+id).textContent=status;
            document.getElementById('status-'+id).className='badge '+status;
        }
    });
}
</script>
</body>
</html>

