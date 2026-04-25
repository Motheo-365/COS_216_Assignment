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
        if (!isset($conn)) {
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
                updateFlightPosition($data, $conn);
                break;
            
            case "DispatchFlight":
                dispatchFlight($data,$conn);
                break;

            case "GetAirports":
                getAirports($data,$conn);
                break;
            
            case "BoardFlight":
                boardFlight($data,$conn);
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

        //Transitions a flight from Scheduled to Boarding. ATC only.
        function dispatchFlight($input,$db){
                
               $user_email = $input['email'] ?? null; // type of user it has to be ATC else stop everything
               $flightId = $input['flight_id'] ?? null; //Id of the flight to dispatch 
               
               //check if the type of user is ATC and if the flight id is valid
               if(!$flightId || $user_email == null){
                    respond("error","Missing required fields",null,400);
               }
               $query = "SELECT 1 FROM users WHERE email = ? AND type = 'ATC' "; //check if the user email belongs to an ATC to continue
               $stmt = $db->prepare($query);
               $stmt->bind_param("s",$user_email);
               $stmt->execute();

               $result = $stmt->get_result();

               if($result->num_rows == 0){ // if no result are returned it means the user is not an ATC
                    respond("error","User is not Authorized",null,400);
               }
               
               $query = "SELECT 1 FROM Flights WHERE id = ? AND status = 'Scheduled'"; //check if the status of the flight id is Scheduled if not return an error
               $stmt = $db->prepare($query);
               $stmt->bind_param("i",$flightId);
               $stmt->execute();

               $result = $stmt->get_result();

               if($result->num_rows === 0){
                    respond("error","No such Scheduled Flight with that ID",null,400);
               }
               else{
                    $timestamp = date("Y-m-d H:i:s"); // create a timestamp for the exact date it was executed
                    $new_status = "Boarding"; // new status to update to 
                    $query = "UPDATE flights 
                            SET status = ?, dispatched_at = ?
                            WHERE id = ?";

                    $stmt = $db->prepare($query);
                    $stmt->bind_param("ssi",$new_status,$timestamp,$flightId);
                    
                    if(!$stmt->execute()){
                        respond("error","Failed to update the flight status",null,400);
                    }
                    else{
                        respond("success","Flight has been successfully updated to Boarding",null);
                    }
               }
        }
        
        //Returns the full list of airports with their GPS coordinates used to plot markers on the Leaflet map
        function getAirports($input,$db){
            $query = "SELECT id,name,iata_code,city,country,latitude,longitude FROM Airports";
            $stmt = $db->prepare($query); // prepare the query to be sent to the database
            $stmt->execute(); //runs the query across the Airports table to retrieve the required information

            $result = $stmt->get_result(); // get the results of the query

            if($result->num_rows == 0){
                respond("error","No data matches your request",null,400);
            }

            $row = $result->fetch_all(MYSQLI_ASSOC);
            respond("success","Request has been successful",$row);
            return;
        }

        function boardFlight($input,$db){
            $user_email = $input['email'] ?? null;
            $flightId = $input['flight_id'] ?? null;

            //check if input is not null 
            if(!$user_email || !$flightId){
                respond("error","Missing required fields",null,400);
            }

            //check if the passenger with the email exists as a passenger
            $query = "SELECT id FROM Users WHERE email = ? AND type = 'Passenger'";
            $stmt = $db->prepare($query);
            $stmt->bind_param("s",$user_email);
            $stmt->execute();

            $result = $stmt->get_result();

            if($result->num_rows == 0){
                respond("error","User is not a Passenger",null,400);
            }

            $row = $result->fetch_assoc();
            $user_id = $row['id']; //user ID if they exists

            //verify that the user is part of the boardFlight
            $query = "SELECT 1 FROM Passenger_Flights WHERE passenger_id = ? AND flight_id = ?";
            $stmt = $db->prepare($query);
            $stmt->bind_param("ii",$user_id,$flightId);
            $stmt->execute();

            $result = $stmt->get_result();

            if($result->num_rows == 0){
                respond("error","User is not Registered in the Flight",null,400);
            }

            //check if dispatch exists for the flight look in the flights table 
            $query = "SELECT dispatched_at FROM Flights WHERE id = ? AND dispatched_at IS NOT NULL";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i",$flightId);
            $stmt->execute();

            $result = $stmt->get_result();

            if($result->num_rows == 0){
                respond("error","Flight has not been dispatched yet",null,400);
            }

            $row = $result->fetch_assoc();
            $dispatchAt = $row['dispatched_at'];
            $currentTime = date("Y-m-d H:i:s");

            $dispatchTime = strtotime($dispatchAt);
            $now = time();

            $diff = $now - $dispatchTime;

            if($diff > 60){
                respond("error","Boarding window expired",null,400);
            }

            $num = 1;
            $query = "UPDATE Passenger_Flights
                        SET boarding_confirmed = ?,  confirmed_at= ?
                        WHERE passenger_id = ? AND flight_id = ?";

            $stmt = $db->prepare($query);
            $stmt->bind_param("isii",$num,$currentTime,$user_id,$flightId);

            if(!$stmt->execute()){
                respond("error","Failed to update the Boarding Flight");
            }

            respond("success","Boarding confirmed successfully",null);

        }
?>
