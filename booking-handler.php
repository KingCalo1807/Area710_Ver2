<?php
/**
 * area710 Booking Handler - SECURE VERSION mit PHPMailer
 * Verarbeitet Buchungsanfragen mit SMTP-Authentifizierung
 *
 * Sicherheitsfeatures:
 * - Strikte CORS-Policy
 * - CSRF-Schutz
 * - Input-Validierung & Sanitization
 * - Rate-Limiting
 * - Whitelist-basierte Validierung
 * - XSS-Schutz
 * - Authentifizierter SMTP-Versand
 */

// ========================================
// PHPMailer EINBINDEN
// ========================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

// Mail-Konfiguration laden
$mailConfig = require __DIR__ . '/config/mail-config.php';

// ========================================
// SICHERHEITS-KONFIGURATION
// ========================================

// Error Reporting für Produktion (keine Details nach außen!)
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/logs/error.log');

// Session starten (für CSRF und Rate-Limiting)
session_start();

// ========================================
// CORS-KONFIGURATION
// ========================================

// Erlaubte Origins (Produktion + lokale Entwicklung)
$allowedOrigins = [
    'https://area710.de',
    'https://www.area710.de',
    'http://localhost',
    'http://127.0.0.1'
];

// CORS-Prüfung
if (isset($_SERVER['HTTP_ORIGIN'])) {
    $origin = $_SERVER['HTTP_ORIGIN'];
    // Prüfe ob Origin erlaubt oder localhost mit beliebigem Port
    $isAllowed = in_array($origin, $allowedOrigins) || 
                 preg_match('/^http:\/\/(localhost|127\.0\.0\.1)(:\d+)?$/', $origin);
    
    if ($isAllowed) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
    } else {
        http_response_code(403);
        exit();
    }
}

header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json; charset=utf-8');

// Handle OPTIONS preflight request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ========================================
// REQUEST-METHODE PRÜFEN
// ========================================

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

// ========================================
// RATE-LIMITING (SPAM-SCHUTZ)
// ========================================

define('MAX_REQUESTS_PER_MINUTE', 3);
define('RATE_LIMIT_WINDOW', 60);

if (!isset($_SESSION['requests'])) {
    $_SESSION['requests'] = [];
}

$_SESSION['requests'] = array_filter($_SESSION['requests'], function($timestamp) {
    return (time() - $timestamp) < RATE_LIMIT_WINDOW;
});

if (count($_SESSION['requests']) >= MAX_REQUESTS_PER_MINUTE) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Too many requests. Please try again later.'
    ]);
    exit();
}

$_SESSION['requests'][] = time();

// ========================================
// CSRF-TOKEN PRÜFEN
// ========================================

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ========================================
// JSON-EINGABE VALIDIEREN
// ========================================

$rawInput = file_get_contents('php://input');

if (empty($rawInput)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'No data received']);
    exit();
}

$data = json_decode($rawInput, true);

if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON format']);
    exit();
}

// ========================================
// CSRF-TOKEN VALIDIEREN
// ========================================

if (!isset($data['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $data['csrf_token'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
    exit();
}

// ========================================
// WHITELISTS DEFINIEREN
// ========================================

$ALLOWED_EVENT_TYPES = [
    'business', 'wedding', 'birthday', 'party',
    'conference', 'workshop', 'other'
];

$ALLOWED_ROOMS = ['hall', 'lab', 'barclub', 'outdoor'];

$ALLOWED_SERVICES = [
    'catering', 'tech', 'decoration',
    'seating', 'bar', 'security'
];

$ALLOWED_LANGUAGES = ['de', 'en'];

// ========================================
// PFLICHTFELDER PRÜFEN
// ========================================

$requiredFields = [
    'firstName', 'lastName', 'email', 'phone',
    'eventType', 'eventDate', 'eventTime',
    'endTime', 'guests'
];

foreach ($requiredFields as $field) {
    if (!isset($data[$field]) || trim($data[$field]) === '') {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => "Required field missing: $field"
        ]);
        exit();
    }
}

// ========================================
// EINGABEN VALIDIEREN & SANITISIEREN
// ========================================

$errors = [];

// --- VORNAME ---
$firstName = trim($data['firstName']);
if (strlen($firstName) < 2 || strlen($firstName) > 50) {
    $errors[] = 'First name must be between 2 and 50 characters';
}
if (!preg_match('/^[\p{L}\s\-\']+$/u', $firstName)) {
    $errors[] = 'First name contains invalid characters';
}
$firstName = htmlspecialchars($firstName, ENT_QUOTES, 'UTF-8');

// --- NACHNAME ---
$lastName = trim($data['lastName']);
if (strlen($lastName) < 2 || strlen($lastName) > 50) {
    $errors[] = 'Last name must be between 2 and 50 characters';
}
if (!preg_match('/^[\p{L}\s\-\']+$/u', $lastName)) {
    $errors[] = 'Last name contains invalid characters';
}
$lastName = htmlspecialchars($lastName, ENT_QUOTES, 'UTF-8');

