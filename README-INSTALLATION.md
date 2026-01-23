# area710 Booking System - Sichere Version

## 🔐 Sicherheitsfeatures

Diese gehärtete Version enthält folgende Sicherheitsmaßnahmen:

### ✅ Implementierte Sicherheitsfeatures

1. **Strikte CORS-Policy**
   - Nur Anfragen von der eigenen Domain werden akzeptiert
   - Schutz vor Cross-Origin-Angriffen

2. **CSRF-Schutz**
   - Jede Anfrage benötigt ein gültiges Token
   - Tokens werden serverseitig validiert

3. **Rate-Limiting**
   - Maximal 3 Anfragen pro Minute pro Session
   - Schutz vor Spam und automatisierten Angriffen

4. **Input-Validierung**
   - Alle Eingaben werden serverseitig geprüft
   - Strikte Whitelists für Räume, Services, Event-Typen
   - Regex-Validierung für Namen, Telefon, etc.
   - Längenprüfungen für alle Felder

5. **XSS-Schutz**
   - Alle Ausgaben werden mit htmlspecialchars() gesichert
   - Schutz vor Code-Injection

6. **Request-Methoden-Prüfung**
   - Nur POST-Requests werden akzeptiert
   - OPTIONS für CORS-Preflight

7. **JSON-Validierung**
   - Strikte Prüfung der JSON-Struktur
   - Fehlerbehandlung bei ungültigen Daten

8. **Fehler-Logging**
   - Keine Fehlerdetails nach außen
   - Logging für Debugging

## 📦 Installation

### Schritt 1: Dateien hochladen

Laden Sie folgende Dateien auf Ihren Webserver:

```
/
├── booking-handler.php       (Hauptscript - SECURE VERSION)
├── get-csrf-token.php        (Token-Generator)
├── booking.html              (Formular - SECURE VERSION)
└── events.json               (Verfügbarkeits-Daten)
```

### Schritt 2: PHP-Konfiguration anpassen

**WICHTIG:** Öffnen Sie `booking-handler.php` und passen Sie folgende Zeilen an:

#### 1. Domain (Zeile 32) - PFLICHT!
```php
define('ALLOWED_ORIGIN', 'https://area710.de');  // ← IHRE DOMAIN EINTRAGEN!
```

#### 2. E-Mail-Adressen (Zeilen 121-124)
```php
define('RECIPIENT_EMAIL', 'info@area710.de');     // ← Empfänger-E-Mail
define('RECIPIENT_NAME', 'area710 Eventbüro');
define('SENDER_EMAIL', 'noreply@area710.de');     // ← Absender-E-Mail
define('SENDER_NAME', 'area710 Buchungssystem');
```

#### 3. Error-Log-Pfad (Zeile 15)
```php
ini_set('error_log', '/path/to/your/error.log'); // ← Pfad anpassen!
```

#### 4. Booking-Log (Zeile 428) - Optional
```php
@error_log($logEntry, 3, '/path/to/bookings.log'); // ← Pfad anpassen!
```

### Schritt 3: CSRF-Token-Generator konfigurieren

Öffnen Sie `get-csrf-token.php` und passen Sie die Domain an:

```php
header('Access-Control-Allow-Origin: https://area710.de'); // ← IHRE DOMAIN!
```

### Schritt 4: HTML anpassen (optional)

Falls Ihre PHP-Dateien andere Namen haben oder in einem Unterordner liegen, passen Sie in `booking.html` die URLs an:

```javascript
// Zeile ~885 - CSRF Token abrufen
const response = await fetch('get-csrf-token.php');

// Zeile ~1090 - Formular senden
const response = await fetch('booking-handler.php', {
```

### Schritt 5: Dateirechte setzen

Setzen Sie folgende Berechtigungen:

```bash
chmod 644 booking-handler.php
chmod 644 get-csrf-token.php
chmod 644 booking.html
chmod 644 events.json
```

### Schritt 6: E-Mail-Weiterleitung einrichten (Optional)

Falls `noreply@area710.de` nicht direkt funktioniert:

1. Gehen Sie in Ihr Hosting-Panel (z.B. Plesk, cPanel)
2. E-Mail-Einstellungen öffnen
3. Weiterleitung einrichten: `noreply@area710.de` → `info@area710.de`

## 🧪 Testen

### 1. CSRF-Token testen

Öffnen Sie im Browser:
```
https://ihre-domain.de/get-csrf-token.php
```

Erwartete Antwort:
```json
{"csrf_token":"a1b2c3d4e5f6..."}
```

### 2. Formular testen

1. Öffnen Sie `booking.html`
2. Öffnen Sie die Browser-Konsole (F12)
3. Prüfen Sie: "CSRF token loaded" erscheint
4. Füllen Sie das Formular aus
5. Senden Sie es ab

