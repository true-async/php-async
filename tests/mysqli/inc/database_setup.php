#!/usr/bin/env php
<?php
/**
 * Database setup script for async MySQLi tests
 * 
 * This script can be run before tests to ensure the database is properly initialized.
 * Usage: php database_setup.php [create|drop|reset]
 */

require_once __DIR__ . '/async_mysqli_test.inc';

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
        $mysqli = new mysqli(
            MYSQL_TEST_HOST,
            MYSQL_TEST_USER,
            MYSQL_TEST_PASSWD,
            null,
            MYSQL_TEST_PORT,
            MYSQL_TEST_SOCKET
        );
        
        if ($mysqli->connect_error) {
            throw new Exception("Connection failed: " . $mysqli->connect_error);
        }
        
        // Create database
        $dbName = MYSQL_TEST_DB;
        if (!$mysqli->query("CREATE DATABASE IF NOT EXISTS `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci")) {
            throw new Exception("Failed to create database: " . $mysqli->error);
        }
        
        echo "Database '{$dbName}' created successfully.\n";
        
        // Initialize with test schema
        $mysqli = AsyncMySQLiTest::initDatabase($mysqli);
        echo "Test database initialized.\n";
        
        $mysqli->close();
        
    } catch (Exception $e) {
        echo "Error creating database: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function dropDatabase() {
    echo "Dropping test database...\n";
    
    try {
        $mysqli = new mysqli(
            MYSQL_TEST_HOST,
            MYSQL_TEST_USER,
            MYSQL_TEST_PASSWD,
            null,
            MYSQL_TEST_PORT,
            MYSQL_TEST_SOCKET
        );
        
        if ($mysqli->connect_error) {
            throw new Exception("Connection failed: " . $mysqli->connect_error);
        }
        
        $dbName = MYSQL_TEST_DB;
        if (!$mysqli->query("DROP DATABASE IF EXISTS `{$dbName}`")) {
            throw new Exception("Failed to drop database: " . $mysqli->error);
        }
        
        echo "Database '{$dbName}' dropped successfully.\n";
        
        $mysqli->close();
        
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
        $mysqli = AsyncMySQLiTest::factory();
        
        $result = $mysqli->query("SELECT 1 as test");
        $row = $result->fetch_assoc();
        $result->free();
        
        if ($row['test'] == 1) {
            echo "Connection successful!\n";
            echo "MySQL version: " . AsyncMySQLiTest::getMySQLVersion($mysqli) . "\n";
        } else {
            echo "Connection test failed.\n";
            exit(1);
        }
        
        $mysqli->close();
        
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