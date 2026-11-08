#!/usr/bin/php
<?php

require_once 'helpers.php';

$envSettings = [];
if (is_file(__DIR__ . '/.env')) {
    $envSettings = getArrayFromSettingsFile(__DIR__ . '/.env');
}

$destinationPathForBackupDefault = 'backup';
$pathsToTryForBackupDefault = ['backup', '/var/www/backup'];
foreach ($pathsToTryForBackupDefault as $p) {
    if (is_dir($p)) {
        $destinationPathForBackupDefault = $p;
        break;
    }
}

$optionalArgumentDefaults = [
    'destination_path_for_backup_default' => $destinationPathForBackupDefault,
    'exclude_directories' => [],
    'directory_timelies_to_keep_count_default' => 5,
    'directory_dailies_to_keep_count_default' => 7,
    'directory_monthlies_to_keep_count_default' => 6,
    'directory_yearlies_to_keep_count_default' => 'all',
    'verbose_default' => false,
    'debugging_default' => false,
];

$usageString = 'Usage: ' . basename(__FILE__) . ' <path>';
foreach ($optionalArgumentDefaults as $a => $ad) {
    $usageString .= ' [<' . $a . '>=' . (is_numeric($ad) ? $ad : '"' . (is_array($ad) ? implode(',', $ad) : $ad) . '"')
        . ']';
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
    } elseif (array_key_exists($varName, $envSettings)) {
        ${$varName} = $envSettings[strtolower($varName)];
    } else {
        ${$varName} = $ad;
    }
}

$retentionVariables = [
    'directory_timelies_to_keep_count',
    'directory_dailies_to_keep_count',
    'directory_monthlies_to_keep_count',
    'directory_yearlies_to_keep_count',
];
foreach ($retentionVariables as $rv) {
    if (isset(${$rv})) {
        $trimmed = is_string(${$rv}) ? strtolower(trim(${$rv})) : ${$rv};
        if ($trimmed === 'all' || $trimmed === '-1') {
            ${$rv} = -1; // -1 represents keep all / unlimited
        } elseif (is_numeric($trimmed)) {
            ${$rv} = max(0, (int)$trimmed);
        } else {
            ${$rv} = 0;
        }
    } else {
        ${$rv} = 0;
    }
}

$arrayArguments = ['exclude_directories'];
foreach ($arrayArguments as $aa) {
    if (!is_array(${$aa})) {
        if (is_string(${$aa})) {
            ${$aa} = explode(',', ${$aa});
            ${$aa} = array_map('trim', ${$aa});
        } elseif (empty($${$aa})) {
            ${$aa} = [];
        }
    }
}

$boolArguments = ['verbose', 'debugging'];
foreach ($boolArguments as $ba) {
    if (!is_bool(${$ba})) {
        if (in_array(strtolower(${$ba}[0]), ['y', 't', 'v'])) {
            ${$ba} = true;
        } else {
            ${$ba} = false;
        }
    }
}

$destination_path_for_backup = rtrim($destination_path_for_backup, DIRECTORY_SEPARATOR);

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

if ($debugging) {
    echo 'DEBUG lastDirectory: ' . $lastDirectory . "\n";
    echo 'DEBUG CWD: ' . getcwd() . "\n";
    echo 'DEBUG tarball filename: ' . $tarballFilename . "\n";
}

// Excludes must be relative when the path is, and we basically always make the path relative
foreach ($exclude_directories as &$ed) {
    $ed = rtrim(str_replace($path . DIRECTORY_SEPARATOR, '', $ed), DIRECTORY_SEPARATOR);
}

if (!empty($exclude_directories)) {
    $exclusionsArgString = " --exclude='" . implode("' --exclude='", $exclude_directories) . "'";
} else {
    $exclusionsArgString = '';
}

$command = 'tar' . $exclusionsArgString . ' -czf ' . $destination_path_for_backup . DIRECTORY_SEPARATOR
    . $tarballFilename . ' ' . $subject;

if ($debugging || $verbose || !empty($exclusionArgSting)) {
    echo 'NOTICE Command is "' . $command . "\"\n";
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
        echo 'NOTICE Successfully created tarball ' . $destination_path_for_backup . DIRECTORY_SEPARATOR
            . $tarballFilename . "\n";
    }
} else {
    $new_warning = 'WARNING Subject "' . $subject . '" is not a directory' . "\n";
    echo $new_warning;
    $warnings[] = $new_warning;
}

$deleteCount = 0;

// Make sure there is a yearly for the current year (if yearlies enabled)
if ($directory_yearlies_to_keep_count !== 0) {
    $yearlyFilepath = $destination_path_for_backup . DIRECTORY_SEPARATOR . $subject . '_' . date('Y') . '.tgz';
    if (!is_file($yearlyFilepath)) {
        if ($verbose || $debugging || date('md') !== '0101') {
            echo 'NOTICE Yearly "' . $yearlyFilepath . '" not found, so copying ' . $destination_path_for_backup
                . DIRECTORY_SEPARATOR . $tarballFilename . ' to ' . $yearlyFilepath . "\n";
        }
        copy($destination_path_for_backup . DIRECTORY_SEPARATOR . $tarballFilename, $yearlyFilepath);
    }
}

