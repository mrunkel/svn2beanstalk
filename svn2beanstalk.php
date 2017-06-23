#!/usr/bin/env php
<?php
/**
 * Loops through an array of directories of raw svn repositories and imports them into beanstalk.
 * User: mrunkel
 * Date: 11/21/14
 * Time: 8:33 PM
 */


$repoList = array (//"path to raw svn repo",

	);

// Update the next three lines to match your account settings.
$username = "{username}";
$token = "{api token}";
$baseURL = "https://{companyname}.beanstalkapp.com/api/";


$contentType = "application/json";
$userAgent = "svn2beanstalk.php";
$repoStatus = array();

foreach($repoList as $repo) {

    $repoName = str_replace("/","_", $repo);
    $names = preg_split("$/$", $repo);
    $repoTitle = ucfirst($names[0]) . " " . $names[1];

    $json = json_encode(array ("repository" => array ("type_id" => "subversion",
                                         "name" => $repoName,
                                         "title" => $repoTitle,
                                         "color_label" => "label-orange")));
    $http_header = array("Content-Type: " . $contentType,
        "Content-Length: " . strlen($json));

    $url = $baseURL . "repositories.json";

    $create_repo = curl_init($url);
    curl_setopt($create_repo, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($create_repo, CURLOPT_USERPWD, $username . ":" . $token);
    curl_setopt($create_repo, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($create_repo, CURLOPT_POST, true);
    curl_setopt($create_repo, CURLOPT_POSTFIELDS, $json);
    curl_setopt($create_repo, CURLOPT_HTTPHEADER, $http_header);

    echo "Creating repo " . $repoName . " on beanstalk." . PHP_EOL;
    //create beanstalk repo
    $output = curl_exec($create_repo);
    $retData = json_decode($output, true);
    if (!$output) {
        var_dump(curl_error($create_repo));
    }
    curl_close($create_repo);

    if (array_key_exists("errors", $retData)) {
        echo "Error creating repo: $repoName";
        var_dump($retData);
    }

    $repoID = $retData["repository"]["id"];
    $repoURL = $retData["repository"]["repository_url"];

    //dump repo
    if (!file_exists("/var/www/dumps/$repoName.dump")) {
        echo "Creating svndump." . PHP_EOL;
        exec("/usr/bin/svnadmin dump /home/svnrepo/$repo >/var/www/dumps/$repoName.dump 2>/var/www/dumps/$repoName.log");
    } else {
        echo "Using existing svndump." . PHP_EOL;
    }
    //start import
    $json = json_encode(array ("repository_import" =>
                            array ("uri" => "http://ny-ngfw.untangleit.net:9999/dumps/$repoName.dump")
                            )
                        );
    $http_header = array("Content-Type: " . $contentType,
                         "Content-Length: " . strlen($json));

    $url = $baseURL . $repoID ."/repository_imports.json";

    $import_repo = curl_init($url);
    curl_setopt($import_repo, CURLOPT_USERAGENT, $userAgent);
    curl_setopt($import_repo, CURLOPT_USERPWD, $username . ":" . $token);
    curl_setopt($import_repo, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($import_repo, CURLOPT_POST, true);
    curl_setopt($import_repo, CURLOPT_POSTFIELDS, $json);
    curl_setopt($import_repo, CURLOPT_HTTPHEADER, $http_header);

    echo "Telling beanstalk to start importing the dump file." . PHP_EOL;

    $output = curl_exec($import_repo);

    curl_close($import_repo);
    $return = json_decode($output, true);

    if (array_key_exists("errors", $return)) {
        echo "Import of $repo failed.";
        var_dump($return);
    }

    $importID = $return["repository_import"]["id"];
    $statusRecord = array ("name" => $repoName,
                           "id"   => $importID,
                           "URL"  => $repoURL,
                           "state" => "started");

    echo "Checking on status of all imports." . PHP_EOL;
    // each time we start an import, update the status of other imports 
    foreach ($repoStatus as $key => $status) {
        // check all the previous imports to see their status.
        $http_header = array("Content-Type: " . $contentType);
        $url = $baseURL . "repository_imports/" . $status["id"] . ".json";

        $check_status = curl_init($url);
        curl_setopt($check_status, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($check_status, CURLOPT_USERPWD, $username . ":" . $token);
        curl_setopt($check_status, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($check_status, CURLOPT_HTTPHEADER, $http_header);

        $output = curl_exec($check_status);

        $return = json_decode($output, true);
        $repoStatus[$key]['state'] = $return["repository_import"]["state"];
        curl_close($check_status);
    }
    // print out all the statuses
    echo "Updating status.html file." . PHP_EOL;
    $repoStatus[] = $statusRecord;
    $statusFile = fopen("/var/www/dumps/status.html", "w");
    foreach ($repoStatus as $status) {
      echo "Repo " . $status["name"] . " - " . $status['state'] . PHP_EOL;
      fwrite ($statusFile, "Repo " . $status["name"] . " - " . $status['state'] . "<BR>" . PHP_EOL);
    }
    fclose($statusFile);
}

echo "Done with all imports, now we'll just loop and update status.html" . PHP_EOL;

$stillProcessing = true;
// now loop until all imports are finished and update the status
while ($stillProcessing) {
    $stillProcessing = false;
    foreach ($repoStatus as $key => $status) {
        // check all the previous imports to see their status.
        $http_header = array("Content-Type: " . $contentType);
        $url = $baseURL . "repository_imports/" . $status["id"] . ".json";

        $check_status = curl_init($url);
        curl_setopt($check_status, CURLOPT_USERAGENT, $userAgent);
        curl_setopt($check_status, CURLOPT_USERPWD, $username . ":" . $token);
        curl_setopt($check_status, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($check_status, CURLOPT_HTTPHEADER, $http_header);

        $output = curl_exec($check_status);

        $return = json_decode($output, true);
	$state = $return["repository_import"]["state"];

	if ($state != "complete" && $state != "failed") {
          $stillProcessing = true;
        }

        $repoStatus[$key]['state'] = $state;
        curl_close($check_status);
    }
    // you can put this status.html page anywhere you have write access.
    $statusFile = fopen("status.html", "w");
    foreach ($repoStatus as $status) {
      fwrite ($statusFile, "Repo " . $status["name"] . " - " . $status['state'] . "<BR>" . PHP_EOL);
    }
    fclose($statusFile);
    sleep(15);
}

echo "Successful execution!";
