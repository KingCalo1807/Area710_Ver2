# 🔐 Sicherheits-Checkliste - area710 Booking System

## Vor dem Go-Live ALLE Punkte prüfen!

### ✅ KRITISCH - MUSS gemacht werden

- [ ] **CORS Domain konfiguriert** (booking-handler.php Zeile 32)
  ```php
  define('ALLOWED_ORIGIN', 'https://area710.de');
  ```
  ⚠️ NIEMALS `*` verwenden!

- [ ] **CORS Domain in get-csrf-token.php** (Zeile 14)
  ```php
  header('Access-Control-Allow-Origin: https://area710.de');
  ```

- [ ] **E-Mail-Adressen angepasst** (booking-handler.php Zeilen 121-124)
  - [ ] RECIPIENT_EMAIL
  - [ ] SENDER_EMAIL
  - [ ] BCC_EMAIL (wenn gewünscht)

- [ ] **Error Reporting ausgeschaltet** (booking-handler.php Zeile 14)
  ```php
  error_reporting(0);
  ini_set('display_errors', 0);
  ```

- [ ] **Log-Pfade angepasst** (booking-handler.php)
  - [ ] Zeile 15: error.log Pfad
  - [ ] Zeile 428: bookings.log Pfad

- [ ] **HTTPS aktiviert** 
  - [ ] Website läuft über HTTPS
  - [ ] SSL-Zertifikat gültig
  - [ ] HTTP → HTTPS Redirect aktiv

- [ ] **Session-Cookies sicher konfiguriert**
  ```php
  // In php.ini oder .htaccess:
  session.cookie_httponly = 1
  session.cookie_secure = 1  (nur für HTTPS!)
  session.cookie_samesite = Lax
  ```

### ⚙️ WICHTIG - Sollte gemacht werden

- [ ] **Rate-Limiting getestet**
  - [ ] 4x schnell hintereinander senden
  - [ ] Fehler "Too many requests" erscheint

- [ ] **CSRF-Token funktioniert**
  - [ ] Browser-Konsole zeigt "CSRF token loaded"
  - [ ] Token wird bei jedem Request mitgeschickt

- [ ] **E-Mail-Versand getestet**
  - [ ] Testbuchung durchführen
  - [ ] E-Mail an Eventbüro kommt an
  - [ ] Bestätigungs-E-Mail an Kunden kommt an
  - [ ] E-Mails landen nicht im Spam

- [ ] **Input-Validierung getestet**
  - [ ] Ungültige E-Mail eingeben → Fehler
  - [ ] Zu kurzer Name → Fehler
  - [ ] Datum in der Vergangenheit → Fehler
  - [ ] Kein Raum gewählt → Fehler

- [ ] **Verfügbarkeitsprüfung funktioniert**
  - [ ] Datum mit geblockten Räumen wählen
  - [ ] Räume werden korrekt gesperrt
  - [ ] Warnung wird angezeigt

- [ ] **Mehrsprachigkeit funktioniert**
  - [ ] DE/EN umschalten
  - [ ] Alle Texte werden übersetzt
  - [ ] Fehlermeldungen in richtiger Sprache

### 🔍 EMPFOHLEN - Zusätzliche Checks

- [ ] **Dateirechte korrekt gesetzt**
  ```bash
  chmod 644 booking-handler.php
  chmod 644 get-csrf-token.php
  chmod 644 booking.html
  ```

- [ ] **PHP-Version aktuell** (mind. PHP 7.4, besser 8.0+)
  ```bash
  php -v
  ```

- [ ] **Log-Dateien angelegt & beschreibbar**
  ```bash
  touch /pfad/zum/error.log
  chmod 666 /pfad/zum/error.log
  touch /pfad/zum/bookings.log
  chmod 666 /pfad/zum/bookings.log
  ```

- [ ] **Backup-Strategie vorhanden**
  - [ ] Regelmäßige Backups der PHP-Dateien
  - [ ] Backup der events.json

- [ ] **Monitoring eingerichtet**
  - [ ] Log-Dateien werden überwacht
  - [ ] Benachrichtigung bei Fehlern

### 🧪 Testfälle durchführen

#### Test 1: Normale Buchung
- [ ] Formular vollständig ausfüllen
- [ ] Einen Raum wählen
- [ ] Services auswählen
- [ ] Absenden
- [ ] ✅ Erfolgsseite erscheint
- [ ] ✅ E-Mails kommen an

#### Test 2: Ungültige Eingaben
- [ ] E-Mail: "test@test" eingeben
- [ ] ❌ Fehler erscheint
- [ ] Name: "A" eingeben
- [ ] ❌ Fehler erscheint
- [ ] Datum in Vergangenheit
- [ ] ❌ Fehler erscheint

#### Test 3: CSRF-Schutz
- [ ] Seite öffnen, Token laden
- [ ] In neuem Tab: Seite nochmal öffnen
- [ ] Im ALTEN Tab: Formular absenden
- [ ] ❌ CSRF-Fehler (oder Erfolg mit neuem Token - je nach Session)

#### Test 4: Rate-Limiting
- [ ] Formular 3x schnell absenden
- [ ] ✅ Alle 3 erfolgreich
- [ ] 4. Mal absenden
- [ ] ❌ "Too many requests"

