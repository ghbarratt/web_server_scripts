#!/bin/bash
# make_database_dumps.sh
# Dumps all non-system databases into <database>_<YYYYMMDD>.sql
# Supports PostgreSQL, MariaDB, and MySQL.

set -o pipefail

# Help option
if [ "$1" = "-h" ] || [ "$1" = "--help" ]; then
    echo "Usage: $(basename "$0") [backup_dir]"
    echo ""
    echo "Dumps all non-system databases into <database>_<YYYYMMDD>.sql."
    echo "Supported engines: PostgreSQL, MariaDB, MySQL (auto-detected if DB_TYPE is unset)."
    echo ""
    echo "Environment variables (or configured via .env):"
    echo "  DESTINATION_PATH_FOR_BACKUP  Destination directory (default: /var/www/backup)"
    echo "  DB_TYPE                      Explicitly set engine: 'postgres', 'mariadb', or 'mysql'"
    echo "  PG*                          Standard PostgreSQL connection parameters (PGHOST, PGPORT, PGUSER, PGDATABASE, etc.)"
    exit 0
fi

# Load optional .env from script directory if present
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
if [ -f "$SCRIPT_DIR/.env" ]; then
    # Only export valid shell assignment lines, ignoring comments and invalid syntax
    while IFS= read -r line || [ -n "$line" ]; do
        # Strip leading whitespace
        trimmed="${line#"${line%%[![:space:]]*}"}"
        # Skip empty lines and comments
        if [ -z "$trimmed" ] || [ "${trimmed#\#}" != "$trimmed" ] || [ "${trimmed#//}" != "$trimmed" ]; then
            continue
        fi
        # Match KEY=VALUE (ignoring spaces around =)
        if [[ "$trimmed" =~ ^([a-zA-Z_][a-zA-Z0-9_]*)[[:space:]]*=[[:space:]]*(.*)$ ]]; then
            key="${BASH_REMATCH[1]}"
            val="${BASH_REMATCH[2]}"
            # Strip outer quotes if present
            if [[ "$val" =~ ^\"(.*)\"$ ]] || [[ "$val" =~ ^\'(.*)\'$ ]]; then
                val="${BASH_REMATCH[1]}"
            fi
            # Do not overwrite already exported environment variables
            if [ -z "${!key}" ]; then
                export "$key"="$val"
            fi
        fi
    done < "$SCRIPT_DIR/.env"
fi

DATE=$(date +%Y%m%d)
DESTINATION_PATH_FOR_BACKUP="${1:-${DESTINATION_PATH_FOR_BACKUP:-/var/www/backup}}"

# Ensure backup directory exists and enter it
mkdir -p "$DESTINATION_PATH_FOR_BACKUP" || {
    echo "ERROR Cannot create or access backup directory: $DESTINATION_PATH_FOR_BACKUP" >&2
    exit 1
}
cd "$DESTINATION_PATH_FOR_BACKUP" || exit 1

# Detect database engine if not explicitly set
if [ -z "$DB_TYPE" ]; then
    if command -v pg_dump >/dev/null 2>&1 && { pg_isready -q 2>/dev/null || [ -n "$PGHOST" ] || { ! command -v mariadb >/dev/null 2>&1 && ! command -v mysql >/dev/null 2>&1 && ! command -v mysqldump >/dev/null 2>&1; }; }; then
        DB_TYPE="postgres"
    elif command -v mariadb >/dev/null 2>&1 || command -v mariadb-dump >/dev/null 2>&1; then
        DB_TYPE="mariadb"
    elif command -v mysql >/dev/null 2>&1 || command -v mysqldump >/dev/null 2>&1; then
        DB_TYPE="mysql"
    elif command -v pg_dump >/dev/null 2>&1; then
        DB_TYPE="postgres"
    fi
fi

# Normalize DB_TYPE
DB_TYPE=$(echo "$DB_TYPE" | tr '[:upper:]' '[:lower:]')

echo "INFO Using database engine: $DB_TYPE"
echo "INFO Backup directory: $DESTINATION_PATH_FOR_BACKUP"

DATABASES=""

case "$DB_TYPE" in
    postgres|postgresql|pgsql)
        # Fetch non-template, connectable databases, excluding default maintenance db 'postgres'
        # -X (--no-psqlrc) prevents reading startup files which may output text like "Pager usage is off."
        PSQL_ERR=$(mktemp)
        DATABASES=$(psql -X -q -d "${PGDATABASE:-postgres}" -At -c "SELECT datname FROM pg_database WHERE datistemplate = false AND datallowconn = true AND datname NOT IN ('postgres');" 2>"$PSQL_ERR")
        PSQL_EXIT=$?
        if [ $PSQL_EXIT -ne 0 ]; then
            echo "ERROR Failed to query PostgreSQL databases using psql (exit code $PSQL_EXIT):" >&2
            cat "$PSQL_ERR" >&2
            rm -f "$PSQL_ERR"
            exit 1
        fi
        rm -f "$PSQL_ERR"
        ;;

    mariadb)
        # MariaDB client
        CLIENT_CMD="mariadb"
        if ! command -v "$CLIENT_CMD" >/dev/null 2>&1; then
            CLIENT_CMD="mysql"
        fi
        DB_ERR=$(mktemp)
        DATABASES=$("$CLIENT_CMD" -N -e "SHOW DATABASES;" 2>"$DB_ERR" | grep -v -E "^(information_schema|performance_schema|mysql|phpmyadmin|sys)$")
        DB_EXIT=$?
        if [ $DB_EXIT -ne 0 ]; then
            echo "ERROR Failed to query MariaDB databases:" >&2
            cat "$DB_ERR" >&2
            rm -f "$DB_ERR"
            exit 1
        fi
        rm -f "$DB_ERR"
        ;;

    mysql)
        DB_ERR=$(mktemp)
        DATABASES=$(mysql -N -e "SHOW DATABASES;" 2>"$DB_ERR" | grep -v -E "^(information_schema|performance_schema|mysql|phpmyadmin|sys)$")
        DB_EXIT=$?
        if [ $DB_EXIT -ne 0 ]; then
            echo "ERROR Failed to query MySQL databases:" >&2
            cat "$DB_ERR" >&2
            rm -f "$DB_ERR"
            exit 1
        fi
        rm -f "$DB_ERR"
        ;;

    *)
        echo "ERROR Unsupported or undetected database engine '$DB_TYPE'." >&2
        echo "Please specify DB_TYPE (postgres, mariadb, or mysql) or verify database tools are installed." >&2
        exit 1
        ;;
