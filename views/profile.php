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
    $phone_number = trim($_POST['phone_number'] ?? '');
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
                                $update_stmt = $conn->prepare("UPDATE users SET username = ?, phone_number = ?, password = ? WHERE id = ?");
                                $update_stmt->execute([$username, $phone_number, $hashed_password, $user_id]);
                                $_SESSION['username'] = $username;
                                $message = "Profile, contact number, and password updated successfully.";
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
                    // Update username and phone number only
                    $update_stmt = $conn->prepare("UPDATE users SET username = ?, phone_number = ? WHERE id = ?");
                    $update_stmt->execute([$username, $phone_number, $user_id]);
                    $_SESSION['username'] = $username;
                    $message = "Profile and contact number updated successfully.";
                }
            }
        } catch (PDOException $e) {
            $error = "Database error: " . $e->getMessage();
        }
    }
}

// Fetch latest user details
try {
    $user_stmt = $conn->prepare("SELECT username, role, phone_number, created_at FROM users WHERE id = ?");
    $user_stmt->execute([$user_id]);
    $current_user = $user_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $current_user = ['username' => $_SESSION['username'] ?? '', 'role' => $role ?? 'farmer', 'phone_number' => '', 'created_at' => 'N/A'];
}
?>

<div class="sub-view-panel-container" style="padding: 10px 5px;">
    <div class="view-panel-header" style="margin-bottom: 25px;">
        <h3 style="color: var(--primary-color); font-weight: 700; font-size: 1.5rem; margin-bottom: 6px;">My Account Profile</h3>
        <p style="color: #657765; font-size: 0.95rem; margin: 0;">Manage your account credentials, security settings, SMS alert contact number, and access role.</p>
    </div>

    <?php if (!empty($message)): ?>
        <div style="background-color: #e8f5e9; border-left: 4px solid #2e7d32; color: #1b5e20; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 0.95rem; display: flex; align-items: center; gap: 10px;">
            <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"></path><polyline points="22 4 12 14.01 9 11.01"></polyline></svg>
            <span><?= htmlspecialchars($message) ?></span>
        </div>
    <?php endif; ?>

    <?php if (!empty($error)): ?>
        <div style="background-color: #ffebee; border-left: 4px solid #c62828; color: #b71c1c; padding: 14px 18px; border-radius: 8px; margin-bottom: 20px; font-size: 0.95rem; display: flex; align-items: center; gap: 10px;">
            <svg viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>
            <span><?= htmlspecialchars($error) ?></span>
        </div>
    <?php endif; ?>

    <div style="background: #ffffff; border: 1px solid #d9e2d9; border-radius: 16px; padding: 30px; box-shadow: 0 4px 20px rgba(0,0,0,0.03); max-width: 750px;">
        <form action="dashboard.php?page=profile" method="POST">
            
            <div style="margin-bottom: 22px;">
                <label style="display: block; font-weight: 600; color: #2c3e2c; margin-bottom: 8px; font-size: 0.9rem;">Account Role</label>
                <div style="background: #f4f7f4; border: 1px solid #e2e8e2; border-radius: 10px; padding: 12px 15px; color: #556b55; font-weight: 500; display: flex; align-items: center; justify-content: space-between;">
                    <span><?= ucfirst(htmlspecialchars($current_user['role'] ?? 'farmer')) ?></span>
                    <span style="font-size: 0.8rem; background: #e2ede2; color: #2e5a2e; padding: 3px 8px; border-radius: 6px;">System Managed</span>
                </div>
            </div>

            <div style="margin-bottom: 25px;">
                <label for="username" style="display: block; font-weight: 600; color: #2c3e2c; margin-bottom: 8px; font-size: 0.9rem;">Username</label>
                <input type="text" id="username" name="username" value="<?= htmlspecialchars($current_user['username'] ?? '') ?>" required style="width: 100%; padding: 12px 15px; border: 1px solid #ccdccb; border-radius: 10px; font-size: 0.95rem; background: #fafbfc; color: #2c3e2c; outline: none; transition: border-color 0.2s;">
            </div>

            <div style="margin-bottom: 25px;">
                <label for="phone_number" style="display: block; font-weight: 600; color: #2c3e2c; margin-bottom: 8px; font-size: 0.9rem;">Mobile Number for SMS Alerts</label>
                <input type="text" id="phone_number" name="phone_number" value="<?= htmlspecialchars($current_user['phone_number'] ?? '') ?>" placeholder="09XXXXXXXXX" style="width: 100%; padding: 12px 15px; border: 1px solid #ccdccb; border-radius: 10px; font-size: 0.95rem; background: #fafbfc; color: #2c3e2c; outline: none; transition: border-color 0.2s;">
                <span style="display: block; font-size: 0.78rem; color: #657765; margin-top: 5px;">Required for receiving automated SMS notifications during critical soil telemetry states.</span>
            </div>

            <div style="border-top: 1px solid #edf2ed; margin: 30px 0; padding-top: 25px;">
                <h5 style="color: #2c3e2c; font-weight: 600; font-size: 1.1rem; margin-bottom: 6px;">Security & Password</h5>
                <p style="color: #657765; font-size: 0.85rem; margin-bottom: 20px;">Leave blank if you do not want to modify your current password.</p>
                
                <div style="margin-bottom: 20px;">
                    <label for="current_password" style="display: block; font-weight: 600; color: #2c3e2c; margin-bottom: 8px; font-size: 0.9rem;">Current Password</label>
                    <div style="position: relative;">
                        <input type="password" id="current_password" name="current_password" placeholder="••••••••••••" style="width: 100%; padding: 12px 45px 12px 15px; border: 1px solid #ccdccb; border-radius: 10px; font-size: 0.95rem; background: #fafbfc; color: #2c3e2c; outline: none;">
                        <button type="button" onclick="togglePassword('current_password', this)" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #657765; padding: 0; display: flex; align-items: center;">
                            <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                        </button>
                    </div>
                </div>

                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 25px;">
                    <div>
                        <label for="new_password" style="display: block; font-weight: 600; color: #2c3e2c; margin-bottom: 8px; font-size: 0.9rem;">New Password</label>
                        <div style="position: relative;">
                            <input type="password" id="new_password" name="new_password" placeholder="Min. 6 characters" style="width: 100%; padding: 12px 45px 12px 15px; border: 1px solid #ccdccb; border-radius: 10px; font-size: 0.95rem; background: #fafbfc; color: #2c3e2c; outline: none;">
                            <button type="button" onclick="togglePassword('new_password', this)" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #657765; padding: 0; display: flex; align-items: center;">
                                <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label for="confirm_password" style="display: block; font-weight: 600; color: #2c3e2c; margin-bottom: 8px; font-size: 0.9rem;">Confirm Password</label>
                        <div style="position: relative;">
                            <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat new password" style="width: 100%; padding: 12px 45px 12px 15px; border: 1px solid #ccdccb; border-radius: 10px; font-size: 0.95rem; background: #fafbfc; color: #2c3e2c; outline: none;">
                            <button type="button" onclick="togglePassword('confirm_password', this)" style="position: absolute; right: 12px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: #657765; padding: 0; display: flex; align-items: center;">
                                <svg class="eye-icon" viewBox="0 0 24 24" width="20" height="20" stroke="currentColor" stroke-width="2" fill="none"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle></svg>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <div style="display: flex; justify-content: flex-end; gap: 12px;">
                <button type="submit" style="background-color: var(--primary-color); color: white; border: none; padding: 12px 24px; border-radius: 10px; font-weight: 600; font-size: 0.95rem; cursor: pointer; box-shadow: 0 4px 12px rgba(46, 125, 50, 0.2); transition: opacity 0.2s;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePassword(fieldId, btn) {
    const inputField = document.getElementById(fieldId);
    const svgIcon = btn.querySelector('.eye-icon');
    
    if (inputField.type === 'password') {
        inputField.type = 'text';
        svgIcon.innerHTML = '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"></path><line x1="1" y1="1" x2="23" y2="23"></line>';
        svgIcon.style.color = 'var(--primary-color)';
    } else {
        inputField.type = 'password';
        svgIcon.innerHTML = '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"></path><circle cx="12" cy="12" r="3"></circle>';
        svgIcon.style.color = '#657765';
    }
}
</script>