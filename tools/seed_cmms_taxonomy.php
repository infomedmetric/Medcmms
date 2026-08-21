<?php

/**
 * ---------------------------------------------------------------------
 *
 * MedEquip CMMS — seed the medical-equipment asset taxonomy.
 *
 * Uses GLPI 11 "generic assets" to create a "Medical equipment" asset
 * definition (its own entry in the Assets menu) with CMMS capacities,
 * device types (Ventilator, Hemodialysis machine, ...), manufacturers
 * and device models.
 *
 * Additionally seeds the CMMS-specific structure:
 *  - a "Equipment category" custom dropdown (Imaging, Laboratory, ICU, ...)
 *    with a matching custom field on the medical equipment form;
 *  - manufacturer <-> model <-> type links (each model belongs to one
 *    manufacturer and one device type), which drive the cascading
 *    Type / Model dropdowns on the asset form;
 *  - the hospital department list (as GLPI Locations), used as the
 *    "Department" field on the asset form.
 *
 * Idempotent: safe to re-run, existing entries are skipped or updated.
 *
 * Run:  php tools/seed_cmms_taxonomy.php
 *
 * ---------------------------------------------------------------------
 */

use Glpi\Asset\AssetDefinition;
use Glpi\Asset\Capacity\AllowedInGlobalSearchCapacity;
use Glpi\Asset\Capacity\HasCertificatesCapacity;
use Glpi\Asset\Capacity\HasContractsCapacity;
use Glpi\Asset\Capacity\HasDocumentsCapacity;
use Glpi\Asset\Capacity\HasHistoryCapacity;
use Glpi\Asset\Capacity\HasInfocomCapacity;
use Glpi\Asset\Capacity\HasKnowbaseCapacity;
use Glpi\Asset\Capacity\HasLinksCapacity;
use Glpi\Asset\Capacity\HasNotepadCapacity;
use Glpi\Asset\CustomFieldDefinition;
use Glpi\Asset\CustomFieldType\DropdownType;
use Glpi\CustomObject\AbstractDefinition;
use Glpi\Dropdown\DropdownDefinition;
use Glpi\Kernel\Kernel;

if (PHP_SAPI !== 'cli') {
    echo "This script must be run from command line\n";
    exit(1);
}

require dirname(__DIR__) . '/vendor/autoload.php';

$kernel = new Kernel();
$kernel->boot();

global $DB;
$DB->connect(); // ensure the mysqli handle is initialized in CLI context

// ------------------------------------------------------------------
// 0. Rights helper: grant rights on an asset / dropdown definition to
//    the central profiles.
// ------------------------------------------------------------------
$grant_definition_rights = static function (AbstractDefinition $definition, int $full_rights, int $read_rights) use ($DB): void {
    $profiles_iterator = $DB->request([
        'FROM'  => Profile::getTable(),
        'ORDER' => 'id ASC',
    ]);
    foreach ($profiles_iterator as $profile_data) {
        $profile_id   = (int) $profile_data['id'];
        $profile_name = (string) $profile_data['name'];
        $rights = match ($profile_data['interface']) {
            'central' => match (strtolower($profile_name)) {
                'admin', 'super-admin', 'supervisor', 'technician' => $full_rights,
                'observer', 'hotliner', 'read-only'                => $read_rights,
                default                                            => $full_rights,
            },
            default => 0, // self-service / helpdesk interfaces have no asset rights
        };
        $definition->setProfileRights($profile_id, $rights);
        echo "  rights set for profile '{$profile_name}': {$rights}\n";
    }
};

// ------------------------------------------------------------------
// 1. Asset definition
// ------------------------------------------------------------------
$definition = new AssetDefinition();
$definition_id = null;

