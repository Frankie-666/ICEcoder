<?php
include("headers.php");
include("settings.php");
$t = $text['updater'];
?>
<!DOCTYPE html>
<head>
<title>Updating ICEcoder...</title>
</head>

<body style="background: #141414; color: #fff; font-size: 10px; font-family: arial, helvetica, swiss, verdana">
<?php
define('PATH', '../tmp/oldVersion/');
$updateDone = false;

function startUpdate() {
	if (is_dir(PATH)) {
		echo 'Postfixing oldVersion dir with a timestamp...<br>';
		rename(PATH,trim(PATH,"/")."-".time());
	}
	copyOldVersion();
}

function copyOldVersion() {
	if (!is_dir(PATH)) {
		echo 'Creating new oldVersion dir...<br>';
		mkdir(PATH);
	}
	$source = "../";
	$dest = PATH;
	// Set a stream context timeout for file reading
	$context = stream_context_create(array('http'=>
		array(
			'timeout' => 60 // secs
		)
	));
	echo 'Moving current ICEcoder files...<br>';
	foreach ($iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, RecursiveDirectoryIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST) as $item) {
		if (strpos($source.DIRECTORY_SEPARATOR.$iterator->getSubPathName(),"oldVersion")==false) {
			// Don't move plugins away
			$testPath = $source.DIRECTORY_SEPARATOR.$iterator->getSubPathName();
			$testPath = str_replace("\\","/",$testPath);
			if (strpos($testPath,"/plugins/")==false) {
				if ($item->isDir()) {
					mkdir($dest.DIRECTORY_SEPARATOR.$iterator->getSubPathName(), 0705);
				} else {
					rename($item, $dest.DIRECTORY_SEPARATOR.$iterator->getSubPathName());
				}
			}
		}
	}
	$icv_url = "https://icecoder.net/latest-version.txt";
	echo 'Detecting current version of ICEcoder...<br>';
	if (ini_get('allow_url_fopen')) {
		$icvInfo = @file_get_contents($icv_url,false,$context);
		if (!$icvInfo) {
			$icvInfo = file_get_contents(str_replace("https:","http:",$icv_url), false, $context);
		}
	} elseif (function_exists('curl_init')) {
		$ch = curl_init($icv_url);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$icvInfo = curl_exec($ch);
	} else {
		die('Sorry, couldn\'t figure out latest version.');
	}
	echo 'Latest version of ICEcoder is '.$icvInfo.'<br>';
	openZipNew($icvInfo);
}

function openZipNew($icvInfo) {
	global $context;

	echo 'Retrieving zip from ICEcoder site...<br>';
	$source = 'ICEcoder v'.$icvInfo;
	$target = '../';

	$remoteFile = 'https://icecoder.net/ICEcoder-v'.(str_replace(" beta", "-beta",$icvInfo)).'.zip';
    	$file = "../tmp/new-version.zip";
	if (ini_get('allow_url_fopen')) {
		$fileData = @file_get_contents($remoteFile,false,$context);
		if (!$fileData) {
			$fileData = file_get_contents(str_replace("https:","http:",$remoteFile), false, $context);
		}
	} elseif (function_exists('curl_init')) {
	    	$client = curl_init($remoteFile);
    		curl_setopt($client, CURLOPT_RETURNTRANSFER, 1);  //fixed this line
		$fileData = curl_exec($client);
	} else {
		die('Sorry, couldn\'t get latest version zip file.');
	}
	echo 'Storing zip file...<br>';
	file_put_contents($file, $fileData);

	$zip = new ZipArchive;
	$zip->open($file);

	echo 'Copying over zip dirs & files...<br>';
	for($i=0; $i<$zip->numFiles; $i++) {
		$name = $zip->getNameIndex($i);

		// Skip files not in $source
		if (strpos($name, "{$source}/") !== 0) continue;

		// Determine output filename (removing the $source prefix and trimming traiing slashes)
		$file = $target.substr($name, strlen($source)+1);

		// Create the directories if necessary
		$dir = dirname($file);
		if (!is_dir($dir)) mkdir($dir, 0777, true);

		// Read from Zip and write to disk
		$fpr = $zip->getStream($name);
		if (!is_dir($file)) {
			$fpw = fopen($file, 'w');
			while ($data = fread($fpr, 1024)) {
				fwrite($fpw, $data);
			}
			fclose($fpw);
		}
		fclose($fpr);
	}
	echo 'Finished copying over zip dirs & files...<br>';
	copyOverSettings($icvInfo);
}

