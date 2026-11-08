#!/usr/bin/php
<?php

require_once 'helpers.php';

if (is_file(__DIR__ . '/.env')) {
    $envSettings = getArrayFromSettingsFile(__DIR__ . '/.env');
} else {
    $envSettings = [];
}

$optionalArgumentDefaults = [
    'destination_path_default' => 'backup',
    'dailies_to_keep_count_default' => 7,
    'monthlies_to_keep_count_default' => 6,
    'yearlies_to_keep_count_default' => null,
    'timelies_to_keep_count_default' => 5,
    'verbose_default' => false,
    'debugging_default' => false,
];

$usageString = 'Usage: ' . basename(__FILE__) . ' <path>';
foreach ($optionalArgumentDefaults as $a => $ad) {
    $usageString .= ' [<' . $a . '>=' . (is_numeric($ad) ? $ad : '"' . $ad . '"') . ']';
}

if ($argc < 2) {
    echo $usageString . "\n";
    exit;
}

// Path argument is the only required one
$path = rtrim($argv[1], DIRECTORY_SEPARATOR);

$ai = 1;
foreach ($optionalArgumentDefaults as $a => $ad) {
    if (str_ends_with($a, '_default')) {
        $varName = substr($a, 0, -8);
    } else {
        $varName = $a;
    }
    $ai++;
    if ($argc > $ai) {
        ${$varName} = $argv[$ai];
    } elseif (defined(strtoupper($a))) {
        ${$varName} = constant(strtoupper($a));
    } elseif (array_key_exists(strtolower($a), $envSettings)) {
        ${$varName} = $envSettings[strtolower($a)];
    } elseif (array_key_exists(strtolower($a), $varName)) {
        ${$varName} = $envSettings[strtolower($varName)];
    } else {
        ${$varName} = $ad;
    }
}

$boolArguments = ['verbose', 'debugging'];
foreach ($boolArguments as $ba) {
    if (in_array(strtolower(${$ba}[0]), ['y', 't', 'v'])) {
        ${$ba} = true;
    } else {
        ${$ba} = false;
    }
}

$destination_path = rtrim($destination_path, DIRECTORY_SEPARATOR);

if (!is_bool($verbose)) {
    if (strtolower($verbose[0]) == 'v' || strtolower($verbose[0]) == 'y' || strtolower($verbose[0]) == 't') {
        $verbose = true;
    } else {
        $verbose = false;
    }
}

if (!is_dir($path)) {
    echo 'ERROR ' . $path . " is not a valid path.\n";
    exit;
}

$warnings = [];

$lastDirectory = basename($path);

$originalDirectory = getcwd();

if ($path == '.') {
    $path = getcwd();
} else {
    chdir($path);
}

chdir('..');

$date_appendage = date('Ymd');
$yesterday_date_appendage = date('Ymd', strtotime('-1 DAY'));

if (is_link($lastDirectory)) {
    chdir(readlink($lastDirectory));
    chdir('..');
    $subject = basename(readlink($lastDirectory));
} else {
    $subject = $lastDirectory;
}

$tarballFilename = $subject . '_' . $date_appendage . '_' . date('Hi') . '.tgz';

$command = 'tar -czf ' . $destination_path . DIRECTORY_SEPARATOR . $tarballFilename . ' ' . $subject;


if ($debugging) {
    echo 'DEBUG lastDirectory: ' . $lastDirectory . "\n";
    echo 'DEBUG CWD: ' . getcwd() . "\n";
    echo 'DEBUG tarball filename: ' . $tarballFilename . "\n";
    echo 'DEBUG command: ' . $command . "\n";
}

if (is_dir($subject)) {
    if ($verbose) {
        echo 'NOTICE Creating backup tarball ' . $tarballFilename . ' of ' . $subject . "\n";
    }
        $last_line = exec($command, $output, $return_status);
    if ($return_status) {
        $new_warning = 'WARNING Difficulty in creating tarball ' . $tarballFilename . "\n";
        echo $new_warning;
        $warnings[] = $new_warning;
    } elseif ($verbose || $debugging) {
        echo 'NOTICE Successfully created tarball ' . $destination_path . DIRECTORY_SEPARATOR . $tarballFilename . "\n";
    }
} else {
    $new_warning = 'WARNING Subject "' . $subject . '" is not a directory' . "\n";
    echo $new_warning;
    $warnings[] = $new_warning;
}

$delete_count = 0;