if ($definition->getFromDBBySystemName('medical_equipment')) {
    echo "Asset definition 'medical_equipment' already exists (id {$definition->getID()}).\n";
    $definition_id = (int) $definition->getID();
} else {
    $new_id = $definition->add([
        'system_name' => 'medical_equipment',
        'label'       => 'Medical equipment',
        'icon'        => 'ti ti-stethoscope',
        'is_active'   => 1,
        'capacities'  => [
            ['name' => AllowedInGlobalSearchCapacity::class],
            ['name' => HasCertificatesCapacity::class],  // calibration / safety certificates
            ['name' => HasContractsCapacity::class],     // maintenance contracts
            ['name' => HasDocumentsCapacity::class],     // manuals, service reports
            ['name' => HasHistoryCapacity::class],       // audit trail
            ['name' => HasInfocomCapacity::class],       // purchase / warranty / value
            ['name' => HasKnowbaseCapacity::class],      // device knowledge base
            ['name' => HasLinksCapacity::class],         // external links
            ['name' => HasNotepadCapacity::class],       // free notes
        ],
    ]);
    if ($new_id === false) {
        echo "ERROR: could not create the 'medical_equipment' asset definition.\n";
        exit(1);
    }
    echo "Created asset definition 'medical_equipment' (id $new_id).\n";
    $definition_id = (int) $new_id;
    $definition->getFromDB($definition_id);
}

// ------------------------------------------------------------------
// 1bis. Remove every other asset type: the CMMS exposes only
//      "Medical equipment" in the Assets menu.
// ------------------------------------------------------------------
$definitions = getAllDataFromTable(AssetDefinition::getTable());
foreach ($definitions as $definition_data) {
    if ($definition_data['system_name'] === 'medical_equipment') {
        continue;
    }
    if ((int) $definition_data['is_active'] === 0) {
        echo "  asset definition already inactive: {$definition_data['system_name']}\n";
        continue;
    }
    $other_definition = new AssetDefinition();
    if ($other_definition->getFromDB((int) $definition_data['id'])) {
        $other_definition->update([
            'id'        => (int) $definition_data['id'],
            'is_active' => 0,
        ]);
        echo "  deactivated asset definition: {$definition_data['system_name']}\n";
    }
}

// ------------------------------------------------------------------
// 1ter. Grant rights on the medical equipment asset to central profiles,
//       mirroring the classic 'computer' asset rights. Without this the
//       Assets > Medical equipment menu stays hidden for every profile.
// ------------------------------------------------------------------
$full_rights = READ | UPDATE | CREATE | DELETE | PURGE | READNOTE | UPDATENOTE
    | READ_ASSIGNED | UPDATE_ASSIGNED | READ_OWNED | UPDATE_OWNED; // == 3967 (all rights)
$read_rights = READ | READNOTE | READ_ASSIGNED | READ_OWNED;      // == 1313 (view only)
$grant_definition_rights($definition, $full_rights, $read_rights);

// ------------------------------------------------------------------
// 1quater. Extend the asset model table with the manufacturer and
//          device-type foreign keys used by the cascading dropdowns.
//          (MariaDB / MySQL compatible: guarded by fieldExists.)
// ------------------------------------------------------------------
$model_table = $definition->getAssetModelClassName()::getTable();
// DDL (ALTER) cannot run inside a transaction, so it is done before
// beginTransaction() below.
if (!$DB->fieldExists($model_table, 'manufacturers_id')) {
    $DB->doQuery("ALTER TABLE `$model_table` ADD `manufacturers_id` INT UNSIGNED NOT NULL DEFAULT 0");
    echo "  added column manufacturers_id on {$model_table}\n";
} else {
    echo "  column manufacturers_id already exists on {$model_table}\n";
}
if (!$DB->fieldExists($model_table, 'assets_assettypes_id')) {
    $DB->doQuery("ALTER TABLE `$model_table` ADD `assets_assettypes_id` INT UNSIGNED NOT NULL DEFAULT 0");
    echo "  added column assets_assettypes_id on {$model_table}\n";
} else {
    echo "  column assets_assettypes_id already exists on {$model_table}\n";
}

