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

// CSS-Styles (inline für E-Mail-Kompatibilität)
$emailStyles = "
    body { margin: 0; padding: 0; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background-color: #f5f5f5; }
    .email-wrapper { max-width: 600px; margin: 0 auto; background: #ffffff; }
    .header { background: #000000; padding: 30px 40px; text-align: center; }
    .header img { max-height: 50px; }
    .gradient-line { height: 4px; background: linear-gradient(90deg, #FCAB14 0%, #CD1151 33%, #009FE2 66%, #AEC610 100%); }
    .content { padding: 40px; color: #333333; line-height: 1.6; }
    .greeting { font-size: 24px; font-weight: 600; color: #000000; margin-bottom: 20px; }
    .intro-text { font-size: 16px; color: #555555; margin-bottom: 30px; }
    .section { margin-bottom: 30px; }
    .section-title { font-size: 14px; font-weight: 600; color: #FCAB14; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #f0f0f0; }
    .info-card { background: #f9f9f9; border-radius: 8px; padding: 20px; margin-bottom: 15px; }
    .info-row { display: flex; margin-bottom: 10px; }
    .info-label { font-weight: 600; color: #666666; min-width: 140px; }
    .info-value { color: #333333; }
    .highlight-box { background: linear-gradient(135deg, rgba(252,171,20,0.1) 0%, rgba(205,17,81,0.1) 100%); border-left: 4px solid #FCAB14; padding: 20px; border-radius: 0 8px 8px 0; margin: 20px 0; }
    .contact-box { background: #000000; color: #ffffff; padding: 25px; border-radius: 8px; margin-top: 30px; }
    .contact-box h3 { color: #FCAB14; margin: 0 0 15px 0; font-size: 16px; }
    .contact-item { margin-bottom: 10px; font-size: 14px; }
    .contact-item a { color: #ffffff; text-decoration: none; }
    .footer { background: #1a1a1a; color: #999999; padding: 30px 40px; text-align: center; font-size: 12px; }
    .footer a { color: #FCAB14; text-decoration: none; }
    .social-links { margin: 20px 0; }
    .social-link { display: inline-block; width: 36px; height: 36px; background: #333333; border-radius: 50%; margin: 0 5px; line-height: 36px; }
    .message-box { background: #fff8e6; border: 1px solid #FCAB14; border-radius: 8px; padding: 20px; margin-top: 15px; }
    .badge { display: inline-block; background: #FCAB14; color: #000000; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600; }
";

// ========================================
// E-MAIL AN AREA710 (Owner)
// ========================================

$subject_owner = "Neue Buchungsanfrage von $firstName $lastName";

$message_owner = "
<!DOCTYPE html>
<html lang='de'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Neue Buchungsanfrage</title>
    <style>$emailStyles</style>
</head>
<body style='margin: 0; padding: 20px; font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; background-color: #f5f5f5;'>
    <div class='email-wrapper' style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1);'>
        
        <!-- Header -->
        <div class='header' style='background: #000000; padding: 30px 40px; text-align: center;'>
            <img src='{$baseUrl}/img/logo.jpg' alt='area710' style='max-height: 50px;'>
        </div>
        
        <!-- Gradient Line -->
        <div style='height: 4px; background: linear-gradient(90deg, #FCAB14 0%, #CD1151 33%, #009FE2 66%, #AEC610 100%);'></div>
        
        <!-- Content -->
        <div style='padding: 40px; color: #333333; line-height: 1.6;'>
            
            <!-- Greeting -->
            <div style='font-size: 24px; font-weight: 600; color: #000000; margin-bottom: 10px;'>
                Neue Buchungsanfrage
            </div>
            <div style='font-size: 14px; color: #666666; margin-bottom: 30px;'>
                Eingegangen am " . date('d.m.Y') . " um " . date('H:i') . " Uhr
            </div>
            
            <!-- Contact Info -->
            <div style='margin-bottom: 30px;'>
                <div style='font-size: 14px; font-weight: 600; color: #FCAB14; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #f0f0f0;'>
                    Kontaktdaten
                </div>
                <div style='background: #f9f9f9; border-radius: 8px; padding: 20px;'>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 600; color: #666666; width: 140px;'>Name:</td>
                            <td style='padding: 8px 0; color: #333333;'>$firstName $lastName</td>
                        </tr>
                        " . ($company ? "
                        <tr>
                            <td style='padding: 8px 0; font-weight: 600; color: #666666;'>Firma:</td>
                            <td style='padding: 8px 0; color: #333333;'>$company</td>
                        </tr>
                        " : "") . "
                        <tr>
                            <td style='padding: 8px 0; font-weight: 600; color: #666666;'>E-Mail:</td>
                            <td style='padding: 8px 0;'><a href='mailto:$email' style='color: #009FE2; text-decoration: none;'>$email</a></td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 600; color: #666666;'>Telefon:</td>
                            <td style='padding: 8px 0;'><a href='tel:$phone' style='color: #009FE2; text-decoration: none;'>$phone</a></td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Event Details -->
            <div style='margin-bottom: 30px;'>
                <div style='font-size: 14px; font-weight: 600; color: #CD1151; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #f0f0f0;'>
                    Event-Details
                </div>
                <div style='background: #f9f9f9; border-radius: 8px; padding: 20px;'>
                    <table style='width: 100%; border-collapse: collapse;'>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 600; color: #666666; width: 140px;'>Veranstaltung:</td>
                            <td style='padding: 8px 0;'><span style='display: inline-block; background: #FCAB14; color: #000000; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;'>$eventTypeLabel</span></td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 600; color: #666666;'>Datum:</td>
                            <td style='padding: 8px 0; color: #333333; font-weight: 600;'>$dateFormatted</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 600; color: #666666;'>Uhrzeit:</td>
                            <td style='padding: 8px 0; color: #333333;'>$eventTime – $endTime Uhr</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 600; color: #666666;'>Dauer:</td>
                            <td style='padding: 8px 0; color: #333333;'>" . number_format($duration, 1) . " Stunden</td>
                        </tr>
                        <tr>
                            <td style='padding: 8px 0; font-weight: 600; color: #666666;'>Gäste:</td>
                            <td style='padding: 8px 0; color: #333333;'>$guests Personen</td>
                        </tr>
                    </table>
                </div>
            </div>
            
            <!-- Rooms -->
            <div style='margin-bottom: 30px;'>
                <div style='font-size: 14px; font-weight: 600; color: #009FE2; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #f0f0f0;'>
                    Raumauswahl
                </div>
                <div style='background: linear-gradient(135deg, rgba(0,159,226,0.1) 0%, rgba(174,198,16,0.1) 100%); border-left: 4px solid #009FE2; padding: 20px; border-radius: 0 8px 8px 0;'>
                    $roomBadgesHtml
                </div>
            </div>
            
            <!-- Services -->
            " . (!empty($services) ? "
            <div style='margin-bottom: 30px;'>
                <div style='font-size: 14px; font-weight: 600; color: #AEC610; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #f0f0f0;'>
                    Zusätzliche Services
                </div>
                <div style='background: linear-gradient(135deg, rgba(174,198,16,0.1) 0%, rgba(252,171,20,0.1) 100%); border-left: 4px solid #AEC610; padding: 20px; border-radius: 0 8px 8px 0;'>
                    <strong style='color: #333333;'>$servicesText</strong>
                </div>
            </div>
            " : "") . "
            
            <!-- Message -->
            " . ($message ? "
            <div style='margin-bottom: 30px;'>
                <div style='font-size: 14px; font-weight: 600; color: #666666; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #f0f0f0;'>
                    Nachricht des Kunden
                </div>
                <div style='background: #fff8e6; border: 1px solid #FCAB14; border-radius: 8px; padding: 20px;'>
                    <p style='margin: 0; color: #333333; white-space: pre-wrap;'>" . nl2br($message) . "</p>
                </div>
            </div>
            " : "") . "
            
            <!-- Action Hint -->
            <div style='background: #CD1151; color: #ffffff; padding: 20px; border-radius: 8px; text-align: center; margin-top: 30px;'>
                <strong>Bei Fragen den Kunden kontaktieren</strong>
            </div>
            
        </div>
        
        <!-- Gradient Line -->
        <div style='height: 4px; background: linear-gradient(90deg, #FCAB14 0%, #CD1151 33%, #009FE2 66%, #AEC610 100%);'></div>
        
        <!-- Footer -->
        <div style='background: #1a1a1a; color: #999999; padding: 20px 40px; text-align: center; font-size: 12px;'>
            <p style='margin: 0;'>Diese Anfrage wurde über das Buchungsformular auf area710.de gesendet.</p>
        </div>
        
    </div>
</body>
</html>
";

// ========================================
// BESTÄTIGUNGS-E-MAIL AN KUNDEN
// ========================================

// Texte je nach Sprache
if ($lang === 'de') {
    $subject_customer = "Ihre Buchungsanfrage bei area710";
    $greeting_text = "Sehr geehrte/r $firstName $lastName,";
    $thanks_text = "vielen Dank für Ihre Buchungsanfrage bei area710. Wir haben Ihre Anfrage erhalten und werden uns innerhalb von 24 Stunden bei Ihnen melden.";
    $summary_title = "Zusammenfassung Ihrer Anfrage";
    $event_title = "Event-Details";
    $rooms_title = "Gewählte Räume";
    $services_title = "Zusätzliche Services";
    $next_steps_title = "Nächste Schritte";
    $next_steps_text = "Unser Team prüft Ihre Anfrage und erstellt ein individuelles Angebot für Sie. Sie erhalten in Kürze eine persönliche Rückmeldung per E-Mail oder Telefon.";
    $location_title = "Unser Standort";
    $contact_title = "Direkter Kontakt";
    $office_hours = "Öffnungszeiten Eventbüro";
    $hours_text = "Montag – Freitag: 10:00 – 18:00 Uhr";
    $closing_text = "Wir freuen uns auf Ihr Event!";
    $team_text = "Ihr area710 Team";
} else {
    $subject_customer = "Your booking request at area710";
    $greeting_text = "Dear $firstName $lastName,";
    $thanks_text = "Thank you for your booking request at area710. We have received your request and will contact you within 24 hours.";
    $summary_title = "Summary of your request";
    $event_title = "Event Details";
    $rooms_title = "Selected Rooms";
    $services_title = "Additional Services";
    $next_steps_title = "Next Steps";
    $next_steps_text = "Our team will review your request and prepare an individual offer for you. You will receive a personal response via email or phone shortly.";
    $location_title = "Our Location";
    $contact_title = "Direct Contact";
    $office_hours = "Office Hours";
    $hours_text = "Monday – Friday: 10:00 AM – 6:00 PM";
    $closing_text = "We look forward to your event!";
    $team_text = "Your area710 Team";
}

$message_customer = "
<!DOCTYPE html>
<html lang='$lang'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>$subject_customer</title>
    <style>$emailStyles</style>
</head>
<body style='margin: 0; padding: 20px; font-family: Segoe UI, Tahoma, Geneva, Verdana, sans-serif; background-color: #f5f5f5;'>
    <div class='email-wrapper' style='max-width: 600px; margin: 0 auto; background: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 4px 20px rgba(0,0,0,0.1);'>
        
        <!-- Header -->
        <div style='background: #000000; padding: 30px 40px; text-align: center;'>
            <img src='{$baseUrl}/img/logo.jpg' alt='area710' style='max-height: 50px;'>
        </div>
        
        <!-- Gradient Line -->
        <div style='height: 4px; background: linear-gradient(90deg, #FCAB14 0%, #CD1151 33%, #009FE2 66%, #AEC610 100%);'></div>
        
        <!-- Content -->
        <div style='padding: 40px; color: #333333; line-height: 1.6;'>
            
            <!-- Greeting -->
            <div style='font-size: 22px; font-weight: 600; color: #000000; margin-bottom: 20px;'>
                $greeting_text
            </div>
            <p style='font-size: 16px; color: #555555; margin-bottom: 30px;'>
                $thanks_text
            </p>
            
            <!-- Summary Box -->
            <div style='background: linear-gradient(135deg, rgba(252,171,20,0.1) 0%, rgba(205,17,81,0.1) 100%); border-radius: 8px; padding: 25px; margin-bottom: 30px;'>
                <div style='font-size: 16px; font-weight: 600; color: #000000; margin-bottom: 20px;'>$summary_title</div>
                
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 10px 0; font-weight: 600; color: #666666; width: 140px; vertical-align: top;'>$event_title:</td>
                        <td style='padding: 10px 0; color: #333333;'>
                            <span style='display: inline-block; background: #FCAB14; color: #000000; padding: 4px 12px; border-radius: 20px; font-size: 12px; font-weight: 600;'>$eventTypeLabel</span><br>
                            <span style='font-size: 14px;'>$dateFormatted, $eventTime – $endTime Uhr<br>" . number_format($duration, 1) . " " . ($lang === 'de' ? 'Stunden' : 'hours') . " | $guests " . ($lang === 'de' ? 'Gäste' : 'guests') . "</span>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding: 10px 0; font-weight: 600; color: #666666; vertical-align: top;'>$rooms_title:</td>
                        <td style='padding: 10px 0; color: #333333;'>$roomBadgesHtml</td>
                    </tr>
                    " . (!empty($services) ? "
                    <tr>
                        <td style='padding: 10px 0; font-weight: 600; color: #666666; vertical-align: top;'>$services_title:</td>
                        <td style='padding: 10px 0; color: #333333;'>$servicesText</td>
                    </tr>
                    " : "") . "
                </table>
            </div>
            
            <!-- Next Steps -->
            <div style='margin-bottom: 30px;'>
                <div style='font-size: 14px; font-weight: 600; color: #CD1151; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #f0f0f0;'>
                    $next_steps_title
                </div>
                <p style='color: #555555; margin: 0;'>$next_steps_text</p>
            </div>
            
            <!-- Location -->
            <div style='margin-bottom: 30px;'>
                <div style='font-size: 14px; font-weight: 600; color: #009FE2; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 15px; padding-bottom: 8px; border-bottom: 2px solid #f0f0f0;'>
                    $location_title
                </div>
                <div style='background: #f9f9f9; border-radius: 8px; padding: 20px;'>
                    <p style='margin: 0 0 10px 0; font-weight: 600; color: #333333;'>area710 - UNIQUE EVENT LOCATION</p>
                    <p style='margin: 0; color: #666666;'>
                        Gottlieb-Binder-Straße 2<br>
                        D-71088 Holzgerlingen
                    </p>
                </div>
            </div>
            
            <!-- Contact Box -->
            <div style='background: #000000; color: #ffffff; padding: 25px; border-radius: 8px;'>
                <div style='font-size: 14px; font-weight: 600; color: #FCAB14; margin-bottom: 15px;'>$contact_title</div>
                <table style='width: 100%; border-collapse: collapse;'>
                    <tr>
                        <td style='padding: 5px 0; color: #ffffff;'>
                            <span style='margin-right: 10px;'>&#128222;</span>
                            <a href='tel:+4970314107311' style='color: #ffffff; text-decoration: none;'>+49 7031 41073-11</a>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #ffffff;'>
                            <span style='margin-right: 10px;'>&#9993;</span>
                            <a href='mailto:info@area710.de' style='color: #ffffff; text-decoration: none;'>info@area710.de</a>
                        </td>
                    </tr>
                    <tr>
                        <td style='padding: 5px 0; color: #999999;'>
                            <span style='margin-right: 10px;'>&#128337;</span>
                            $hours_text
                        </td>
                    </tr>
                </table>
            </div>
            
            <!-- Closing -->
            <div style='text-align: center; margin-top: 40px;'>
                <p style='font-size: 18px; color: #333333; margin-bottom: 5px;'>$closing_text</p>
                <p style='font-size: 16px; color: #FCAB14; font-weight: 600; margin: 0;'>$team_text</p>
            </div>
            
        </div>
        
        <!-- Gradient Line -->
        <div style='height: 4px; background: linear-gradient(90deg, #FCAB14 0%, #CD1151 33%, #009FE2 66%, #AEC610 100%);'></div>
        
        <!-- Footer -->
        <div style='background: #1a1a1a; color: #999999; padding: 30px 40px; text-align: center; font-size: 12px;'>
            
            <!-- Social Links -->
            <div style='margin-bottom: 20px;'>
                <a href='https://www.facebook.com/profile.php?id=100086162780846' style='display: inline-block; width: 36px; height: 36px; background: #333333; border-radius: 50%; margin: 0 5px; line-height: 36px; text-decoration: none; color: #ffffff;'>f</a>
                <a href='https://www.instagram.com/area710_event/' style='display: inline-block; width: 36px; height: 36px; background: #333333; border-radius: 50%; margin: 0 5px; line-height: 36px; text-decoration: none; color: #ffffff;'>&#128247;</a>
                <a href='https://wa.me/1713573288' style='display: inline-block; width: 36px; height: 36px; background: #333333; border-radius: 50%; margin: 0 5px; line-height: 36px; text-decoration: none; color: #ffffff;'>&#128172;</a>
            </div>
            
            <p style='margin: 0 0 10px 0;'>
                <a href='{$baseUrl}' style='color: #FCAB14; text-decoration: none;'>area710.de</a>
            </p>
            <p style='margin: 0 0 10px 0;'>
                Gottlieb-Binder-Straße 2 | D-71088 Holzgerlingen
            </p>
            <p style='margin: 0; color: #666666;'>
                area710 eine Marke der seeeye Werbung & Event GmbH
            </p>
            <p style='margin: 15px 0 0 0;'>
                <a href='{$baseUrl}/impressum.html' style='color: #666666; text-decoration: none; margin: 0 10px;'>" . ($lang === 'de' ? 'Impressum' : 'Imprint') . "</a>
                <a href='{$baseUrl}/datenschutz.html' style='color: #666666; text-decoration: none; margin: 0 10px;'>" . ($lang === 'de' ? 'Datenschutz' : 'Privacy Policy') . "</a>
            </p>
        </div>
        
    </div>
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
