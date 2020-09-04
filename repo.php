<?php

require_once __DIR__ . '/vendor/autoload.php';
require_once __DIR__ . '/icalendar/zapcallib.php';

use Httpful\Request;
use Symfony\Component\Yaml\Yaml;

$options = getopt('z:b:v:r:') ?: [];
function usage() {
    echo 'php ./' . basename(__FILE__) . " -r owner/repo [-z=yes|no] [-b=yes|no] [-v=yes|no]\n\n";
    echo "-z: ZIP MODE\n";
    echo "-b: BOTH MODE\n";
    echo "-v: VERBOSE_MODE MODE\n";
    exit();
}
define('PER_PAGE', 100);
define('ZIP_MODE', array_key_exists('z', $options) and in_array($options['z'], ['yes', 'y', '1', 'oui', 'o']));
define('BOTH_MODE', array_key_exists('b', $options) and in_array($options['b'], ['yes', 'y', '1', 'oui', 'o']));
define('VERBOSE_MODE', !array_key_exists('v', $options) or in_array($options['v'], ['yes', 'y', '1', 'oui', 'o']));
if (!array_key_exists('r', $options)) usage();
if (!preg_match('/[^\/]+\/.+/i', $options['r'])) usage();
$repo = $options['r'];

if (!is_file(__DIR__ . '/config/user.yaml')) throw new Exception('no user');
$user = Yaml::parseFile(__DIR__ . '/config/user.yaml');
$lineTime = (isset($user['linetime'])) ? $user['linetime'] : 1;

if (!is_dir(__DIR__ . '/exports')) mkdir(__DIR__ . '/ics');
if (ZIP_MODE or BOTH_MODE) {
    $zipFilename = __DIR__ . '/exports/export-' . date('Y-m-d-H-i-s') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($zipFilename, ZipArchive::CREATE) !== true) {
        exit("Can't open the archive {$zipFilename}\n");
    }
}

// create the ical object
$icalobj = new ZCiCal();
for ($page=1,$count=PER_PAGE; $count >= PER_PAGE and $page < 1000;$page++) {
    $baseUrl = 'https://api.github.com/repos/' . $repo . '/commits';
    $response = Request::get($baseUrl . "?page={$page}&per_page=" . PER_PAGE)
        ->basicAuth($user['username'], $user['password'])
        ->expectsJson()
        ->send();

    $count = count($response->body);
    if ($count) {
        echo "Page {$page}, {$count} commits\n";
        foreach ($response->body as $commit) {
            $commitResponse = Request::get($baseUrl . '/' . $commit->sha)
                ->basicAuth($user['username'], $user['password'])
                ->expectsJson()
                ->send();
            $commitData = $commitResponse->body;
            $time = min(540, max(10, floor($commitData->stats->total * $lineTime))); // min 10mn; max 9h
            $start = (new DateTime($commitData->commit->committer->date))->sub(new DateInterval("PT{$time}M"));
            $end = new DateTime($commitData->commit->committer->date);
            // create the event within the ical object
            $eventobj = new ZCiCalNode("VEVENT", $icalobj->curnode);

            // add title
            $eventobj->addNode(new ZCiCalDataNode("SUMMARY:" . '[' . $repo . '] ' . $commitData->commit->message));

            // add start date
            $eventobj->addNode(new ZCiCalDataNode("DTSTART:" . ZCiCal::fromSqlDateTime($start->format('Y-m-d H:i:s'))));

            // add end date
            $eventobj->addNode(new ZCiCalDataNode("DTEND:" . ZCiCal::fromSqlDateTime($end->format('Y-m-d H:i:s'))));

            // UID is a required item in VEVENT, create unique string for this event
            // Adding your domain to the end is a good way of creating uniqueness
            $eventobj->addNode(new ZCiCalDataNode("UID:" . $commitData->sha));

            // DTSTAMP is a required item in VEVENT
            $eventobj->addNode(new ZCiCalDataNode("DTSTAMP:" . ZCiCal::fromSqlDateTime()));

            // Add description
            $eventobj->addNode(new ZCiCalDataNode("Description:" . ZCiCal::formatContent(
            	"{$commitData->commit->message} " .
                "URL: {$commitData->html_url}"
            )));

        }
    }
}
// write iCalendar feed to file
$icalExport = $icalobj->export();
$repoName = $repo . '.ics';
if (ZIP_MODE or BOTH_MODE) {
    $repoFilename = $repoName;
    $zip->addFromString($repoFilename, $icalExport);
    if (VERBOSE_MODE) {
        echo "{$repoName} added in the archive\n";
        echo "Files count: " . $zip->numFiles . "\n";
        echo "Status: " . $zip->status . "\n";
        echo "\n";
    }
}
if (!ZIP_MODE or BOTH_MODE) {
    $repoFilename = __DIR__ . '/exports/' . $repoName;
    if (!is_dir(dirname($repoFilename))) mkdir(dirname($repoFilename));
    file_put_contents($repoFilename, $icalExport);
    if (VERBOSE_MODE) echo "{$repoName} created\n";
}
if (ZIP_MODE or BOTH_MODE) {
    $zip->close();
    if (VERBOSE_MODE) echo basename($zipFilename) . " has been created\n";
}
if (VERBOSE_MODE) echo "Export done.\n";
