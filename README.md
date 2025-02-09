php-mysql-replication
=========
[![PHP Tests](https://github.com/Moln/php-mysql-replication/actions/workflows/tests.yml/badge.svg)](https://github.com/Moln/php-mysql-replication/actions/workflows/tests.yml)
[![Latest Stable Version](https://poser.pugx.org/moln/php-mysql-replication/v/stable)](https://packagist.org/packages/moln/php-mysql-replication) [![Total Downloads](https://poser.pugx.org/moln/php-mysql-replication/downloads)](https://packagist.org/packages/moln/php-mysql-replication) 
[![License](https://poser.pugx.org/moln/php-mysql-replication/license)](https://packagist.org/packages/moln/php-mysql-replication)
[![Scrutinizer Code Quality](https://scrutinizer-ci.com/g/moln/php-mysql-replication/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/moln/php-mysql-replication/?branch=master)
[![Coverage Status](https://coveralls.io/repos/github/Moln/php-mysql-replication/badge.svg?branch=master)](https://coveralls.io/github/Moln/php-mysql-replication?branch=master)

Pure PHP Implementation of MySQL replication protocol. This allow you to receive event like insert, update, delete with their data and raw SQL queries.

Based on a great work of creators：https://github.com/noplay/python-mysql-replication and https://github.com/fengxiangyun/mysql-replication

--------------------------------------------------

**Note:** Resolve these issues:

- Add regular expression matching support for `DatabasesOnly` or `TablesOnly` of `Config`. 
- Resolve [krowinski/php-mysql-replication#94](https://github.com/krowinski/php-mysql-replication/issues/94),  change static config properties to non-static.
- Add retry feature.
  ```php
  (new ConfigBuilder())
    ->withRetry(-1) // Retry always.
    ->withRetry(0)  // Disable retry feature. (Default)
    ->withRetry(2)  // Retry twice.
  ```
- PHP 8.1,8.2 supports.
--------------------------------------------------


Installation
=========

In you project

```sh
composer require moln/php-mysql-replication
```

or standalone 

```sh
git clone https://github.com/moln/php-mysql-replication.git

composer install -o
```

Compatibility (based on integration tests) 
=========

 - mysql 5.5
 - mysql 5.6
 - mysql 5.7
 - mysql 8.0 (ONLY with mysql_native_password)
 - mariadb 5.5
 - mariadb 10.0
 - mariadb 10.1
 - probably percona versions as is based on native mysql

MySQL server settings
=========

In your MySQL server configuration file you need to enable replication:

    [mysqld]
    server-id        = 1
    log_bin          = /var/log/mysql/mysql-bin.log
    expire_logs_days = 10
    max_binlog_size  = 100M
    binlog-format    = row #Very important if you want to receive write, update and delete row events


Mysql replication events explained
    https://dev.mysql.com/doc/internals/en/event-meanings.html


Mysql user privileges:
```
GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'user'@'host';

GRANT SELECT ON `dbName`.* TO 'user'@'host';
```

Configuration
=========

Use ConfigBuilder or ConfigFactory to create configuration.
Available options:

'user' - your mysql user (mandatory)

'ip' or 'host' - your mysql host/ip (mandatory)

'password' - your mysql password (mandatory)

'port' - your mysql host port (default 3306)

'charset' - db connection charset (default utf8)

'gtid' - GTID marker(s) to start from (format 9b1c8d18-2a76-11e5-a26b-000c2976f3f3:1-177592)

'mariaDbGtid' - MariaDB GTID marker(s) to start from (format 1-1-3,0-1-88)

'slaveId' - script slave id for identification (SHOW SLAVE HOSTS)

'binLogFileName' - bin log file name to start from

'binLogPosition' - bin log position to start from 

'eventsOnly' - array  to listen on events (full list in [ConstEventType.php](https://github.com/moln/php-mysql-replication/blob/master/src/MySQLReplication/Definitions/ConstEventType.php) file)

'eventsIgnore' - array to ignore events (full list in [ConstEventType.php](https://github.com/moln/php-mysql-replication/blob/master/src/MySQLReplication/Definitions/ConstEventType.php) file) 

'tablesOnly' - array to only listen on given tables (default all tables) 

'databasesOnly' - array to only listen on given databases (default all databases) 
 
'tableCacheSize' - some data are collected from information schema, this data is cached.

'custom' - if some params must be set in extended/implemented own classes

'heartbeatPeriod' - sets the interval in seconds between replication heartbeats. Whenever the master's binary log is updated with an event, the waiting period for the next heartbeat is reset. interval is a decimal value having the range 0 to 4294967 seconds and a resolution in milliseconds; the smallest nonzero value is 0.001. Heartbeats are sent by the master only if there are no unsent events in the binary log file for a period longer than interval.

Similar projects
=========
Ruby: https://github.com/y310/kodama

Java: https://github.com/shyiko/mysql-binlog-connector-java

GO: https://github.com/siddontang/go-mysql

Python: https://github.com/noplay/python-mysql-replication

.NET: https://github.com/rusuly/MySqlCdc

Examples
=========

All examples are available in the [examples directory](https://github.com/moln/php-mysql-replication/tree/master/example)

This example will dump all replication events to the console:

Remember to change config for your user, host and password.

User should have replication privileges [ REPLICATION CLIENT, SELECT]

```sh
php example/dump_events.php
```

For test SQL events:

```sql
CREATE DATABASE php_mysql_replication;
use php_mysql_replication;
CREATE TABLE test4 (id int NOT NULL AUTO_INCREMENT, data VARCHAR(255), data2 VARCHAR(255), PRIMARY KEY(id));
INSERT INTO test4 (data,data2) VALUES ("Hello", "World");
UPDATE test4 SET data = "World", data2="Hello" WHERE id = 1;
DELETE FROM test4 WHERE id = 1;
```

Output will be similar to this (depends on configuration for example GTID off/on):

    === Event format description ===
    Date: 2017-07-06T13:31:11+00:00
    Log position: 0
    Event size: 116
    Memory usage 2.4 MB
    
    === Event gtid ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57803092
    Event size: 48
    Commit: true
    GTID NEXT: 3403c535-624f-11e7-9940-0800275713ee:13675
    Memory usage 2.42 MB
    
    === Event query ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57803237
    Event size: 145
    Database: php_mysql_replication
    Execution time: 0
    Query: CREATE DATABASE php_mysql_replication
    Memory usage 2.45 MB
    
    === Event gtid ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57803285
    Event size: 48
    Commit: true
    GTID NEXT: 3403c535-624f-11e7-9940-0800275713ee:13676
    Memory usage 2.45 MB
    
    === Event query ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57803500
    Event size: 215
    Database: php_mysql_replication
    Execution time: 0
    Query: CREATE TABLE test4 (id int NOT NULL AUTO_INCREMENT, data VARCHAR(255), data2 VARCHAR(255), PRIMARY KEY(id))
    Memory usage 2.45 MB
    
    === Event gtid ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57803548
    Event size: 48
    Commit: true
    GTID NEXT: 3403c535-624f-11e7-9940-0800275713ee:13677
    Memory usage 2.45 MB
    
    === Event query ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57803637
    Event size: 89
    Database: php_mysql_replication
    Execution time: 0
    Query: BEGIN
    Memory usage 2.45 MB
    
    === Event tableMap ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57803708
    Event size: 71
    Table: test4
    Database: php_mysql_replication
    Table Id: 866
    Columns amount: 3
    Memory usage 2.71 MB
    
    === Event write ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57803762
    Event size: 54
    Table: test4
    Affected columns: 3
    Changed rows: 1
    Values: Array
    (
        [0] => Array
            (
                [id] => 1
                [data] => Hello
                [data2] => World
            )
    
    )
    
    Memory usage 2.74 MB
    
    === Event xid ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57803793
    Event size: 31
    Transaction ID: 662802
    Memory usage 2.75 MB
    
    === Event gtid ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57803841
    Event size: 48
    Commit: true
    GTID NEXT: 3403c535-624f-11e7-9940-0800275713ee:13678
    Memory usage 2.75 MB
    
    === Event query ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57803930
    Event size: 89
    Database: php_mysql_replication
    Execution time: 0
    Query: BEGIN
    Memory usage 2.76 MB
    
    === Event tableMap ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57804001
    Event size: 71
    Table: test4
    Database: php_mysql_replication
    Table Id: 866
    Columns amount: 3
    Memory usage 2.75 MB
    
    === Event update ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57804075
    Event size: 74
    Table: test4
    Affected columns: 3
    Changed rows: 1
    Values: Array
    (
        [0] => Array
            (
                [before] => Array
                    (
                        [id] => 1
                        [data] => Hello
                        [data2] => World
                    )
    
                [after] => Array
                    (
                        [id] => 1
                        [data] => World
                        [data2] => Hello
                    )
    
            )
    
    )
    
    Memory usage 2.76 MB
    
    === Event xid ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57804106
    Event size: 31
    Transaction ID: 662803
    Memory usage 2.76 MB
    
    === Event gtid ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57804154
    Event size: 48
    Commit: true
    GTID NEXT: 3403c535-624f-11e7-9940-0800275713ee:13679
    Memory usage 2.76 MB
    
    === Event query ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57804243
    Event size: 89
    Database: php_mysql_replication
    Execution time: 0
    Query: BEGIN
    Memory usage 2.76 MB
    
    === Event tableMap ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57804314
    Event size: 71
    Table: test4
    Database: php_mysql_replication
    Table Id: 866
    Columns amount: 3
    Memory usage 2.76 MB
    
    === Event delete ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57804368
    Event size: 54
    Table: test4
    Affected columns: 3
    Changed rows: 1
    Values: Array
    (
        [0] => Array
            (
                [id] => 1
                [data] => World
                [data2] => Hello
            )
    
    )
    
    Memory usage 2.77 MB
    
    === Event xid ===
    Date: 2017-07-06T15:23:44+00:00
    Log position: 57804399
    Event size: 31
    Transaction ID: 662804
    Memory usage 2.77 MB



Benchmarks
=========

Tested on VM

    Debian 8.7
    PHP 5.6.30
    Percona 5.6.35

```sh
inxi
```

    CPU(s)~4 Single core Intel Core i5-2500Ks (-SMP-) clocked at 5901 Mhz Kernel~3.16.0-4-amd64 x86_64 Up~1 day Mem~1340.3/1996.9MB HDD~41.9GB(27.7% used) Procs~122 Client~Shell inxi~2.1.28

```sh
php example/benchmark.php
```
    Start insert data
    7442 event by seconds (1000 total)
    7679 event by seconds (2000 total)
    7914 event by seconds (3000 total)
    7904 event by seconds (4000 total)
    7965 event by seconds (5000 total)
    8006 event by seconds (6000 total)
    8048 event by seconds (7000 total)
    8038 event by seconds (8000 total)
    8040 event by seconds (9000 total)
    8055 event by seconds (10000 total)
    8058 event by seconds (11000 total)
    8071 event by seconds (12000 total)

FAQ
=========

1. ### Why and when need php-mysql-replication ?
 Well first of all mysql don't give you async calls. You usually need to program this in your application (by event dispaching and adding to some queue system and if your db have many point of entry like web, backend other microservices its not always cheap to add processing to all of them. But using mysql replication protocol you can lisen on write events and process then asynchronously (best combo it's to add item to some queue system like rabbitmq, redis or kafka). Also in invalidate cache,  search engine replication, real time analytics and audits.

2. ### It's awsome ! but what is the catch ?
 Well first of all you need to know that a lot of events may come through, like if you update 1 000 000  records in table   "bar" and you need this one insert from your table "foo" Then all must be processed by script and you need to wait for your data. This is normal and this how it's work. You can speed up using [config options](https://github.com/moln/php-mysql-replication#configuration).
Also if script crashes you need to save from time to time position form binlog (or gtid) to start from this position when  you run this script again to avoid duplicates.

3. ### I need to process 1 000 000 records and its taking forever!!
 Like I mention in 1 point use queue system like rabbitmq, redis or kafka, they will give you ability to process data in multiple scripts.

4. ### I have a problem ? you script is missing something ! I have found a bug !
 Create an [issue](https://github.com/moln/php-mysql-replication/issues) I will try to work on it in my free time :)

5. ### How much its give overhead to mysql server ?
 It work like any other mysql in slave mode and its giving same overhead.
 
6. ### Socket timeouts error
 To fix this best is to increase db configurations "net_read_timeout" and "net_write_timeout" to 3600.  (tx Bijimon)
 
7. ### Partial updates fix
 Set in my.conf ```binlog_row_image=full``` to fix reciving only partial updates.  
 
8. ### No replication events when connected to replica server   
 Set in my.conf ```log_slave_updates=on``` to fix this (#71)(#66)

