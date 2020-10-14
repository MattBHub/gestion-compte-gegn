<?php

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

require_once ".parameters.php";

/* Connection base données */
$pdo = new PDO(sprintf('mysql:host=%s;dbname=%s;port=3306', DB_HOST, DB_NAME), DB_USER, DB_PASSWORD, [PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8"]);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$today = new DateTime();

echo "\nDébut du traitement , le {$today->format('d/m/Y H:i')} \n";

// Create a new temporary code using the Igloohome API
$code = generateCode();

if ($code !== null) {
    // Insert new code in Db and close others
    remplaceCodeInDb($code);
}

echo "\nFin du traitement";

function generateCode() {
    global $today;
    $response = "";

    $todayUsaFormat = $today->format('Y-m-d');

    $url = sprintf(URL, LOCK_ID);

    $headers = [
        'X-IGLOOHOME-APIKEY' => API_KEY,
        'Content-Type' => "application/json",
        'Accept' => "application/json",
    ];

    $body = [
        'durationCode' => TYPE_CODE,
        'startDate' => $todayUsaFormat . START,
        'endDate' => $todayUsaFormat . END,
        'description' => $today->format('d/m/Y')
    ];

    $jsonBody = json_encode($body);

    try {
        $client = HttpClient::create();
        $response = $client->request('POST', $url, ['headers' => $headers, 'body' => $jsonBody]);
        return $response->toArray()['code'];

    } catch (TransportExceptionInterface | ClientExceptionInterface | RedirectionExceptionInterface| ServerExceptionInterface $e) {
        echo("\nEchec de la création du code (code http retourné : " . $e->getCode() . "\n" . $e);
    } catch (DecodingExceptionInterface $e) {
        echo "\n réponse body cannot be decoded to an array\n";
        var_dump($response);
    }

    return null;
}

function remplaceCodeInDb($code){
    global $pdo;

    try {
        $stmt = $pdo->prepare("UPDATE code c SET c.closed = 1");
        $stmt->execute();

        $stmt = $pdo->prepare("INSERT INTO code(value, created_at, closed) VALUES(:code, NOW(), 0)");
        $stmt->bindValue(":code", $code);
        $stmt->execute();
    } catch (Exception $e) {
        echo "\n impossible d'écrire dans la base de données \n".$e->getTraceAsString();
    }
}

