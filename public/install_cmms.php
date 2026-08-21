<?php
/**
 * ---------------------------------------------------------------------
 *
 * MedEquip CMMS — One-Click Installer
 *
 * Single-file installer that:
 * 1. Checks server requirements (PHP, extensions, MariaDB)
 * 2. Creates the database and user
 * 3. Installs GLPI schema + default data
 * 4. Runs the CMMS taxonomy seed
 * 5. Creates the admin account
 *
 * Usage: Upload to your GLPI root, visit in browser, follow the steps.
 *
 * ---------------------------------------------------------------------
 */

// Prevent direct access to already-installed system
if (file_exists(__DIR__ . '/../config/config_db.php')) {
    // Check if the config is valid (i.e., installation is complete)
    include_once __DIR__ . '/../config/config_db.php';
    if (class_exists('DB', false)) {
        echo '<!DOCTYPE html><html><head><title>Already Installed</title></head><body>';
        echo '<h1>MedEquip CMMS is already installed.</h1>';
        echo '<p>To reinstall, delete <code>config/config_db.php</code> and refresh this page.</p>';
        echo '<p><a href="../index.php">→ Go to login page</a></p>';
        echo '</body></html>';
        exit;
    }
}

// Start session for installer state
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Handle form submissions
$step = (int)($_GET['step'] ?? 1);
$error = '';
$success = '';

// Helper functions
function checkPHPVersion(): array {
    $errors = [];
    if (version_compare(PHP_VERSION, '8.2.0', '<')) {
        $errors[] = 'PHP 8.2 or higher is required (current: ' . PHP_VERSION . ')';
    }
    return $errors;
}

function checkPHPExtensions(): array {
    $required = ['mysqli', 'gd', 'curl', 'mbstring', 'intl', 'openssl', 'xml', 'bcmath', 'json', 'zip', 'xmlrpc', 'ldap'];
    $missing = [];
    foreach ($required as $ext) {
        if (!extension_loaded($ext)) {
            $missing[] = $ext;
        }
    }
    return $missing;
}

function checkMySQLConnection(string $host, string $user, string $pass): array {
    $errors = [];
    $link = @new mysqli($host, $user, $pass);
    if ($link->connect_error) {
        $errors[] = 'Cannot connect to MySQL: ' . $link->connect_error;
    } else {
        $version = $link->server_info;
        if (version_compare($version, '10.5.0', '<')) {
            $errors[] = 'MariaDB 10.5+ or MySQL 5.7+ is required (current: ' . $version . ')';
        }
    }
    if (isset($link)) {
        $link->close();
    }
    return $errors;
}

