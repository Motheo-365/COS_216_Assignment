<?php
    error_reporting(E_ALL);
    ini_set('display_errors', 1);

    header('Content-Type: application/json'); //Force API to always return JSON
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, GET, OPTIONS'); //ALlow HTTP methods used by API
    header('Access-Control-Allow-Headers: Content-Type, Authorization'); //ALlow header like APIkeys

    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }

    // ============================ Database CONNECTION ============================
        include __DIR__ . '/config.php'; //Load database connection fomr config.php

        //Ensure database connection exists before continuing
        if (!isset($configure)) {
            http_response_code(500);
            exit(json_encode([
                'status' => 'error',
                'message' => 'Database connection not found'
            ]));
        }

    $rawBody = file_get_contents("php://input"); //Read raw JSON body sent by client
    $data = json_decode($rawBody, true); //COnvert JSON into PHP array

    if (!is_array($data)) {
        $data = $_POST;
    }

    $type = $data['type'] ?? null; // ** IMPORTANT*** Extract Request Type (used for routing API calls)

    if (!$type) {
        http_response_code(400);
        exit(json_encode([
            'status' => 'error',
            'message' => 'Missing request type'
        ]));
    }

    // ============================ RESPONSE HELPER ============================
        
        //Standard function to return consistent JSON reponses
        function respond($status, $message = null, $data = null, $code = 200) {
            http_response_code($code);

            $res = [
                "status" => $status,
                "timestamp" => round(microtime(true) * 1000)
            ];

            if ($message) $res["message"] = $message;
            if ($data !== null) $res["data"] = $data;

            exit(json_encode($res));
        }

        //Validates API key against database users table
        //NOTE: Ussed by other endpoints in full system (ATC / Passenger access control)
        function authenticate($db, $apiKey) {
            if (!$apiKey) {
                respond("error", "Missing API key", null, 401);
            }

            $stmt = $db->prepare("SELECT id, type FROM users WHERE api_key = ?");
            $stmt->bind_param("s", $apiKey);
            $stmt->execute();

            $result = $stmt->get_result();

            if ($result->num_rows === 0) {
                respond("error", "Invalid API key", null, 403);
            }

            return $result->fetch_assoc();
        }

    // ============================ ROUTER ============================
        //Directs requests to correct function based on "type"
        switch ($type) {
            case "UpdateFlightPosition":
                updateFlightPosition($data, $configure);
                break;

            default:
                respond("error", "Unknown endpoint", null, 400);
        }

    // ============================ PERSON 3 FUNCTIONS ============================

        // LIVE FLIGHT TRACKING
        //Handles real-time flight movement updates from simulation
        function updateFlightPosition($data, $db) {
            $flightId = $data['flight_id'] ?? null;

            //New GPS coordinates
                $lat = $data['latitude'] ?? null;
                $lng = $data['longitude'] ?? null;

            $status = $data['status'] ?? null; //Flight status

            if (!$flightId || $lat === null || $lng === null) {
                respond("error", "Missing required fields", null, 400);
            }

            // Only allow predefined flight statuses (prevents invalid data corruption)
            $validStatuses = ["Scheduled", "Boarding", "In Flight", "Landed"];

            if ($status && !in_array($status, $validStatuses)) {
                respond("error", "Invalid status", null, 400);
            }

             // If status is provided → update everything (lat, lng, status)
            if ($status) {
                $stmt = $db->prepare("
                    UPDATE flights 
                    SET current_latitude = ?, current_longitude = ?, status = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("ddsi", $lat, $lng, $status, $flightId);
            } 
            //Otherwise only update position(lat/long)
            else {
                $stmt = $db->prepare("
                    UPDATE flights 
                    SET current_latitude = ?, current_longitude = ?
                    WHERE id = ?
                ");
                $stmt->bind_param("ddi", $lat, $lng, $flightId);
            }

            //Confirm if update was success or not
            if ($stmt->execute()) {
                respond("success", "Flight position updated");
            } 
            
            else {
                respond("error", "Update failed");
            }
        }
?>