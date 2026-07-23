<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
session_start();
require __DIR__ . '/db.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = $_SESSION['user_id'];

/* =========================
   FETCH EXISTING KYC
========================= */
$stmt = $conn->prepare("SELECT * FROM kyc_requests WHERE user_id=?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$kyc = $stmt->get_result()->fetch_assoc();

/* =========================
   HANDLE UPLOAD (ONLY ONCE)
========================= */
if (!$kyc && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $uploadDir = __DIR__ . '/uploads/kyc/';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    function uploadFile($file, $dir) {
        if ($file['error'] !== 0) {
            return false;
        }
        $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
        $safeName = uniqid('kyc_', true) . '.' . $ext;
        $path = $dir . $safeName;
        move_uploaded_file($file['tmp_name'], $path);
        return 'uploads/kyc/' . $safeName;
    }

    $id_front = uploadFile($_FILES['id_front'], $uploadDir);
    $id_back  = uploadFile($_FILES['id_back'], $uploadDir);
    $address  = uploadFile($_FILES['address_proof'], $uploadDir);

    if ($id_front && $id_back && $address) {
        $stmt = $conn->prepare("
            INSERT INTO kyc_requests (user_id, id_front, id_back, address_proof, status)
            VALUES (?, ?, ?, ?, 'Pending')
        ");
        $stmt->bind_param("isss", $user_id, $id_front, $id_back, $address);
        $stmt->execute();
        header("Location: kyc.php");
        exit();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>KYC Verification</title>
<meta name="viewport" content="width=device-width, initial-scale=1">

<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap" rel="stylesheet">

<style>
body{
    margin:0;
    font-family:'Inter',sans-serif;
    background:linear-gradient(135deg,#0f172a,#020617);
    color:#e5e7eb;
}
.container{
    max-width:950px;
    margin:40px auto;
    padding:20px;
}
.card{
    background:#020617;
    border-radius:20px;
    padding:35px;
    box-shadow:0 20px 50px rgba(0,0,0,.6);
}
h1{margin-top:0}
.badge{
    display:inline-block;
    padding:8px 16px;
    border-radius:999px;
    font-weight:700;
    margin-bottom:20px;
}
.Pending{background:#facc15;color:#422006}
.Approved{background:#22c55e;color:#052e16}
.Rejected{background:#ef4444;color:#450a0a}

.grid{
    display:grid;
    grid-template-columns:repeat(auto-fit,minmax(240px,1fr));
    gap:20px;
}
.upload-box{
    background:#020617;
    border:2px dashed #334155;
    padding:25px;
    border-radius:16px;
    text-align:center;
    transition:.3s;
}
.upload-box:hover{border-color:#3b82f6}
input[type=file]{margin-top:12px;color:#e5e7eb}

button{
    width:100%;
    margin-top:30px;
    padding:16px;
    background:#3b82f6;
    border:none;
    border-radius:14px;
    color:#fff;
    font-size:17px;
    font-weight:700;
    cursor:pointer;
}
button:hover{background:#2563eb}

.doc-link{
    display:block;
    margin-top:12px;
    color:#60a5fa;
    text-decoration:none;
    font-weight:600;
}

.note{
    margin-top:20px;
    background:#020617;
    border-left:4px solid #f97316;
    padding:15px;
    border-radius:10px;
}

@media(max-width:600px){
    .card{padding:25px}
}
</style>
</head>

<body>
<div class="container">
<div class="card">

<h1>KYC Verification</h1>

<?php if ($kyc): ?>

<span class="badge <?= $kyc['status'] ?>"><?= $kyc['status'] ?></span>

<p>Your documents have been submitted. Uploading is locked.</p>

<h3>Uploaded Documents</h3>
<a class="doc-link" href="<?= $kyc['id_front'] ?>" target="_blank">View ID Front</a>
<a class="doc-link" href="<?= $kyc['id_back'] ?>" target="_blank">View ID Back</a>
<a class="doc-link" href="<?= $kyc['address_proof'] ?>" target="_blank">View Proof of Address</a>

<?php if (!empty($kyc['admin_note'])): ?>
<div class="note">
<strong>Admin Note:</strong><br>
<?= htmlspecialchars($kyc['admin_note']) ?>
</div>
<?php endif; ?>

<?php else: ?>

<p>Please upload your KYC documents. You can only submit once.</p>

<form method="POST" enctype="multipart/form-data">
<div class="grid">
    <div class="upload-box">
        <strong>ID Front</strong>
        <input type="file" name="id_front" required>
    </div>
    <div class="upload-box">
        <strong>ID Back</strong>
        <input type="file" name="id_back" required>
    </div>
    <div class="upload-box">
        <strong>Proof of Address</strong>
        <input type="file" name="address_proof" required>
    </div>
</div>

<button type="submit">Submit KYC</button>
</form>

<?php endif; ?>

</div>
</div>
</body>
</html>

