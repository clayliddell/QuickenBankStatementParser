<?php

/**
 * Parse supplied bankstatement and use it to generate a QIF transactions file.
 */

if (isset($argv[1], $argv[2], $argv[3])) {
    // Name/location of input text file.
    $fileName = $argv[1];
    // Account to import.
    $account = $argv[2];
    // Name/location of Input text File.
    $outputFileName = $argv[3];
} else {
    die("File name, account name, and output file name required!\n");
}

$inputFile = file_get_contents($fileName);

$inputFile = preg_replace("/[\r\n] +?Date(?:(?!\*\/).)*?  Date      Description                                  Amount/s", "$1", $inputFile);

$newQIFFile = "!Account \r\nN$account \r\nTBank \r\n^ \r\n!Type:Bank \r\n";

$memo = "Gen:";

$Qpayees = json_decode(file_get_contents("Payees.json"), true);

$newQIFFile .= generateReg($inputFile);
$newQIFFile .= generateXfers($inputFile);
$newQIFFile .= generateCheck($inputFile);
$newQIFFile .= generate1line($inputFile);

file_put_contents($outputFileName, $newQIFFile);

function generateXfers($inputFile)
{
    preg_match_all("/[ ]{2,}([0-9]{1,2}\/[0-9]{1,2})[ ]+(?:Xfer)(.+?)[ ]+([0-9\.]+)\-?(?:\r\n|\r|\n)/", $inputFile, $outputArray);

    $transactions = '';

    for ($i = 0; $i < sizeof($outputArray[0]); $i++) {
        $date = $outputArray[1][$i];
        $amount = $outputArray[3][$i];
        $type = "TXFR";

        $actualPayeeName = "Transfer";
        $actualPayeeCategory = "Transfer";
        $memo = $outputArray[2][$i];

        $transactions .= "D$date \r\nU$amount \r\nT$amount \r\nN$type \r\nP$actualPayeeName \r\nM$memo \r\nL$actualPayeeCategory \r\n^ \r\n";
    }

    return $transactions;
}

function generate1line($inputFile)
{
    preg_match_all("/[ ]{2,}([0-9]{1,2}\/[0-9]{1,2})[ ]+(DEPOSIT|INTEREST PAID).*[ ]+([0-9,.]+)(?:\r\n|\r|\n)/", $inputFile, $outputArray);

    $transactions = '';

    for ($i = 0; $i < sizeof($outputArray[0]); $i++) {
        $date = $outputArray[1][$i];
        $amount = $outputArray[3][$i];
        $type = "DEP";

        if (stripos($outputArray[2][$i], "DEPOSIT") !== false) {
            $actualPayeeName = "DEPOSIT";
            $actualPayeeCategory = "Deposit";
        } else if (stripos($outputArray[2][$i], "INTEREST") !== false) {
            $actualPayeeName = "Interest";
            $actualPayeeCategory = "Interest Inc";
        }

        $transactions .= "D$date \r\nU$amount \r\nT$amount \r\nN$type \r\nP$actualPayeeName \r\nM \r\nL$actualPayeeCategory \r\n^ \r\n";
    }

    return $transactions;
}

function generate3line($inputFile)
{
    global $memo, $Qpayees;

    preg_match_all("/[ ]{2,}([0-9]{1,2}\/[0-9]{1,2})[ ]{2,}(.*?)[ ]+([0-9,.]{1,})(?:\r\n|\r|\n)[ ]{2,}(.*?)(?:\r\n|\r|\n)[ ]+(TRACE.*?)(?:\r\n|\r|\n)[ ]{2,}[0-9]{1,2}\/[0-9]{1,2}/", $inputFile, $outputArray);

    $transactions = '';

    for ($i = 0; $i < sizeof($outputArray[0]); $i++) {
        $date = $outputArray[1][$i] . "'" . (isset($argv[4]) ? $argv[4] : date('y'));

        $amount = rtrim($outputArray[3][$i], "\r\n");

        if (substr($amount, -1) == "-") {
            $amount = "-" . rtrim($amount, "-");
        }

        $oldType = $type = $outputArray[2][$i];

        if (stripos($type, "DEPOSIT") !== false || stripos($type, "PR PAYMENT") !== false) {
            $type = "DEP";
        } elseif (stripos($type, "E-Payment") !== false || stripos($type, "ONLINE PMT") !== false) {
            $type = "EFT";
        } elseif (stripos($type, "DBT CRD") !== false || stripos($type, "POS DEB") !== false) {
            $type = "CC";
        } elseif (stripos($type, "CHECKPYMT") !== false) {
            $type = preg_replace('/[^0-9]/', '', $outputArray[5][$i]);
        } elseif (stripos($type, "ATM W/D") !== false) {
            $type = "WD";
            $actualPayeeName = "Cash Withdrawal";
            $actualPayeeCategory = "Cash & ATM";
            $payee = "ATM";
        } else {
            echo "NOTICE($i): Unknown transaction type '$type', assuming 'CC' (Date: $date)\n";
            $type = "CC";
        }

        if ($type != "WD") {
            $payee = rtrim($outputArray[4][$i], "/01234567890 \r\n");

            if ($payee == "") {
                $payee = $oldType;
            }

            $actualPayee = 0;
            $similarityPercentage = 0;

            for ($j = 0; $j < sizeof($Qpayees[0]); $j++) {
                similar_text(strtoupper($payee), strtoupper($Qpayees[0][$j]), $tempSimilarityPercentage);
                if ($tempSimilarityPercentage > $similarityPercentage) {
                    $similarityPercentage = $tempSimilarityPercentage;
                    $actualPayee = $j;
                }
            }

            $actualPayeeName = $Qpayees[0][$actualPayee];

            if ($type != "DEP") {
                $actualPayeeCategory = $Qpayees[1][$actualPayee];
            } else {
                $actualPayeeCategory = "Deposit";
            }
        }

        $transactions .= "D$date \r\nU$amount \r\nT$amount \r\nN$type \r\nP$actualPayeeName \r\nM$memo$payee \r\nL$actualPayeeCategory \r\n^ \r\n"; // Use category from last known transaction
    }

    return $transactions;
}

