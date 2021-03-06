Configuring TDBM
================

All the configuration needed for TDBM is stored in a `Configuration` object.

When you install TDBM, Mouf will create and set-up this configuration object for you.

If you are interested into setting up this configuration object yourself, it is quite easy to do.
At minimum, you need a Doctrine database connection and a Doctrine cache object.

We strongly advise to use the APCCache from Doctrine that will yield the best performances.

Without using any framework, a working TDBM setup could look like this:

```php
$config = new \Doctrine\DBAL\Configuration();

$connectionParams = array(
    'user' => 'mysql_user',
    'password' => 'mysql_password',
    'host' => 'localhost',
    'driver' => 'pdo_mysql',
    'dbname' => 'my_db',
);

$dbConnection = Doctrine\DBAL\DriverManager::getConnection($connectionParams, $config);

// The bean and DAO namespace that will be used to generate the beans and DAOs. These namespaces must be autoloadable from Composer.
$beanNamespace = 'MyApp\\Beans';
$daoNamespace = 'MyApp\\Daos';

// The naming strategy used to define the classname of DAOs and beans.
$namingStrategy = new Mouf\Database\TDBM\Utils\DefaultNamingStrategy();

$cache = new Doctrine\Common\Cache\ApcuCache();

$logger = new Monolog\Logger(); // $logger must be a PSR-3 compliant logger.

$configuration = new Mouf\Database\TDBM\Configuration(
    $beanNamespace,
    $daoNamespace,
    $dbConnection,
    $namingStrategy,
    $cache,
    null,    // An optional SchemaAnalyzer instance
    $logger, // An optional logger
    []       // A list of generator listeners to hook into code generation
);

$tdbmService = new TDBMService($configuration);
```