### 3. Rate-Limiting testen

Senden Sie das Formular 4x schnell hintereinander.
Beim 4. Mal sollte erscheinen:
```
"Too many requests. Please try again later."
```

## ⚠️ Wichtige Sicherheitshinweise

### DO:
✅ **Domain in CORS einschränken** - niemals `*` verwenden!
✅ **HTTPS verwenden** - kein HTTP in Produktion
✅ **Error-Logs regelmäßig prüfen**
✅ **Backups anlegen**
✅ **PHP-Version aktuell halten**

### DON'T:
❌ **Niemals `display_errors = 1` in Produktion**
❌ **Niemals CORS auf `*` setzen**
❌ **Keine Datenbank-Credentials im Code** (falls später DB genutzt wird)
❌ **Keine unvalidierten Eingaben verarbeiten**

## 📊 Konfigurierbare Limits

### Rate-Limiting anpassen

In `booking-handler.php` Zeile 66-67:

```php
define('MAX_REQUESTS_PER_MINUTE', 3);  // Anfragen pro Minute
define('RATE_LIMIT_WINDOW', 60);       // Zeitfenster in Sekunden
```

Empfohlene Werte:
- **Produktiv:** 3 Anfragen / 60 Sekunden
- **Testumgebung:** 10 Anfragen / 60 Sekunden

### Input-Limits anpassen

In `booking-handler.php` ab Zeile 145:

```php
// Namenslängen
if (strlen($firstName) < 2 || strlen($firstName) > 50) { ... }

// Telefonlänge
if (strlen($phone) < 5 || strlen($phone) > 30) { ... }

// Nachrichtenlänge
if (strlen($message) > 2000) { ... }

// Gästeanzahl
if ($guests === false || $guests < 1 || $guests > 1000) { ... }
```

## 🔍 Fehlersuche (Troubleshooting)

### Problem: "Invalid CSRF token"

**Ursache:** Session-Cookie wird nicht gesetzt

**Lösung:**
1. Prüfen Sie `php.ini`: `session.cookie_samesite = Lax`
2. Prüfen Sie Browser-Konsole auf Cookie-Fehler
3. Testen Sie in einem anderen Browser

### Problem: "Method not allowed"

**Ursache:** Falsche HTTP-Methode

**Lösung:**
1. Prüfen Sie, ob das Formular wirklich per POST sendet
2. Prüfen Sie Server-Logs

### Problem: E-Mails kommen nicht an

**Ursache:** PHP `mail()` oft nicht konfiguriert

**Lösungen:**
1. SMTP-Plugin nutzen (z.B. PHPMailer)
2. Hosting-Support kontaktieren
3. SPF/DKIM-Records prüfen

### Problem: "Too many requests" direkt beim ersten Mal

**Ursache:** Rate-Limit-Fenster zu klein

**Lösung:**
```php
define('MAX_REQUESTS_PER_MINUTE', 5);  // Erhöhen
```

## 📈 Monitoring

### Log-Dateien regelmäßig prüfen

```bash
# Error-Log
tail -f /pfad/zum/error.log

# Booking-Log
tail -f /pfad/zum/bookings.log
```

### Wichtige Metriken

- **Anzahl Buchungsanfragen pro Tag**
- **Anzahl CSRF-Fehler** (hohe Anzahl = Angriff?)
- **Anzahl Rate-Limit-Hits** (hohe Anzahl = Bot?)

## 🛡️ Zusätzliche Sicherheitsmaßnahmen (Optional)

### 1. Honeypot-Feld hinzufügen

In HTML:
```html
<input type="text" name="website" style="display:none" tabindex="-1" autocomplete="off">
```

In PHP:
```php
if (!empty($data['website'])) {
    // Bot detected!
    exit();
}
```

### 2. reCAPTCHA integrieren

Google reCAPTCHA v3 für unsichtbaren Bot-Schutz.

### 3. IP-basiertes Rate-Limiting

Statt Session → IP-Adresse nutzen:
```php
$ip = $_SERVER['REMOTE_ADDR'];
```

### 4. Datenbank-Logging

Alle Anfragen in MySQL speichern für Auswertung.

## 📞 Support

Bei Fragen oder Problemen:

1. Prüfen Sie die Logs
2. Aktivieren Sie temporär `error_reporting(E_ALL)` (nur zum Debuggen!)
3. Prüfen Sie Browser-Konsole auf JavaScript-Fehler

## 🔄 Updates

**Version:** 1.0 (Januar 2026)

**Changelog:**
- Initial release mit allen Sicherheitsfeatures
- CSRF-Schutz implementiert
- Rate-Limiting implementiert
- Strikte Input-Validierung
- XSS-Schutz

## ⚖️ Lizenz

Dieses System wurde für area710 entwickelt.
