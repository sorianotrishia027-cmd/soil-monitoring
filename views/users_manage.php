<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
include "../config/db_connect.php";

if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    echo "<p class='error'>Access Denied. Administrative clearance required.</p>";
    exit;
}

// Auto-add contact_number column if it doesn't exist yet (no manual SQL needed)
try {
    $conn->exec("ALTER TABLE users ADD COLUMN contact_number VARCHAR(20) DEFAULT NULL");
} catch (PDOException $e) {
    // Column already exists — ignore
}

$action_msg = "";

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_action'])) {
    $action = $_POST['form_action'];

    // CREATE USER
    if ($action === 'create_user') {
        $username       = trim($_POST['username']       ?? '');
        $email          = trim($_POST['email']          ?? '');
        $fullname       = trim($_POST['fullname']       ?? '');
        $password       = $_POST['password']            ?? '';
        $role           = $_POST['role']                ?? 'farmer';
        $contact_number = trim($_POST['contact_number'] ?? '');

        if (!empty($username) && !empty($email) && !empty($password)) {
            try {
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("INSERT INTO users (username, email, fullname, password, role, contact_number) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$username, $email, $fullname, $hashed_password, $role, $contact_number]);
                $action_msg = "<div class='alert success'>Account for '$username' registered successfully.</div>";
            } catch (PDOException $e) {
                $action_msg = "<div class='alert danger'>Registration error: " . $e->getMessage() . "</div>";
            }
        } else {
            $action_msg = "<div class='alert warning'>Please populate all required entry slots.</div>";
        }
    }

    // UPDATE USER
    if ($action === 'update_user') {
        $id             = intval($_POST['user_id']        ?? 0);
        $role           = $_POST['role']                  ?? 'farmer';
        $fullname       = trim($_POST['fullname']         ?? '');
        $contact_number = trim($_POST['contact_number']   ?? '');

        try {
            $stmt = $conn->prepare("UPDATE users SET role = ?, fullname = ?, contact_number = ? WHERE id = ?");
            $stmt->execute([$role, $fullname, $contact_number, $id]);
            $action_msg = "<div class='alert success'>Account updates applied successfully.</div>";
        } catch (PDOException $e) {
            $action_msg = "<div class='alert danger'>Update failed: " . $e->getMessage() . "</div>";
        }
    }

    // DELETE USER
    if ($action === 'delete_user') {
        $id = intval($_POST['user_id'] ?? 0);

        if ($id === intval($_SESSION['user_id'])) {
            $action_msg = "<div class='alert danger'>Operational error: You cannot drop your own active root session profile.</div>";
        } else {
            try {
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $action_msg = "<div class='alert success'>Account systematically purged from records.</div>";
            } catch (PDOException $e) {
                $action_msg = "<div class='alert danger'>Deletion failed: " . $e->getMessage() . "</div>";
            }
        }
    }
}

