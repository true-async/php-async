<?php
require_once __DIR__ . '/config.inc';

use function Async\spawn;
use function Async\await;
use function Async\awaitAllOrFail;

class AsyncPDOMySQLTest {
    
    /**
     * Create a PDO connection with async support
     */
    static function factory($host = null, $db = null, $user = null, $pass = null, $options = array()) {
        $host = $host ?: MYSQL_TEST_HOST;
        $db = $db ?: MYSQL_TEST_DB;
        $user = $user ?: MYSQL_TEST_USER;
        $pass = $pass ?: MYSQL_TEST_PASSWD;
        $port = MYSQL_TEST_PORT;
        $socket = MYSQL_TEST_SOCKET;
        $charset = MYSQL_TEST_CHARSET;
        
        $dsn = "mysql:host={$host}";
        if ($port) {
            $dsn .= ";port={$port}";
        }
        if ($socket) {
            $dsn .= ";unix_socket={$socket}";
        }
        if ($db) {
            $dsn .= ";dbname={$db}";
        }
        if ($charset) {
            $dsn .= ";charset={$charset}";
        }
        
        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        return new PDO($dsn, $user, $pass, $options);
    }
    
    /**
     * Create a PDO connection without specifying database (for database creation)
     */
    static function factoryWithoutDB($host = null, $user = null, $pass = null, $options = array()) {
        $host = $host ?: MYSQL_TEST_HOST;
        $user = $user ?: MYSQL_TEST_USER;
        $pass = $pass ?: MYSQL_TEST_PASSWD;
        $port = MYSQL_TEST_PORT;
        $socket = MYSQL_TEST_SOCKET;
        $charset = MYSQL_TEST_CHARSET;
        
        $dsn = "mysql:host={$host}";
        if ($port) {
            $dsn .= ";port={$port}";
        }
        if ($socket) {
            $dsn .= ";unix_socket={$socket}";
        }
        if ($charset) {
            $dsn .= ";charset={$charset}";
        }
        
        $defaultOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ];
        
        $options = array_merge($defaultOptions, $options);
        
        return new PDO($dsn, $user, $pass, $options);
    }
    
    /**
     * Initialize database with test schema
     */
    static function initDatabase($pdo = null) {
        if (!$pdo) {
            // First connect without database to create it
            $pdo = self::factoryWithoutDB();
        }
        
        // Create test database if it doesn't exist
        $dbName = MYSQL_TEST_DB;
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$dbName}`");
        
        return $pdo;
    }
    
    /**
     * Create a test table for async tests
     */
    static function createTestTable($pdo, $tableName = 'async_test', $engine = null) {
        if (!$engine) {
            $engine = MYSQL_TEST_ENGINE;
        }
        
        $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");
        $pdo->exec("CREATE TABLE `{$tableName}` (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE={$engine}");
        
        // Insert test data
        $pdo->exec("INSERT INTO `{$tableName}` (name, value) VALUES 
            ('test1', 'value1'),
            ('test2', 'value2'),
            ('test3', 'value3'),
            ('test4', 'value4'),
            ('test5', 'value5')
        ");
        
        return $tableName;
    }
    
    /**
     * Clean up test tables
     */
    static function cleanupTestTable($pdo, $tableName = 'async_test') {
        $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");
    }
    
    /**
     * Check if MySQL server is available
     */
    static function skip() {
        try {
            // Connect without database first to test server availability
            $pdo = self::factoryWithoutDB();
            $pdo->query("SELECT 1");
        } catch (Exception $e) {
            die('skip MySQL server not available or configuration invalid: ' . $e->getMessage());
        }
    }
    
    /**
     * Check if async extension is loaded
     */
    static function skipIfNoAsync() {
    }
    
    /**
     * Check if PDO MySQL is available
     */
    static function skipIfNoPDOMySQL() {
        if (!extension_loaded('pdo_mysql')) {
            die('skip pdo_mysql extension not loaded');
        }
    }
    
    /**
     * Skip if MySQL version is less than required
     */
    static function skipIfMySQLVersionLess($requiredVersion) {
        $pdo = self::factoryWithoutDB();
        $stmt = $pdo->query("SELECT VERSION() as version");
        $row = $stmt->fetch();
        
        if (version_compare($row['version'], $requiredVersion, '<')) {
            die("skip MySQL version {$requiredVersion} or higher required, found {$row['version']}");
        }
    }
    
    /**
     * Get MySQL version
     */
    static function getMySQLVersion($pdo = null) {
        if (!$pdo) {
            $pdo = self::factoryWithoutDB();
        }
        
        $stmt = $pdo->query("SELECT VERSION() as version");
        $row = $stmt->fetch();
        return $row['version'];
    }
    
    /**
     * Run concurrent async tests without depending on execution order
     */
    static function runConcurrentTest($coroutines, $assertCallback = null) {
        $pdo = self::initDatabase();
        
        try {
            $results = awaitAllOrFail($coroutines);
            
            if ($assertCallback) {
                $assertCallback($results);
            }
            
            return $results;
            
        } catch (Exception $e) {
            throw $e;
        }
    }
    
    /**
     * Run async test with proper setup and cleanup
     */
    static function runAsyncTest($testCallback, $tableName = 'async_test', $setupCallback = null, $cleanupCallback = null) {
        $pdo = self::initDatabase();
        
        try {
            // Setup
            if ($setupCallback) {
                $setupCallback($pdo);
            } else {
                self::createTestTable($pdo, $tableName);
            }
            
            // Run test in coroutine
            $coroutine = spawn(function() use ($testCallback, $pdo, $tableName) {
                return $testCallback($pdo, $tableName);
            });
            
            $result = await($coroutine);
            
            // Cleanup
            if ($cleanupCallback) {
                $cleanupCallback($pdo);
            } else {
                self::cleanupTestTable($pdo, $tableName);
            }
            
            return $result;
            
        } catch (Exception $e) {
            // Ensure cleanup on error
            if ($cleanupCallback) {
                $cleanupCallback($pdo);
            } else {
                self::cleanupTestTable($pdo, $tableName);
            }
            throw $e;
        }
    }
}
?>