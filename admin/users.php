<?php
session_start();
include __DIR__ . '/../db.php';

if(!isset($_SESSION['admin_id'])){
    header("Location: admin_login.php");
    exit();
}

// Logic for updates (Balance & Profile)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_user'])) {
    $uid = (int)$_POST['user_id'];
    $balance = floatval($_POST['balance']);
    $name = htmlspecialchars($_POST['name']);
    
    $stmt = $conn->prepare("UPDATE users SET balance=?, name=? WHERE id=?");
    $stmt->bind_param("dsi", $balance, $name, $uid);
    $stmt->execute();
    header("Location: admin_users.php?msg=updated");
    exit();
}

// Handling simple actions (Toggle Status / Delete)
if(isset($_GET['action'], $_GET['user_id'])){
    $user_id = (int)$_GET['user_id'];
    $msg_type = "";

    if($_GET['action'] === 'activate') {
        $status = 'active';
        $msg_type = "activated";
    }
    elseif($_GET['action'] === 'deactivate') {
        $status = 'inactive';
        $msg_type = "deactivated";
    }
    
    if(isset($status)){
        $stmt = $conn->prepare("UPDATE users SET status=? WHERE id=?");
        $stmt->bind_param("si", $status, $user_id);
    } elseif($_GET['action'] === 'delete'){
        $stmt = $conn->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param("i", $user_id);
        $msg_type = "deleted";
    }
    $stmt->execute();
    header("Location: admin_users.php?msg=" . $msg_type);
    exit();
}