// --- E-MAIL ---
$email = filter_var(trim($data['email']), FILTER_VALIDATE_EMAIL);
if (!$email) {
    $errors[] = 'Invalid email address';
}
if (strlen($email) > 100) {
    $errors[] = 'Email address too long';
}

// --- TELEFON ---
$phone = trim($data['phone']);
if (!preg_match('/^[\d\s\+\-\(\)\/]+$/', $phone)) {
    $errors[] = 'Phone number contains invalid characters';
}
if (strlen($phone) < 5 || strlen($phone) > 30) {
    $errors[] = 'Phone number must be between 5 and 30 characters';
}
$phone = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');

// --- FIRMA (Optional) ---
$company = '';
if (isset($data['company']) && trim($data['company']) !== '') {
    $company = trim($data['company']);
    if (strlen($company) > 100) {
        $errors[] = 'Company name too long';
    }
    $company = htmlspecialchars($company, ENT_QUOTES, 'UTF-8');
}

// --- EVENT-TYP (Whitelist) ---
$eventType = trim($data['eventType']);
if (!in_array($eventType, $ALLOWED_EVENT_TYPES, true)) {
    $errors[] = 'Invalid event type';
}

// --- DATUM ---
$eventDate = trim($data['eventDate']);
$dateObj = DateTime::createFromFormat('Y-m-d', $eventDate);
if (!$dateObj || $dateObj->format('Y-m-d') !== $eventDate) {
    $errors[] = 'Invalid date format';
} else {
    $today = new DateTime('today');
    if ($dateObj < $today) {
        $errors[] = 'Date must be in the future';
    }
    $maxDate = (new DateTime())->modify('+2 years');
    if ($dateObj > $maxDate) {
        $errors[] = 'Date too far in the future';
    }
}

// --- UHRZEIT ---
$eventTime = trim($data['eventTime']);
$timeObj = DateTime::createFromFormat('H:i', $eventTime);
if (!$timeObj || $timeObj->format('H:i') !== $eventTime) {
    $errors[] = 'Invalid time format';
}

// --- ENDZEIT ---
$endTime = trim($data['endTime']);
$endTimeObj = DateTime::createFromFormat('H:i', $endTime);
if (!$endTimeObj || $endTimeObj->format('H:i') !== $endTime) {
    $errors[] = 'Invalid end time format';
}

// --- DAUER BERECHNEN ---
// Konvertiere Zeiten zu Minuten für Berechnung
function timeToMinutes($time) {
    $parts = explode(':', $time);
    return (int)$parts[0] * 60 + (int)$parts[1];
}

$startMinutes = timeToMinutes($eventTime);
$endMinutes = timeToMinutes($endTime);

// Wenn Endzeit <= Startzeit, geht Event über Mitternacht
if ($endMinutes <= $startMinutes) {
    $endMinutes += 24 * 60;
}

$durationMinutes = $endMinutes - $startMinutes;
$duration = $durationMinutes / 60; // Dauer in Stunden

// Validierung: Minimum 1 Stunde, Maximum 24 Stunden
if ($durationMinutes < 60) {
    $errors[] = 'Event duration must be at least 1 hour';
}
if ($durationMinutes > 24 * 60) {
    $errors[] = 'Event duration must not exceed 24 hours';
}

// --- GÄSTEANZAHL ---
$guests = filter_var($data['guests'], FILTER_VALIDATE_INT);
if ($guests === false || $guests < 1 || $guests > 1000) {
    $errors[] = 'Number of guests must be between 1 and 1000';
}

// --- RÄUME (Whitelist, Array-Validierung) ---
$rooms = [];
if (isset($data['rooms']) && is_array($data['rooms'])) {
    foreach ($data['rooms'] as $room) {
        if (in_array($room, $ALLOWED_ROOMS, true)) {
            $rooms[] = $room;
        }
    }
}
if (empty($rooms)) {
    $errors[] = 'At least one room must be selected';
}

// --- SERVICES (Whitelist, Array-Validierung) ---
$services = [];
if (isset($data['services']) && is_array($data['services'])) {
    foreach ($data['services'] as $service) {
        if (in_array($service, $ALLOWED_SERVICES, true)) {
            $services[] = $service;
        }
    }
}

// --- NACHRICHT (Optional) ---
$message = '';
if (isset($data['message']) && trim($data['message']) !== '') {
    $message = trim($data['message']);
    if (strlen($message) > 2000) {
        $errors[] = 'Message too long (max 2000 characters)';
    }
    $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
}

// --- SPRACHE (Whitelist) ---
$lang = isset($data['lang']) && in_array($data['lang'], $ALLOWED_LANGUAGES, true)
    ? $data['lang']
    : 'de';

// ========================================
// FEHLER ZURÜCKGEBEN
// ========================================

if (!empty($errors)) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'errors' => $errors
    ]);
    exit();
}

// ========================================
// ÜBERSETZUNGEN
// ========================================

