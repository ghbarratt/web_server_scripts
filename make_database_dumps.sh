#!/bin/bash
DATE=$(date +%Y%m%d)
BACKUP_DIR="/var/www/backup"

# Ensure we are in the backup directory
cd "$BACKUP_DIR" || exit 1

# Fetch databases, excluding system schemas
DATABASES=$(mysqldump -N -e "SHOW DATABASES;" 2>/dev/null | grep -v -E "^(information_schema|performance_schema|mysql|phpmyadmin)$")
# Note: For SHOW DATABASES, use 'mysql' or 'mariadb' client, not mysqldump:
DATABASES=$(mariadb -N -e "SHOW DATABASES;" | grep -v -E "^(information_schema|performance_schema|mysql|phpmyadmin)$")

for db in $DATABASES; do
    echo "Dumping database: $db -> ${db}_${DATE}.sql"
    mysqldump "$db" > "${db}_${DATE}.sql"
done

echo "All backups completed successfully!"
