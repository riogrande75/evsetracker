#!/usr/bin/php
<?php
//definitions
$debug=1; //debug outputs
$info=0; //Schreibe ins InfoFile
$meternfile = "/tmp/EVSEWIFI.txt";
$logfilename='/var/log/evse_';
$adaptfile = "/tmp/adapt.txt";
$hpower = 0; //actual house power consumption
$stufe = 0; // aktuelle Ladestufe = Ampere 6-16, also ~4-11kW Ladeleistung
$stufealt = 0; //vorherige Stufe
$zaehler = 0; //Loop Counter
$fivequeue = array(); //5 Minuten Schnittarray
$vehicleState = 0; // Zustand der Autos an Ladestation
$vehicleState_old = 0; // alter Zustand der Autos an Ladestation
$evseState = false; // EVSE State, TRUE=EIN
$evseStateI = 0; //EVSE State inverted?
$schnittcount = 60; //Set to 60=5 Minutes, lower for debug
$durchschnitt = 5000; //aktueller 5 Minuten Schnitt
$battcap = 0; //Batteriekapazität lt.WR
$battpower = 0; //Actual Battery charing power

logging("*** EVSE ADAPT NEUSTART ***");
//open sh mem obj for reading actual values
$sh_sdm6301 = shmop_open(0x6301, "a", 0, 0);
if (!$sh_sdm6301) {
    echo "Couldn't open shared memory segment\n";
}
//checken ob $adapt file vorhanden ist
if (!file_exists($adaptfile)) {
	$file_handle = fopen($adaptfile, 'a+');
	fwrite($file_handle, 'EIN');
	fclose($file_handle);
	echo "Adapt-File erstellt!\n";
}

//read initial counter for meterN logging
$zeilen = file("$meternfile");
$zeile7 = $zeilen[6];
$evseglobal = str_replace("7(","",str_replace("*kWh)\n","",$zeile7));
if($debug) echo "Gesamtzähler $evseglobal geladen!\n";

