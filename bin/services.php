<?php

// call function based on requested action
switch ($_REQUEST['action']) {
    case 'addVisit':
        addVisit();
        break;
    case 'getReport':
        getReport();
        break;
    case 'upgradeDB':
        upgradeDB();
        break;
    default:
        echo 'success=false';
}

/*
		Returns a generic connection to the DB
	*/
function getConnection()
{
    $db = 'movedb';
    $dbHost = 'localhost';
    $dbUser = 'root';
    $dbPw = '';
    $conn = new mysqli($dbHost, $dbUser, $dbPw, $db);

    /* check connection */
    if ($conn->connect_errno) {
        echo 'Connect failed: ' . $conn->connect_error;
        exit();
    } else {
        return $conn;
    }
}

/*
		Adds a visit for a particular squadron and date.
		If no date or squadron is specified, the current date and 'Other' is used
	*/
function addVisit()
{
    $conn = getConnection();

    // In case values aren't sent
    if (!isset($_REQUEST['time'])) $_REQUEST['time'] = date('Y-m-d H:i:s');
    if (!isset($_REQUEST['squadron'])) $_REQUEST['squadron'] = 'Other';

    $sql = 'INSERT INTO students VALUES (null, "' . $_REQUEST['time'] . '", "' . $_REQUEST['squadron'] . '")';
    $response = (object) array('success' => false);

    if ($conn->query($sql) === TRUE) {
        $response->success = true;
    } else {
        $response->error = $conn->error;
        $response->sql = $sql;
    }

    $conn->close();

    echo json_encode($response);
}

/*
		Grabs all the records between two dates
		If no start date is passed, the beginning of current day is used
		If no end date is passed, the end of the the start day is used
	*/
function getReport()
{
    $conn = getConnection();

    // In case values aren't sent
    if (!isset($_REQUEST['start']) || $_REQUEST['start'] == 'null') {
        $startDate = new DateTime(date('Y-m-d H:i:s'));
        $startDate->setTime(0, 0, 0);
        $_REQUEST['start'] = $startDate->format('Y-m-d H:i:s');
    }
    if (!isset($_REQUEST['end']) || $_REQUEST['end'] == 'null') {
        $endDate = new DateTime($_REQUEST['start']);
        $endDate->setTime(23, 59, 59);
        $_REQUEST['end'] = $endDate->format('Y-m-d H:i:s');
    }

    $sql = 'SELECT * FROM students WHERE Date >= "' . $_REQUEST['start'] . '" AND Date < "' . $_REQUEST['end'] . '"';
    $response = (object) array('success' => false);

    if ($result = $conn->query($sql)) {
        $response->success = true;
        $response->visits = array();
        while ($row = $result->fetch_object()) {
            array_push($response->visits, (object) array('date' => $row->Date, 'name' => $row->Squadron));
        }
    } else {
        $response->error = $conn->error;
        $response->sql = $sql;
    }

    $conn->close();

    echo json_encode($response);
}

/*
		Remove all the squadrons and invalid data that doesn't match our labels

		SELECT *
		FROM  `students`
		WHERE Squadron =  'Black Ropes'
		LIMIT 0 , 1000

		312 TRS
		316 TRS
		315 TRS
		Marine Corps Det
		344 MIB
		17 TRW
		17TRW
		(None)
		Select
				  315 TRS
		Fit Flight
		Black Ropes
		Blacks Ropes
		White Rope
		blackrope
		White Rope wannabe (AKA Black rope)
		blackropes
		319 TRS
		323 hoorah band flight
		315
		319TRS
		NULL
		NASIC
		(None)315
		The Ultimate
		361
		3153
		743 mi bn
		black rope
		315`
	*/
function cleanDB()
{
    $sql = 'SELECT DISTINCT Squadron FROM students';
    $sql = 'SELECT * FROM students WHERE Squadron="315"';
    $sql = 'UPDATE students SET Squadron="315 TRS" WHERE Squadron="315"';
}

/*
		- Removes BranchOfService
		- Removes "Number of students"
		- Combines date and time into one
		- Removes Time
	*/
function upgradeDB()
{
    $conn = getConnection();

    if (!isset($_REQUEST['start'])) $_REQUEST['start'] = 0;

    $linesPerSession = 3000;
    $lineStart = $_REQUEST['start'];
    $result = $conn->query('SELECT COUNT(Students) FROM students')->fetch_array();
    $lineTotal = $result[0];

    // Check start and foffset are numeric values
    if (!is_numeric($_REQUEST['start'])) {
        echo ('<p class=\'error\'>UNEXPECTED: Non-numeric values for start and foffset</p>\n');
        exit();
    }

    if ($lineStart == $lineTotal) {
        echo 'Dates updated...<br>';

        // Since Date and Time have merged, drop Time
        $sql = 'ALTER TABLE students DROP COLUMN Time';
        $result = $conn->query($sql);
        echo 'Time column removed...<br>';

        $sql = 'ALTER TABLE students DROP COLUMN BranchOfService';
        $result = $conn->query($sql);
        echo 'BranchOfService column removed...<br>';

        $sql = 'ALTER TABLE students DROP COLUMN "Number of students"';
        $result = $conn->query($sql);
        echo '"Number of students" column removed...<br>';

        echo 'Database upgraded!';
    } else {
        $sql = 'SELECT * FROM students LIMIT ' . $lineStart . ', ' . $linesPerSession;
        echo $sql . '<br>';
        if ($result = $conn->query($sql)) {

            /* prepare statement */
            $stmt = $conn->prepare('UPDATE students SET Date=? WHERE Students=?');

            while ($row = $result->fetch_object()) {
                $date = new DateTime($row->Date);
                $time = new DateTime($row->Time);
                $date->setTime($time->format('H'), $time->format('i'), $time->format('s'));
                //echo $row['Students'] . ' : ' . $date->format('Y-m-d H:i:s') . '<br>';

                /*
					i - Integer
					d - Decimal
					s - String
					b - Blob (sent in packets)
					*/
                $stmt->bind_param("si", $date, $id);
                $date = $date->format('Y-m-d H:i:s');
                $id = $row->Students;

                /* execute prepared statement */
                $stmt->execute();
                $lineStart++;
            }

            /* close statement */
            $stmt->close();
        } else {
            echo $conn->error;
        }

        /* close connection */
        $conn->close();
        echo $lineStart;
        echo ("<script language=\"JavaScript\" type=\"text/javascript\">window.setTimeout('location.href=\"" . $_SERVER["PHP_SELF"] . "?action=upgradeDB&start=$lineStart\";',500);</script>\n");
    }
}
