#!/bin/sh

if [ -z "${MYSQL_APP_PASSWORD:-}" ]; then
    echo "MYSQL_APP_PASSWORD is required." >&2
    return 1
fi

if [ -z "${MYSQL_APP_HOST:-}" ]; then
    echo "MYSQL_APP_HOST is required." >&2
    return 1
fi

case "$MYSQL_APP_PASSWORD$MYSQL_APP_HOST" in
    *"'"*|*\\*)
        echo "MYSQL_APP_PASSWORD and MYSQL_APP_HOST must not contain single quotes or backslashes." >&2
        return 1
        ;;
esac

if command -v docker_process_sql > /dev/null 2>&1; then
    sql() {
        docker_process_sql --database=mysql
    }
elif [ -n "${MYSQL_ROOT_PASSWORD:-}" ]; then
    sql() {
        mysql --protocol=socket -uroot -p"${MYSQL_ROOT_PASSWORD}" mysql
    }
else
    sql() {
        mysql --protocol=socket -uroot mysql
    }
fi

sql <<-EOSQL
    CREATE DATABASE IF NOT EXISTS osu;
    CREATE DATABASE IF NOT EXISTS osu_mp;
    CREATE DATABASE IF NOT EXISTS osu_charts;
    CREATE DATABASE IF NOT EXISTS osu_chat;
    CREATE DATABASE IF NOT EXISTS osu_store;
    CREATE DATABASE IF NOT EXISTS osu_updates;

    CREATE USER IF NOT EXISTS 'osuweb'@'${MYSQL_APP_HOST}' IDENTIFIED BY '${MYSQL_APP_PASSWORD}';
    ALTER USER 'osuweb'@'${MYSQL_APP_HOST}' IDENTIFIED BY '${MYSQL_APP_PASSWORD}';

    GRANT ALL PRIVILEGES ON osu.* TO 'osuweb'@'${MYSQL_APP_HOST}';
    GRANT ALL PRIVILEGES ON osu_mp.* TO 'osuweb'@'${MYSQL_APP_HOST}';
    GRANT ALL PRIVILEGES ON osu_charts.* TO 'osuweb'@'${MYSQL_APP_HOST}';
    GRANT ALL PRIVILEGES ON osu_chat.* TO 'osuweb'@'${MYSQL_APP_HOST}';
    GRANT ALL PRIVILEGES ON osu_store.* TO 'osuweb'@'${MYSQL_APP_HOST}';
    GRANT ALL PRIVILEGES ON osu_updates.* TO 'osuweb'@'${MYSQL_APP_HOST}';
EOSQL
