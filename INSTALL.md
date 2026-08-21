# MedEquip CMMS — Installation Guide

## Quick Start (One-Click Install)

### Requirements

| Requirement | Minimum |
|-------------|---------|
| PHP | 8.2 or higher |
| MariaDB/MySQL | 10.5+ (or MySQL 5.7+) |
| PHP Extensions | `mysqli`, `gd`, `curl`, `mbstring`, `intl`, `openssl`, `xml`, `bcmath`, `json`, `zip` |

### Step 1: Upload Files

**Option A: cPanel File Manager**
1. Login to cPanel → **File Manager**
2. Navigate to your subdomain folder (e.g., `public_html/cmms/`)
3. Upload the `medequip-cmms-full.zip` file
4. Right-click → **Extract**
5. Make sure the Document Root points to the `public/` folder

**Option B: Git (if SSH available)**
```bash
cd ~/public_html
git clone https://github.com/infomedmetric/glpi.git cmms
```

### Step 2: Create Database

1. In cPanel → **MySQL® Databases**
2. Create new database: `medequip_cmms`
3. Create new user with a strong password
4. Add user to database with **ALL PRIVILEGES**

### Step 3: Run the Installer

1. Visit your site: `https://yourdomain.com/install_cmms.php`
2. Follow the 4-step wizard:
   - **Step 1**: Requirements check (auto-detected)
   - **Step 2**: Enter database credentials and create admin account
   - **Step 3**: Automatic installation (30-60 seconds)
   - **Step 4**: Login with your credentials

### Step 4: Delete Installer

After successful installation, delete `public/install_cmms.php` for security.

---

## Default Accounts

| Account | Username | Password | Role |
|---------|----------|----------|------|
| Admin | `admin` | *(your choice)* | Super-Admin (sees all departments) |
| Default | `glpi` | `glpi` | Super-Admin (sees all departments) |
| Technician | `tech` | `tech` | Technician (sees ICU only) |
| Viewer | `normal` | `normal` | Viewer (sees Radiology only) |

---

## Post-Installation Setup

### 1. Create Users
- Go to **Administration → Users**
- Create accounts for your staff
- Assign each user to a department

### 2. Register Equipment
- Go to **Assets → Medical equipment**
- Click **Add** to register new equipment
- Select Department, Manufacturer, Type, and Model
- The cascading dropdowns will auto-filter based on manufacturer

### 3. Department Management
- Go to **Administration → Locations**
- Add/edit/delete departments as needed
- Assign users to their departments

### 4. Dashboard
- The main dashboard shows:
  - Total equipment count
  - Equipment by Manufacturer (donut chart)
  - Equipment by Model (donut chart)
  - Equipment by Status (donut chart)
  - Open work orders
  - PM schedules

---

## Troubleshooting

### "Missing composer autoload file"
Run this via SSH:
```bash
cd ~/public_html/cmms
composer install --no-dev --optimize-autoloader
```

### "Connection to SQL server could not be established"
1. Delete `config/config_db.php`
2. Re-run the installer
3. Double-check your database credentials (use full `username_dbname` format)

### "The web root directory must correspond to the /public directory"
Change your subdomain's Document Root to point to the `public/` folder:
1. cPanel → **Domains** → Manage your subdomain
2. Change Document Root to: `public_html/cmms/public`

---

## File Structure

```
cmms/
├── public/                    ← Web server points here
│   ├── index.php
│   ├── install_cmms.php       ← One-click installer
│   ├── pics/
│   │   └── logos/
│   │       ├── medequip-icon.svg
│   │       └── medequip-login.svg
│   └── .htaccess
├── src/
│   ├── Glpi/
│   │   ├── CMMS/
│   │   │   └── DepartmentFilter.php    ← Department scoping
│   │   ├── Asset/
│   │   │   └── AssetModel.php          ← Manufacturer/Type fields
│   │   └── Dashboard/
│   │       └── Dashboard.php           ← CMMS dashboard cards
│   └── Html.php                        ← Menu configuration
├── ajax/
│   ├── cmms_equipment_fields.php       ← Cascading dropdowns
│   └── cmms_department_switch.php      ← Department switcher
├── front/
│   └── department.php                  ← Department management
├── templates/
│   └── pages/assets/
│       └── asset.html.twig             ← Asset form with Department field
├── tools/
│   ├── seed_cmms_taxonomy.php          ← CMMS data seeder
│   └── preview.sh                      ← Preview launcher
└── css/
    └── palettes/
        └── _medical.scss               ← Teal medical theme
```

---

## Features

### Equipment Management
- Register medical equipment with full details
- Cascading Manufacturer → Type → Model dropdowns
- Equipment categories (Imaging, Laboratory, ICU, etc.)
- Department assignment
- Serial number and inventory tracking

### Department Isolation
- Each department sees only its equipment
- Admin can switch between departments via topbar dropdown
- Technicians see only their assigned department's data
- Viewers have read-only access to their department

### Dashboard
- Real-time equipment statistics
- Manufacturer and model distribution
- Work order tracking
- PM schedule overview

### Work Orders
- Create tickets for equipment issues
- Auto-assign department from equipment
- Track maintenance history
- PM scheduling for preventive maintenance

---

## Support

For issues or questions:
- GitHub: https://github.com/infomedmetric/glpi
- Documentation: See README.md
