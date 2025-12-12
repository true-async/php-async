#!/usr/bin/env php
<?php
/**
 * Database setup script for async PDO MySQL tests
 * 
 * This script can be run before tests to ensure the database is properly initialized.
 * Usage: php database_setup.php [create|drop|reset]
 */

require_once __DIR__ . '/async_pdo_mysql_test.inc';

function printUsage() {
    echo "Usage: php database_setup.php [create|drop|reset]\n";
    echo "  create - Create test database and tables\n";
    echo "  drop   - Drop test database\n";
    echo "  reset  - Drop and recreate test database\n";
    echo "  help   - Show this help message\n";
}

function createDatabase() {
    echo "Creating test database...\n";
    
    try {
        // Connect without specifying database first
        $pdo = new PDO(
            "mysql:host=" . MYSQL_TEST_HOST . ";port=" . MYSQL_TEST_PORT,
            MYSQL_TEST_USER,
            MYSQL_TEST_PASSWD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        // Create database
        $dbName = MYSQL_TEST_DB;
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        echo "Database '{$dbName}' created successfully.\n";
        
        // Initialize with test schema
        $pdo = AsyncPDOMySQLTest::initDatabase($pdo);
        echo "Test database initialized.\n";
        
    } catch (Exception $e) {
        echo "Error creating database: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function dropDatabase() {
    echo "Dropping test database...\n";
    
    try {
        $pdo = new PDO(
            "mysql:host=" . MYSQL_TEST_HOST . ";port=" . MYSQL_TEST_PORT,
            MYSQL_TEST_USER,
            MYSQL_TEST_PASSWD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $dbName = MYSQL_TEST_DB;
        $pdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
        echo "Database '{$dbName}' dropped successfully.\n";
        
    } catch (Exception $e) {
        echo "Error dropping database: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function resetDatabase() {
    echo "Resetting test database...\n";
    dropDatabase();
    createDatabase();
    echo "Database reset completed.\n";
}

function testConnection() {
    echo "Testing database connection...\n";
    
    try {
        $pdo = AsyncPDOMySQLTest::factory();
        $stmt = $pdo->query("SELECT 1 as test");
        $result = $stmt->fetch();
        
        if ($result['test'] == 1) {
            echo "Connection successful!\n";
            echo "MySQL version: " . AsyncPDOMySQLTest::getMySQLVersion($pdo) . "\n";
        } else {
            echo "Connection test failed.\n";
            exit(1);
        }
        
    } catch (Exception $e) {
        echo "Connection failed: " . $e->getMessage() . "\n";
        exit(1);
    }
}

// Parse command line arguments
$action = $argv[1] ?? 'help';

switch ($action) {
    case 'create':
        createDatabase();
        break;
    case 'drop':
        dropDatabase();
        break;
    case 'reset':
        resetDatabase();
        break;
    case 'test':
        testConnection();
        break;
    case 'help':
    default:
        printUsage();
        break;
}
?>