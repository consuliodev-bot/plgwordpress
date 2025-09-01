/**
 * AlfaAI Professional Frontend JavaScript - Enterprise Edition (fixed)
 */

(function($) {
    'use strict';

    // Global variables
    let currentConversationId = 0;
    let currentMode = 'chat';
    let isStreaming = false;
    let eventSource = null;
    let pendingAssistantId = null;
    let currentStreamFormat = 'plain';
    let lastUserMessage = '';

    // Initialize when document is ready
    $(document).ready(function() {
        if (!document.getElementById('alfaai-app')) { return; }
        initializeApp();
        bindEvents();
        loadConversations();
        initializeTheme();

        // Initialize highlight.js if available
        if (typeof hljs !== 'undefined') {
            hljs.highlightAll();
        }
    });

    /**
     * Initialize the application
     */
    function initializeApp() {
        // Auto-resize textarea
        autoResizeTextarea($('#message-input'));

        // Set initial focus
        $('#message-input').focus();

        // Initialize tooltips if needed
        initializeTooltips();

        // Inject lightbox markup (per immagini nei risultati)
        ensureImageLightbox();
    }

    /**
     * Bind all event handlers
     */
    function bindEvents() {
        // Theme toggle
        $('#theme-toggle').on('click', toggleTheme);

        // Sidebar toggle (mobile)
        $('#sidebar-toggle').on('click', toggleSidebar);

        // New conversation
        $('#new-conversation').on('click', startNewConversation);

        // Mode buttons
        $('.alfaai-mode-btn').on('click', function() {
            switchMode($(this).data('mode'));
        });

        // Lightbox: handler unico per qualsiasi elemento con classe .alfaai-open-image
        $(document).on('click', '.alfaai-open-image', function (e) {
            e.preventDefault();
            const src = $(this).data('full') || $(this).data('full-src') || $(this).attr('href') || $(this).attr('src');
            if (src) openLightbox(src);
        });

        // Chiudi lightbox
        $(document).on('click', '#alfaai-lightbox .alfaai-lightbox-close, #alfaai-lightbox .alfaai-lightbox-backdrop', function() {
            closeLightbox();
        });
        $(document).on('keydown', function(e){
            if (e.key === 'Escape') closeLightbox();
        });

        // Message form
        $('#message-form').on('submit', handleMessageSubmit);

        // File attachment
        $('#attach-file').on('click', function() {
            $('#file-input').click();
        });

        $('#file-input').on('change', handleFileUpload);

        // Voice input
        $('#voice-input').on('click', handleVoiceInput);

        // Export chat
        $('#export-chat').on('click', showExportModal);

        // Conversation search
        $('#conversation-search').on('input', debounce(searchConversations, 300));

        // Modal handlers
        bindModalEvents();

        // Keyboard shortcuts
        bindKeyboardShortcuts();

        // Auto-scroll messages
        bindAutoScroll();
    }

    /**
     * Initialize theme system
     */
    function initializeTheme() {
        const savedTheme = localStorage.getItem('alfaai-theme') || alfaai_ajax.theme || 'auto';
        setTheme(savedTheme);
    }

    /**
     * Set theme
     */
    function setTheme(theme) {
        document.body.setAttribute('data-theme', theme);
        localStorage.setItem('alfaai-theme', theme);

        // Update theme toggle icon
        updateThemeToggleIcon(theme);
    }
// Aggiungi queste funzioni alla sezione di gestione file
function handleGoogleVisionAnalysis(file) {
    const formData = new FormData();
    formData.append('action', 'alfaai_analyze_image');
    formData.append('nonce', alfaai_ajax.nonce);
    formData.append('file', file);

    showNotification('Analisi immagine in corso...', 'info');

    $.ajax({
        url: alfaai_ajax.ajax_url,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                const analysisText = formatVisionAnalysis(response.data);
                addMessageToChat('assistant', analysisText);
                showNotification('Analisi completata', 'success');
            } else {
                showNotification('Errore analisi: ' + response.data.message, 'error');
            }
        },
        error: function() {
            showNotification('Errore di connessione', 'error');
        }
    });
}

function formatVisionAnalysis(analysis) {
    let text = '## Analisi Immagine\n\n';
    
    if (analysis.labelAnnotations && analysis.labelAnnotations.length > 0) {
        text += '**Etichette rilevate:**\n';
        analysis.labelAnnotations.forEach(label => {
            text += `- ${label.description} (${Math.round(label.score * 100)}%)\n`;
        });
        text += '\n';
    }
    
    if (analysis.textAnnotations && analysis.textAnnotations.length > 0) {
        text += '**Testo individuato:**\n';
        text += '```\n' + analysis.textAnnotations[0].description + '\n```\n\n';
    }
    
    if (analysis.safeSearchAnnotation) {
        const safeSearch = analysis.safeSearchAnnotation;
        text += '**Sicurezza contenuto:**\n';
        text += `- Adulti: ${safeSearch.adult}\n`;
        text += `- Violenza: ${safeSearch.violence}\n`;
        text += `- Contenuto medico: ${safeSearch.medical}\n`;
    }
    
    return text;
}

// Modifica la funzione handleFileUpload per includere l'analisi immagini
function handleFileUpload(e) {
    const file = e.target.files[0];
    if (!file) return;

    const allowedTypes = [
        'application/pdf', 
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 
        'image/jpeg', 
        'image/png', 
        'image/gif',
        'audio/webm',
        'audio/mpeg'
    ];
    
    const maxSize = 10 * 1024 * 1024; // 10MB

    if (!allowedTypes.includes(file.type)) {
        showNotification('Tipo di file non supportato', 'error');
        return;
    }

    if (file.size > maxSize) {
        showNotification('File troppo grande (max 10MB)', 'error');
        return;
    }

    // Se è un'immagine, avvia l'analisi con Google Vision
    if (file.type.startsWith('image/')) {
        handleGoogleVisionAnalysis(file);
        $(e.target).val('');
        return;
    }
    
    // Se è un audio, usa il riconoscimento vocale
    if (file.type.startsWith('audio/')) {
        handleSpeechRecognition(file);
        $(e.target).val('');
        return;
    }

    // Upload file per altri tipi
    const formData = new FormData();
    formData.append('action', 'alfaai_upload_file');
    formData.append('nonce', alfaai_ajax.nonce);
    formData.append('file', file);

    $.ajax({
        url: alfaai_ajax.ajax_url,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                const currentMessage = $('#message-input').val();
                const fileInfo = `[File: ${file.name}] `;
                $('#message-input').val(fileInfo + currentMessage);
                showNotification('File caricato con successo', 'success');
            } else {
                showNotification('Errore nel caricamento: ' + response.data.message, 'error');
            }
        },
        error: function() {
            showNotification('Errore nel caricamento del file', 'error');
        }
    });

    // Clear file input
    $(e.target).val('');
}

