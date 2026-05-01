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
            
            case "GetAllFlights":
                getAllFlights($data, $conn);
                break;

            case "GetFlight":
                getFlight($data, $conn);
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

    //================================== GETALLFLIGHTS SECTION =====================================

    function getAllFlights($input, $db) {
        $apiKey = $input['api_key'] ?? null;
        
        //here we authenticate the user
        $user = authenticate($db, $apiKey);
        
        if (!$user) {
            respond("error", "Authentication failed", null, 401);//the error message that is returned if the authentication fails
        }
        
        $role = $user['type'];
        $userId = $user['id'];
        
        if ($role === 'ATC') {
            //getting all the flights
            $query = "
                SELECT 
                    f.id,
                    f.flight_number,
                    f.departure_time,
                    f.flight_duration_hours,
                    f.status,
                    f.current_latitude,
                    f.current_longitude,
                    f.dispatched_at,
                    origin.id as origin_id,
                    origin.name as origin_name,
                    origin.iata_code as origin_iata,
                    origin.city as origin_city,
                    origin.country as origin_country,
                    origin.latitude as origin_latitude,
                    origin.longitude as origin_longitude,
                    dest.id as dest_id,
                    dest.name as dest_name,
                    dest.iata_code as dest_iata,
                    dest.city as dest_city,
                    dest.country as dest_country,
                    dest.latitude as dest_latitude,
                    dest.longitude as dest_longitude
                FROM flights f
                INNER JOIN airports origin ON f.origin_airport_id = origin.id
                INNER JOIN airports dest ON f.destination_airport_id = dest.id
                ORDER BY f.departure_time ASC
            ";
            $stmt = $db->prepare($query);
            
        } else if ($role === 'Passenger') {
            //rememeber, the passenger should only see the flights they are booked on
            $query = "
                SELECT 
                    f.id,
                    f.flight_number,
                    f.departure_time,
                    f.flight_duration_hours,
                    f.status,
                    f.current_latitude,
                    f.current_longitude,
                    f.dispatched_at,
                    pf.seat_number,
                    pf.boarding_confirmed,
                    pf.confirmed_at,
                    origin.id as origin_id,
                    origin.name as origin_name,
                    origin.iata_code as origin_iata,
                    origin.city as origin_city,
                    origin.country as origin_country,
                    origin.latitude as origin_latitude,
                    origin.longitude as origin_longitude,
                    dest.id as dest_id,
                    dest.name as dest_name,
                    dest.iata_code as dest_iata,
                    dest.city as dest_city,
                    dest.country as dest_country,
                    dest.latitude as dest_latitude,
                    dest.longitude as dest_longitude
                FROM flights f
                INNER JOIN airports origin ON f.origin_airport_id = origin.id
                INNER JOIN airports dest ON f.destination_airport_id = dest.id
                INNER JOIN passenger_flights pf ON f.id = pf.flight_id
                WHERE pf.passenger_id = ?
                ORDER BY f.departure_time ASC
            ";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $userId);
            
        } else {
            respond("error", "Unauthorized role", null, 403);
        }
        
        if (!$stmt) {
            respond("error", "Database query failed", null, 500);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            respond("success", "No flights found", []);
        }
        
        $flights = [];
        while ($row = $result->fetch_assoc()) {
            //building the flight object
            $flight = [
                'id' => $row['id'],
                'flight_number' => $row['flight_number'],
                'departure_time' => $row['departure_time'],
                'flight_duration_hours' => (float)$row['flight_duration_hours'],
                'status' => $row['status'],
                'current_position' => [
                    'latitude' => (float)$row['current_latitude'],
                    'longitude' => (float)$row['current_longitude']
                ],
                'origin' => [
                    'id' => $row['origin_id'],
                    'name' => $row['origin_name'],
                    'iata_code' => $row['origin_iata'],
                    'city' => $row['origin_city'],
                    'country' => $row['origin_country'],
                    'coordinates' => [
                        'latitude' => (float)$row['origin_latitude'],
                        'longitude' => (float)$row['origin_longitude']
                    ]
                ],
                'destination' => [
                    'id' => $row['dest_id'],
                    'name' => $row['dest_name'],
                    'iata_code' => $row['dest_iata'],
                    'city' => $row['dest_city'],
                    'country' => $row['dest_country'],
                    'coordinates' => [
                        'latitude' => (float)$row['dest_latitude'],
                        'longitude' => (float)$row['dest_longitude']
                    ]
                ]
            ];
            
            //adding dispatched_at if it exists (only for dispatched flights)
            if ($row['dispatched_at'] !== null) {
                $flight['dispatched_at'] = $row['dispatched_at'];
            }
            
            //adding the passenger-specific fields if role is Passenger
            if ($role === 'Passenger') {
                $flight['booking_details'] = [
                    'seat_number' => $row['seat_number'],
                    'boarding_confirmed' => (bool)$row['boarding_confirmed'],
                    'confirmed_at' => $row['confirmed_at']
                ];
            }
            
            $flights[] = $flight;
        }
        
        respond("success", "Flights retrieved successfully", [
            'role' => $role,
            'count' => count($flights),
            'flights' => $flights
        ]);
    }

    //======================== GETFLIGHT CODE ====================================

    function getFlight($input, $db) {

        $apiKey = $input['api_key'] ?? null;
        $flightId = $input['flight_id'] ?? null;
        
        //validating the required fields
        if (!$flightId) {
            respond("error", "Missing flight_id parameter", null, 400);
        }
        
        //authenticating the user
        $user = authenticate($db, $apiKey);
        
        if (!$user) {
            respond("error", "Authentication failed", null, 401);
        }
        
        $role = $user['type'];
        $userId = $user['id'];
        
        //we first have to check if flight exists and get basic details
        if ($role === 'ATC') {
            //the ATC will then get the full flight details with passenger list
            $query = "
                SELECT 
                    f.id,
                    f.flight_number,
                    f.departure_time,
                    f.flight_duration_hours,
                    f.status,
                    f.current_latitude,
                    f.current_longitude,
                    f.dispatched_at,
                    origin.id as origin_id,
                    origin.name as origin_name,
                    origin.iata_code as origin_iata,
                    origin.city as origin_city,
                    origin.country as origin_country,
                    origin.latitude as origin_latitude,
                    origin.longitude as origin_longitude,
                    dest.id as dest_id,
                    dest.name as dest_name,
                    dest.iata_code as dest_iata,
                    dest.city as dest_city,
                    dest.country as dest_country,
                    dest.latitude as dest_latitude,
                    dest.longitude as dest_longitude
                FROM flights f
                INNER JOIN airports origin ON f.origin_airport_id = origin.id
                INNER JOIN airports dest ON f.destination_airport_id = dest.id
                WHERE f.id = ?
            ";
            $stmt = $db->prepare($query);
            $stmt->bind_param("i", $flightId);
            
        } else if ($role === 'Passenger') {
            //remember: the passenger has to get the flight details ONLY if they are booked on it; not any other flight
            $query = "
                SELECT 
                    f.id,
                    f.flight_number,
                    f.departure_time,
                    f.flight_duration_hours,
                    f.status,
                    f.current_latitude,
                    f.current_longitude,
                    f.dispatched_at,
                    pf.seat_number,
                    pf.boarding_confirmed,
                    pf.confirmed_at,
                    origin.id as origin_id,
                    origin.name as origin_name,
                    origin.iata_code as origin_iata,
                    origin.city as origin_city,
                    origin.country as origin_country,
                    origin.latitude as origin_latitude,
                    origin.longitude as origin_longitude,
                    dest.id as dest_id,
                    dest.name as dest_name,
                    dest.iata_code as dest_iata,
                    dest.city as dest_city,
                    dest.country as dest_country,
                    dest.latitude as dest_latitude,
                    dest.longitude as dest_longitude
                FROM flights f
                INNER JOIN airports origin ON f.origin_airport_id = origin.id
                INNER JOIN airports dest ON f.destination_airport_id = dest.id
                INNER JOIN passenger_flights pf ON f.id = pf.flight_id
                WHERE f.id = ? AND pf.passenger_id = ?
            ";
            $stmt = $db->prepare($query);
            $stmt->bind_param("ii", $flightId, $userId);
            
        } else {
            respond("error", "Unauthorized role", null, 403);
        }
        
        if (!$stmt) {
            respond("error", "Database query failed", null, 500);
        }
        
        $stmt->execute();
        $result = $stmt->get_result();
        
        //check if flight exists and if user has access
        if ($result->num_rows === 0) {
            if ($role === 'Passenger') {
                respond("error", "Flight not found or you are not booked on this flight", null, 404);
            } else {
                respond("error", "Flight not found", null, 404);
            }
        }
        
        $row = $result->fetch_assoc();
        
        //build the flight object (same structure for both roles)
        $flight = [
            'id' => $row['id'],
            'flight_number' => $row['flight_number'],
            'departure_time' => $row['departure_time'],
            'flight_duration_hours' => (float)$row['flight_duration_hours'],
            'status' => $row['status'],
            'current_position' => [
                'latitude' => (float)$row['current_latitude'],
                'longitude' => (float)$row['current_longitude']
            ],
            'origin' => [
                'id' => $row['origin_id'],
                'name' => $row['origin_name'],
                'iata_code' => $row['origin_iata'],
                'city' => $row['origin_city'],
                'country' => $row['origin_country'],
                'coordinates' => [
                    'latitude' => (float)$row['origin_latitude'],
                    'longitude' => (float)$row['origin_longitude']
                ]
            ],
            'destination' => [
                'id' => $row['dest_id'],
                'name' => $row['dest_name'],
                'iata_code' => $row['dest_iata'],
                'city' => $row['dest_city'],
                'country' => $row['dest_country'],
                'coordinates' => [
                    'latitude' => (float)$row['dest_latitude'],
                    'longitude' => (float)$row['dest_longitude']
                ]
            ]
        ];
        
        //add the dispatched_at if it exists
        if ($row['dispatched_at'] !== null) {
            $flight['dispatched_at'] = $row['dispatched_at'];
        }
        
        //add passenger-specific fields if role is Passenger
        if ($role === 'Passenger') {
            $flight['booking_details'] = [
                'seat_number' => $row['seat_number'],
                'boarding_confirmed' => (bool)$row['boarding_confirmed'],
                'confirmed_at' => $row['confirmed_at']
            ];
        }
        
        //**FOR ATC ONLY: Get the passenger list**
        if ($role === 'ATC') {
            $passengerQuery = "
                SELECT 
                    u.id,
                    u.username,
                    u.email,
                    pf.seat_number,
                    pf.boarding_confirmed,
                    pf.confirmed_at
                FROM passenger_flights pf
                INNER JOIN users u ON pf.passenger_id = u.id
                WHERE pf.flight_id = ?
                ORDER BY u.username ASC
            ";
            
            $passengerStmt = $db->prepare($passengerQuery);
            $passengerStmt->bind_param("i", $flightId);
            $passengerStmt->execute();
            $passengerResult = $passengerStmt->get_result();
            
            $passengers = [];
            while ($passengerRow = $passengerResult->fetch_assoc()) {
                $passengers[] = [
                    'id' => $passengerRow['id'],
                    'username' => $passengerRow['username'],
                    'email' => $passengerRow['email'],
                    'seat_number' => $passengerRow['seat_number'],
                    'boarding_confirmed' => (bool)$passengerRow['boarding_confirmed'],
                    'confirmed_at' => $passengerRow['confirmed_at']
                ];
            }
            
            $flight['passengers'] = $passengers;
            $flight['passenger_count'] = count($passengers);
        }
        
        respond("success", "Flight retrieved successfully", $flight);
    }

    
?>
