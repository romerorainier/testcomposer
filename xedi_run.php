#!/usr/bin/php
<?php

require_once "lib/ReadSets.php";
require_once "lib/Smbclient.php";
require_once "lib/sysLogger.php";

$common=new loadmain();
$syslog = new Syslog();
$basedate = new DateTime("now");

$queuelist=array();
$convlist=array();
$force_exit = false;

declare(ticks = 1);
register_tick_function('check_exit');
pcntl_signal(SIGTERM, 'sig_handler');
pcntl_signal(SIGINT, 'sig_handler');

$date=$basedate->format('Y-m-d H:i:s');

$load=$common->getini(__DIR__."/xedi.ini",true)['path'];
$models=$common->getini(__DIR__."/models.ini",true);
$setcred = file_get_contents("/etc/default/set.cred"); #remote credential
$set=json_decode($setcred);

$ip = gethostbyname($set->dfs);
$shared = $set->shr;
$smbc = new smbclient ("//".$ip.DIRECTORY_SEPARATOR.$shared, $set->usr, $set->pwd);

$spawn = $set->spawn;

$syslog->SetServer($set->logserver);
$syslog->SetHostname($set->hostname);
$syslog->SetPort($set->logport);
$syslog->SetIpFrom($set->clientip);
$syslog->SetProcess($set->process);
$syslog->SetType($set->type);

$ipath=$load['in'];
$outpath=$load['out'];
$failedpath=$load['failed'];
$archivepath=$load['archive'];
$errorpath=$load['error'];
$tmp=$load['tmp'];

try
{
	$syslog->Loginfo($message="service up and running",
									$ofname=null,
									$fname=null,
									$sender=null,
									$status='Notice',
									$failreason=null,
									$location=null);
	while (true)
	{
		if(count($queuelist)<=$spawn)
		{
			parentProc();
		}
		sleep(5);
		isRunning();
	}
}

catch (Exception $e)
{
	$msg=$e->getMessage();
		$syslog->Loginfo($message=$msg,
										$ofname=null,
										$fname=null,
										$sender=null,
										$status='Warning/Catchable Error',
										$failreason=null,
										$location=null);
}

function sig_handler () {
		global $force_exit;
				$force_exit=true;
}

function check_exit () {
		global $force_exit,$syslog;
		if ($force_exit)
		{
			$syslog->Loginfo($message="service stop",
											$ofname=null,
											$fname=null,
											$sender=null,
											$status='Warning',
											$failreason=null,
											$location=null);
			exit();
		}
}

function parentProc()
{
	global $common,$load,$models,$syslog,$smbc,$queuelist,$convlist,$ipath,$tmp;

			foreach ($smbc->dir($ipath) as $folder)
			{
				if ($folder['isdir'])
				{
					if( isset($models[$folder['filename']]['config']) )
					{
							$subs[ ]=$folder['filename'];

							foreach($smbc->dir($ipath.$folder['filename']) as $items)
							{
									if ($items['isdir']<>1)
									{
										$smbfile=$ipath.$folder['filename'].DIRECTORY_SEPARATOR.$items['filename'];
										$tmpfile=$tmp.$folder['filename'].DIRECTORY_SEPARATOR.$items['filename'];
										#$basicname=$items['filename'];
										if (!file_exists($tmp.$folder['filename']))
										{
											@mkdir($tmp.$folder['filename'],0777);
										}
										if (!file_exists($tmpfile) and !in_array($tmpfile,$convlist))
										{
											$smbc->get($smbfile,$tmpfile);
											@chmod($tmpfile,0777);
											convertFile($tmpfile);
										}
									}
							}

					}
				}
			}

	$filelist=array();

			foreach ($subs as $folder)
			{
				if( isset($models[$folder]['config']) ){

					foreach ($models[$folder]['config'] as $pattern=>$model)
					{
						$temp=$tmp.$folder.DIRECTORY_SEPARATOR;
						$filelist["$model.ini"]=$common->setFilesModel($temp,"{$pattern}.{xlsx,csv,xls}");
					}
				}
			}

			foreach ($filelist as $model=>$files)
			{

				$itemfilter = array_filter($files);
				if (!empty($itemfilter))
				{

				foreach ($files as $file)
				{
					$basefile=basename($file);
					$tfile=escapeshellarg($file);
					$basename=getOrigName($basefile);
					$info=ensurepattern($basename,$file);

					if (!in_array($file, $queuelist))
					{
						if (!empty($info))
						{
							$orig=$info[0];
							$sender=$info[1];
							$command=__DIR__."/xedi.php -f $tfile -m $model -b $basename -o $orig -s $sender"; #execute using shebang
							#print $command;
							childProc($command,$file);
						}
					}
					else
					{
						print 'file already on queue';
					}
				}

				}
				else
				{
					print "no file to process\r\n";
				}
			}
}

