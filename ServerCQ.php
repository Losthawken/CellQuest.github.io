<?php
$stateFile = "CellQuestState.json";
$logFile   = "CellQuestWins.log";
$WAILog    = "WAIlog.txt";

// Output text/plain for debugging
header("Content-Type: text/plain");

$action = $_GET["action"] ?? "";

// ---------------------------------------------------------
// Utility functions
// ---------------------------------------------------------

function loadState($file) {
    if (!file_exists($file)) return null;
    $txt = file_get_contents($file);
    return json_decode($txt, true);
}

function saveState($file, $state) {
    file_put_contents($file, json_encode($state, JSON_PRETTY_PRINT));
}

function logWin($file, $state) {
    $msg = date("Y-m-d H:i:s") . " -- " . json_encode($state["teamCounts"]) . "\n";
    file_put_contents($file, $msg, FILE_APPEND);
}
function randomizer($arr) {
    // pick a random index > 0
    $i = rand(1, count($arr) - 1);

    // apply chaos
    $arr[$i] = $arr[$i] * ((rand(0,160)/100) + 0.5);

    // clamp between 1 and 10
    if ($arr[$i] < 1)  $arr[$i] = 1;
    if ($arr[$i] > 10) $arr[$i] = 10;

    // round up because you seem to enjoy integers
    $arr[$i] = ceil($arr[$i]);

    return $arr;
}

function randomizerWAI(array $arr) {
    // If the array has no index beyond 0, nothing to randomize
    if (count($arr) < 2) return $arr;

    // Pick any index except 0
    $index = rand(1, count($arr) - 1);

    // Random multiplier: 0.5x to 2.1x (same pattern you used before)
    $multiplier = ((rand(0, 160) / 100) + 0.5);

    // Only modify if numeric
    if (is_numeric($arr[$index])) {
        $arr[$index] = ceil($arr[$index] * $multiplier);
    }
    return $arr;
}

