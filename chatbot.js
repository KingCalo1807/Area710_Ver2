// ==================== CHATBOT CONFIGURATION ====================
const CHATBOT_CONFIG = {
    showGreetingWidget: true // Wird auf anderen Seiten auf false gesetzt
};

// ==================== CHATBOT JAVASCRIPT ====================

// JSON Knowledge Base - wird aus externer Datei geladen
let knowledgeBase = null;
let chatbotLang = 'de'; // Current chatbot language

// Lade Knowledge Base
async function loadKnowledgeBase() {
    try {
        const response = await fetch('chatbot_knowledge.json');
        if (!response.ok) {
            throw new Error('Konnte Knowledge Base nicht laden');
        }
        knowledgeBase = await response.json();
        init();
    } catch (error) {
        console.error('Fehler beim Laden der Knowledge Base:', error);
        // Fallback Knowledge Base with multi-language support
        knowledgeBase = {
            "de": {
                "intents": [],
                "fallback": "Der Chatbot konnte nicht korrekt geladen werden. Bitte kontaktiere uns direkt.",
                "greeting": "Hallo! 👋 Ich bin area710 :). Wie kann ich dir helfen?",
                "maxFailuresMessage": "Es scheint, als könnte ich dir aktuell nicht weiterhelfen. Möchtest du direkt mit unserem Team sprechen?"
            },
            "en": {
                "intents": [],
                "fallback": "The chatbot could not be loaded correctly. Please contact us directly.",
                "greeting": "Hello! 👋 I'm area710 :). How can I help you?",
                "maxFailuresMessage": "It seems I can't help you at the moment. Would you like to speak directly with our team?"
            }
        };
        init();
    }
}

// Update chatbot language when page language changes
function updateChatbotLanguage(lang) {
    chatbotLang = lang;
    
    // Update placeholder based on language
    if (userInput) {
        if (lang === 'de' && userInput.dataset.placeholderDe) {
            userInput.placeholder = userInput.dataset.placeholderDe;
        } else if (lang === 'en' && userInput.dataset.placeholderEn) {
            userInput.placeholder = userInput.dataset.placeholderEn;
        }
    }
    
    // Update greeting if chat is empty or only has greeting
    if (messagesContainer.children.length <= 1) {
        messagesContainer.innerHTML = '';
        if (knowledgeBase && knowledgeBase[chatbotLang]) {
            addBotMessage(knowledgeBase[chatbotLang].greeting);
        }
    }

    // Update widget message if it's currently visible
    const widget = document.getElementById('chatbot-greeting-widget');
    if (widget && widget.classList.contains('show')) {
        const messageDiv = document.getElementById('widget-message');
        const messages = {
            'de': 'Hey, ich bin der Chatbot der area710! Möchtest du mit mir chatten?',
            'en': 'Hey, I\'m the area710 chatbot! Do you want to chat with me?'
        };
        messageDiv.textContent = messages[lang] || messages['de'];
    }
}

// Chatbot State
let isOpen = false;
let consecutiveFailures = 0;
const MAX_FAILURES = 3;

// Forward declaration for saveChatHistory (defined later in cookie section)
let saveChatHistory = function () {
};

// DOM Elements
const chatButton = document.getElementById('chatbot-button');
const chatWindow = document.getElementById('chatbot-window');
const closeBtn = document.getElementById('close-chat');
const messagesContainer = document.getElementById('chat-messages');
const userInput = document.getElementById('user-input');
const sendBtn = document.getElementById('send-btn');

// Initialize
function init() {
    // Nur Begrüßung zeigen, wenn Chat leer ist
    if (messagesContainer.children.length === 0) {
        if (knowledgeBase && knowledgeBase[chatbotLang]) {
            addBotMessage(knowledgeBase[chatbotLang].greeting);
        } else {
            addBotMessage("Hallo! 👋 Ich bin area710 :). Wie kann ich dir helfen?");
        }
    }
}

// Toggle Chat
function toggleChat() {
    isOpen = !isOpen;
    chatWindow.classList.toggle('open', isOpen);
    if (isOpen) {
        userInput.focus();
    }
}