$eventTypes = [
    'business' => ['de' => 'Business Event', 'en' => 'Business Event'],
    'wedding' => ['de' => 'Hochzeit', 'en' => 'Wedding'],
    'birthday' => ['de' => 'Geburtstag', 'en' => 'Birthday'],
    'party' => ['de' => 'Party', 'en' => 'Party'],
    'conference' => ['de' => 'Konferenz/Tagung', 'en' => 'Conference/Meeting'],
    'workshop' => ['de' => 'Workshop/Seminar', 'en' => 'Workshop/Seminar'],
    'other' => ['de' => 'Sonstiges', 'en' => 'Other']
];
$eventTypeLabel = $eventTypes[$eventType][$lang];

$roomNames = [
    'hall' => 'Hall',
    'lab' => 'Lab',
    'barclub' => 'Bar & Club',
    'outdoor' => 'Outdoor / Plaza'
];

// Raum-Farben für E-Mail-Badges
$roomColors = [
    'hall' => '#FCAB14',      // Orange
    'lab' => '#CD1151',       // Rot
    'barclub' => '#009FE2',   // Blau
    'outdoor' => '#AEC610'    // Grün
];

$roomsText = implode(', ', array_map(function($r) use ($roomNames) {
    return $roomNames[$r] ?? $r;
}, $rooms));

// Räume als farbige HTML-Badges für E-Mails
$roomBadgesHtml = implode(' ', array_map(function($r) use ($roomNames, $roomColors) {
    $name = $roomNames[$r] ?? $r;
    $color = $roomColors[$r] ?? '#666666';
    return "<span style='display: inline-block; background: {$color}; color: #ffffff; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; margin: 2px;'>{$name}</span>";
}, $rooms));

$serviceNames = [
    'catering' => ['de' => 'Catering', 'en' => 'Catering'],
    'tech' => ['de' => 'Technik', 'en' => 'Technology'],
    'decoration' => ['de' => 'Dekoration', 'en' => 'Decoration'],
    'seating' => ['de' => 'Bestuhlung', 'en' => 'Seating'],
    'bar' => ['de' => 'Bar-Service', 'en' => 'Bar Service'],
    'security' => ['de' => 'Security', 'en' => 'Security']
];
$servicesText = !empty($services)
    ? implode(', ', array_map(function($s) use ($serviceNames, $lang) {
        return $serviceNames[$s][$lang] ?? $s;
    }, $services))
    : ($lang === 'de' ? 'Keine Auswahl' : 'None selected');

// Datum formatieren
$dateFormatted = $dateObj->format('d.m.Y');

// ========================================
// PHPMAILER FUNKTION
// ========================================

function sendMail($mailConfig, $to, $toName, $subject, $htmlBody, $replyTo = null, $replyToName = null) {
    $mail = new PHPMailer(true);
    
    try {
        // SMTP-Konfiguration
        $mail->isSMTP();
        $mail->Host       = $mailConfig['smtp']['host'];
        $mail->SMTPAuth   = $mailConfig['smtp']['auth'];
        $mail->Username   = $mailConfig['credentials']['username'];
        $mail->Password   = $mailConfig['credentials']['password'];
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = $mailConfig['smtp']['port'];
        $mail->CharSet    = 'UTF-8';
        
        // Absender
        $mail->setFrom(
            $mailConfig['sender']['email'],
            $mailConfig['sender']['name']
        );
        
        // Empfänger
        $mail->addAddress($to, $toName);
        
        // Reply-To (optional)
        if ($replyTo) {
            $mail->addReplyTo($replyTo, $replyToName ?? '');
        }
        
        // BCC (optional)
        if (!empty($mailConfig['bcc'])) {
            $mail->addBCC($mailConfig['bcc']);
        }
        
        // Inhalt
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $htmlBody;
        $mail->AltBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
        
        $mail->send();
        return true;
        
    } catch (Exception $e) {
        error_log("Mail Error: " . $mail->ErrorInfo);
        return false;
    }
}

// ========================================
// E-MAIL TEMPLATES
// ========================================

$baseUrl = $mailConfig['base_url'];

// ========================================
// E-MAIL AN AREA710 (Owner)
// ========================================

$subject_owner = "Neue Buchungsanfrage: $eventTypeLabel am $dateFormatted";

$message_owner = "
<!DOCTYPE html>
<html lang='de'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <meta http-equiv='X-UA-Compatible' content='IE=edge'>
    <title>Neue Buchungsanfrage</title>
    <!--[if mso]>
    <style type='text/css'>
        table { border-collapse: collapse; }
        td { padding: 0; }
    </style>
    <![endif]-->
