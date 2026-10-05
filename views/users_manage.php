<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

include "../config/db_connect.php";

if (strtolower($_SESSION['role'] ?? '') !== 'admin') {
    echo "<p class='error'>Access Denied. Administrative clearance required.</p>";
    exit;
}

// ==========================================================
// ENSURE contact_number COLUMN EXISTS
// ==========================================================
try {
    $conn->exec("ALTER TABLE users ADD COLUMN contact_number VARCHAR(20) DEFAULT NULL");
} catch (PDOException $e) {
    // Column probably already exists.
}

$action_msg = "";

// ==========================================================
// FORM ACTIONS
// ==========================================================
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST['form_action'])) {

    $action = $_POST['form_action'];

    // ======================================================
    // CREATE USER
    // ======================================================
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

                $hashed_password = password_hash(
                    $password,
                    PASSWORD_BCRYPT
                );

                $stmt = $conn->prepare("
                    INSERT INTO users
                    (
                        username,
                        email,
                        fullname,
                        password,
                        role,
                        contact_number
                    )
                    VALUES (?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $username,
                    $email,
                    $fullname,
                    $hashed_password,
                    $role,
                    $contact_number
                ]);

                $action_msg = "
                    <div class='alert success'>
                        Account for '" .
                        htmlspecialchars($username, ENT_QUOTES, 'UTF-8') .
                        "' registered successfully.
                    </div>
                ";

            } catch (PDOException $e) {

                $action_msg = "
                    <div class='alert danger'>
                        Registration error: " .
                        htmlspecialchars(
                            $e->getMessage(),
                            ENT_QUOTES,
                            'UTF-8'
                        ) .
                        "
                    </div>
                ";
            }

        } else {

            $action_msg = "
                <div class='alert warning'>
                    Please populate all required entry slots.
                </div>
            ";
        }
    }


    // ======================================================
    // UPDATE USER
    // ======================================================
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

                $stmt = $conn->prepare("
                    UPDATE users
                    SET
                        role = ?,
                        fullname = ?,
                        contact_number = ?
                    WHERE id = ?
                ");

                $stmt->execute([
                    $role,
                    $fullname,
                    $contact_number,
                    $id
                ]);

                $action_msg = "
                    <div class='alert success'>
                        Account updates applied successfully.
                    </div>
                ";

            } catch (PDOException $e) {

                $action_msg = "
                    <div class='alert danger'>
                        Update failed: " .
                        htmlspecialchars(
                            $e->getMessage(),
                            ENT_QUOTES,
                            'UTF-8'
                        ) .
                        "
                    </div>
                ";
            }

        } else {

            $action_msg = "
                <div class='alert warning'>
                    Invalid user account selected.
                </div>
            ";
        }
    }


    // ======================================================
    // DELETE USER
    // ======================================================
    if ($action === 'delete_user') {

        $id = intval($_POST['user_id'] ?? 0);

        if ($id === intval($_SESSION['user_id'])) {

            $action_msg = "
                <div class='alert danger'>
                    Operational error: You cannot drop your own active root session profile.
                </div>
            ";

        } elseif ($id <= 0) {

            $action_msg = "
                <div class='alert warning'>
                    Invalid user account selected.
                </div>
            ";

        } else {

            try {

                $stmt = $conn->prepare("
                    DELETE FROM users
                    WHERE id = ?
                ");

                $stmt->execute([$id]);

                $action_msg = "
                    <div class='alert success'>
                        Account systematically purged from records.
                    </div>
                ";

            } catch (PDOException $e) {

                $action_msg = "
                    <div class='alert danger'>
                        Deletion failed: " .
                        htmlspecialchars(
                            $e->getMessage(),
                            ENT_QUOTES,
                            'UTF-8'
                        ) .
                        "
                    </div>
                ";
            }
        }
    }
}


