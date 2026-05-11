<?php
require_once __DIR__ . '/../../config/auth_middleware.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/project_search_support.php';

require_role('super_admin');

header('Content-Type: application/json; charset=UTF-8');

$searchQuery = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$view = trim((string)($_GET['view'] ?? ''));
$trashFilterSql = $view === 'trash' ? 'p.deleted_at IS NOT NULL' : 'p.deleted_at IS NULL';
$limit = min(10, max(1, (int)($_GET['limit'] ?? 8)));
$hasProjectSiteColumn = project_search_table_has_column($conn, 'projects', 'project_site');
$hasProjectAddressColumn = project_search_table_has_column($conn, 'projects', 'project_address');
$hasProjectEmailColumn = project_search_table_has_column($conn, 'projects', 'project_email');
$hasProjectCodeColumn = project_search_table_has_column($conn, 'projects', 'project_code');
$hasPoNumberColumn = project_search_table_has_column($conn, 'projects', 'po_number');
$hasContactPersonColumn = project_search_table_has_column($conn, 'projects', 'contact_person');
$hasContactNumberColumn = project_search_table_has_column($conn, 'projects', 'contact_number');

ensure_project_search_indexes($conn, $hasProjectAddressColumn, $hasProjectSiteColumn);

if (mb_strlen($searchQuery) < 2) {
    echo json_encode([
        'query' => $searchQuery,
        'results' => [],
        'message' => 'Type at least 2 characters.',
    ]);
    exit();
}

$results = project_search_fetch_suggestions($conn, $hasProjectAddressColumn, $hasProjectEmailColumn, $hasProjectCodeColumn, $hasPoNumberColumn, $hasProjectSiteColumn, $hasContactPersonColumn, $hasContactNumberColumn, $searchQuery, $statusFilter, $limit, $trashFilterSql);
$payload = array_map(
    static function (array $project): array {
        return [
            'id' => (int)($project['id'] ?? 0),
            'title' => (string)($project['project_name'] ?? 'Project'),
            'status' => (string)($project['status'] ?? ''),
            'client' => (string)($project['client_name'] ?? 'N/A'),
            'engineer' => (string)($project['engineer_names'] ?? 'Not assigned'),
            'site' => (string)($project['project_site'] ?? ($project['project_address'] ?? '')),
            'link' => '/codesamplecaps/SUPERADMIN/sidebar/project_details.php?id=' . (int)($project['id'] ?? 0),
        ];
    },
    $results
);

echo json_encode([
    'query' => $searchQuery,
    'results' => $payload,
    'message' => empty($payload) ? 'No matching projects found.' : null,
]);