function handleSpeechRecognition(audioFile) {
    const formData = new FormData();
    formData.append('action', 'alfaai_google_speech');
    formData.append('nonce', alfaai_ajax.nonce);
    formData.append('audio', audioFile);
    formData.append('language', 'it-IT');

    showNotification('Trascrizione audio in corso...', 'info');

    $.ajax({
        url: alfaai_ajax.ajax_url,
        type: 'POST',
        data: formData,
        processData: false,
        contentType: false,
        success: function(response) {
            if (response.success) {
                $('#message-input').val(response.data.text);
                showNotification('Trascrizione completata', 'success');
            } else {
                showNotification('Errore trascrizione: ' + response.data.message, 'error');
            }
        },
        error: function() {
            showNotification('Errore di connessione', 'error');
        }
    });
}
    /**
     * Toggle theme
     */
    function toggleTheme() {
        const currentTheme = document.body.getAttribute('data-theme') || 'auto';
        let newTheme;

        switch (currentTheme) {
            case 'light':
                newTheme = 'dark';
                break;
            case 'dark':
                newTheme = 'auto';
                break;
            default:
                newTheme = 'light';
        }

        setTheme(newTheme);
    }

    /**
     * Update theme toggle icon
     */
    function updateThemeToggleIcon(theme) {
        const $toggle = $('#theme-toggle');
        $toggle.find('.theme-icon').hide();

        if (theme === 'dark') {
            $toggle.find('.theme-dark').show();
        } else {
            $toggle.find('.theme-light').show();
        }
    }

    /**
     * Toggle sidebar (mobile)
     */
    function toggleSidebar() {
        $('#alfaai-sidebar').toggleClass('open');
    }

    /**
     * Switch mode (chat, web, image, video)
     */
    function switchMode(mode) {
        currentMode = mode;

        // Update active button
        $('.alfaai-mode-btn').removeClass('active');
        $(`.alfaai-mode-btn[data-mode="${mode}"]`).addClass('active');

        // Update placeholder text
        updateInputPlaceholder(mode);

        // Show/hide mode-specific UI
        updateModeUI(mode);
    }

    /**
     * Update input placeholder based on mode
     */
    function updateInputPlaceholder(mode) {
        const placeholders = {
            'chat': 'Scrivi il tuo messaggio...',
            'web': 'Cerca informazioni sul web...',
            'image': 'Descrivi l\'immagine da generare...',
            'video': 'Descrivi il video da generare...'
        };

        $('#message-input').attr('placeholder', placeholders[mode] || placeholders.chat);
    }

    /**
     * Update mode-specific UI
     */
    function updateModeUI(mode) {
        // (Lasciata per estensioni future)
    }

    /**
     * Handle message form submission
     */
    function handleMessageSubmit(e) {
        e.preventDefault();

        if (isStreaming) return;

        const message = $('#message-input').val().trim();
        if (!message) return;

        switch (currentMode) {
            case 'image':
                generateImageInChat(message);
                break;
            case 'video':
                showVideoModal(message);
                break;
            default:
                sendMessage(message);
        }

        // Clear input
        $('#message-input').val('').trigger('input');
    }

    /**
     * Send message to AI
     */
    function sendMessage(message) {
        if (isStreaming) return;

        isStreaming = true;
        lastUserMessage = message;

        // Add user message to chat
        addMessageToChat('user', message);

        // Show thinking indicator (la bolla con loader)
        showThinkingIndicator();

        // Prepare data
        const data = {
            action: 'alfaai_send_message',
            nonce: alfaai_ajax.nonce,
            message: message,
            conversation_id: currentConversationId,
            provider: (currentMode === 'web' ? 'brave' : 'alfassa_first'),
            mode: currentMode
        };

        // Try Server-Sent Events first
        if (typeof EventSource !== 'undefined') {
            startStreamingResponse(data);
        } else {
            // Fallback to regular AJAX
            sendRegularMessage(data);
        }
    }

    /**
     * Generazione immagine in chat
     */
    function generateImageInChat(prompt) {
        if (isStreaming) return;
        isStreaming = true;

        addMessageToChat('user', prompt);
        const thinkingMessageId = addMessageToChat('assistant', 'Sto creando la tua immagine, attendi un momento...');

        $.ajax({
            url: alfaai_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'alfaai_generate_image',
                prompt: prompt,
                nonce: alfaai_ajax.nonce
            },
            success: function(response) {
                $('#' + thinkingMessageId).remove();
                if (response.success) {
                    const content = `
  <p>Ecco l'immagine che ho generato per te:</p>
  <div class="alfaai-generated-image-gallery">
    <a href="${response.data.image_url}" class="alfaai-open-image" data-full="${response.data.image_url}">
      <img src="${response.data.image_url}" alt="${escapeHtml(prompt)}" class="alfaai-generated-image-thumb">
    </a>
  </div>
`;
                    addMessageToChat('assistant', content);
                } else {
                    addMessageToChat('assistant', 'Errore: ' + (response.data && response.data.message ? response.data.message : 'imprevisto'));
                }
            },
            error: () => {
                $('#' + thinkingMessageId).remove();
                addMessageToChat('assistant', 'Errore di connessione durante la generazione dell\'immagine.');
            },
            complete: () => { isStreaming = false; }
        });
    }

    function fetchVisionAnswer(prompt) {
        return $.ajax({
            url: alfaai_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'alfaai_get_vision',
                nonce: alfaai_ajax.nonce,
                prompt: prompt
            }
        });
    }

    /**
     * Start streaming response with SSE
     */
    function startStreamingResponse(data) {
    const params = new URLSearchParams(data).toString();
    const url = `${alfaai_ajax.ajax_url}?${params}&stream=1`;

    eventSource = new EventSource(url);
    let assistantMessageId = null;
    let fullResponse = '';
    let hasFirstChunk = false;
    let streamFormat = 'plain'; // <- formato annunciato dal backend via evento 'meta'

    // usa la bolla loader come contenitore dello stream
    assistantMessageId = showThinkingIndicator();

    // formato/metadata (prima dei chunk)
    eventSource.addEventListener('meta', function(event) {
        try {
            const meta = JSON.parse(event.data || '{}');
            streamFormat = meta.format || 'plain';
            currentStreamFormat = streamFormat;
        } catch (e) {}
    });

    // chunk di testo
    eventSource.addEventListener('response_chunk', function(event) {
        try {
            const dataChunk = JSON.parse(event.data || '{}');
            if (dataChunk.content) {
                fullResponse += dataChunk.content;
                updateMessageContent(assistantMessageId, fullResponse, null, streamFormat);

                if (!hasFirstChunk) {
                    hideThinkingIndicator();
                    hasFirstChunk = true;
                }
            }
        } catch (e) {
            console.error('Error parsing stream chunk:', e);
        }
    });

    // fine stream
    eventSource.addEventListener('done', function(event) {
        try { eventSource.close(); } catch (e) {}

        let attachments = null;
        try {
            const payload = JSON.parse(event.data || '{}');
            if (payload && payload.attachments) {
                attachments = payload.attachments; // { web_sources: [...], images: [...] }
            }
            if (payload && payload.format) {
                streamFormat = payload.format; // eventuale conferma dal backend
            }
        } catch (e) {}

        try { hideThinkingIndicator(); } catch (e) {}
        updateMessageContent(assistantMessageId, fullResponse, attachments, streamFormat);

        isStreaming = false;
        pendingAssistantId = null; // reset per prossime richieste

        fetchVisionAnswer(lastUserMessage).done(function(resp) {
            if (resp.success && resp.data && resp.data.message) {
                addMessageToChat('assistant', resp.data.message, null, resp.data.format || 'markdown');
            }
        });
    });

    // errore stream
    eventSource.onerror = function() {
        try { eventSource.close(); } catch (e) {}
        isStreaming = false;
        hideThinkingIndicator();
        updateMessageContent(assistantMessageId, 'Errore di connessione. Riprova più tardi.', null, 'plain');
        pendingAssistantId = null;
    };
}

    /**
     * Send regular AJAX message (fallback)
     */
    function sendRegularMessage(data) {
        $.ajax({
            url: alfaai_ajax.ajax_url,
            type: 'POST',
            data: data,
            success: function(response) {
                hideThinkingIndicator();

                if (response.success) {
                    addMessageToChat('assistant', response.data.content, response.data.attachments);
                    fetchVisionAnswer(lastUserMessage).done(function(resp) {
                        if (resp.success && resp.data && resp.data.message) {
                            addMessageToChat('assistant', resp.data.message, null, resp.data.format || 'markdown');
                        }
                    });
                } else {
                    let errorMessage = 'Si è verificato un errore sconosciuto.';
                    if (response.data) {
                        if (typeof response.data === 'string') {
                            errorMessage = response.data;
                        } else if (response.data.message) {
                            errorMessage = response.data.message;
                        }
                    }
                    addMessageToChat('assistant', `Errore: ${errorMessage}`);
                }
            },
            error: function() {
                hideThinkingIndicator();
                addMessageToChat('assistant', 'Errore critico di connessione. Impossibile raggiungere il server.');
            },
            complete: function() {
                isStreaming = false;
                pendingAssistantId = null;
            }
        });
    }

    /**
     * Placeholder for future per-chunk logic
     */
    function handleStreamEvent() {}

    /**
     * Thinking indicator (in-bubble)
     */
    function showThinkingIndicator() {
        // crea (una sola volta) la bolla dell’assistente con il loader dentro
        if (pendingAssistantId) return pendingAssistantId;

        pendingAssistantId = addMessageToChat('assistant', `
            <div class="alfaai-thinking-bubble">
              <span class="dot"></span><span class="dot"></span><span class="dot"></span>
              <span class="alfaai-thinking-text">Sto pensando…</span>
            </div>
        `);
        return pendingAssistantId;
    }

    function updateThinkingIndicator(text) {
        if (!pendingAssistantId) return;
        const el = document.getElementById(pendingAssistantId);
        if (!el) return;
        const t = el.querySelector('.alfaai-thinking-text');
        if (t) t.textContent = text || 'Sto pensando…';
    }

    function hideThinkingIndicator() {
        if (!pendingAssistantId) return;
        const el = document.getElementById(pendingAssistantId);
        if (!el) return;
        const loader = el.querySelector('.alfaai-thinking-bubble');
        if (loader) loader.remove(); // Lascia la bolla: verrà riempita dallo streaming
    }

    /**
     * Add message to chat
     */
    function addMessageToChat(role, content, attachments = null, format = 'plain') {
    const messageId = 'msg-' + Date.now() + '-' + Math.random().toString(36).substr(2, 9);
    const isUser = role === 'user';
    const avatarText = isUser ? 'U' : 'AI';

    // Se passo HTML "puro" (es. loader), non processare Markdown
    let bodyHtml;
    if (typeof content === 'string' && /^\s*</.test(content.trim())) {
        bodyHtml = content;
        format = 'raw';
    } else {
        bodyHtml = processMessageContent(content || '', format);
    }

    const messageHtml = `
        <div class="alfaai-message ${role}" id="${messageId}" data-format="${format}">
            <div class="alfaai-message-avatar">${avatarText}</div>
            <div class="alfaai-message-content">
                <div class="alfaai-message-bubble">
                    <div class="alfaai-message-text">${bodyHtml}</div>
                    ${attachments ? renderAttachments(attachments) : ''}
                </div>
                ${!isUser ? renderMessageActions(messageId) : ''}
            </div>
        </div>
    `;

    $('.alfaai-welcome-message').remove();
    $('#messages-container').append(messageHtml);
    scrollToBottom();

    if (typeof hljs !== 'undefined') {
        $(`#${messageId} pre code`).each(function(_, block){ hljs.highlightElement(block); });
    }

    return messageId;
}


    function updateMessageContent(messageId, rawText, attachments, formatOverride) {
    const msgEl = document.getElementById(messageId);
    if (!msgEl) return;

    const textEl   = msgEl.querySelector('.alfaai-message-text');
    const bubbleEl = msgEl.querySelector('.alfaai-message-bubble');
    if (!textEl || !bubbleEl) return;

    // formato corrente (dataset o override)
    const format = formatOverride || msgEl.getAttribute('data-format') || 'plain';
    msgEl.setAttribute('data-format', format);

    // ----- IMMAGINI (sopra il testo) -----
    const oldImgs = msgEl.querySelector('.alfaai-attachments-images');
    if (oldImgs) oldImgs.remove();

    if (attachments && Array.isArray(attachments.images) && attachments.images.length) {
        const imgsWrap = document.createElement('div');
        imgsWrap.className = 'alfaai-attachments-images';
        imgsWrap.style.display = 'flex';
        imgsWrap.style.flexWrap = 'wrap';
        imgsWrap.style.gap = '8px';
        imgsWrap.style.marginBottom = '10px';

        attachments.images.forEach((src) => {
            if (!src) return;
            const a = document.createElement('a');
            a.href = '#';
            a.className = 'alfaai-open-image';
            a.setAttribute('data-full', src);

            const img = document.createElement('img');
            img.src = src;
            img.alt = '';
            img.style.width = '96px';
            img.style.height = '96px';
            img.style.objectFit = 'cover';
            img.style.borderRadius = '8px';
            img.style.display = 'block';

            a.appendChild(img);
            imgsWrap.appendChild(a);
        });

        bubbleEl.parentNode.insertBefore(imgsWrap, bubbleEl);
    }

    // ----- TESTO -----
    let html;
    if (format === 'raw') {
        html = rawText || '';
    } else {
        html = processMessageContent(rawText || '', format);
    }
    textEl.innerHTML = html;

    // ----- FONTI WEB -----
    const oldSources = msgEl.querySelector('.alfaai-attachments-sources');
    if (oldSources) oldSources.remove();

    if (attachments && Array.isArray(attachments.web_sources) && attachments.web_sources.length) {
        const box = document.createElement('div');
        box.className = 'alfaai-attachments-sources';

        const title = document.createElement('div');
        title.className = 'alfaai-sources__title';
        title.textContent = 'Fonti web';
        box.appendChild(title);

        const ol = document.createElement('ol');
        ol.className = 'alfaai-sources__list';

        attachments.web_sources.forEach((s) => {
            if (!s || !s.url) return;
            const li = document.createElement('li');

            const a = document.createElement('a');
            a.href = s.url;
            a.target = '_blank';
            a.rel = 'noopener';

            // Etichetta: titolo -> domain -> hostname -> url
            let label = (s.title && String(s.title).trim()) ? s.title
                      : (s.domain && String(s.domain).trim()) ? s.domain : '';
            if (!label) {
                try { label = new URL(s.url).hostname; } catch(e) { label = s.url; }
            }
            a.textContent = label;

            li.appendChild(a);
            ol.appendChild(li);
        });

        box.appendChild(ol);
        bubbleEl.insertAdjacentElement('afterend', box);
    }

    // evidenziazione codice
    if (typeof hljs !== 'undefined') {
        msgEl.querySelectorAll('pre code').forEach((block) => hljs.highlightElement(block));
    }
}


    /**
     * Process message content for markdown and code blocks
     */
    /* ===== Markdown renderer (no external libs) ===== */
