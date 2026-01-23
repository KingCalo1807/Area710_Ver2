<?php
/**
 * area710 Booking Handler - SECURE VERSION
 * Verarbeitet Buchungsanfragen mit umfassenden Sicherheitsmaßnahmen
 *
 * Sicherheitsfeatures:
 * - Strikte CORS-Policy
 * - CSRF-Schutz
 * - Input-Validierung & Sanitization
 * - Rate-Limiting
 * - Whitelist-basierte Validierung
 * - XSS-Schutz
 */

// ========================================
// SICHERHEITS-KONFIGURATION
// ========================================

// Error Reporting für Produktion (keine Details nach außen!)
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', '/path/to/your/error.log'); // TODO: Anpassen!

// Session starten (für CSRF und Rate-Limiting)
session_start();

// ========================================
// CORS-KONFIGURATION (KRITISCH!)
// ========================================

// TODO: HIER DEINE ECHTE DOMAIN EINTRAGEN!
define('ALLOWED_ORIGIN', 'https://area710.de');

// Strikte CORS-Prüfung
if (isset($_SERVER['HTTP_ORIGIN'])) {
    if ($_SERVER['HTTP_ORIGIN'] === ALLOWED_ORIGIN) {
        header('Access-Control-Allow-Origin: ' . ALLOWED_ORIGIN);
        header('Access-Control-Allow-Credentials: true');
    } else {
        // Unerlaubte Origin - Zugriff verweigern
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

// Maximale Anfragen pro Session
define('MAX_REQUESTS_PER_MINUTE', 3);
define('RATE_LIMIT_WINDOW', 60); // Sekunden

if (!isset($_SESSION['requests'])) {
    $_SESSION['requests'] = [];
}

// Alte Requests entfernen
$_SESSION['requests'] = array_filter($_SESSION['requests'], function($timestamp) {
    return (time() - $timestamp) < RATE_LIMIT_WINDOW;
});

// Prüfen ob Limit überschritten
if (count($_SESSION['requests']) >= MAX_REQUESTS_PER_MINUTE) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'error' => 'Too many requests. Please try again later.'
    ]);
    exit();
}

// Request registrieren
$_SESSION['requests'][] = time();

// ========================================
// CSRF-TOKEN PRÜFEN
// ========================================

// CSRF-Token generieren falls nicht vorhanden
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
// E-MAIL-KONFIGURATION
// ========================================

define('RECIPIENT_EMAIL', 'info@area710.de');
define('RECIPIENT_NAME', 'area710 Eventbüro');
define('SENDER_EMAIL', 'noreply@area710.de');
define('SENDER_NAME', 'area710 Buchungssystem');
define('BCC_EMAIL', ''); // Optional

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
    'duration', 'guests'
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
// Erlaubt: Zahlen, Leerzeichen, +, -, (), /
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
    // Datum muss in der Zukunft liegen
    $today = new DateTime('today');
    if ($dateObj < $today) {
        $errors[] = 'Date must be in the future';
    }
    // Nicht mehr als 2 Jahre in der Zukunft
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

