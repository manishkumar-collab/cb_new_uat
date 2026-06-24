<?php
// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include configuration file
require_once '../../config.php';

// Set headers
header('Content-Type: application/json');

// Initialize response
 $response = [
    'success' => false,
    'message' => '',
    'data' => [],
    'debug_info' => []
];

try {
    // Check if file was uploaded
    if (!isset($_FILES['csvFile']) || $_FILES['csvFile']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload error. Please try again. Error code: ' . $_FILES['csvFile']['error']);
    }
    
    // Check file type
    $fileInfo = pathinfo($_FILES['csvFile']['name']);
    if (strtolower($fileInfo['extension']) !== 'csv') {
        throw new Exception('Please upload only CSV files.');
    }
    
    // Open CSV file
    $csvFile = $_FILES['csvFile']['tmp_name'];
    $handle = fopen($csvFile, 'r');
    
    if (!$handle) {
        throw new Exception('Error opening CSV file.');
    }
    
    // Read and trim headers
    $headers = fgetcsv($handle, 1000, ",");
    if ($headers === false) {
        throw new Exception('Could not read headers from CSV file. Is the file empty?');
    }
    $headers = array_map('trim', $headers);
    $response['debug_info']['headers_found'] = $headers;

    // Required headers (case-insensitive)
    $requiredHeaders = [
        'user_id', 'date', 'quotation_number', 'ccs_lead_id', 'name', 'mobile_no', 'vehicle_number', 
        'rm_name', 'leader_name', 'premium', 'premium_wo_gst', 'multi_single', 
        'wheeler', 'city', 'state', 'cc', 'register_year', 'vehicle_age', 
        'tp_status', 'tp_premium', 'odsy', 'odmy', 'category', 'fuel_type', 
        'make', 'model', 'insurance_company', 'deal_type', 
        'payment_screenshot_attached', 'remarks'
    ];
    
    // Create header index map (case-insensitive)
    $headerMap = [];
    $headersLower = array_map('strtolower', $headers);
    foreach ($requiredHeaders as $requiredHeader) {
        $index = array_search(strtolower($requiredHeader), $headersLower);
        if ($index === false) {
            throw new Exception("Required column missing in CSV file: '$requiredHeader'");
        }
        $headerMap[$requiredHeader] = $index;
    }
    $response['debug_info']['header_map'] = $headerMap;

    // Begin transaction
    mysqli_begin_transaction($conn);
    
    $successCount = 0;
    $errorCount = 0;
    $errors = [];
    $rowNumber = 2; // Start after header
    
    // Cache user manager and head mapping
    $userManagerHeadMap = [];
    
    // Process each row
    while (($data = fgetcsv($handle, 1000, ",")) !== FALSE) {
        // Skip empty rows
        if (count(array_filter($data, function($value) { return $value !== null && $value !== ''; })) === 0) {
            continue;
        }

        try {
            // Extract user ID
            $userId = intval(trim($data[$headerMap['user_id']]));
            
            // Validate user
            if (empty($userId)) {
                throw new Exception("user_id is required and must be a valid number.");
            }
            
            // Get user info if not in cache
            if (!isset($userManagerHeadMap[$userId])) {
                $userQuery = "SELECT id, manager_id, head_id FROM users WHERE id = $userId";
                $userResult = mysqli_query($conn, $userQuery);
                
                if (!$userResult || mysqli_num_rows($userResult) === 0) {
                    throw new Exception("No user found with ID $userId.");
                }
                
                $userData = mysqli_fetch_assoc($userResult);
                $userManagerHeadMap[$userId] = [
                    'manager_id' => $userData['manager_id'],
                    'head_id' => $userData['head_id']
                ];
            }
            
            // Extract and validate date
            $dateValue = trim($data[$headerMap['date']]);
            if (empty($dateValue)) {
                throw new Exception("date is required.");
            }
            
            // Handle different date formats (YYYY-MM-DD or YYYY/MM/DD)
            $dateValue = str_replace('/', '-', $dateValue);
            
            // Validate date format (YYYY-MM-DD)
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
                throw new Exception("date format must be YYYY-MM-DD, found: '$dateValue'");
            }
            
            // Convert to valid date
            $dateArray = explode('-', $dateValue);
            if (!checkdate($dateArray[1], $dateArray[2], $dateArray[0])) {
                throw new Exception("date is not a valid date: '$dateValue'");
            }
            $date = mysqli_real_escape_string($conn, $dateValue);
            
            // Extract and secure data
            $quotationNumber = mysqli_real_escape_string($conn, trim($data[$headerMap['quotation_number']]));
            $ccsLeadId = mysqli_real_escape_string($conn, trim($data[$headerMap['ccs_lead_id']]));
            $name = mysqli_real_escape_string($conn, trim($data[$headerMap['name']]));
            $mobileNo = mysqli_real_escape_string($conn, trim($data[$headerMap['mobile_no']]));
            $vehicleNumber = mysqli_real_escape_string($conn, trim($data[$headerMap['vehicle_number']]));
            $rmName = mysqli_real_escape_string($conn, trim($data[$headerMap['rm_name']]));
            
            $leaderNameVal = trim($data[$headerMap['leader_name']]);
            $leaderName = !empty($leaderNameVal) ? "'" . mysqli_real_escape_string($conn, $leaderNameVal) . "'" : "NULL";
            
            $premium = floatval(trim($data[$headerMap['premium']]));
            $premiumWoGst = floatval(trim($data[$headerMap['premium_wo_gst']]));
            $multiSingle = mysqli_real_escape_string($conn, trim($data[$headerMap['multi_single']]));
            $wheeler = mysqli_real_escape_string($conn, trim($data[$headerMap['wheeler']]));
            $city = mysqli_real_escape_string($conn, trim($data[$headerMap['city']]));
            $state = mysqli_real_escape_string($conn, trim($data[$headerMap['state']]));
            $cc = mysqli_real_escape_string($conn, trim($data[$headerMap['cc']]));
            $registerYear = intval(trim($data[$headerMap['register_year']]));
            $vehicleAge = intval(trim($data[$headerMap['vehicle_age']]));
            $tpStatus = mysqli_real_escape_string($conn, trim($data[$headerMap['tp_status']]));
            
            $tpPremiumVal = trim($data[$headerMap['tp_premium']]);
            $tpPremium = !empty($tpPremiumVal) ? floatval($tpPremiumVal) : "NULL";
            
            $odsyVal = trim($data[$headerMap['odsy']]);
            $odsy = !empty($odsyVal) ? "'" . mysqli_real_escape_string($conn, $odsyVal) . "'" : "NULL";
            
            $odmyVal = trim($data[$headerMap['odmy']]);
            $odmy = !empty($odmyVal) ? "'" . mysqli_real_escape_string($conn, $odmyVal) . "'" : "NULL";
            
            $category = mysqli_real_escape_string($conn, trim($data[$headerMap['category']]));
            $fuelType = mysqli_real_escape_string($conn, trim($data[$headerMap['fuel_type']]));
            $make = mysqli_real_escape_string($conn, trim($data[$headerMap['make']]));
            $model = mysqli_real_escape_string($conn, trim($data[$headerMap['model']]));
            $insuranceCompany = mysqli_real_escape_string($conn, trim($data[$headerMap['insurance_company']]));
            $dealType = mysqli_real_escape_string($conn, trim($data[$headerMap['deal_type']]));
            $paymentScreenshotAttached = mysqli_real_escape_string($conn, trim($data[$headerMap['payment_screenshot_attached']]));
            $remarks = mysqli_real_escape_string($conn, trim($data[$headerMap['remarks']]));
            
            // Validation
            if (!in_array($multiSingle, ['Multi', 'Single'])) {
                throw new Exception("multi_single can only be 'Multi' or 'Single', found: '$multiSingle'");
            }
            
            if (!in_array($wheeler, ['2', '4'])) {
                throw new Exception("wheeler can only be '2' or '4', found: '$wheeler'");
            }
            
            if (!in_array($paymentScreenshotAttached, ['Yes', 'No'])) {
                throw new Exception("payment_screenshot_attached can only be 'Yes' or 'No', found: '$paymentScreenshotAttached'");
            }
            
            // Generate unique code and reference number
            $uniqueCode = "SALE-{$userId}-" . date('YmdHis') . mt_rand(100, 999);
            $referenceNumber = "SA-" . date('ymd') . "-" . strtoupper(substr(md5(uniqid()), 0, 12));
            
            // Get manager and head IDs
            $managerId = $userManagerHeadMap[$userId]['manager_id'] ? $userManagerHeadMap[$userId]['manager_id'] : "NULL";
            $headId = $userManagerHeadMap[$userId]['head_id'] ? $userManagerHeadMap[$userId]['head_id'] : "NULL";
            
            // Insert into database
            $insertQuery = "INSERT INTO sales_requests (
                reference_number, user_id, manager_id, head_id, unique_code, date, quotation_number, ccs_lead_id, 
                name, mobile_no, vehicle_number, rm_name, leader_name, premium, premium_wo_gst, 
                multi_single, wheeler, city, state, cc, register_year, vehicle_age, tp_status, 
                tp_premium, odsy, odmy, category, fuel_type, make, model, insurance_company, 
                deal_type, payment_screenshot_attached, remarks, status, created_at, updated_at
            ) VALUES (
                '$referenceNumber', $userId, $managerId, $headId, '$uniqueCode', '$date', '$quotationNumber', '$ccsLeadId', 
                '$name', '$mobileNo', '$vehicleNumber', '$rmName', $leaderName, $premium, $premiumWoGst, 
                '$multiSingle', '$wheeler', '$city', '$state', '$cc', $registerYear, $vehicleAge, '$tpStatus', 
                $tpPremium, $odsy, $odmy, '$category', '$fuelType', '$make', '$model', '$insuranceCompany', 
                '$dealType', '$paymentScreenshotAttached', '$remarks', 'Pending', '$date 00:00:00', '$date 00:00:00'
            )";
            
            if (!mysqli_query($conn, $insertQuery)) {
                // Add SQL error to debug info
                $response['debug_info']['sql_error_row_' . $rowNumber] = [
                    'query' => $insertQuery,
                    'error' => mysqli_error($conn)
                ];
                throw new Exception("Database error: " . mysqli_error($conn));
            }
            
            $successCount++;
        } catch (Exception $e) {
            $errorCount++;
            $errors[] = "Row $rowNumber: " . $e->getMessage();
        }
        
        $rowNumber++;
    }
    
    // Close file
    fclose($handle);
    
    // Commit transaction
    mysqli_commit($conn);
    
    // Prepare response
    $response['success'] = true;
    $response['message'] = "CSV upload successful. $successCount records added successfully.";
    
    if ($errorCount > 0) {
        $response['message'] .= " $errorCount records had errors.";
        $response['errors'] = $errors; // Detailed errors
    }
    
} catch (Exception $e) {
    // Rollback transaction
    if (isset($conn) && mysqli_ping($conn)) {
        mysqli_rollback($conn);
    }
    
    $response['success'] = false;
    $response['message'] = $e->getMessage();
}

// Send JSON response
echo json_encode($response, JSON_PRETTY_PRINT);
?>