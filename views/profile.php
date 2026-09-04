<?php
// views/profile.php
if (basename($_SERVER['PHP_SELF']) == 'profile.php') {
    exit; // Direct access prevention if routed through dashboard.php
}

$message = '';
$error = '';

// Handle form submission for profile updates
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (empty($username)) {
        $error = "Username cannot be empty.";
    } else {
        try {
            // Check if username is already taken by another user
            $check_stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $check_stmt->execute([$username, $user_id]);
            if ($check_stmt->fetch()) {
                $error = "This username is already taken.";
            } else {
                // If updating password
                if (!empty($current_password) || !empty($new_password)) {
                    $pass_stmt = $conn->prepare("SELECT password FROM users WHERE id = ?");
                    $pass_stmt->execute([$user_id]);
                    $user_data = $pass_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($user_data && password_verify($current_password, $user_data['password'])) {
                        if ($new_password === $confirm_password) {
                            if (strlen($new_password) >= 6) {
                                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                                $update_stmt = $conn->prepare("UPDATE users SET username = ?, password = ? WHERE id = ?");
                                $update_stmt->execute([$username, $hashed_password, $user_id]);
                                $_SESSION['username'] = $username;
                                $message = "Profile and password updated successfully.";
                            } else {
                                $error = "New password must be at least 6 characters long.";
                            }
                        } else {
                            $error = "New passwords do not match.";
                        }
                    } else {
                        $error = "Incorrect current password.";
                    }
                } else {
                    // Update username only
                    $update_stmt = $conn->prepare("UPDATE users SET username = ? WHERE id = ?");
                    $update_stmt->execute([$username, $user_id]);
                    $_SESSION['username'] = $username;
                    $message = "Profile updated successfully.";
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch latest user details
try {
    $user_stmt = $conn->prepare("SELECT username, role, created_at FROM users WHERE id = ?");
    $user_stmt->execute([$user_id]);
    $current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $current_user = ['username' => $_SESSION['username'], 'role' => $role, 'created_at' => 'N/A'];
}
?>

<div class="container-fluid py-4">
    <div class="row">
        <div class="col-md-8 mx-auto">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-white py-3">
                    <h4 class="mb-0 font-weight-bold" style="color: var(--primary-color);">My Account Profile</h4>
                </div>
                <div class="card-body">
                    <?php if (!empty($message)): ?>
                        <div class="alert alert-success" role="alert">
                            <?= htmlspecialchars($message) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($error)): ?>
                        <div class="alert alert-danger" role="alert">
                            <?= htmlspecialchars($error) ?>
                        </div>
                    <?php endif; ?>

                    <form action="dashboard.php?page=profile" method="POST">
                        <div class="form-group mb-3">
                            <label class="form-label font-weight-bold">Role</label>
                            <input type="text" class="form-control" value="<?= ucfirst(htmlspecialchars($current_user['role'])) ?>" disabled>
                            <small class="text-muted">Account role is managed by system administrators.</small>
                        </div>

                        <div class="form-group mb-3">
                            <label for="username" class="form-label font-weight-bold">Username</label>
                            <input type="text" class="form-control" id="username" name="username" value="<?= htmlspecialchars($current_user['username']) ?>" required>
                        </div>

                        <hr class="my-4">

                        <h5 class="mb-3 text-secondary">Change Password</h5>
                        <div class="form-group mb-3">
                            <label for="current_password" class="form-label">Current Password</label>
                            <input type="password" class="form-control" id="current_password" name="current_password" placeholder="Enter current password to change">
                        </div>

                        <div class="form-group mb-3">
                            <label for="new_password" class="form-label">New Password</label>
                            <input type="password" class="form-control" id="new_password" name="new_password" placeholder="Leave blank to keep current">
                        </div>

                        <div class="form-group mb-4">
                            <label for="confirm_password" class="form-label">Confirm New Password</label>
                            <input type="password" class="form-control" id="confirm_password" name="confirm_password" placeholder="Confirm new password">
                        </div>

                        <button type="submit" class="btn btn-primary px-4" style="background-color: var(--primary-color); border: none;">Save Changes</button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>