<?php
/**
 * MedEquip CMMS — Standalone One-Click Installer
 *
 * This file works on ANY cPanel/LiteSpeed server without GLPI routing.
 * It directly creates the database config, then redirects to GLPI's real installer.
 *
 * Upload this to: public_html/cmms/public/
 * Visit: https://yourdomain.com/install_cmms_standalone.php
 */

$project_root = dirname(__DIR__); // goes from public/ to project root
$config_file  = $project_root . '/config/config_db.php';

// If already installed, show login link
if (file_exists($config_file)) {
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>MedEquip CMMS — Already Installed</title>
        <style>
            body { font-family: system-ui; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f0fdfa; }
            .card { background: white; padding: 40px; border-radius: 16px; box-shadow: 0 4px 20px rgba(0,0,0,0.1); text-align: center; max-width: 500px; }
            a { display: inline-block; margin-top: 20px; padding: 12px 24px; background: #0d9488; color: white; text-decoration: none; border-radius: 8px; }
        </style>
    </head>
    <body>
        <div class="card">
            <h1>✅ MedEquip CMMS is Installed</h1>
            <p>Database configuration already exists.</p>
            <a href="./index.php">→ Go to Login Page</a>
        </div>
    </body>
    </html>
    <?php
    exit;
}

// Handle form submission
$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db_host = trim($_POST['db_host'] ?? 'localhost');
    $db_user = trim($_POST['db_user'] ?? '');
    $db_pass = $_POST['db_pass'] ?? '';
    $db_name = trim($_POST['db_name'] ?? '');

    if (empty($db_user) || empty($db_name)) {
        $error = 'Please fill in database name and username.';
    } else {
        // Test connection
        $link = @new mysqli($db_host, $db_user, $db_pass, $db_name);
        if ($link->connect_error) {
            $error = 'Cannot connect to database: ' . $link->connect_error . '<br>Make sure the database exists in cPanel → MySQL® Databases.';
        } else {
            // Write config_db.php
            $config_content = '<?php' . "\n";
            $config_content .= 'class DB extends DBmysql {' . "\n";
            $config_content .= '   public $dbhost     = ' . var_export($db_host, true) . ';' . "\n";
            $config_content .= '   public $dbuser     = ' . var_export($db_user, true) . ';' . "\n";
            $config_content .= '   public $dbpassword = ' . var_export($db_pass, true) . ';' . "\n";
            $config_content .= '   public $dbdefault  = ' . var_export($db_name, true) . ';' . "\n";
            $config_content .= '   public $use_utf8mb4 = true;' . "\n";
            $config_content .= '}' . "\n";

            // Ensure config directory exists
            $config_dir = $project_root . '/config';
            if (!is_dir($config_dir)) {
                mkdir($config_dir, 0755, true);
            }

            if (file_put_contents($config_file, $config_content) !== false) {
                // Config written successfully — now we need to run the GLPI installer to create tables
                // Redirect to GLPI's native installer which will detect the config and set up the DB schema
                header('Location: ./index.php');
                exit;
            } else {
                $error = 'Cannot write config file. Check that the config/ folder is writable (chmod 755 or 777 in cPanel File Manager).';
            }
        }
        $link->close();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MedEquip CMMS — Setup</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: linear-gradient(135deg, #f0fdfa 0%, #e0f2f1 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: center;
            padding: 20px;
        }
        .card {
            background: white;
            padding: 40px;
            border-radius: 16px;
            box-shadow: 0 4px 24px rgba(0,0,0,0.1);
            max-width: 550px;
            width: 100%;
        }
        .logo { text-align: center; margin-bottom: 30px; }
        .logo h1 { color: #0d9488; font-size: 28px; }
        .logo p { color: #6b7280; margin-top: 4px; }
        h2 { color: #1f2937; margin-bottom: 8px; font-size: 20px; }
        .subtitle { color: #6b7280; margin-bottom: 24px; font-size: 14px; }
        .field { margin-bottom: 16px; }
        label { display: block; font-weight: 600; color: #374151; margin-bottom: 6px; font-size: 14px; }
        input[type="text"], input[type="password"] {
            width: 100%; padding: 10px 14px; border: 1px solid #d1d5db;
            border-radius: 8px; font-size: 14px; transition: border 0.2s;
        }
        input:focus { outline: none; border-color: #0d9488; box-shadow: 0 0 0 3px rgba(13,148,136,0.1); }
        .hint { font-size: 12px; color: #9ca3af; margin-top: 4px; }
        button {
            width: 100%; padding: 12px; background: #0d9488; color: white;
            border: none; border-radius: 8px; font-size: 16px; font-weight: 600;
            cursor: pointer; margin-top: 8px; transition: background 0.2s;
        }
        button:hover { background: #0f766e; }
        .error {
            background: #fef2f2; border: 1px solid #fecaca; color: #dc2626;
            padding: 12px; border-radius: 8px; margin-bottom: 16px; font-size: 14px;
        }
        .steps { margin-top: 30px; padding-top: 20px; border-top: 1px solid #e5e7eb; }
        .steps h3 { color: #374151; font-size: 14px; margin-bottom: 10px; }
        .steps ol { font-size: 13px; color: #6b7280; padding-left: 20px; }
        .steps li { margin-bottom: 6px; }
        .steps code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 12px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <h1>⚕️ MedEquip CMMS</h1>
            <p>Medical Equipment Maintenance Management</p>
        </div>

        <h2>Database Setup</h2>
        <p class="subtitle">Enter your MySQL database credentials to connect.</p>

        <?php if ($error): ?>
            <div class="error"><?php echo $error; ?></div>
        <?php endif; ?>

        <form method="POST">
            <div class="field">
                <label>Database Host</label>
                <input type="text" name="db_host" value="<?php echo htmlspecialchars($_POST['db_host'] ?? 'localhost'); ?>">
                <div class="hint">Usually "localhost" on cPanel</div>
            </div>
            <div class="field">
                <label>Database Name</label>
                <input type="text" name="db_name" value="<?php echo htmlspecialchars($_POST['db_name'] ?? ''); ?>" required placeholder="e.g. netric_cmms">
                <div class="hint">Full name from cPanel → MySQL® Databases (e.g. yourusername_cmms)</div>
            </div>
            <div class="field">
                <label>Database Username</label>
                <input type="text" name="db_user" value="<?php echo htmlspecialchars($_POST['db_user'] ?? ''); ?>" required placeholder="e.g. netric_cmms">
                <div class="hint">Usually same as the database name on cPanel</div>
            </div>
            <div class="field">
                <label>Database Password</label>
                <input type="password" name="db_pass" required placeholder="Your MySQL password">
            </div>
            <button type="submit">Save Configuration & Continue</button>
        </form>

        <div class="steps">
            <h3>Don't have a database yet?</h3>
            <ol>
                <li>Open <strong>cPanel → MySQL® Databases</strong></li>
                <li>Create database: enter <code>cmms</code> → click Create</li>
                <li>Create user: enter <code>cmms</code> + password → click Create</li>
                <li><strong>Add user to database</strong> → check ALL PRIVILEGES → click Make Changes</li>
                <li>Copy the <strong>full database name</strong> (e.g. <code>yourusername_cmms</code>) into the form above</li>
            </ol>
        </div>
    </div>
</body>
</html>
