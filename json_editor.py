"""
JSON Editor für Events & Gallery - Web-basierte Version
Funktioniert auf allen Betriebssystemen ohne tkinter-Probleme
"""

import os
import json
import shutil
from datetime import datetime
from flask import Flask, render_template_string, request, jsonify, send_from_directory, send_file
from werkzeug.utils import secure_filename
import webbrowser
from threading import Timer
import zipfile
import io

app = Flask(__name__)
app.config['MAX_CONTENT_LENGTH'] = 16 * 1024 * 1024  # 16MB max

# Globale Variable für Projektpfad
PROJECT_PATH = os.environ.get('PROJECT_PATH', None)


# --------------------------------------
# Hilfsfunktionen
# --------------------------------------
def load_json(path):
    """Lädt JSON-Datei oder gibt leere Liste zurück"""
    if os.path.exists(path):
        try:
            with open(path, "r", encoding="utf-8") as f:
                return json.load(f)
        except Exception as e:
            print(f"Fehler beim Laden von {path}: {e}")
            return []
    return []


def save_json(path, data):
    """Speichert JSON mit Backup"""
    try:
        backup_path = path + ".backup"
        if os.path.exists(path):
            shutil.copyfile(path, backup_path)
        with open(path, "w", encoding="utf-8") as f:
            json.dump(data, f, indent=4, ensure_ascii=False)
        return True
    except Exception as e:
        print(f"Fehler beim Speichern: {e}")
        return False


def copy_image(file, dest_folder, project_path):
    """Kopiert Bild in Zielordner und gibt relativen Pfad zurück"""
    try:
        os.makedirs(dest_folder, exist_ok=True)
        filename = secure_filename(file.filename)
        dest_path = os.path.join(dest_folder, filename)
        file.save(dest_path)

        # Relativen Pfad zum Projektordner berechnen
        rel_path = os.path.relpath(dest_path, project_path)
        return rel_path.replace("\\", "/")
    except Exception as e:
        print(f"Fehler beim Kopieren des Bildes: {e}")
        return ""


