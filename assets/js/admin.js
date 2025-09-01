// AlfaAI Professional Admin JavaScript - Versione Definitiva
jQuery(document).ready(function($) {
    'use strict';
    
    function initializeAdmin() {
        console.log('🚀 AlfaAI Professional Admin initialized - IT Team Alfassa');
        if (!$('.alfaai-admin-wrap').length) { return; }
        initializeTabs();
        initializeApiTests();
        initializeDbModal();
        loadSystemStatus();
    }

    function initializeTabs() {
        const $tabButtons = $('.alfaai-tab-button');
        if (!$tabButtons.length) return;
        $tabButtons.on('click', function() {
            const tabId = $(this).data('tab');
            $tabButtons.removeClass('active');
            $(this).addClass('active');
            $('.alfaai-tab-content').removeClass('active');
            $('#' + tabId).addClass('active');
        });
    }

    function initializeApiTests() {
        $(document).on('click', '.alfaai-cards-grid .alfaai-test-btn', function() {
            const $button = $(this);
            const provider = $button.data('provider');
            const $input = $button.closest('.alfaai-card').find('.alfaai-input');
            if (!$input.length) { return; }
            const apiKey = $input.val().trim();
            if (!apiKey) {
                alert('Inserisci prima la chiave API da testare.');
                return;
            }
            performAjaxRequest($button, { action: 'alfaai_test_provider', provider: provider, api_key: apiKey });
        });

        $(document).on('click', '.alfaai-db-card .alfaai-test-btn', function() {
            const $button = $(this);
            const dbId = $button.data('db-id');
            performAjaxRequest($button, { action: 'alfaai_test_external_db', db_id: dbId });
        });
    }

    function initializeDbModal() {
        const $modal = $('#add-db-modal');
        const $form = $('#add-db-form');
        if (!$modal.length || !$form.length) return;

        $('#add-external-db').on('click', function() {
            $form[0].reset();
            $form.find('input[name="db_id"]').remove();
            $modal.find('h3').text('Aggiungi Database Esterno');
            $form.find('input[name="password"]').attr('placeholder', '');
            $modal.fadeIn(200).addClass('active');
        });

        $(document).on('click', '.alfaai-btn-edit', function() {
            const dbId = $(this).data('db-id');
            $.ajax({
                url: alfaai_admin.ajax_url,
                type: 'POST',
                data: { action: 'alfaai_get_db_details', id: dbId, _ajax_nonce: alfaai_admin.nonce },
                success: function(response) {
                    if (response.success) {
                        const db = response.data;
                        $form[0].reset();
                        $form.find('input[name="db_id"]').remove();
                        $form.append(`<input type="hidden" name="db_id" value="${db.id}">`);
                        $modal.find('h3').text('Modifica Database Esterno');
                        $form.find('input[name="name"]').val(db.name);
                        $form.find('input[name="host"]').val(db.host);
                        $form.find('input[name="port"]').val(db.port);
                        $form.find('input[name="database"]').val(db.database);
                        $form.find('input[name="username"]').val(db.username);
                        $form.find('input[name="password"]').attr('placeholder', 'Lascia vuoto per non modificare').val('');
                        $modal.fadeIn(200).addClass('active');
                    } else {
                        alert('Errore: ' + response.data.message);
                    }
                }
            });
        });

        $('.alfaai-modal-close, #cancel-add-db').on('click', () => $modal.fadeOut(200).removeClass('active'));

        $('#save-add-db').on('click', function() {
            const $button = $(this);
            const formData = {};
            $form.find('input').each(function() { formData[$(this).attr('name')] = $(this).val(); });
            if (!formData.name || !formData.host || !formData.database) {
                alert('Nome, Host, e Nome Database sono obbligatori.');
                return;
            }
            performAjaxRequest($button, { action: 'alfaai_save_external_db', ...formData }, () => location.reload());
        });

        $(document).on('click', '.alfaai-remove-btn', function() {
            if (!confirm('Sei sicuro?')) return;
            const $button = $(this);
            const dbId = $button.data('db-id');
            performAjaxRequest($button, { action: 'alfaai_delete_external_db', id: dbId }, () => $button.closest('.alfaai-db-card').fadeOut(300, function() { $(this).remove(); }));
        });
    }

    function loadSystemStatus() {
    const $container = $('#system-status-container');
    if (!$container.length) return;

    // Funzione helper per aggiornare lo stato
    const updateStatus = (id, success, message) => {
        const $item = $(`#${id} span`);
        if (success) {
            $item.text(message).css('color', 'green');
        } else {
            $item.text(message).css('color', 'red');
        }
    };

    // 1. Carica info base
    $.ajax({
        url: alfaai_admin.ajax_url, type: 'POST',
        data: { action: 'alfaai_get_system_status', _ajax_nonce: alfaai_admin.nonce },
        success: function(res) {
            if (res.success) {
                updateStatus('status-php', true, res.data.php_version);
                updateStatus('status-wp', true, res.data.wp_version);
            }
        }
    });

    // 2. Testa le API una per una
    ['openai', 'gemini', 'brave'].forEach(provider => {
        const apiKey = $(`input[name="alfaai_pro_${provider}_key"]`).val();
        if (apiKey) {
            $.ajax({
                url: alfaai_admin.ajax_url, type: 'POST',
                data: { action: 'alfaai_test_provider', provider: provider, api_key: apiKey, _ajax_nonce: alfaai_admin.nonce },
                success: (res) => updateStatus(`status-${provider}`, res.success, res.success ? 'Attiva e Funzionante' : 'Fallito'),
                error: () => updateStatus(`status-${provider}`, false, 'Errore Chiamata')
            });
        } else {
            updateStatus(`status-${provider}`, false, 'Non configurata');
        }
    });

    // 3. Testa i DB esterni
    const $dbContainer = $('#status-external-dbs');
    $dbContainer.html(''); // Pulisci
    if ($('.alfaai-db-card').length === 0) {
        $dbContainer.html('<span>Nessun database configurato.</span>');
    } else {
        $('.alfaai-db-card').each(function() {
            const dbId = $(this).data('db-id');
            const dbName = $(this).find('h3').text();
            $dbContainer.append(`<div class="alfaai-status-item" id="status-db-${dbId}"><strong>${dbName}:</strong> <span>Verificando...</span></div>`);
            $.ajax({
                url: alfaai_admin.ajax_url, type: 'POST',
                data: { action: 'alfaai_test_external_db', db_id: dbId, _ajax_nonce: alfaai_admin.nonce },
                success: (res) => updateStatus(`status-db-${dbId}`, res.success, res.success ? 'Connesso' : 'Fallito'),
                error: () => updateStatus(`status-db-${dbId}`, false, 'Errore Chiamata')
            });
        });
    }
}

function updateMessageContent(messageId, newContent, attachments = null) {
    if (!messageId) return;

    const $messageBubble = $(`#${messageId}`).find('.alfaai-message-bubble');
    if ($messageBubble.length) {
        const processedContent = processMessageContent(newContent);
        $messageBubble.find('.alfaai-message-text').html(processedContent);

        // Rimuovi vecchi allegati e aggiungi i nuovi se presenti
        $messageBubble.find('.alfaai-attachments-container').remove();
        if (attachments) {
            $messageBubble.append(renderAttachments(attachments));
        }

        // Evidenzia i nuovi blocchi di codice se ce ne sono
        if (typeof hljs !== 'undefined') {
            $messageBubble.find('pre code').each(function(i, block) {
                hljs.highlightBlock(block);
            });
        }

        scrollToBottom();
    }
}
    function performAjaxRequest($button, data, successCallback) {
        const originalText = $button.html();
        $button.prop('disabled', true).html('Verifica...');
        data._ajax_nonce = alfaai_admin.nonce;
        $.ajax({
            url: alfaai_admin.ajax_url,
            type: 'POST',
            data: data,
            success: function(response) {
                const message = (response.data && response.data.message) ? response.data.message : 'Operazione completata.';
                if (response.success) {
                    alert('Successo: ' + message);
                    if (successCallback) successCallback(response);
                } else {
                    alert('Errore: ' + message);
                }
            },
            error: () => alert('Errore critico di connessione AJAX (403 o 500).'),
            complete: () => $button.prop('disabled', false).html(originalText)
        });
    }

    initializeAdmin();
    // Aggiungi un pulsante di test speciale
    if ($('.alfaai-admin-header').length) {
        $('.alfaai-admin-header').append('<button type="button" id="super-test-btn" class="alfaai-btn alfaai-btn-primary" style="margin-top: 15px;">Esegui Test di Connessione DB Esterno</button>');
        
        $('#super-test-btn').on('click', function() {
            const $button = $(this);
            const originalText = $button.text();
            $button.prop('disabled', true).text('Esecuzione...');

            $.ajax({
                url: alfaai_admin.ajax_url,
                type: 'POST',
                data: {
                    action: 'alfaai_super_test_db',
                    _ajax_nonce: alfaai_admin.nonce
                },
                success: function(response) {
                    if (response.success) {
                        alert('RISULTATO TEST:\n\n' + response.data.message);
                    } else {
                        alert('RISULTATO TEST:\n\n' + response.data.message);
                    }
                },
                error: function() {
                    alert('Errore AJAX. Impossibile eseguire il test.');
                },
                complete: function() {
                    $button.prop('disabled', false).text(originalText);
                }
            });
        });
    }
});