$users_res = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Investors | Xmstockexchange Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.6.0/css/all.min.css">
    <style>
        :root { --admin-navy: #0a2540; --admin-gold: #c5a059; --admin-bg: #f8f9fc; }
        body { background: var(--admin-bg); font-family: 'Inter', sans-serif; }
        .sidebar { width: 260px; background: var(--admin-navy); min-height: 100vh; position: fixed; transition: 0.3s; z-index: 1000; }
        .content { margin-left: 260px; padding: 30px; transition: 0.3s; }
        .nav-link { color: rgba(255,255,255,0.7); padding: 12px 20px; border-radius: 8px; margin: 4px 15px; }
        .nav-link:hover, .nav-link.active { background: rgba(255,255,255,0.1); color: #fff; }
        .nav-link.active { border-left: 4px solid var(--admin-gold); }
        .user-card { background: #fff; border: none; border-radius: 15px; box-shadow: 0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.1); }
        .status-badge { padding: 5px 12px; border-radius: 50px; font-size: 0.8rem; font-weight: 600; }
        .bg-active { background: #d1e7dd; color: #0f5132; }
        .bg-inactive { background: #f8d7da; color: #842029; }
        @media (max-width: 992px) { .sidebar { margin-left: -260px; } .sidebar.active { margin-left: 0; } .content { margin-left: 0; } }
    </style>
</head>
<body>

<div class="sidebar" id="sidebar">
    <div class="p-4 text-white">
        <h4 class="fw-bold"><i class="fa fa-shield-halved text-warning me-2"></i> Xmstockexchange</h4>
    </div>
    <nav class="nav flex-column">
        <a href="admin_dashboard.php" class="nav-link"><i class="fa fa-tachometer-alt me-2"></i> Dashboard</a>
        <a href="admin_users.php" class="nav-link active"><i class="fa fa-users me-2"></i> Investors</a>
        <a href="deposits.php" class="nav-link"><i class="fa fa-wallet me-2"></i> Deposits</a>
        <a href="logout.php" class="nav-link text-danger mt-5"><i class="fa fa-sign-out-alt me-2"></i> Logout</a>
    </nav>
</div>

<div class="content">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <button class="btn btn-dark d-lg-none" onclick="toggleSidebar()"><i class="fa fa-bars"></i></button>
        <h2 class="fw-bold">User Management</h2>
        <div class="dropdown">
            <img src="https://ui-avatars.com/api/?name=Admin" class="rounded-circle" width="40">
        </div>
    </div>

    <div class="card user-card">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="bg-light">
                        <tr>
                            <th class="ps-4">Investor</th>
                            <th>Status</th>
                            <th>Balance</th>
                            <th>Joined</th>
                            <th class="text-end pe-4">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php while($user = $users_res->fetch_assoc()): ?>
                        <tr>
                            <td class="ps-4">
                                <div class="d-flex align-items-center">
                                    <img src="https://ui-avatars.com/api/?name=<?php echo $user['name']; ?>" class="rounded-circle me-3" width="40">
                                    <div>
                                        <div class="fw-bold"><?php echo htmlspecialchars($user['name']); ?></div>
                                        <div class="text-muted small"><?php echo $user['email']; ?></div>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <span class="status-badge bg-<?php echo $user['status']; ?>">
                                    <?php echo ucfirst($user['status']); ?>
                                </span>
                            </td>
                            <td class="fw-bold text-navy">$<?php echo number_format($user['balance'] ?? 0, 2); ?></td>
                            <td class="text-muted small"><?php echo date('M d, Y', strtotime($user['created_at'])); ?></td>
                            <td class="text-end pe-4">
                                <button class="btn btn-sm btn-outline-primary me-1" onclick="editUser(<?php echo htmlspecialchars(json_encode($user)); ?>)">
                                    <i class="fa fa-edit"></i>
                                </button>
                                <?php if($user['status'] == 'active'): ?>
                                    <a href="?action=deactivate&user_id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-warning"><i class="fa fa-ban"></i></a>
                                <?php else: ?>
                                    <a href="?action=activate&user_id=<?php echo $user['id']; ?>" class="btn btn-sm btn-outline-success"><i class="fa fa-check"></i></a>
                                <?php endif; ?>
                                <button class="btn btn-sm btn-outline-danger ms-1" onclick="confirmDelete(<?php echo $user['id']; ?>)">
                                    <i class="fa fa-trash"></i>
                                </button>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <form method="POST">
                <div class="modal-header bg-navy text-white" style="background: #0a2540;">
                    <h5 class="modal-title">Edit Account</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body p-4">
                    <input type="hidden" name="user_id" id="edit_id">
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Full Name</label>
                        <input type="text" name="name" id="edit_name" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-bold small">Account Balance ($)</label>
                        <div class="input-group">
                            <span class="input-group-text">$</span>
                            <input type="number" step="0.01" name="balance" id="edit_balance" class="form-control" required>
                        </div>
                    </div>
                </div>
                <div class="modal-footer bg-light">
                    <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="update_user" class="btn btn-primary px-4">Save Changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<script>
    const modalElement = document.getElementById('editModal');
    const modal = new bootstrap.Modal(modalElement);
    const editForm = modalElement.querySelector('form');

    function toggleSidebar() {
        document.getElementById('sidebar').classList.toggle('active');
    }

    function editUser(user) {
        document.getElementById('edit_id').value = user.id;
        document.getElementById('edit_name').value = user.name;
        document.getElementById('edit_balance').value = user.balance;
        modal.show();
    }

    // Fixed AJAX Logic
    editForm.addEventListener('submit', function(e) {
        e.preventDefault(); 

        const formData = new FormData(this);
        formData.append('update_user', '1'); 

        fetch(window.location.href, {
            method: 'POST',
            body: formData,
            redirect: 'manual' // <--- This tells JS not to worry about the PHP header redirect
        })
        .then(() => {
            // We show the success popup immediately because we know the request was sent
            modal.hide();
            Swal.fire({
                icon: 'success',
                title: 'Changes Saved',
                text: 'The user details have been updated.',
                timer: 1500,
                showConfirmButton: false
            }).then(() => {
                // Now we refresh to show the new data without the URL changing to ?msg=updated
                window.location.href = 'users.php'; 
            });
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Could not connect to server', 'error');
        });
    });

    // Handle Delete and Status Toggles with Popups
    function confirmDelete(id) {
        actionRequest(`?action=delete&user_id=${id}`, 'Deleted!', 'User has been removed.');
    }

    // Helper to handle the background requests for Status/Delete
    function actionRequest(url, title, text) {
        Swal.fire({
            title: 'Are you sure?',
            icon: 'warning',
            showCancelButton: true,
            confirmButtonColor: '#0a2540',
            confirmButtonText: 'Yes, proceed'
        }).then((result) => {
            if (result.isConfirmed) {
                fetch(url, { redirect: 'manual' })
                .then(() => {
                    Swal.fire({
                        icon: 'success',
                        title: title,
                        text: text,
                        timer: 1500,
                        showConfirmButton: false
                    }).then(() => {
                        window.location.href = 'users.php';
                    });
                });
            }
        });
    }
</script>
</body>
</html>