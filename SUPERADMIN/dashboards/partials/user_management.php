<?php
/** @var bool $isUserWorkspaceTab */
/** @var bool $userWorkspaceShouldOpenModal */
/** @var string $userStatusFilter */
/** @var string $csrfToken */
/** @var array<string, string> $old */
/** @var array<int, array<string, mixed>> $managedUsers */
?>
<div id="users" class="tab-content <?php echo $isUserWorkspaceTab ? 'active' : ''; ?>">
    <section class="user-management-shell" data-user-management-shell data-create-modal-default-open="<?php echo $userWorkspaceShouldOpenModal ? 'true' : 'false'; ?>">
        <section class="dashboard-panel user-management-panel">
            <div class="user-table-toolbar">
                <div class="user-table-toolbar__copy">
                    <h1 class="dashboard-section-title">Manage Users</h1>
                </div>
                <div class="user-table-toolbar__controls">
                    <label class="user-search-field" for="userSearch">
                        <input type="search" id="userSearch" placeholder="Search name, email, phone, or role" data-user-search>
                    </label>
                </div>
                <button type="button" class="btn-primary user-management-trigger" data-open-create-modal>Create Account</button>
            </div>

            <div class="dashboard-actions user-filters">
                <a href="/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=users" class="action-chip<?php echo $userStatusFilter === '' ? ' active-chip' : ''; ?>">All Users</a>
                <a href="/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=users&amp;status=active" class="action-chip<?php echo $userStatusFilter === 'active' ? ' active-chip' : ''; ?>">Active</a>
                <a href="/codesamplecaps/SUPERADMIN/dashboards/super_admin_dashboard.php?tab=users&amp;status=inactive" class="action-chip<?php echo $userStatusFilter === 'inactive' ? ' active-chip' : ''; ?>">Inactive</a>
            </div>

            <div class="users-table user-management-table">
                <table class="responsive-table">
                    <colgroup>
                        <col class="user-management-table__col-name">
                        <col class="user-management-table__col-email">
                        <col class="user-management-table__col-phone">
                        <col class="user-management-table__col-role">
                        <col class="user-management-table__col-status">
                        <col class="user-management-table__col-actions">
                    </colgroup>
                    <thead>
                        <tr><th>Name</th><th>Email</th><th>Phone</th><th>Role</th><th>Status</th><th>Actions</th></tr>
                    </thead>
                    <tbody data-user-table-body>
                        <?php if (empty($managedUsers)): ?>
                            <tr><td colspan="6" class="user-table-empty">No users match the current filter.</td></tr>
                        <?php else: ?>
                            <?php foreach ($managedUsers as $user): $status = $user['status'] ?? 'active'; $rowId = (int)$user['id']; $normalizedRole = normalizeRole((string)($user['role'] ?? '')); ?>
                                <tr class="user-row" data-row-id="<?php echo $rowId; ?>" data-user-search="<?php echo htmlspecialchars(strtolower(trim(($user['full_name'] ?? '') . ' ' . ($user['email'] ?? '') . ' ' . ($user['phone'] ?? '') . ' ' . $normalizedRole . ' ' . $status))); ?>">
                                    <td data-label="Name">
                                        <input class="table-input" type="text" data-field="full_name" value="<?php echo htmlspecialchars($user['full_name']); ?>" readonly required>
                                    </td>
                                    <td data-label="Email">
                                        <input class="table-input" type="email" data-field="email" value="<?php echo htmlspecialchars($user['email']); ?>" readonly required>
                                    </td>
                                    <td data-label="Phone">
                                        <input class="table-input" type="tel" data-field="phone" value="<?php echo htmlspecialchars((string)($user['phone'] ?? '')); ?>" readonly pattern="^09[0-9]{9}$" maxlength="11" inputmode="numeric">
                                    </td>
                                    <td data-label="Role">
                                        <span class="role-badge role-badge-<?php echo htmlspecialchars($normalizedRole); ?>"><?php echo htmlspecialchars(ucwords(str_replace('_', ' ', $normalizedRole))); ?></span>
                                    </td>
                                    <td data-label="Status"><span class="status-badge <?php echo $status === 'active' ? 'status-active' : 'status-inactive'; ?>"><?php echo htmlspecialchars(ucfirst($status)); ?></span></td>
                                    <td data-label="Actions">
                                        <div class="action-group compact">
                                            <button type="button" class="action-btn edit" data-edit-btn>Edit</button>
                                            <button type="button" class="action-btn save" data-save-btn hidden>Save</button>
                                            <button type="button" class="action-btn cancel" data-cancel-btn hidden>Cancel</button>
                                            <form method="POST" class="inline-action-form" onsubmit="return confirm('Move this user to trash? Permanent deletion will happen only from the trash bin.');">
                                                <input type="hidden" name="action" value="delete_user">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $rowId; ?>">
                                                <button type="submit" class="action-btn delete">Move to Trash</button>
                                            </form>
                                        </div>
                                        <div class="action-group compact row-secondary-actions">
                                            <form method="POST" class="inline-action-form" onsubmit="return confirm('<?php echo $status === 'active' ? 'Deactivate this user? They will lose access to login.' : 'Reactivate this user?'; ?>');">
                                                <input type="hidden" name="action" value="update_status">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $rowId; ?>">
                                                <input type="hidden" name="status" value="<?php echo $status === 'active' ? 'inactive' : 'active'; ?>">
                                                <button type="submit" class="action-btn <?php echo $status === 'active' ? 'deactivate' : 'activate'; ?>"><?php echo $status === 'active' ? 'Deactivate' : 'Reactivate'; ?></button>
                                            </form>
                                            <form method="POST" id="save-form-<?php echo $rowId; ?>" class="hidden-save-form">
                                                <input type="hidden" name="action" value="edit_user">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                                                <input type="hidden" name="user_id" value="<?php echo $rowId; ?>">
                                                <input type="hidden" name="edit_full_name" data-save-field="full_name">
                                                <input type="hidden" name="edit_email" data-save-field="email">
                                                <input type="hidden" name="edit_phone" data-save-field="phone" value="<?php echo htmlspecialchars((string)($user['phone'] ?? '')); ?>">
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <tr class="user-search-empty-row" hidden><td colspan="6" class="user-table-empty">No users match your search.</td></tr>
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
                <form method="POST" class="user-create-form">
                    <input type="hidden" name="action" value="create_account">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <div class="form-row">
                        <div class="form-group"><label for="full_name">Full Name <span class="required-indicator">*</span></label><input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($old['full_name']); ?>" required></div>
                        <div class="form-group"><label for="email">Email <span class="required-indicator">*</span></label><input type="email" id="email" name="email" value="<?php echo htmlspecialchars($old['email']); ?>" required></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group"><label for="phone">Phone Number (PH) <span class="required-indicator">*</span></label><input type="tel" id="phone" name="phone" value="<?php echo htmlspecialchars($old['phone']); ?>" pattern="^09[0-9]{9}$" maxlength="11" placeholder="09XXXXXXXXX" inputmode="numeric" data-ph-phone-lock-prefix required></div>
                        <div class="form-group">
                            <label for="role">Role <span class="required-indicator">*</span></label>
                            <select id="role" name="role" required>
                                <option value="">Select a role</option>
                                <option value="engineer" <?php echo $old['role']=='engineer'?'selected':''; ?>>Engineer</option>
                                <option value="foreman" <?php echo $old['role']=='foreman'?'selected':''; ?>>Foreman</option>
                                <option value="client" <?php echo $old['role']=='client'?'selected':''; ?>>Client</option>
                            </select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group password-field">
                            <label for="password">Temporary Password <span class="required-indicator">*</span></label>
                            <div class="password-input-wrap">
                                <input type="password" id="password" name="password" minlength="12" required>
                                <button type="button" class="togglePassword" data-target="password">Show</button>
                            </div>
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
    </section>
</div>
