#!/usr/bin/php
<?php

$debugging = false;
$clean_old_tarballs = true;

if($argc>1) $path = $argv[1];
else $path = '/var/www/websites';

if($argc>2) $destination_path = $argv[2];
else $destination_path = '/var/www/backup';

if($argc>3) $report_email = $argv[3];
else $report_email = 'ghbarratt@gmail.com';

if($argc>4) $verbose = $argv[4];
else $verbose = false;

if(!is_bool($verbose))
{
	if(strtolower($verbose[0])=='v' || strtolower($verbose[0])=='y' || strtolower($verbose)=='true') $verbose = true;
	else $verbose = false;
}

if(!is_dir($path))
{
	echo 'ERROR '.$path." is not a valid path.\n";
	exit;
}

chdir($path);
$items = scandir('.');

$date_appendage = date('Ymd');
$warnings = array();
$dir_tarballs = array();

foreach($items as $i)
{
	if(is_dir($i) && $i[0]!='.')
	{
		$tarball_name = $i.'_'.$date_appendage.'.tgz';
		if($debugging) echo 'DEBUG Now will attempt to create tarball: '.$tarball_name."\n";
		$command = 'tar -czf '.$tarball_name.' '.$i;
		if($debugging) echo 'DEBUG Command: '.$command."\n";
		$last_line = exec($command, $output, $return_status);
		if($return_status) 
		{
			$new_warning = 'WARNING Difficulty in creating tarball '.$tarball_name."\n";
			echo $new_warning;
			$warnings[] = $new_warning;
		}
		else 
		{
			$dir_tarballs[$i] = $tarball_name;
			if($verbose) echo 'NOTICE Successfully created tarball '.$tarball_name."\n";
			if($destination_path!=getcwd())
			{
				$success = rename($tarball_name,$destination_path.DIRECTORY_SEPARATOR.$tarball_name);
				if($success) 
				{
					if($verbose) echo 'NOTICE '.$tarball_name.' successfully placed in '.$destination_path."\n";
				}	
				else
				{	
					$new_warning = 'WARNING Unable to move '.$tarball_name.' to '.$destination_path."\n";
					echo $new_warning;
					$warnings[] = $new_warning;
				}	
			}// need to move
			if($clean_old_tarballs)
			{
				for($di=1; $di<60; $di++)
				{
					$tarball_filepath_to_delete = $destination_path.DIRECTORY_SEPARATOR.$i.'_'.date('Ymd', strtotime('- '.$di.' DAYS')).'.tgz';
					if($debugging) echo 'DEBUG Delete if exists: '.$tarball_filepath_to_delete."\n";
					if(is_file($tarball_filepath_to_delete))
					{
						$success = unlink($tarball_filepath_to_delete);
						if($success) 
						{
							if($verbose) echo 'NOTICE Deleting '.$tarball_filepath_to_delete."\n";
						}
						else 
						{
							$new_warning = 'WARNING Unable to delete '.$tarball_filepath_to_delete;
							echo $new_warning;
							$warnings[] = $new_warning;
						}
					}// file to delete exists
				}
			}
		}// tarball created
	}// found a dir to work with
}// the for loop

if($report_email)
{
	// Send report email
	if(count($warnings))
	{
		$message = "There were some warnings: \n".implode("\n",$warnings)."\n";
	}
	else
	{
		$message = 'It appears as if all '.count($dir_tarballs)." backup tarballs were created successfully:\n";
		foreach($dir_tarballs as $dt_dir=>$dt_tarball)
		{
			$message .= (array_search($dt_dir,array_keys($dir_tarballs))+1).'. '.$dt_dir.' was tarred into '.$dt_tarball."\n";
		}
	}

	$success = mail($report_email,'Message from the Make Website Tarballs Script on '.gethostname(),$message);
	if($verbose && $success) echo 'NOTICE Successfully sent email to '.$report_email."\n";
}
