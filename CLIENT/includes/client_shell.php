<?php

require_once __DIR__ . '/../../config/user_notifications.php';
require_once __DIR__ . '/../../config/profile_photo_storage.php';

if (!function_exists('client_shell_initial')) {
    function client_shell_initial(string $name): string
    {
        $trimmed = trim($name);
        return strtoupper(substr($trimmed !== '' ? $trimmed : 'C', 0, 1));
    }
}

if (!function_exists('client_shell_build_topbar_context')) {
    function client_shell_build_topbar_context(mysqli $conn, int $userId, string $clientName, string $clientEmailDisplay): array
    {
        $clientInitial = client_shell_initial($clientName);
        $activeProjectCount = 0;
        $completedProjectCount = 0;
        $nextDeadline = null;
        $quotationPendingCount = 0;
        $userNotificationState = user_notifications_fetch_unread_state($conn, $userId);

        $projectStatement = $conn->prepare(
            "SELECT
                p.status,
                task_totals.next_deadline
             FROM projects p
             LEFT JOIN (
                 SELECT
                     project_id,
                     MIN(CASE WHEN status <> 'completed' AND deadline IS NOT NULL THEN deadline END) AS next_deadline
                 FROM tasks
                 GROUP BY project_id
             ) task_totals ON task_totals.project_id = p.id
             WHERE p.client_id = ?
             AND p.status <> 'draft'"
        );

        if ($projectStatement) {
            $projectStatement->bind_param('i', $userId);
            $projectStatement->execute();
            $projectResult = $projectStatement->get_result();
            $projectRows = $projectResult ? $projectResult->fetch_all(MYSQLI_ASSOC) : [];
            $projectStatement->close();

            foreach ($projectRows as $projectRow) {
                $status = (string)($projectRow['status'] ?? 'pending');

                if (in_array($status, ['pending', 'ongoing', 'on-hold'], true)) {
                    $activeProjectCount++;
                }

                if ($status === 'completed') {
                    $completedProjectCount++;
                }

                $candidateDeadline = trim((string)($projectRow['next_deadline'] ?? ''));
                if ($candidateDeadline !== '' && ($nextDeadline === null || $candidateDeadline < $nextDeadline)) {
                    $nextDeadline = $candidateDeadline;
                }
            }
        }

        if (function_exists('quotation_module_tables_ready') && function_exists('quotation_module_fetch_quotations') && quotation_module_tables_ready($conn)) {
            $quotationRows = quotation_module_fetch_quotations($conn, 'client', $userId);

            foreach ($quotationRows as $quotationRow) {
                if ((string)($quotationRow['status'] ?? '') === 'sent') {
                    $quotationPendingCount++;
                }
            }
        }

        $notificationItems = [
            [
                'title' => $activeProjectCount . ' active project(s)',
                'detail' => 'Current work that still needs visibility from your side.',
            ],
            [
                'title' => $completedProjectCount . ' completed project(s)',
                'detail' => 'Delivered work already closed out in the system.',
            ],
            [
                'title' => ($nextDeadline !== null ? date('M j, Y', strtotime($nextDeadline)) : 'No tracked deadline'),
                'detail' => $nextDeadline !== null
                    ? 'Nearest open milestone across your projects.'
                    : 'No upcoming deadline is currently tracked.',
            ],
            [
                'title' => $quotationPendingCount . ' quotation(s) waiting response',
                'detail' => 'Review pricing and scope before confirming or rejecting.',
            ],
        ];

        return [
            'client_name' => $clientName,
            'client_email_display' => $clientEmailDisplay,
            'client_initial' => $clientInitial,
            'active_project_count' => $activeProjectCount,
            'quotation_pending_count' => $quotationPendingCount,
            'notification_items' => $notificationItems,
            'user_notification_unread_count' => (int)($userNotificationState['unread_count'] ?? 0),
            'user_notification_items' => $userNotificationState['items'] ?? [],
        ];
    }
}