# --------------------------------------
# HTML Template
# --------------------------------------
HTML_TEMPLATE = """
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>JSON Editor – Events & Gallery | area710</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
     <link rel="icon" type="image/png" href="/img/fav.png">
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@100;300;400;500;700;900&display=swap" rel="stylesheet">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        :root {
            --orange: #FCAB14;
            --red: #CD1151;
            --blue: #009FE2;
            --green: #AEC610;
            --black: #000;
            --white: #fff;
            --gray: #222;
            --border: rgba(255, 255, 255, 0.1);
        }

        body {
            font-family: 'Roboto', sans-serif;
            background: var(--black);
            color: var(--white);
            line-height: 1.6;
            padding: 20px;
        }

        .container {
            max-width: 1400px;
            margin: 0 auto;
            background: var(--gray);
            border-radius: 20px;
            box-shadow: 0 8px 40px rgba(0, 0, 0, 0.6);
            padding: 40px;
            border: 1px solid var(--border);
        }

        h1 {
            text-align: center;
            font-size: clamp(2rem, 5vw, 3rem);
            font-weight: 300;
            letter-spacing: 0.1em;
            margin-bottom: 2rem;
            background: linear-gradient(135deg, var(--orange), var(--red), var(--blue), var(--green));
            background-size: 200% auto;
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            animation: shimmer 4s ease-in-out infinite;
        }

        @keyframes shimmer {
            0%, 100% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
        }

        .project-selector {
            display: flex;
            gap: 15px;
            margin-bottom: 40px;
            padding: 25px;
            background: rgba(0, 0, 0, 0.4);
            border-radius: 12px;
            border: 1px solid var(--border);
        }

        .project-selector input {
            flex: 1;
            padding: 12px 18px;
            background: rgba(0, 0, 0, 0.6);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--white);
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            transition: all 0.3s ease;
        }

        .project-selector input:focus {
            outline: none;
            border-color: var(--orange);
            background: rgba(0, 0, 0, 0.8);
        }

        .project-selector input::placeholder {
            color: rgba(255, 255, 255, 0.4);
        }

        .btn {
            padding: 12px 28px;
            font-size: 14px;
            font-weight: 400;
            letter-spacing: 0.05em;
            border: 1px solid var(--white);
            background: transparent;
            color: var(--white);
            cursor: pointer;
            border-radius: 8px;
            position: relative;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            font-family: 'Roboto', sans-serif;
        }

        .btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: var(--white);
            transition: left 0.4s cubic-bezier(0.4, 0, 0.2, 1);
            z-index: -1;
        }

        .btn:hover::before {
            left: 0;
        }

        .btn:hover {
            color: var(--black);
            border-color: var(--white);
        }

        .btn-primary {
            background: var(--orange);
            border-color: var(--orange);
            color: var(--black);
        }

        .btn-primary::before {
            background: transparent;
        }

        .btn-primary:hover {
            background: transparent;
            color: var(--orange);
        }

        .btn-success {
            background: var(--green);
            border-color: var(--green);
            color: var(--black);
        }

        .btn-success::before {
            background: transparent;
        }

        .btn-success:hover {
            background: transparent;
            color: var(--green);
        }

        .btn-danger {
            background: var(--red);
            border-color: var(--red);
            color: var(--white);
        }

        .btn-danger::before {
            background: transparent;
        }

        .btn-danger:hover {
            background: transparent;
            color: var(--red);
        }

        .tabs {
            display: flex;
            gap: 8px;
            border-bottom: 2px solid var(--border);
            margin-bottom: 30px;
        }

        .tab {
            padding: 14px 28px;
            background: rgba(0, 0, 0, 0.3);
            border: none;
            cursor: pointer;
            font-size: 15px;
            font-weight: 400;
            letter-spacing: 0.05em;
            border-radius: 12px 12px 0 0;
            transition: all 0.3s ease;
            color: var(--white);
            font-family: 'Roboto', sans-serif;
            position: relative;
        }

        .tab::after {
            content: '';
            position: absolute;
            bottom: -2px;
            left: 0;
            width: 0;
            height: 2px;
            background: linear-gradient(90deg, var(--orange), var(--red), var(--blue), var(--green));
            transition: width 0.4s cubic-bezier(0.4, 0, 0.2, 1);
        }

        .tab.active {
            background: rgba(252, 171, 20, 0.15);
            color: var(--orange);
        }

        .tab.active::after {
            width: 100%;
        }

        .tab:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .list-controls {
            display: flex;
            gap: 12px;
            margin-bottom: 25px;
        }

        .item-list {
            list-style: none;
            border: 1px solid var(--border);
            border-radius: 12px;
            max-height: 450px;
            overflow-y: auto;
            margin-bottom: 20px;
            background: rgba(0, 0, 0, 0.4);
        }

        .item-list::-webkit-scrollbar {
            width: 8px;
        }

        .item-list::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.3);
        }

        .item-list::-webkit-scrollbar-thumb {
            background: var(--orange);
            border-radius: 4px;
        }

        .item-list li {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            cursor: default;
            transition: all 0.3s ease;
            position: relative;
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .item-list li:last-child {
            border-bottom: none;
        }

        /* Label nimmt den ganzen Platz */
        .item-list li label {
            flex: 1;
            margin: 0;
        }

        .item-list li::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            width: 0;
            height: 100%;
            background: linear-gradient(90deg, rgba(252, 171, 20, 0.2), transparent);
            transition: width 0.3s ease;
        }

        .item-list li:hover {
            background: rgba(255, 255, 255, 0.03);
        }

        .item-list li:hover::before {
            width: 100%;
        }

        .item-list li.selected {
            background: rgba(252, 171, 20, 0.15);
            border-left: 4px solid var(--orange);
            padding-left: 16px;
        }

        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.9);
            backdrop-filter: blur(10px);
            z-index: 1000;
            overflow-y: auto;
        }

        .modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 20px;
        }

        .modal-content {
            background: var(--gray);
            padding: 40px;
            border-radius: 16px;
            max-width: 650px;
            width: 100%;
            max-height: 90vh;
            overflow-y: auto;
            border: 1px solid var(--border);
            box-shadow: 0 10px 50px rgba(0, 0, 0, 0.8);
        }

        .modal-content::-webkit-scrollbar {
            width: 8px;
        }

        .modal-content::-webkit-scrollbar-track {
            background: rgba(0, 0, 0, 0.3);
        }

        .modal-content::-webkit-scrollbar-thumb {
            background: var(--orange);
            border-radius: 4px;
        }

        .modal-content h2 {
            font-size: 1.8rem;
            font-weight: 300;
            margin-bottom: 30px;
            color: var(--orange);
            letter-spacing: 0.05em;
        }

        .form-group {
            margin-bottom: 25px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 400;
            color: rgba(255, 255, 255, 0.9);
            font-size: 14px;
            letter-spacing: 0.03em;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 12px 16px;
            background: rgba(0, 0, 0, 0.6);
            border: 1px solid var(--border);
            border-radius: 8px;
            color: var(--white);
            font-size: 14px;
            font-family: 'Roboto', sans-serif;
            transition: all 0.3s ease;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            outline: none;
            border-color: var(--orange);
            background: rgba(0, 0, 0, 0.8);
        }

        .form-group textarea {
            resize: vertical;
            min-height: 100px;
        }

        .form-group select {
            cursor: pointer;
        }

        .form-group select[multiple] {
            padding: 8px;
        }

        .form-group select option {
            padding: 8px;
        }

        .form-group small {
            display: block;
            margin-top: 6px;
            color: rgba(255, 255, 255, 0.5);
            font-size: 12px;
        }

        .form-actions {
            display: flex;
            gap: 12px;
            justify-content: flex-end;
            margin-top: 30px;
            padding-top: 25px;
            border-top: 1px solid var(--border);
        }

        .alert {
            padding: 14px 18px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 14px;
            border: 1px solid;
            animation: slideIn 0.4s ease;
        }

        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(-10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .alert-success {
            background: rgba(174, 198, 16, 0.15);
            color: var(--green);
            border-color: var(--green);
        }

        .alert-error {
            background: rgba(205, 17, 81, 0.15);
            color: var(--red);
            border-color: var(--red);
        }

        @media (max-width: 768px) {
            .container {
                padding: 20px;
            }

            .project-selector {
                flex-direction: column;
            }

            .tabs {
                flex-wrap: wrap;
            }

            .tab {
                flex: 1;
                min-width: 120px;
                text-align: center;
            }

            .list-controls {
                flex-wrap: wrap;
            }

            .btn {
                flex: 1;
            }
        }

        /* VORSCHAU BUTTON */
        .preview-btn {
            position: fixed;
            bottom: 30px;
            right: 30px;
            padding: 15px 25px;
            background: var(--orange);
            border: 2px solid var(--orange);
            color: var(--black);
            font-family: 'Roboto', sans-serif;
            font-size: 16px;
            font-weight: 500;
            cursor: pointer;
            border-radius: 50px;
            box-shadow: 0 8px 24px rgba(252, 171, 20, 0.4);
            transition: all 0.3s ease;
            z-index: 999;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .preview-btn:hover {
            background: transparent;
            color: var(--orange);
            transform: translateY(-3px);
            box-shadow: 0 12px 32px rgba(252, 171, 20, 0.6);
        }

        .preview-btn svg {
            width: 24px;
            height: 24px;
        }

        /* VORSCHAU MODAL */
        .preview-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.95);
            backdrop-filter: blur(10px);
            z-index: 10000;
            padding: 20px;
        }

        .preview-modal.active {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .preview-container {
            width: 100%;
            max-width: 1400px;
            height: 90vh;
            background: var(--gray);
            border-radius: 16px;
            border: 1px solid var(--border);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }

        .preview-header {
            padding: 20px 30px;
            border-bottom: 1px solid var(--border);
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: rgba(0, 0, 0, 0.4);
        }

        .preview-header h2 {
            font-size: 1.5rem;
            font-weight: 300;
            color: var(--orange);
        }

        .preview-tabs {
            display: flex;
            gap: 10px;
        }

        .preview-tab {
            padding: 8px 20px;
            background: transparent;
            border: 1px solid var(--border);
            color: var(--white);
            cursor: pointer;
            border-radius: 6px;
            font-family: 'Roboto', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .preview-tab.active {
            background: var(--orange);
            color: var(--black);
            border-color: var(--orange);
        }

        .preview-close {
            background: transparent;
            border: 2px solid var(--red);
            color: var(--red);
            cursor: pointer;
            border-radius: 6px;
            padding: 8px 20px;
            font-family: 'Roboto', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
        }

        .preview-close:hover {
            background: var(--red);
            color: var(--white);
        }

        .preview-iframe {
            flex: 1;
            border: none;
            background: var(--black);
        }

        @media (max-width: 768px) {
            .preview-btn {
                bottom: 20px;
                right: 20px;
                padding: 12px 20px;
                font-size: 14px;
            }

            .preview-btn span {
                display: none;
            }

            .preview-header {
                flex-direction: column;
                gap: 15px;
                align-items: stretch;
            }

            .preview-tabs {
                flex-direction: column;
            }

            .preview-tab {
                text-align: center;
            }
        }

        /* KALENDER STYLES */
        .calendar-grid div {
            transition: all 0.3s ease;
        }

        .calendar-grid div:hover {
            background: rgba(255, 255, 255, 0.05) !important;
        }

        /* DRAG & DROP - NUR für Kalender, NICHT für Listen */
        .calendar-grid div[draggable="true"] {
            cursor: move;
        }

        .item-list li:hover {
            background: rgba(255, 255, 255, 0.05);
        }

        .item-list li.dragging {
            opacity: 0.5;
        }

        .item-list li.drag-over {
            border-top: 2px solid var(--orange);
        }

        /* Checkbox Styling */
        .item-list input[type="checkbox"] {
            cursor: pointer;
            width: 18px;
            height: 18px;
            flex-shrink: 0;
        }

        /* SECONDARY BUTTON */
        .btn-secondary {
            background: rgba(255, 255, 255, 0.1);
            border-color: rgba(255, 255, 255, 0.3);
        }

        .btn-secondary:hover {
            background: rgba(255, 255, 255, 0.2);
            border-color: var(--white);
        }
    </style>
</head>
<body>
    <!-- Vorschau-Button (rechts unten fixiert) -->
    <button class="preview-btn" onclick="openPreview()" title="Webseiten-Vorschau">
        <svg width="24" height="24" fill="currentColor" viewBox="0 0 24 24">
            <path d="M12 4.5C7 4.5 2.73 7.61 1 12c1.73 4.39 6 7.5 11 7.5s9.27-3.11 11-7.5c-1.73-4.39-6-7.5-11-7.5zM12 17c-2.76 0-5-2.24-5-5s2.24-5 5-5 5 2.24 5 5-2.24 5-5 5zm0-8c-1.66 0-3 1.34-3 3s1.34 3 3 3 3-1.34 3-3-1.34-3-3-3z"/>
        </svg>
        <span>Vorschau</span>
    </button>

    <div class="container">
        <h1>area710 JSON Editor</h1>

        <div class="project-selector">
            <input type="text" id="projectPath" placeholder="Projektpfad eingeben (z.B. /Users/username/mein-projekt)" value="{{ project_path or '' }}">
            <button class="btn btn-primary" onclick="setProjectPath()">Pfad laden</button>
        </div>

        <div id="alert"></div>

        <div class="tabs">
            <button class="tab active" onclick="switchTab('dashboard')">Dashboard</button>
            <button class="tab" onclick="switchTab('calendar')">Kalender</button>
            <button class="tab" onclick="switchTab('events')">Events</button>
            <button class="tab" onclick="switchTab('blocked')">Private Veranstaltungen</button>
            <button class="tab" onclick="switchTab('gallery')">Gallery</button>
        </div>

        <!-- Dashboard Tab -->
        <div id="dashboard-tab" class="tab-content active">
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 30px;">
                <div style="background: rgba(252, 171, 20, 0.1); border: 1px solid var(--orange); border-radius: 12px; padding: 25px;">
                    <h3 style="color: var(--orange); font-size: 2.5rem; margin-bottom: 10px;" id="stats-total-events">-</h3>
                    <p style="color: rgba(255,255,255,0.8);">Gesamt Events</p>
                </div>
                <div style="background: rgba(174, 198, 16, 0.1); border: 1px solid var(--green); border-radius: 12px; padding: 25px;">
                    <h3 style="color: var(--green); font-size: 2.5rem; margin-bottom: 10px;" id="stats-upcoming">-</h3>
                    <p style="color: rgba(255,255,255,0.8);">Zukünftige Events</p>
                </div>
                <div style="background: rgba(0, 159, 226, 0.1); border: 1px solid var(--blue); border-radius: 12px; padding: 25px;">
                    <h3 style="color: var(--blue); font-size: 2.5rem; margin-bottom: 10px;" id="stats-gallery">-</h3>
                    <p style="color: rgba(255,255,255,0.8);">Gallery Bilder</p>
                </div>
                <div style="background: rgba(205, 17, 81, 0.1); border: 1px solid var(--red); border-radius: 12px; padding: 25px;">
                    <h3 style="color: var(--red); font-size: 2.5rem; margin-bottom: 10px;" id="stats-blocked">-</h3>
                    <p style="color: rgba(255,255,255,0.8);">Geblockte Termine</p>
                </div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px;">
                <div style="background: rgba(0, 0, 0, 0.4); border: 1px solid var(--border); border-radius: 12px; padding: 25px;">
                    <h3 style="margin-bottom: 20px; color: var(--orange);">Event Kategorien</h3>
                    <div id="category-stats"></div>
                </div>
                <div style="background: rgba(0, 0, 0, 0.4); border: 1px solid var(--border); border-radius: 12px; padding: 25px;">
                    <h3 style="margin-bottom: 20px; color: var(--green);">Nächste Events</h3>
                    <div id="next-events-list"></div>
                </div>
            </div>

            <div style="text-align: center; padding: 20px;">
                <button class="btn btn-primary" onclick="downloadBackup()" style="padding: 15px 40px; font-size: 16px;">
                    💾 Backup als ZIP herunterladen
                </button>
            </div>
        </div>

        <!-- Kalender Tab -->
        <div id="calendar-tab" class="tab-content">
            <div class="calendar-view-header" style="margin-bottom: 20px; padding: 20px; background: rgba(0,0,0,0.4); border-radius: 12px; border: 1px solid var(--border);">
                <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                    <h2 id="calendarEditorTitle" style="font-size: 1.8rem; font-weight: 300; color: var(--white);">Januar 2026</h2>
                    <div style="display: flex; gap: 10px;">
                        <button class="btn btn-primary" onclick="changeCalendarMonth(-1)">‹ Zurück</button>
                        <button class="btn btn-primary" onclick="goToToday()">Heute</button>
                        <button class="btn btn-primary" onclick="changeCalendarMonth(1)">Weiter ›</button>
                    </div>
                </div>
                <div style="margin-top: 15px; display: flex; gap: 10px; flex-wrap: wrap;">
                    <div style="display: flex; align-items: center; gap: 8px;">
                        <input type="checkbox" id="filter-future" checked onchange="filterCalendarEvents()">
                        <span>Nur zukünftige Events</span>
                    </div>
                </div>
            </div>

            <div style="overflow-x: auto;">
                <div style="min-width: 800px;">
                    <div class="calendar-grid" id="calendarEditorGrid" style="display: grid; grid-template-columns: repeat(7, 1fr); gap: 1px; background: var(--border); border: 1px solid var(--border); border-radius: 8px; overflow: hidden;"></div>
                </div>
            </div>

            <div style="margin-top: 20px; padding: 15px; background: rgba(0,0,0,0.3); border-radius: 8px; display: flex; gap: 20px; justify-content: center; flex-wrap: wrap; font-size: 14px;">
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 20px; height: 20px; background: linear-gradient(135deg, var(--blue), var(--blue)); border-radius: 3px;"></div>
                    <span>Öffentliches Event</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 20px; height: 20px; background: rgba(205, 17, 81, 0.3); border: 1px solid var(--red); border-radius: 3px;"></div>
                    <span>Privat / Geblockt</span>
                </div>
                <div style="display: flex; align-items: center; gap: 8px;">
                    <div style="width: 20px; height: 20px; background: rgba(174, 198, 16, 0.1); border: 2px solid var(--green); border-radius: 3px;"></div>
                    <span>Heute</span>
                </div>
            </div>
        </div>

        <!-- Events Tab -->
        <div id="events-tab" class="tab-content">
            <div style="margin-bottom: 20px; padding: 20px; background: rgba(0,0,0,0.4); border-radius: 12px; border: 1px solid var(--border);">
                <input type="text" id="search-events" placeholder="Suchen..."
                       style="width: 100%; padding: 10px; background: var(--black); border: 1px solid var(--border); color: var(--white); border-radius: 6px;"
                       oninput="applyEventsFilters()">
            </div>

            <div class="list-controls">
                <button class="btn btn-success" onclick="openEventModal()">Neu</button>
                <button class="btn btn-primary" onclick="editSelectedEvent()">Bearbeiten</button>
                <button class="btn btn-danger" onclick="deleteSelectedEvent()">Löschen</button>
            </div>
            <ul id="events-list" class="item-list"></ul>
        </div>

        <!-- Private Veranstaltungen Tab -->
        <div id="blocked-tab" class="tab-content">
            <div style="margin-bottom: 20px; padding: 20px; background: rgba(0,0,0,0.4); border-radius: 12px; border: 1px solid var(--border);">
                <input type="text" id="search-blocked" placeholder="Nach Datum oder Grund suchen..."
                       style="width: 100%; padding: 10px; background: var(--black); border: 1px solid var(--border); color: var(--white); border-radius: 6px;"
                       oninput="applyBlockedFilters()">
            </div>

            <div class="list-controls">
                <button class="btn btn-success" onclick="openBlockedModal()">Neu</button>
                <button class="btn btn-primary" onclick="editSelectedBlocked()">Bearbeiten</button>
                <button class="btn btn-danger" onclick="deleteSelectedBlocked()">Löschen</button>
            </div>
            <ul id="blocked-list" class="item-list"></ul>
        </div>

        <!-- Gallery Tab -->
        <div id="gallery-tab" class="tab-content">
            <div style="margin-bottom: 20px; padding: 20px; background: rgba(0,0,0,0.4); border-radius: 12px; border: 1px solid var(--border);">
                <input type="text" id="search-gallery" placeholder="Suchen..."
                       style="width: 100%; padding: 10px; background: var(--black); border: 1px solid var(--border); color: var(--white); border-radius: 6px;"
                       oninput="applyGalleryFilters()">
            </div>

            <div class="list-controls">
                <button class="btn btn-success" onclick="openGalleryModal()">Neu</button>
                <button class="btn btn-primary" onclick="editSelectedGallery()">Bearbeiten</button>
                <button class="btn btn-danger" onclick="deleteSelectedGallery()">Löschen</button>
            </div>
            <ul id="gallery-list" class="item-list"></ul>
        </div>
    </div>

    <!-- Event Modal -->
    <div id="event-modal" class="modal">
        <div class="modal-content">
            <h2 id="event-modal-title">Event bearbeiten</h2>
            <form id="event-form" onsubmit="saveEvent(event)">
                <input type="hidden" id="event-id">
                <input type="hidden" id="event-index">

                <div class="form-group">
                    <label>Titel (Deutsch)</label>
                    <input type="text" id="event-title-de" required>
                </div>

                <div class="form-group">
                    <label>Titel (English)</label>
                    <input type="text" id="event-title-en" required>
                </div>

                <div class="form-group">
                    <label>Beschreibung (Deutsch)</label>
                    <textarea id="event-desc-de"></textarea>
                </div>

                <div class="form-group">
                    <label>Beschreibung (English)</label>
                    <textarea id="event-desc-en"></textarea>
                </div>

                <div class="form-group">
                    <label>Kategorie</label>
                    <select id="event-category" required>
                        <option value="">Bitte wählen...</option>
                        <option value="party">Party</option>
                        <option value="workshop">Workshop</option>
                        <option value="culture">Culture</option>
                        <option value="business">Business</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Datum (YYYY-MM-DD)</label>
                    <input type="date" id="event-date">
                </div>

                <div class="form-group">
                    <label>Zeit (HH:MM)</label>
                    <input type="time" id="event-time">
                </div>

                <div class="form-group">
                    <label>Preis</label>
                    <input type="text" id="event-price">
                </div>

                <div class="form-group">
                    <label>Ticket URL</label>
                    <input type="url" id="event-ticket-url">
                </div>

                <div class="form-group">
                    <label>Bild</label>
                    <input type="file" id="event-image" accept="image/*">
                    <small id="event-current-image"></small>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="closeEventModal()">Abbrechen</button>
                    <button type="submit" class="btn btn-success">Speichern</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Gallery Modal -->
    <div id="gallery-modal" class="modal">
        <div class="modal-content">
            <h2 id="gallery-modal-title">Gallery bearbeiten</h2>
            <form id="gallery-form" onsubmit="saveGallery(event)">
                <input type="hidden" id="gallery-id">
                <input type="hidden" id="gallery-index">

                <div class="form-group">
                    <label>Titel (Deutsch)</label>
                    <input type="text" id="gallery-title-de" required>
                </div>

                <div class="form-group">
                    <label>Titel (English)</label>
                    <input type="text" id="gallery-title-en" required>
                </div>

                <div class="form-group">
                    <label>Kategorie</label>
                    <input type="text" id="gallery-category">
                </div>

                <div class="form-group">
                    <label>Datum (YYYY-MM-DD)</label>
                    <input type="date" id="gallery-date">
                </div>

                <div class="form-group">
                    <label>Event zuordnen</label>
                    <select id="gallery-event-id">
                        <option value="">Kein Event</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Bild</label>
                    <input type="file" id="gallery-image" accept="image/*">
                    <small id="gallery-current-image"></small>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="closeGalleryModal()">Abbrechen</button>
                    <button type="submit" class="btn btn-success">Speichern</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Blocked/Private Veranstaltungen Modal -->
    <div id="blocked-modal" class="modal">
        <div class="modal-content">
            <h2 id="blocked-modal-title">Private Veranstaltung bearbeiten</h2>
            <form id="blocked-form" onsubmit="saveBlocked(event)">
                <input type="hidden" id="blocked-id">
                <input type="hidden" id="blocked-index">

                <div class="form-group">
                    <label>Datum (YYYY-MM-DD)</label>
                    <input type="date" id="blocked-date" required>
                </div>

                <div class="form-group">
                    <label>Start-Zeit (HH:MM)</label>
                    <input type="time" id="blocked-start-time" required>
                </div>

                <div class="form-group">
                    <label>End-Zeit (HH:MM)</label>
                    <input type="time" id="blocked-end-time" required>
                </div>

                <div class="form-group">
                    <label>Grund / Beschreibung</label>
                    <input type="text" id="blocked-reason">
                </div>

                <div class="form-group">
                    <label>Status (optional)</label>
                    <select id="blocked-status">
                        <option value="">Kein Status</option>
                        <option value="reserved">Reserved</option>
                        <option value="confirmed">Confirmed</option>
                    </select>
                </div>

                <div class="form-group">
                    <label>Räume (optional, mehrere mit Strg/Cmd auswählen)</label>
                    <select id="blocked-rooms" multiple style="height: 100px;">
                        <option value="hall">Hall</option>
                        <option value="lab">Lab</option>
                        <option value="outdoor">Outdoor</option>
                        <option value="barclub">Bar/Club</option>
                    </select>
                    <small style="color: #666;">Keine Auswahl = alle Räume blockiert</small>
                </div>

                <div class="form-actions">
                    <button type="button" class="btn btn-danger" onclick="closeBlockedModal()">Abbrechen</button>
                    <button type="submit" class="btn btn-success">Speichern</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirm Modal (für Drag & Drop etc.) -->
    <div class="modal" id="confirm-modal" style="display: none;">
        <div class="modal-content" style="max-width: 500px;">
            <h2 id="confirm-title" style="margin-bottom: 20px; color: var(--orange);">Bestätigung</h2>
            <p id="confirm-message" style="margin-bottom: 30px; line-height: 1.6;"></p>
            <div style="display: flex; gap: 12px; justify-content: flex-end;">
                <button class="btn btn-secondary" id="confirm-cancel-btn">Abbrechen</button>
                <button class="btn btn-primary" id="confirm-ok-btn">Bestätigen</button>
            </div>
        </div>
    </div>

    <script>
        let eventsData = [];
        let galleryData = [];
        let selectedEventIndex = null;
        let selectedGalleryIndex = null;
        let selectedBlockedIndex = null;
        let selectedEventsIndices = [];
        let selectedGalleryIndices = [];
        let currentCalendarDate = new Date();
        let calendarFilterFuture = true;

        // Confirm Modal (für Drag & Drop)
        let confirmResolve = null;

        function showConfirm(title, message) {
            return new Promise((resolve) => {
                confirmResolve = resolve;
                document.getElementById('confirm-title').textContent = title;
                document.getElementById('confirm-message').textContent = message;
                document.getElementById('confirm-modal').style.display = 'flex';
                
                // Event Listeners für Buttons
                const okBtn = document.getElementById('confirm-ok-btn');
                const cancelBtn = document.getElementById('confirm-cancel-btn');
                
                const handleOk = () => {
                    document.getElementById('confirm-modal').style.display = 'none';
                    if (confirmResolve) {
                        confirmResolve(true);
                        confirmResolve = null;
                    }
                    okBtn.removeEventListener('click', handleOk);
                    cancelBtn.removeEventListener('click', handleCancel);
                };
                
                const handleCancel = () => {
                    document.getElementById('confirm-modal').style.display = 'none';
                    if (confirmResolve) {
                        confirmResolve(false);
                        confirmResolve = null;
                    }
                    okBtn.removeEventListener('click', handleOk);
                    cancelBtn.removeEventListener('click', handleCancel);
                };
                
                okBtn.addEventListener('click', handleOk);
                cancelBtn.addEventListener('click', handleCancel);
            });
        }

        // Undo/Redo
        let undoStack = [];
        let redoStack = [];
        const MAX_UNDO = 20;

        // Suche & Filter
        let searchTerm = '';
        let currentCategory = 'all';
        let currentSort = 'date';  // 'date', 'title', 'category'

        // Letzte Änderungen
        let recentChanges = [];

        // Tab Switching
        function switchTab(tabName) {
            document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));

            event.target.classList.add('active');
            document.getElementById(tabName + '-tab').classList.add('active');

            // Dashboard laden beim Öffnen
            if (tabName === 'dashboard') {
                loadDashboardStats();
            }
            // Kalender rendern beim Öffnen
            if (tabName === 'calendar') {
                renderEditorCalendar();
            }
        }

        // Alerts
        function showAlert(message, type = 'success') {
            const alert = document.getElementById('alert');
            alert.className = `alert alert-${type}`;
            alert.textContent = message;
            setTimeout(() => alert.textContent = '', 5000);
        }

        // Projektpfad setzen
        async function setProjectPath() {
            const path = document.getElementById('projectPath').value;
            if (!path) {
                showAlert('Bitte einen Pfad eingeben', 'error');
                return;
            }

            const response = await fetch('/set_project_path', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({path})
            });

            const result = await response.json();
            if (result.success) {
                showAlert('Projektpfad gesetzt und Daten geladen');
                await loadData();
            } else {
                showAlert(result.error || 'Fehler beim Setzen des Pfads', 'error');
            }
        }

        // Daten laden
        async function loadData() {
            const response = await fetch('/get_data');
            const data = await response.json();

            if (data.success) {
                eventsData = data.events;
                galleryData = data.gallery;
                renderEventsList();
                renderBlockedList();
                renderGalleryList();
                populateEventDropdown();

                // Dashboard aktualisieren wenn aktiv
                if (document.getElementById('dashboard-tab').classList.contains('active')) {
                    loadDashboardStats();
                }

                // Kalender aktualisieren wenn aktiv
                if (document.getElementById('calendar-tab').classList.contains('active')) {
                    renderEditorCalendar();
                }
            }
        }

        // Events Liste rendern
        function renderEventsList() {
            applyEventsFilters();  // Nutzt jetzt Filter-System
        }

        function selectEvent(index) {
            selectedEventIndex = index;
            applyEventsFilters();
        }

        // Blocked/Private Veranstaltungen Liste rendern
        function renderBlockedList() {
            applyBlockedFilters();  // Nutzt jetzt Filter-System
        }

        function selectBlocked(index) {
            selectedBlockedIndex = index;
            applyBlockedFilters();
        }

        // Gallery Liste rendern
        function renderGalleryList() {
            applyGalleryFilters();  // Nutzt jetzt Filter-System
        }

        function selectGallery(index) {
            selectedGalleryIndex = index;
            applyGalleryFilters();
        }

        // Event Dropdown für Gallery
        function populateEventDropdown() {
            const select = document.getElementById('gallery-event-id');
            select.innerHTML = '<option value="">Kein Event</option>';

            eventsData.forEach(event => {
                if (event.type === 'event') {
                    const option = document.createElement('option');
                    option.value = event.id;
                    option.textContent = `${event.title.de} (${event.date})`;
                    select.appendChild(option);
                }
            });
        }

        // Event Modal
        function openEventModal(index = null) {
            const modal = document.getElementById('event-modal');
            const form = document.getElementById('event-form');
            form.reset();

            if (index !== null) {
                const event = eventsData[index];
                document.getElementById('event-modal-title').textContent = 'Event bearbeiten';
                document.getElementById('event-id').value = event.id;
                document.getElementById('event-index').value = index;
                document.getElementById('event-title-de').value = event.title.de;
                document.getElementById('event-title-en').value = event.title.en;
                document.getElementById('event-desc-de').value = event.description.de;
                document.getElementById('event-desc-en').value = event.description.en;
                document.getElementById('event-category').value = event.category;
                document.getElementById('event-date').value = event.date;
                document.getElementById('event-time').value = event.time;
                document.getElementById('event-price').value = event.price;
                document.getElementById('event-ticket-url').value = event.ticketUrl;
                document.getElementById('event-current-image').textContent = event.image ? `Aktuell: ${event.image}` : '';
            } else {
                document.getElementById('event-modal-title').textContent = 'Neues Event';
                document.getElementById('event-index').value = '';
            }

            modal.classList.add('active');
        }

        function closeEventModal() {
            document.getElementById('event-modal').classList.remove('active');
        }

        function editSelectedEvent() {
            if (selectedEventIndex === null) {
                showAlert('Bitte ein Event auswählen', 'error');
                return;
            }
            openEventModal(selectedEventIndex);
        }

        async function saveEvent(e) {
            e.preventDefault();

            const formData = new FormData();
            const index = document.getElementById('event-index').value;

            formData.append('index', index);
            formData.append('id', document.getElementById('event-id').value);
            formData.append('title_de', document.getElementById('event-title-de').value);
            formData.append('title_en', document.getElementById('event-title-en').value);
            formData.append('desc_de', document.getElementById('event-desc-de').value);
            formData.append('desc_en', document.getElementById('event-desc-en').value);
            formData.append('category', document.getElementById('event-category').value);
            formData.append('date', document.getElementById('event-date').value);
            formData.append('time', document.getElementById('event-time').value);
            formData.append('price', document.getElementById('event-price').value);
            formData.append('ticketUrl', document.getElementById('event-ticket-url').value);

            const imageFile = document.getElementById('event-image').files[0];
            if (imageFile) {
                formData.append('image', imageFile);
            }

            const response = await fetch('/save_event', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            if (result.success) {
                showAlert('Event gespeichert!');
                await loadData();
                closeEventModal();
            } else {
                showAlert(result.error || 'Fehler beim Speichern', 'error');
            }
        }

        function editSelectedEvent() {
            if (selectedEventIndex === null) {
                showAlert('Bitte ein Event auswählen', 'error');
                return;
            }
            openEventModal(selectedEventIndex);
        }

        async function deleteSelectedEvent() {
            if (selectedEventIndex === null) {
                showAlert('Bitte ein Event auswählen', 'error');
                return;
            }

            showDeleteConfirmation('Event wirklich löschen?', async () => {
                const response = await fetch('/delete_event', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({index: selectedEventIndex})
                });

                const result = await response.json();
                if (result.success) {
                    showAlert('Event gelöscht!');
                    selectedEventIndex = null;
                    await loadData();
                } else {
                    showAlert(result.error || 'Fehler beim Löschen', 'error');
                }
            });
        }

        // Blocked/Private Veranstaltungen Modal
        function openBlockedModal(index = null) {
            const modal = document.getElementById('blocked-modal');
            const form = document.getElementById('blocked-form');
            form.reset();

            if (index !== null) {
                const blocked = eventsData[index];
                document.getElementById('blocked-modal-title').textContent = 'Private Veranstaltung bearbeiten';
                document.getElementById('blocked-id').value = blocked.id;
                document.getElementById('blocked-index').value = index;
                document.getElementById('blocked-date').value = blocked.date;
                document.getElementById('blocked-start-time').value = blocked.startTime;
                document.getElementById('blocked-end-time').value = blocked.endTime;
                document.getElementById('blocked-reason').value = blocked.reason || '';
                document.getElementById('blocked-status').value = blocked.status || '';

                // Räume auswählen falls vorhanden
                if (blocked.room && Array.isArray(blocked.room)) {
                    const roomSelect = document.getElementById('blocked-rooms');
                    for (let option of roomSelect.options) {
                        option.selected = blocked.room.includes(option.value);
                    }
                }
            } else {
                document.getElementById('blocked-modal-title').textContent = 'Neue Private Veranstaltung';
                document.getElementById('blocked-index').value = '';
                // Standardwerte setzen
                document.getElementById('blocked-start-time').value = '00:00';
                document.getElementById('blocked-end-time').value = '23:59';
            }

            modal.classList.add('active');
        }

        function closeBlockedModal() {
            document.getElementById('blocked-modal').classList.remove('active');
        }

        function editSelectedBlocked() {
            if (selectedBlockedIndex === null) {
                showAlert('Bitte eine Veranstaltung auswählen', 'error');
                return;
            }
            openBlockedModal(selectedBlockedIndex);
        }

        async function saveBlocked(e) {
            e.preventDefault();

            const formData = new FormData();
            const index = document.getElementById('blocked-index').value;

            formData.append('index', index);
            formData.append('id', document.getElementById('blocked-id').value);
            formData.append('date', document.getElementById('blocked-date').value);
            formData.append('startTime', document.getElementById('blocked-start-time').value);
            formData.append('endTime', document.getElementById('blocked-end-time').value);
            formData.append('reason', document.getElementById('blocked-reason').value);
            formData.append('status', document.getElementById('blocked-status').value);

            // Räume sammeln
            const roomSelect = document.getElementById('blocked-rooms');
            const selectedRooms = Array.from(roomSelect.selectedOptions).map(opt => opt.value);
            formData.append('rooms', JSON.stringify(selectedRooms));

            const response = await fetch('/save_blocked', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            if (result.success) {
                showAlert('Private Veranstaltung gespeichert!');
                await loadData();
                closeBlockedModal();
            } else {
                showAlert(result.error || 'Fehler beim Speichern', 'error');
            }
        }

        async function deleteSelectedBlocked() {
            if (selectedBlockedIndex === null) {
                showAlert('Bitte eine Veranstaltung auswählen', 'error');
                return;
            }

            showDeleteConfirmation('Private Veranstaltung wirklich löschen?', async () => {
                const response = await fetch('/delete_blocked', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({index: selectedBlockedIndex})
                });

                const result = await response.json();
                if (result.success) {
                    showAlert('Private Veranstaltung gelöscht!');
                    selectedBlockedIndex = null;
                    await loadData();
                } else {
                    showAlert(result.error || 'Fehler beim Löschen', 'error');
                }
            });
        }

        // Gallery Modal
        function openGalleryModal(index = null) {
            const modal = document.getElementById('gallery-modal');
            const form = document.getElementById('gallery-form');
            form.reset();

            if (index !== null) {
                const item = galleryData[index];
                document.getElementById('gallery-modal-title').textContent = 'Gallery bearbeiten';
                document.getElementById('gallery-id').value = item.id;
                document.getElementById('gallery-index').value = index;
                document.getElementById('gallery-title-de').value = item.title.de;
                document.getElementById('gallery-title-en').value = item.title.en;
                document.getElementById('gallery-category').value = item.category;
                document.getElementById('gallery-date').value = item.date;
                document.getElementById('gallery-event-id').value = item.eventId || '';
                document.getElementById('gallery-current-image').textContent = item.image ? `Aktuell: ${item.image}` : '';
            } else {
                document.getElementById('gallery-modal-title').textContent = 'Neue Gallery';
                document.getElementById('gallery-index').value = '';
            }

            modal.classList.add('active');
        }

        function closeGalleryModal() {
            document.getElementById('gallery-modal').classList.remove('active');
        }

        function editSelectedGallery() {
            if (selectedGalleryIndex === null) {
                showAlert('Bitte einen Eintrag auswählen', 'error');
                return;
            }
            openGalleryModal(selectedGalleryIndex);
        }

        async function saveGallery(e) {
            e.preventDefault();

            const formData = new FormData();
            const index = document.getElementById('gallery-index').value;

            formData.append('index', index);
            formData.append('id', document.getElementById('gallery-id').value);
            formData.append('title_de', document.getElementById('gallery-title-de').value);
            formData.append('title_en', document.getElementById('gallery-title-en').value);
            formData.append('category', document.getElementById('gallery-category').value);
            formData.append('date', document.getElementById('gallery-date').value);
            formData.append('eventId', document.getElementById('gallery-event-id').value);

            const imageFile = document.getElementById('gallery-image').files[0];
            if (imageFile) {
                formData.append('image', imageFile);
            }

            const response = await fetch('/save_gallery', {
                method: 'POST',
                body: formData
            });

            const result = await response.json();
            if (result.success) {
                showAlert('Gallery-Eintrag gespeichert!');
                await loadData();
                closeGalleryModal();
            } else {
                showAlert(result.error || 'Fehler beim Speichern', 'error');
            }
        }

        async function deleteSelectedGallery() {
            if (selectedGalleryIndex === null) {
                showAlert('Bitte einen Eintrag auswählen', 'error');
                return;
            }

            showDeleteConfirmation('Gallery-Eintrag wirklich löschen?', async () => {
                const response = await fetch('/delete_gallery', {
                    method: 'POST',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify({index: selectedGalleryIndex})
                });

                const result = await response.json();
                if (result.success) {
                    showAlert('Gallery-Eintrag gelöscht!');
                    selectedGalleryIndex = null;
                    await loadData();
                } else {
                    showAlert(result.error || 'Fehler beim Löschen', 'error');
                }
            });
        }

        // ========================================
        // NEUE FUNKTIONEN: Dashboard, Kalender, Mehrfachauswahl
        // ========================================

        // Dashboard Statistiken laden
        async function loadDashboardStats() {
            const response = await fetch('/get_dashboard_stats');
            const data = await response.json();

            if (data.success) {
                const stats = data.stats;
                document.getElementById('stats-total-events').textContent = stats.total_events;
                document.getElementById('stats-upcoming').textContent = stats.upcoming_events;
                document.getElementById('stats-gallery').textContent = stats.gallery_items;
                document.getElementById('stats-blocked').textContent = stats.blocked_dates;

                // Kategorien
                let catHtml = '';
                const catColors = {
                    'party': 'var(--orange)',
                    'business': 'var(--red)',
                    'culture': 'var(--blue)',
                    'workshop': 'var(--green)'
                };
                for (const [cat, count] of Object.entries(stats.categories)) {
                    const color = catColors[cat] || 'var(--white)';
                    catHtml += `
                        <div style="display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid var(--border);">
                            <span style="text-transform: capitalize;">${cat}</span>
                            <span style="color: ${color}; font-weight: 500;">${count}</span>
                        </div>
                    `;
                }
                document.getElementById('category-stats').innerHTML = catHtml || '<p style="color: rgba(255,255,255,0.5);">Keine Events vorhanden</p>';

                // Nächste Events
                let nextHtml = '';
                stats.next_events.forEach(event => {
                    nextHtml += `
                        <div style="padding: 10px 0; border-bottom: 1px solid var(--border);">
                            <div style="font-weight: 500;">${event.title}</div>
                            <div style="font-size: 0.9rem; color: rgba(255,255,255,0.7);">📅 ${event.date}</div>
                        </div>
                    `;
                });
                document.getElementById('next-events-list').innerHTML = nextHtml || '<p style="color: rgba(255,255,255,0.5);">Keine kommenden Events</p>';
            }
        }

        // Backup herunterladen
        function downloadBackup() {
            window.location.href = '/create_backup';
            showAlert('Backup wird erstellt und heruntergeladen...');
        }

        // Kalender rendern (Editor)
        function renderEditorCalendar() {
            const year = currentCalendarDate.getFullYear();
            const month = currentCalendarDate.getMonth();
            const today = new Date();

            const monthNames = ['Januar', 'Februar', 'März', 'April', 'Mai', 'Juni', 'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember'];
            document.getElementById('calendarEditorTitle').textContent = `${monthNames[month]} ${year}`;

            const firstDay = new Date(year, month, 1).getDay();
            const daysInMonth = new Date(year, month + 1, 0).getDate();
            const startDay = firstDay === 0 ? 6 : firstDay - 1;

            let html = ['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'].map(d =>
                `<div style="background: rgba(252,171,20,0.2); padding: 12px; text-align: center; font-weight: 500;">${d}</div>`
            ).join('');

            // Vorheriger Monat
            const prevMonthDays = new Date(year, month, 0).getDate();
            for (let i = startDay; i > 0; i--) {
                html += `<div style="background: var(--black); min-height: 100px; padding: 8px; opacity: 0.3;">
                    <div>${prevMonthDays - i + 1}</div>
                </div>`;
            }

            // Aktueller Monat
            for (let day = 1; day <= daysInMonth; day++) {
                const dateStr = `${year}-${String(month + 1).padStart(2, '0')}-${String(day).padStart(2, '0')}`;
                const cellDate = new Date(year, month, day);
                const isToday = today.getFullYear() === year && today.getMonth() === month && today.getDate() === day;

                // Filter: Nur zukünftige Events
                const filterFuture = document.getElementById('filter-future')?.checked || false;

                let dayEvents = eventsData.filter(e => e.type === 'event' && e.date === dateStr);
                const dayBlocked = eventsData.filter(e => e.type === 'blocked' && e.date === dateStr);

                // Zeitfilter anwenden
                if (filterFuture && cellDate < today) {
                    dayEvents = [];
                }

                let classes = 'background: var(--black); min-height: 100px; padding: 8px; border: 1px solid var(--border); cursor: pointer;';
                if (isToday) classes += ' border: 2px solid var(--green);';

                html += `<div style="${classes}"
                              ondrop="dropEvent(event, '${dateStr}')"
                              ondragover="allowDrop(event)"
                              data-date="${dateStr}">
                    <div style="font-size: 0.9rem; margin-bottom: 5px;">${day}</div>
                    ${dayEvents.map(e => `
                        <div style="background: var(--blue); padding: 4px; font-size: 0.7rem; margin-bottom: 3px; border-radius: 3px; cursor: move;"
                             draggable="true"
                             ondragstart="dragEvent(event, ${e.id})"
                             onclick="jumpToEvent(${e.id}, 'events')"
                             title="${e.title.de}">
                            ${e.title.de}
                        </div>
                    `).join('')}
                    ${dayBlocked.map(b => `
                        <div style="background: rgba(205,17,81,0.3); padding: 4px; font-size: 0.65rem; border-radius: 3px;">
                            ${b.reason || 'Geblockt'}
                        </div>
                    `).join('')}
                </div>`;
            }

            // Nächster Monat
            const totalCells = Math.ceil((startDay + daysInMonth) / 7) * 7;
            const nextMonthDays = totalCells - (startDay + daysInMonth);
            for (let i = 1; i <= nextMonthDays; i++) {
                html += `<div style="background: var(--black); min-height: 100px; padding: 8px; opacity: 0.3;">
                    <div>${i}</div>
                </div>`;
            }

            document.getElementById('calendarEditorGrid').innerHTML = html;
        }

        // Drag & Drop im Kalender
        let draggedEventId = null;

        function dragEvent(event, eventId) {
            draggedEventId = eventId;
            event.dataTransfer.effectAllowed = 'move';
        }

        function allowDrop(event) {
            event.preventDefault();
            event.dataTransfer.dropEffect = 'move';
        }

        async function dropEvent(event, newDate) {
            event.preventDefault();
            if (!draggedEventId) return;

            // Finde Event und aktualisiere Datum
            const eventIndex = eventsData.findIndex(e => e.id === draggedEventId);
            if (eventIndex === -1) return;

            const oldDate = eventsData[eventIndex].date;
            if (oldDate === newDate) {
                draggedEventId = null;
                return;
            }

            // Schöner Confirm-Dialog
            const eventTitle = eventsData[eventIndex].title.de;
            const confirmed = await showConfirm(
                'Event verschieben?',
                `Möchten Sie "${eventTitle}" von ${oldDate} nach ${newDate} verschieben?`
            );

            if (!confirmed) {
                draggedEventId = null;
                return;
            }

            // Datum aktualisieren
            eventsData[eventIndex].date = newDate;

            // Speichern
            const response = await fetch('/save_event', {
                method: 'POST',
                body: new URLSearchParams({
                    index: eventIndex,
                    id: eventsData[eventIndex].id,
                    title_de: eventsData[eventIndex].title.de,
                    title_en: eventsData[eventIndex].title.en,
                    description_de: eventsData[eventIndex].description.de,
                    description_en: eventsData[eventIndex].description.en,
                    date: newDate,
                    time: eventsData[eventIndex].time,
                    category: eventsData[eventIndex].category,
                    price: eventsData[eventIndex].price,
                    ticketUrl: eventsData[eventIndex].ticketUrl
                })
            });

            const result = await response.json();
            if (result.success) {
                showAlert(`Event nach ${newDate} verschoben!`);
                await loadData();
            } else {
                showAlert('Fehler beim Verschieben', 'error');
            }

            draggedEventId = null;
        }

        function changeCalendarMonth(delta) {
            currentCalendarDate.setMonth(currentCalendarDate.getMonth() + delta);
            renderEditorCalendar();
        }

        function goToToday() {
            currentCalendarDate = new Date();
            renderEditorCalendar();
        }

        function filterCalendarEvents() {
            renderEditorCalendar();
        }

        function jumpToEvent(eventId, tabName) {
            switchTab(tabName);
            setTimeout(() => {
                const index = eventsData.findIndex(e => e.id === eventId);
                if (index !== -1) {
                    selectedEventIndex = index;
                    renderEventsList();
                    document.getElementById('events-list').children[index]?.scrollIntoView({behavior: 'smooth', block: 'center'});
                }
            }, 100);
        }

        // Mehrfachauswahl
        function toggleSelectAll(type) {
            const checkbox = document.getElementById(`select-all-${type}`);
            const checkboxes = document.querySelectorAll(`#${type}-list input[type="checkbox"]`);

            // Alle Checkboxen setzen UND Change-Event triggern
            checkboxes.forEach(cb => {
                cb.checked = checkbox.checked;
                // Trigger change event to update arrays
                cb.dispatchEvent(new Event('change'));
            });
        }

        function updateSelectedIndices(type) {
            if (type === 'events') {
                selectedEventsIndices = [];
                document.querySelectorAll('#events-list input[type="checkbox"]:checked').forEach(cb => {
                    selectedEventsIndices.push(parseInt(cb.dataset.index));
                });
            } else if (type === 'gallery') {
                selectedGalleryIndices = [];
                document.querySelectorAll('#gallery-list input[type="checkbox"]:checked').forEach(cb => {
                    selectedGalleryIndices.push(parseInt(cb.dataset.index));
                });
            }
        }
        
        document.addEventListener('change', function (e) {
    if (e.target.matches('#events-list input[type="checkbox"]')) {
        updateSelectedIndices('events');
    }
    if (e.target.matches('#gallery-list input[type="checkbox"]')) {
        updateSelectedIndices('gallery');
    }
});


        async function deleteSelected(type) {
            const indices = type === 'events' ? selectedEventsIndices : selectedGalleryIndices;

            if (indices.length === 0) {
                showAlert('Bitte mindestens einen Eintrag auswählen', 'error');
                return;
            }

            const confirmed = await showConfirm(
                'Einträge löschen?',
                `Möchten Sie wirklich ${indices.length} ${indices.length === 1 ? 'Eintrag' : 'Einträge'} löschen? Diese Aktion kann nicht rückgängig gemacht werden.`
            );

            if (!confirmed) return;

            const response = await fetch('/delete_multiple', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({indices, type})
            });

            const result = await response.json();
            if (result.success) {
                showAlert(`${result.deleted} Einträge gelöscht!`);
                if (type === 'events') selectedEventsIndices = [];
                else selectedGalleryIndices = [];
                await loadData();
            } else {
                showAlert(result.error || 'Fehler beim Löschen', 'error');
            }
        }

        async function duplicateSelected(type) {
            if (type !== 'events') return;

            // Nutze erstes ausgewähltes Event
            if (selectedEventsIndices.length === 0) {
                showAlert('Bitte mindestens ein Event auswählen', 'error');
                return;
            }

            const indexToDuplicate = selectedEventsIndices[0];

            const response = await fetch('/duplicate_event', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({index: indexToDuplicate})
            });

            const result = await response.json();
            if (result.success) {
                showAlert('Event dupliziert!');
                await loadData();
            } else {
                showAlert(result.error || 'Fehler beim Duplizieren', 'error');
            }
        }

        // ========================================
        // UNDO/REDO SYSTEM
        // ========================================

        function saveState(action) {
            const state = {
                events: JSON.parse(JSON.stringify(eventsData)),
                gallery: JSON.parse(JSON.stringify(galleryData)),
                timestamp: new Date().toISOString(),
                action: action
            };

            undoStack.push(state);
            if (undoStack.length > MAX_UNDO) {
                undoStack.shift();
            }
            redoStack = [];  // Clear redo stack on new action

            // Letzte Änderungen tracken
            addRecentChange(action);
        }

        async function undo() {
            if (undoStack.length === 0) {
                showAlert('Nichts zum Rückgängigmachen', 'error');
                return;
            }

            const currentState = {
                events: JSON.parse(JSON.stringify(eventsData)),
                gallery: JSON.parse(JSON.stringify(galleryData))
            };
            redoStack.push(currentState);

            const previousState = undoStack.pop();
            eventsData = previousState.events;
            galleryData = previousState.gallery;

            // Save to server
            await saveAllData();
            renderEventsList();
            renderBlockedList();
            renderGalleryList();

            showAlert('Rückgängig: ' + previousState.action);
        }

        async function redo() {
            if (redoStack.length === 0) {
                showAlert('Nichts zum Wiederholen', 'error');
                return;
            }

            const currentState = {
                events: JSON.parse(JSON.stringify(eventsData)),
                gallery: JSON.parse(JSON.stringify(galleryData)),
                action: 'Redo'
            };
            undoStack.push(currentState);

            const nextState = redoStack.pop();
            eventsData = nextState.events;
            galleryData = nextState.gallery;

            // Save to server
            await saveAllData();
            renderEventsList();
            renderBlockedList();
            renderGalleryList();

            showAlert('Wiederhergestellt');
        }

        async function saveAllData() {
            // Events speichern
            const eventsPath = document.getElementById('projectPath').value + '/events.json';
            await fetch('/save_event', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({events: eventsData})
            });

            // Gallery speichern
            await fetch('/save_gallery', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({gallery: galleryData})
            });
        }

        // Letzte Änderungen
        function addRecentChange(action) {
            const change = {
                action: action,
                timestamp: new Date().toLocaleString('de-DE'),
                time: Date.now()
            };

            recentChanges.unshift(change);
            if (recentChanges.length > 10) {
                recentChanges = recentChanges.slice(0, 10);
            }

            updateRecentChangesDisplay();
        }

        function updateRecentChangesDisplay() {
            const container = document.getElementById('recent-changes-list');
            if (!container) return;

            if (recentChanges.length === 0) {
                container.innerHTML = '<p style="color: rgba(255,255,255,0.4);">Keine Änderungen</p>';
                return;
            }

            container.innerHTML = recentChanges.map(change => `
                <div style="padding: 5px 0; border-bottom: 1px solid rgba(255,255,255,0.1);">
                    <span style="color: var(--orange);">${change.action}</span>
                    <span style="float: right; font-size: 0.8rem;">${change.timestamp}</span>
                </div>
            `).join('');
        }

        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            if (e.ctrlKey || e.metaKey) {
                if (e.key === 'z' && !e.shiftKey) {
                    e.preventDefault();
                    undo();
                } else if (e.key === 'y' || (e.key === 'z' && e.shiftKey)) {
                    e.preventDefault();
                    redo();
                }
            }
        });

        // ========================================
        // SUCHE, FILTER & SORTIERUNG
        // ========================================

        function applyEventsFilters() {
            const search = document.getElementById('search-events').value.toLowerCase();

            const filtered = eventsData.filter(item => {
                if (item.type !== 'event') return false;

                return search === '' ||
                    item.title.de.toLowerCase().includes(search) ||
                    item.title.en.toLowerCase().includes(search) ||
                    item.description.de.toLowerCase().includes(search) ||
                    item.description.en.toLowerCase().includes(search) ||
                    item.date.includes(search);
            });

            const list = document.getElementById('events-list');
            list.innerHTML = '';

            filtered.forEach((item) => {
                const index = eventsData.indexOf(item);
                const li = document.createElement('li');
                li.textContent = `${item.id}: ${item.title.de} (${item.date})`;
                li.onclick = () => selectEvent(index);
                if (index === selectedEventIndex) li.classList.add('selected');
                list.appendChild(li);
            });
        }

        function applyGalleryFilters() {
            const search = document.getElementById('search-gallery').value.toLowerCase();

            const filtered = galleryData.filter(item => {
                return search === '' ||
                    item.title.de.toLowerCase().includes(search) ||
                    item.title.en.toLowerCase().includes(search) ||
                    item.date.includes(search) ||
                    item.category.toLowerCase().includes(search);
            });

            const list = document.getElementById('gallery-list');
            list.innerHTML = '';

            filtered.forEach((item) => {
                const index = galleryData.indexOf(item);
                const li = document.createElement('li');
                li.textContent = `${item.id}: ${item.title.de} (${item.date}) - ${item.category}`;
                li.onclick = () => selectGallery(index);
                if (index === selectedGalleryIndex) li.classList.add('selected');
                list.appendChild(li);
            });
        }

        function applyBlockedFilters() {
            const search = document.getElementById('search-blocked').value.toLowerCase();

            const filtered = eventsData.filter(item => {
                if (item.type !== 'blocked') return false;

                const reason = item.reason || 'Private Veranstaltung';
                return search === '' ||
                    item.date.includes(search) ||
                    reason.toLowerCase().includes(search);
            });

            const list = document.getElementById('blocked-list');
            list.innerHTML = '';

            filtered.forEach((item) => {
                const index = eventsData.indexOf(item);
                const li = document.createElement('li');
                const reason = item.reason || 'Private Veranstaltung';
                li.textContent = `${item.id}: ${item.date} (${item.startTime}-${item.endTime}) - ${reason}`;
                li.onclick = () => selectBlocked(index);
                if (index === selectedBlockedIndex) li.classList.add('selected');
                list.appendChild(li);
            });
        }


        // Custom Delete Confirmation Dialog
        let deleteConfirmCallback = null;

        function showDeleteConfirmation(message, onConfirm) {
            document.getElementById('confirmMessage').textContent = message;
            document.getElementById('confirmModal').classList.add('active');
            deleteConfirmCallback = onConfirm;
        }

        function closeDeleteConfirmModal() {
            document.getElementById('confirmModal').classList.remove('active');
            deleteConfirmCallback = null;
        }

        function handleDeleteConfirmKeyPress(e) {
            if (e.key === 'Enter' && document.getElementById('confirmModal').classList.contains('active')) {
                e.preventDefault();
                executeDeleteConfirm();
            } else if (e.key === 'Escape' && document.getElementById('confirmModal').classList.contains('active')) {
                e.preventDefault();
                closeDeleteConfirmModal();
            }
        }

        function executeDeleteConfirm() {
            if (deleteConfirmCallback) {
                deleteConfirmCallback();
            }
            closeDeleteConfirmModal();
        }

        // Event Listener für Bestätigen-Button - wird nach dem DOM-Load gesetzt
        window.addEventListener('DOMContentLoaded', function() {
            document.getElementById('confirmButton').addEventListener('click', executeDeleteConfirm);
            document.addEventListener('keydown', handleDeleteConfirmKeyPress);
        });


        // Initial load
        loadData();
        loadDashboardStats();  // Dashboard beim Start laden
    </script>

    <!-- Vorschau Modal -->
    <div class="preview-modal" id="previewModal">
        <div class="preview-container">
            <div class="preview-header">
                <h2>Webseiten-Vorschau</h2>
                <div class="preview-tabs">
                    <button class="preview-tab active" onclick="switchPreview('events')">Events Ansicht</button>
                    <button class="preview-tab" onclick="switchPreview('gallery')">Gallery Ansicht</button>
                </div>
                <button class="preview-close" onclick="closePreview()">✕ Schließen</button>
            </div>
            <iframe id="previewIframe" class="preview-iframe"></iframe>
        </div>
    </div>

    <script>
        function openPreview() {
            document.getElementById('previewModal').classList.add('active');
            document.body.style.overflow = 'hidden';
            switchPreview('events');
        }

        function closePreview() {
            document.getElementById('previewModal').classList.remove('active');
            document.body.style.overflow = '';
            document.getElementById('previewIframe').src = 'about:blank';
        }

        function switchPreview(type) {
            document.querySelectorAll('.preview-tab').forEach(t => t.classList.remove('active'));
            event.target.classList.add('active');

            const iframe = document.getElementById('previewIframe');
            iframe.src = `/preview/${type}`;
        }

        // ESC zum Schließen
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && document.getElementById('previewModal').classList.contains('active')) {
                closePreview();
            }
        });
    </script>
    <!-- Custom Confirmation Modal -->
    <div id="confirmModal" class="modal">
        <div class="modal-content" style="max-width: 500px;">
            <h2 id="confirmTitle">Bestätigung</h2>
            <p id="confirmMessage" style="margin: 20px 0; font-size: 1.1rem;"></p>
            <div style="display: flex; gap: 15px; justify-content: flex-end; margin-top: 30px;">
                <button class="btn btn-secondary" onclick="closeConfirmModal()">Abbrechen</button>
                <button class="btn btn-danger" id="confirmButton">Löschen</button>
            </div>
        </div>
    </div>

</body>
</html>
"""


