<?php
/**
 * ---------------------------------------------------------------------
 *
 * CMMS Department Switcher
 *
 * AJAX endpoint for switching the active department filter.
 * Used by the admin department switcher in the topbar.
 *
 * ---------------------------------------------------------------------
 */

include('../inc/includes.php');

header("Content-Type: application/json; charset=UTF-8");

if (!Session::getLoginUserID()) {
    http_response_code(401);
    echo json_encode(['error' => 'Not logged in']);
    exit;
}

// Only super-admins can switch departments
if (!Session::haveRight('config', Session::UPDATE)) {
    http_response_code(403);
    echo json_encode(['error' => 'Insufficient permissions']);
    exit;
}

// Handle POST requests for switching
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    $departmentId = intval($input['department_id'] ?? $_POST['department_id'] ?? 0);
    
    // Store the department filter in session
    \Glpi\CMMS\DepartmentFilter::setActiveDepartmentFilter($departmentId);
    
    echo json_encode([
        'success' => true,
        'department_id' => $departmentId,
        'message' => $departmentId > 0 ? 'Department filter applied' : 'Showing all departments'
    ]);
    exit;
}

// Handle GET requests to fetch available departments
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $departments = [];
    
    $iterator = $DB->request([
        'FROM' => 'glpi_locations',
        'WHERE' => [
            'locations_id' => 1, // Under Hospital root
            'NOT' => ['id' => 1]
        ],
        'ORDER' => 'name ASC'
    ]);
    
    foreach ($iterator as $dept) {
        // Count assets in this department
        $assetCount = $DB->request([
            'FROM' => 'glpi_assets_assets',
            'WHERE' => ['locations_id' => $dept['id']],
            'COUNT' => 'id'
        ])->current();
        
        $departments[] = [
            'id' => $dept['id'],
            'name' => $dept['name'],
            'code' => $dept['code'] ?? '',
            'asset_count' => $assetCount['id'] ?? 0,
        ];
    }
    
    // Get current filter from session
    $currentFilter = \Glpi\CMMS\DepartmentFilter::getActiveDepartmentFilter();
    
    echo json_encode([
        'success' => true,
        'departments' => $departments,
        'current_filter' => $currentFilter,
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
