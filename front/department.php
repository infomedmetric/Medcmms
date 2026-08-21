<?php
/**
 * ---------------------------------------------------------------------
 *
 * MedCMMS - Computerized Maintenance Management System
 *
 * https://github.com/infomedmetric/glpi
 *
 * @copyright 2015-2026 Teclib' and contributors.
 * @licence   https://www.gnu.org/licenses/gpl-3.0.html
 *
 * ---------------------------------------------------------------------
 *
 * LICENSE
 *
 * This file is part of MedCMMS.
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 *
 * ---------------------------------------------------------------------
 */

include('../inc/includes.php');

Session::checkLoginUser();

// Only super-admins can manage departments
if (!Session::haveRight('config', Session::UPDATE)) {
    Html::displayRightError();
}

// Handle AJAX requests
if (isset($_POST['add_department'])) {
    $name = trim($_POST['name'] ?? '');
    $code = trim($_POST['code'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (!empty($name)) {
        $DB->insert('glpi_locations', [
            'name' => $name,
            'code' => $code,
            'comment' => $description,
            'locations_id' => 1, // Under Hospital root
            'entities_id' => 0,
            'is_recursive' => 1,
            'completename' => 'Hospital > ' . $name,
            'level' => 2,
            'date_creation' => $_SESSION['glpi_financial_year'] ?? date('Y-m-d H:i:s'),
            'date_mod' => $_SESSION['glpi_financial_year'] ?? date('Y-m-d H:i:s'),
        ]);
        Html::back();
    }
}

if (isset($_POST['toggle_department'])) {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0) {
        $DB->update('glpi_locations', 
            ['date_mod' => date('Y-m-d H:i:s')],
            ['id' => $id]
        );
        Html::back();
    }
}

if (isset($_POST['delete_department'])) {
    $id = intval($_POST['id'] ?? 0);
    if ($id > 0 && $id > 1) { // Don't delete root "Hospital" location
        // Unassign users from this department
        $DB->update('glpi_users', 
            ['locations_id' => 0],
            ['locations_id' => $id]
        );
        
        // Unassign assets from this department
        $DB->update('glpi_assets_assets', 
            ['locations_id' => 0],
            ['locations_id' => $id]
        );
        
        // Delete the department
        $DB->delete('glpi_locations', ['id' => $id]);
        Html::back();
    }
}

// Get all departments (child locations of Hospital root)
$departments = iterator_to_array($DB->request([
    'FROM' => 'glpi_locations',
    'WHERE' => [
        'locations_id' => 1,
        'NOT' => ['id' => 1]
    ],
    'ORDER' => 'name ASC'
]));

// Count users and assets per department
$departmentStats = [];
foreach ($departments as &$dept) {
    // Count users in this department
    $userCount = $DB->request([
        'FROM' => 'glpi_users',
        'WHERE' => ['locations_id' => $dept['id']],
        'COUNT' => 'id'
    ])->current();
    $dept['user_count'] = $userCount['id'] ?? 0;
    
    // Count assets in this department
    $assetCount = $DB->request([
        'FROM' => 'glpi_assets_assets',
        'WHERE' => ['locations_id' => $dept['id']],
        'COUNT' => 'id'
    ])->current();
    $dept['asset_count'] = $assetCount['id'] ?? 0;
}
unset($dept);

// Include header
include(GLPI_ROOT . '/front/dropdown.common.php');

// Display department management UI
echo '<div class="container-fluid">';
echo '<div class="card">';
echo '<div class="card-header">';
echo '<h5 class="card-title">';
echo '<i class="ti ti-building-hospital me-2"></i>';
echo __('Medical Departments');
echo '</h5>';
echo '</div>';
echo '<div class="card-body">';

// Add Department Form
echo '<div class="row mb-4">';
echo '<div class="col-md-12">';
echo '<form method="post" class="card">';
echo '<div class="card-body">';
echo '<h6 class="card-subtitle mb-3 text-muted">' . __('Add New Department') . '</h6>';
echo '<div class="row">';
echo '<div class="col-md-3">';
echo '<div class="form-floating mb-3">';
echo '<input type="text" class="form-control" name="name" id="dept_name" placeholder="' . __('Department Name') . '" required>';
echo '<label for="dept_name">' . __('Department Name') . '</label>';
echo '</div>';
echo '</div>';
echo '<div class="col-md-2">';
echo '<div class="form-floating mb-3">';
echo '<input type="text" class="form-control" name="code" id="dept_code" placeholder="' . __('Code') . '">';
echo '<label for="dept_code">' . __('Code') . '</label>';
echo '</div>';
echo '</div>';
echo '<div class="col-md-5">';
echo '<div class="form-floating mb-3">';
echo '<input type="text" class="form-control" name="description" id="dept_desc" placeholder="' . __('Description') . '">';
echo '<label for="dept_desc">' . __('Description') . '</label>';
echo '</div>';
echo '</div>';
echo '<div class="col-md-2">';
echo '<button type="submit" name="add_department" class="btn btn-primary w-100">';
echo '<i class="ti ti-plus me-2"></i>';
echo __('Add Department');
echo '</button>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '</form>';
echo '</div>';
echo '</div>';

// Department List
echo '<div class="table-responsive">';
echo '<table class="table table-striped table-hover">';
echo '<thead>';
echo '<tr>';
echo '<th>' . __('Department Name') . '</th>';
echo '<th>' . __('Code') . '</th>';
echo '<th>' . __('Description') . '</th>';
echo '<th class="text-center">' . __('Users') . '</th>';
echo '<th class="text-center">' . __('Assets') . '</th>';
echo '<th class="text-center">' . __('Actions') . '</th>';
echo '</tr>';
echo '</thead>';
echo '<tbody>';

foreach ($departmentStats as $dept) {
    echo '<tr>';
    echo '<td><strong>' . htmlspecialchars($dept['name']) . '</strong></td>';
    echo '<td>' . htmlspecialchars($dept['code'] ?? '') . '</td>';
    echo '<td>' . htmlspecialchars($dept['comment'] ?? '') . '</td>';
    echo '<td class="text-center">';
    echo '<span class="badge bg-info rounded-pill">' . $dept['user_count'] . '</span>';
    echo '</td>';
    echo '<td class="text-center">';
    echo '<span class="badge bg-success rounded-pill">' . $dept['asset_count'] . '</span>';
    echo '</td>';
    echo '<td class="text-center">';
    
    // Edit button
    echo '<a href="' . $CFG_GLPI['root_doc'] . '/front/location.form.php?id=' . $dept['id'] . '" class="btn btn-sm btn-outline-primary me-1" title="' . __('Edit') . '">';
    echo '<i class="ti ti-edit"></i>';
    echo '</a>';
    
    // Delete button (only for non-root departments)
    if ($dept['id'] > 1) {
        echo '<form method="post" class="d-inline">';
        echo '<input type="hidden" name="id" value="' . $dept['id'] . '">';
        echo '<button type="submit" name="delete_department" class="btn btn-sm btn-outline-danger" title="' . __('Delete') . '" onclick="return confirm(\'' . __('Are you sure you want to delete this department?') . '\')">';
        echo '<i class="ti ti-trash"></i>';
        echo '</button>';
        echo '</form>';
    }
    
    echo '</td>';
    echo '</tr>';
}

echo '</tbody>';
echo '</table>';
echo '</div>';

echo '</div>';
echo '</div>';
echo '</div>';

// Include footer
include(GLPI_ROOT . '/front/dropdown.common.footer.php');