#### Test 5: Geblockter Raum
- [ ] In events.json Raum für heute blocken
- [ ] Heute als Datum wählen
- [ ] ⚠️ Warnung erscheint
- [ ] Raum ist deaktiviert
- [ ] Andere Räume wählbar

#### Test 6: Alle Räume geblockt
- [ ] In events.json alle Räume für morgen blocken
- [ ] Morgen als Datum wählen
- [ ] ❌ Alle Räume gesperrt
- [ ] ❌ Submit-Button deaktiviert

### 🚨 Sicherheits-Penetrationstests

#### Test 1: XSS-Versuch
Eingabe in Nachrichtenfeld:
```html
<script>alert('XSS')</script>
```
- [ ] ✅ Kein Alert-Popup
- [ ] ✅ Code wird escaped in E-Mail

#### Test 2: SQL-Injection (falls DB aktiv)
Eingabe in Namensfeld:
```sql
'; DROP TABLE users; --
```
- [ ] ✅ Wird als Text behandelt
- [ ] ✅ Keine Datenbank-Fehler

#### Test 3: CORS-Umgehung
Von fremder Domain fetch() aufrufen:
```javascript
fetch('https://area710.de/booking-handler.php', {
  method: 'POST',
  body: JSON.stringify({...})
})
```
- [ ] ❌ CORS-Fehler im Browser
- [ ] ❌ Request wird blockiert

#### Test 4: CSRF ohne Token
POST-Request ohne csrf_token senden:
```bash
curl -X POST https://area710.de/booking-handler.php \
  -H "Content-Type: application/json" \
  -d '{"firstName":"Test"}'
```
- [ ] ❌ HTTP 403 Forbidden
- [ ] ❌ "Invalid CSRF token"

#### Test 5: Rate-Limit-Umgehung
Schnell 10 Requests hintereinander:
```bash
for i in {1..10}; do
  curl -X POST ... &
done
```
- [ ] ✅ Max. 3 erfolgreich
- [ ] ❌ Rest: "Too many requests"

### 📊 Performance-Tests

- [ ] **Ladezeit < 2 Sekunden**
  - [ ] Booking.html lädt schnell
  - [ ] CSRF-Token wird sofort geladen

- [ ] **Formular-Submit < 3 Sekunden**
  - [ ] Request dauert nicht zu lang
  - [ ] Keine Timeouts

### 🔐 Server-Sicherheit

- [ ] **PHP hardened**
  - [ ] `allow_url_fopen = Off` (in php.ini)
  - [ ] `expose_php = Off`
  - [ ] `disable_functions` konfiguriert

- [ ] **ModSecurity aktiviert** (falls verfügbar)
  - [ ] OWASP Core Rule Set
  - [ ] Custom Rules für /booking-handler.php

- [ ] **Firewall-Regeln**
  - [ ] Nur Port 80/443 offen
  - [ ] Fail2Ban aktiv gegen Brute-Force

### 📝 Dokumentation

- [ ] **README für Team vorhanden**
  - [ ] Installationsschritte dokumentiert
  - [ ] Kontaktpersonen eingetragen

- [ ] **Notfall-Kontakte definiert**
  - [ ] Wer reagiert bei Ausfall?
  - [ ] Backup-Kontakte vorhanden

- [ ] **Änderungs-Log führen**
  - [ ] Datum der letzten Änderung
  - [ ] Was wurde geändert

### 🎯 Go-Live Checklist

**Finale Schritte:**

1. [ ] Alle Tests durchgeführt
2. [ ] Alle KRITISCHEN Punkte erledigt
3. [ ] Backup angelegt
4. [ ] Team informiert
5. [ ] Monitoring aktiviert
6. [ ] Alte Version deaktivieren
7. [ ] Neue Version aktivieren
8. [ ] 24h beobachten
9. [ ] Erste echte Buchungen testen

### 🚀 Nach Go-Live

**In den ersten 24 Stunden:**

- [ ] Stündlich Logs prüfen
- [ ] Test-Buchung von extern
- [ ] E-Mail-Zustellung prüfen
- [ ] Performance monitoren

**In der ersten Woche:**

- [ ] Täglich Logs prüfen
- [ ] Fehlerrate überwachen
- [ ] User-Feedback sammeln

**Laufend:**

- [ ] Wöchentlich Logs durchsehen
- [ ] Monatlich Updates prüfen
- [ ] Quartalsweise Security-Audit

---

## 📅 Sign-Off

**Checkliste ausgefüllt von:**
- Name: ____________________
- Datum: ____________________
- Unterschrift: ____________________

**Freigabe erteilt von:**
- Name: ____________________
- Datum: ____________________
- Unterschrift: ____________________

---

## ⚠️ Bei Problemen nach Go-Live

**Emergency-Rollback:**

1. Alte Version wiederherstellen
2. DNS/Routing zurücksetzen
3. Team informieren
4. Fehler analysieren
5. Fix entwickeln
6. Erneut testen
7. Neuer Go-Live

**Notfall-Kontakte:**

- Hosting-Support: ____________________
- PHP-Entwickler: ____________________
- Sicherheit: ____________________