if (!function_exists('client_shell_render_topbar')) {
    function client_shell_render_topbar(array $context): void
    {
        global $conn;

        $clientName = (string)($context['client_name'] ?? 'Client User');
        $clientInitial = (string)($context['client_initial'] ?? 'C');
        $clientPhotoUrl = '';
        $clientUserId = (int)($_SESSION['user_id'] ?? 0);
        if ($clientUserId > 0 && $conn instanceof mysqli) {
            $photoStmt = $conn->prepare('SELECT profile_photo_path FROM users WHERE id = ? LIMIT 1');
            if ($photoStmt) {
                $photoStmt->bind_param('i', $clientUserId);
                $photoStmt->execute();
                $photoRow = $photoStmt->get_result()->fetch_assoc() ?: [];
                $photoStmt->close();
                $photoPath = trim((string)($photoRow['profile_photo_path'] ?? ''));
                if ($photoPath !== '') {
                    $clientPhotoUrl = profile_photo_public_url($photoPath, $clientUserId);
                }
            }
        }
        $userNotificationUnreadCount = (int)($context['user_notification_unread_count'] ?? 0);
        $userNotificationItems = is_array($context['user_notification_items'] ?? null)
            ? $context['user_notification_items']
            : [];
        $notificationItems = is_array($context['notification_items'] ?? null)
            ? $context['notification_items']
            : [];
        $clientNotificationCsrf = auth_csrf_token('client_user_notifications');

        ob_start();

        $headerProfileName = $clientName;
        $headerProfileRole = 'Client';
        $headerProfilePhotoUrl = $clientPhotoUrl;
        $headerProfileInitials = $clientInitial;
        $headerProfileAlt = 'Client profile picture';
        $headerProfileLinks = [
            [
                'label' => 'Account Settings',
                'href' => '/codesamplecaps/CLIENT/dashboards/account_settings.php?section=profile',
            ],
            [
                'label' => 'Logout',
                'href' => '/codesamplecaps/LOGIN/php/logout.php',
            ],
        ];

        ob_start();
        include __DIR__ . '/../../SHARED/header/profile/php/profile.php';
        $operationsHeaderProfileHtml = (string)ob_get_clean();
        ?>
        <div
            class="topbar-notifications"
            data-notification-root
            data-client-notification-endpoint="/codesamplecaps/CLIENT/api/notifications.php"
            data-client-notification-csrf="<?php echo htmlspecialchars($clientNotificationCsrf, ENT_QUOTES, 'UTF-8'); ?>"
        >
            <button
                id="topbarNotificationToggle"
                class="topbar-notifications__toggle"
                type="button"
                aria-label="Open notifications"
                aria-controls="topbarNotificationDropdown"
                aria-expanded="false"
            >
                <span class="topbar-notifications__icon" aria-hidden="true">
                    <svg viewBox="0 0 24 24" focusable="false">
                        <path d="M12 3a4 4 0 0 0-4 4v1.1a7 7 0 0 1-1.52 4.33L5 14.5V16h14v-1.5l-1.48-2.07A7 7 0 0 1 16 8.1V7a4 4 0 0 0-4-4Zm0 18a3 3 0 0 0 2.83-2H9.17A3 3 0 0 0 12 21Z" fill="currentColor"/>
                    </svg>
                </span>
                <span class="topbar-notifications__badge" data-client-notification-badge<?php echo $userNotificationUnreadCount > 0 ? '' : ' hidden'; ?>><?php echo $userNotificationUnreadCount > 99 ? '99+' : $userNotificationUnreadCount; ?></span>
            </button>

            <div id="topbarNotificationDropdown" class="topbar-notifications__dropdown" hidden>
                <div class="topbar-notifications__panel-head">
                    <div>
                        <strong>Notifications</strong>
                        <span data-client-notification-count><?php echo $userNotificationUnreadCount; ?> unread</span>
                    </div>
                </div>
                <div class="topbar-notifications__section" data-client-notification-list>
                    <?php if ($userNotificationItems === []): ?>
                        <div class="topbar-notifications__empty">No unread notifications.</div>
                    <?php else: ?>
                        <?php foreach ($userNotificationItems as $notification): ?>
                            <a
                                href="<?php echo htmlspecialchars((string)$notification['target_url'], ENT_QUOTES, 'UTF-8'); ?>"
                                class="notification-item notification-item--info"
                                data-client-notification-id="<?php echo (int)$notification['id']; ?>"
                            >
                                <span class="notification-item__dot"></span>
                                <div class="notification-item__copy">
                                    <strong><?php echo htmlspecialchars((string)$notification['title'], ENT_QUOTES, 'UTF-8'); ?></strong>
                                    <span><?php echo htmlspecialchars((string)$notification['message'], ENT_QUOTES, 'UTF-8'); ?></span>
                                </div>
                                <span class="notification-item__time"><?php echo htmlspecialchars(user_notifications_relative_time((string)($notification['created_at'] ?? '')), ENT_QUOTES, 'UTF-8'); ?></span>
                            </a>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                <div class="topbar-notifications__section">
                    <div class="topbar-notifications__section-title">Recent signals</div>
                    <?php foreach ($notificationItems as $notification): ?>
                        <article class="notification-item notification-item--neutral">
                            <span class="notification-item__dot"></span>
                            <div class="notification-item__copy">
                                <strong><?php echo htmlspecialchars((string)($notification['title'] ?? 'Update')); ?></strong>
                                <span><?php echo htmlspecialchars((string)($notification['detail'] ?? '')); ?></span>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php

        echo $operationsHeaderProfileHtml;
        $operationsHeaderActionsHtml = (string)ob_get_clean();
        $operationsHeaderRole = 'client';
        $operationsHeaderClass = 'global-topbar';
        $operationsHeaderBrandClass = 'global-topbar__copy global-topbar__brand-link';
        $operationsHeaderActionsClass = 'global-topbar__actions';
        $operationsHeaderClockClass = 'global-topbar__clock';
        $operationsHeaderHomeHref = '/codesamplecaps/CLIENT/dashboards/client_dashboard.php';
        $operationsHeaderBrandText = 'EDGE Automation';
        $operationsHeaderLogoClass = 'global-topbar__brand-logo operations-topbar__brand-logo';
        $operationsHeaderBrandLabel = 'Go to Client dashboard';
        $operationsHeaderTime = '--:--:--';
        $operationsHeaderDate = 'Loading date...';
        $operationsHeaderTimeAttr = 'class="global-topbar__time" data-ph-time';
        $operationsHeaderDateAttr = 'class="global-topbar__date" data-ph-date';
        $operationsHeaderAttrs = 'aria-live="polite"';

        include __DIR__ . '/../../SHARED/header/core/operations-header.php';
    }
}