function escapeHtml(str) {
  return (str || '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]));
}

function mdToHtml(md) {
  if (!md) return '';
  md = md.replace(/\r\n?/g, '\n');

  // Estrai blocchi di codice ```lang ... ```
  const codeBlocks = [];
  md = md.replace(/```([\w+-]*)\n([\s\S]*?)```/g, (m, lang, code) => {
    const id = codeBlocks.push({ lang, code }) - 1;
    return `§§CODE${id}§§`;
  });

  // Escape HTML del resto
  md = escapeHtml(md);

  // Headings
  md = md.replace(/^###### (.*)$/gm, '<h6>$1</h6>')
         .replace(/^##### (.*)$/gm, '<h5>$1</h5>')
         .replace(/^#### (.*)$/gm, '<h4>$1</h4>')
         .replace(/^### (.*)$/gm, '<h3>$1</h3>')
         .replace(/^## (.*)$/gm, '<h2>$1</h2>')
         .replace(/^# (.*)$/gm, '<h1>$1</h1>');

  // Blockquote (dopo escape gli ">" diventano &gt;)
  md = md.replace(/^\s*&gt;\s?(.*)$/gm, '<blockquote>$1</blockquote>');

  // Link [testo](url)
  md = md.replace(/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>');

  // Bold / italic
  md = md.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
         .replace(/(?<!\*)\*(?!\s)(.+?)(?<!\s)\*(?!\*)/g, '<em>$1</em>');

  // Inline code
  md = md.replace(/`([^`]+)`/g, (m, code) => `<code>${code}</code>`);

  // Liste ordinate
  md = md.replace(/^(?:\s*\d+\.\s.*(?:\n(?!\n|\d+\.|\s*[-*+]\s).+)*)/gm, block => {
    const items = block.split(/\n/).filter(Boolean).map(line => line.replace(/^\s*\d+\.\s/, '').trim());
    if (!items.length) return block;
    return '<ol>' + items.map(it => `<li>${it}</li>`).join('') + '</ol>';
  });

  // Liste non ordinate
  md = md.replace(/^(?:\s*[-*+]\s.*(?:\n(?!\n|\d+\.|\s*[-*+]\s).+)*)/gm, block => {
    const items = block.split(/\n/).filter(Boolean).map(line => line.replace(/^\s*[-*+]\s/, '').trim());
    if (!items.length) return block;
    return '<ul>' + items.map(it => `<li>${it}</li>`).join('') + '</ul>';
  });

  // Righe orizzontali
  md = md.replace(/^\s*---\s*$/gm, '<hr/>');

  // Paragrafi
  md = md.split(/\n{2,}/).map(chunk => {
    if (/^\s*(<h\d|<ul>|<ol>|<pre|<blockquote>|<hr\/>)/.test(chunk)) return chunk;
    const lines = chunk.split('\n').filter(s => s.trim() !== '');
    if (!lines.length) return '';
    return '<p>' + lines.join('<br/>') + '</p>';
  }).join('\n');

  // Re-inserisci i blocchi di codice
  md = md.replace(/§§CODE(\d+)§§/g, (m, id) => {
    const blk = codeBlocks[Number(id)] || { lang: '', code: '' };
    const lang = blk.lang ? ` data-lang="${escapeHtml(blk.lang)}"` : '';
    return `<pre class="alfaai-code"><code${lang}>${escapeHtml(blk.code)}</code></pre>`;
  });

  return md.trim();
}

/**
 * Main content processor usato dalle bolle chat.
 * - format: 'markdown' | 'plain'
 */
function processMessageContent(text, format = 'plain') {
  if (format === 'markdown') return mdToHtml(text);
  return '<p>' + escapeHtml(text || '').replace(/\n/g, '<br/>') + '</p>';
}


    /**
     * Render attachments (citations, images, etc.)
     * (Manteniamo per compatibilità – le immagini ora vengono rese da updateMessageContent)
     */
    function renderAttachments(attachments) {
        let html = '';

        if (attachments && attachments.sources && attachments.sources.length > 0) {
            html += '<div class="alfaai-citations"><h4>Fonti:</h4>';
            attachments.sources.forEach(function(source) {
                html += `
                    <a href="#" class="alfaai-citation">
                        <svg class="alfaai-citation-icon" fill="currentColor" viewBox="0 0 20 20">
                            <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/>
                        </svg>
                        <span class="alfaai-citation-text">${source}</span>
                    </a>
                `;
            });
            html += '</div>';
        }

        if (attachments && attachments.web_sources && attachments.web_sources.length > 0) {
            html += '<div class="alfaai-citations"><h4>Fonti web:</h4>';
            attachments.web_sources.forEach(function(source) {
                html += `
                    <a href="${source.url}" target="_blank" rel="noopener" class="alfaai-citation">
                        <svg class="alfaai-citation-icon" fill="currentColor" viewBox="0 0 20 20">
                            <path fill-rule="evenodd" d="M4.083 9h1.946c.089-1.546.383-2.97.837-4.118A6.004 6.004 0 004.083 9zM10 2a8 8 0 100 16 8 8 0 000-16zm0 2c-.076 0-.232.032-.465.262-.238.234-.497.623-.737 1.182-.389.907-.673 2.142-.766 3.556h3.936c-.093-1.414-.377-2.649-.766-3.556-.24-.56-.5-.948-.737-1.182C10.232 4.032 10.076 4 10 4zm3.971 5c-.089-1.546-.383-2.97-.837-4.118A6.004 6.004 0 0115.917 9h-1.946zm-2.003 2H8.032c.093 1.414.377 2.649.766 3.556.24.56.5.948.737 1.182.233.23.389.262.465.262.076 0 .232-.032.465-.262.238-.234.498-.623.737-1.182.389-.907.673-2.142.766-3.556zm1.166 4.118c.454-1.147.748-2.572.837-4.118h1.946a6.004 6.004 0 01-2.783 4.118zm-6.268 0C6.412 13.97 6.118 12.546 6.03 11H4.083a6.004 6.004 0 002.783 4.118z" clip-rule="evenodd"/>
                        </svg>
                        <span class="alfaai-citation-text">${source.title}</span>
                    </a>
                `;
            });
            html += '</div>';
        }

        // (le immagini non vengono più rese qui)
        return html;
    }

    /**
     * Render message actions (copy, share, etc.)
     */
    function renderMessageActions(messageId) {
        return `
            <div class="alfaai-message-actions">
                <button class="alfaai-message-action" data-action="copy" data-message-id="${messageId}" title="Copia messaggio">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M8 3a1 1 0 011-1h2a1 1 0 110 2H9a1 1 0 01-1-1z"/>
                        <path d="M6 3a2 2 0 00-2 2v11a2 2 0 002 2h8a2 2 0 002-2V5a2 2 0 00-2-2 3 3 0 01-3 3H9a3 3 0 01-3-3z"/>
                    </svg>
                </button>
                <button class="alfaai-message-action" data-action="share" data-message-id="${messageId}" title="Condividi messaggio">
                    <svg width="16" height="16" fill="currentColor" viewBox="0 0 20 20">
                        <path d="M15 8a3 3 0 10-2.977-2.63l-4.94 2.47a3 3 0 100 4.319l4.94 2.47a3 3 0 10.895-1.789l-4.94-2.47a3.027 3.027 0 000-.74l4.94-2.47C13.456 7.68 14.19 8 15 8z"/>
                    </svg>
                </button>
            </div>
        `;
    }

    /**
     * Handle file upload
     */
    function handleFileUpload(e) {
        const file = e.target.files[0];
        if (!file) return;

        // Check file type and size
        const allowedTypes = ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'image/jpeg', 'image/png', 'image/gif'];
        const maxSize = 10 * 1024 * 1024; // 10MB

        if (!allowedTypes.includes(file.type)) {
            showNotification('Tipo di file non supportato', 'error');
            return;
        }

        if (file.size > maxSize) {
            showNotification('File troppo grande (max 10MB)', 'error');
            return;
        }

        // Upload file
        const formData = new FormData();
        formData.append('action', 'alfaai_upload_file');
        formData.append('nonce', alfaai_ajax.nonce);
        formData.append('file', file);

        $.ajax({
            url: alfaai_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    const currentMessage = $('#message-input').val();
                    const fileInfo = `[File: ${file.name}] `;
                    $('#message-input').val(fileInfo + currentMessage);
                    showNotification('File caricato con successo', 'success');
                } else {
                    showNotification('Errore nel caricamento: ' + response.data.message, 'error');
                }
            },
            error: function() {
                showNotification('Errore nel caricamento del file', 'error');
            }
        });

        // Clear file input
        $(e.target).val('');
    }

    /**
     * Load conversations list
     */
    function loadConversations() {
        $.ajax({
            url: alfaai_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'alfaai_get_conversations',
                nonce: alfaai_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    renderConversations(response.data);
                } else {
                    $('#conversations-list').html('<div class="alfaai-loading">Errore nel caricamento</div>');
                }
            },
            error: function() {
                $('#conversations-list').html('<div class="alfaai-loading">Errore di connessione</div>');
            }
        });
    }

    /**
     * Render conversations in sidebar
     */
    function renderConversations(conversations) {
        const $list = $('#conversations-list');

        if (!conversations || conversations.length === 0) {
            $list.html('<div class="alfaai-loading">Nessuna conversazione</div>');
            return;
        }

        let html = '';
        conversations.forEach(function(conv) {
            const date = new Date(conv.created_at).toLocaleDateString();
            const isActive = conv.id == currentConversationId ? 'active' : '';

            html += `
                <div class="alfaai-conversation-item ${isActive}" data-conversation-id="${conv.id}">
                    <div class="alfaai-conversation-title">${escapeHtml(conv.title)}</div>
                    <div class="alfaai-conversation-date">${date}</div>
                </div>
            `;
        });

        $list.html(html);

        // Bind click events
        $('.alfaai-conversation-item').on('click', function() {
            const conversationId = $(this).data('conversation-id');
            loadConversation(conversationId);
        });
    }

    /**
     * Load specific conversation
     */
    function loadConversation(conversationId) {
        currentConversationId = conversationId;

        // Update active conversation in sidebar
        $('.alfaai-conversation-item').removeClass('active');
        $(`.alfaai-conversation-item[data-conversation-id="${conversationId}"]`).addClass('active');

        // For now, we'll just clear the chat
        $('#messages-container').empty();

        // Close sidebar on mobile
        if (window.innerWidth <= 768) {
            $('#alfaai-sidebar').removeClass('open');
        }
    }

    /**
     * Start new conversation
     */
    function startNewConversation() {
        currentConversationId = 0;

        // Clear active conversation
        $('.alfaai-conversation-item').removeClass('active');

        // Clear messages
        $('#messages-container').html(`
            <div class="alfaai-welcome-message">
                <div class="alfaai-welcome-content">
                    <h2>Benvenuto in ${alfaai_ajax.brand_name}</h2>
                    <p>La tua AI professionale con accesso ai database Alfassa, ricerca web e generazione multimediale.</p>
                    <div class="alfaai-welcome-features">
                        <div class="alfaai-feature">
                            <svg width="24" height="24" fill="currentColor" viewBox="0 0 20 20">
                                <path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/>
                            </svg>
                            <span>Database Alfassa integrati</span>
                        </div>
                        <div class="alfaai-feature">
                            <svg width="24" height="24" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M4.083 9h1.946c.089-1.546.383-2.97.837-4.118A6.004 6.004 0 004.083 9zM10 2a8 8 0 100 16 8 8 0 000-16zm0 2c-.076 0-.232.032-.465.262-.238.234-.497.623-.737 1.182-.389.907-.673 2.142-.766 3.556h3.936c-.093-1.414-.377-2.649-.766-3.556-.24-.56-.5-.948-.737-1.182C10.232 4.032 10.076 4 10 4zm3.971 5c-.089-1.546-.383-2.97-.837-4.118A6.004 6.004 0 0115.917 9h-1.946zm-2.003 2H8.032c.093 1.414.377 2.649.766 3.556.24.56.5.948.737 1.182.233.23.389.262.465.262.076 0 .232-.032.465-.262.238-.234.498-.623.737-1.182.389-.907.673-2.142.766-3.556zm1.166 4.118c.454-1.147.748-2.572.837-4.118h1.946a6.004 6.004 0 01-2.783 4.118zm-6.268 0C6.412 13.97 6.118 12.546 6.03 11H4.083a6.004 6.004 0 002.783 4.118z" clip-rule="evenodd"/>
                            </svg>
                            <span>Ricerca web in tempo reale</span>
                        </div>
                        <div class="alfaai-feature">
                            <svg width="24" height="24" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd" d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z" clip-rule="evenodd"/>
                            </svg>
                            <span>Generazione immagini e video</span>
                        </div>
                    </div>
                </div>
            </div>
        `);

        // Focus input
        $('#message-input').focus();

        // Close sidebar on mobile
        if (window.innerWidth <= 768) {
            $('#alfaai-sidebar').removeClass('open');
        }
    }

    /**
     * Search conversations
     */
    function searchConversations() {
        const query = $('#conversation-search').val().toLowerCase();

        $('.alfaai-conversation-item').each(function() {
            const title = $(this).find('.alfaai-conversation-title').text().toLowerCase();
            if (title.includes(query)) {
                $(this).show();
            } else {
                $(this).hide();
            }
        });
    }

    /**
     * Show image generation modal
     */
    function showImageModal(prompt = '') {
        $('#image-modal').show();
        $('#image-modal textarea[name="prompt"]').val(prompt).focus();
        $('#image-result').hide();
        $('#generated-image').attr('src', '');
    }

    /**
     * Show video generation modal
     */
    function showVideoModal(prompt = '') {
        $('#video-modal').show();
        $('#video-modal textarea[name="prompt"]').val(prompt).focus();
        $('#video-result').hide();
        $('#generated-video').attr('src', '').hide();
    }

    /**
     * Show export modal
     */
    function showExportModal() {
        if (currentConversationId === 0) {
            showNotification('Nessuna conversazione da esportare', 'warning');
            return;
        }

        // Simple export options
        const format = confirm('Esportare in formato JSON? (Annulla per Markdown)') ? 'json' : 'markdown';

        $.ajax({
            url: alfaai_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'alfaai_export_conversation',
                nonce: alfaai_ajax.nonce,
                conversation_id: currentConversationId,
                format: format
            },
            success: function(response) {
                if (response.success) {
                    downloadFile(response.data.data, response.data.filename, response.data.mime_type);
                } else {
                    showNotification('Errore nell\'esportazione: ' + response.data.message, 'error');
                }
            },
            error: function() {
                showNotification('Errore nell\'esportazione', 'error');
            }
        });
    }

    /**
     * Bind modal events
     */
    function bindModalEvents() {
        // Close modals
        $('.alfaai-modal-close, #cancel-image, #cancel-video').on('click', function() {
            $(this).closest('.alfaai-modal').hide();
        });

        // Close modal when clicking outside
        $('.alfaai-modal').on('click', function(e) {
            if (e.target === this) {
                $(this).hide();
            }
        });

        // Generate image
        $('#generate-image').on('click', function() {
            const prompt = $('#image-modal textarea[name="prompt"]').val().trim();
            if (!prompt) {
                showNotification('Inserisci una descrizione per l\'immagine', 'warning');
                return;
            }

            generateImage(prompt);
        });

        // Generate video
        $('#generate-video').on('click', function() {
            const prompt = $('#video-modal textarea[name="prompt"]').val().trim();
            if (!prompt) {
                showNotification('Inserisci una descrizione per il video', 'warning');
                return;
            }

            const duration = $('#video-modal select[name="duration"]').val();
            const resolution = $('#video-modal select[name="resolution"]').val();

            generateVideo(prompt, duration, resolution);
        });

        // Copy code buttons
        $(document).on('click', '.alfaai-code-copy', function() {
            const codeId = $(this).data('code-id');
            const code = document.getElementById(codeId).textContent;

            copyToClipboard(code);
            $(this).text('Copiato!');

            setTimeout(() => {
                $(this).text('Copia');
            }, 2000);
        });

        // Message actions
        $(document).on('click', '.alfaai-message-action', function() {
            const action = $(this).data('action');
            const messageId = $(this).data('message-id');

            handleMessageAction(action, messageId);
        });
    }

    /**
     * Generate image (modal)
     */
    function generateImage(prompt) {
        $('#generate-image').prop('disabled', true).text('Generando...');

        $.ajax({
            url: alfaai_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'alfaai_generate_image',
                nonce: alfaai_ajax.nonce,
                prompt: prompt
            },
            success: function(response) {
                if (response.success) {
                    $('#generated-image').attr('src', response.data.image_url);
                    $('#image-result').show();
                    $('#generated-image')
                        .addClass('alfaai-open-image')
                        .attr('data-full', response.data.image_url)
                        .css('cursor','zoom-in');
                    showNotification('Immagine generata con successo!', 'success');
                } else {
                    showNotification('Errore nella generazione: ' + response.data.message, 'error');
                }
            },
            error: function() {
                showNotification('Errore nella generazione dell\'immagine', 'error');
            },
            complete: function() {
                $('#generate-image').prop('disabled', false).text('Genera');
            }
        });
    }

    /**
     * Generate video
     */
    function generateVideo(prompt, duration, resolution) {
        $('#generate-video').prop('disabled', true).text('Generando...');
        $('#video-result').show();
        $('#video-status-text').text('Avvio generazione...');

        $.ajax({
            url: alfaai_ajax.ajax_url,
            type: 'POST',
            data: {
                action: 'alfaai_generate_video',
                nonce: alfaai_ajax.nonce,
                prompt: prompt,
                duration: duration,
                resolution: resolution
            },
            success: function(response) {
                if (response.success) {
                    const jobId = response.data.job_id;
                    pollVideoStatus(jobId);
                } else {
                    showNotification('Errore nella generazione: ' + response.data.message, 'error');
                    $('#video-result').hide();
                }
            },
            error: function() {
                showNotification('Errore nella generazione del video', 'error');
                $('#video-result').hide();
            },
            complete: function() {
                $('#generate-video').prop('disabled', false).text('Genera');
            }
        });
    }

    /**
     * Poll video generation status
     */
    function pollVideoStatus(jobId) {
        const poll = function() {
            $.ajax({
                url: alfaai_ajax.ajax_url,
                type: 'POST',
                data: {
                    action: 'alfaai_video_status',
                    nonce: alfaai_ajax.nonce,
                    job_id: jobId
                },
                success: function(response) {
                    if (response.success) {
                        const status = response.data.status;
                        $('#video-status-text').text(response.data.message);

                        if (status === 'completed') {
                            $('#generated-video').attr('src', response.data.result_url).show();
                            $('.alfaai-video-status').hide();
                            showNotification('Video generato con successo!', 'success');
                        } else if (status === 'failed') {
                            showNotification('Generazione video fallita', 'error');
                            $('#video-result').hide();
                        } else {
                            setTimeout(poll, 3000);
                        }
                    } else {
                        showNotification('Errore nel controllo stato: ' + response.data.message, 'error');
                        $('#video-result').hide();
                    }
                },
                error: function() {
                    showNotification('Errore nel controllo stato del video', 'error');
                    $('#video-result').hide();
                }
            });
        };

        poll();
    }

    /**
     * Handle message actions (copy, share)
     */
    function handleMessageAction(action, messageId) {
        const $message = $('#' + messageId);
        const messageText = $message.find('.alfaai-message-text').text();

        switch (action) {
            case 'copy':
                copyToClipboard(messageText);
                showNotification('Messaggio copiato!', 'success');
                break;
            case 'share':
                if (navigator.share) {
                    navigator.share({
                        title: 'Messaggio da AlfaAI',
                        text: messageText
                    });
                } else {
                    copyToClipboard(messageText);
                    showNotification('Messaggio copiato per la condivisione!', 'success');
                }
                break;
        }
    }

    /**
     * Bind auto-scroll for messages
     */
    function bindAutoScroll() {
        const target = document.getElementById('messages-container');
        if (target) {
            const observer = new MutationObserver(function(mutations) {
                mutations.forEach(function(mutation) {
                    if (mutation.type === 'childList' && mutation.addedNodes.length > 0) {
                        scrollToBottom();
                    }
                });
            });
            observer.observe(target, { childList: true });
        }
    }

    /**
     * Scroll to bottom of messages
     */
    function scrollToBottom() {
        const $container = $('#messages-container');
        $container.scrollTop($container[0].scrollHeight);
    }

    /**
     * Keyboard shortcuts
     */
    function bindKeyboardShortcuts() {
        $(document).on('keydown', function(e) {
            // Ctrl/Cmd + Enter to send message
            if ((e.ctrlKey || e.metaKey) && e.key === 'Enter') {
                e.preventDefault();
                $('#message-form').trigger('submit');
            }

            // Escape to close modals
            if (e.key === 'Escape') {
                $('.alfaai-modal:visible').hide();
                closeLightbox();
            }

            // Ctrl/Cmd + K to focus search
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                $('#conversation-search').focus();
            }
        });

        // Enter to send message (without Shift)
        $('#message-input').on('keydown', function(e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                $('#message-form').trigger('submit');
            }
        });
    }

    /**
     * Auto-resize textarea
     */
    function autoResizeTextarea($textarea) {
        if (!$textarea || !$textarea.length) return;
        $textarea.on('input', function() {
            this.style.height = 'auto';
            this.style.height = Math.min(this.scrollHeight, 160) + 'px';
        });
        // trigger immediately to size on load
        $textarea.trigger('input');
    }

    /**
     * Initialize tooltips
     */
    function initializeTooltips() {
        $('[title]').on('mouseenter', function() {
            const title = $(this).attr('title');
            if (title) {
                $(this).removeAttr('title').attr('data-original-title', title);
            }
        });
    }

    /**
     * Show notification
     */
    function showNotification(message, type = 'info') {
        const notification = $(`
            <div class="alfaai-notification alfaai-notification-${type}">
                ${message}
            </div>
        `);

        $('body').append(notification);

        setTimeout(function() {
            notification.fadeOut(function() {
                $(this).remove();
            });
        }, 3000);
    }

    /**
     * Copy text to clipboard
     */
    function copyToClipboard(text) {
        if (navigator.clipboard) {
            navigator.clipboard.writeText(text);
        } else {
            const textArea = document.createElement('textarea');
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.focus();
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
        }
    }

    /**
     * Download file
     */
    function downloadFile(content, filename, mimeType) {
        const blob = new Blob([content], { type: mimeType });
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = filename;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
    }

    /**
     * Escape HTML
     */
    function escapeHtml(text) {
        const map = {
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        };
        return String(text || '').replace(/[&<>"']/g, function(m) { return map[m]; });
    }

    /**
     * Handle voice input
     */
    function handleVoiceInput() {
        const $button = $('#voice-input');
        const $input = $('#message-input');

        if ($button.hasClass('recording')) {
            stopVoiceRecording();
            return;
        }

        if ('webkitSpeechRecognition' in window || 'SpeechRecognition' in window) {
            startWebSpeechRecognition($button, $input);
        } else {
            startMediaRecorder($button, $input);
        }
    }

    /**
     * Start Web Speech Recognition
     */
    function startWebSpeechRecognition($button, $input) {
        const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
        const recognition = new SpeechRecognition();

        recognition.lang = 'it-IT';
        recognition.continuous = false;
        recognition.interimResults = false;

        $button.addClass('recording').attr('title', 'Ferma registrazione');

        recognition.onstart = function() {
            console.log('Voice recognition started');
        };

        recognition.onresult = function(event) {
            const transcript = event.results[0][0].transcript;
            $input.val(transcript);
            autoResizeTextarea($input);
            $input.focus();
        };

        recognition.onerror = function(event) {
            console.error('Speech recognition error:', event.error);
            showNotification('Errore nel riconoscimento vocale: ' + event.error, 'error');
        };

        recognition.onend = function() {
            $button.removeClass('recording').attr('title', 'Registra messaggio vocale');
        };

        recognition.start();

        $button.data('recognition', recognition);
    }

    /**
     * Start MediaRecorder fallback
     */
    function startMediaRecorder($button, $input) {
        navigator.mediaDevices.getUserMedia({ audio: true })
            .then(function(stream) {
                const mediaRecorder = new MediaRecorder(stream);
                const audioChunks = [];

                $button.addClass('recording').attr('title', 'Ferma registrazione');

                mediaRecorder.ondataavailable = function(event) {
                    audioChunks.push(event.data);
                };

                mediaRecorder.onstop = function() {
                    const audioBlob = new Blob(audioChunks, { type: 'audio/webm' });
                    uploadAudioForTranscription(audioBlob, $input);

                    stream.getTracks().forEach(track => track.stop());
                    $button.removeClass('recording').attr('title', 'Registra messaggio vocale');
                };

                mediaRecorder.start();

                $button.data('mediaRecorder', mediaRecorder);
            })
            .catch(function(error) {
                console.error('Error accessing microphone:', error);
                showNotification('Errore nell\'accesso al microfono', 'error');
            });
    }

    /**
     * Stop voice recording
     */
    function stopVoiceRecording() {
        const $button = $('#voice-input');

        // Stop Web Speech Recognition
        const recognition = $button.data('recognition');
        if (recognition) {
            recognition.stop();
            $button.removeData('recognition');
        }

        // Stop MediaRecorder
        const mediaRecorder = $button.data('mediaRecorder');
        if (mediaRecorder && mediaRecorder.state === 'recording') {
            mediaRecorder.stop();
            $button.removeData('mediaRecorder');
        }
    }

    /**
     * Upload audio for transcription
     */
    function uploadAudioForTranscription(audioBlob, $input) {
        const formData = new FormData();
        formData.append('action', 'alfaai_transcribe_audio');
        formData.append('nonce', alfaai_ajax.nonce);
        formData.append('audio', audioBlob, 'recording.webm');

        $.ajax({
            url: alfaai_ajax.ajax_url,
            type: 'POST',
            data: formData,
            processData: false,
            contentType: false,
            success: function(response) {
                if (response.success) {
                    $input.val(response.data.text);
                    autoResizeTextarea($input);
                    $input.focus();
                } else {
                    showNotification(response.data.message || 'Errore nella trascrizione', 'error');
                }
            },
            error: function() {
                showNotification('Errore nella trascrizione audio', 'error');
            }
        });
    }

    /**
     * Debounce function
     */
    function debounce(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = function() {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    }

    /**
     * Lightbox helpers
     */
    function ensureImageLightbox() {
        if (document.getElementById('alfaai-lightbox')) return;
        const html = `
            <div id="alfaai-lightbox" class="alfaai-lightbox" style="display:none;">
              <div class="alfaai-lightbox-backdrop" style="position:fixed;inset:0;background:rgba(0,0,0,.6)"></div>
              <div class="alfaai-lightbox-inner" style="position:fixed;inset:0;display:flex;align-items:center;justify-content:center;padding:24px;">
                <button class="alfaai-lightbox-close" aria-label="Chiudi" style="position:absolute;top:12px;right:12px;font-size:28px;line-height:1;background:#fff;border:none;border-radius:8px;padding:2px 10px;cursor:pointer">×</button>
                <img class="alfaai-lightbox-img" alt="" style="max-width:95%;max-height:90%;border-radius:10px;box-shadow:0 10px 30px rgba(0,0,0,.35)">
              </div>
            </div>`;
        document.body.insertAdjacentHTML('beforeend', html);
    }
    function openLightbox(src) {
        const box = document.getElementById('alfaai-lightbox');
        if (!box) return;
        box.querySelector('.alfaai-lightbox-img').src = src;
        box.style.display = 'block';
    }
    function closeLightbox() {
        const box = document.getElementById('alfaai-lightbox');
        if (!box) return;
        box.style.display = 'none';
    }

})(jQuery);