esac

if [ -z "$DATABASES" ]; then
    echo "WARNING No databases found to dump."
    exit 0
fi

ERRORS=0

for db in $DATABASES; do
    dump_file="${db}_${DATE}.sql"
    echo "INFO Dumping database: $db -> $dump_file"

    case "$DB_TYPE" in
        postgres|postgresql|pgsql)
            # Use --clean --if-exists for clean DROP TABLE IF EXISTS statements
            if pg_dump --clean --if-exists "$db" > "$dump_file"; then
                echo "INFO Successfully dumped $db"
            else
                echo "ERROR Failed to dump PostgreSQL database $db" >&2
                rm -f "$dump_file" 2>/dev/null
                ERRORS=$((ERRORS + 1))
            fi
            ;;

        mariadb)
            DUMP_CMD="mariadb-dump"
            if ! command -v "$DUMP_CMD" >/dev/null 2>&1; then
                DUMP_CMD="mysqldump"
            fi
            if "$DUMP_CMD" "$db" > "$dump_file"; then
                echo "INFO Successfully dumped $db"
            else
                echo "ERROR Failed to dump MariaDB database $db" >&2
                rm -f "$dump_file" 2>/dev/null
                ERRORS=$((ERRORS + 1))
            fi
            ;;

        mysql)
            if mysqldump "$db" > "$dump_file"; then
                echo "INFO Successfully dumped $db"
            else
                echo "ERROR Failed to dump MySQL database $db" >&2
                rm -f "$dump_file" 2>/dev/null
                ERRORS=$((ERRORS + 1))
            fi
            ;;
    esac
done

if [ "$ERRORS" -gt 0 ]; then
    echo "WARNING Database backups finished with $ERRORS error(s)." >&2
    exit 1
fi

echo "INFO All backups completed successfully!"
