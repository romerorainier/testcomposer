#!/usr/bin/php
<?php
require_once "lib/ReadSets.php";
require_once "lib/Smbclient.php";
require_once "lib/sysLogger.php";
require 'lib/PHPMailer/PHPMailerAutoload.php';

date_default_timezone_set('UTC');

$time_start = microtime(true);
$common=new loadmain();
$syslog = new Syslog();
$basepath=__DIR__;
$format=null;

$load=$common->getini(__DIR__."/xedi.ini",true)['path'];
$MString = file_get_contents(__DIR__."/custom/msg.mf"); #read messageformats
$MList=json_decode($MString); #load messageformats

$vStr = file_get_contents(__DIR__."/custom/vlst.vl"); #global loading of validation list
$vList = json_decode($vStr); #load val list

$setcred = file_get_contents("/etc/default/set.cred"); #read cred format

$set=json_decode($setcred); #load setting format

$syslog->SetServer($set->logserver);
$syslog->SetHostname($set->hostname);
$syslog->SetPort($set->logport);
$syslog->SetIpFrom($set->clientip);
$syslog->SetProcess($set->process);
$syslog->SetType($set->type);

$ip = gethostbyname($set->dfs);
$smbc = new smbclient ("//".$ip.DIRECTORY_SEPARATOR.$set->shr, $set->usr, $set->pwd);
$basedate = new DateTime("now");

$date=$basedate->format('Y-m-d H:i:s');

$result = date_format($basedate, 'y-m-d H:i');
$dt=explode(" ",$result);
$dt=str_replace("-","",$dt[0]);



parse_str(implode('&', array_slice($argv, 1)), $_GET);
$values=$common->getArgs($argv);

$filepath=$values['f'];
$model=$values['m'];
$basicfname=$values['b'];
$fname=$values['o'];
$sender=$values['s'];

$inpath=$load['in'];
$outpath=$load['out'];
$failedpath=$load['failed'];
$archivepath=$load['archive'];
$errorpath=$load['error'];
$tmp=$load['tmp'];

$subf=basename(dirname("$filepath"));


$LANG=$common->getini($file="$basepath/model/$model",true)['Base']['Lang']; #load only language on base

$FailedFlag=False;
$DataTmpError=array();

$filename=$common->_hash(basename($filepath));

$errfile="$tmp$filename.txt";
$errbname="$filename.txt";
$tmpedifile="$tmp$filename.edi";
$tmpedibname="$filename.edi";


$ext = pathinfo($filepath, PATHINFO_EXTENSION);

try
{
    _xedi($filepath,$inpath,$outpath,$archivepath,$failedpath,$errorpath,$tmp,$model,__DIR__);
}

catch (Exception $e) {

    $efolder=$e->getMessage();

  if ($efolder=="Uoffset")
  {
		unlink($filepath);
		Move("$inpath$subf/$basicfname","$failedpath$efolder/",$date);
		setmessageformat($MList,'General',$LANG);
		sendValidation($efname,null,null,$date,$attachments=null);
		$syslog->Loginfo($message="Undefined offset please check number of columns in module and expected range",
											$ofname=$fname,
											$fname=null,
											$sender=null,
											$status='Warning',
											$failreason='No File Created',
											$location="$failedpath$efolder/");
  }
	else
	{
		unlink($filepath);
		unlink($tmpedifile);
		$msg=$e->getMessage();
		$syslog->Loginfo($message=$msg,
											$ofname=$fname,
											$fname=null,
											$sender=null,
											$status='Warning/Catchable Error',
											$failreason=null,
											$location=null);
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
		throw new Exception($smbc->get_last_cmd_stdout());
	}
	else
	{
		return true;
	}

}


