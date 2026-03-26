// ==================== LANGUAGE SWITCHER ====================
// Reusable language switcher for all pages
// Include this file in all HTML pages with: <script src="language-switcher.js"></script>

const LANGUAGE_PREF_KEY = 'area710_language_preference';
let currentLang = 'de';

// Load saved language preference on page load
function loadLanguagePreference() {
    const savedLang = localStorage.getItem(LANGUAGE_PREF_KEY);
    if (savedLang && (savedLang === 'de' || savedLang === 'en')) {
        currentLang = savedLang;

        // Update button states
        document.querySelectorAll('.lang-btn').forEach(btn => {
            btn.classList.remove('active');
            if (btn.dataset.lang === savedLang) {
                btn.classList.add('active');
            }
        });

        // Apply saved language
        translatePage(savedLang);
    }
}

// Initialize language buttons
function initLanguageSwitcher() {
    document.querySelectorAll('.lang-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const targetLang = this.dataset.lang;

            if (targetLang === currentLang) return;

            document.querySelectorAll('.lang-btn').forEach(b => b.classList.remove('active'));
            this.classList.add('active');

            currentLang = targetLang;
            translatePage(targetLang);
        });
    });
}

// Translate page content
function translatePage(lang) {
    // Method 1: Handle data-de / data-en attributes
    document.querySelectorAll('[data-de]').forEach(el => {
        if (lang === 'de' && el.dataset.de) {
            el.textContent = el.dataset.de;
        } else if (lang === 'en' && el.dataset.en) {
            el.textContent = el.dataset.en;
        }
    });

    // Method 2: Handle lang-de / lang-en classes (for legacy pages)
    document.querySelectorAll('.lang-de, .lang-en').forEach(el => {
        el.style.display = 'none';
    });

    document.querySelectorAll(`.lang-${lang}`).forEach(el => {
        el.style.display = el.tagName === 'SPAN' ? 'inline' : 'block';
    });

    document.documentElement.lang = lang;

    // Update chatbot language (if chatbot exists on this page)
    if (typeof updateChatbotLanguage === 'function') {
        updateChatbotLanguage(lang);
    }

    // Save language preference to localStorage
    localStorage.setItem(LANGUAGE_PREF_KEY, lang);
}

// Initialize when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    initLanguageSwitcher();
    loadLanguagePreference();
});