// MAIN
while(true)
{
//	echo date('Y-M-d H:i:s')."\n";
//	$zaehler++;
//	echo "Zähler: $zaehler\n";
	$hpower = shmop_read($sh_sdm6301, 18, 6); //Haus-Stromverbrauch erfragen
        $battcap = file_get_contents("/tmp/inv1/BATTCAP.txt");
        $battpower = round(file_get_contents("/tmp/inv1/BATTPOWER.txt"));
	if($debug) echo date('Y-M-d H:i:s')." \033[1mHAUSPOWER:$hpower Schnitt:$durchschnitt STUFE $stufe BATTCAP:$battcap BATTPOW:$battpower\033[0m\n";

	$stufe = (int)((($hpower +500)*-1)/690); //Stelle Ladestrom (6A-16A) ein, 500W Puffer
//	$stufe = (int)((($hpower - $battpower +500)*-1)/690);// mit WR

	// Alter Gesamtzählerstand wird in variable evseglobal_old gespeichert
	$evseglobal_old = $evseglobal;
	if($debug) echo "EVSEGOLBAL_OLD: $evseglobal_old \n";
	//Check if adaptive charging is allowed
	$adapt = substr(file_get_contents($adaptfile),0,3);
	if($adapt == "AUS"){
		if($debug) echo "Adatptives Laden DEAKTIViert\n!";
		sleep(30);
		continue;
		}
	// Query EvseWifi for all parameters
	getParameters();
	// update des Gesamtzählerstandes für meterN
	$evseglobal= $evseglobal_old + $energy;

	// 5 Minuten Schnitt wird errechnet
//	array_unshift($fivequeue,($hpower+500)); //Hauspower mit 500W Puffer
	array_unshift($fivequeue,($hpower - $battpower +500)); //Hauspower abzgl. BATTPOWER mit 500W Puffer
	$queue_length = count($fivequeue);

	// Wenn wir von 5 Minuten Werte haben Durschnitt berechnen für EIN/AUS Schalten
	if($queue_length >= $schnittcount+1) { //Should be 61 loops/values for ~5 min.
		array_pop($fivequeue);
               	$durchschnitt = (int)(array_sum($fivequeue) / $schnittcount);
//		if($debug) print_r($fivequeue);
//		if($debug) logging("\033[1mSCHNITT:$durchschnitt\033[0m");
		}
	//Check if car is fully charged
	if($vehicleState_old==3 && $vehicleState==2) echo "AUTO VOLL, Laden beendet!\n";
	if($vehicleState_old==2 && $vehicleState==2 && $evseState==1) echo "AUTO VOLL - laden nicht notwendig.\n";

	if($evseState==0 && $vehicleState==2) //Auto angesteckt, lädt aber noch nicht
		{
		if(count($fivequeue)<=$schnittcount) // Checken ob 5 Minuten Wert da ist
		{
			//echo "POWER:".( $hpower + 500)." \n";
			if(($hpower - 500) < -4500){
			echo "kein schnitt, aber $hpower W\n";
				if($debug) logging("KEIN Schnitt aber > 4.5kW => EVSE ein!");
                        	setActive();
			}
		}
		if(count($fivequeue)>=$schnittcount) //Wir haben einen 5Minuten Schnitt und aktivieren EVSE
		{
			if($durchschnitt < -4500 and $hpower < -4500){
//mit WR			if($durchschnitt < -4500) {
				if($debug) logging("Schnitt > 4.5kW=> EVSE ein!");
				setActive();
				}
			}
		}
        if($evseState==true && $vehicleState==3) //Auto lädt, aber nicht mehr genug Strom von PV
                {
		if(count($fivequeue)>=$schnittcount)
		{
                	if($durchschnitt > 0){
                		if($debug) logging("Schnitt kleiner 100W => EVSE aus!");
                		setActiveOff();
                	}}}
	if(!$evseState){
		$evseStateI="AUS";
		}else{
		$evseStateI="EIN";
		}
	if($debug) logging("HAUS:$hpower 5MINSchnitt:$durchschnitt STUFEALT:$stufealt NEU:$stufe EVSE:$evseStateI");
	sleep(5);
if($evseState==true) //EVSE Aktiv
	{
	if($stufe==0){
		echo "=GLEICH BLEIBEN, Stufe $stufe\n"; // gleichbleiben
		sleep(5);
		$vehicleState_old = $vehicleState;
		continue;
		}
	if($stufe<0 and $stufealt>6){ //kleiner werden
//		echo "\033[31m KLEINER werden\033[39m\n";
		$stufe=$stufealt-1;
		if($stufe<6)
			{
			$stufe=6;
			if($durchschnitt > 4500){
				if($debug) echo "Schnitt zu klein => Ladende!\n";
				setActiveOff();
				}
			} else {
			echo "\033[32m KLEINER werden\033[39m\n";
			}
 		setCurrent($stufe);
		if($debug) logging("Stufe $stufe gesetzt!");
		sleep(60); //Wait for 1 min to let things cool down
		$vehicleState_old = $vehicleState;
		continue;
		}
	if($stufe>=1 and $stufealt>=6){ //größer werden
//		echo "\033[32m GROESSER werden\033[39m\n";
		$stufe=$stufealt+1;
		if($stufe>16)
			{
			$stufe=16;
			sleep(60); //Wait for 1 min to let things cool down
			$vehicleState_old = $vehicleState;
			continue;
			} else {
			echo "\033[32m GROESSER werden\033[39m";
			}
		setCurrent($stufe);
		if($debug) echo "\nStufe $stufe gesetzt!\n";
		sleep(5);
		$vehicleState_old = $vehicleState;
		continue;
 		}
	echo "\033[32m***** STATUS NICHT ABGEFANGEN !! ******\033[39m\n";
	sleep(5);
	}
}
// END MAIN

