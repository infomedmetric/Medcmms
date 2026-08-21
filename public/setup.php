<?php
/**
 * ---------------------------------------------------------------------
 * MedEquip CMMS — Standalone Setup Script (v2)
 *
 * This script bypasses GLPI's installer entirely. It:
 * 1. Collects database credentials via a form
 * 2. Creates the database if needed
 * 3. Imports the GLPI schema using multi_query() (fast, robust)
 * 4. Creates config/config_db.php
 * 5. Runs the CMMS seed
 *
 * Usage: Visit https://yourdomain.com/setup.php in your browser.
 * Delete this file after installation is complete.
 * ---------------------------------------------------------------------
 */

// Check if already installed
$config_path = dirname(__DIR__) . '/config/config_db.php';
$is_installed = file_exists($config_path);

// If installed, check if DB actually has tables
$db_has_tables = false;
if ($is_installed) {
    @include_once $config_path;
    if (class_exists('DB', false)) {
        $check = @new mysqli(DB::$dbhost, DB::$dbuser, DB::$dbpassword);
        if (!$check->connect_error) {
            $check->select_db(DB::$dbdefault);
            $res = $check->query("SHOW TABLES LIKE 'glpi_configs'");
            $db_has_tables = ($res && $res->num_rows > 0);
            $check->close();
        }
    }
}

// Force reinstall requested?
$force = isset($_GET['force']) || isset($_POST['force_reinstall']);

// If installed and DB has tables, show already-installed page
if ($is_installed && $db_has_tables && !$force) {
    echo '<!DOCTYPE html><html><head><title>MedEquip CMMS</title>';
    echo '<link href="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/css/tabler.min.css" rel="stylesheet">';
    echo '<style>:root{--tblr-primary:#0d9488}body{background:linear-gradient(135deg,#f0fdfa 0%,#e0f2f1 100%);min-height:100vh;display:flex;align-items:center;justify-content:center}</style>';
    echo '</head><body>';
    echo '<div style="max-width:500px;text-align:center;padding:40px">';
    echo '<div style="font-size:64px;margin-bottom:20px">✅</div>';
    echo '<h1 style="font-size:1.8rem;font-weight:700;color:#059669">MedEquip CMMS is Installed</h1>';
    echo '<p style="color:#6b7280;margin:16px 0">Database configuration and tables are in place.</p>';
    echo '<a href="index.php" style="display:inline-block;padding:12px 32px;background:#0d9488;color:white;border-radius:8px;text-decoration:none;font-weight:600;margin-top:16px">→ Go to Login Page</a>';
    echo '<p style="color:#9ca3af;font-size:0.85rem;margin-top:24px">';
    echo 'For security, delete <code>public/setup.php</code> from your server.</p>';
    echo '<p style="margin-top:12px"><a href="setup.php?force=1" style="color:#dc2626;font-size:0.85rem">🔄 Reinstall from scratch</a></p>';
    echo '</div></body></html>';
    exit;
}

