<?php
// Kapag binuksan ito direkta sa sidebar, gamitin ang shared page shell
// para pareho ang header/sidebar design sa ibang Super Admin pages.
if (!defined('SUPERADMIN_RENDER_USER_MANAGEMENT_PARTIAL')) {
    $_GET['tab'] = isset($_GET['create']) ? 'create' : 'users';
    require_once __DIR__ . '/../includes/page_shell.php';
    require_once __DIR__ . '/../includes/user_management_actions.php';

    $userManagementContext = superadmin_user_context($conn);
    $message = $userManagementContext['message'];
    $error = $userManagementContext['error'];
    $isUserWorkspaceTab = $userManagementContext['isUserWorkspaceTab'];
    $userWorkspaceShouldOpenModal = $userManagementContext['userWorkspaceShouldOpenModal'];
    $userStatusFilter = $userManagementContext['userStatusFilter'];
    $userRoleFilter = $userManagementContext['userRoleFilter'];
    $userTrashView = $userManagementContext['userTrashView'];
    $allowedRoles = $userManagementContext['allowedRoles'];
    $csrfToken = $userManagementContext['csrfToken'];
    $old = $userManagementContext['old'];
    $managedUsers = $userManagementContext['managedUsers'];

    superadmin_render_page(
        'User Management',
        function () use (
            $isUserWorkspaceTab,
            $userWorkspaceShouldOpenModal,
            $userStatusFilter,
            $userRoleFilter,
            $userTrashView,
            $allowedRoles,
            $csrfToken,
            $old,
            $managedUsers
        ): void {
            define('SUPERADMIN_RENDER_USER_MANAGEMENT_PARTIAL', true);
            define('SUPERADMIN_USER_MANAGEMENT_STANDALONE', true);
            include __FILE__;
        },
        ['/codesamplecaps/SUPERADMIN/css/user-management.css'],
        ['/codesamplecaps/SUPERADMIN/js/user-management.js'],
        'superadmin-user-management-page'
    );
    return;
}

/** @var bool $isUserWorkspaceTab */
/** @var bool $userWorkspaceShouldOpenModal */
/** @var string $userStatusFilter */
/** @var string $userRoleFilter */
/** @var bool $userTrashView */
/** @var array<int, string> $allowedRoles */
/** @var string $csrfToken */
/** @var array<string, string> $old */
/** @var array<int, array<string, mixed>> $managedUsers */
$isStandaloneUserManagement = defined('SUPERADMIN_USER_MANAGEMENT_STANDALONE');
$usersWrapperClass = $isStandaloneUserManagement
    ? 'user-management-content active'
    : 'tab-content ' . ($isUserWorkspaceTab ? 'active' : '');
