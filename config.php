<?php
    //Local Database Connection
    $host = "127.0.0.1";
    $db = "cos216_asynconus";
    $user = "root";
    $dbpass = "";
    $port = 3306;

    $conn = new mysqli($host, $user, $dbpass, $db, $port);

    if ($conn->connect_error) {
        die(json_encode([
            "status" => "error",
            "message" => "Database connection failed"
        ]));
    }
?>