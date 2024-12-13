<?php

require_once __DIR__ . '/vendor/autoload.php';

use HsDeduper\Config;
use HsDeduper\SequenceEnroller;

try {
    $ignoreErrors = false;
    $args = array_slice($argv, 1);
    
    // Check for -ignore-errors flag
    $flagIndex = array_search('-ignore-errors', $args);
    if ($flagIndex !== false) {
        $ignoreErrors = true;
        array_splice($args, $flagIndex, 1);
    }

    // Get list ID and sequence ID from remaining arguments
    $listId = $args[0] ?? null;
    $sequenceId = $args[1] ?? null;

    if (!$listId || !$sequenceId) {
        echo "Usage: php enroll_sequence.php [-ignore-errors] <list_id> <sequence_id>\n";
        echo "\nOptions:\n";
        echo "  -ignore-errors    Retry enrollment for contacts that previously failed\n";
        exit(1);
    }

    $config = new Config();
    $enroller = new SequenceEnroller($config->getHubspotApiKey(), $ignoreErrors);
    
    if ($ignoreErrors) {
        echo "Running with -ignore-errors flag: Will retry previously failed enrollments\n\n";
    }
    
    $stats = $enroller->enrollListIntoSequence($listId, $sequenceId);
    $enroller->printEnrollmentStats($stats);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
