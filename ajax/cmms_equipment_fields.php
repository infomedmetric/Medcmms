<?php

/**
 * ---------------------------------------------------------------------
 *
 * MedEquip CMMS — cascading Type / Model dropdowns for the
 * "Medical equipment" asset form.
 *
 * Re-renders the device Type and Model dropdowns so that, given a
 * selected manufacturer (and optionally a device type), only the items
 * that manufacturer actually produces are offered:
 *  - Type dropdown  -> types that have at least one model from the
 *                      selected manufacturer
 *  - Model dropdown -> models of the selected manufacturer (and of the
 *                      selected type when one is picked)
 *
 * This endpoint is loaded into #cmms_type_model_fields by the asset
 * form (templates/pages/assets/asset.html.twig) whenever the
 * manufacturer or type selection changes. The response contains the
 * usual GLPI dropdown markup, including its own select2 init script,
 * so the dropdowns are re-initialized automatically.
 *
 * ---------------------------------------------------------------------
 */

use Glpi\Application\View\TemplateRenderer;
use Glpi\Asset\AssetDefinition;
use Glpi\DBAL\QuerySubQuery;

include('../inc/includes.php');

Session::checkLoginUser();

$definition_id    = (int) ($_REQUEST['assets_assetdefinitions_id'] ?? 0);
$manufacturers_id = (int) ($_REQUEST['manufacturers_id'] ?? 0);
$assettypes_id    = (int) ($_REQUEST['assets_assettypes_id'] ?? 0);
$assetmodels_id   = (int) ($_REQUEST['assets_assetmodels_id'] ?? 0);

$definition = AssetDefinition::getById($definition_id);
if ($definition === false || $definition->fields['system_name'] !== 'medical_equipment') {
    http_response_code(404);
    exit;
}

$type_class       = $definition->getAssetTypeClassName();
$model_class      = $definition->getAssetModelClassName();
$type_table       = $type_class::getTable();
$model_table      = $model_class::getTable();
$definition_field = AssetDefinition::getForeignKeyField(); // assets_assetdefinitions_id

// Type dropdown: only types that have at least one model from the selected manufacturer
$type_condition = [];
if ($manufacturers_id > 0) {
    $type_condition = [
        'id' => new QuerySubQuery([
            'SELECT'  => 'assets_assettypes_id',
            'FROM'    => $model_table,
            'WHERE'   => [
                $definition_field  => $definition_id,
                'manufacturers_id' => $manufacturers_id,
            ],
            'GROUPBY' => 'assets_assettypes_id',
        ]),
    ];
}

// Model dropdown: models of the selected manufacturer, narrowed by type when one is chosen
$model_condition = [
    $definition_field => $definition_id,
];
if ($manufacturers_id > 0) {
    $model_condition['manufacturers_id'] = $manufacturers_id;
}
if ($assettypes_id > 0) {
    $model_condition['assets_assettypes_id'] = $assettypes_id;
}

// Render the two labeled dropdowns through the fields macros so the reloaded
// zone keeps the same markup (label + field) as the initial server-side render.
$html = TemplateRenderer::getInstance()->renderFromStringTemplate(<<<'TWIG'
    {% import 'components/form/fields_macros.html.twig' as fields %}
    <div class="row">
        <div class="col-12">
            {{ fields.dropdownField(type_class, 'assets_assettypes_id', type_value, _n('Type', 'Types', 1), {
                'condition': type_condition,
            }) }}
        </div>
        <div class="col-12">
            {{ fields.dropdownField(model_class, 'assets_assetmodels_id', model_value, _n('Model', 'Models', 1), {
                'condition': model_condition,
            }) }}
        </div>
    </div>
TWIG, [
    'type_class'      => $type_class,
    'type_value'      => $assettypes_id,
    'model_class'     => $model_class,
    'model_value'     => $assetmodels_id,
    'type_condition'  => $type_condition,
    'model_condition' => $model_condition,
]);

echo $html;
