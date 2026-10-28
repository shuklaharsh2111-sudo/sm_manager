<?php
mysqli_report(MYSQLI_REPORT_OFF);
// Database credentials
$host = "127.0.0.1";
$username = "root";
$password = "";
$database = "creative_manager";
// Create MySQLi connection
$conn = new mysqli(
    $host,
    $username,
    $password,
    $database
);
// Check connection
if ($conn->connect_error) {
    error_log("Database connection failed: " . $conn->connect_error);
    http_response_code(500);
    exit("Unable to connect to the database. Please try again later.");
}
// Set character encoding
if (!$conn->set_charset("utf8mb4")) {
    error_log("Failed to set database charset: " . $conn->error);
    http_response_code(500);
    exit("Database configuration error.");
}
