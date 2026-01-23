<?php
/**
 * CSRF Token Generator für area710 Booking System
 * Gibt ein sicheres CSRF-Token zurück
 */

// Error Reporting für Produktion
error_reporting(0);
ini_set('display_errors', 0);

// Session starten
session_start();

// CORS Headers (anpassen!)
header('Access-Control-Allow-Origin: https://area710.de'); // TODO: Anpassen!
header('Access-Control-Allow-Credentials: true');
header('Content-Type: application/json; charset=utf-8');

// Nur GET erlauben
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit();
}

// CSRF-Token generieren falls nicht vorhanden
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Token zurückgeben
echo json_encode([
    'csrf_token' => $_SESSION['csrf_token']
]);
?>