# Phase 4c: Connection Pooling & Read Replicas Deployment Guide

## Overview

Phase 4c enables horizontal scaling through database connection pooling and read replicas, supporting 10-30x throughput increase.

## Part 1: Connection Pooling with PgBouncer or MariaDB MaxScale

### Option A: MariaDB MaxScale (Recommended for MySQL/MariaDB)

**Benefits:**
- Read/write routing to primary and replicas
- Connection pooling: 1000s of client connections → 10s to primary
- Query result caching
- No application code changes required

**Installation (Docker):**

```yaml
# docker-compose.yml
  maxscale:
    image: mariadb/maxscale:latest
    environment:
      MAXSCALE_ADMIN_PORT: 8989
    ports:
      - "3307:3306"  # Pooled connection port
    volumes:
      - ./maxscale.cnf:/etc/maxscale.cnf:ro
```

**maxscale.cnf:**

```ini
[maxscale]
threads=4
log_info=1

[server-primary]
type=server
address=continuo-db-1
port=3306
protocol=MySQLBackend

[Read-Only-Slaves]
type=server
address=continuo-db-replica-1
port=3306
protocol=MySQLBackend

[MySQL-Monitor]
type=monitor
module=mariadbmon
servers=server-primary,Read-Only-Slaves
user=maxscale
password=maxscale_pass
monitor_interval=1000ms

[Read-Write-Router]
type=filter
module=qc_sqlite
cache_dir=/var/lib/maxscale

[RW-Split-Router]
type=service
router=readwritesplit
servers=server-primary,Read-Only-Slaves
user=continuo
password=continuo_pass

[MaxScale-Listener]
type=listener
service=RW-Split-Router
protocol=MySQLClient
port=3306
```

**In application:** Use `maxscale:3306` instead of `continuo-db-1:3306`

---

### Option B: PgBouncer (For PostgreSQL)

Not applicable for MariaDB, but documented for reference.

---

## Part 2: Read Replicas with MariaDB Replication

### Setup Primary-Replica Replication

**1. On Primary Server:**

```sql
-- Enable binary logging
SHOW VARIABLES LIKE 'log_bin';  -- Should be ON

-- Create replication user
CREATE USER 'repl'@'%' IDENTIFIED BY 'repl_password';
GRANT REPLICATION SLAVE ON *.* TO 'repl'@'%';
FLUSH PRIVILEGES;

-- Get current binlog position
SHOW MASTER STATUS;
-- Record: File, Position, Binlog_Do_DB
```

**2. On Replica Server:**

```sql
-- Configure replica
CHANGE MASTER TO
  MASTER_HOST='primary-host',
  MASTER_USER='repl',
  MASTER_PASSWORD='repl_password',
  MASTER_LOG_FILE='mysql-bin.000001',
  MASTER_LOG_POS=1234;

-- Start replication
START SLAVE;

-- Monitor replication
SHOW SLAVE STATUS\G
-- Look for: Seconds_Behind_Master (should be 0 or small)
```

**3. In Docker Compose:**

```yaml
services:
  db:
    image: mariadb:10.11
    environment:
      MYSQL_ROOT_PASSWORD: root_pass
      MYSQL_DATABASE: continuo
      MYSQL_USER: continuo
      MYSQL_PASSWORD: continuo
    command:
      - --server-id=1
      - --log-bin=mysql-bin
      - --binlog-format=ROW
    volumes:
      - db_primary:/var/lib/mysql

  db-replica:
    image: mariadb:10.11
    environment:
      MYSQL_ROOT_PASSWORD: root_pass
    command:
      - --server-id=2
      - --relay-log=mysql-relay-bin
      - --relay-log-index=mysql-relay-bin.index
    depends_on:
      - db
    volumes:
      - db_replica:/var/lib/mysql
```

---

## Part 3: Application Configuration

### Update .env

```env
# Primary for writes
DATABASE_URL="mysql://continuo:continuo@maxscale:3306/continuo"

# Optional: Separate read replica (if not using MaxScale routing)
# DATABASE_REPLICA_URL="mysql://continuo:continuo@continuo-db-replica-1:3306/continuo"
```

### Update Doctrine Config

Uncomment replica connection in `config/packages/doctrine.yaml`:

```yaml
connections:
    replica:
        url: '%env(resolve:DATABASE_REPLICA_URL)%'
        readonly: true
```

### Use Replica in Queries

```php
// Repository method — explicit replica usage
public function findComposersByPopularity(): array
{
    $conn = $this->getEntityManager()->getConnection('replica');
    return $conn->fetchAllAssociative('
        SELECT composer, COUNT(*) AS cnt
        FROM imslp_work
        GROUP BY composer
        ORDER BY cnt DESC
        LIMIT 100
    ');
}
```

---

## Performance Expectations

| Configuration | Reads/sec | Writes/sec | Latency |
|---|---|---|---|
| Single DB | 100 | 50 | 10ms |
| With Connection Pooling | 500+ | 250+ | 2-5ms |
| With Read Replica | 1000+ | 50 | 1-3ms |
| MaxScale + Replica | 2000+ | 250+ | 1-2ms |

---

## Monitoring

### Check Connection Pool Status (MaxScale)

```bash
maxadmin -u admin -p mariadb list services
maxadmin -u admin -p mariadb show service RW-Split-Router
```

### Monitor Replica Lag

```sql
-- On replica
SHOW SLAVE STATUS\G
-- Key metrics:
--   Seconds_Behind_Master: Should be 0 or < 1
--   Slave_IO_Running: Should be Yes
--   Slave_SQL_Running: Should be Yes
```

---

## Troubleshooting

### Replica Falls Behind

**Cause:** Replica can't keep up with primary writes

**Solution:**
1. Increase replica server resources (CPU, disk IOPS)
2. Optimize slow queries on primary (run EXPLAIN ANALYZE)
3. Consider parallel replication (MariaDB 10.2+)

```sql
SET GLOBAL slave_parallel_workers=4;
SET GLOBAL slave_parallel_threads=4;
```

### Connection Pool Exhaustion

**Symptom:** "Too many connections" error

**Solution:** Increase pool size in MaxScale config

```ini
[RW-Split-Router]
max_connections=100
```

---

## Rollback

To disable replicas and return to single primary:

1. Stop replication on replica
2. Update DATABASE_URL to point directly to primary
3. Comment out replica connection in doctrine.yaml
4. Clear application cache