// --- DAUER ---
$duration = filter_var($data['duration'], FILTER_VALIDATE_INT);
if ($duration === false || $duration < 1 || $duration > 24) {
    $errors[] = 'Duration must be between 1 and 24 hours';
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
// Mindestens ein Raum muss gewählt sein
if (empty($rooms)) {
    $errors[] = 'At least one room must be selected';
}
$roomsText = implode(', ', array_map('htmlspecialchars', $rooms));

// --- SERVICES (Whitelist, Array-Validierung) ---
$services = [];
if (isset($data['services']) && is_array($data['services'])) {
    foreach ($data['services'] as $service) {
        if (in_array($service, $ALLOWED_SERVICES, true)) {
            $services[] = $service;
        }
    }
}
$servicesText = !empty($services)
    ? implode(', ', array_map('htmlspecialchars', $services))
    : 'Keine Auswahl';

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
// EVENT-TYP ÜBERSETZEN
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

// ========================================
// E-MAIL AN AREA710 (Owner)
// ========================================

$subject_owner = "Neue Buchungsanfrage von $firstName $lastName";

$message_owner = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #FCAB14, #CD1151); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .section { margin-bottom: 25px; }
        .section h3 { color: #FCAB14; border-bottom: 2px solid #FCAB14; padding-bottom: 5px; margin-bottom: 15px; }
        .info-row { display: flex; margin-bottom: 10px; }
        .label { font-weight: bold; min-width: 180px; color: #666; }
        .value { color: #333; }
        .footer { text-align: center; padding: 20px; color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>Neue Buchungsanfrage</h1>
            <p>area710 - UNIQUE EVENT LOCATION</p>
        </div>

        <div class='content'>
            <div class='section'>
                <h3>Kontaktdaten</h3>
                <div class='info-row'><span class='label'>Name:</span><span class='value'>$firstName $lastName</span></div>
                " . ($company ? "<div class='info-row'><span class='label'>Firma:</span><span class='value'>$company</span></div>" : "") . "
                <div class='info-row'><span class='label'>E-Mail:</span><span class='value'><a href='mailto:$email'>$email</a></span></div>
                <div class='info-row'><span class='label'>Telefon:</span><span class='value'>$phone</span></div>
            </div>

            <div class='section'>
                <h3>Event-Details</h3>
                <div class='info-row'><span class='label'>Art der Veranstaltung:</span><span class='value'>$eventTypeLabel</span></div>
                <div class='info-row'><span class='label'>Datum:</span><span class='value'>$eventDate</span></div>
                <div class='info-row'><span class='label'>Uhrzeit:</span><span class='value'>$eventTime Uhr</span></div>
                <div class='info-row'><span class='label'>Dauer:</span><span class='value'>$duration Stunden</span></div>
                <div class='info-row'><span class='label'>Anzahl Gäste:</span><span class='value'>$guests Personen</span></div>
            </div>

            <div class='section'>
                <h3>Raumauswahl</h3>
                <div class='info-row'><span class='label'>Gewünschte Räume:</span><span class='value'>$roomsText</span></div>
            </div>

            " . (!empty($services) ? "
            <div class='section'>
                <h3>Zusätzliche Services</h3>
                <div class='info-row'><span class='label'>Gewählte Services:</span><span class='value'>$servicesText</span></div>
            </div>
            " : "") . "

            " . ($message ? "
            <div class='section'>
                <h3>Nachricht</h3>
                <p>" . nl2br($message) . "</p>
            </div>
            " : "") . "
        </div>

        <div class='footer'>
            <p>Diese Anfrage wurde über das Buchungsformular auf area710.de gesendet.</p>
            <p>Bitte antworten Sie dem Kunden zeitnah.</p>
        </div>
    </div>
</body>
</html>
";

$headers_owner = "MIME-Version: 1.0" . "\r\n";
$headers_owner .= "Content-type:text/html;charset=UTF-8" . "\r\n";
$headers_owner .= "From: " . SENDER_NAME . " <" . SENDER_EMAIL . ">" . "\r\n";
$headers_owner .= "Reply-To: " . $firstName . " " . $lastName . " <" . $email . ">" . "\r\n";
if (BCC_EMAIL) {
    $headers_owner .= "Bcc: " . BCC_EMAIL . "\r\n";
}

$mail_owner_sent = @mail(RECIPIENT_EMAIL, $subject_owner, $message_owner, $headers_owner);

// ========================================
// BESTÄTIGUNGS-E-MAIL AN KUNDEN
// ========================================

$subject_customer = $lang === 'de'
    ? "Ihre Buchungsanfrage bei area710"
    : "Your booking request at area710";

$greeting = $lang === 'de'
    ? "Vielen Dank für Ihre Buchungsanfrage!"
    : "Thank you for your booking request!";

$intro = $lang === 'de'
    ? "Wir haben Ihre Anfrage erhalten und werden uns innerhalb von 24 Stunden bei Ihnen melden."
    : "We have received your request and will contact you within 24 hours.";

$summary_title = $lang === 'de' ? "Zusammenfassung Ihrer Anfrage:" : "Summary of your request:";
$event_title = $lang === 'de' ? "Event-Details:" : "Event details:";
$rooms_title = $lang === 'de' ? "Gewählte Räume:" : "Selected rooms:";
$services_title = $lang === 'de' ? "Zusätzliche Services:" : "Additional services:";
$questions = $lang === 'de'
    ? "Bei Fragen erreichen Sie uns unter:"
    : "If you have any questions, please contact us:";

$message_customer = "
<!DOCTYPE html>
<html>
<head>
    <meta charset='UTF-8'>
    <style>
        body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
        .container { max-width: 600px; margin: 0 auto; padding: 20px; }
        .header { background: linear-gradient(135deg, #FCAB14, #CD1151); color: white; padding: 30px; text-align: center; }
        .content { background: #f9f9f9; padding: 30px; }
        .section { margin-bottom: 20px; }
        .section h3 { color: #FCAB14; margin-bottom: 10px; }
        .info-row { margin-bottom: 8px; }
        .label { font-weight: bold; color: #666; }
        .contact-box { background: white; padding: 20px; border-left: 4px solid #FCAB14; margin-top: 20px; }
        .footer { text-align: center; padding: 20px; color: #999; font-size: 12px; }
    </style>
</head>
<body>
    <div class='container'>
        <div class='header'>
            <h1>$greeting</h1>
            <p>area710 - UNIQUE EVENT LOCATION</p>
        </div>

        <div class='content'>
            <p>Hallo $firstName $lastName,</p>
            <p>$intro</p>

            <h3>$summary_title</h3>

            <div class='section'>
                <p><span class='label'>$event_title</span></p>
                <div class='info-row'>$eventTypeLabel am $eventDate um $eventTime Uhr</div>
                <div class='info-row'>$duration Stunden | $guests Gäste</div>
            </div>

            <div class='section'>
                <p><span class='label'>$rooms_title</span></p>
                <div class='info-row'>$roomsText</div>
            </div>

            " . (!empty($services) ? "
            <div class='section'>
                <p><span class='label'>$services_title</span></p>
                <div class='info-row'>$servicesText</div>
            </div>
            " : "") . "

            <div class='contact-box'>
                <p><strong>$questions</strong></p>
                <p>📞 +49 7031 41073-11</p>
                <p>📧 info@area710.de</p>
                <p>🕒 Mo-Fr 10:00-18:00 Uhr</p>
            </div>
        </div>

        <div class='footer'>
            <p>Gottlieb-Binder-Straße 2 | D-71088 Holzgerlingen</p>
            <p>area710 eine Marke der seeeye Werbung & Event GmbH</p>
        </div>
    </div>
</body>
</html>
";

$headers_customer = "MIME-Version: 1.0" . "\r\n";
$headers_customer .= "Content-type:text/html;charset=UTF-8" . "\r\n";
$headers_customer .= "From: " . RECIPIENT_NAME . " <" . RECIPIENT_EMAIL . ">" . "\r\n";
$headers_customer .= "Reply-To: " . RECIPIENT_NAME . " <" . RECIPIENT_EMAIL . ">" . "\r\n";

$mail_customer_sent = @mail($email, $subject_customer, $message_customer, $headers_customer);

// ========================================
// LOGGING (Optional aber empfohlen)
// ========================================

$logEntry = sprintf(
    "[%s] Booking request: %s %s (%s) | Event: %s | Rooms: %s\n",
    date('Y-m-d H:i:s'),
    $firstName,
    $lastName,
    $email,
    $eventDate,
    $roomsText
);

@error_log($logEntry, 3, '/path/to/bookings.log'); // TODO: Pfad anpassen!

// ========================================
// ANTWORT SENDEN
// ========================================

if ($mail_owner_sent && $mail_customer_sent) {
    http_response_code(200);
    echo json_encode([
        'success' => true,
        'message' => $lang === 'de'
            ? 'Buchungsanfrage erfolgreich versendet'
            : 'Booking request sent successfully'
    ]);
} else {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $lang === 'de'
            ? 'Fehler beim Versenden der E-Mails'
            : 'Error sending emails'
    ]);
}

exit();
?>