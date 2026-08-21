<?php
/**
 * ---------------------------------------------------------------------
 * MedEquip CMMS — Installer proxy
 *
 * GLPI's real install/install.php lives one level above public/.
 * When the document root is public/, LiteSpeed/Apache can't reach it
 * directly. This tiny proxy includes the real file.
 *
 * __DIR__ inside the real install.php resolves to the real install/
 * directory, so all relative paths inside it work correctly.
 * ---------------------------------------------------------------------
 */

$real = dirname(__DIR__, 2) . '/install/install.php';
if (file_exists($real)) {
    require $real;
} else {
    http_response_code(500);
    echo 'GLPI installer not found. Please re-upload the project files.';
}