function loadBestTeamSettings($filename) {
    if (!file_exists($filename)) {
        //Default: teamCount=1, agr=1, def=1, vrd=1
        return [1, 1, 1, 1];
    }

    $lines = file($filename, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    $first = explode(",", $lines[0]);
    return [
        intval($first[0]),
        floatval($first[1]),
        floatval($first[2]),
        floatval($first[3])
    ];
}

function saveTeamRoundResult($filename, $teamCount, $agr, $def, $vrd) {
    $entry = "$teamCount,$agr,$def,$vrd";

    // Load previous lines (if any)
    $lines = file_exists($filename)
        ? file($filename, FILE_IGNORE_NEW_LINES)
        : [];

    if (count($lines) === 0) {
        // Create file fresh
        file_put_contents($filename, $entry . "\n");
        return;
    }

    // Compare with best line
    list($bestCount) = explode(",", $lines[0]);

    if ($teamCount > intval($bestCount)) {
        // New champion
        array_unshift($lines, $entry);
    } else {
        // Mediocre, append to the bottom
        $lines[] = $entry;
    }

    file_put_contents($filename, implode("\n", $lines) . "\n");
}


// ---------------------------------------------------------
// ACTION HANDLER
// ---------------------------------------------------------
switch ($action) {

    // -----------------------------------------------------
    // GET
    // -----------------------------------------------------
    case "get":
        $state = loadState($stateFile);
        if (!$state) {
            echo json_encode(["error" => "No state found"]);
            exit;
        }
        echo json_encode($state);
        exit;

    // -----------------------------------------------------
    // RESET
    // -----------------------------------------------------
    case "reset":

        $oneSide = 60;
        $cellSize = 10;

        // Default team settings
        $BlueSettings   = [10,1,1];
        $RedSettings    = [1,10,1];
        $YellowSettings = [1,1,1];
        $GreenSettings  = [1,1,10];
        $PurpleSettings  = [10,10,10];

        // --- Load best WAI from WAIlog.txt ---
        $WAISettings = [1,5486,3474,120,0.1,3,17,20]; // fallback
        if (file_exists($WAILog)) {
            $lines = file($WAILog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!empty($lines)) {
                $first = explode(",", $lines[0]);
                if (count($first) >= 5) $WAISettings = array_map('floatval', $first);
            }
        }

        // Randomize WAI settings500,1414.26,0.006296,0.8,0.8,0.8
        $WAISettings = randomizerWAI($WAISettings);

        // Initialize map coordinates
        $coordString = "";
        $pop = 1;
        $battle = 1;
        $terrainType = 2400;

        $P1 = ceil($oneSide/4)   . "-" . ceil($oneSide/4);
        $P2 = ceil($oneSide*3/4) . "-" . ceil($oneSide*3/4);
        $P3 = ceil($oneSide/4)   . "-" . ceil($oneSide*3/4);
        $P4 = ceil($oneSide*3/4) . "-" . ceil($oneSide/4);
        $P5 = ceil($oneSide/2) . "-" . ceil($oneSide/2);

        for ($y=1; $y<=$oneSide; $y++) {
            for ($x=1; $x<=$oneSide; $x++) {
                $coord = "$x-$y";
                $owner = "None";
                $P = [1,1,1];

                if ($coord === $P1) { $owner="TeamBlue";   $P=$BlueSettings; }
                if ($coord === $P2) { $owner="TeamRed";    $P=$RedSettings; }
                if ($coord === $P3) { $owner="TeamYellow"; $P=$YellowSettings; }
                if ($coord === $P4) { $owner="TeamGreen";  $P=$GreenSettings; }
                if ($coord === $P5) { $owner="TeamPurple";  $P=$PurpleSettings; }
                $coordString .= "$coord.$owner.$pop.$battle.$terrainType.".implode("_",$P).",";
            }
        }

        $state = [
            "oneSide"       => $oneSide,
            "cellSize"      => $cellSize,
            "reloadCount"   => 0,
            "totalCells"    => $oneSide*$oneSide,
            "teamCounts"    => ["blue"=>1,"red"=>1,"yellow"=>1,"green"=>1,"purple"=>1],
            "blueSettings"  => $BlueSettings,
            "redSettings"   => $RedSettings,
            "yellowSettings"=> $YellowSettings,
            "greenSettings" => $GreenSettings,
            "purpleSettings" => $PurpleSettings,
            "waiSettings"   => $WAISettings,
            "Finished"      => false,
            "coordString"   => rtrim($coordString,",")
        ];
       
        // Load best settings for each team
        $blueBest   = loadBestTeamSettings("blueLog.txt");
        $redBest    = loadBestTeamSettings("redLog.txt");
        $yellowBest = loadBestTeamSettings("yellowLog.txt");
        $greenBest  = loadBestTeamSettings("greenLog.txt");
        $purpleBest  = loadBestTeamSettings("purpleLog.txt");

        // Randomize
        //$blueBest   = randomizer($blueBest);
        //$redBest    = randomizer($redBest);
        //$yellowBest = randomizer($yellowBest);
       //$greenBest  = randomizer($greenBest);
       //$purpleBest  = randomizer($purpleBest);

        // Assign randomized agr/def/vrd into the game state
        $state["blueSettings"]   = array_slice($blueBest,   1);
        $state["redSettings"]    = array_slice($redBest,    1);
        $state["yellowSettings"] = array_slice($yellowBest, 1);
        $state["greenSettings"]  = array_slice($greenBest,  1);
        $state["purpleSettings"]  = array_slice($purpleBest, 1);


        saveState($stateFile,$state);

        header("Content-Type: application/json");
        echo json_encode($state);
        exit;

    // -----------------------------------------------------
    // ROUND
    // -----------------------------------------------------
    case "round":

        $raw = file_get_contents("php://input");
        file_put_contents("debug_input.log", $raw . "\n", FILE_APPEND);
        $incoming = json_decode($raw,true);
        $state = loadState($stateFile);
        $state["Finished"] = false;

        // apply incoming settings if provided
        if ($incoming) {
            $state["blueSettings"]   = $incoming["blueSettings"]   ?? $state["blueSettings"];
            $state["redSettings"]    = $incoming["redSettings"]    ?? $state["redSettings"];
            $state["yellowSettings"] = $incoming["yellowSettings"] ?? $state["yellowSettings"];
            $state["greenSettings"]  = $incoming["greenSettings"]  ?? $state["greenSettings"];
             $state["purpleSettings"]  = $incoming["purpleSettings"]  ?? $state["purpleSettings"];
            $state["waiSettings"]    = $incoming["waiSettings"]    ?? $state["waiSettings"];
        }
        
        // ----- SIMULATION -----
        $makeMapArray = explode(",", $state["coordString"]);
        $arrayCount   = count($makeMapArray);
        $oneSide      = $state["oneSide"];
        $reloadCount  = $state["reloadCount"];

        $BlueSettings   = $state["blueSettings"];
        $RedSettings    = $state["redSettings"];
        $YellowSettings = $state["yellowSettings"];
        $GreenSettings  = $state["greenSettings"];
        $PurpleSettings  = $state["purpleSettings"];
        $WAISettings    = $state["waiSettings"];

        $i = 0;
        for ($y = 0; $y < $oneSide; $y++) {
            for ($x = 0; $x < $oneSide; $x++) {

                $cell = $makeMapArray[$i];
                $parsed = explode(".", $cell);

                $owner       = $parsed[1];
                $pop         = (int)$parsed[2];
                $terrainType = (int)$parsed[4];

                if ($reloadCount > 0 && $owner != "None") {

                    if ($owner === "TeamBlue")   $P = $BlueSettings;
                    if ($owner === "TeamRed")    $P = $RedSettings;
                    if ($owner === "TeamYellow") $P = $YellowSettings;
                    if ($owner === "TeamGreen")  $P = $GreenSettings;
                    if ($owner === "TeamPurple")  $P = $PurpleSettings;

                    $agr = $P[0]*$WAISettings[11];
                    $def = $P[1]*$WAISettings[12];
                    $vrd = $P[2]*$WAISettings[13];

                    // neighbors
                    $neighbors = [];
                    $neighbors[] = ($i - $oneSide + $arrayCount) % $arrayCount; // north
                    $neighbors[] = ($i + $oneSide) % $arrayCount;               // south
                    $neighbors[] = ($i % $oneSide == $oneSide-1) ? $i-($oneSide-1) : $i+1; // east
                    $neighbors[] = ($i % $oneSide == 0) ? $i+($oneSide-1) : $i-1;         // west

                    // growth                         
                    $L  = 1000;                       //maxx population
                    $k = $WAISettings[1]*0.01;    //Growth rate
                    $GInf= $WAISettings[2];  //inflection point 
                    $growth =  $pop
                        * $k 
                        * (1 - $pop / $L)
                        * (1 + ($terrainType / $WAISettings[9]))
                        *(1 - 0.7 * ($agr / 10)); // logistic growth increment
                    $pop = $pop + $growth;
                    $pop = ceil(max(1, $pop));    

                    if ($pop >= 100) {
                       $pop = ceil($pop - $pop*($pop/$L));
                    }
                    if ($pop < 1) $pop = 1;

                    // terrain
                    $terrainType = $terrainType
                        ? max(1, min(2550, $terrainType + $WAISettings[10] - ceil($pop/$WAISettings[3]) - ceil($def/50) + ceil($vrd^2/10))): 2400;

                    // battles
                    $AttackRatio = 0.8;// * $WAISettings[4]*0.001;

                    /* Determine whether any neighbor is a different owner (Border detection) */
                    $Border = false;
                    foreach ($neighbors as $nIdx) {
                        $neighborCheck = explode(".", $makeMapArray[$nIdx]); // parse neighbor early and explicitly
                        $nOwnerCheck = $neighborCheck[1] ?? "None";
                        if ($nOwnerCheck != $owner) {
                        $Border = true;
                        break;
                        }
                    }

                    /* Now iterate neighbors properly */
                    foreach ($neighbors as $nIdx) {
                        $neighbor = explode(".", $makeMapArray[$nIdx]);   // parse this neighbor
                        $nOwner   = $neighbor[1] ?? "None";
                        $nPop     = (int) ($neighbor[2] ?? 0);
                        $nTerr    = (int) ($neighbor[4] ?? 0);
                        $p = $pop;
                        $n = $nPop;

                        // Ensure $E is always an array (so $E[1] won't blow up)
                        $E = [0,0,0];

                        // Do we attack?
                        if ($nOwner != $owner && $nPop > 0 && ($pop / max(1, $nPop)) > ($AttackRatio )) {

                            // assign enemy settings array (guaranteed to be an array)
                            if ($nOwner === "TeamBlue")   $E = is_array($BlueSettings)   ? $BlueSettings   : [0,0,0];
                            if ($nOwner === "TeamRed")    $E = is_array($RedSettings)    ? $RedSettings    : [0,0,0];
                            if ($nOwner === "TeamYellow") $E = is_array($YellowSettings) ? $YellowSettings : [0,0,0];
                            if ($nOwner === "TeamGreen")  $E = is_array($GreenSettings)  ? $GreenSettings  : [0,0,0];
                            if ($nOwner === "TeamPurple") $E = is_array($PurpleSettings) ? $PurpleSettings : [0,0,0];

                            // corrected score formulas (operators and parentheses fixed)
                            $myScore = $pop * rand(1,100) * (1 + ((2550 - $terrainType) / 2550 + $agr/8));
                            $eScore  = $nPop * rand(1,900) * (1 + ((2550 - $nTerr) / 2550 + $E[1]));

                            if ($myScore > $eScore) {
                                // conquest: write into parsed and update map
                                $neighbor[1] = $owner;
                                $loss = max($WAISettings[5] * 0.001 , 0.99);
                                $p = max(1, ceil($pop - $pop * $loss));
                                $n = max(1, ceil($nPop - $nPop * $WAISettings[6] * 0.01) + $pop * $loss / 4);
                                //$p = (int) ceil($pop);
                                $makeMapArray[$nIdx] = implode(".", $neighbor);
                            } else {
                                // failed attack
                                $p = max(1, ceil($pop - $pop * $WAISettings[6] * 0.01));                              
                                $n= max(1, ceil($nPop[2] - $nPop[2] * $WAISettings[6] * 0.01 / 3));
                                $makeMapArray[$nIdx] = implode(".", $neighbor); // keep neighboring pop consistent
                            }
                        }
                        // Population migration (unchanged logic, but using correct variables)

                        $baseRate   = max(1, $WAISettings[7] * 0.001);
                        $borderRate = max(1, $WAISettings[8] * 0.001);
                        $strongerNeighbor = ($nPop > $pop);

                        if ($Border !== true) {
                            if ($strongerNeighbor) {
                                $migrate = floor($pop * $baseRate);
                                if ($p < 50) { $migrate = 0; }
                                $p -= $migrate;
                                $n += $migrate;
                            }
                        } else {
                            if ($strongerNeighbor) {
                                $migrate = floor($pop * $borderRate);
                                $transfer = $migrate ; //* 1 + ($vrd - 1) / 9;
                               $p -= $transfer;
                               $n += $transfer;
                            } else {
                                $migrate = floor($pop * $baseRate);
                               // $p -= $migrate;
                               // $n += $migrate;
                            }
                        }
                                         
                        // safety clamps
                        if ($p < 1) $p = 1;
                        if ($n < 1) $n = 1;

                        // write results back into parsed and neighbor              
                        $neighbor[2] = max(1, (int) ceil($n));
                        $parsed[2]   = max(1, (int) ceil($p));
                        $makeMapArray[$nIdx] = implode(".", $neighbor); // keep neighbor in-sync                     
                    }
                }
                
                $parsed[4] = ceil($terrainType);
                $makeMapArray[$i] = implode(".", $parsed);

                $i++;
            }
        }

        // counting
        $counts = ["blue"=>0,"red"=>0,"yellow"=>0,"green"=>0,"purple"=>0];
        foreach ($makeMapArray as $c) {
            $p = explode(".", $c);
            switch ($p[1]) {
                case "TeamBlue":   $counts["blue"]++; break;
                case "TeamRed":    $counts["red"]++; break;
                case "TeamYellow": $counts["yellow"]++; break;
                case "TeamGreen":  $counts["green"]++; break;
                case "TeamPurple":  $counts["purple"]++; break;
            }
        }

        $state["teamCounts"] = $counts;
        $state["totalCells"] = count($makeMapArray);

        $state["coordString"] = implode(",", $makeMapArray);

        // increment reloadCount
        $state["reloadCount"]++;

        // win condition
        $minPct = 0.70;
        $total = $state["totalCells"];
        foreach ($state["teamCounts"] as $c) {
            if ($c < 1) {
                $state["Finished"] = true;
                
            }
        }
        if ($state["Finished"] == true) {
            logWin($logFile, $state);
            // --- Machine Learning Step for WAI (already implemented) ---
            $WAISets = $state["waiSettings"];
            $WAISets[0] = $state["reloadCount"];
            $lines = file_exists($WAILog) ? file($WAILog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [];
            if (empty($lines) || $WAISets[0] > intval(explode(",", $lines[0])[0])) {
                array_unshift($lines, implode(",", $WAISets));
                file_put_contents($WAILog, implode(PHP_EOL, $lines) . PHP_EOL);
            } 

            // --- TEAM LEARNING: Blue, Red, Yellow, Green ---
            $teams = ["blue", "red", "yellow", "green", "purple"];

            foreach ($teams as $team) {
                // Pull current round result
                $teamCount = $state["teamCounts"][$team];

                // Pull settings for this team
                $teamSettings = $state[$team . "Settings"];
                $agr = $teamSettings[0];
                $def = $teamSettings[1];
                $vrd = $teamSettings[2];

                // Build the exact line to store
                // format: count,agr,def,vrd
                $line = implode(",", [$teamCount, $agr, $def, $vrd]);

                $teamLog = "${team}Log.txt"; // adjust path if needed

                // Load existing file
                $tLines = file_exists($teamLog)
                    ? file($teamLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
                    : [];

                // Check if this line is superior to the top of the log
                $bestCount = empty($tLines) ? -INF : intval(explode(",", $tLines[0])[0]);

                if ($teamCount > $bestCount) {
                    array_unshift($tLines, $line);
                    file_put_contents($teamLog, implode(PHP_EOL, $tLines) . PHP_EOL);
                }
                break;
            }
            
        }

        saveState($stateFile, $state);
        echo json_encode($state);
        exit;

    // -----------------------------------------------------
    // Invalid
    // -----------------------------------------------------
    default:
        echo json_encode(["error"=>"Invalid action"]);
        exit;
}
?>