$DB->beginTransaction();

// ------------------------------------------------------------------
// 2. Asset types (category of equipment)
// ------------------------------------------------------------------
$types = [
    'Ventilator',
    'Anesthesia machine',
    'Patient monitor',
    'Defibrillator',
    'Infusion pump',
    'Syringe pump',
    'Hemodialysis machine',
    'MRI scanner',
    'CT scanner',
    'X-ray machine',
    'Ultrasound machine',
    'Laboratory analyzer',
    'Autoclave / sterilizer',
    'Surgical instrument',
    'Other medical device',
];

$type_classname = $definition->getAssetTypeClassName();
$type_obj = new $type_classname();
foreach ($types as $type_name) {
    if ($type_obj->getFromDBByCrit(['name' => $type_name, 'assets_assetdefinitions_id' => $definition_id])) {
        echo "  type already exists: $type_name\n";
        continue;
    }
    if ($type_obj->add(['name' => $type_name]) !== false) {
        echo "  added type: $type_name\n";
    } else {
        echo "  ERROR adding type: $type_name\n";
    }
}

// ------------------------------------------------------------------
// 3. Manufacturers
// ------------------------------------------------------------------
$manufacturers = [
    'Dräger',
    'Philips Healthcare',
    'GE HealthCare',
    'Siemens Healthineers',
    'Fresenius Medical Care',
    'Mindray',
    'Baxter',
    'Medtronic',
    'Getinge',
    'Nihon Kohden',
    'ZOLL',
    'B. Braun',
    'Roche Diagnostics',
    'Stryker',
];

$manufacturer = new Manufacturer();
foreach ($manufacturers as $manufacturer_name) {
    if ($manufacturer->getFromDBByCrit(['name' => $manufacturer_name])) {
        echo "  manufacturer already exists: $manufacturer_name\n";
        continue;
    }
    if ($manufacturer->add(['name' => $manufacturer_name]) !== false) {
        echo "  added manufacturer: $manufacturer_name\n";
    } else {
        echo "  ERROR adding manufacturer: $manufacturer_name\n";
    }
}

// ------------------------------------------------------------------
// 4. Asset models (device model names), each linked to ONE device type
//    and ONE manufacturer. This is what powers the cascading
//    Manufacturer -> Type -> Model dropdowns on the asset form.
// ------------------------------------------------------------------
$models = [
    // [model name, type, manufacturer]
    // Ventilators
    ['Dräger Evita V500',           'Ventilator',              'Dräger'],
    ['Dräger Evita Infinity V600',  'Ventilator',              'Dräger'],
    ['Getinge Servo-u',             'Ventilator',              'Getinge'],
    ['Philips Respironics V60',     'Ventilator',              'Philips Healthcare'],
    // Anesthesia
    ['GE Avance CS2',               'Anesthesia machine',      'GE HealthCare'],
    // Hemodialysis
    ['Fresenius 4008S',             'Hemodialysis machine',    'Fresenius Medical Care'],
    ['Fresenius 5008 CorDiax',      'Hemodialysis machine',    'Fresenius Medical Care'],
    // Patient monitors
    ['Philips IntelliVue MX450',    'Patient monitor',         'Philips Healthcare'],
    ['Nihon Kohden BSM-6501',       'Patient monitor',         'Nihon Kohden'],
    ['Mindray uMEC12',              'Patient monitor',         'Mindray'],
    // Ultrasound
    ['GE Vivid S70',                'Ultrasound machine',      'GE HealthCare'],
    ['Philips EPIQ 7',              'Ultrasound machine',      'Philips Healthcare'],
    // Defibrillators
    ['Philips HeartStart XL+',      'Defibrillator',           'Philips Healthcare'],
    ['ZOLL X Series',               'Defibrillator',           'ZOLL'],
    // Infusion / syringe pumps
    ['Baxter SIGMA Spectrum',       'Infusion pump',           'Baxter'],
    ['B. Braun Infusomat Space',    'Infusion pump',           'B. Braun'],
    ['B. Braun Perfusor Space',     'Syringe pump',            'B. Braun'],
    // Imaging
    ['Siemens Magnetom Vida',       'MRI scanner',             'Siemens Healthineers'],
    ['GE Revolution CT',            'CT scanner',              'GE HealthCare'],
    ['Siemens Multix Impact',       'X-ray machine',           'Siemens Healthineers'],
    // Laboratory
    ['Roche Cobas 8000',            'Laboratory analyzer',     'Roche Diagnostics'],
    // Sterilization
    ['Getinge HS66',                'Autoclave / sterilizer',  'Getinge'],
    // Surgery
    ['Stryker 1588 HD Camera',      'Surgical instrument',     'Stryker'],
];