# --------------------------------------
# Flask Routes
# --------------------------------------
@app.route('/')
def index():
    return render_template_string(HTML_TEMPLATE, project_path=PROJECT_PATH)


@app.route('/set_project_path', methods=['POST'])
def set_project_path():
    global PROJECT_PATH
    data = request.json
    path = data.get('path', '')

    if not path or not os.path.isdir(path):
        return jsonify({'success': False, 'error': 'Ungültiger Pfad'})

    PROJECT_PATH = path
    return jsonify({'success': True})


@app.route('/get_data')
def get_data():
    if not PROJECT_PATH:
        return jsonify({'success': False, 'error': 'Kein Projektpfad gesetzt'})

    events_path = os.path.join(PROJECT_PATH, "events.json")
    gallery_path = os.path.join(PROJECT_PATH, "gallery.json")

    events = load_json(events_path)
    gallery = load_json(gallery_path)

    return jsonify({
        'success': True,
        'events': events,
        'gallery': gallery
    })


@app.route('/save_event', methods=['POST'])
def save_event():
    if not PROJECT_PATH:
        return jsonify({'success': False, 'error': 'Kein Projektpfad gesetzt'})

    try:
        index = request.form.get('index')
        event_data = {
            'id': int(request.form.get('id')) if request.form.get('id') else None,
            'type': 'event',
            'title': {
                'de': request.form.get('title_de', ''),
                'en': request.form.get('title_en', '')
            },
            'description': {
                'de': request.form.get('desc_de', ''),
                'en': request.form.get('desc_en', '')
            },
            'category': request.form.get('category', ''),
            'date': request.form.get('date', ''),
            'time': request.form.get('time', ''),
            'price': request.form.get('price', ''),
            'ticketUrl': request.form.get('ticketUrl', ''),
            'image': ''
        }

        # Bestehendes Bild übernehmen falls vorhanden
        events_path = os.path.join(PROJECT_PATH, "events.json")
        events = load_json(events_path)
        if index and int(index) < len(events):
            event_data['image'] = events[int(index)].get('image', '')

        # Neues Bild verarbeiten
        if 'image' in request.files:
            file = request.files['image']
            if file.filename:
                dest_folder = os.path.join(PROJECT_PATH, "img")
                rel_path = copy_image(file, dest_folder, PROJECT_PATH)
                if rel_path:
                    event_data['image'] = rel_path

        if index:
            # Bearbeiten
            events[int(index)] = event_data
        else:
            # Neu
            event_data['id'] = max([e.get('id', 0) for e in events] + [0]) + 1
            events.append(event_data)

        save_json(events_path, events)
        return jsonify({'success': True})

    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})