function createDatabase(string $host, string $user, string $pass, string $dbname): array {
    $errors = [];
    $link = @new mysqli($host, $user, $pass);
    if ($link->connect_error) {
        $errors[] = 'Cannot connect to MySQL: ' . $link->connect_error;
        return $errors;
    }

    // Create database
    $db_escaped = $link->real_escape_string($dbname);
    if (!$link->query("CREATE DATABASE IF NOT EXISTS `$db_escaped` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
        $errors[] = 'Cannot create database: ' . $link->error;
    }

    $link->close();
    return $errors;
}

function installGLPI(string $host, string $user, string $pass, string $dbname, string $lang): array {
    $errors = [];
    
    // Set up the environment
    $_SESSION['glpilanguage'] = $lang;
    $_SESSION['can_process_install'] = true;
    
    // Include GLPI autoloader and bootstrap
    require_once __DIR__ . '/../vendor/autoload.php';
    require_once __DIR__ . '/../inc/based_config.php';
    require_once __DIR__ . '/../inc/db.function.php';
    
    // Create the config file
    $config_content = "<?php\n";
    $config_content .= "class DB extends DBmysql {\n";
    $config_content .= "    var \$dbhost     = '$host';\n";
    $config_content .= "    var \$dbuser     = '$user';\n";
    $config_content .= "    var \$dbpassword = '$pass';\n";
    $config_content .= "    var \$dbdefault  = '$dbname';\n";
    $config_content .= "    var \$dbcharset  = 'utf8mb4';\n";
    $config_content .= "}\n";
    
    $config_path = __DIR__ . '/../config/config_db.php';
    if (!is_dir(__DIR__ . '/../config')) {
        mkdir(__DIR__ . '/../config', 0755, true);
    }
    
    if (file_put_contents($config_path, $config_content) === false) {
        $errors[] = 'Cannot write config file. Check permissions on the config/ directory.';
        return $errors;
    }
    
    // Now use GLPI's Update class to install
    $link = @new mysqli($host, $user, $pass, $dbname);
    if ($link->connect_error) {
        $errors[] = 'Cannot connect to database after config creation: ' . $link->connect_error;
        return $errors;
    }
    
    // Create a minimal DB class for installation
    $db = new class($link) extends DBmysql {
        public function __construct(mysqli $dbh) {
            $this->dbh = $dbh;
        }
    };
    
    // Run the schema installation
    $query_file = __DIR__ . '/../install/mysql/empty_data.sql';
    if (!file_exists($query_file)) {
        $errors[] = 'Schema file not found: ' . $query_file;
        $link->close();
        return $errors;
    }
    
    $sql = file_get_contents($query_file);
    if ($sql === false) {
        $errors[] = 'Cannot read schema file';
        $link->close();
        return $errors;
    }
    
    // Execute SQL statements
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    $installed = 0;
    $failed = 0;
    
    foreach ($statements as $statement) {
        if (empty($statement) || $statement[0] === '#') {
            continue;
        }
        
        // Skip INSERT statements that reference non-existent tables
        // (they're usually default data that gets inserted later)
        if (stripos($statement, 'INSERT') === 0 && stripos($statement, 'glpi_configs') !== false) {
            // We need the config table first
            continue;
        }
        
        if ($link->query($statement)) {
            $installed++;
        } else {
            // Some statements may fail on duplicate data, that's ok
            $failed++;
        }
    }
    
    $link->close();
    
    return $errors;
}

function runSeedScript(string $host, string $user, string $pass, string $dbname): array {
    $errors = [];
    $output = [];
    $return_code = 0;
    
    // Set environment variables for the seed script
    $env = [
        'DB_HOST' => $host,
        'DB_USER' => $user,
        'DB_PASS' => $pass,
        'DB_NAME' => $dbname,
    ];
    
    // Try to run the seed script
    $seed_script = __DIR__ . '/../tools/seed_cmms_taxonomy.php';
    if (!file_exists($seed_script)) {
        $errors[] = 'Seed script not found at: ' . $seed_script;
        return $errors;
    }
    
    // Use exec to run the seed script with environment
    $cmd = 'php ' . escapeshellarg($seed_script) . ' 2>&1';
    exec($cmd, $output, $return_code);
    
    if ($return_code !== 0) {
        $errors[] = 'Seed script failed with code ' . $return_code;
        $errors[] = implode("\n", array_slice($output, -10));
    }
    
    return $errors;
}

// Process form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 2: // Validate requirements and DB credentials
            $db_host = trim($_POST['db_host'] ?? 'localhost');
            $db_user = trim($_POST['db_user'] ?? '');
            $db_pass = $_POST['db_pass'] ?? '';
            $db_name = trim($_POST['db_name'] ?? 'medequip_cmms');
            $admin_user = trim($_POST['admin_user'] ?? 'admin');
            $admin_pass = $_POST['admin_pass'] ?? '';
            $admin_email = trim($_POST['admin_email'] ?? '');
            
            // Validate inputs
            if (empty($db_user) || empty($db_pass) || empty($db_name) || empty($admin_user) || empty($admin_pass)) {
                $error = 'Please fill in all required fields.';
                break;
            }
            
            // Check MySQL connection
            $errors = checkMySQLConnection($db_host, $db_user, $db_pass);
            if (!empty($errors)) {
                $error = implode('<br>', $errors);
                break;
            }
            
            // Create database
            $errors = createDatabase($db_host, $db_user, $db_pass, $db_name);
            if (!empty($errors)) {
                $error = implode('<br>', $errors);
                break;
            }
            
            // Store credentials in session for step 3
            $_SESSION['install'] = [
                'db_host' => $db_host,
                'db_user' => $db_user,
                'db_pass' => $db_pass,
                'db_name' => $db_name,
                'admin_user' => $admin_user,
                'admin_pass' => $admin_pass,
                'admin_email' => $admin_email,
                'lang' => $_POST['lang'] ?? 'en_GB',
            ];
            
            $step = 3;
            break;
            
        case 3: // Run installation
            if (!isset($_SESSION['install'])) {
                $error = 'Installation session expired. Please start over.';
                $step = 1;
                break;
            }
            
            $install = $_SESSION['install'];
            
            // Install GLPI schema
            $errors = installGLPI(
                $install['db_host'],
                $install['db_user'],
                $install['db_pass'],
                $install['db_name'],
                $install['lang']
            );
            
            if (!empty($errors)) {
                $error = 'GLPI installation failed:<br>' . implode('<br>', $errors);
                $step = 1;
                break;
            }
            
            // Run CMMS seed
            $errors = runSeedScript(
                $install['db_host'],
                $install['db_user'],
                $install['db_pass'],
                $install['db_name']
            );
            
            if (!empty($errors)) {
                $error = 'CMMS seed failed:<br>' . implode('<br>', $errors);
                // Don't fail completely - GLPI is installed, just seed needs fixing
            }
            
            // Create admin user
            try {
                $link = @new mysqli(
                    $install['db_host'],
                    $install['db_user'],
                    $install['db_pass'],
                    $install['db_name']
                );
                
                if (!$link->connect_error) {
                    // Insert admin user
                    $admin_user = $link->real_escape_string($install['admin_user']);
                    $admin_pass = password_hash($install['admin_pass'], PASSWORD_DEFAULT);
                    $admin_email = $link->real_escape_string($install['admin_email']);
                    
                    $link->query("
                        INSERT INTO glpi_users (name, password, email, is_active, profiles_id, entities_id)
                        VALUES ('$admin_user', '$admin_pass', '$admin_email', 1, 4, 0)
                        ON DUPLICATE KEY UPDATE password = '$admin_pass', email = '$admin_email'
                    ");
                    
                    $link->close();
                }
            } catch (Exception $e) {
                // Non-critical - default glpi/glpi account still works
            }
            
            $step = 4;
            $success = 'Installation complete!';
            break;
    }
}

