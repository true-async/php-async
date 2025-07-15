<?php
require_once __DIR__ . '/config.inc';

use function Async\spawn;
use function Async\await;
use function Async\awaitAllOrFail;

class AsyncMySQLiTest {
    
    /**
     * Create a MySQLi connection with async support
     */
    static function factory($host = null, $user = null, $passwd = null, $db = null, $port = null, $socket = null) {
        $host = $host ?: MYSQL_TEST_HOST;
        $user = $user ?: MYSQL_TEST_USER;
        $passwd = $passwd ?: MYSQL_TEST_PASSWD;
        $db = $db ?: MYSQL_TEST_DB;
        $port = $port ?: MYSQL_TEST_PORT;
        $socket = $socket ?: MYSQL_TEST_SOCKET;
        
        $mysqli = new mysqli($host, $user, $passwd, $db, $port, $socket);
        
        if ($mysqli->connect_error) {
            throw new Exception("Connection failed: " . $mysqli->connect_error);
        }
        
        return $mysqli;
    }
    
    /**
     * Create a MySQLi connection without specifying database (for database creation)
     */
    static function factoryWithoutDB($host = null, $user = null, $passwd = null, $port = null, $socket = null) {
        $host = $host ?: MYSQL_TEST_HOST;
        $user = $user ?: MYSQL_TEST_USER;
        $passwd = $passwd ?: MYSQL_TEST_PASSWD;
        $port = $port ?: MYSQL_TEST_PORT;
        $socket = $socket ?: MYSQL_TEST_SOCKET;
        
        $mysqli = new mysqli($host, $user, $passwd, null, $port, $socket);
        
        if ($mysqli->connect_error) {
            throw new Exception("Connection failed: " . $mysqli->connect_error);
        }
        
        return $mysqli;
    }
    
    /**
     * Initialize database with test schema
     */
    static function initDatabase($mysqli = null) {
        if (!$mysqli) {
            // First connect without database to create it
            $mysqli = self::factoryWithoutDB();
        }
        
        // Create test database if it doesn't exist
        $dbName = MYSQL_TEST_DB;
        if (!$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            throw new Exception("Failed to create database: " . $mysqli->error);
        }
        
        if (!$mysqli->select_db($dbName)) {
            throw new Exception("Failed to select database: " . $mysqli->error);
        }
        
        return $mysqli;
    }
    
    /**
     * Create a test table for async tests
     */
    static function createTestTable($mysqli, $tableName = 'async_test', $engine = null) {
        if (!$engine) {
            $engine = MYSQL_TEST_ENGINE;
        }
        
        if (!$mysqli->query("DROP TABLE IF EXISTS `{$tableName}`")) {
            throw new Exception("Failed to drop table: " . $mysqli->error);
        }
        
        $sql = "CREATE TABLE `{$tableName}` (
            id INT PRIMARY KEY AUTO_INCREMENT,
            name VARCHAR(255) NOT NULL,
            value TEXT,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE={$engine}";
        
        if (!$mysqli->query($sql)) {
            throw new Exception("Failed to create table: " . $mysqli->error);
        }
        
        // Insert test data
        $sql = "INSERT INTO `{$tableName}` (name, value) VALUES 
            ('test1', 'value1'),
            ('test2', 'value2'),
            ('test3', 'value3'),
            ('test4', 'value4'),
            ('test5', 'value5')";
        
        if (!$mysqli->query($sql)) {
            throw new Exception("Failed to insert test data: " . $mysqli->error);
        }
        
        return $tableName;
    }
    
    /**
     * Clean up test tables
     */
    static function cleanupTestTable($mysqli, $tableName = 'async_test') {
        $mysqli->query("DROP TABLE IF EXISTS `{$tableName}`");
    }
    
    /**
     * Check if MySQL server is available
     */
    static function skip() {
        try {
            // Connect without database first to test server availability
            $mysqli = self::factoryWithoutDB();
            if (!$mysqli->query("SELECT 1")) {
                throw new Exception("Query failed");
            }
            $mysqli->close();
        } catch (Exception $e) {
            die('skip MySQL server not available or configuration invalid: ' . $e->getMessage());
        }
    }
    
    /**
     * Check if async extension is loaded
     */
    static function skipIfNoAsync() {
        // No need to check for async extension - it's always available in this context
    }
    
    /**
     * Check if MySQLi is available
     */
    static function skipIfNoMySQLi() {
        if (!extension_loaded('mysqli')) {
            die('skip mysqli extension not loaded');
        }
    }
    
    /**
     * Skip if MySQL version is less than required
     */
    static function skipIfMySQLVersionLess($requiredVersion) {
        $mysqli = self::factoryWithoutDB();
        $result = $mysqli->query("SELECT VERSION() as version");
        $row = $result->fetch_assoc();
        $result->free();
        
        if (version_compare($row['version'], $requiredVersion, '<')) {
            die("skip MySQL version {$requiredVersion} or higher required, found {$row['version']}");
        }
        
        $mysqli->close();
    }
    
    /**
     * Get MySQL version
     */
    static function getMySQLVersion($mysqli = null) {
        if (!$mysqli) {
            $mysqli = self::factoryWithoutDB();
            $shouldClose = true;
        }
        
        $result = $mysqli->query("SELECT VERSION() as version");
        $row = $result->fetch_assoc();
        $result->free();
        
        if (isset($shouldClose)) {
            $mysqli->close();
        }
        
        return $row['version'];
    }
    
    /**
     * Run concurrent async tests without depending on execution order
     */
    static function runConcurrentTest($coroutines, $assertCallback = null) {
        $mysqli = self::initDatabase();
        
        try {
            $results = awaitAllOrFail($coroutines);
            
            if ($assertCallback) {
                $assertCallback($results);
            }
            
            return $results;
            
        } catch (Exception $e) {
            throw $e;
        } finally {
            if ($mysqli) {
                $mysqli->close();
            }
        }
    }
    
    /**
     * Run async test with proper setup and cleanup
     */
    static function runAsyncTest($testCallback, $tableName = 'async_test', $setupCallback = null, $cleanupCallback = null) {
        $mysqli = self::initDatabase();
        
        try {
            // Setup
            if ($setupCallback) {
                $setupCallback($mysqli);
            } else {
                self::createTestTable($mysqli, $tableName);
            }
            
            // Run test in coroutine
            $coroutine = spawn(function() use ($testCallback, $mysqli, $tableName) {
                return $testCallback($mysqli, $tableName);
            });
            
            $result = await($coroutine);
            
            // Cleanup
            if ($cleanupCallback) {
                $cleanupCallback($mysqli);
            } else {
                self::cleanupTestTable($mysqli, $tableName);
            }
            
            return $result;
            
        } catch (Exception $e) {
            // Ensure cleanup on error
            if ($cleanupCallback) {
                $cleanupCallback($mysqli);
            } else {
                self::cleanupTestTable($mysqli, $tableName);
            }
            throw $e;
        } finally {
            if ($mysqli) {
                $mysqli->close();
            }
        }
    }
}
?>