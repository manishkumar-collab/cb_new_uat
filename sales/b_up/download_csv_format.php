<?php
// Include configuration file
require_once '../../config.php';

// Set headers
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="sales_requests_format.csv"');

// Open output buffer
 $output = fopen('php://output', 'w');

// Write CSV headers
 $headers = [
    'user_id',
    'date',
    'quotation_number',
    'ccs_lead_id',
    'name',
    'mobile_no',
    'vehicle_number',
    'rm_name',
    'leader_name',
    'premium',
    'premium_wo_gst',
    'multi_single',
    'wheeler',
    'city',
    'state',
    'cc',
    'register_year',
    'vehicle_age',
    'tp_status',
    'tp_premium',
    'odsy',
    'odmy',
    'category',
    'fuel_type',
    'make',
    'model',
    'insurance_company',
    'deal_type',
    'payment_screenshot_attached',
    'remarks'
];

fputcsv($output, $headers);

// Write sample data
 $sampleData = [
    '41', // user_id
    '2026-01-27', // date
    'Q753726',
    'L73878249',
    'Manish Kushwaha',
    '07827942272',
    'DL7CV0121',
    'Vikas',
    'Shubham',
    '118.00',
    '100.00',
    'Multi',
    '2',
    'Gurgaon',
    'Haryana',
    '180',
    '2023',
    '0',
    'N/A',
    '',
    '',
    '',
    'Bike',
    'Petrol',
    '',
    '',
    'CoverYou',
    'Third Party',
    'Yes',
    'ok'
];

fputcsv($output, $sampleData);

// Write another sample data (for 4-wheeler)
 $sampleData2 = [
    '22', // user_id
    '2025-12-15', // date
    'Q753727',
    'L73878250',
    'Rahul Sharma',
    '09876543210',
    'DL4AB1234',
    'Amit',
    'Sunil',
    '15234.50',
    '12918.64',
    'Single',
    '4',
    'Delhi',
    'Delhi',
    '120',
    '2022',
    '1',
    'Active',
    '3200.00',
    '12345',
    '67890',
    'Car',
    'Diesel',
    'Maruti',
    'Swift',
    'CoverYou',
    'Comprehensive',
    'No',
    'Regular customer'
];

fputcsv($output, $sampleData2);

// Close output buffer
fclose($output);

// End script
exit;
?>