$model_classname = $definition->getAssetModelClassName();
$model_obj = new $model_classname();
foreach ($models as $model_data) {
    [$model_name, $model_type, $model_manufacturer] = $model_data;

    // Resolve the type and the manufacturer ids
    $type_id = 0;
    if ($type_obj->getFromDBByCrit(['name' => $model_type, 'assets_assetdefinitions_id' => $definition_id])) {
        $type_id = (int) $type_obj->getID();
    } else {
        echo "  WARNING: type '$model_type' not found for model '$model_name'\n";
    }
    $manufacturer_id = 0;
    if ($manufacturer->getFromDBByCrit(['name' => $model_manufacturer])) {
        $manufacturer_id = (int) $manufacturer->getID();
    } else {
        echo "  WARNING: manufacturer '$model_manufacturer' not found for model '$model_name'\n";
    }

    if ($model_obj->getFromDBByCrit(['name' => $model_name, 'assets_assetdefinitions_id' => $definition_id])) {
        $model_obj->update([
            'id'                  => (int) $model_obj->getID(),
            'manufacturers_id'    => $manufacturer_id,
            'assets_assettypes_id' => $type_id,
        ]);
        echo "  updated model: $model_name (type: $model_type, manufacturer: $model_manufacturer)\n";
        continue;
    }
    $added = $model_obj->add([
        'name'                  => $model_name,
        'manufacturers_id'      => $manufacturer_id,
        'assets_assettypes_id'  => $type_id,
    ]);
    if ($added !== false) {
        echo "  added model: $model_name (type: $model_type, manufacturer: $model_manufacturer)\n";
    } else {
        echo "  ERROR adding model: $model_name\n";
    }
}

// ------------------------------------------------------------------
// 5. "Equipment category" custom dropdown + its values.
//    Used by the 'Equipment category' custom field on the asset form
//    to differentiate Imaging / Laboratory / ICU / ... equipment.
// ------------------------------------------------------------------
$category_definition = new DropdownDefinition();
$category_definition_id = null;
if ($category_definition->getFromDBBySystemName('equipment_category')) {
    echo "Custom dropdown 'equipment_category' already exists (id {$category_definition->getID()}).\n";
    $category_definition_id = (int) $category_definition->getID();
} else {
    $new_id = $category_definition->add([
        'system_name' => 'equipment_category',
        'label'       => 'Equipment category',
        'icon'        => 'ti ti-tag',
        'is_active'   => 1,
    ]);
    if ($new_id === false) {
        echo "ERROR: could not create the 'equipment_category' dropdown definition.\n";
        exit(1);
    }
    echo "Created custom dropdown 'equipment_category' (id $new_id).\n";
    $category_definition_id = (int) $new_id;
    $category_definition->getFromDB($category_definition_id);
}

