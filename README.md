# COS216 Homework Assignment – PHP + XAMPP Setup
## Overview
   - This project uses **XAMPP (Apache + MySQL)** as a local development environment and **PHP (mysqli)** to interact with a MySQL database.
   - File transfer is handled via **FileZilla**, which maps directly to the XAMPP `htdocs` directory.

## Setup Instructions

    ### 1. Start XAMPP
        Open XAMPP Control Panel and start:
            - Apache
            - MySQL

    ### 2. Access phpMyAdmin (Our Local Database)
        Open in browser:
            http://localhost:8081/phpmyadmin
            

        Database Name:
                cos216_asynconus

    ### 3. Configure Database Connection
        Inside config.php, ensures that MySQL connection is correctly setup.

## Running the Project

    Access via the browser:
        http://localhost:8081/HomeWorkAssignment/

    NOTE:
        If you open api.php directly in the browser like:
            http://localhost/HomeWorkAssignment/api.php

        You will see an error:
            {
                "status": "error",
                "message": "Missing request type"
            }
        
        This is expected behaviour.

        * Why this happens?
            - The API is designed to only respond to POST requests with JSON data
            - Opening it in a browser sends no request body
            - Therefore, the required "type" field is missing

        - This does NOT mean the API is broken
        - It will work correctly when proper data is sent

## Testing
    1. Ensure Apache and MySQL are running

    # Using the Browser
       2. (GET) Example:
            http://localhost:8081/HomeWorkAssignment/getUsers.php

    # Using POSTMAN
        3. Open POSTMAN
        4. Create a Request to URL http://localhost/HomeWorkAssignment/api.php

        5. Set Headers:
            - Go to Headers tab and add:
                - Key: Content-Type, Value: application/JSON
                
        6. Send JSON body
            - Go to Body -> raw -> JSON and test endpoints like:
            
            # For Updating Flight Position
                PATCH:
                
                    {
                        "type": "UpdateFlightPosition",
                        "flight_id": 1,
                        "latitude": -26.2041,
                        "longitude": 28.0473,
                        "status": "In Flight"
                    }

                    - Expected Response:
                    {
                        "status": "success",
                        "message": "Flight position updated",
                        "timestamp": 1710000000000
                    }

            # For Get Airports 
                  PATCH:

                     {
                        "type": "GetAirports",
                     }

                     - Expected Response:
                     {
                        "status": "success",
                        "message" : "Request has been successful",
                        "timestamp": 171000000100
                     }

            # For Dispatch Flight
                  PATCH:

                     {
                        "type": "DispatchFlight",
                        "email" : input a user in the user table,
                        "flight_id" : 1
                     }

                     - Expected Response:
                     {
                        "status": "success",
                        "message" : "Flight has been successfully updated to Boarding",
                        "timestamp": 171000000100
                     }

            # For Board Flight
                   PATCH:

                     {
                        "type": "BoardFlight",
                        "email" : input a user in the user table,
                        "flight_id" : 1
                     }

                     - Expected Response:
                     {
                        "status": "success",
                        "message" : "Boarding confirmed successfully",
                        "timestamp": 171000000100
                     }

    # TIPS:
        * Always include "type" in your request