function _xedi($filepath,$inpath,$out,$archive,$failed,$errorpath,$tmp,$model,$basepath)
{
	global $smbc,$set,$vList,$MList,$syslog,$basicfname,$errfile,$errbname,$tmpedifile,$tmpedibname,$fname,$sender,$ext;

	$common=new loadmain();
	$subf=basename(dirname("$filepath"));

	echo "processing file: $fname\r\n";

	#initialization

	$ctemp=null;
	$basedate = new DateTime("now");

	$fpdate=$basedate->format('Y-m-d H:i:s');

	$result = date_format($basedate, 'y-m-d H:i');
	$dt=explode(" ",$result);
	$date=str_replace("-","",$dt[0]);
	$time=$dt[1];
	#base variables
	$row=null;
	$range=null;
	$headers=null;
	$delimiter=null;

	$max=65000;


		$data=$common->getini($file="$basepath/model/$model",true);


    if (isset($data["Base"])) {
        #load all base

        $base=$data['Base'];

				if (isset($base['Type']))
				{
					$Type=$base['Type'];

					if (isset($data[$Type]))
					{
			        #load Msharp ini info
							$msHead=array();
							$Tbase=$data[$Type];

							$MSstart=$Tbase['rowstart'];
			        $MSrowend=$Tbase['rowend'];
			        $MSrange=$Tbase['columrange'];
							$MSrs=new ReadSets($filepath,$MSstart,$MSrowend,$MSrange);
							$msHead=$MSrs->Fheader();
							$tcustomer=$Tbase['Customer'];
							$tcarrier=$Tbase['SCAC'];
							$tregion=$Tbase['Region'];
							$tmodel=$Tbase['ModelOverride'];

			        $Customer=$msHead[$tcustomer];
							$VendorName=$msHead[$tcarrier];
							$model="$msHead[$tmodel].ini";
							$Region=$msHead[$tregion];
							#override model to point M# models
							$modelfile="$basepath/model/$Type/$model";
							if (file_exists($modelfile))
							{
								$data=$common->getini($file=$modelfile,true);
								$base=$data['Base'];
							}
							else
							{
								throw new Exception("directory $modelfile does not exist");
							}

			    }
				}

        $row=$base['rowstart'];
        $range=$base['columrange'];

        #base columns default tabular reading
        $columns=$data['Columns'];
        $headers=$columns['value'];

        #set delimiter
        $delimiter=$base['delimiter'];
        #General
				$LANG=$base['Lang'];
				if ($VendorName==null and $Customer==null)
				{
					$VendorName=$base['SCAC'];
	        $Customer=$base['Customer'];
				}

    }

    if (isset($data["CustomBase"]))
		{
        #load all customBase
        $Cbase=$data["CustomBase"];

        #customBaseConfig
        $Crowstart=$Cbase['rowstart'];
        $Crowend=$Cbase['rowend'];
        $Crange=$Cbase['columrange'];

        #preset columns
        $Ccolumns=$data["CustomBaseColumns"];
        $Cheaders=$Ccolumns["value"];

        if ($ext!='xlsx' and $ext!='csv')
        {
        #create reader for custom reading horizontal base on parameters given
		        $rscustom=new ReadSets($file=$filepath,$startrow=$Crowstart,$endrow=$Crowend,$crange=$Crange);
						$rscustom->setdelimiter($delimiter);
						$rscustom->setvalidationList($vList);
						$ctemp=$rscustom->horizontalRead($Cheaders);
        }
        else
        {
        		$rscustom=new ReadSets($file=$filepath,$startrow=$Crowstart,$endrow=$Crowend,$crange=$Crange);
						$rscustom->setdelimiter($delimiter);
						$rscustom->setvalidationList($vList);
						$ctemp=$rscustom->cRead($Cheaders);
        }

    }


#start process here
$rs=new ReadSets($file=$filepath,$startrow=$row,$endrow=$max,$crange=$range,$header=$headers);
$rs->setvendor($VendorName);
$rs->setcustomer($Customer);
$rs->settmpfile($tmpedifile);
$rs->seterrorfile($errfile);
$rs->setdelimiter($delimiter);
$rs->setvalidationList($vList);

	if ($ext!='xlsx' and $ext!='csv')
	{
			$tmpfile=$rs->tabToArray($ctemp);
			if (file_exists($errfile) and file_exists($tmpfile))
			{
				$smbc->put($errfile,"$errorpath$errbname");
					if (Move("$inpath$subf/$basicfname",$failed."Validation/",$fpdate))
					{
						$errortxt=file_get_contents($errfile);
						#message sending
						setmessageformat($MList,'Failed',$LANG);
						sendValidation($fname,$sender,$errortxt,$date,$attachments=$errfile);

						unlink($tmpfile);
						unlink($errfile);
						unlink($filepath);

						$syslog->Loginfo($message="check error txt on path $errfile",
														$ofname=$fname,
														$fname=null,
														$sender=$sender,
														$status='Failed',
														$failreason="File failed error(s) found on file",
														$location=$failed."Validation/");
					}

			}
			else
			{
					$smbc->put($tmpfile,"$out$tmpedibname");
				  if (Move("$inpath$subf/$basicfname",$archive,$fpdate))
					{
						#message sending
						setmessageformat($MList,'Success',$LANG);
						sendValidation($fname,$sender,null,$date,$attachments=null);

						unlink($tmpfile);
						unlink($filepath);

						$syslog->Loginfo($message="File successfully processed",
														$ofname=$fname,
														$fname=$tmpedifile,
														$sender=$sender,
														$status='Success',
														$failreason=null,
														$location=$archive);
					}

			}
	}
	else
	{
			$tmpfile=$rs->readbysubsets($ctemp);
			if (file_exists($errfile) and file_exists($tmpfile))
			{
					$smbc->put($errfile,"$errorpath$errbname");
					if (Move("$inpath$subf/$basicfname",$failed."Validation/",$fpdate))
					{
						$errortxt=file_get_contents($errfile);
						#message sending
						setmessageformat($MList,'Failed',$LANG);
						sendValidation($fname,$sender,$errortxt,$date,$attachments=$errfile);

						unlink($tmpfile);
						unlink($errfile);
						unlink($filepath);

						$syslog->Loginfo($message="check error txt on path $errfile",
														$ofname=$fname,
														$fname=null,
														$sender=$sender,
														$status='Failed',
														$failreason="File failed error(s) found on file",
														$location=$failed."Validation/");
					}

			}
			else
			{
				$smbc->put($tmpfile,"$out$tmpedibname");
					if (Move("$inpath$subf/$basicfname",$archive,$fpdate))
					{
							unlink($tmpfile);
							unlink($filepath);

							#message sending
							setmessageformat($MList,'Success',$LANG);
							sendValidation($fname,$sender,null,$date,$attachments=null);

							$syslog->Loginfo($message="File successfully processed",
															$ofname=$fname,
															$fname=$tmpedifile,
															$sender=$sender,
															$status='Success',
															$failreason=null,
															$location=$archive);

					}

			}
	}


}