// Make sure there is a yearly for the current year
$yearlyFilepath = $destination_path . DIRECTORY_SEPARATOR . $subject . '_' . date('Y') . '.tgz';
if (!is_file($yearlyFilepath)) {
    echo 'NOTICE Yearly "' . $yearlyFilepath . '" not found, so copying ' . $destination_path . DIRECTORY_SEPARATOR
        . $tarballFilename . ' to ' . $yearlyFilepath . "\n";
    copy($destination_path . DIRECTORY_SEPARATOR . $tarballFilename, $yearlyFilepath);
}

// Make sure there is a monthly for the current month
$monthlyFilepath = $destination_path . DIRECTORY_SEPARATOR . $subject . '_' . date('Ym') . '.tgz';
if (!is_file($monthlyFilepath)) {
    echo 'NOTICE Monthly "' . $monthlyFilepath . '" not found, so copying ' . $destination_path . DIRECTORY_SEPARATOR
        . $tarballFilename . ' to ' . $monthlyFilepath . "\n";
    copy($destination_path . DIRECTORY_SEPARATOR . $tarballFilename, $monthlyFilepath);
}

// Copy every new tarball to the daily so that the daily will have the latest/last for the day
$dailyFilepath = $destination_path . DIRECTORY_SEPARATOR . $subject . '_' . date('Ymd') . '.tgz';
if ($debugging) {
    echo 'DEBUG Copying ' . $destination_path . DIRECTORY_SEPARATOR . $tarballFilename . ' to ' . $dailyFilepath . "\n";
}
copy($destination_path . DIRECTORY_SEPARATOR . $tarballFilename, $dailyFilepath);

// Delete timelies that are past retention
if ($timelies_to_keep_count) {
    $timeliesFilepaths = glob($destination_path . DIRECTORY_SEPARATOR . $subject . '_*_*.tgz');
    arsort($timeliesFilepaths);

    $i = 0;
    foreach ($timeliesFilepaths as $filepath) {
        $i++;
        if ($debugging) {
            echo 'DEBUG Should delete filename: "' . basename($filepath) . '" (' . $i . ")?\n";
        }
        if ($i > $timelies_to_keep_count) {
            if (is_file($filepath)) {
                if ($verbose || $debugging) {
                    echo 'NOTICE Deleting old "timely" tarball: ' . $filepath . "\n";
                }
                if (!$debugging) {
                    $delete_count += (int)(unlink($filepath));
                }
            } elseif ($debugging) {
                echo 'DEBUG File: ' . $filepath . " not found\n";
            }
        } elseif ($debugging || $verbose) {
            echo 'Okay, this file (' . $filepath . ") is okay to keep for now\n";
        }
    }
}

// Then delete all the dailies that are past retention
for ($i = $dailies_to_keep_count; $i < ($dailies_to_keep_count + 30); $i++) {
    $tmp_date = date('Ymd', strtotime('-' . $i . ' day'));
    $tempFilepath = $destination_path . DIRECTORY_SEPARATOR . $subject . '_' . $tmp_date . '.tgz';
    if (is_file($tempFilepath)) {
        if ($verbose || $debugging) {
            echo 'NOTICE Deleting old backup file: ' . $tempFilepath . "\n";
        }
        if (!$debugging) {
            $delete_count += (int)(unlink($tempFilepath));
        }
    } elseif ($debugging) {
        echo 'DEBUG File: ' . $tempFilepath . " not found\n";
    }
}

// TODO FINISH THIS!!! Notice script is expected to run daily
// TODO Delete monthlies that are past retention (when monthlies_to_keep_count not 0)
// TODO Delete yearlies that are past retention (when yearlies_to_keep_count not 0)

if ($verbose) {
    if ($delete_count > 0) {
        echo 'NOTICE Successfully deleted ' . $delete_count . " old backup files\n";
    } else {
        echo "NOTICE There were no old backup files to delete\n";
    }
}

// TODO? Reintroduce email report?
//if ($report_email) {
    // Send report email
    //if (count($warnings)) {
        //$message = "There were some warnings: \n" . implode("\n", $warnings) . "\n";
    //} else {
        //$message = 'Successfully backed up ' . $path . ' into ' . $destination_path . DIRECTORY_SEPARATOR
            //. $tarballFilename . "\n";
    //}

    //$success = mail($report_email, 'Message from the Backup Directory Script on ' . gethostname(), $message);
    //if ($verbose && $success) {
        //echo 'NOTICE Successfully sent email to ' . $report_email . "\n";
    //}
//}

chdir($originalDirectory);
exit;