@app.route('/delete_event', methods=['POST'])
def delete_event():
    if not PROJECT_PATH:
        return jsonify({'success': False, 'error': 'Kein Projektpfad gesetzt'})

    try:
        data = request.json
        index = data.get('index')

        events_path = os.path.join(PROJECT_PATH, "events.json")
        events = load_json(events_path)

        events.pop(index)
        save_json(events_path, events)

        return jsonify({'success': True})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})


@app.route('/save_blocked', methods=['POST'])
def save_blocked():
    if not PROJECT_PATH:
        return jsonify({'success': False, 'error': 'Kein Projektpfad gesetzt'})

    try:
        index = request.form.get('index')

        blocked_data = {
            'id': int(request.form.get('id')) if request.form.get('id') else None,
            'type': 'blocked',
            'date': request.form.get('date', ''),
            'startTime': request.form.get('startTime', ''),
            'endTime': request.form.get('endTime', '')
        }

        # Optionale Felder
        reason = request.form.get('reason', '')
        if reason:
            blocked_data['reason'] = reason

        status = request.form.get('status', '')
        if status:
            blocked_data['status'] = status

        # Räume parsen
        rooms_json = request.form.get('rooms', '[]')
        rooms = json.loads(rooms_json)
        if rooms:
            blocked_data['room'] = rooms

        events_path = os.path.join(PROJECT_PATH, "events.json")
        events = load_json(events_path)

        if index:
            # Bearbeiten
            events[int(index)] = blocked_data
        else:
            # Neu
            blocked_data['id'] = max([e.get('id', 0) for e in events] + [0]) + 1
            events.append(blocked_data)

        save_json(events_path, events)
        return jsonify({'success': True})

    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})