function setmessageformat($msglist,$type,$lang)
{
	global $format;
	$format=$msglist->{$type}->{$lang};
}

function sendValidation($file,$to,$errortext,$date,$attachments=null)
{
	global $set,$format,$syslog;
	$mail = new PHPMailer;

	$mail->isSMTP();                                      // Set mailer to use SMTP
	$mail->Host = $set->smtp;
	$mail->SMTPAutoTLS = false;                        // Enable TLS encryption, `ssl` also accepted
	$mail->Port = 25;                                    // TCP port to connect to

	$mail->setFrom($set->from, 'Billing Report Service');
	if($to!==null)
	{
		$mail->addAddress($to);      // Add a recipient
	}
	if ($set->cc!==null)
	{
			$recipients=explode(',',$set->cc);

			foreach($recipients as $email)
			{
			   $mail->addBCC(trim($email));
			}
	}
	if ($attachments!==null)
	{
		$mail->addAttachment($attachments);
	}


	eval("\$message = \"$format->Body\";");

	$mail->CharSet = "UTF-8";  // Set email format to HTML

	$mail->Subject = $format->Subject;
	$mail->Body    = $message;

	if(!$mail->send()) {

		$syslog->Loginfo($message="Message could not be sent",
										$ofname=$file,
										$fname=null,
										$sender=$to,
										$status='Failed',
										$failreason=escapeshellarg($mail->ErrorInfo),
										$location=null);
	} else {

		$syslog->Loginfo($message="Message has been sent",
											$ofname=$file,
											$fname=null,
											$sender=$to,
											$status='Notice',
											$failreason=null,
											$location=null);
	}

}

$time_end = microtime(true);

//dividing with 60 will give the execution time in minutes other wise seconds
$execution_time = ($time_end - $time_start)/60;


//execution time of the script
echo 'Total Execution Time:'.$execution_time.' Mins';

?>
