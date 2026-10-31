<?php
require_once __DIR__ . '/db.php';

/**
 * PhilSMS Helper Function
 * Sends an SMS using the PhilSMS API v3
 */
function sendSMS($recipient, $message) {
    // Check if CURL is enabled
    if (!function_exists('curl_init')) {
        error_log("PhilSMS Error: CURL extension is not enabled in PHP.");
        return false;
    }

    // Ensure recipient is in correct format (starts with 63)
    $recipient = preg_replace('/[^0-9]/', '', $recipient);
    if (strpos($recipient, '0') === 0) {
        $recipient = '63' . substr($recipient, 1);
    } elseif (strpos($recipient, '9') === 0) {
        $recipient = '63' . $recipient;
    }

    // Append footer
    $full_message = $message . SMS_FOOTER;

    $url = "https://dashboard.philsms.com/api/v3/sms/send";
    
    $data = [
        "recipient" => $recipient,
        "sender_id" => SMS_SENDER,
        "type"      => "plain",
        "message"   => $full_message
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . SMS_TOKEN,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local testing if SSL certificate issues occur

    $response = curl_exec($ch);
    $err = curl_error($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($err) {
        error_log("PhilSMS CURL Error: " . $err);
        return false;
    }

    $result = json_decode($response, true);
    if ($http_code == 200 && isset($result['status']) && ($result['status'] == 'success' || $result['status'] == 'Delivered')) {
        return true;
    } else {
        error_log("PhilSMS API Failure (HTTP $http_code): " . $response);
        return false;
    }
}