function transposeSettings($oldFile,$newFile,$saveFile) {
	global $context;

	echo '- Getting old and new settings...<br>';
	// Get old and new settings and start a new $contents
	$oldSettingsContent = file_get_contents($oldFile,false,$context);
	$oldSettingsArray = explode("\n",$oldSettingsContent);
	$newSettingsContent = file_get_contents($newFile,false,$context);
	$newSettingsArray = explode("\n",$newSettingsContent);
	$contents = "";

	echo '- Transposing settings...<br>';
	// Now need to copy the old settings over to new settings...
	for ($i=0; $i<count($newSettingsArray); $i++) {
		$thisKey = "";
		if (strpos($newSettingsArray[$i],'"') > -1) {
			$thisKey = explode('"',$newSettingsArray[$i]);
		}
		if (is_array($thisKey)) {
			$thisKey = $thisKey[1];
		}
		// We set the new line to begin with
		$contentLine = $newSettingsArray[$i].PHP_EOL;
		for ($j=0; $j<count($oldSettingsArray); $j++) {
			// And override with old setting if not blank, not in excluded array and we have a match
			if ($thisKey != "" && $thisKey != "versionNo" && $thisKey != "codeMirrorDir" && strpos($oldSettingsArray[$j],'"'.$thisKey.'"') > -1) {
				$contentLine = $oldSettingsArray[$j].PHP_EOL;
				// If the old setting we're copying over isn't replacing the last line and doesn't end in a comma (after an rtrim to remove line endings), add one
				if ($i != count($newSettingsArray)-1 && substr(rtrim($contentLine),-1) != ",") {
					$contentLine = str_replace(PHP_EOL,",".PHP_EOL,$contentLine);	
				}
			}
		}
		$contents .= $contentLine;
	}
	echo '- Saving old settings to new settings file...<br>';
	$fh = fopen($saveFile, 'w') or die("Sorry, cannot update ".$saveFile);
	fwrite($fh, $contents);
	fclose($fh);
}

function copyOverSettings($icvInfo) {
	global $updateDone;

	// System settings
	echo 'Transposing system settings...<br>';
	transposeSettings(PATH."lib/config___settings.php","config___settings.php","config___settings.php");

	// Users template settings
	echo 'Transposing users template settings...<br>';
	transposeSettings(PATH."lib/config___users-template.php","config___users-template.php","config___users-template.php");

	// Users settings files
	$fileList = scanDir(PATH."lib/");
	for ($i=0; $i<count($fileList); $i++) {
		if (strpos($fileList[$i],"config-") > -1) {
			echo 'Transposing users settings file '.$fileList[$i].'...<br>';
			transposeSettings(PATH."lib/".$fileList[$i],"config___users-template.php",$fileList[$i]);
		}
	}

	echo 'All update tasks completed...<br>';
	$updateDone = true;
}

startUpdate();
if ($updateDone) {
	echo 'Updated successfully!<br><br>';
	echo 'Restarting ICEcoder...';
	echo '<script>alert("'.$t['Update appears to...'].'");window.location = "../?display=updated&csrf='.$_SESSION["csrf"].'";</script>';
} else {
	echo 'Something appears to have gone wrong :-/<br><br>';
	echo 'Please report bugs at <a href="https://github.com/mattpass/ICEcoder" style="color: #fff">https://github.com/mattpass/ICEcoder</a><br><br>';
	echo 'You can recover the old version from ICEcoder\'s tmp dir';
}
?>
</body>

</html>