$categories = [
    'Imaging / Radiology',
    'Laboratory',
    'Intensive Care Unit (ICU)',
    'Neonatal ICU (NICU)',
    'Operating Room',
    'Emergency',
    'Cardiology',
    'Cardiac Catheterization Lab',
    'Dialysis',
    'Respiratory Care',
    'Anesthesiology',
    'Sterilization / CSSD',
    'Maternity',
    'Pediatrics',
    'Neurology',
    'Neurosurgery',
    'Gastroenterology',
    'Endoscopy',
    'Oncology',
    'Radiotherapy',
    'Nuclear Medicine',
    'Pathology',
    'Hematology',
    'Blood Bank',
    'Pharmacy',
    'Physiotherapy',
    'Dental',
    'ENT',
    'Ophthalmology',
    'Dermatology',
    'Urology',
    'Orthopedics',
    'General Surgery',
    'General Ward',
    'Outpatient Clinics',
    'Ambulance / EMS',
    'Morgue',
];

$category_classname = $category_definition->getDropdownClassName();
$category_obj = new $category_classname();
foreach ($categories as $category_name) {
    if ($category_obj->getFromDBByCrit(['name' => $category_name])) {
        echo "  category already exists: $category_name\n";
        continue;
    }
    if ($category_obj->add(['name' => $category_name]) !== false) {
        echo "  added category: $category_name\n";
    } else {
        echo "  ERROR adding category: $category_name\n";
    }
}

// Rights on the category dropdown (view for everyone central, manage for tech profiles)
$dropdown_full_rights = READ | UPDATE | CREATE | DELETE | PURGE; // == 1039
$dropdown_read_rights = READ;                                     // == 1
$grant_definition_rights($category_definition, $dropdown_full_rights, $dropdown_read_rights);

// ------------------------------------------------------------------
// 6. 'Equipment category' custom field on the medical equipment asset,
//    and the form layout (fields_display): drop the generic
//    Type / Model / Location fields (they are re-rendered by the CMMS
//    form template with cascading + Department label), and add the new
//    category field.
// ------------------------------------------------------------------
$custom_field = new CustomFieldDefinition();
$field_added = false;
if ($custom_field->getFromDBByCrit(['assets_assetdefinitions_id' => $definition_id, 'system_name' => 'equipment_category'])) {
    echo "Custom field 'equipment_category' already exists (id {$custom_field->getID()}).\n";
} else {
    $field_added = $custom_field->add([
        'assets_assetdefinitions_id' => $definition_id,
        'system_name'                => 'equipment_category',
        'label'                      => 'Equipment category',
        'type'                       => DropdownType::class,
        'itemtype'                   => $category_classname,
        'field_options'              => [],
    ]);
    if ($field_added !== false) {
        echo "Added custom field 'equipment_category' (id $field_added).\n";
    } else {
        echo "ERROR adding custom field 'equipment_category'.\n";
    }
}

$definition->getFromDB($definition_id);
$fields_display = $definition->getDecodedFieldsField();
$removed_fields = ['assets_assettypes_id', 'assets_assetmodels_id', 'locations_id'];
$new_display = [];
$order = 0;
foreach ($fields_display as $field) {
    if (in_array($field['key'], $removed_fields, true)) {
        continue;
    }
    $new_display[$order++] = $field['key'];
}
if (!in_array('custom_equipment_category', $new_display, true)) {
    $new_display[$order++] = 'custom_equipment_category';
}
$definition->update([
    'id'             => $definition_id,
    'fields_display' => $new_display,
]);
echo "Updated form layout (fields_display) for 'medical_equipment'.\n";

