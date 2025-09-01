# AlfaAI Professional - Complete AI Solution

**Version:** 10.0.0  
**Developed by:** IT Team Alfassa  
**Website:** https://alfassa.org

## 🚀 Descrizione

AlfaAI Professional è una soluzione AI completa per WordPress che offre un'esperienza moderna e professionale simile a ChatGPT. Il plugin integra multiple provider AI, generazione video, ricerca web e database esterni Alfassa.

## ✨ Caratteristiche Principali

### 🎨 Design Moderno
- **Interfaccia stile ChatGPT** con design scuro e moderno
- **Completamente responsive** per mobile e desktop
- **Sidebar con conversazioni** e menu hamburger mobile
- **Animazioni fluide** e micro-interazioni
- **Scrollbar personalizzate** stile ChatGPT

### 🤖 Provider AI Multipli
- **Alfa AI Intelligence** (OpenAI GPT-4) - Conversazioni generali
- **AI Design** (Google Gemini) - Creatività e design
- **Alfa AI DeepSearch** (DeepSeek) - Programmazione e ricerca
- **Selezione automatica** del provider basata sul contenuto

### 🎬 Funzionalità Avanzate
- **Generazione video** con ChatGPT e Gemini Veo3
- **Ricerca web integrata** con storico
- **Upload file** con preview (immagini, video, audio, PDF)
- **Registrazione vocale** integrata
- **Database esterni Alfassa** per ricerche specializzate

### 🛠️ Dashboard Amministrativa
- **Menu visibile** in Impostazioni → AlfaAI Professional
- **Configurazione API keys** con test connessione
- **Gestione database esterni** con modal professionale
- **Personalizzazione brand** (colori, font)
- **Status sistema** con monitoraggio

## 📋 Requisiti

- **WordPress:** 5.8 o superiore
- **PHP:** 7.4 o superiore
- **MySQL:** 5.7 o superiore
- **API Keys:** OpenAI, Google Gemini, DeepSeek (almeno una)

## 🔧 Installazione

1. **Carica il plugin** nella directory `/wp-content/plugins/`
2. **Attiva il plugin** dal pannello WordPress
3. **Configura le API keys** in Impostazioni → AlfaAI Professional
4. **Visita la pagina AI** su `tuosito.com/alfa-ai`

## ⚙️ Configurazione

### API Keys
Configura almeno una delle seguenti API keys:

- **OpenAI API Key** - Per Alfa AI Intelligence
- **Google Gemini API Key** - Per AI Design
- **DeepSeek API Key** - Per Alfa AI DeepSearch

### Database Esterni
Aggiungi database esterni Alfassa per ricerche specializzate:

- **Nome:** Nome identificativo del database
- **Host:** Indirizzo del server database
- **Porta:** Porta di connessione (default: 3306)
- **Database:** Nome del database
- **Username/Password:** Credenziali di accesso

## 🎯 Utilizzo

### Pagina Standalone
Accedi all'interfaccia AI su `tuosito.com/alfa-ai`:

- **Conversazioni** salvate automaticamente
- **Upload file** trascinando o cliccando
- **Registrazione vocale** con il pulsante microfono
- **Quick actions** per iniziare rapidamente

### Provider Selection
Il sistema seleziona automaticamente il provider migliore:

- **Programmazione** → Alfa AI DeepSearch
- **Design/Creatività** → AI Design
- **Generale** → Alfa AI Intelligence

## 🔒 Sicurezza

- **Validazione input** completa
- **Sanitizzazione dati** WordPress standard
- **Nonce verification** per tutte le richieste AJAX
- **Escape output** per prevenire XSS
- **File upload** con controlli tipo e dimensione

## 📊 Database

Il plugin crea le seguenti tabelle:

- `wp_alfaai_conversations` - Conversazioni utente
- `wp_alfaai_messages` - Messaggi delle conversazioni
- `wp_alfaai_files` - File caricati
- `wp_alfaai_videos` - Generazioni video
- `wp_alfaai_searches` - Storico ricerche

## 🛠️ Sviluppo

### Struttura File
```
alfaai-professional/
├── alfaai-professional.php     # File principale
├── includes/
│   ├── class-alfaai-core.php      # Classe core
│   ├── class-alfaai-admin.php     # Dashboard admin
│   ├── class-alfaai-frontend.php  # Pagina frontend
│   ├── class-alfaai-api.php       # API REST
│   └── class-alfaai-database.php  # Gestione database
└── assets/
    ├── css/
    │   ├── admin.css           # Stili admin
    │   └── frontend.css        # Stili frontend
    └── js/
        ├── admin.js            # JavaScript admin
        └── frontend.js         # JavaScript frontend
```

### Hook e Filter
Il plugin utilizza hook WordPress standard:

- `plugins_loaded` - Inizializzazione classi
- `admin_menu` - Menu amministrativo
- `wp_ajax_*` - Endpoint AJAX
- `rest_api_init` - API REST

## 🐛 Troubleshooting

### Menu Admin Non Visibile
- Verifica che il plugin sia attivato
- Controlla i permessi utente
- Verifica errori PHP nei log

### Frontend Non Carica
- Controlla rewrite rules: vai in Impostazioni → Permalink e salva
- Verifica conflitti con altri plugin
- Controlla errori JavaScript nella console

### API Non Funzionano
- Verifica le API keys in Impostazioni
- Controlla la connessione internet
- Verifica i log di errore WordPress

## 📞 Supporto

- **Website:** https://alfassa.org
- **Support:** https://alfassa.org/support
- **Documentation:** https://alfassa.org/docs/alfaai-professional

## 📄 Licenza

Questo plugin è rilasciato sotto licenza GPL v2 o superiore.

## 🏆 Credits

**Sviluppato da IT Team Alfassa**  
Un prodotto di qualità professionale per l'ecosistema WordPress.

---

© 2024 IT Team Alfassa - Tutti i diritti riservati