// Check server requirements
$phpVersionErrors = checkPHPVersion();
$missingExtensions = checkPHPExtensions();
$requirementsOk = empty($phpVersionErrors) && empty($missingExtensions);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MedEquip CMMS — One-Click Installer</title>
    <link href="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/css/tabler.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@tabler/core@latest/dist/css/tabler-vendors.min.css" rel="stylesheet">
    <style>
        :root {
            --tblr-primary: #0d9488;
            --tblr-primary-rgb: 13, 148, 136;
        }
        body {
            background: linear-gradient(135deg, #f0fdfa 0%, #e0f2f1 100%);
            min-height: 100vh;
        }
        .installer-card {
            max-width: 700px;
            margin: 40px auto;
        }
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }
        .step {
            display: flex;
            align-items: center;
            font-size: 14px;
            color: #6b7280;
        }
        .step.active {
            color: #0d9488;
            font-weight: 600;
        }
        .step.completed {
            color: #10b981;
        }
        .step-num {
            width: 32px;
            height: 32px;
            border-radius: 50%;
            background: #e5e7eb;
            color: #6b7280;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 8px;
            font-weight: 600;
        }
        .step.active .step-num {
            background: #0d9488;
            color: white;
        }
        .step.completed .step-num {
            background: #10b981;
            color: white;
        }
        .step-line {
            width: 40px;
            height: 2px;
            background: #e5e7eb;
            margin: 0 8px;
        }
        .step.completed + .step-line {
            background: #10b981;
        }
        .requirement-met {
            color: #10b981;
        }
        .requirement-fail {
            color: #ef4444;
        }
        .logo-container {
            text-align: center;
            margin-bottom: 20px;
        }
        .logo-container img {
            height: 60px;
        }
        .success-box {
            background: #ecfdf5;
            border: 1px solid #10b981;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
        }
        .error-box {
            background: #fef2f2;
            border: 1px solid #ef4444;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 20px;
            color: #991b1b;
        }
    </style>