</head>
<body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #1a1a1a; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;'>
    
    <!-- Wrapper -->
    <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='background-color: #1a1a1a;'>
        <tr>
            <td align='center' style='padding: 30px 15px;'>
                
                <!-- Main Container -->
                <table role='presentation' cellspacing='0' cellpadding='0' border='0' style='max-width: 600px; width: 100%;'>
                    
                    <!-- Main Card -->
                    <tr>
                        <td style='background-color: #0d0d0d; border-radius: 12px; overflow: hidden; border: 1px solid #333333;'>
                            
                            <!-- Gradient Top Border -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='height: 4px; background: linear-gradient(90deg, #FCAB14 0%, #CD1151 33%, #009FE2 66%, #AEC610 100%);'></td>
                                </tr>
                            </table>
                            
                            <!-- Logo -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td align='center' style='padding: 35px 30px 25px 30px;'>
                                        <img src='{$baseUrl}/img/logo.jpg' alt='area710' width='140' style='max-width: 140px; height: auto; display: block; border: 0;'>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Header Section -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px 25px 30px;'>
                                        <p style='margin: 0 0 8px 0; font-size: 11px; font-weight: 600; color: #FCAB14; text-transform: uppercase; letter-spacing: 2px;'>Neue Anfrage</p>
                                        <h1 style='margin: 0 0 8px 0; font-size: 26px; font-weight: 700; color: #ffffff; line-height: 1.3;'>$firstName $lastName</h1>
                                        <p style='margin: 0; font-size: 13px; color: #888888;'>Eingegangen am " . date('d.m.Y') . " um " . date('H:i') . " Uhr</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Event Type Badge -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px 25px 30px;'>
                                        <table role='presentation' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='background: linear-gradient(135deg, #FCAB14, #CD1151); color: #ffffff; padding: 10px 24px; border-radius: 25px; font-size: 13px; font-weight: 600;'>$eventTypeLabel</td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Divider -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px;'>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='height: 1px; background-color: #333333;'></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Event Details - Vertical Layout -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 25px 30px;'>
                                        <p style='margin: 0 0 20px 0; font-size: 11px; font-weight: 600; color: #888888; text-transform: uppercase; letter-spacing: 1px;'>Event-Details</p>
                                        
                                        <!-- Datum -->
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='margin-bottom: 12px;'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 15px 18px; border-left: 3px solid #CD1151;'>
                                                    <p style='margin: 0 0 4px 0; font-size: 11px; font-weight: 600; color: #CD1151; text-transform: uppercase; letter-spacing: 1px;'>Datum</p>
                                                    <p style='margin: 0; font-size: 18px; font-weight: 600; color: #ffffff;'>$dateFormatted</p>
                                                </td>
                                            </tr>
                                        </table>
                                        
                                        <!-- Uhrzeit -->
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='margin-bottom: 12px;'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 15px 18px; border-left: 3px solid #009FE2;'>
                                                    <p style='margin: 0 0 4px 0; font-size: 11px; font-weight: 600; color: #009FE2; text-transform: uppercase; letter-spacing: 1px;'>Uhrzeit</p>
                                                    <p style='margin: 0; font-size: 18px; font-weight: 600; color: #ffffff;'>$eventTime - $endTime Uhr</p>
                                                </td>
                                            </tr>
                                        </table>
                                        
                                        <!-- Dauer -->
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='margin-bottom: 12px;'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 15px 18px; border-left: 3px solid #AEC610;'>
                                                    <p style='margin: 0 0 4px 0; font-size: 11px; font-weight: 600; color: #AEC610; text-transform: uppercase; letter-spacing: 1px;'>Dauer</p>
                                                    <p style='margin: 0; font-size: 18px; font-weight: 600; color: #ffffff;'>" . number_format($duration, 1) . " Stunden</p>
                                                </td>
                                            </tr>
                                        </table>
                                        
                                        <!-- Gäste -->
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 15px 18px; border-left: 3px solid #FCAB14;'>
                                                    <p style='margin: 0 0 4px 0; font-size: 11px; font-weight: 600; color: #FCAB14; text-transform: uppercase; letter-spacing: 1px;'>Gäste</p>
                                                    <p style='margin: 0; font-size: 18px; font-weight: 600; color: #ffffff;'>$guests Personen</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Räume Section -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px 25px 30px;'>
                                        <p style='margin: 0 0 15px 0; font-size: 11px; font-weight: 600; color: #888888; text-transform: uppercase; letter-spacing: 1px;'>Gewählte Räume</p>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 18px;'>
                                                    $roomBadgesHtml
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            " . (!empty($services) ? "
                            <!-- Services Section -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px 25px 30px;'>
                                        <p style='margin: 0 0 15px 0; font-size: 11px; font-weight: 600; color: #888888; text-transform: uppercase; letter-spacing: 1px;'>Zusätzliche Services</p>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 18px;'>
                                                    <p style='margin: 0; color: #ffffff; font-size: 15px; line-height: 1.5;'>$servicesText</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            " : "") . "
                            
                            <!-- Divider -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px;'>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='height: 1px; background-color: #333333;'></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Kontaktdaten Section -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 25px 30px;'>
                                        <p style='margin: 0 0 20px 0; font-size: 11px; font-weight: 600; color: #888888; text-transform: uppercase; letter-spacing: 1px;'>Kontaktdaten des Kunden</p>
                                        
                                        " . ($company ? "
                                        <!-- Firma -->
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='margin-bottom: 12px;'>
                                            <tr>
                                                <td style='padding: 12px 0; border-bottom: 1px solid #252525;'>
                                                    <p style='margin: 0 0 4px 0; font-size: 11px; color: #666666; text-transform: uppercase; letter-spacing: 1px;'>Firma</p>
                                                    <p style='margin: 0; font-size: 15px; font-weight: 500; color: #ffffff;'>$company</p>
                                                </td>
                                            </tr>
                                        </table>
                                        " : "") . "
                                        
                                        <!-- E-Mail -->
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='margin-bottom: 12px;'>
                                            <tr>
                                                <td style='padding: 12px 0; border-bottom: 1px solid #252525;'>
                                                    <p style='margin: 0 0 4px 0; font-size: 11px; color: #666666; text-transform: uppercase; letter-spacing: 1px;'>E-Mail</p>
                                                    <a href='mailto:$email' style='margin: 0; font-size: 15px; font-weight: 500; color: #009FE2; text-decoration: none;'>$email</a>
                                                </td>
                                            </tr>
                                        </table>
                                        
                                        <!-- Telefon -->
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='padding: 12px 0;'>
                                                    <p style='margin: 0 0 4px 0; font-size: 11px; color: #666666; text-transform: uppercase; letter-spacing: 1px;'>Telefon</p>
                                                    <a href='tel:$phone' style='margin: 0; font-size: 15px; font-weight: 500; color: #009FE2; text-decoration: none;'>$phone</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            " . ($message ? "
                            <!-- Nachricht Section -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px 25px 30px;'>
                                        <p style='margin: 0 0 15px 0; font-size: 11px; font-weight: 600; color: #888888; text-transform: uppercase; letter-spacing: 1px;'>Nachricht</p>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 18px; border-left: 3px solid #FCAB14;'>
                                                    <p style='margin: 0; color: #cccccc; font-size: 14px; line-height: 1.7;'>" . nl2br($message) . "</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            " : "") . "
                            
                            <!-- CTA Button -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td align='center' style='padding: 15px 30px 35px 30px;'>
                                        <table role='presentation' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td align='center' style='background: linear-gradient(135deg, #FCAB14, #CD1151); border-radius: 25px;'>
                                                    <a href='mailto:$email' style='display: inline-block; color: #ffffff; padding: 14px 35px; font-size: 14px; font-weight: 600; text-decoration: none; text-transform: uppercase; letter-spacing: 1px;'>Kunden kontaktieren</a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Footer inside card -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px;'>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='height: 1px; background-color: #333333;'></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td align='center' style='padding: 25px 30px;'>
                                        <p style='margin: 0; font-size: 12px; color: #666666;'>Diese Anfrage wurde über das Buchungsformular auf area710.de gesendet.</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Gradient Bottom Border -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='height: 4px; background: linear-gradient(90deg, #FCAB14 0%, #CD1151 33%, #009FE2 66%, #AEC610 100%);'></td>
                                </tr>
                            </table>
                            
                        </td>
                    </tr>
                    
                </table>
            </td>
        </tr>
    </table>
    