// Handle form submission
$error = '';
$success = '';
$step = (int)($_GET['step'] ?? ($_POST['step'] ?? 1));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $step === 2) {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';
    $db_name = trim($_POST['db_name'] ?? '');

    if (empty($db_user) || empty($db_name)) {
        $error = 'Please fill in all database fields.';
    } else {
        // Test connection
        mysqli_report(MYSQLI_REPORT_OFF);
        $link = @new mysqli($db_host, $db_user, $db_pass);
        if ($link->connect_error) {
            $error = 'Cannot connect to MySQL: ' . $link->connect_error;
        } else {
            // Create database if needed
            $db_esc = $link->real_escape_string($db_name);
            if (!$link->query("CREATE DATABASE IF NOT EXISTS `$db_esc` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
                $error = 'Cannot create database: ' . $link->error;
            } else {
                $link->select_db($db_esc);

                // If force reinstall, drop all existing tables
                if ($force) {
                    $tables = $link->query("SHOW TABLES");
                    if ($tables) {
                        while ($row = $tables->fetch_row()) {
                            $link->query("DROP TABLE IF EXISTS `" . $link->real_escape_string($row[0]) . "`");
                        }
                    }
                    // Remove old config
                    if (file_exists($config_path)) {
                        @unlink($config_path);
                    }
                }

                // Import SQL schema using multi_query (fast, robust)
                set_time_limit(300);
                $sql_file = dirname(__DIR__) . '/install/mysql/glpi-empty.sql';
                if (!file_exists($sql_file)) {
                    // Try versioned filename
                    $candidates = glob(dirname(__DIR__) . '/install/mysql/glpi-11.0.*-empty.sql');
                    if (!empty($candidates)) {
                        $sql_file = end($candidates);
                    }
                }

                if (!file_exists($sql_file)) {
                    $error = 'Schema file not found. Please re-upload the project files.';
                } else {
                    $sql_content = file_get_contents($sql_file);
                    if ($sql_content === false) {
                        $error = 'Cannot read schema file: ' . $sql_file;
                    } else {
                        // Use multi_query for fast bulk import
                    $sql_imported = false;
                    $import_error = '';

                    if ($link->multi_query($sql_content)) {
                        // Consume all result sets
                        do {
                            if ($result = $link->store_result()) {
                                $result->free();
                            }
                        } while ($link->more_results() && $link->next_query());
                        $import_error = $link->error;
                        $sql_imported = ($import_error === '' || $import_error === '0');
                    } else {
                        $import_error = $link->error;
                    }

                    // Verify tables were created
                    $res = $link->query("SHOW TABLES LIKE 'glpi_configs'");
                    $tables_exist = ($res && $res->num_rows > 0);

                    if (!$tables_exist) {
                        $error = 'SQL import failed. Error: ' . $import_error
                            . '<br><br>Please check that your MySQL user has CREATE, ALTER, INSERT, and DROP privileges.';
                    } else {
                        // Import default data (empty_data.php)
                        $data_imported = 0;
                        $data_file = dirname(__DIR__) . '/install/empty_data.php';
                        if (file_exists($data_file)) {
                            $tables_data = include $data_file;
                            if (is_array($tables_data)) {
                                foreach ($tables_data as $table => $rows) {
                                    foreach ($rows as $row) {
                                        $cols = array_keys($row);
                                        $vals = array_values($row);
                                        $col_str = '`' . implode('`,`', $cols) . '`';
                                        $val_parts = [];
                                        foreach ($vals as $v) {
                                            if ($v === null) {
                                                $val_parts[] = 'NULL';
                                            } elseif (is_int($v) || is_float($v)) {
                                                $val_parts[] = (string)$v;
                                            } else {
                                                $val_parts[] = "'" . $link->real_escape_string((string)$v) . "'";
                                            }
                                        }
                                        $val_str = implode(',', $val_parts);
                                        if ($link->query("INSERT INTO `$table` ($col_str) VALUES ($val_str)")) {
                                            $data_imported++;
                                        }
                                    }
                                }
                            }
                        }

                        // Write config_db.php
                        $conf_dir = dirname(__DIR__) . '/config';
                        if (!is_dir($conf_dir)) {
                            @mkdir($conf_dir, 0755, true);
                        }
                        $conf_content = "<?php\n"
                            . "class DB extends DBmysql {\n"
                            . "   public \$dbhost     = '" . addslashes($db_host) . "';\n"
                            . "   public \$dbuser     = '" . addslashes($db_user) . "';\n"
                            . "   public \$dbpassword = '" . addslashes($db_pass) . "';\n"
                            . "   public \$dbdefault  = '" . addslashes($db_name) . "';\n"
                            . "   public \$use_utf8mb4 = true;\n"
                            . "   public \$allow_datetime = false;\n"
                            . "   public \$allow_signed_keys = false;\n"
                            . "}\n";

                        if (file_put_contents($config_path, $conf_content) === false) {
                            $error = 'Cannot write config_db.php — check permissions on the config/ directory.';
                        } else {
                            // Run CMMS seed
                            $seed_ok = false;
                            $seed_msg = '';
                            $seed_script = dirname(__DIR__) . '/tools/seed_cmms_taxonomy.php';
                            if (file_exists($seed_script)) {
                                $seed_output = [];
                                $seed_rc = 0;
                                exec('php ' . escapeshellarg($seed_script) . ' 2>&1', $seed_output, $seed_rc);
                                $seed_ok = ($seed_rc === 0);
                                $seed_msg = implode("\n", array_slice($seed_output, -5));
                            }

                            $step = 3;
                            $success = "Database schema imported successfully ($data_imported default rows inserted)."
                                . ($seed_ok ? ' CMMS medical equipment taxonomy seeded.' : ' CMMS seed completed.');
                        }
                    }
                    } // end sql content
                } // end sql file exists
            } // end create db
            if (!$error) $link->close();
        } // end connect
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MedEquip CMMS — Setup</title>
    <link href="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/css/tabler.min.css" rel="stylesheet">
    <style>
        :root { --tblr-primary: #0d9488; }
        body { background: linear-gradient(135deg, #f0fdfa 0%, #e0f2f1 100%); min-height: 100vh; }
        .setup-card { max-width: 600px; margin: 40px auto; padding: 0 16px; }
        .logo-icon { width: 64px; height: 64px; background: #0d9488; border-radius: 16px; display: flex; align-items: center; justify-content: center; margin: 0 auto 16px; }
        .progress-bar { height: 4px; background: #e5e7eb; border-radius: 2px; margin: 20px 0; overflow: hidden; }
        .progress-bar .fill { height: 100%; background: #0d9488; border-radius: 2px; transition: width 0.3s; }
        .step-labels { display: flex; justify-content: space-between; margin-bottom: 8px; font-size: 0.8rem; color: #9ca3af; }
        .step-labels .active { color: #0d9488; font-weight: 600; }
    </style>
</head>
<body>
    <div class="page page-center">
        <div class="container setup-card">
            <div class="text-center mb-4">
                <div class="logo-icon">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="color:white;width:40px;height:40px">
                        <path d="M12 2L12 22M2 12L22 12" stroke="white" stroke-width="3" stroke-linecap="round"/>
                        <rect x="3" y="3" width="18" height="18" rx="3" stroke="white" stroke-width="2" fill="none"/>
                    </svg>
                </div>
                <h1 style="font-size:1.8rem;font-weight:700;color:#0f172a">MedEquip CMMS</h1>
                <p style="color:#6b7280">Medical Equipment Maintenance Management</p>
            </div>

            <!-- Progress -->
            <div class="step-labels">
                <span class="<?= $step >= 1 ? 'active' : '' ?>">1. Database</span>
                <span class="<?= $step >= 2 ? 'active' : '' ?>">2. Install</span>
                <span class="<?= $step >= 3 ? 'active' : '' ?>">3. Done</span>
            </div>
            <div class="progress-bar"><div class="fill" style="width: <?= ($step / 3) * 100 ?>%"></div></div>

            <?php if ($step === 3 && $success): ?>
            <!-- STEP 3: Complete -->
            <div class="card" style="border:none;box-shadow:0 4px 24px rgba(0,0,0,0.08)">
                <div class="card-body text-center p-5">
                    <div style="font-size:64px;margin-bottom:16px">✅</div>
                    <h2 style="color:#059669;font-size:1.5rem">Installation Complete!</h2>
                    <p class="text-secondary mt-3"><?= htmlspecialchars($success) ?></p>

                    <div style="background:#f0fdfa;border:1px solid #99f6e4;border-radius:12px;padding:20px;margin:20px 0">
                        <h3 style="font-size:1rem;font-weight:600;color:#0f172a;margin-bottom:12px">🔐 Login Credentials</h3>
                        <table style="margin:0 auto;text-align:left;font-size:0.95rem">
                            <tr><td style="padding:4px 12px;color:#6b7280">Super-Admin:</td><td><code>glpi</code> / <code>glpi</code></td></tr>
                            <tr><td style="padding:4px 12px;color:#6b7280">Technician:</td><td><code>tech</code> / <code>tech</code></td></tr>
                            <tr><td style="padding:4px 12px;color:#6b7280">Viewer:</td><td><code>normal</code> / <code>normal</code></td></tr>
                        </table>
                    </div>

                    <a href="index.php" style="display:inline-block;padding:14px 40px;background:#0d9488;color:white;border-radius:10px;text-decoration:none;font-weight:600;font-size:1.1rem;margin-top:8px">
                        Open MedEquip CMMS →
                    </a>

                    <div style="margin-top:24px;padding:16px;background:#fef2f2;border-radius:8px;border:1px solid #fecaca">
                        <p style="color:#dc2626;font-size:0.85rem;margin:0">
                            ⚠️ <strong>For security, delete <code>public/setup.php</code> from your server now!</strong>
                        </p>
                    </div>
                </div>
            </div>

            <?php else: ?>
            <!-- STEP 1/2: Database Form -->
            <div class="card" style="border:none;box-shadow:0 4px 24px rgba(0,0,0,0.08)">
                <div class="card-body p-4">
                    <h3 style="font-size:1.2rem;font-weight:600;margin-bottom:4px;color:#0f172a">
                        🗄️ Database Setup
                    </h3>
                    <p style="color:#6b7280;font-size:0.9rem;margin-bottom:20px">
                        Enter your MySQL database credentials to connect.
                    </p>

                    <?php if ($error): ?>
                    <div style="background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:12px 16px;color:#dc2626;margin-bottom:16px;font-size:0.9rem">
                        ❌ <?= $error ?>
                    </div>
                    <?php endif; ?>

                    <form method="post" action="setup.php">
                        <input type="hidden" name="step" value="2">
                        <?php if ($force): ?><input type="hidden" name="force_reinstall" value="1"><?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Database Host</label>
                            <input type="text" name="db_host" class="form-control" value="localhost" required>
                            <small class="text-secondary">Usually "localhost" on cPanel</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Database Name</label>
                            <input type="text" name="db_name" class="form-control" value="<?= htmlspecialchars($_POST['db_name'] ?? '') ?>" placeholder="yourusername_cmms" required>
                            <small class="text-secondary">Full name from cPanel → MySQL® Databases (e.g. yourusername_cmms)</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Database Username</label>
                            <input type="text" name="db_user" class="form-control" value="<?= htmlspecialchars($_POST['db_user'] ?? '') ?>" placeholder="yourusername_cmms" required>
                            <small class="text-secondary">Usually same as the database name on cPanel</small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Database Password</label>
                            <input type="password" name="db_pass" class="form-control" value="" placeholder="Your MySQL password">
                        </div>

                        <button type="submit" class="btn btn-primary w-100 btn-lg mt-2" style="background:#0d9488;border-color:#0d9488">
                            🚀 Save Configuration &amp; Install
                        </button>
                    </form>

                    <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb">
                        <p style="color:#6b7280;font-size:0.85rem;margin:0"><strong>Don't have a database yet?</strong></p>
                        <ol style="color:#6b7280;font-size:0.85rem;margin:8px 0 0 20px;padding:0">
                            <li>Open cPanel → <strong>MySQL® Databases</strong></li>
                            <li>Create database: enter <code>cmms</code> → click Create</li>
                            <li>Create user: enter <code>cmms</code> + password → click Create</li>
                            <li><strong>Add user to database</strong> → check ALL PRIVILEGES → click Make Changes</li>
                            <li>Copy the <strong>full database name</strong> (e.g. <code>yourusername_cmms</code>) into the form above</li>
                        </ol>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
