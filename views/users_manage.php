<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($conn)) {
    require_once __DIR__ . '/../config/db_connect.php';
}

if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    echo "<p class='alert danger'>Access Denied. Administrative clearance required.</p>";
    exit;
}

// Ensure contact_number column exists
try {
    $conn->exec("ALTER TABLE users ADD COLUMN contact_number VARCHAR(20) DEFAULT NULL");
} catch (PDOException $e) {}

$action_msg = "";

// ==========================================================
// FORM ACTIONS
// ==========================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_action'])) {

    $action = $_POST['form_action'];

    // CREATE USER
    if ($action === 'create_user') {
        $username       = trim($_POST['username'] ?? '');
        $email          = trim($_POST['email'] ?? '');
        $fullname       = trim($_POST['fullname'] ?? '');
        $password       = $_POST['password'] ?? '';
        $role           = strtolower(trim($_POST['role'] ?? 'farmer'));
        $contact_number = trim($_POST['contact_number'] ?? '');

        if (!in_array($role, ['farmer', 'admin'], true)) {
            $role = 'farmer';
        }

        if (!empty($username) && !empty($email) && !empty($password)) {
            try {
                $hashed_password = password_hash($password, PASSWORD_BCRYPT);
                $stmt = $conn->prepare("
                    INSERT INTO users (username, email, fullname, password, role, contact_number)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$username, $email, $fullname, $hashed_password, $role, $contact_number]);
                $action_msg = "<div class='alert success'>Account for '<strong>" . htmlspecialchars($username, ENT_QUOTES, 'UTF-8') . "</strong>' registered successfully.</div>";
            } catch (PDOException $e) {
                $action_msg = "<div class='alert danger'>Registration error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</div>";
            }
        } else {
            $action_msg = "<div class='alert warning'>Please populate all required entry slots.</div>";
        }
    }

    // UPDATE USER
    if ($action === 'update_user') {
        $id             = intval($_POST['user_id'] ?? 0);
        $role           = strtolower(trim($_POST['role'] ?? 'farmer'));
        $fullname       = trim($_POST['fullname'] ?? '');
        $contact_number = trim($_POST['contact_number'] ?? '');

        if (!in_array($role, ['farmer', 'admin'], true)) {
            $role = 'farmer';
        }

        if ($id > 0) {
            try {
                $stmt = $conn->prepare("UPDATE users SET role = ?, fullname = ?, contact_number = ? WHERE id = ?");
                $stmt->execute([$role, $fullname, $contact_number, $id]);
                $action_msg = "<div class='alert success'>Account updates applied successfully.</div>";
            } catch (PDOException $e) {
                $action_msg = "<div class='alert danger'>Update failed: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</div>";
            }
        } else {
            $action_msg = "<div class='alert warning'>Invalid user account selected.</div>";
        }
    }

    // DELETE USER
    if ($action === 'delete_user') {
        $id = intval($_POST['user_id'] ?? 0);
        if ($id === intval($_SESSION['user_id'])) {
            $action_msg = "<div class='alert danger'>Operational error: You cannot revoke your own active root session profile.</div>";
        } elseif ($id <= 0) {
            $action_msg = "<div class='alert warning'>Invalid user account selected.</div>";
        } else {
            try {
                $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
                $stmt->execute([$id]);
                $action_msg = "<div class='alert success'>Account systematically revoked from records.</div>";
            } catch (PDOException $e) {
                $action_msg = "<div class='alert danger'>Revocation failed: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "</div>";
            }
        }
    }
}

// GET ALL USERS
$users_list = $conn->query("
    SELECT id, username, email, fullname, role, contact_number
    FROM users
    ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);

$totalUsersCount = count($users_list);
?>