</body>
</html>
";

// ========================================
// BESTÄTIGUNGS-E-MAIL AN KUNDEN
// ========================================

// Texte je nach Sprache
if ($lang === 'de') {
    $subject_customer = "Ihre Buchungsanfrage bei area710";
    $greeting_text = "Hallo $firstName,";
    $thanks_text = "vielen Dank für Ihre Buchungsanfrage! Wir haben Ihre Anfrage erhalten und freuen uns, Sie bald bei uns begrüßen zu dürfen.";
    $summary_title = "Ihre Anfrage im Überblick";
    $next_steps_title = "So geht es weiter";
    $next_steps_text = "Unser Event-Team prüft Ihre Anfrage und meldet sich innerhalb von 24 Stunden mit einem individuellen Angebot bei Ihnen.";
    $contact_title = "Fragen? Wir sind für Sie da!";
    $hours_label = "Eventbüro";
    $hours_text = "Mo - Fr: 10:00 - 18:00 Uhr";
    $closing_text = "Wir freuen uns auf Ihr Event!";
    $team_text = "Ihr area710 Team";
    $date_label = "Datum";
    $time_label = "Uhrzeit";
    $duration_label = "Dauer";
    $duration_unit = "Stunden";
    $guests_label = "Gäste";
    $guests_unit = "Personen";
    $rooms_label = "Gewählte Räume";
    $services_label = "Zusätzliche Services";
    $phone_label = "Telefon";
    $email_label = "E-Mail";
    $address_label = "Adresse";
    $imprint_label = "Impressum";
    $privacy_label = "Datenschutz";
} else {
    $subject_customer = "Your booking request at area710";
    $greeting_text = "Hello $firstName,";
    $thanks_text = "Thank you for your booking request! We have received your inquiry and look forward to welcoming you soon.";
    $summary_title = "Your request at a glance";
    $next_steps_title = "What happens next";
    $next_steps_text = "Our event team will review your request and get back to you within 24 hours with a personalized offer.";
    $contact_title = "Questions? We're here to help!";
    $hours_label = "Event Office";
    $hours_text = "Mon - Fri: 10:00 AM - 6:00 PM";
    $closing_text = "We look forward to your event!";
    $team_text = "Your area710 Team";
    $date_label = "Date";
    $time_label = "Time";
    $duration_label = "Duration";
    $duration_unit = "hours";
    $guests_label = "Guests";
    $guests_unit = "persons";
    $rooms_label = "Selected Rooms";
    $services_label = "Additional Services";
    $phone_label = "Phone";
    $email_label = "Email";
    $address_label = "Address";
    $imprint_label = "Imprint";
    $privacy_label = "Privacy";
}