// Add Message
function addMessage(text, isBot = true) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${isBot ? 'bot' : 'user'}`;

    const avatar = document.createElement('div');
    avatar.className = 'message-avatar';

    const bubble = document.createElement('div');
    bubble.className = 'message-bubble';
    bubble.innerHTML = text;

    messageDiv.appendChild(avatar);
    messageDiv.appendChild(bubble);
    messagesContainer.appendChild(messageDiv);

    scrollToBottom();

    // Save to cookies after adding message
    saveChatHistory();
}

// Add Bot Message with Typing Effect
function addBotMessage(text) {
    showTypingIndicator();

    setTimeout(() => {
        hideTypingIndicator();
        addMessage(text, true);
    }, 800 + Math.random() * 400);
}

// Typing Indicator
function showTypingIndicator() {
    const typingDiv = document.createElement('div');
    typingDiv.className = 'message bot';
    typingDiv.id = 'typing-indicator';

    const avatar = document.createElement('div');
    avatar.className = 'message-avatar';

    const bubble = document.createElement('div');
    bubble.className = 'message-bubble typing-indicator';
    bubble.innerHTML = '<span></span><span></span><span></span>';

    typingDiv.appendChild(avatar);
    typingDiv.appendChild(bubble);
    messagesContainer.appendChild(typingDiv);

    scrollToBottom();
}

function hideTypingIndicator() {
    const indicator = document.getElementById('typing-indicator');
    if (indicator) {
        indicator.remove();
    }
}

// Scroll to Bottom
function scrollToBottom() {
    messagesContainer.scrollTop = messagesContainer.scrollHeight;
}

// Process User Input
function processInput(input) {
    if (!input.trim()) return;

    addMessage(input, false);

    const response = getResponse(input);
    addBotMessage(response);

    userInput.value = '';
}

// Get Response from Knowledge Base
function getResponse(input) {
    const normalizedInput = input.toLowerCase().trim();

    // Get current language knowledge base
    const currentKB = knowledgeBase[chatbotLang];
    if (!currentKB) {
        return "Language not supported.";
    }

    // Check each intent
    for (const intent of currentKB.intents) {
        for (const keyword of intent.keywords) {
            const regex = new RegExp(`\\b${keyword.toLowerCase()}\\b`);
            if (regex.test(normalizedInput)) {
                consecutiveFailures = 0;
                return intent.answer;
            }
        }
    }

    // No match found
    consecutiveFailures++;

    if (consecutiveFailures >= MAX_FAILURES) {
        consecutiveFailures = 0;
        return currentKB.maxFailuresMessage || "Es scheint, als könnte ich dir aktuell nicht weiterhelfen. Möchtest du direkt mit unserem Team sprechen? <a href='contact.html' style='color: #FCAB14; text-decoration: underline;'>Hier geht's zum Kontaktformular</a>";
    }

    return currentKB.fallback;
}

// Event Listeners
chatButton.addEventListener('click', toggleChat);
closeBtn.addEventListener('click', toggleChat);

// Clear chat button
const clearChatBtn = document.getElementById('clear-chat');
clearChatBtn.addEventListener('click', () => {
    // Alle Nachrichten löschen
    messagesContainer.innerHTML = '';

    // Chat-History aus Cookies löschen
    localStorage.removeItem(CHAT_HISTORY_KEY);

    // Willkommensnachricht anzeigen
    if (knowledgeBase && knowledgeBase[chatbotLang]) {
        addBotMessage(knowledgeBase[chatbotLang].greeting);
    } else {
        addBotMessage("Hallo! 👋 Ich bin area710 :). Wie kann ich dir helfen?");
    }
});

sendBtn.addEventListener('click', () => {
    processInput(userInput.value);
});

userInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        processInput(userInput.value);
    }
});

// Widget Event Listeners
const widgetAcceptBtn = document.getElementById('widget-accept');
const widgetDeclineBtn = document.getElementById('widget-decline');

if (widgetAcceptBtn) {
    widgetAcceptBtn.addEventListener('click', () => {
        hideGreetingWidget();
        toggleChat();
    });
}

if (widgetDeclineBtn) {
    widgetDeclineBtn.addEventListener('click', () => {
        hideGreetingWidget();
    });
}

// ==================== COOKIE MANAGEMENT ====================
const COOKIE_CONSENT_KEY = 'area710_cookie_consent';
const CHAT_HISTORY_KEY = 'area710_chat_history';
const FIRST_VISIT_KEY = 'area710_first_visit';
const CHATBOT_GREETED_KEY = 'area710_chatbot_greeted';
let cookiesAccepted = false;

// Check cookie consent on page load
function checkCookieConsent() {
    const consent = localStorage.getItem(COOKIE_CONSENT_KEY);
    if (consent === null) {
        // Show banner if no decision was made
        setTimeout(() => {
            document.getElementById('cookie-banner').classList.add('show');
        }, 1000);
    } else if (consent === 'accepted') {
        cookiesAccepted = true;
    }
}

// Check if this is the first visit to this page
function isFirstVisitToPage() {
    const firstVisit = localStorage.getItem(FIRST_VISIT_KEY);
    if (firstVisit === null) {
        // Mark as visited
        localStorage.setItem(FIRST_VISIT_KEY, 'true');
        return true;
    }
    return false;
}

// Check if chatbot greeting was already shown
function hasShownGreeting() {
    return localStorage.getItem(CHATBOT_GREETED_KEY) === 'true';
}

// Mark greeting as shown
function markGreetingAsShown() {
    localStorage.setItem(CHATBOT_GREETED_KEY, 'true');
}

// Show greeting widget with language-specific message
function showGreetingWidget() {
    const widget = document.getElementById('chatbot-greeting-widget');
    const messageDiv = document.getElementById('widget-message');

    // Determine language - use chatbotLang or fallback to de
    const lang = chatbotLang || 'de';

    // Get message based on language
    const messages = {
        'de': 'Hey, ich bin der Chatbot der area710! Möchtest du mit mir chatten?',
        'en': 'Hey, I\'m the area710 chatbot! Do you want to chat with me?'
    };

    messageDiv.textContent = messages[lang] || messages['de'];

    // Show widget with animation
    setTimeout(() => {
        widget.classList.add('show');
    }, 100);
}

// Hide greeting widget
function hideGreetingWidget() {
    const widget = document.getElementById('chatbot-greeting-widget');
    widget.classList.remove('show');
}

// Auto-open chatbot after 15 seconds on first visit
function scheduleAutoOpenChatbot() {
    // Nur auf index.html mit CHATBOT_CONFIG aktivieren
    if (CHATBOT_CONFIG.showGreetingWidget && isFirstVisitToPage() && !hasShownGreeting()) {
        setTimeout(() => {
            // Only show if still on the same page and document is fully loaded
            if (document.readyState === 'complete' || document.readyState === 'interactive') {
                showGreetingWidget();
                markGreetingAsShown();
            }
        }, 15000); // 15 seconds
    }
}

function acceptCookies() {
    localStorage.setItem(COOKIE_CONSENT_KEY, 'accepted');
    cookiesAccepted = true;
    document.getElementById('cookie-banner').classList.remove('show');
}

function declineCookies() {
    localStorage.setItem(COOKIE_CONSENT_KEY, 'declined');
    cookiesAccepted = false;
    // Clear any existing chat history and language preference
    localStorage.removeItem(CHAT_HISTORY_KEY);
    localStorage.removeItem(LANGUAGE_PREF_KEY);
    document.getElementById('cookie-banner').classList.remove('show');
}

// Make functions globally available
window.acceptCookies = acceptCookies;
window.declineCookies = declineCookies;

// Save chat history to cookies
saveChatHistory = function () {
    if (!cookiesAccepted) return;

    const messages = [];
    document.querySelectorAll('#chat-messages .message').forEach(msg => {
        const isBot = msg.classList.contains('bot');
        const text = msg.querySelector('.message-bubble').innerHTML;
        messages.push({text, isBot});
    });

    localStorage.setItem(CHAT_HISTORY_KEY, JSON.stringify(messages));
};

// Load chat history from cookies
function loadChatHistory() {
    if (!cookiesAccepted) return;

    const saved = localStorage.getItem(CHAT_HISTORY_KEY);
    if (saved) {
        try {
            const messages = JSON.parse(saved);
            messages.forEach(msg => {
                addMessageDirectly(msg.text, msg.isBot);
            });
        } catch (e) {
            console.error('Error loading chat history:', e);
        }
    }
}

// Add message directly without triggering save (for loading history)
function addMessageDirectly(text, isBot = true) {
    const messageDiv = document.createElement('div');
    messageDiv.className = `message ${isBot ? 'bot' : 'user'}`;

    const avatar = document.createElement('div');
    avatar.className = 'message-avatar';

    const bubble = document.createElement('div');
    bubble.className = 'message-bubble';
    bubble.innerHTML = text;

    messageDiv.appendChild(avatar);
    messageDiv.appendChild(bubble);
    messagesContainer.appendChild(messageDiv);

    scrollToBottom();
}

// Initialize - wird aufgerufen wenn das DOM bereit ist
document.addEventListener('DOMContentLoaded', function() {
    // Set initial placeholder
    if (userInput && userInput.dataset.placeholderDe) {
        userInput.placeholder = userInput.dataset.placeholderDe;
    }
    
    // Initialize cookie check and load history
    checkCookieConsent();
    loadChatHistory();

    // Load saved language preference
    if (typeof loadLanguagePreference === 'function') {
        loadLanguagePreference();
    }

    // Start loading knowledge base
    loadKnowledgeBase();

    // Schedule auto-open chatbot after 15 seconds on first visit (nur wenn aktiviert in CHATBOT_CONFIG)
    if (CHATBOT_CONFIG && CHATBOT_CONFIG.showGreetingWidget) {
        scheduleAutoOpenChatbot();
    }
});

