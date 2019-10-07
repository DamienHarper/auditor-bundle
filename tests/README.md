Running Tests
=============

Before running the test suite, you first need to install dev dependencies:

```bash
composer install --dev
```

Then you can run the test suite with different configuration (SQLite, MySQL or PostgreSQL):

### Default configuration (SQLite)

This configuration uses an in memory SQLite database and generates code coverage report in `tests/coverage` folder (requires [Xdebug extension](https://xdebug.org/docs/install#configure-php)).

```bash
./vendor/bin/phpunit 
```

You can also run tests using an in memory SQLite database and without generating code coverage report, it's the fastest configuration.

```bash
./vendor/bin/phpunit -c tests/travis/sqlite.travis.xml 
```

### MySQL configuration

This configuration expects to connect to a MySQL database.

```bash
./vendor/bin/phpunit -c tests/travis/mysql.travis.xml 
```

**Note**: connection parameters (username, password, host, port, etc) are set in `./tests/travis/mysql.travis.xml` file.

Assuming you have docker installed, you can easily start a MySQL server with following command (MySQL 8)

```bash
docker run --name mysql_db -e MYSQL_DATABASE=doctrine_audit -e MYSQL_ALLOW_EMPTY_PASSWORD=1 -d -p 3306:3306 mysql --default-authentication-plugin=mysql_native_password
```


### PostgreSQL configuration

This configuration expects to connect to a PostgreSQL database.

```bash
./vendor/bin/phpunit -c tests/travis/pgsql.travis.xml 
```

**Note**: connection parameters (username, password, host, port, etc) are set in `./tests/travis/pgsql.travis.xml` file.

Assuming you have docker installed, you can easily start a PostgreSQL server with following command (PostgreSQL 11)

```bash
docker run --name postgres_db -e POSTGRES_DB=doctrine_audit -d -p 5432:5432 postgres
```