@app.route('/delete_blocked', methods=['POST'])
def delete_blocked():
    if not PROJECT_PATH:
        return jsonify({'success': False, 'error': 'Kein Projektpfad gesetzt'})

    try:
        data = request.json
        index = data.get('index')

        events_path = os.path.join(PROJECT_PATH, "events.json")
        events = load_json(events_path)

        events.pop(index)
        save_json(events_path, events)

        return jsonify({'success': True})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})


@app.route('/save_gallery', methods=['POST'])
def save_gallery():
    if not PROJECT_PATH:
        return jsonify({'success': False, 'error': 'Kein Projektpfad gesetzt'})

    try:
        index = request.form.get('index')
        gallery_data = {
            'id': int(request.form.get('id')) if request.form.get('id') else None,
            'title': {
                'de': request.form.get('title_de', ''),
                'en': request.form.get('title_en', '')
            },
            'category': request.form.get('category', ''),
            'date': request.form.get('date', ''),
            'eventId': int(request.form.get('eventId')) if request.form.get('eventId') else None,
            'image': ''
        }

        # Bestehendes Bild übernehmen falls vorhanden
        gallery_path = os.path.join(PROJECT_PATH, "gallery.json")
        gallery = load_json(gallery_path)
        if index and int(index) < len(gallery):
            gallery_data['image'] = gallery[int(index)].get('image', '')

        # Neues Bild verarbeiten
        if 'image' in request.files:
            file = request.files['image']
            if file.filename:
                dest_folder = os.path.join(PROJECT_PATH, "gallery-images")
                rel_path = copy_image(file, dest_folder, PROJECT_PATH)
                if rel_path:
                    gallery_data['image'] = rel_path

        if index:
            # Bearbeiten
            gallery[int(index)] = gallery_data
        else:
            # Neu
            gallery_data['id'] = max([g.get('id', 0) for g in gallery] + [0]) + 1
            gallery.append(gallery_data)

        save_json(gallery_path, gallery)
        return jsonify({'success': True})

    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})