$message_customer = "
<!DOCTYPE html>
<html lang='$lang'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <meta http-equiv='X-UA-Compatible' content='IE=edge'>
    <title>$subject_customer</title>
    <!--[if mso]>
    <style type='text/css'>
        table { border-collapse: collapse; }
        td { padding: 0; }
    </style>
    <![endif]-->
</head>
<body style='margin: 0; padding: 0; font-family: -apple-system, BlinkMacSystemFont, \"Segoe UI\", Roboto, Helvetica, Arial, sans-serif; background-color: #1a1a1a; -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%;'>
    
    <!-- Wrapper -->
    <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='background-color: #1a1a1a;'>
        <tr>
            <td align='center' style='padding: 30px 15px;'>
                
                <!-- Main Container -->
                <table role='presentation' cellspacing='0' cellpadding='0' border='0' style='max-width: 600px; width: 100%;'>
                    
                    <!-- Main Card -->
                    <tr>
                        <td style='background-color: #0d0d0d; border-radius: 12px; overflow: hidden; border: 1px solid #333333;'>
                            
                            <!-- Gradient Top Border -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='height: 4px; background: linear-gradient(90deg, #FCAB14 0%, #CD1151 33%, #009FE2 66%, #AEC610 100%);'></td>
                                </tr>
                            </table>
                            
                            <!-- Logo -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td align='center' style='padding: 35px 30px 25px 30px;'>
                                        <img src='{$baseUrl}/img/logo.jpg' alt='area710' width='140' style='max-width: 140px; height: auto; display: block; border: 0;'>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Success Icon -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td align='center' style='padding: 0 30px 20px 30px;'>
                                        <table role='presentation' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td align='center' style='width: 70px; height: 70px; background: linear-gradient(135deg, #FCAB14, #CD1151); border-radius: 50%;'>
                                                    <span style='font-size: 32px; color: #ffffff; line-height: 70px; font-family: Arial, sans-serif;'>&#10003;</span>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Greeting -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td align='center' style='padding: 0 30px 30px 30px;'>
                                        <h1 style='margin: 0 0 15px 0; font-size: 24px; font-weight: 700; color: #ffffff;'>$greeting_text</h1>
                                        <p style='margin: 0; font-size: 15px; color: #aaaaaa; line-height: 1.6;'>$thanks_text</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Divider -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px;'>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='height: 1px; background-color: #333333;'></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Summary Section -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 25px 30px;'>
                                        <p style='margin: 0 0 20px 0; font-size: 11px; font-weight: 600; color: #FCAB14; text-transform: uppercase; letter-spacing: 2px;'>$summary_title</p>
                                        
                                        <!-- Event Type Badge -->
                                        <table role='presentation' cellspacing='0' cellpadding='0' border='0' style='margin-bottom: 20px;'>
                                            <tr>
                                                <td style='background: linear-gradient(135deg, #FCAB14, #CD1151); color: #ffffff; padding: 10px 24px; border-radius: 25px; font-size: 13px; font-weight: 600;'>$eventTypeLabel</td>
                                            </tr>
                                        </table>
                                        
                                        <!-- Datum -->
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='margin-bottom: 12px;'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 15px 18px; border-left: 3px solid #CD1151;'>
                                                    <p style='margin: 0 0 4px 0; font-size: 11px; font-weight: 600; color: #CD1151; text-transform: uppercase; letter-spacing: 1px;'>$date_label</p>
                                                    <p style='margin: 0; font-size: 18px; font-weight: 600; color: #ffffff;'>$dateFormatted</p>
                                                </td>
                                            </tr>
                                        </table>
                                        
                                        <!-- Uhrzeit -->
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='margin-bottom: 12px;'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 15px 18px; border-left: 3px solid #009FE2;'>
                                                    <p style='margin: 0 0 4px 0; font-size: 11px; font-weight: 600; color: #009FE2; text-transform: uppercase; letter-spacing: 1px;'>$time_label</p>
                                                    <p style='margin: 0; font-size: 18px; font-weight: 600; color: #ffffff;'>$eventTime - $endTime " . ($lang === 'de' ? 'Uhr' : '') . "</p>
                                                </td>
                                            </tr>
                                        </table>
                                        
                                        <!-- Dauer -->
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='margin-bottom: 12px;'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 15px 18px; border-left: 3px solid #AEC610;'>
                                                    <p style='margin: 0 0 4px 0; font-size: 11px; font-weight: 600; color: #AEC610; text-transform: uppercase; letter-spacing: 1px;'>$duration_label</p>
                                                    <p style='margin: 0; font-size: 18px; font-weight: 600; color: #ffffff;'>" . number_format($duration, 1) . " $duration_unit</p>
                                                </td>
                                            </tr>
                                        </table>
                                        
                                        <!-- Gäste -->
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 15px 18px; border-left: 3px solid #FCAB14;'>
                                                    <p style='margin: 0 0 4px 0; font-size: 11px; font-weight: 600; color: #FCAB14; text-transform: uppercase; letter-spacing: 1px;'>$guests_label</p>
                                                    <p style='margin: 0; font-size: 18px; font-weight: 600; color: #ffffff;'>$guests $guests_unit</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Räume Section -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px 25px 30px;'>
                                        <p style='margin: 0 0 15px 0; font-size: 11px; font-weight: 600; color: #888888; text-transform: uppercase; letter-spacing: 1px;'>$rooms_label</p>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 18px;'>
                                                    $roomBadgesHtml
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            " . (!empty($services) ? "
                            <!-- Services Section -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px 25px 30px;'>
                                        <p style='margin: 0 0 15px 0; font-size: 11px; font-weight: 600; color: #888888; text-transform: uppercase; letter-spacing: 1px;'>$services_label</p>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 18px;'>
                                                    <p style='margin: 0; color: #ffffff; font-size: 15px; line-height: 1.5;'>$servicesText</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            " : "") . "
                            
                            <!-- Divider -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px;'>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='height: 1px; background-color: #333333;'></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Next Steps -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 25px 30px;'>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='background-color: #1a1a1a; border-radius: 8px; padding: 20px; border-left: 3px solid #FCAB14;'>
                                                    <p style='margin: 0 0 8px 0; font-size: 13px; font-weight: 600; color: #FCAB14; text-transform: uppercase; letter-spacing: 1px;'>$next_steps_title</p>
                                                    <p style='margin: 0; font-size: 14px; color: #cccccc; line-height: 1.6;'>$next_steps_text</p>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Contact Section -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px 30px 30px;'>
                                        <p style='margin: 0 0 20px 0; font-size: 11px; font-weight: 600; color: #888888; text-transform: uppercase; letter-spacing: 1px;'>$contact_title</p>
                                        
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='background-color: #1a1a1a; border-radius: 8px;'>
                                            <tr>
                                                <td style='padding: 20px;'>
                                                    
                                                    <!-- Telefon -->
                                                    <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='margin-bottom: 12px;'>
                                                        <tr>
                                                            <td style='padding-bottom: 12px; border-bottom: 1px solid #252525;'>
                                                                <p style='margin: 0 0 4px 0; font-size: 11px; color: #666666; text-transform: uppercase; letter-spacing: 1px;'>$phone_label</p>
                                                                <a href='tel:+4970314107311' style='font-size: 15px; color: #009FE2; text-decoration: none;'>+49 7031 41073-11</a>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    
                                                    <!-- E-Mail -->
                                                    <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='margin-bottom: 12px;'>
                                                        <tr>
                                                            <td style='padding-bottom: 12px; border-bottom: 1px solid #252525;'>
                                                                <p style='margin: 0 0 4px 0; font-size: 11px; color: #666666; text-transform: uppercase; letter-spacing: 1px;'>$email_label</p>
                                                                <a href='mailto:info@area710.de' style='font-size: 15px; color: #009FE2; text-decoration: none;'>info@area710.de</a>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    
                                                    <!-- Öffnungszeiten -->
                                                    <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0' style='margin-bottom: 12px;'>
                                                        <tr>
                                                            <td style='padding-bottom: 12px; border-bottom: 1px solid #252525;'>
                                                                <p style='margin: 0 0 4px 0; font-size: 11px; color: #666666; text-transform: uppercase; letter-spacing: 1px;'>$hours_label</p>
                                                                <p style='margin: 0; font-size: 14px; color: #888888;'>$hours_text</p>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    
                                                    <!-- Adresse -->
                                                    <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                                        <tr>
                                                            <td>
                                                                <p style='margin: 0 0 4px 0; font-size: 11px; color: #666666; text-transform: uppercase; letter-spacing: 1px;'>$address_label</p>
                                                                <p style='margin: 0; font-size: 14px; color: #888888;'>Gottlieb-Binder-Str. 2, 71088 Holzgerlingen</p>
                                                            </td>
                                                        </tr>
                                                    </table>
                                                    
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Closing -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td align='center' style='padding: 0 30px 30px 30px;'>
                                        <p style='margin: 0 0 5px 0; font-size: 17px; color: #ffffff;'>$closing_text</p>
                                        <p style='margin: 0; font-size: 15px; font-weight: 600; color: #FCAB14;'>$team_text</p>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Divider -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='padding: 0 30px;'>
                                        <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <td style='height: 1px; background-color: #333333;'></td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Social Links -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td align='center' style='padding: 25px 30px;'>
                                        <table role='presentation' cellspacing='0' cellpadding='0' border='0'>
                                            <tr>
                                                <!-- Facebook -->
                                                <td style='padding: 0 6px;'>
                                                    <a href='https://www.facebook.com/profile.php?id=100086162780846' style='display: inline-block; width: 36px; height: 36px; background-color: #1a1a1a; border-radius: 50%; text-align: center; line-height: 36px; text-decoration: none; border: 1px solid #333333;'>
                                                        <span style='color: #ffffff; font-size: 14px; font-weight: bold; font-family: Arial, sans-serif;'>f</span>
                                                    </a>
                                                </td>
                                                <!-- Instagram -->
                                                <td style='padding: 0 6px;'>
                                                    <a href='https://www.instagram.com/area710_event/' style='display: inline-block; width: 36px; height: 36px; background-color: #1a1a1a; border-radius: 50%; text-align: center; line-height: 36px; text-decoration: none; border: 1px solid #333333;'>
                                                        <span style='color: #ffffff; font-size: 14px; font-weight: bold; font-family: Arial, sans-serif;'>in</span>
                                                    </a>
                                                </td>
                                                <!-- WhatsApp -->
                                                <td style='padding: 0 6px;'>
                                                    <a href='https://wa.me/491713573288' style='display: inline-block; width: 36px; height: 36px; background-color: #1a1a1a; border-radius: 50%; text-align: center; line-height: 36px; text-decoration: none; border: 1px solid #333333;'>
                                                        <span style='color: #ffffff; font-size: 14px; font-weight: bold; font-family: Arial, sans-serif;'>wa</span>
                                                    </a>
                                                </td>
                                            </tr>
                                        </table>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Footer inside card -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td align='center' style='padding: 0 30px 20px 30px;'>
                                        <p style='margin: 0 0 8px 0;'>
                                            <a href='{$baseUrl}' style='color: #FCAB14; text-decoration: none; font-size: 14px; font-weight: 600;'>area710.de</a>
                                        </p>
                                        <p style='margin: 0 0 12px 0; font-size: 12px; color: #666666;'>
                                            area710 | Gottlieb-Binder-Str. 2 | 71088 Holzgerlingen
                                        </p>
                                        <p style='margin: 0; font-size: 11px; color: #444444;'>
                                            <a href='{$baseUrl}/impressum.html' style='color: #666666; text-decoration: none;'>$imprint_label</a>
                                            <span style='margin: 0 8px; color: #333333;'>|</span>
                                            <a href='{$baseUrl}/datenschutz.html' style='color: #666666; text-decoration: none;'>$privacy_label</a>
                                        </p>
                                    </td>
                                </tr>
                            </table>
                            
                            <!-- Gradient Bottom Border -->
                            <table role='presentation' width='100%' cellspacing='0' cellpadding='0' border='0'>
                                <tr>
                                    <td style='height: 4px; background: linear-gradient(90deg, #FCAB14 0%, #CD1151 33%, #009FE2 66%, #AEC610 100%);'></td>
                                </tr>
                            </table>
                            
                        </td>
                    </tr>
                    
                </table>
            </td>
        </tr>
    </table>
    