$users_list = $conn->query("SELECT id, username, email, fullname, role, contact_number FROM users ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="sub-view-panel-container">
    <div class="view-panel-header">
        <h3>User Account Management Control Panel</h3>
        <p>System access control: Provision new accounts, update clearance roles, or revoke cooperative database entries.</p>
    </div>

    <?= $action_msg ?>

    <div class="insights-dashboard-split-row" style="margin-bottom: 30px;">

        <!-- Register New User Form -->
        <div class="action-alert-panel-card" style="background: #ffffff; border: 1px solid #ccd4cc;">
            <h3 style="margin-bottom: 15px; color: var(--primary-color);">Register New User</h3>
            <form action="dashboard.php?page=users_manage" method="POST">
                <input type="hidden" name="form_action" value="create_user">

                <div class="input-wrapper" style="background: #f4f6f4;">
                    <input type="text" name="fullname" placeholder="Full Name (e.g. Juan Dela Cruz)">
                </div>
                <div class="input-wrapper" style="background: #f4f6f4;">
                    <input type="text" name="username" placeholder="Username" required>
                </div>
                <div class="input-wrapper" style="background: #f4f6f4;">
                    <input type="email" name="email" placeholder="Email Address" required>
                </div>
                <div class="input-wrapper" style="background: #f4f6f4;">
                    <input type="tel" name="contact_number" placeholder="Contact Number (e.g. 09XXXXXXXXX)" maxlength="20">
                </div>
                <div class="input-wrapper" style="background: #f4f6f4;">
                    <input type="password" name="password" placeholder="Temporary Password" required>
                </div>

                <div class="role-selection-group" style="margin-top: 10px; text-align: left;">
                    <span class="chip-label" style="display:block; margin-bottom: 5px;">Assigned Portal Scope:</span>
                    <div class="grid-two-columns">
                        <label class="selector-card">
                            <input type="radio" name="role" value="farmer" checked> Farmer
                        </label>
                        <label class="selector-card">
                            <input type="radio" name="role" value="admin"> Admin
                        </label>
                    </div>
                </div>

                <button type="submit" class="mockup-login-btn" style="margin-top: 10px;">Provision Account</button>
            </form>
        </div>

        <!-- Operational Directives -->
        <div class="action-alert-panel-card" style="background: #ffffff; border: 1px solid #ccd4cc; display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <h3 style="margin-bottom: 15px;">Operational Directives</h3>
                <p style="font-size: 14px; line-height: 1.5; color: var(--text-muted);">
                    When updating user details or removing old profiles, double-check profiles to maintain accurate data mapping. Deleting a farmer's account completely cleans up their assigned entries from the historical system.
                </p>
                <p style="font-size: 13px; line-height: 1.6; color: #0b8a47; margin-top: 12px;">
                    📱 <strong>SMS Alerts:</strong> The contact number registered here will receive soil sensor SMS notifications from the ESP32 device automatically.
                </p>
            </div>
            <div class="nested-sub-recommends-box" style="border-left-color: #1565c0; margin-top: 20px;">
                <span class="muted-title">COOPERATIVE METRICS</span>
                <p style="font-weight: bold; font-size: 16px; margin-top: 5px;">Total Profiles Linked: <?= count($users_list) ?></p>
            </div>
        </div>
    </div>

    <!-- Registered Profiles Table -->
    <div class="view-panel-header">
        <h3>Registered Cooperative Profiles</h3>
    </div>

    <div class="history-table-wrapper" style="overflow-x: auto; background: #fff; padding: 15px; border-radius: 16px; border: 1px solid #ccd4cc;">
        <table style="width: 100%; border-collapse: collapse; text-align: left; font-size: 14px;">
            <thead>
                <tr style="border-bottom: 2px solid #e2e8e2; color: #424242;">
                    <th style="padding: 12px;">ID</th>
                    <th style="padding: 12px;">Full Name</th>
                    <th style="padding: 12px;">Username</th>
                    <th style="padding: 12px;">Email</th>
                    <th style="padding: 12px;">📱 Contact No.</th>
                    <th style="padding: 12px;">Role</th>
                    <th style="padding: 12px; text-align: center;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users_list as $row): ?>
                    <tr style="border-bottom: 1px solid #f0f4f0;">
                        <td style="padding: 12px; color: var(--text-muted);"><?= $row['id'] ?></td>
                        <td style="padding: 12px; font-weight: 600;"><?= htmlspecialchars($row['fullname'] ?: 'No Name Provided') ?></td>
                        <td style="padding: 12px;"><?= htmlspecialchars($row['username']) ?></td>
                        <td style="padding: 12px; color: var(--text-muted);"><?= htmlspecialchars($row['email']) ?></td>
                        <td style="padding: 12px;">
                            <?php if (!empty($row['contact_number'])): ?>
                                <span style="background: #e8f5e9; color: #2e7d32; padding: 3px 8px; border-radius: 20px; font-size: 12px; font-weight: 600;">
                                    📱 <?= htmlspecialchars($row['contact_number']) ?>
                                </span>
                            <?php else: ?>
                                <span style="color: #bbb; font-size: 12px;">— not set —</span>
                            <?php endif; ?>
                        </td>
                        <td style="padding: 12px;">
                            <span class="status-pill" style="background: <?= $row['role'] === 'admin' ? '#e3f2fd; color: #0d47a1;' : '#e8f5e9; color: #2e7d32;' ?>">
                                <?= ucfirst(htmlspecialchars($row['role'])) ?>
                            </span>
                        </td>
                        <td style="padding: 12px; text-align: center;">
                            <button class="status-pill" style="background: #e4ebe4; color: #333; border: none; cursor: pointer; padding: 5px 10px; margin-right: 4px;"
                                    onclick="openEditUserModal(<?= $row['id'] ?>, '<?= addslashes($row['fullname']) ?>', '<?= $row['role'] ?>', '<?= addslashes($row['contact_number'] ?? '') ?>')">
                                Edit
                            </button>
                            <form action="dashboard.php?page=users_manage" method="POST" style="display:inline;" onsubmit="return confirm('Are you sure you want to completely delete this user row record?');">
                                <input type="hidden" name="form_action" value="delete_user">
                                <input type="hidden" name="user_id" value="<?= $row['id'] ?>">
                                <button type="submit" class="status-pill" style="background: #ffebee; color: #c62828; border: none; cursor: pointer; padding: 5px 10px;">
                                    Revoke
                                </button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit User Modal -->
<div id="editUserModal" style="display: none; position: fixed; z-index: 10000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5); align-items: center; justify-content: center;">
    <div class="action-alert-panel-card" style="background: #ffffff; max-width: 400px; width: 90%; border-radius: 20px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); padding: 30px;">
        <h3 style="margin-bottom: 15px; color: var(--primary-color);">Update User Profile</h3>
        <form action="dashboard.php?page=users_manage" method="POST">
            <input type="hidden" name="form_action" value="update_user">
            <input type="hidden" name="user_id" id="modal_user_id">

            <label class="chip-label" style="text-align: left; display: block; margin-bottom: 5px;">Display Full Name:</label>
            <div class="input-wrapper" style="background: #f4f6f4;">
                <input type="text" name="fullname" id="modal_fullname" placeholder="Full Name" required>
            </div>

            <label class="chip-label" style="text-align: left; display: block; margin-bottom: 5px; margin-top: 12px;">📱 Contact Number (for SMS):</label>
            <div class="input-wrapper" style="background: #f4f6f4;">
                <input type="tel" name="contact_number" id="modal_contact_number" placeholder="09XXXXXXXXX" maxlength="20">
            </div>

            <div class="role-selection-group" style="margin-top: 15px; text-align: left;">
                <span class="chip-label" style="display:block; margin-bottom: 5px;">System Security Privilege:</span>
                <div class="grid-two-columns">
                    <label class="selector-card">
                        <input type="radio" name="role" value="farmer" id="modal_role_farmer"> Farmer
                    </label>
                    <label class="selector-card">
                        <input type="radio" name="role" value="admin" id="modal_role_admin"> Admin
                    </label>
                </div>
            </div>

            <div style="display: flex; gap: 10px; margin-top: 25px;">
                <button type="button" class="mockup-login-btn" style="background: #ccd4cc !important; color: #333;" onclick="closeEditUserModal()">Cancel</button>
                <button type="submit" class="mockup-login-btn">Apply Updates</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditUserModal(id, fullname, role, contact_number) {
    document.getElementById('modal_user_id').value = id;
    document.getElementById('modal_fullname').value = fullname;
    document.getElementById('modal_contact_number').value = contact_number;

    if (role.toLowerCase() === 'admin') {
        document.getElementById('modal_role_admin').checked = true;
    } else {
        document.getElementById('modal_role_farmer').checked = true;
    }

    document.getElementById('editUserModal').style.display = 'flex';
}

function closeEditUserModal() {
    document.getElementById('editUserModal').style.display = 'none';
}
</script>