<?php
/**
 * SMTP-Konfiguration für area710 Buchungssystem
 * 
 * WICHTIG: Diese Datei enthält sensible Zugangsdaten!
 *
 * - Durch .htaccess vor direktem Zugriff geschützt
 * 
 * ANLEITUNG:
 * 1. Mailbox "noreply@area710.de" in Plesk erstellen
 * 2. Passwort unten eintragen
 * 3. Datei auf Server hochladen
 */

return [
    // SMTP-Server Einstellungen
    'smtp' => [
        'host'       => 'sandbox.smtp.mailtrap.io',
        'port'       => 2525,
        'encryption' => 'tls',  // STARTTLS
        'auth'       => true,
    ],
    
    // Zugangsdaten (HIER EINTRAGEN!)
    'credentials' => [
        'username' => '72b29d9c24556a',
        'password' => 'd875542d2151c2',  // ← TODO: Passwort eintragen!
    ],
    
    // Absender-Informationen
    'sender' => [
        'email' => 'noreply@area710.de',
        'name'  => 'area710 Buchungssystem',
    ],
    
    // Empfänger für Buchungsanfragen
    'recipient' => [
        'email' => 'info@area710.de',
        'name'  => 'area710 Eventbüro',
    ],
    
    // Optional: BCC-Empfänger (leer lassen wenn nicht benötigt)
    'bcc' => '',
    
    // Basis-URL für Bilder in E-Mails
    'base_url' => 'https://area710.de',
];