@app.route('/delete_gallery', methods=['POST'])
def delete_gallery():
    if not PROJECT_PATH:
        return jsonify({'success': False, 'error': 'Kein Projektpfad gesetzt'})

    try:
        data = request.json
        index = data.get('index')

        gallery_path = os.path.join(PROJECT_PATH, "gallery.json")
        gallery = load_json(gallery_path)

        gallery.pop(index)
        save_json(gallery_path, gallery)

        return jsonify({'success': True})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})


# --------------------------------------
# Neue Funktionen: Backup, Dashboard, Duplikate, Mehrfachauswahl
# --------------------------------------

@app.route('/create_backup')
def create_backup():
    """Erstellt ZIP-Backup des gesamten Projekts"""
    if not PROJECT_PATH:
        return jsonify({'success': False, 'error': 'Kein Projektpfad gesetzt'})

    try:
        timestamp = datetime.now().strftime('%Y%m%d_%H%M%S')
        memory_file = io.BytesIO()

        with zipfile.ZipFile(memory_file, 'w', zipfile.ZIP_DEFLATED) as zf:
            # JSON Dateien
            for json_file in ['events.json', 'gallery.json']:
                file_path = os.path.join(PROJECT_PATH, json_file)
                if os.path.exists(file_path):
                    zf.write(file_path, json_file)

            # Bilder
            for folder in ['img', 'gallery-images']:
                folder_path = os.path.join(PROJECT_PATH, folder)
                if os.path.exists(folder_path):
                    for root, dirs, files in os.walk(folder_path):
                        for file in files:
                            file_path = os.path.join(root, file)
                            arcname = os.path.relpath(file_path, PROJECT_PATH)
                            zf.write(file_path, arcname)

        memory_file.seek(0)
        return send_file(
            memory_file,
            mimetype='application/zip',
            as_attachment=True,
            download_name=f'area710_backup_{timestamp}.zip'
        )
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})


