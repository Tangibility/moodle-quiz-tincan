<?php
$handlers = array(
    // Handle our own quiz_attempt_submitted event, as a way to send confirmation
    // messages asynchronously.
	'quiz_attempt_submitted' => array (
	    'handlerfile'     => '/report/tincan/lib.php',
	    'handlerfunction' => 'report_tincan::tincan_quiz_attempt_submitted',
	    'schedule'        => 'instant',
	),
	'quiz_attempt_started' => array (
	    'handlerfile'     => '/report/tincan/lib.php',
	    'handlerfunction' => 'report_tincan::tincan_quiz_attempt_started',
	    'schedule'        => 'instant',
	),
);