</body>
</html>
";

// ========================================
// E-MAILS VERSENDEN
// ========================================

$mail_owner_sent = sendMail(
    $mailConfig,
    $mailConfig['recipient']['email'],
    $mailConfig['recipient']['name'],
    $subject_owner,
    $message_owner,
    $email,  // Reply-To: Kunde
    "$firstName $lastName"
);

// Verzögerung für Rate-Limiting (Mailtrap Free: max 1-15 Mails pro 10 Sek)
// Für Produktion kann dieser Wert auf 1 reduziert werden
sleep(10);

$mail_customer_sent = sendMail(
    $mailConfig,
    $email,
    "$firstName $lastName",
    $subject_customer,
    $message_customer,
    $mailConfig['recipient']['email'],  // Reply-To: area710
    $mailConfig['recipient']['name']
);

// ========================================
// LOGGING
// ========================================

$logEntry = sprintf(
    "[%s] Booking request: %s %s (%s) | Event: %s %s-%s | Rooms: %s | Owner-Mail: %s | Customer-Mail: %s\n",
    date('Y-m-d H:i:s'),
    $firstName,
    $lastName,
    $email,
    $eventDate,
    $eventTime,
    $endTime,
    $roomsText,
    $mail_owner_sent ? 'OK' : 'FAILED',
    $mail_customer_sent ? 'OK' : 'FAILED'
);

