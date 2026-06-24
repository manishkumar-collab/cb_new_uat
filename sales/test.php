<?php
// Error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Test file is working<br>";

// Check if config file exists
 $configPath = '../config.php';
if (file_exists($configPath)) {
    echo "Config file found at: " . $configPath . "<br>";
    
    // Try to include config file
    try {
        include_once $configPath;
        echo "Config file included successfully<br>";
        
        // Check if database connection variables are defined
        if (isset($servername) && isset($username) && isset($password) && isset($dbname)) {
            echo "Database connection variables found<br>";
            
            // Try to connect to database
            $conn = new mysqli($servername, $username, $password, $dbname);
            if ($conn->connect_error) {
                echo "Database connection failed: " . $conn->connect_error . "<br>";
            } else {
                echo "Database connection successful<br>";
                $conn->close();
            }
        } else {
            echo "Database connection variables not found in config file<br>";
        }
    } catch (Exception $e) {
        echo "Error including config file: " . $e->getMessage() . "<br>";
    }
} else {
    echo "Config file not found at: " . $configPath . "<br>";
}

// Display PHP version
echo "PHP Version: " . phpversion() . "<br>";
?>