<div class="sub-view-panel-container">

    <div class="view-panel-header">
        <h3>User Account Management Control Panel</h3>
        <p>System access control: Provision new accounts, update clearance roles, or revoke cooperative database entries.</p>
    </div>

    <?= $action_msg ?>

    <!-- =========================================================
         2-COLUMN SPLIT: REGISTER NEW USER & OPERATIONAL DIRECTIVES (Matches Image 5)
         ========================================================= -->
    <div class="insights-dashboard-split-row">
        
        <!-- Register New User Card -->
        <div class="card-panel">
            <h3 style="font-size: 16px; font-weight: 700; color: var(--text-heading); margin-bottom: 16px;">
                Register New User
            </h3>

            <form action="dashboard.php?page=users_manage" method="POST">
                <input type="hidden" name="form_action" value="create_user">

                <div class="form-group">
                    <div class="input-field-wrapper">
                        <input type="text" name="fullname" placeholder="Full Name (e.g. Juan Dela Cruz)">
                    </div>
                </div>

                <div class="form-group">
                    <div class="input-field-wrapper">
                        <input type="text" name="username" placeholder="Username" required>
                    </div>
                </div>

                <div class="form-group">
                    <div class="input-field-wrapper">
                        <input type="email" name="email" placeholder="Email Address" required>
                    </div>
                </div>

                <div class="form-group">
                    <div class="input-field-wrapper">
                        <input type="text" name="contact_number" placeholder="Contact Number (e.g. 09XXXXXXXXX)">
                    </div>
                </div>

                <div class="form-group">
                    <div class="input-field-wrapper">
                        <input type="password" id="create-user-pass" name="password" placeholder="Create Password" required>
                        <button type="button" class="password-toggle-btn" onclick="togglePassVisibility('create-user-pass')">
                            <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                                <circle cx="12" cy="12" r="3"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label" style="font-size: 12px; color: var(--text-muted);">Assigned Portal Scope:</label>
                    <div class="radio-cards-container">
                        <label class="radio-card-label selected" id="scope-farmer-label" onclick="selectRoleRadio('farmer')">
                            <input type="radio" name="role" value="farmer" checked style="accent-color: var(--primary-color);">
                            <span>Farmer</span>
                        </label>
                        <label class="radio-card-label" id="scope-admin-label" onclick="selectRoleRadio('admin')">
                            <input type="radio" name="role" value="admin" style="accent-color: var(--primary-color);">
                            <span>Admin</span>
                        </label>
                    </div>
                </div>

                <button type="submit" class="btn-primary">
                    Provision Account
                </button>
            </form>
        </div>

        <!-- Operational Directives Card -->
        <div class="card-panel" style="display: flex; flex-direction: column; justify-content: space-between;">
            <div>
                <h3 style="font-size: 16px; font-weight: 700; color: var(--text-heading); margin-bottom: 12px;">
                    Operational Directives
                </h3>
                <p style="font-size: 13.5px; line-height: 1.55; color: var(--text-muted); margin-bottom: 14px;">
                    When updating user details or removing old profiles, double-check profiles to maintain accurate data mapping. Deleting a farmer's account completely cleans up their assigned entries from the historical system.
                </p>
                <p style="font-size: 13px; line-height: 1.5; color: var(--primary-color); font-weight: 600;">
                    SMS Alerts: <span style="font-weight: 500; color: var(--text-body);">The contact number entered will receive critical soil alert SMS directly from the system.</span>
                </p>
            </div>

            <div class="directive-highlight-box">
                <div class="directive-muted-tag">COOPERATIVE METRICS</div>
                <div class="directive-metric-val">Total Profiles Linked: <?= $totalUsersCount ?></div>
            </div>
        </div>

    </div>

    <!-- =========================================================
         REGISTERED COOPERATIVE PROFILES TABLE (Matches Image 5)
         ========================================================= -->
    <div class="table-container-card">
        <div class="table-header-flex">
            <div>
                <div class="card-title" style="font-size: 16px;">Registered Cooperative Profiles</div>
            </div>
        </div>

        <div class="table-responsive">
            <table class="custom-data-table">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th>Full Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Contact No.</th>
                        <th>Role</th>
                        <th style="text-align: right;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (!empty($users_list)): ?>
                        <?php foreach ($users_list as $user): ?>
                            <tr>
                                <td style="color: #6b7280;"><?= htmlspecialchars((string)$user['id']) ?></td>
                                <td><strong><?= htmlspecialchars($user['fullname'] ?: '---') ?></strong></td>
                                <td><?= htmlspecialchars($user['username']) ?></td>
                                <td><?= htmlspecialchars($user['email']) ?></td>
                                <td><?= htmlspecialchars($user['contact_number'] ?: '---') ?></td>
                                <td>
                                    <span class="badge-pill <?= strtolower($user['role']) === 'admin' ? 'neutral' : 'optimal' ?>" style="font-size: 11px;">
                                        <?= ucfirst(htmlspecialchars($user['role'])) ?>
                                    </span>
                                </td>
                                <td style="text-align: right;">
                                    <button type="button" class="btn-sm-action" onclick="openEditUserModal(<?= htmlspecialchars(json_encode($user), ENT_QUOTES, 'UTF-8') ?>)">
                                        Edit
                                    </button>

                                    <?php if ($user['id'] !== intval($_SESSION['user_id'])): ?>
                                        <form action="dashboard.php?page=users_manage" method="POST" style="display: inline;" onsubmit="return confirm('Revoke account for <?= htmlspecialchars($user['username']) ?>?');">
                                            <input type="hidden" name="form_action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                            <button type="submit" class="btn-sm-action btn-sm-revoke">
                                                Revoke
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="7" style="text-align: center; color: #9ca3af; padding: 24px;">No registered accounts found.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- EDIT USER MODAL -->
<div id="editUserModal" style="display:none; position:fixed; z-index:9999; inset:0; background:rgba(0,0,0,0.4); align-items:center; justify-content:center; padding:20px;">
    <div style="background:#fff; width:100%; max-width:440px; border-radius:16px; padding:24px; box-shadow:0 20px 25px -5px rgba(0,0,0,0.1);">
        <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:16px;">
            <h3 style="font-size:16px; font-weight:700; color:var(--text-heading); margin:0;">Edit Account Details</h3>
            <button type="button" onclick="closeEditUserModal()" style="background:none; border:none; font-size:18px; cursor:pointer; color:#6b7280;">✕</button>
        </div>

        <form action="dashboard.php?page=users_manage" method="POST">
            <input type="hidden" name="form_action" value="update_user">
            <input type="hidden" id="edit-user-id" name="user_id">

            <div class="form-group">
                <label class="form-label">Full Name:</label>
                <div class="input-field-wrapper">
                    <input type="text" id="edit-fullname" name="fullname">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Contact Number:</label>
                <div class="input-field-wrapper">
                    <input type="text" id="edit-contact" name="contact_number">
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">System Role:</label>
                <div class="input-field-wrapper">
                    <select id="edit-role" name="role">
                        <option value="farmer">Farmer</option>
                        <option value="admin">Admin</option>
                    </select>
                </div>
            </div>

            <div style="display:flex; gap:10px; margin-top:20px;">
                <button type="button" onclick="closeEditUserModal()" class="btn-outline" style="flex:1;">Cancel</button>
                <button type="submit" class="btn-primary" style="flex:1;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function togglePassVisibility(id) {
    const input = document.getElementById(id);
    if (input) {
        input.type = input.type === 'password' ? 'text' : 'password';
    }
}

function selectRoleRadio(role) {
    const farmerLabel = document.getElementById('scope-farmer-label');
    const adminLabel = document.getElementById('scope-admin-label');
    if (role === 'farmer') {
        farmerLabel?.classList.add('selected');
        adminLabel?.classList.remove('selected');
    } else {
        adminLabel?.classList.add('selected');
        farmerLabel?.classList.remove('selected');
    }
}

function openEditUserModal(user) {
    document.getElementById('edit-user-id').value = user.id;
    document.getElementById('edit-fullname').value = user.fullname || '';
    document.getElementById('edit-contact').value = user.contact_number || '';
    document.getElementById('edit-role').value = user.role.toLowerCase();
    
    const modal = document.getElementById('editUserModal');
    if (modal) modal.style.display = 'flex';
}

function closeEditUserModal() {
    const modal = document.getElementById('editUserModal');
    if (modal) modal.style.display = 'none';
}
</script>