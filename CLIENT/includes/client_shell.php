<?php

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
        ];
    }
}

if (!function_exists('client_shell_render_topbar')) {
    function client_shell_render_topbar(array $context): void
    {
        $clientName = (string)($context['client_name'] ?? 'Client User');
        $clientInitial = (string)($context['client_initial'] ?? 'C');
        $activeProjectCount = (int)($context['active_project_count'] ?? 0);
        $notificationItems = $context['notification_items'] ?? [];
        ?>
        <header class="global-topbar" aria-live="polite">
            <div class="global-topbar__copy">
                <img src="/codesamplecaps/IMAGES/edge.jpg" alt="Edge Automation logo" class="global-topbar__brand-logo">
                <div class="global-topbar__copy-text">
                    <strong>EDGE Automation</strong>
                    <span>Welcome, <?php echo htmlspecialchars($clientName); ?></span>
                </div>
            </div>

            <div class="global-topbar__actions">
                <div class="topbar-profile" data-profile-root>
                    <button
                        id="topbarProfileToggle"
                        class="topbar-profile__toggle"
                        type="button"
                        aria-label="Open profile menu"
                        aria-controls="topbarProfileDropdown"
                        aria-expanded="false"
                    >
                        <span class="topbar-profile__avatar" aria-hidden="true"><?php echo htmlspecialchars($clientInitial); ?></span>
                        <span class="topbar-profile__identity">
                            <strong>Client</strong>
                            <span><?php echo htmlspecialchars($clientName); ?></span>
                        </span>
                        <span class="topbar-profile__chevron" aria-hidden="true">
                            <svg viewBox="0 0 20 20" focusable="false">
                                <path d="M5 7.5 10 12.5 15 7.5"></path>
                            </svg>
                        </span>
                    </button>

                    <div id="topbarProfileDropdown" class="topbar-profile__dropdown" hidden>
                        <div class="topbar-profile__panel-head">
                            <span class="topbar-profile__avatar topbar-profile__avatar--panel" aria-hidden="true"><?php echo htmlspecialchars($clientInitial); ?></span>
                            <div>
                                <strong><?php echo htmlspecialchars($clientName); ?></strong>
                                <span>Client</span>
                            </div>
                        </div>
                        <div class="topbar-profile__links">
                            <a href="/codesamplecaps/CLIENT/dashboards/client_dashboard.php#overview-section">Dashboard</a>
                            <a href="/codesamplecaps/CLIENT/dashboards/client_dashboard.php#projects-tab">Projects</a>
                            <a href="/codesamplecaps/CLIENT/dashboards/reports.php">Reports</a>
                            <a href="/codesamplecaps/CLIENT/dashboards/quotations.php">Quotations</a>
                            <a href="/codesamplecaps/CLIENT/dashboards/client_dashboard.php#profile-tab">Profile</a>
                            <a href="/codesamplecaps/LOGIN/php/logout.php">Logout</a>
                        </div>
                    </div>
                </div>

                <div class="topbar-notifications" data-notification-root>
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
                        <?php if ($activeProjectCount > 0): ?>
                            <span class="topbar-notifications__badge"><?php echo $activeProjectCount; ?></span>
                        <?php endif; ?>
                    </button>

                    <div id="topbarNotificationDropdown" class="topbar-notifications__dropdown" hidden>
                        <div class="topbar-notifications__panel-head">
                            <div>
                                <strong>Project Updates</strong>
                                <span><?php echo $activeProjectCount; ?> active item(s)</span>
                            </div>
                        </div>
                        <div class="topbar-notifications__section">
                            <div class="topbar-notifications__section-title">Recent signals</div>
                            <?php foreach ($notificationItems as $notification): ?>
                                <article class="notification-item notification-item--neutral">
                                    <span class="notification-item__dot"></span>
                                    <div class="notification-item__copy">
                                        <strong><?php echo htmlspecialchars((string)$notification['title']); ?></strong>
                                        <span><?php echo htmlspecialchars((string)$notification['detail']); ?></span>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="global-topbar__clock">
                    <span class="global-topbar__clock-label">Philippines Time</span>
                    <strong class="global-topbar__time" data-ph-time>--:--:--</strong>
                    <span class="global-topbar__date" data-ph-date>Loading date...</span>
                </div>
            </div>
        </header>
        <?php
    }
}