@app.route('/get_dashboard_stats')
def get_dashboard_stats():
    """Liefert Statistiken für Dashboard"""
    if not PROJECT_PATH:
        return jsonify({'success': False, 'error': 'Kein Projektpfad gesetzt'})

    try:
        events_path = os.path.join(PROJECT_PATH, "events.json")
        gallery_path = os.path.join(PROJECT_PATH, "gallery.json")

        events = load_json(events_path)
        gallery = load_json(gallery_path)

        # Events nach Typ filtern
        public_events = [e for e in events if e.get('type') == 'event']
        blocked_events = [e for e in events if e.get('type') == 'blocked']

        # Kategorien zählen
        categories = {}
        for event in public_events:
            cat = event.get('category', 'unknown')
            categories[cat] = categories.get(cat, 0) + 1

        # Nächstes Event finden
        from datetime import datetime as dt
        today = dt.now().date()
        upcoming = []
        past = []
        for event in public_events:
            try:
                event_date = dt.strptime(event.get('date', ''), '%Y-%m-%d').date()
                event_info = {
                    'id': event.get('id'),
                    'title': event['title']['de'],
                    'date': event['date'],
                    'category': event.get('category', '')
                }
                if event_date >= today:
                    upcoming.append(event_info)
                else:
                    past.append(event_info)
            except:
                pass

        upcoming.sort(key=lambda x: x['date'])
        past.sort(key=lambda x: x['date'], reverse=True)

        return jsonify({
            'success': True,
            'stats': {
                'total_events': len(public_events),
                'upcoming_events': len(upcoming),
                'past_events': len(past),
                'blocked_dates': len(blocked_events),
                'gallery_items': len(gallery),
                'categories': categories,
                'next_events': upcoming[:5],
                'recent_past': past[:3]
            }
        })
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})


@app.route('/duplicate_event', methods=['POST'])
def duplicate_event():
    """Dupliziert ein Event"""
    if not PROJECT_PATH:
        return jsonify({'success': False, 'error': 'Kein Projektpfad gesetzt'})

    try:
        data = request.json
        index = data.get('index')

        events_path = os.path.join(PROJECT_PATH, "events.json")
        events = load_json(events_path)

        if index is None or index >= len(events):
            return jsonify({'success': False, 'error': 'Ungültiger Index'})

        # Event duplizieren
        original = events[index]
        duplicate = json.loads(json.dumps(original))  # Deep copy

        # Neue ID vergeben
        duplicate['id'] = max([e.get('id', 0) for e in events] + [0]) + 1

        # Titel anpassen
        if 'title' in duplicate and isinstance(duplicate['title'], dict):
            duplicate['title']['de'] = duplicate['title']['de'] + ' (Kopie)'
            duplicate['title']['en'] = duplicate['title']['en'] + ' (Copy)'

        events.append(duplicate)
        save_json(events_path, events)

        return jsonify({'success': True})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})


@app.route('/delete_multiple', methods=['POST'])
def delete_multiple():
    """Löscht mehrere Events/Gallery Items"""
    if not PROJECT_PATH:
        return jsonify({'success': False, 'error': 'Kein Projektpfad gesetzt'})

    try:
        data = request.json
        indices = data.get('indices', [])
        data_type = data.get('type', 'events')  # 'events' oder 'gallery'

        if data_type == 'events':
            file_path = os.path.join(PROJECT_PATH, "events.json")
        else:
            file_path = os.path.join(PROJECT_PATH, "gallery.json")

        items = load_json(file_path)

        # Sortiere Indices absteigend, damit das Löschen funktioniert
        for index in sorted(indices, reverse=True):
            if 0 <= index < len(items):
                items.pop(index)

        save_json(file_path, items)
        return jsonify({'success': True, 'deleted': len(indices)})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})


@app.route('/reorder_items', methods=['POST'])
def reorder_items():
    """Ändert die Reihenfolge von Items"""
    if not PROJECT_PATH:
        return jsonify({'success': False, 'error': 'Kein Projektpfad gesetzt'})

    try:
        data = request.json
        new_order = data.get('order', [])  # Liste von IDs in neuer Reihenfolge
        data_type = data.get('type', 'events')

        if data_type == 'events':
            file_path = os.path.join(PROJECT_PATH, "events.json")
        else:
            file_path = os.path.join(PROJECT_PATH, "gallery.json")

        items = load_json(file_path)

        # Neu sortieren basierend auf der ID-Reihenfolge
        reordered = []
        for item_id in new_order:
            for item in items:
                if item.get('id') == item_id:
                    reordered.append(item)
                    break

        # Füge Items hinzu die nicht in der Order-Liste waren
        for item in items:
            if item.get('id') not in new_order:
                reordered.append(item)

        save_json(file_path, reordered)
        return jsonify({'success': True})
    except Exception as e:
        return jsonify({'success': False, 'error': str(e)})