?>
<div id="users" class="<?php echo htmlspecialchars($usersWrapperClass, ENT_QUOTES, 'UTF-8'); ?>">
    <?php if (!empty($message)): ?><div class="user-toast user-toast-success" role="status" data-user-toast><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <?php if (!empty($error)): ?><div class="user-toast user-toast-error" role="alert" data-user-toast><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div><?php endif; ?>
    <section class="user-management-shell" data-user-management-shell data-create-modal-default-open="<?php echo $userWorkspaceShouldOpenModal ? 'true' : 'false'; ?>">
        <section class="dashboard-panel user-management-panel">
            <div class="user-table-toolbar">
                <div class="user-table-toolbar__copy">
                <h1 class="dashboard-section-title"><?php echo $userTrashView ? 'Trashed Users' : 'Manage Users'; ?></h1>
                </div>
                <div class="user-table-toolbar__controls">
                    <label class="user-search-field" for="userSearch">
                        <input type="search" id="userSearch" placeholder="Search name, email, phone, or role" data-user-search>
                    </label>
                </div>
                <button type="button" class="btn-primary user-management-trigger" data-open-create-modal>Create Account</button>
            </div>

            <div class="dashboard-actions user-filters">
                <?php
                $statusBase = $userRoleFilter !== '' ? ['role' => $userRoleFilter] : [];
                $trashQuery = array_merge(['view' => 'trash'], $userRoleFilter !== '' ? ['role' => $userRoleFilter] : []);
                ?>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/user_management.php<?php echo $statusBase ? '?' . http_build_query($statusBase) : ''; ?>" class="action-chip<?php echo !$userTrashView && $userStatusFilter === '' ? ' active-chip' : ''; ?>">All</a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/user_management.php?<?php echo http_build_query(array_merge($statusBase, ['status' => 'active'])); ?>" class="action-chip<?php echo !$userTrashView && $userStatusFilter === 'active' ? ' active-chip' : ''; ?>">Active</a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/user_management.php?<?php echo http_build_query(array_merge($statusBase, ['status' => 'inactive'])); ?>" class="action-chip<?php echo !$userTrashView && $userStatusFilter === 'inactive' ? ' active-chip' : ''; ?>">Inactive</a>
                <a href="/codesamplecaps/SUPERADMIN/sidebar/user_management.php?<?php echo http_build_query($trashQuery); ?>" class="action-chip action-chip-trash<?php echo $userTrashView ? ' active-chip' : ''; ?>">Trash</a>
                <form method="GET" class="user-role-filter" data-role-filter-form>
                    <?php if ($userTrashView): ?>
                        <input type="hidden" name="view" value="trash">
                    <?php endif; ?>
                    <?php if ($userStatusFilter !== ''): ?>
                        <input type="hidden" name="status" value="<?php echo htmlspecialchars($userStatusFilter, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <label for="userRoleFilter">Role</label>
                    <select id="userRoleFilter" name="role" data-role-filter-select>
                        <option value="">All Roles</option>
                        <?php foreach ($allowedRoles as $roleOption): ?>
                            <option value="<?php echo htmlspecialchars($roleOption, ENT_QUOTES, 'UTF-8'); ?>" <?php echo $userRoleFilter === $roleOption ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $roleOption)), ENT_QUOTES, 'UTF-8'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </div>

            <div class="users-table user-management-table">
                <table class="responsive-table">
                    <colgroup>
                        <col class="user-management-table__col-name">
                        <col class="user-management-table__col-role">
                        <col class="user-management-table__col-status">
                        <col class="user-management-table__col-created">
                        <col class="user-management-table__col-actions">
                    </colgroup>
                    <thead>
                        <tr><th>Name</th><th>Role</th><th>Status</th><th>Created</th><th>Actions</th></tr>
                    </thead>
                    <tbody data-user-table-body>
                        <?php if (empty($managedUsers)): ?>
                            <tr><td colspan="5" class="user-table-empty"><?php echo $userTrashView ? 'No users in trash.' : 'No users match the current filter.'; ?></td></tr>
                        <?php else: ?>
                            <?php foreach ($managedUsers as $user): $status = $user['status'] ?? 'active'; $rowId = (int)$user['id']; $normalizedRole = normalizeRole((string)($user['role'] ?? '')); ?>
                                <tr class="user-row" data-row-id="<?php echo $rowId; ?>" data-user-search="<?php echo htmlspecialchars(strtolower(trim(($user['full_name'] ?? '') . ' ' . ($user['email'] ?? '') . ' ' . ($user['phone'] ?? '') . ' ' . $normalizedRole . ' ' . $status))); ?>">
                                    <td data-label="Name">
                                        <input class="table-input" type="text" data-field="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly required>
                                    </td>
                                    <td data-label="Role">
                                        <span class="role-badge role-badge-<?php echo htmlspecialchars($normalizedRole); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $normalizedRole))); ?></span>
                                    </td>
                                    <td data-label="Status"><span class="status-badge <?php echo $status === 'active' ? 'status-active' : 'status-inactive'; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
                                    <td data-label="Created"><span class="user-date-chip"><?php echo htmlspecialchars(superadmin_user_format_date($user['created_at'] ?? null)); ?></span></td>
                                    <td data-label="Actions">
                                        <div class="user-actions-menu" data-user-actions-menu>
                                            <button type="button" class="action-btn user-actions-menu__toggle" data-user-actions-toggle aria-expanded="false">Manage</button>
                                            <div class="user-actions-menu__list" data-user-actions-list hidden>
                                                <div class="user-actions-menu__header">
                                                    <span>Manage Actions</span>
                                                    <button type="button" class="user-actions-menu__close" aria-label="Close manage actions" data-user-actions-close>&times;</button>
                                                </div>
                                                <?php if ($userTrashView): ?>
                                                    <form method="POST" class="inline-action-form" data-confirm-message="Restore this user to the active list?">
                                                        <input type="hidden" name="action" value="restore_user">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="user_id" value="<?php echo $rowId; ?>">
                                                        <button type="submit" class="user-actions-menu__item is-success">Restore User</button>
                                                    </form>
                                                    <form method="POST" class="inline-action-form" data-confirm-message="Permanently delete this user? This cannot be undone.">
                                                        <input type="hidden" name="action" value="permanent_delete_user">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="user_id" value="<?php echo $rowId; ?>">
                                                        <button type="submit" class="user-actions-menu__item is-danger">Delete Forever</button>
                                                    </form>
                                                <?php else: ?>
                                                    <button
                                                        type="button"
                                                        class="user-actions-menu__item"
                                                        data-open-edit-modal
                                                        data-user-id="<?php echo $rowId; ?>"
                                                        data-user-name="<?php echo htmlspecialchars((string)($user['full_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-user-email="<?php echo htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-user-phone="<?php echo htmlspecialchars((string)($user['phone'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                                        data-user-status-date="<?php echo htmlspecialchars(superadmin_user_format_date($user['status_changed_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?>"
                                                    >Edit Details</button>
                                                    <button type="button" class="user-actions-menu__item" data-open-reset-modal data-user-id="<?php echo $rowId; ?>" data-user-name="<?php echo htmlspecialchars((string)$user['full_name'], ENT_QUOTES, 'UTF-8'); ?>">Reset Password</button>
                                                    <form method="POST" class="inline-action-form" data-confirm-message="<?php echo $status === 'active' ? 'Deactivate this user? They will lose access to login.' : 'Reactivate this user?'; ?>">
                                                        <input type="hidden" name="action" value="update_status">
                                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                        <input type="hidden" name="user_id" value="<?php echo $rowId; ?>">
                                                        <input type="hidden" name="status" value="<?php echo $status === 'active' ? 'inactive' : 'active'; ?>">
                                                        <button type="submit" class="user-actions-menu__item <?php echo $status === 'active' ? 'is-danger' : 'is-success'; ?>"><?php echo $status === 'active' ? 'Deactivate' : 'Reactivate'; ?></button>
                                                    </form>
                                                    <?php if ($status === 'inactive'): ?>
                                                        <form method="POST" class="inline-action-form" data-confirm-message="Move this user to trash? Permanent deletion will happen only from the trash bin.">
                                                            <input type="hidden" name="action" value="delete_user">
                                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                            <input type="hidden" name="user_id" value="<?php echo $rowId; ?>">
                                                            <button type="submit" class="user-actions-menu__item is-danger">Move to Trash</button>
                                                        </form>
                                                    <?php endif; ?>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="user-search-empty-row" hidden><td colspan="5" class="user-table-empty">No users match your search.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <div class="modal-backdrop user-create-modal" data-user-create-modal hidden>
            <div class="modal-panel user-create-modal__panel" role="dialog" aria-modal="true" aria-labelledby="createAccountModalTitle">
                <div class="user-create-modal__header">
                    <div>
                        <p class="section-kicker">Create Account</p>
                        <h2 id="createAccountModalTitle" class="dashboard-section-title">Add a new user without leaving the table</h2>
                    </div>
                    <button type="button" class="modal-close-button" aria-label="Close create account modal" data-close-create-modal>&times;</button>
                </div>
                <form method="POST" class="user-create-form" data-user-create-form novalidate>
                    <input type="hidden" name="action" value="create_account">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="form-row">
                        <div class="form-group"><label for="full_name">Full Name <span class="required-indicator">*</span></label><input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($old['full_name']); ?>" required><small class="field-error" data-field-error hidden></small></div>
                        <div class="form-group"><label for="email">Email <span class="required-indicator">*</span></label><input type="email" id="email" name="email" value="<?php echo htmlspecialchars($old['email']); ?>" required><small class="field-error" data-field-error hidden></small></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="phone">Phone Number (PH) <span class="required-indicator">*</span></label><input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($old['phone']); ?>" pattern="^09[0-9]{9}$" maxlength="11" placeholder="09XXXXXXXXX" inputmode="numeric" data-ph-phone-lock-prefix required><small class="field-error" data-field-error hidden></small></div>
                        <div class="form-group">
                            <label for="role">Role <span class="required-indicator">*</span></label>
                            <select id="role" name="role" required>
                                <option value="">Select a role</option>
                                <option value="admin" <?php echo $old['role']=='admin'?'selected':''; ?>>Admin</option>
                                <option value="inventory_clerk" <?php echo $old['role']=='inventory_clerk'?'selected':''; ?>>Inventory Clerk</option>
                                <option value="engineer" <?php echo $old['role']=='engineer'?'selected':''; ?>>Engineer</option>
                                <option value="foreman" <?php echo $old['role']=='foreman'?'selected':''; ?>>Foreman</option>
                                <option value="client" <?php echo $old['role']=='client'?'selected':''; ?>>Client</option>
                            </select>
                            <small class="field-error" data-field-error hidden></small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group password-field">
                            <label for="password">Temporary Password <span class="required-indicator">*</span></label>
                            <div class="password-input-wrap">
                                <input type="password" id="password" name="password" minlength="12" required>
                                <button type="button" class="togglePassword" data-target="password">Show</button>
                            </div>
                            <small class="field-error" data-field-error hidden></small>
                            <small class="password-tip">Use 12+ characters with uppercase, lowercase, number, and special symbol.</small>
                            <small id="tempPassStrength" class="pass-indicator">Strength: -</small>
                        </div>
                    </div>
                    <div class="user-create-modal__actions">
                        <button type="button" class="btn-secondary" data-close-create-modal>Cancel</button>
                        <button type="submit" class="btn-primary">Create Account</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal-backdrop user-create-modal" data-edit-user-modal hidden>
            <div class="modal-panel user-create-modal__panel user-edit-modal__panel" role="dialog" aria-modal="true" aria-labelledby="editUserModalTitle">
                <div class="user-create-modal__header">
                    <div>
                        <p class="section-kicker">Edit User</p>
                        <h2 id="editUserModalTitle" class="dashboard-section-title">Update account details</h2>
                    </div>
                    <button type="button" class="modal-close-button" aria-label="Close edit user modal" data-close-edit-modal>&times;</button>
                </div>
                <form method="POST" class="user-create-form" data-edit-user-form novalidate>
                    <input type="hidden" name="action" value="edit_user">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="user_id" data-edit-user-id>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_full_name">Full Name <span class="required-indicator">*</span></label>
                            <input type="text" id="edit_full_name" name="edit_full_name" required>
                            <small class="field-error" data-field-error hidden></small>
                        </div>
                        <div class="form-group">
                            <label for="edit_email">Email <span class="required-indicator">*</span></label>
                            <input type="email" id="edit_email" name="edit_email" required>
                            <small class="field-error" data-field-error hidden></small>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="edit_phone">Phone Number (PH) <span class="required-indicator">*</span></label>
                            <input type="tel" id="edit_phone" name="edit_phone" pattern="^09[0-9]{9}$" maxlength="11" placeholder="09XXXXXXXXX" inputmode="numeric" data-ph-phone-lock-prefix required>
                            <small class="field-error" data-field-error hidden></small>
                        </div>
                        <div class="form-group">
                            <span class="readonly-field-label">Last Status Change</span>
                            <strong class="readonly-field-value" data-edit-status-date>Not set</strong>
                        </div>
                    </div>
                    <div class="user-create-modal__actions">
                        <button type="button" class="btn-secondary" data-close-edit-modal>Cancel</button>
                        <button type="submit" class="btn-primary">Save Changes</button>
                    </div>
                </form>
            </div>
        </div>

        <div class="modal-backdrop user-create-modal" data-reset-password-modal hidden>
            <div class="modal-panel user-create-modal__panel user-reset-modal__panel" role="dialog" aria-modal="true" aria-labelledby="resetPasswordModalTitle">
                <div class="user-create-modal__header">
                    <div>
                        <p class="section-kicker">Reset Password</p>
                        <h2 id="resetPasswordModalTitle" class="dashboard-section-title">Set temporary password</h2>
                        <p class="user-reset-target" data-reset-password-target>User account</p>
                    </div>
                    <button type="button" class="modal-close-button" aria-label="Close reset password modal" data-close-reset-modal>&times;</button>
                </div>
                <form method="POST" class="user-create-form" data-reset-password-form novalidate>
                    <input type="hidden" name="action" value="reset_password">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="user_id" data-reset-password-user-id>
                    <div class="form-row">
                        <div class="form-group password-field">
                            <label for="reset_new_password">New Temporary Password <span class="required-indicator">*</span></label>
                            <div class="password-input-wrap">
                                <input type="password" id="reset_new_password" name="new_password" minlength="12" required>
                                <button type="button" class="togglePassword" data-target="reset_new_password">Show</button>
                            </div>
                            <small class="field-error" data-field-error hidden></small>
                            <small class="password-tip">Tell the user to change this after login.</small>
                            <small id="resetPassStrength" class="pass-indicator">Strength: -</small>
                        </div>
                    </div>
                    <div class="user-create-modal__actions">
                        <button type="button" class="btn-secondary" data-close-reset-modal>Cancel</button>
                        <button type="submit" class="btn-primary">Update Password</button>
                    </div>
                </form>
            </div>
        </div>
    </section>
</div>
