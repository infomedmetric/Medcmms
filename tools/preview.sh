#!/bin/sh
# MedEquip CMMS (GLPI) — Freebuff managed preview launcher.
#
# Starts MariaDB (if not already running), waits until it accepts connections,
# ensures the GLPI database configuration exists, then serves the app with the
# PHP built-in server bound to $PORT (Freebuff injects it; default 8080).
#
# Kept as a single-token command (`./tools/preview.sh`) so the Freebuff
# preview runner can invoke it without shell-quoting issues.

set -e

# 1. Start MariaDB (ignore failure when it is already running)
service mariadb start >/dev/null 2>&1 || true

# 2. Wait up to 45s for the database to accept connections
i=0
while ! mysqladmin ping --silent 2>/dev/null; do
    i=$((i + 1))
    if [ "$i" -ge 45 ]; then
        echo "WARNING: MariaDB did not become ready within 45s, starting PHP anyway." >&2
        break
    fi
    sleep 1
done

# 3. If GLPI is not configured yet (fresh checkout), install the database schema
if [ ! -f config/config_db.php ]; then
    php bin/console db:install -u root -d glpi -H localhost -n --allow-superuser >/dev/null 2>&1 || true
fi

# 4. Seed the CMMS taxonomy (idempotent): creates the "Medical equipment"
#    asset type, its device types/manufacturers/models, grants profile
#    rights and deactivates every other asset type so the UI only shows
#    medical equipment.
if [ -f config/config_db.php ]; then
    php tools/seed_cmms_taxonomy.php >/dev/null 2>&1 || echo "WARNING: CMMS taxonomy seed failed (see previous error)." >&2
fi

# 5. Serve the application (Freebuff injects PORT for isolated workspaces)
exec php -S 0.0.0.0:${PORT:-8080} -t public public/index.php