function generateCheck($inputFile)
{
    global $memo, $Qpayees;

    preg_match_all("/([0-9]{1,2}\/[0-9]{1,2})[ ]{2,}([0-9]{1,})[\*]?[ ]+([0-9,.]{1,})/s", $inputFile, $outputArray);

    $transactions = '';

    for ($i = 0; $i < sizeof($outputArray[0]); $i++) {
        $date = $outputArray[1][$i];
        $year = (isset($argv[4]) ? $argv[4] : date("y"));
        $amount = "-" . $outputArray[3][$i];

        $checkNum = $outputArray[2][$i];

        $transactions .= "D$date'$year \r\nU$amount \r\nT$amount \r\nN$checkNum \r\n^ \r\n";
    }

    return $transactions;
}

function generateReg($inputFile)
{
    global $memo, $Qpayees;

    preg_match_all("/[ ]{2,}([0-9]{1,2}\/[0-9]{1,2})[ ]{1,5}([A-Za-z0-9].*?)[ ]+([0-9.,\-]+)(?:\r\n|\r|\n)[ ]{10,}(.*)(?:\r\n|\r|\n)[ ]+(.*)(?:\r\n|\r|\n)/", $inputFile, $outputArray);

    $transactions = '';

    for ($i = 0; $i < sizeof($outputArray[0]); $i++) {
        $date = $outputArray[1][$i] . "'" . (isset($argv[4]) ? $argv[4] : date('y'));

        if (strpos($outputArray[0][$i], 'TRACE')) {
            continue;
        }

        $amount = rtrim($outputArray[3][$i], "\r\n");

        if (substr($amount, -1) == "-") {
            $amount = "-" . rtrim($amount, "-");
        }

        $oldType = $type = $outputArray[2][$i];

        if (stripos($type, "DEPOSIT") !== false || stripos($type, "PR PAYMENT") !== false) {
            $type = "DEP";
        } elseif (stripos($type, "DBT CRD") !== false || stripos($type, "POS DEB") !== false) {
            $type = "CC";
        } elseif (stripos($type, "CHECKPYMT") !== false || stripos($type, "CHECKPAYMT") !== false || stripos($outputArray[5][$i], "CHECK#") !== false) {
            $type = preg_replace('/[^0-9]/', '', $outputArray[5][$i]);
        } elseif (stripos($type, "ATM W/D") !== false) {
            $type = "WD";
            $actualPayeeName = "Cash Withdrawal";
            $actualPayeeCategory = "Cash & ATM";
            $payee = "ATM";
        } elseif (stripos($type, "E-Payment") !== false || stripos($type, "ONLINE PMT") !== false || stripos($outputArray[5][$i], "ID #") !== false || stripos($outputArray[5][$i], "TRACE") !== false) {
            $type = "EFT";
        } else {
            echo "NOTICE($i): Unknown transaction type '$type', assuming 'CC' (Date: $date)\n";
            $type = "CC";
        }

        if ($type != "WD") {
            //var_dump($outputArray[4][$i]);
            //die();
            $payee = rtrim($outputArray[4][$i], "/01234567890 \r\n");

            if ($payee == "") {
                $payee = $oldType;
            }

            $actualPayee = 0;
            $similarityPercentage = 0;

            for ($j = 0; $j < sizeof($Qpayees[0]); $j++) {
                similar_text(strtoupper($payee), strtoupper($Qpayees[0][$j]), $tempSimilarityPercentage);
                if ($tempSimilarityPercentage > $similarityPercentage) {
                    $similarityPercentage = $tempSimilarityPercentage;
                    $actualPayee = $j;
                }
            }

            $actualPayeeName = $Qpayees[0][$actualPayee];

            if ($type != "DEP") {
                $actualPayeeCategory = $Qpayees[1][$actualPayee];
            } else {
                $actualPayeeCategory = "Deposit";
            }
        }

        $transactions .= "D$date \r\nU$amount \r\nT$amount \r\nN$type \r\nP$actualPayeeName \r\nM$memo$payee \r\nL$actualPayeeCategory \r\n^ \r\n"; // Use category from last known transaction
    }

    return $transactions;
}
