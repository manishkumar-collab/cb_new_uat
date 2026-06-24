<?php
// /var/www/html/cb_new_uat/sales/debug.php

// Error Reporting ON - Yeh sabhi errors browser mein dikhayega
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "<h1>Debugging Tool</h1>";

// Test 1: Check basic PHP
echo "<h2>Test 1: Basic PHP Working</h2>";
echo "If you can see this, PHP is working fine.";

// Test 2: Check Database Connection
echo "<h2>Test 2: Database Connection</h2>";
try {
    include_once '../config.php';
    if (isset($conn) && $conn->ping()) {
        echo "✅ Database connection successful.";
    } else {
        echo "❌ Database connection FAILED. Check config.php file path and credentials.";
    }
} catch (Exception $e) {
    echo "❌ Database connection FAILED with error: " . $e->getMessage();
}

// Test 3: Check functions.php
echo "<h2>Test 3: functions.php File</h2>";
try {
    include_once 'functions.php';
    if (function_exists('sanitize_input')) {
        echo "✅ functions.php included and sanitize_input function exists.";
    } else {
        echo "❌ functions.php included but sanitize_input function NOT found.";
    }
} catch (Exception $e) {
    echo "❌ Error including functions.php: " . $e->getMessage();
}

// Test 4: Check Session
echo "<h2>Test 4: Session</h2>";
session_start();
if (isset($_SESSION['user_id'])) {
    echo "✅ Session is active for user ID: " . $_SESSION['user_id'];
} else {
    echo "❌ No active session found. Please login first.";
}

echo "<hr>";
echo "<h2>Next Step: Copy-Paste Your Code</h2>";
echo "Agar upar ke sabhi tests ✅ pass hain, toh problem aapke code mein hai.<br>";
echo "Ab apne <b>process_edit_sale.php</b> file ka code neeche box mein copy karein aur submit button par click karein.";

?>
<form action="" method="post">
    <h3>Paste your process_edit_sale.php code here:</h3>
    <textarea name="code_to_test" rows="20" style="width:100%; font-family:monospace;"><?php
// Yahan apne process_edit_sale.php ka code paste karein
// Example:
/*
include_once 'functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['is_resubmission'])) {
    // ... aapka code yahan
}
*/
?></textarea>
    <br>
    <button type="submit">Test My Code</button>
</form>

<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['code_to_test'])) {
    echo "<h2>Code Execution Result:</h2>";
    echo "<pre style='background:#f0f0f0;padding:10px;border:1px solid #ccc;'>";
    eval($_POST['code_to_test']);
    echo "</pre>";
}
?>