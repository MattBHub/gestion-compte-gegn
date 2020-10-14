<?php

echo generateEAN13('12011234');

function smokeTest() {
    $ok = 0;
    $ko = 0;
    for ($i = 0; $i < 10000; $i++) {
        $random = rand(100, 999999999);
        $code = generateEAN13($random);
        if (!checkEAN13($code)) {
            echo "\n pas bon " . $code;
            $ko++;
        } else {
            $ok++;
        }
    }
    echo "\n " . $ok . " sont ok";
    echo "\n " . $ko . " sont ko";
}

function generateEAN13($number) {
    // must be between 200 AND 299
    $companyCode = '200';
    $code = $companyCode . str_pad($number, 9, '0', STR_PAD_LEFT);
    $weightflag = true;
    $sum = 0;
    for ($i = strlen($code) - 1; $i >= 0; $i--) {
        $sum += (int)$code[$i] * ($weightflag ? 3 : 1);
        $weightflag = !$weightflag;
    }
    $code .= (10 - ($sum % 10)) % 10;
    return $code;
}

function checkEAN13($code, $checksum = null) {
    $c = strlen($code);
    if ($c === 13) {
        if (!$checksum) {
            $checksum = substr($code, -1, 1);
        }
        $code = substr($code, 0, 12);
    } elseif ($c !== 12 || !$checksum) {
        return false;
    }
//    echo "\n size pas ok";

    $odd = true;
    $checksumValue = 0;
    $keys = array('0', '1', '2', '3', '4', '5', '6', '7', '8', '9');
    $c = strlen($code);
    for ($i = $c; $i > 0; $i--) {
        if ($odd === true) {
            $multiplier = 3;
            $odd = false;
        } else {
            $multiplier = 1;
            $odd = true;
        }

//        echo "\n " . $keys[$code[$i - 1]] . " => " . $multiplier;
        if (!isset($keys[$code[$i - 1]])) {
            return false;
        }

//        echo " donc " . $keys[$code[$i - 1]] * $multiplier;
        $checksumValue += $keys[$code[$i - 1]] * $multiplier;
    }


//    echo "\n checksumValue : " . $checksumValue;
    $checksumValue = (10 - $checksumValue % 10) % 10;

//    echo "\n checksum : " . $checksum;
//    echo "\n checksumValue : " . $checksumValue;

//    if ($checksumValue == $checksum)
//        echo "\n final ok ";
//    else
//        echo "\n final KO ";
    return $checksumValue == $checksum;
}