// ------------------------------------------------------------------
// 7. Hospital departments (GLPI Locations). The asset form's
//    "Department" field lists these locations.
// ------------------------------------------------------------------
$departments = [
    'Emergency Department (ED)',
    'Intensive Care Unit (ICU)',
    'Coronary Care Unit (CCU)',
    'Operating Rooms',
    'Radiology / Imaging',
    'Medical Laboratory',
    'Dialysis Unit',
    'Cardiology',
    'Pulmonology / Respiratory Care',
    'Neurology',
    'Neurosurgery',
    'Gastroenterology',
    'Endoscopy Unit',
    'Oncology',
    'Radiotherapy',
    'Nuclear Medicine',
    'Pathology',
    'Hematology',
    'Blood Bank',
    'Pharmacy',
    'Pediatrics (PICU)',
    'Neonatology (NICU)',
    'Maternity / Obstetrics',
    'Gynecology',
    'Orthopedics',
    'General Surgery',
    'Urology',
    'Nephrology',
    'Dermatology',
    'Ophthalmology',
    'ENT',
    'Dental Clinic',
    'Physiotherapy / Rehabilitation',
    'Psychiatry',
    'Outpatient Clinics',
    'Ambulance / EMS',
    'Sterilization (CSSD)',
    'Medical Stores',
    'Morgue',
    'Administration',
];

$location = new Location();
$hospital_id = null;
if (!$location->getFromDBByCrit(['name' => 'Hospital'])) {
    $hospital_id = $location->add(['name' => 'Hospital']);
    echo "Added top-level location 'Hospital' (id $hospital_id).\n";
} else {
    $hospital_id = (int) $location->getID();
    echo "Location 'Hospital' already exists (id $hospital_id).\n";
}
foreach ($departments as $department_name) {
    if ($location->getFromDBByCrit(['name' => $department_name, 'locations_id' => $hospital_id])) {
        echo "  department already exists: $department_name\n";
        continue;
    }
    if ($location->add(['name' => $department_name, 'locations_id' => $hospital_id]) !== false) {
        echo "  added department: $department_name\n";
    } else {
        echo "  ERROR adding department: $department_name\n";
    }
}

$DB->commit();

// ------------------------------------------------------------------
// 8. Assign departments to default users (for department scoping demo).
//    Admin gets no department (sees all), tech gets ICU, normal gets
//    Radiology.
// ------------------------------------------------------------------
echo "\nAssigning departments to users...\n";

// Find department IDs
$icu_dept_id = 0;
$radiology_dept_id = 0;
$lab_dept_id = 0;

if ($location->getFromDBByCrit(['name' => 'Intensive Care Unit (ICU)', 'locations_id' => $hospital_id])) {
    $icu_dept_id = (int) $location->getID();
}
if ($location->getFromDBByCrit(['name' => 'Radiology / Imaging', 'locations_id' => $hospital_id])) {
    $radiology_dept_id = (int) $location->getID();
}
if ($location->getFromDBByCrit(['name' => 'Medical Laboratory', 'locations_id' => $hospital_id])) {
    $lab_dept_id = (int) $location->getID();
}

$user = new User();

// Assign 'tech' user to ICU (id=2 in default install)
if ($user->getFromDBByCrit(['name' => 'tech'])) {
    if ($icu_dept_id > 0) {
        $user->update(['id' => (int) $user->getID(), 'locations_id' => $icu_dept_id]);
        echo "  assigned 'tech' user to ICU (locations_id=$icu_dept_id)\n";
    }
} else {
    echo "  user 'tech' not found\n";
}

// Assign 'normal' user to Radiology
if ($user->getFromDBByCrit(['name' => 'normal'])) {
    if ($radiology_dept_id > 0) {
        $user->update(['id' => (int) $user->getID(), 'locations_id' => $radiology_dept_id]);
        echo "  assigned 'normal' user to Radiology (locations_id=$radiology_dept_id)\n";
    }
} else {
    echo "  user 'normal' not found\n";
}

// 'glpi' admin keeps locations_id=0 (sees all departments)
echo "  'glpi' admin remains unassigned (sees all departments)\n";

echo "\nDone. Open the app and check: Assets > Medical equipment (add devices; pick a\n";
echo "manufacturer to see only its types/models), Setup > Asset definitions > Medical\n";
echo "equipment (manage types / models / fields), Setup > Dropdowns (Equipment category)\n";
echo "and Administration > Locations (departments).\n";
echo "\nDepartment scoping: Admin sees all, tech sees ICU, normal sees Radiology.\n";