// Log-Verzeichnis erstellen falls nicht vorhanden
$logDir = __DIR__ . '/logs';
if (!is_dir($logDir)) {
    @mkdir($logDir, 0755, true);
}
@error_log($logEntry, 3, $logDir . '/bookings.log');

// ========================================
// ANTWORT SENDEN
// ========================================

// Zusammenfassung für Frontend erstellen
$summary = [
    'name' => "$firstName $lastName",
    'email' => $email,
    'phone' => $phone,
    'company' => $company,
    'eventType' => $eventTypeLabel,
    'eventDate' => $dateFormatted,
    'eventTime' => $eventTime,
    'endTime' => $endTime,
    'duration' => number_format($duration, 1),
    'guests' => $guests,
    'rooms' => $roomsText,
    'services' => $servicesText,
    'message' => $message
];

if ($mail_owner_sent && $mail_customer_sent) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'ownerMailSent' => true,
        'customerMailSent' => true,
        'message' => $lang === 'de'
            ? 'Buchungsanfrage erfolgreich versendet'
            : 'Booking request sent successfully',
        'summary' => $summary
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'ownerMailSent' => $mail_owner_sent,
        'customerMailSent' => $mail_customer_sent,
        'error' => $lang === 'de'
            ? 'Fehler beim Versenden der E-Mails. Bitte kontaktieren Sie uns telefonisch.'
            : 'Error sending emails. Please contact us by phone.',
        'summary' => $summary
    ]);
}

exit();
?>