function convertFile($file)
{
	global $convlist;
	$ext = pathinfo($file, PATHINFO_EXTENSION);
	#print $ext;
	if (file_exists($file) and $ext=='xls')
	{
		$bconvfile=str_replace('.xls','.xlsx',$file);
		$convfile=escapeshellarg($bconvfile);
		$ofile=escapeshellarg($file);
		#echo "ssconvert $file $convfile";
		#print "ssconvert $ofile $convfile";
		exec("ssconvert $ofile $convfile");
		unlink($file);
		$convlist[basename($bconvfile)]=basename($file);
	}
}

function getOrigName($file)
{
	global $convlist;

	if (isset($convlist[basename($file)]))
	{
		return basename($convlist[basename($file)]);
	}
	else {
		return basename($file);
	}
}

function ensurepattern($file,$tfile)
{
	global $ipath,$failedpath,$date;
	$ext = pathinfo($file, PATHINFO_EXTENSION);
	$basicfname=basename($file);
	$subf=basename(dirname($tfile));
	$temparray=array();
		if (strpos($basicfname, '__') !== false)
		{
			$tempfname=explode('__',$basicfname);
			$filename=$tempfname[0];
			$sender=str_replace(".$ext","",$tempfname[1]);
			if (strpos($sender, '@') !== false)
			{
				array_push($temparray,$filename,$sender);
				return $temparray;
			}
		}
		else
		{
			unlink($tfile);
			Move("$ipath$subf/$basicfname",$failedpath."NoSender/",$date);
			throw new Exception("Email information not found in file $basicfname");
		}
}

function Move($origLoc,$destLoc,$date=null)
{
	global $smbc;

	if ($date!=null)
	{
			$ext = pathinfo($origLoc, PATHINFO_EXTENSION);
			$listtoreplace=array(':','-',' ');
			$dt=str_replace($listtoreplace,'',$date);
			$tempfile=basename($origLoc,".$ext");
			$tempfile=$tempfile.$dt.".$ext";
	}
	else
	{
			$tempfile=basename($origLoc);
	}
		$smbc->mkdir($destLoc);
	if (!$smbc->rename($origLoc, $destLoc.$tempfile))
	{
		throw new Exception("failed to move file $tempfile");
	}
	else
	{
		return true;
	}

}

function childProc($command,$file)
{
	global $spawn,$syslog,$queuelist;

	$command = $command .'> /dev/null 2>&1 & echo $!';

		if (count($queuelist)<=$spawn)
		{
			$pid=exec($command, $output);
			$queuelist[$pid]=$file;
			$syslog->Loginfo($message="queue:$pid",
											$ofname=$file,
											$fname=null,
											$sender=null,
											$status='Notice',
											$failreason=null,
											$location=null);
		}
}

function isRunning() #check for child process running not to overlap parent execution.
{
	global $queuelist;
	foreach ($queuelist as $pid=>$file)
	{
		$running = posix_kill($pid,0);
		if (!$running){
			unset($queuelist[$pid]); #unset to clear pipe of child proc
		}
		else {
			echo 'still running';
		}
	}
}


?>