// ==========================================================
// GET ALL USERS
// ==========================================================
$users_list = $conn->query("
    SELECT
        id,
        username,
        email,
        fullname,
        role,
        contact_number
    FROM users
    ORDER BY id DESC
")->fetchAll(PDO::FETCH_ASSOC);

?>

<div class="sub-view-panel-container">

<div class="view-panel-header">

<h3>User Account Management Control Panel</h3>

<p>
    System access control: Provision new accounts,
    update clearance roles, or revoke cooperative database entries.
</p>

</div>


<?= $action_msg ?>


<div
    class="insights-dashboard-split-row"
    style="margin-bottom: 30px;"
>

<!-- ==================================================
     REGISTER NEW USER
     ================================================== -->

<div
    class="action-alert-panel-card"
    style="
        background: #ffffff;
        border: 1px solid #ccd4cc;
    "
>

    <h3
        style="
            margin-bottom: 15px;
            color: var(--primary-color);
        "
    >
        Register New User
    </h3>


    <form
        action="dashboard.php?page=users_manage"
        method="POST"
        id="registerForm"
        autocomplete="off"
    >

        <input
            type="hidden"
            name="form_action"
            value="create_user"
        >


        <!-- FULL NAME -->

        <div
            class="input-wrapper"
            style="background: #f4f6f4;"
        >

            <input
                type="text"
                name="fullname"
                placeholder="Full Name (e.g. Juan Dela Cruz)"
                autocomplete="off"
            >

        </div>


        <!-- USERNAME -->

        <div
            class="input-wrapper"
            style="background: #f4f6f4;"
        >

            <input
                type="text"
                name="username"
                placeholder="Username"
                autocomplete="off"
                required
            >

        </div>


        <!-- EMAIL -->

        <div
            class="input-wrapper"
            style="background: #f4f6f4;"
        >

            <input
                type="email"
                name="email"
                placeholder="Email Address"
                autocomplete="off"
                required
            >

        </div>


        <!-- CONTACT NUMBER -->

        <div
            class="input-wrapper"
            style="background: #f4f6f4;"
        >

            <input
                type="tel"
                name="contact_number"
                placeholder="Contact Number (e.g. 09XXXXXXXXX)"
                maxlength="20"
                autocomplete="off"
            >

        </div>


        <!-- PASSWORD -->

        <div
            class="input-wrapper"
            style="
                background: #f4f6f4;
                position: relative;
                display: flex;
                align-items: center;
            "
        >

            <input
                type="password"
                name="password"
                id="newUserPassword"
                placeholder="Create Password"
                autocomplete="new-password"
                required
                style="
                    padding-right: 48px;
                "
            >


            <button
                type="button"
                id="togglePasswordBtn"
                onclick="toggleNewUserPassword()"
                aria-label="Show password"
                title="Show password"
                style="
                    position: absolute;
                    right: 8px;
                    top: 50%;
                    transform: translateY(-50%);
                    width: 36px;
                    height: 36px;
                    border: 1px solid #aeb7ae;
                    border-radius: 8px;
                    background: transparent;
                    color: #707770;
                    cursor: pointer;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    padding: 0;
                "
            >

                <svg
                    id="eyeIcon"
                    xmlns="http://www.w3.org/2000/svg"
                    width="19"
                    height="19"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.8"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    aria-hidden="true"
                >
                    <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>
                    <circle cx="12" cy="12" r="3"></circle>
                </svg>

            </button>

        </div>


        <!-- ROLE -->

        <div
            class="role-selection-group"
            style="
                margin-top: 10px;
                text-align: left;
            "
        >

            <span
                class="chip-label"
                style="
                    display: block;
                    margin-bottom: 5px;
                "
            >
                Assigned Portal Scope:
            </span>


            <div class="grid-two-columns">

                <label class="selector-card">

                    <input
                        type="radio"
                        name="role"
                        value="farmer"
                        checked
                    >

                    Farmer

                </label>


                <label class="selector-card">

                    <input
                        type="radio"
                        name="role"
                        value="admin"
                    >

                    Admin

                </label>

            </div>

        </div>


        <!-- CREATE BUTTON -->

        <button
            type="submit"
            class="mockup-login-btn"
            style="margin-top: 15px;"
            id="btn_submit_account"
        >
            Provision Account
        </button>

    </form>

</div>


<!-- ==================================================
     OPERATIONAL DIRECTIVES
     ================================================== -->

<div
    class="action-alert-panel-card"
    style="
        background: #ffffff;
        border: 1px solid #ccd4cc;
        display: flex;
        flex-direction: column;
        justify-content: space-between;
    "
>

    <div>

        <h3 style="margin-bottom: 15px;">
            Operational Directives
        </h3>


        <p
            style="
                font-size: 14px;
                line-height: 1.5;
                color: var(--text-muted);
            "
        >
            When updating user details or removing old profiles,
            double-check profiles to maintain accurate data mapping.
            Deleting a farmer's account completely cleans up their
            assigned entries from the historical system.
        </p>


        <p
            style="
                font-size: 13px;
                line-height: 1.6;
                color: #0b8a47;
                margin-top: 12px;
            "
        >
            <strong>SMS Alerts:</strong>
            The contact number entered will receive critical soil
            alert SMS directly from the system.
        </p>

    </div>


    <div
        class="nested-sub-recommends-box"
        style="
            border-left-color: #1565c0;
            margin-top: 20px;
        "
    >

        <span class="muted-title">
            COOPERATIVE METRICS
        </span>


        <p
            style="
                font-weight: bold;
                font-size: 16px;
                margin-top: 5px;
            "
        >
            Total Profiles Linked:
            <?= count($users_list) ?>
        </p>

    </div>

</div>

</div>


<!-- ======================================================
     REGISTERED PROFILES TABLE
     ====================================================== -->

<div class="view-panel-header">

<h3>
    Registered Cooperative Profiles
</h3>

</div>


<div
    class="history-table-wrapper"
    style="
        overflow-x: auto;
        background: #fff;
        padding: 15px;
        border-radius: 16px;
        border: 1px solid #ccd4cc;
    "
>

<table
    style="
        width: 100%;
        border-collapse: collapse;
        text-align: left;
        font-size: 14px;
    "
>

    <thead>

        <tr
            style="
                border-bottom: 2px solid #e2e8e2;
                color: #424242;
            "
        >

            <th style="padding: 12px;">
                ID
            </th>

            <th style="padding: 12px;">
                Full Name
            </th>

            <th style="padding: 12px;">
                Username
            </th>

            <th style="padding: 12px;">
                Email
            </th>

            <th style="padding: 12px;">
                Contact No.
            </th>

            <th style="padding: 12px;">
                Role
            </th>

            <th
                style="
                    padding: 12px;
                    text-align: center;
                "
            >
                Actions
            </th>

        </tr>

    </thead>


    <tbody>

        <?php foreach ($users_list as $row): ?>

            <?php

            $userPhone = trim(
                $row['contact_number'] ?? ''
            );

            ?>

            <tr
                style="
                    border-bottom: 1px solid #f0f4f0;
                "
            >

                <td
                    style="
                        padding: 12px;
                        color: var(--text-muted);
                    "
                >
                    <?= intval($row['id']) ?>
                </td>


                <td
                    style="
                        padding: 12px;
                        font-weight: 600;
                    "
                >
                    <?= htmlspecialchars(
                        $row['fullname'] ?: 'No Name Provided',
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>
                </td>


                <td style="padding: 12px;">

                    <?= htmlspecialchars(
                        $row['username'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </td>


                <td
                    style="
                        padding: 12px;
                        color: var(--text-muted);
                    "
                >

                    <?= htmlspecialchars(
                        $row['email'],
                        ENT_QUOTES,
                        'UTF-8'
                    ) ?>

                </td>


                <td style="padding: 12px;">

                    <?php if (!empty($userPhone)): ?>

                        <span
                            style="
                                background: #e8f5e9;
                                color: #2e7d32;
                                padding: 3px 8px;
                                border-radius: 20px;
                                font-size: 12px;
                                font-weight: 600;
                            "
                        >
                            <?= htmlspecialchars(
                                $userPhone,
                                ENT_QUOTES,
                                'UTF-8'
                            ) ?>
                        </span>

                    <?php else: ?>

                        <span
                            style="
                                color: #bbb;
                                font-size: 12px;
                            "
                        >
                            — not set —
                        </span>

                    <?php endif; ?>

                </td>


                <td style="padding: 12px;">

                    <span
                        class="status-pill"
                        style="<?= strtolower($row['role']) === 'admin'
                            ? 'background: #e3f2fd; color: #0d47a1;'
                            : 'background: #e8f5e9; color: #2e7d32;' ?>"
                    >

                        <?= ucfirst(
                            htmlspecialchars(
                                $row['role'],
                                ENT_QUOTES,
                                'UTF-8'
                            )
                        ) ?>

                    </span>

                </td>


                <td
                    style="
                        padding: 12px;
                        text-align: center;
                    "
                >

                    <button
                        type="button"
                        class="status-pill"
                        style="
                            background: #e4ebe4;
                            color: #333;
                            border: none;
                            cursor: pointer;
                            padding: 5px 10px;
                            margin-right: 4px;
                        "
                        onclick='openEditUserModal(
                            <?= intval($row["id"]) ?>,
                            <?= json_encode(
                                $row["fullname"] ?? "",
                                JSON_HEX_TAG |
                                JSON_HEX_APOS |
                                JSON_HEX_AMP |
                                JSON_HEX_QUOT
                            ) ?>,
                            <?= json_encode(
                                $row["role"] ?? "farmer",
                                JSON_HEX_TAG |
                                JSON_HEX_APOS |
                                JSON_HEX_AMP |
                                JSON_HEX_QUOT
                            ) ?>,
                            <?= json_encode(
                                $userPhone,
                                JSON_HEX_TAG |
                                JSON_HEX_APOS |
                                JSON_HEX_AMP |
                                JSON_HEX_QUOT
                            ) ?>
                        )'
                    >
                        Edit
                    </button>


                    <form
                        action="dashboard.php?page=users_manage"
                        method="POST"
                        style="display:inline;"
                        onsubmit="return confirm('Are you sure you want to completely delete this user row record?');"
                    >

                        <input
                            type="hidden"
                            name="form_action"
                            value="delete_user"
                        >

                        <input
                            type="hidden"
                            name="user_id"
                            value="<?= intval($row['id']) ?>"
                        >

                        <button
                            type="submit"
                            class="status-pill"
                            style="
                                background: #ffebee;
                                color: #c62828;
                                border: none;
                                cursor: pointer;
                                padding: 5px 10px;
                            "
                        >
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


<!-- ==========================================================
     EDIT USER MODAL
     ========================================================== -->

<div
    id="editUserModal"
    style="
        display: none;
        position: fixed;
        z-index: 10000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.5);
        align-items: center;
        justify-content: center;
    "
>

<div
    class="action-alert-panel-card"
    style="
        background: #ffffff;
        max-width: 400px;
        width: 90%;
        border-radius: 20px;
        box-shadow: 0 10px 25px rgba(0,0,0,0.3);
        padding: 30px;
    "
>

<h3
    style="
        margin-bottom: 15px;
        color: var(--primary-color);
    "
>
    Update User Profile
</h3>


<form
    action="dashboard.php?page=users_manage"
    method="POST"
>

    <input
        type="hidden"
        name="form_action"
        value="update_user"
    >

    <input
        type="hidden"
        name="user_id"
        id="modal_user_id"
    >


    <label
        class="chip-label"
        style="
            text-align: left;
            display: block;
            margin-bottom: 5px;
        "
    >
        Display Full Name:
    </label>


    <div
        class="input-wrapper"
        style="background: #f4f6f4;"
    >

        <input
            type="text"
            name="fullname"
            id="modal_fullname"
            placeholder="Full Name"
            required
        >

    </div>


    <label
        class="chip-label"
        style="
            text-align: left;
            display: block;
            margin-bottom: 5px;
            margin-top: 12px;
        "
    >
        Contact Number:
    </label>


    <div
        class="input-wrapper"
        style="background: #f4f6f4;"
    >

        <input
            type="tel"
            name="contact_number"
            id="modal_contact_number"
            placeholder="09XXXXXXXXX"
            maxlength="20"
        >

    </div>


    <div
        class="role-selection-group"
        style="
            margin-top: 15px;
            text-align: left;
        "
    >

        <span
            class="chip-label"
            style="
                display: block;
                margin-bottom: 5px;
            "
        >
            System Security Privilege:
        </span>


        <div class="grid-two-columns">

            <label class="selector-card">

                <input
                    type="radio"
                    name="role"
                    value="farmer"
                    id="modal_role_farmer"
                >

                Farmer

            </label>


            <label class="selector-card">

                <input
                    type="radio"
                    name="role"
                    value="admin"
                    id="modal_role_admin"
                >

                Admin

            </label>

        </div>

    </div>


    <div
        style="
            display: flex;
            gap: 10px;
            margin-top: 25px;
        "
    >

        <button
            type="button"
            class="mockup-login-btn"
            style="
                background: #ccd4cc !important;
                color: #333;
            "
            onclick="closeEditUserModal()"
        >
            Cancel
        </button>


        <button
            type="submit"
            class="mockup-login-btn"
        >
            Apply Updates
        </button>

    </div>

</form>

</div>

</div>


<script>

function toggleNewUserPassword() {

    const passwordInput =
        document.getElementById('newUserPassword');

    const toggleButton =
        document.getElementById('togglePasswordBtn');

    const eyeIcon =
        document.getElementById('eyeIcon');


    if (passwordInput.type === 'password') {

        passwordInput.type = 'text';

        toggleButton.setAttribute(
            'aria-label',
            'Hide password'
        );

        toggleButton.setAttribute(
            'title',
            'Hide password'
        );


        eyeIcon.innerHTML = `
            <path d="M2 12s3.5-7 10-7c2.2 0 4.1.8 5.7 2"></path>
            <path d="M22 12s-3.5 7-10 7c-2.2 0-4.1-.8-5.7-2"></path>
            <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2"></path>
            <path d="M3 3l18 18"></path>
        `;

    } else {

        passwordInput.type = 'password';

        toggleButton.setAttribute(
            'aria-label',
            'Show password'
        );

        toggleButton.setAttribute(
            'title',
            'Show password'
        );


        eyeIcon.innerHTML = `
            <path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12z"></path>
            <circle cx="12" cy="12" r="3"></circle>
        `;
    }
}


function openEditUserModal(
    id,
    fullname,
    role,
    contact_number
) {

    document.getElementById(
        'modal_user_id'
    ).value = id;


    document.getElementById(
        'modal_fullname'
    ).value = fullname;


    document.getElementById(
        'modal_contact_number'
    ).value = contact_number;


    if (
        String(role).toLowerCase() === 'admin'
    ) {

        document.getElementById(
            'modal_role_admin'
        ).checked = true;

    } else {

        document.getElementById(
            'modal_role_farmer'
        ).checked = true;
    }


    document.getElementById(
        'editUserModal'
    ).style.display = 'flex';
}


function closeEditUserModal() {

    document.getElementById(
        'editUserModal'
    ).style.display = 'none';
}


window.addEventListener(
    'click',
    function(event) {

        const modal =
            document.getElementById(
                'editUserModal'
            );

        if (event.target === modal) {
            closeEditUserModal();
        }
    }
);


document.addEventListener(
    'DOMContentLoaded',
    function() {

        const registerForm =
            document.getElementById(
                'registerForm'
            );

        const submitButton =
            document.getElementById(
                'btn_submit_account'
            );


        if (
            registerForm &&
            submitButton
        ) {

            registerForm.addEventListener(
                'submit',
                function() {

                    submitButton.disabled = true;

                    submitButton.style.opacity =
                        '0.7';

                    submitButton.innerText =
                        'Creating Account...';
                }
            );
        }
    }
);

</script>