function getParameters(){
global $debug,$info,$stufe,$stufealt,$evseState,$vehicleState,$meternfile,$evseglobal,$energy;
// API UTR von EVSE-WiFi
$url='http://192.168.1.69/getParameters';
$ch=curl_init();
$timeout=5;

curl_setopt($ch, CURLOPT_URL, $url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);

$result=curl_exec($ch);
curl_close($ch);
$decoded = json_decode($result,true);
$estate = [
    "0" => "Gesperrt",
    "1" => "Aktiv",
];
$vstate = [
    "1" => "Bereit",
    "2" => "Angeschlossen",
    "3" => "AUTO lädt"
];
if($info) {
//	echo date('Y-M-d:H:i:s')."\n";
//	print_r($decoded);
	echo "	Fahrzeugstatus: ".$vstate[$decoded['list']['0']['vehicleState']]."\n";
	echo "	evseStatus: ".$estate[$decoded['list']['0']['evseState']]."\n";
	echo "	Aktueller Ladestrom: ".$decoded['list']['0']['actualCurrent']."A\n";
	echo "	Aktuelle Ladeleistung: ".$decoded['list']['0']['actualPower']."kW\n";
	$secs =(int)(($decoded['list']['0']['duration'])/1000);
	echo "	Ladedauer: ".(int)($secs/60)." Minuten und ".($secs%60)." Sekunden\n";
	echo "	Geladene Energie: ".$decoded['list']['0']['energy']."kWh\n";
	echo "	Energiezählerstand: ".$decoded['list']['0']['meterReading']."\n";
}
	$vehicleState = $decoded['list']['0']['vehicleState']; //Fahrzeugstatus (1: bereit | 2: Fahrzeug angeschlossen | 3: Fahrzeug lädt)
	$evseState = (int)$decoded['list']['0']['evseState']; //EVSE Status (true: EVSE freigeschaltet | false: EVSE gesperrt)
	$actualCurrent = $decoded['list']['0']['actualCurrent']; //Aktueller Ladestrom in A (z.B. 20)
	$actualPower = $decoded['list']['0']['actualPower']; //Aktuelle Ladeleistung (nur wenn Stromzähler angeschlossen ist)
	$duration = $decoded['list']['0']['duration']; // charging duration in milliseconds
	$energy = $decoded['list']['0']['energy']; // charged energy of the current charging process in kWh
	$meterReading = $decoded['list']['0']['meterReading']; // actual meter reading in kWh
	$stufealt = $actualCurrent;
//	update_meter($energy);
	// update meterN file data
	$fd = fopen($meternfile,"w");
	fprintf($fd,"1(%.2f*kWh)\n",$energy);
	fprintf($fd,"2(%.2f*kW)\n",$actualPower);
	fprintf($fd,"3(%d\n",$vehicleState);
	fprintf($fd,"4(%b\n",$evseState);
	fprintf($fd,"5(%d*A\n",$actualCurrent);
	fprintf($fd,"6(%.2f*kW)\n",$meterReading);
	fprintf($fd,"7(%.2f*kWh)\n",$evseglobal);
	fclose($fd);
}
function update_meter($energy)
{
	global $debug,$file;
	$file = '/tmp/evseenergy.txt';
	$fp = fopen($file,"w");
	fwrite($fp, $energy);
	fclose($fp);
}
function setActive(){
	global $debug,$info,$evseState;
	$url='http://192.168.1.69/setStatus?active=true';
	$ch=curl_init();
	$timeout=5;
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
	$result=curl_exec($ch);
	curl_close($ch);
        $evseState = TRUE;
        logging("Neuer EVSE Status: $evseState");
	//var_dump(json_decode($result));
}
function setActiveOff(){
	global $debug,$info,$evseState;
	$url='http://192.168.1.69/setStatus?active=false';
	$ch=curl_init();
	$timeout=5;
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
	$result=curl_exec($ch);
	curl_close($ch);
        $evseState = FALSE;
	logging("Neuer EVSE Status: $evseState");
	//var_dump(json_decode($result));
}
function setCurrent($curr){
	global $debug,$info;
	if($curr < 6 || $curr > 16)
		{
		if($debug) echo date('Y-M-d:H:i:s')." Illegal Current set!\n";
		return false;
		}
	$url='http://192.168.1.69/setCurrent?current='.$curr;
	if($info) echo "URL:".$url."\n";
	$ch=curl_init();
	$timeout=5;
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
	$result=curl_exec($ch);
	curl_close($ch);
	if($info) echo date('Y-M-d:H:i:s')."\n";
	if($info) echo "CurrentSet:".$result."\n";
	//var_dump(json_decode($result));
}
function logging($txt, $write2syslog=false)
{
        global $debug,$logfilename;
        $fp_log = @fopen($logfilename.date("Y-M-d").".log", "a");
	{
	$dt = new DateTime(date("Y-m-d H:i:s"));
	$logdate = $dt->format("Y-m-d H:i:s");
	fwrite($fp_log, $logdate." $txt\n");
	}
}
function getLog(){
	$url='http://192.168.1.69/getLog';
	$ch=curl_init();
	$timeout=5;

	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);

	$result=curl_exec($ch);
	curl_close($ch);
	echo date('Y-M-d:H:i:s')."\n";
	echo $result;
	var_dump(json_decode($result));
}
?>
