<?php
/**
 * ---------------------------------------------------------------------
 *
 * CMMS Department Filter
 *
 * Provides department-based scoping for assets, tickets, and other items.
 * Uses the existing GLPI Location model as the department concept.
 *
 * ---------------------------------------------------------------------
 */

namespace Glpi\CMMS;

use Session;

class DepartmentFilter
{
    /**
     * Get the current user's department (location) ID.
     * 
     * @return int Department ID (0 = no department assigned)
     */
    public static function getUserDepartment(): int
    {
        if (!Session::getLoginUserID()) {
            return 0;
        }

        global $DB;

        $iterator = $DB->request([
            'FROM' => 'glpi_users',
            'WHERE' => ['id' => Session::getLoginUserID()],
            'SELECT' => ['locations_id'],
            'LIMIT' => 1,
        ]);

        if (count($iterator) === 1) {
            $row = $iterator->current();
            return (int) ($row['locations_id'] ?? 0);
        }

        return 0;
    }

    /**
     * Get the active department filter for the current session.
     * Admins can switch departments via the topbar dropdown.
     * 
     * @return int Department ID (0 = no filter / show all)
     */
    public static function getActiveDepartmentFilter(): int
    {
        return (int) ($_SESSION['cmms_department_filter'] ?? 0);
    }

    /**
     * Set the active department filter for the current session.
     * 
     * @param int $departmentId Department ID (0 = no filter)
     * @return void
     */
    public static function setActiveDepartmentFilter(int $departmentId): void
    {
        $_SESSION['cmms_department_filter'] = $departmentId;
    }

    /**
     * Get the effective department filter for the current user.
     * 
     * - Super-admins with an admin-selected filter: use that filter
     * - Super-admins with no filter: 0 (show all)
     * - Non-admins: use their assigned department (if any)
     * 
     * @return int Department ID (0 = show all)
     */
    public static function getEffectiveDepartmentFilter(): int
    {
        // Admin switcher filter takes priority
        $adminFilter = self::getActiveDepartmentFilter();
        if ($adminFilter > 0) {
            return $adminFilter;
        }

        // Admins without a filter see everything
        if (Session::haveRight('config', Session::UPDATE)) {
            return 0;
        }

        // Non-admins are restricted to their assigned department
        return self::getUserDepartment();
    }

    /**
     * Check if a given item belongs to the effective department.
     * 
     * @param int $itemDepartmentId The item's locations_id
     * @return bool True if the item is visible
     */
    public static function isItemVisible(int $itemDepartmentId): bool
    {
        $filter = self::getEffectiveDepartmentFilter();
        
        // No filter = show all
        if ($filter <= 0) {
            return true;
        }

        // Item has no department = always visible
        if ($itemDepartmentId <= 0) {
            return true;
        }

        // Check if item's department matches the filter
        return $itemDepartmentId === $filter;
    }

    /**
     * Get WHERE criteria for GLPI query builder.
     * Returns an array that can be merged into a WHERE clause.
     * 
     * @return array Criteria array for GLPI query
     */
    public static function getWhereCriteria(): array
    {
        $filter = self::getEffectiveDepartmentFilter();
        
        if ($filter <= 0) {
            return [];
        }

        return ['locations_id' => $filter];
    }

    /**
     * Check if the current user can switch departments (admin only).
     * 
     * @return bool
     */
    public static function canSwitchDepartment(): bool
    {
        return Session::haveRight('config', Session::UPDATE);
    }

    /**
     * Get all available departments for the switcher dropdown.
     * 
     * @return array List of departments with id, name, code, asset_count
     */
    public static function getAvailableDepartments(): array
    {
        global $DB;

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

        return $departments;
    }
}
