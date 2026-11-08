#!/usr/bin/php
<?php

$debugging = false;

if($argc>1) $process_name = $argv[1];
else $process_name = 'apache'; // On other OSes this might be "httpd"

if($argc>2) $email_recipients = $argv[2];
else $email_recipients = '8014140117@messaging.sprintpcs.com,ghbarratt@gmail.com';

if($argc>3) $process_count_threshold = $argv[3];
else
{
	if($process_name=='apache') $process_count_threshold = 2;
	else $process_count_threshold = 1;
}

$words_to_ignore = array('grep',basename(__FILE__));
if($debugging>1) echo 'DEBUG filename: '.basename(__FILE__)."\n";

// This script needs to be run as root
$pwu_data = posix_getpwuid(posix_geteuid());
$username = $pwu_data['name'];
if($debugging) echo 'DEBUG You are running this script as '.$username."\n";

$command = 'ps -ef | grep '.$process_name;
if($debugging) echo 'DEBUG Command is '.$command."\n";
$last_line = exec($command, $output);

foreach($output as $line) 
{
	if($debugging>1) echo 'DEBUG Now confirming that "'.$line."\" should count\n";
	$ignore_line = false;
	foreach($words_to_ignore as $wti)
	{
		if(strpos($line, $wti)!==false) 
		{
			if($debugging>1) echo 'DEBUG Ignoring line "'.$line.'" because it has "'.$wti."\"\n";
			$ignore_line = true; 
		}
		if ($ignore_line) break;
	}
	if(!$ignore_line) $qualifying_lines[] = $line;
	
}


if($debugging) echo 'There were '.count($qualifying_lines)." qualifying lines.\n";
if($debugging>1) print_r($qualifying_lines);

if(count($qualifying_lines)<$process_count_threshold)
{
	if($debugging) echo 'DEBUG Sending warning email since there were only '.count($qualifying_lines)." lines\n";
	$message = 'There are currently '.(count($qualifying_lines)>0 ? 'only ' : '').count($qualifying_lines).' '.$process_name.' processe'.(count($qualifying_lines)!=1 ? 's' : '')."?!? Better go check it out now.\n"; 
	$message .= "Here is a glimpse: \n".implode("\n",$qualifying_lines);
	//$message .= "\n\n".$email_recipients;
	mail($email_recipients,'WARNING from Check for '.ucwords($process_name).' on '.gethostname(), $message);
}

exit;