// Make sure there is a monthly for the current month (if monthlies enabled)
if ($directory_monthlies_to_keep_count !== 0) {
    $monthlyFilepath = $destination_path_for_backup . DIRECTORY_SEPARATOR . $subject . '_' . date('Ym') . '.tgz';
    if (!is_file($monthlyFilepath)) {
        if ($verbose || $debugging || date('d') !== '01') {
            echo 'NOTICE Monthly "' . $monthlyFilepath . '" not found, so copying ' . $destination_path_for_backup
                . DIRECTORY_SEPARATOR . $tarballFilename . ' to ' . $monthlyFilepath . "\n";
        }
        copy($destination_path_for_backup . DIRECTORY_SEPARATOR . $tarballFilename, $monthlyFilepath);
    }
}

// Copy every new tarball to the daily so that the daily will have the latest/last for the day (if dailies enabled)
if ($directory_dailies_to_keep_count !== 0) {
    $dailyFilepath = $destination_path_for_backup . DIRECTORY_SEPARATOR . $subject . '_' . date('Ymd') . '.tgz';
    if ($debugging) {
        echo 'DEBUG Copying ' . $destination_path_for_backup . DIRECTORY_SEPARATOR . $tarballFilename . ' to '
            . $dailyFilepath . "\n";
    }
    copy($destination_path_for_backup . DIRECTORY_SEPARATOR . $tarballFilename, $dailyFilepath);
}

// Helper to purge files exceeding keep count
$purgeBackupFiles = function (array $files, int $keepCount, string $label) use (&$deleteCount, $verbose, $debugging) {
    if ($keepCount === -1) {
        return; // -1 means keep all / unlimited
    }
    rsort($files);
    $toDelete = ($keepCount === 0) ? $files : array_slice($files, $keepCount);
    foreach ($toDelete as $filepath) {
        if (is_file($filepath)) {
            if ($verbose || $debugging) {
                echo 'NOTICE Deleting old ' . $label . ' backup file: ' . $filepath . "\n";
            }
            if (!$debugging) {
                $deleteCount += (int)unlink($filepath);
            }
        }
    }
};

// Purge timelies (format: <subject>_YYYYMMDD_HHMM.tgz)
if ($directory_timelies_to_keep_count !== -1) {
    $timeliesFilepaths = glob($destination_path_for_backup . DIRECTORY_SEPARATOR . $subject
        . '_[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9]_[0-9][0-9][0-9][0-9].tgz');
    if ($timeliesFilepaths) {
        $purgeBackupFiles($timeliesFilepaths, $directory_timelies_to_keep_count, 'timely');
    }
}

// Purge dailies (format: <subject>_YYYYMMDD.tgz)
if ($directory_dailies_to_keep_count !== -1) {
    $dailyFiles = glob($destination_path_for_backup . DIRECTORY_SEPARATOR . $subject
        . '_[0-9][0-9][0-9][0-9][0-9][0-9][0-9][0-9].tgz');
    if ($dailyFiles) {
        $purgeBackupFiles($dailyFiles, $directory_dailies_to_keep_count, 'daily');
    }
}

// Purge monthlies (format: <subject>_YYYYMM.tgz)
if ($directory_monthlies_to_keep_count !== -1) {
    $monthlyFiles = glob($destination_path_for_backup . DIRECTORY_SEPARATOR . $subject
        . '_[0-9][0-9][0-9][0-9][0-9][0-9].tgz');
    if ($monthlyFiles) {
        $purgeBackupFiles($monthlyFiles, $directory_monthlies_to_keep_count, 'monthly');
    }
}

// Purge yearlies (format: <subject>_YYYY.tgz)
if ($directory_yearlies_to_keep_count !== -1) {
    $yearlyFiles = glob($destination_path_for_backup . DIRECTORY_SEPARATOR . $subject . '_[0-9][0-9][0-9][0-9].tgz');
    if ($yearlyFiles) {
        $purgeBackupFiles($yearlyFiles, $directory_yearlies_to_keep_count, 'yearly');
    }
}

if ($verbose) {
    if ($deleteCount > 0) {
        echo 'NOTICE Successfully deleted ' . $deleteCount . " old backup files\n";
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
        //$message = 'Successfully backed up ' . $path . ' into ' . $destination_path_for_backup . DIRECTORY_SEPARATOR
            //. $tarballFilename . "\n";
    //}

    //$success = mail($report_email, 'Message from the Backup Directory Script on ' . gethostname(), $message);
    //if ($verbose && $success) {
        //echo 'NOTICE Successfully sent email to ' . $report_email . "\n";
    //}
//}

chdir($originalDirectory);
exit;