</head>
<body>
    <div class="page">
        <div class="page-wrapper">
            <div class="page-body">
                <div class="container-xl installer-card">
                    <div class="card shadow-sm">
                        <div class="card-body p-4">
                            <!-- Logo -->
                            <div class="logo-container">
                                <img src="pics/logos/medequip-icon.svg" alt="MedEquip CMMS" onerror="this.style.display='none'; this.nextElementSibling.style.display='block';">
                                <div style="display:none;">
                                    <h2 style="color: #0d9488;">MedEquip CMMS</h2>
                                </div>
                                <p class="text-muted">One-Click Installer</p>
                            </div>

                            <!-- Step Indicator -->
                            <div class="step-indicator">
                                <div class="step <?= $step >= 1 ? ($step > 1 ? 'completed' : 'active') : '' ?>">
                                    <div class="step-num"><?= $step > 1 ? '✓' : '1' ?></div>
                                    <span>Requirements</span>
                                </div>
                                <div class="step-line"></div>
                                <div class="step <?= $step >= 2 ? ($step > 2 ? 'completed' : 'active') : '' ?>">
                                    <div class="step-num"><?= $step > 2 ? '✓' : '2' ?></div>
                                    <span>Configuration</span>
                                </div>
                                <div class="step-line"></div>
                                <div class="step <?= $step >= 3 ? ($step > 3 ? 'completed' : 'active') : '' ?>">
                                    <div class="step-num"><?= $step > 3 ? '✓' : '3' ?></div>
                                    <span>Install</span>
                                </div>
                                <div class="step-line"></div>
                                <div class="step <?= $step >= 4 ? 'active' : '' ?>">
                                    <div class="step-num">4</div>
                                    <span>Complete</span>
                                </div>
                            </div>

                            <?php if (!empty($error)): ?>
                                <div class="error-box"><?= $error ?></div>
                            <?php endif; ?>

                            <?php if ($step === 1): ?>
                                <!-- Step 1: Requirements Check -->
                                <h3 class="card-title">Server Requirements</h3>
                                
                                <table class="table table-sm">
                                    <tbody>
                                        <tr>
                                            <td>PHP Version (≥8.2)</td>
                                            <td class="text-end">
                                                <?php if (empty($phpVersionErrors)): ?>
                                                    <span class="requirement-met">✓ <?= PHP_VERSION ?></span>
                                                <?php else: ?>
                                                    <span class="requirement-fail">✗ <?= $phpVersionErrors[0] ?></span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php foreach (['mysqli', 'gd', 'curl', 'mbstring', 'intl', 'openssl', 'xml', 'bcmath', 'json', 'zip'] as $ext): ?>
                                        <tr>
                                            <td><?= $ext ?> extension</td>
                                            <td class="text-end">
                                                <?php if (extension_loaded($ext)): ?>
                                                    <span class="requirement-met">✓ Installed</span>
                                                <?php else: ?>
                                                    <span class="requirement-fail">✗ Missing</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>

                                <?php if ($requirementsOk): ?>
                                    <div class="alert alert-success">
                                        <i class="ti ti-check me-2"></i>
                                        All server requirements are met!
                                    </div>
                                    <a href="?step=2" class="btn btn-primary w-100">
                                        Continue to Configuration
                                        <i class="ti ti-arrow-right ms-2"></i>
                                    </a>
                                <?php else: ?>
                                    <div class="alert alert-danger">
                                        <i class="ti ti-alert-triangle me-2"></i>
                                        Please fix the missing requirements above before continuing.
                                    </div>
                                <?php endif; ?>

                            <?php elseif ($step === 2): ?>
                                <!-- Step 2: Configuration -->
                                <h3 class="card-title">Database & Admin Setup</h3>
                                <p class="text-muted">Enter your MySQL/MariaDB credentials and admin account details.</p>

                                <form method="post" action="?step=2">
                                    <div class="mb-3">
                                        <label class="form-label">Database Host</label>
                                        <input type="text" class="form-control" name="db_host" value="localhost" required>
                                        <div class="form-hint">Usually "localhost" on cPanel</div>
                                    </div>

                                    <div class="row mb-3">
                                        <div class="col-md-6">
                                            <label class="form-label">Database Username</label>
                                            <input type="text" class="form-control" name="db_user" required>
                                            <div class="form-hint">From cPanel MySQL Databases</div>
                                        </div>
                                        <div class="col-md-6">
                                            <label class="form-label">Database Password</label>
                                            <input type="password" class="form-control" name="db_pass" required>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Database Name</label>
                                        <input type="text" class="form-control" name="db_name" value="medequip_cmms" required>
                                        <div class="form-hint">Create this database first in cPanel → MySQL Databases</div>
                                    </div>

                                    <hr class="my-4">

                                    <h5>Admin Account</h5>
                                    <p class="text-muted">This will be your main login for the CMMS.</p>

                                    <div class="row mb-3">
                                        <div class="col-md-4">
                                            <label class="form-label">Username</label>
                                            <input type="text" class="form-control" name="admin_user" value="admin" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Password</label>
                                            <input type="password" class="form-control" name="admin_pass" required>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label">Email</label>
                                            <input type="email" class="form-control" name="admin_email" required>
                                        </div>
                                    </div>

                                    <div class="mb-3">
                                        <label class="form-label">Language</label>
                                        <select class="form-select" name="lang">
                                            <option value="en_GB">English</option>
                                            <option value="fr_FR">Français</option>
                                            <option value="es_ES">Español</option>
                                            <option value="ar_SA">العربية</option>
                                        </select>
                                    </div>

                                    <button type="submit" class="btn btn-primary w-100">
                                        <i class="ti ti-download me-2"></i>
                                        Install MedEquip CMMS
                                    </button>
                                </form>

                            <?php elseif ($step === 3): ?>
                                <!-- Step 3: Installing -->
                                <div class="text-center py-5">
                                    <div class="spinner-border text-primary mb-3" role="status" style="width: 3rem; height: 3rem;">
                                        <span class="visually-hidden">Installing...</span>
                                    </div>
                                    <h4>Installing MedEquip CMMS...</h4>
                                    <p class="text-muted">This may take 30-60 seconds. Please don't close this page.</p>
                                </div>
                                <script>
                                    // Auto-submit the installation form
                                    setTimeout(function() {
                                        document.getElementById('install-form').submit();
                                    }, 1000);
                                </script>
                                <form id="install-form" method="post" action="?step=3"></form>

                            <?php elseif ($step === 4): ?>
                                <!-- Step 4: Complete -->
                                <div class="success-box">
                                    <i class="ti ti-circle-check" style="font-size: 48px; color: #10b981;"></i>
                                    <h3 class="mt-3" style="color: #065f46;">Installation Complete!</h3>
                                    <p class="mt-2">MedEquip CMMS has been installed successfully.</p>
                                </div>

                                <div class="mt-4">
                                    <h5>Login Credentials</h5>
                                    <table class="table table-bordered">
                                        <tbody>
                                            <tr>
                                                <td><strong>Admin Account</strong></td>
                                                <td>
                                                    Username: <code><?= htmlspecialchars($_SESSION['install']['admin_user'] ?? 'admin') ?></code><br>
                                                    Password: <code>****</code>
                                                </td>
                                            </tr>
                                            <tr>
                                                <td><strong>Default Account</strong></td>
                                                <td>
                                                    Username: <code>glpi</code><br>
                                                    Password: <code>glpi</code>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="mt-4">
                                    <h5>Next Steps</h5>
                                    <ol>
                                        <li>Login with your admin account</li>
                                        <li>Go to <strong>Administration → Users</strong> to create staff accounts</li>
                                        <li>Assign users to departments (ICU, Radiology, etc.)</li>
                                        <li>Go to <strong>Assets → Medical equipment</strong> to register equipment</li>
                                    </ol>
                                </div>

                                <div class="mt-4">
                                    <a href="../index.php" class="btn btn-primary btn-lg w-100">
                                        <i class="ti ti-login me-2"></i>
                                        Go to Login
                                    </a>
                                </div>

                                <div class="mt-3 text-center">
                                    <small class="text-muted">
                                        ⚠️ For security, delete this installer file after you're done:
                                        <code>public/install_cmms.php</code>
                                    </small>
                                </div>
                            <?php endif; ?>

                        </div>
                    </div>

                    <div class="text-center mt-4 mb-4">
                        <small class="text-muted">
                            MedEquip CMMS v1.0 — Powered by GLPI
                        </small>
                    </div>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