# --------------------------------------
# Vorschau-Routes
# --------------------------------------
@app.route('/preview/<preview_type>')
def preview(preview_type):
    if not PROJECT_PATH:
        return "<h1 style='color: white; text-align: center; padding: 50px; font-family: Roboto, sans-serif; background: #000;'>Bitte zuerst einen Projektpfad setzen</h1>"

    events_path = os.path.join(PROJECT_PATH, "events.json")
    gallery_path = os.path.join(PROJECT_PATH, "gallery.json")

    events = load_json(events_path)
    gallery = load_json(gallery_path)

    if preview_type == 'events':
        return render_template_string(EVENTS_PREVIEW_TEMPLATE, events=json.dumps(events))
    elif preview_type == 'gallery':
        return render_template_string(GALLERY_PREVIEW_TEMPLATE,
                                      gallery=json.dumps(gallery),
                                      events=json.dumps(events))
    else:
        return "Invalid preview type", 404


@app.route('/project-files/<path:filename>')
def serve_project_file(filename):
    """Liefert Bilder und andere Dateien aus dem Projektordner"""
    if not PROJECT_PATH:
        return "No project path set", 404

    try:
        # Sicherheit: Verhindere Directory Traversal
        safe_path = os.path.normpath(os.path.join(PROJECT_PATH, filename))
        if not safe_path.startswith(os.path.normpath(PROJECT_PATH)):
            return "Access denied", 403

        return send_from_directory(PROJECT_PATH, filename)
    except Exception as e:
        print(f"Error serving file {filename}: {e}")
        return "File not found", 404


# Events Vorschau Template (vereinfachte Version der events.html)
EVENTS_PREVIEW_TEMPLATE = """
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Events Vorschau - area710</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@100;300;400;500;700;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --orange: #FCAB14; --red: #CD1151; --blue: #009FE2; --green: #AEC610;
            --black: #000; --white: #fff; --gray: #222; --border: rgba(255, 255, 255, 0.1);
        }
        body {
            font-family: 'Roboto', sans-serif;
            background: var(--black);
            color: var(--white);
            padding: 40px 20px;
        }
        .container { max-width: 1400px; margin: 0 auto; }
        h1 {
            text-align: center;
            font-size: 2.5rem;
            font-weight: 300;
            margin-bottom: 3rem;
            background: linear-gradient(135deg, var(--orange), var(--red), var(--blue), var(--green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .events-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 2rem;
        }
        .event-card {
            background: var(--gray);
            border: 1px solid var(--border);
            border-radius: 12px;
            overflow: hidden;
            transition: all 0.4s ease;
        }
        .event-card:hover {
            transform: translateY(-10px);
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.5);
            border-color: var(--orange);
        }
        .event-image {
            width: 100%;
            height: 250px;
            object-fit: cover;
        }
        .event-content { padding: 2rem; }
        .event-category {
            display: inline-block;
            padding: 0.4rem 1rem;
            font-size: 0.8rem;
            font-weight: 500;
            border-radius: 20px;
            margin-bottom: 1rem;
            text-transform: uppercase;
        }
        .event-category.party { background: rgba(252, 171, 20, 0.2); color: var(--orange); }
        .event-category.business { background: rgba(205, 17, 81, 0.2); color: var(--red); }
        .event-category.culture { background: rgba(0, 159, 226, 0.2); color: var(--blue); }
        .event-category.workshop { background: rgba(174, 198, 16, 0.2); color: var(--green); }
        .event-title {
            font-size: 1.5rem;
            font-weight: 500;
            margin-bottom: 1rem;
        }
        .event-date, .event-time {
            margin-bottom: 0.5rem;
            color: rgba(255, 255, 255, 0.7);
        }
        .event-description {
            margin: 1rem 0;
            line-height: 1.7;
            color: rgba(255, 255, 255, 0.8);
        }
        .event-footer {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding-top: 1rem;
            border-top: 1px solid var(--border);
        }
        .event-price {
            font-size: 1.2rem;
            font-weight: 500;
        }
        .blocked-info {
            background: rgba(205, 17, 81, 0.2);
            border: 1px solid var(--red);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
        }
        .blocked-info h3 {
            color: var(--red);
            margin-bottom: 1rem;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Events Vorschau</h1>
        <div class="events-grid" id="eventsGrid"></div>
    </div>
    <script>
        const eventsData = {{ events|safe }};
        const publicEvents = eventsData.filter(e => e.type === 'event');
        
        publicEvents.forEach(event => {
            const card = document.createElement('div');
            card.className = 'event-card';
            card.innerHTML = `
                <img src="/project-files/${event.image}" alt="${event.title.de}" class="event-image" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22350%22 height=%22250%22%3E%3Crect width=%22350%22 height=%22250%22 fill=%22%23222%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 fill=%22%23fff%22%3EKein Bild%3C/text%3E%3C/svg%3E'">
                <div class="event-content">
                    <span class="event-category ${event.category}">${event.category}</span>
                    <h3 class="event-title">${event.title.de}</h3>
                    <div class="event-date">📅 ${event.date}</div>
                    <div class="event-time">🕒 ${event.time}</div>
                    <p class="event-description">${event.description.de}</p>
                    <div class="event-footer">
                        <span class="event-price">${event.price}</span>
                    </div>
                </div>
            `;
            document.getElementById('eventsGrid').appendChild(card);
        });
        
        if (publicEvents.length === 0) {
            document.getElementById('eventsGrid').innerHTML = '<div class="blocked-info"><h3>Keine Events vorhanden</h3><p>Erstellen Sie Ihr erstes Event im Editor!</p></div>';
        }
    </script>
</body>
</html>
"""

# Gallery Vorschau Template
GALLERY_PREVIEW_TEMPLATE = """
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gallery Vorschau - area710</title>
    <link href="https://fonts.googleapis.com/css2?family=Roboto:wght@100;300;400;500;700;900&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        :root {
            --orange: #FCAB14; --red: #CD1151; --blue: #009FE2; --green: #AEC610;
            --black: #000; --white: #fff; --gray: #222; --border: rgba(255, 255, 255, 0.1);
        }
        body {
            font-family: 'Roboto', sans-serif;
            background: var(--black);
            color: var(--white);
            padding: 40px 20px;
        }
        .container { max-width: 1600px; margin: 0 auto; }
        h1 {
            text-align: center;
            font-size: 2.5rem;
            font-weight: 300;
            margin-bottom: 3rem;
            background: linear-gradient(135deg, var(--orange), var(--red), var(--blue), var(--green));
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }
        .gallery-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 1.5rem;
        }
        .gallery-item {
            position: relative;
            overflow: hidden;
            border-radius: 12px;
            background: var(--gray);
            border: 1px solid var(--border);
            transition: all 0.4s ease;
        }
        .gallery-item:hover {
            transform: translateY(-5px);
            box-shadow: 0 15px 30px rgba(0, 0, 0, 0.5);
        }
        .gallery-img-wrapper {
            width: 100%;
            height: 300px;
            overflow: hidden;
        }
        .gallery-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.6s ease;
        }
        .gallery-item:hover .gallery-img {
            transform: scale(1.1);
        }
        .gallery-overlay {
            position: absolute;
            inset: 0;
            background: linear-gradient(to top, rgba(0, 0, 0, 0.9), transparent);
            display: flex;
            flex-direction: column;
            justify-content: flex-end;
            padding: 1.5rem;
            opacity: 0;
            transition: opacity 0.3s ease;
        }
        .gallery-item:hover .gallery-overlay {
            opacity: 1;
        }
        .gallery-category {
            display: inline-block;
            padding: 0.3rem 0.8rem;
            font-size: 0.75rem;
            font-weight: 500;
            border-radius: 20px;
            margin-bottom: 0.5rem;
            text-transform: uppercase;
            width: fit-content;
        }
        .gallery-category.events { background: rgba(252, 171, 20, 0.3); color: var(--orange); }
        .gallery-category.location { background: rgba(205, 17, 81, 0.3); color: var(--red); }
        .gallery-category.party { background: rgba(0, 159, 226, 0.3); color: var(--blue); }
        .gallery-category.business { background: rgba(174, 198, 16, 0.3); color: var(--green); }
        .gallery-title {
            font-size: 1rem;
            font-weight: 400;
            margin-bottom: 0.3rem;
        }
        .gallery-date {
            font-size: 0.85rem;
            color: rgba(255, 255, 255, 0.7);
        }
        .empty-state {
            text-align: center;
            padding: 5rem 2rem;
            color: rgba(255, 255, 255, 0.5);
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Gallery Vorschau</h1>
        <div class="gallery-grid" id="galleryGrid"></div>
    </div>
    <script>
        const galleryData = {{ gallery|safe }};
        const eventsData = {{ events|safe }};
        
        galleryData.forEach(item => {
            const card = document.createElement('div');
            card.className = 'gallery-item';
            card.innerHTML = `
                <div class="gallery-img-wrapper">
                    <img src="/project-files/${item.image}" alt="${item.title.de}" class="gallery-img" onerror="this.src='data:image/svg+xml,%3Csvg xmlns=%22http://www.w3.org/2000/svg%22 width=%22300%22 height=%22300%22%3E%3Crect width=%22300%22 height=%22300%22 fill=%22%23222%22/%3E%3Ctext x=%2250%25%22 y=%2250%25%22 text-anchor=%22middle%22 fill=%22%23fff%22%3EKein Bild%3C/text%3E%3C/svg%3E'">
                    <div class="gallery-overlay">
                        <span class="gallery-category ${item.category}">${item.category}</span>
                        <div class="gallery-title">${item.title.de}</div>
                        <div class="gallery-date">${item.date}</div>
                    </div>
                </div>
            `;
            document.getElementById('galleryGrid').appendChild(card);
        });
        
        if (galleryData.length === 0) {
            document.getElementById('galleryGrid').innerHTML = '<div class="empty-state"><h3>Keine Gallery-Einträge vorhanden</h3><p>Erstellen Sie Ihren ersten Eintrag im Editor!</p></div>';
        }
    </script>
</body>
</html>
"""


# --------------------------------------
# Browser öffnen
# --------------------------------------
def open_browser():
    webbrowser.open('http://127.0.0.1:5000')


# --------------------------------------
# Start
# --------------------------------------
if __name__ == '__main__':
    print("\n" + "=" * 50)
    print("JSON Editor startet...")
    print("Öffne Browser auf: http://127.0.0.1:5000")
    print("Zum Beenden: Strg+C drücken")
    print("=" * 50 + "\n")

    Timer(1, open_browser).start()
    app.run(debug=False, port=5000)