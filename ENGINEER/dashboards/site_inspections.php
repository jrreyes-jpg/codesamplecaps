<?php
define('AUTH_REQUIRED_ROLE', 'engineer');
require_once __DIR__ . '/../../config/auth_check.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/site_inspections.php';
require_once __DIR__ . '/../includes/engineer_helpers.php';

$userId = (int)($_SESSION['user_id'] ?? 0);
site_inspection_ensure_table($conn);

$inspections = [];
$stmt = $conn->prepare(
    'SELECT
        si.id,
        si.scheduled_at,
        si.site_notes,
        si.status,
        si.created_at,
        s.client_name,
        s.company_name,
        s.email,
        s.contact_no,
        s.site_address,
        s.service_category,
        s.description
     FROM site_inspections si
     INNER JOIN service_inquiries s ON s.id = si.inquiry_id
     WHERE si.engineer_id = ?
     ORDER BY si.scheduled_at ASC, si.id DESC'
);
if ($stmt) {
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $inspections[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Site Inspections - Engineer</title>
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../css/engineer-sidebar.css">
    <link rel="stylesheet" href="../css/engineer.css">
    <style>
        .inspection-shell { display: grid; gap: 16px; padding: 28px; }
        .inspection-panel { background: #fff; border: 1px solid #e2e8f0; border-radius: 18px; padding: 18px; box-shadow: 0 18px 42px rgba(15, 23, 42, 0.08); }
        .inspection-card { display: grid; gap: 10px; padding: 14px; border: 1px solid #dbeafe; border-radius: 14px; background: #eff6ff; }
        .inspection-card + .inspection-card { margin-top: 10px; }
        .inspection-card__head { display: flex; justify-content: space-between; gap: 12px; flex-wrap: wrap; }
        .inspection-card h2 { margin: 0; font-size: 1.05rem; color: #0f172a; }
        .inspection-meta, .inspection-card p { margin: 0; color: #475569; }
        .inspection-status { width: fit-content; padding: 5px 10px; border-radius: 999px; background: #dbeafe; color: #1d4ed8; font-size: 0.78rem; font-weight: 800; }
        .inspection-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 10px; }
        .inspection-detail { padding: 10px; border-radius: 12px; background: #fff; overflow-wrap: anywhere; }
        .inspection-detail span { display: block; color: #64748b; font-size: 0.78rem; font-weight: 700; }
        .inspection-detail strong { display: block; margin-top: 4px; color: #0f172a; }
        @media (max-width: 800px) { .inspection-grid { grid-template-columns: 1fr; } }
    </style>
</head>
<body>
<?php include '../sidebar/sidebar_engineer.php'; ?>
<main class="main-content">
    <div class="inspection-shell">
        <section class="inspection-panel">
            <p class="reports-kicker">Engineer Work Queue</p>
            <h1>Site Inspections</h1>
            <p class="inspection-meta">Assigned client inspections from Admin lead filtering.</p>
        </section>

        <section class="inspection-panel">
            <?php if (empty($inspections)): ?>
                <p class="inspection-meta">No assigned site inspections yet.</p>
            <?php else: ?>
                <?php foreach ($inspections as $inspection): ?>
                    <article class="inspection-card">
                        <div class="inspection-card__head">
                            <div>
                                <h2><?php echo htmlspecialchars((string)$inspection['client_name'], ENT_QUOTES, 'UTF-8'); ?></h2>
                                <p class="inspection-meta"><?php echo htmlspecialchars((string)$inspection['service_category'], ENT_QUOTES, 'UTF-8'); ?></p>
                            </div>
                            <span class="inspection-status"><?php echo htmlspecialchars((string)$inspection['status'], ENT_QUOTES, 'UTF-8'); ?></span>
                        </div>
                        <div class="inspection-grid">
                            <div class="inspection-detail"><span>Schedule</span><strong><?php echo htmlspecialchars(site_inspection_format_datetime($inspection['scheduled_at'] ?? null), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div class="inspection-detail"><span>Contact</span><strong><?php echo htmlspecialchars((string)$inspection['contact_no'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div class="inspection-detail"><span>Email</span><strong><?php echo htmlspecialchars((string)$inspection['email'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div class="inspection-detail"><span>Company</span><strong><?php echo htmlspecialchars((string)($inspection['company_name'] ?: 'N/A'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div class="inspection-detail"><span>Site Address</span><strong><?php echo htmlspecialchars((string)$inspection['site_address'], ENT_QUOTES, 'UTF-8'); ?></strong></div>
                            <div class="inspection-detail"><span>Admin Notes</span><strong><?php echo htmlspecialchars((string)($inspection['site_notes'] ?: 'None'), ENT_QUOTES, 'UTF-8'); ?></strong></div>
                        </div>
                        <p><?php echo nl2br(htmlspecialchars((string)$inspection['description'], ENT_QUOTES, 'UTF-8')); ?></p>
                    </article>
                <?php endforeach; ?>
            <?php endif; ?>
        </section>
    </div>
</main>
<script src="../js/engineer.js"></script>
</body>
</html>
