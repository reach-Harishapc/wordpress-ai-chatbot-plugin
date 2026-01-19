/**
 * AightBot Frontend Widget JavaScript
 * Self-contained with built-in markdown parser (no external dependencies)
 */
(function($) {
    'use strict';
    
    const AightBot = {
        sessionId: null,
        isOpen: false,
        isLoading: false,
        
        /**
         * Initialize widget
         */
        init: function() {
            try {
                const savedSession = sessionStorage.getItem('aightbot_session_id');
                if (savedSession) {
                    this.sessionId = savedSession;
                }
                
                const savedMessages = sessionStorage.getItem('aightbot_messages');
                if (savedMessages) {
                    const messages = JSON.parse(savedMessages);
                    if (Array.isArray(messages)) {
                        messages.forEach(msg => {
                            if (msg.type && msg.content) {
                                this.addMessage(msg.content, msg.type);
                            }
                        });
                    }
                }
            } catch (e) {
            }
            
            this.bindEvents();
            
            const hasHistory = $('.aightbot-widget-messages').children().length > 0;
            if (!hasHistory) {
                this.showInitialGreeting();
            }
        },
        
        /**
         * Set session ID and persist to sessionStorage
         */
        setSessionId: function(sessionId) {
            this.sessionId = sessionId;
            if (sessionId) {
                try {
                    sessionStorage.setItem('aightbot_session_id', sessionId);
                } catch (e) {
                    // sessionStorage not available
                }
            } else {
                try {
                    sessionStorage.removeItem('aightbot_session_id');
                } catch (e) {
                    // sessionStorage not available
                }
            }
        },
        
        /**
         * Comprehensive markdown parser (built-in, no external dependencies)
         */
        parseMarkdown: function(text) {
            // Convert various citation and link formats BEFORE escaping HTML
            
            // Handle @cite(url) format -> convert to markdown link
            text = text.replace(/@cite\(([^)]+)\)/g, '[$1]($1)');
            
            // Handle Source: URL format -> convert to markdown link
            text = text.replace(/Source:\s*(https?:\/\/[^\s<>"]+)/gi, 'Source: [$1]($1)');
            text = text.replace(/URL:\s*(https?:\/\/[^\s<>"]+)/gi, 'URL: [$1]($1)');
            
            // Convert HTML anchor tags to markdown
            text = text.replace(/<a\s+href=["']([^"']+)["'][^>]*>([^<]+)<\/a>/gi, '[$2]($1)');
            
            // Escape HTML to prevent XSS
            let html = text
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
            
            // Code blocks (triple backticks) - must be done first
            html = html.replace(/```([^\n]*)\n([\s\S]*?)```/g, function(match, lang, code) {
                return '<pre><code>' + code.trim() + '</code></pre>';
            });
            
            // Auto-link plain URLs (before markdown link processing)
            // Match http(s):// URLs but not those in markdown link syntax
            // We'll mark markdown URLs first to protect them
            const markdownLinkPlaceholder = 'MARKDOWN_LINK_PLACEHOLDER_';
            let markdownLinks = [];
            
            // Temporarily replace markdown links with placeholders
            html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(match) {
                const index = markdownLinks.length;
                markdownLinks.push(match);
                return markdownLinkPlaceholder + index;
            });
            
            // Now auto-link plain URLs
            html = html.replace(/(^|[\s])(https?:\/\/[^\s<>"]+)/g, function(match, prefix, url) {
                // Clean up trailing punctuation
                let cleanUrl = url;
                const trailingPunc = /[.,;:!?)]+$/;
                const trailing = url.match(trailingPunc);
                if (trailing) {
                    cleanUrl = url.slice(0, -trailing[0].length);
                }
                
                // Check if it's an internal or external link
                const currentHost = window.location.hostname;
                let isInternal = false;
                try {
                    const linkHost = new URL(cleanUrl).hostname;
                    isInternal = linkHost === currentHost;
                } catch (e) {
                    isInternal = false;
                }
                
                if (isInternal) {
                    return prefix + '<a href="' + cleanUrl + '">' + cleanUrl + '</a>' + (trailing ? trailing[0] : '');
                } else {
                    return prefix + '<a href="' + cleanUrl + '" target="_blank" rel="noopener">' + cleanUrl + '</a>' + (trailing ? trailing[0] : '');
                }
            });
            
            // Restore markdown links
            html = html.replace(/MARKDOWN_LINK_PLACEHOLDER_(\d+)/g, function(match, index) {
                return markdownLinks[parseInt(index)];
            });
            
            // Tables
            const tableRegex = /^\|(.+)\|[ ]*$/gm;
            let tables = [];
            let tableMatch;
            while ((tableMatch = tableRegex.exec(html)) !== null) {
                tables.push({index: tableMatch.index, match: tableMatch[0]});
            }
            
            if (tables.length > 0) {
                // Group consecutive table rows
                let tableGroups = [];
                let currentGroup = [tables[0]];
                
                for (let i = 1; i < tables.length; i++) {
                    if (tables[i].index - (currentGroup[currentGroup.length - 1].index + currentGroup[currentGroup.length - 1].match.length) < 5) {
                        currentGroup.push(tables[i]);
                    } else {
                        tableGroups.push(currentGroup);
                        currentGroup = [tables[i]];
                    }
                }
                tableGroups.push(currentGroup);
                
                // Replace each table group
                for (let group of tableGroups.reverse()) {
                    let rows = group.map(t => t.match);
                    let tableHtml = '<table>';
                    
                    // Header row
                    let headerCells = rows[0].split('|').filter(c => c.trim());
                    tableHtml += '<thead><tr>';
                    headerCells.forEach(cell => {
                        tableHtml += '<th>' + cell.trim() + '</th>';
                    });
                    tableHtml += '</tr></thead>';
                    
                    // Data rows (skip separator if exists)
                    let dataStart = 1;
                    if (rows.length > 1 && rows[1].includes('---')) {
                        dataStart = 2;
                    }
                    
                    if (rows.length > dataStart) {
                        tableHtml += '<tbody>';
                        for (let i = dataStart; i < rows.length; i++) {
                            let cells = rows[i].split('|').filter(c => c.trim());
                            tableHtml += '<tr>';
                            cells.forEach(cell => {
                                tableHtml += '<td>' + cell.trim() + '</td>';
                            });
                            tableHtml += '</tr>';
                        }
                        tableHtml += '</tbody>';
                    }
                    
                    tableHtml += '</table>';
                    
                    // Replace in html
                    let fullTableText = rows.join('\n');
                    html = html.replace(fullTableText, tableHtml);
                }
            }
            
            // Headers (must be at start of line)
            html = html.replace(/^#### (.+)$/gm, '<h4>$1</h4>');
            html = html.replace(/^### (.+)$/gm, '<h3>$1</h3>');
            html = html.replace(/^## (.+)$/gm, '<h2>$1</h2>');
            html = html.replace(/^# (.+)$/gm, '<h1>$1</h1>');
            
            // Bold and italic (order matters)
            html = html.replace(/\*\*\*(.+?)\*\*\*/g, '<strong><em>$1</em></strong>');
            html = html.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
            html = html.replace(/\*(.+?)\*/g, '<em>$1</em>');
            html = html.replace(/___(.+?)___/g, '<strong><em>$1</em></strong>');
            html = html.replace(/__(.+?)__/g, '<strong>$1</strong>');
            html = html.replace(/_(.+?)_/g, '<em>$1</em>');
            
            // Inline code
            html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
            
            // Links - internal links same tab, external new tab
            html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function(match, text, url) {
                // Validate URL protocol
                var allowedProtocols = ['http:', 'https:', 'mailto:', 'tel:'];
                var currentHost = window.location.hostname;
                var isInternal = false;
                
                try {
                    if (url.startsWith('/') || url.startsWith('#') || url.startsWith('?')) {
                        isInternal = true;
                    } else if (url.startsWith('http://') || url.startsWith('https://')) {
                        var urlObj = new URL(url);
                        if (allowedProtocols.indexOf(urlObj.protocol) === -1) {
                            return text; // Invalid protocol, return plain text
                        }
                        isInternal = urlObj.hostname === currentHost;
                    } else if (url.startsWith('mailto:') || url.startsWith('tel:')) {
                        isInternal = false;
                    } else {
                        return text; // Unknown protocol, return plain text
                    }
                } catch (e) {
                    return text;
                }
                
                if (isInternal) {
                    return '<a href="' + url + '">' + text + '</a>';
                } else {
                    return '<a href="' + url + '" target="_blank" rel="noopener noreferrer">' + text + '</a>';
                }
            });
            
            // Blockquotes
            html = html.replace(/^&gt; (.+)$/gm, '<blockquote>$1</blockquote>');
            
            // Horizontal rules
            html = html.replace(/^---$/gm, '<hr>');
            html = html.replace(/^\*\*\*$/gm, '<hr>');
            
            // Lists - numbered (use unique marker first)
            html = html.replace(/^\d+\. (.+)$/gm, '<oli>$1</oli>');
            
            // Lists - bulleted (use unique marker first)
            html = html.replace(/^[-*+] (.+)$/gm, '<uli>$1</uli>');
            
            // Wrap ordered lists
            html = html.replace(/(<oli>.*?<\/oli>\n?)+/g, function(match) {
                return '<ol>' + match.replace(/<\/?oli>/g, function(tag) {
                    return tag === '<oli>' ? '<li>' : '</li>';
                }) + '</ol>';
            });
            
            // Wrap unordered lists
            html = html.replace(/(<uli>.*?<\/uli>\n?)+/g, function(match) {
                return '<ul>' + match.replace(/<\/?uli>/g, function(tag) {
                    return tag === '<uli>' ? '<li>' : '</li>';
                }) + '</ul>';
            });
            
            // Paragraphs and line breaks
            html = html.replace(/\n\n+/g, '</p><p>');
            html = html.replace(/\n/g, '<br>');
            
            // Wrap in paragraph if not already wrapped in block element
            if (!html.match(/^<(h[1-6]|p|ul|ol|table|pre|blockquote)/)) {
                html = '<p>' + html + '</p>';
            }
            
            return html;
        },
        
        /**
         * Show the initial greeting message
         */
        showInitialGreeting: function() {
            const greeting = aightbotWidget.starter_message || 
                `Hi! I'm ${aightbotWidget.bot_name}. How can I help you today?`;
            
            const greetingHtml = aightbotWidget.starter_message ? 
                this.parseMarkdown(greeting) : 
                this.escapeHtml(greeting);
            
            const $message = $(`
                <div class="aightbot-message aightbot-bot-message">
                    <div class="aightbot-message-content">${greetingHtml}</div>
                </div>
            `);
            
            $('.aightbot-widget-messages').append($message);
            this.saveChatHistory();
        },
        
        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Toggle chat window
            $(document).on('click', '.aightbot-widget-toggle', () => {
                this.toggleChat();
            });
            
            // Send message on form submit
            $(document).on('submit', '#aightbot-chat-form', (e) => {
                e.preventDefault();
                this.sendMessage();
            });
            
            // Send on Enter (but allow Shift+Enter for new line)
            $(document).on('keypress', '#aightbot-message-input', (e) => {
                if (e.which === 13 && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
            
            // New chat button
            $(document).on('click', '.aightbot-new-chat', () => {
                this.newChat();
            });
            
            // Close button
            $(document).on('click', '.aightbot-widget-close', () => {
                this.toggleChat();
            });
        },
        
        /**
         * Toggle chat window
         */
        toggleChat: function() {
            this.isOpen = !this.isOpen;
            
            if (this.isOpen) {
                $('.aightbot-widget').addClass('aightbot-open');
                $('#aightbot-message-input').focus();
            } else {
                $('.aightbot-widget').removeClass('aightbot-open');
            }
        },
        
        /**
         * Send message
         */
        sendMessage: function() {
            if (this.isLoading) return;
            
            const $input = $('#aightbot-message-input');
            const message = $input.val().trim();
            
            if (!message) return;
            
            // If no session yet, create one first
            if (!this.sessionId) {
                this.createSessionAndSend(message);
                $input.val('');
                return;
            }
            
            // Add user message
            this.addMessage(message, 'user');
            
            // Clear input
            $input.val('');
            
            // Show typing indicator
            this.showTyping();
            
            // Send to API
            this.isLoading = true;
            this.toggleSendButton(false);
            
            $.ajax({
                url: aightbotWidget.ajax_url,
                type: 'POST',
                data: {
                    action: 'aightbot_send_message',
                    nonce: aightbotWidget.nonce,
                    message: message,
                    session_id: this.sessionId
                },
                success: (response) => {
                    this.hideTyping();
                    
                    if (response.success) {
                        this.addMessage(response.data.message, 'bot');
                        this.setSessionId(response.data.session_id);
                    } else {
                        this.showError(response.data || aightbotWidget.strings.error);
                    }
                },
                error: () => {
                    this.hideTyping();
                    this.showError(aightbotWidget.strings.error);
                },
                complete: () => {
                    this.isLoading = false;
                    this.toggleSendButton(true);
                }
            });
        },
        
        /**
         * Create session then send message
         */
        createSessionAndSend: function(message) {
            this.addMessage(message, 'user');
            this.showTyping();
            this.isLoading = true;
            this.toggleSendButton(false);
            
            $.ajax({
                url: aightbotWidget.ajax_url,
                type: 'POST',
                data: {
                    action: 'aightbot_create_session',
                    nonce: aightbotWidget.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.setSessionId(response.data.session_id);
                        
                        // Now send the message
                        $.ajax({
                            url: aightbotWidget.ajax_url,
                            type: 'POST',
                            data: {
                                action: 'aightbot_send_message',
                                nonce: aightbotWidget.nonce,
                                message: message,
                                session_id: this.sessionId
                            },
                            success: (response) => {
                                this.hideTyping();
                                if (response.success) {
                                    this.addMessage(response.data.message, 'bot');
                                } else {
                                    this.showError(response.data || aightbotWidget.strings.error);
                                }
                            },
                            error: () => {
                                this.hideTyping();
                                this.showError(aightbotWidget.strings.error);
                            },
                            complete: () => {
                                this.isLoading = false;
                                this.toggleSendButton(true);
                            }
                        });
                    } else {
                        this.hideTyping();
                        this.showError('Failed to create session');
                        this.isLoading = false;
                        this.toggleSendButton(true);
                    }
                },
                error: () => {
                    this.hideTyping();
                    this.showError('Failed to create session');
                    this.isLoading = false;
                    this.toggleSendButton(true);
                }
            });
        },
        
        /**
         * Add message to chat
         */
        addMessage: function(text, type) {
            const messageClass = type === 'user' ? 'aightbot-user-message' : 'aightbot-bot-message';
            
            // For bot messages, parse markdown
            // For user messages, escape HTML
            const content = type === 'bot' ? this.parseMarkdown(text) : this.escapeHtml(text);
            
            const $message = $(`
                <div class="aightbot-message ${messageClass}">
                    <div class="aightbot-message-content">${content}</div>
                </div>
            `);
            
            $('.aightbot-widget-messages').append($message);
            this.scrollToBottom();
            this.saveChatHistory();
        },
        
        /**
         * Save chat history to sessionStorage
         */
        saveChatHistory: function() {
            try {
                const messages = [];
                $('.aightbot-widget-messages .aightbot-message').each(function() {
                    const $msg = $(this);
                    const type = $msg.hasClass('aightbot-user-message') ? 'user' : 'bot';
                    const content = $msg.find('.aightbot-message-content').text();
                    if (content) {
                        messages.push({ type: type, content: content });
                    }
                });
                sessionStorage.setItem('aightbot_messages', JSON.stringify(messages));
            } catch (e) {
            }
        },
        
        /**
         * Show typing indicator
         */
        showTyping: function() {
            const $typing = $(`
                <div class="aightbot-message aightbot-bot-message aightbot-typing">
                    <div class="aightbot-message-content">
                        <span></span><span></span><span></span>
                    </div>
                </div>
            `);
            
            $('.aightbot-widget-messages').append($typing);
            this.scrollToBottom();
        },
        
        /**
         * Hide typing indicator
         */
        hideTyping: function() {
            $('.aightbot-typing').remove();
        },
        
        /**
         * Show error message
         */
        showError: function(message) {
            const $error = $(`
                <div class="aightbot-message aightbot-bot-message aightbot-error">
                    <div class="aightbot-message-content">${this.escapeHtml(message)}</div>
                </div>
            `);
            
            $('.aightbot-widget-messages').append($error);
            this.scrollToBottom();
        },
        
        /**
         * Scroll to bottom of messages
         */
        scrollToBottom: function() {
            const $messages = $('.aightbot-widget-messages');
            $messages.scrollTop($messages[0].scrollHeight);
        },
        
        /**
         * Toggle send button state
         */
        toggleSendButton: function(enabled) {
            $('.aightbot-send-btn').prop('disabled', !enabled);
        },
        
        /**
         * Create new session
         */
        createSession: function() {
            $.ajax({
                url: aightbotWidget.ajax_url,
                type: 'POST',
                data: {
                    action: 'aightbot_create_session',
                    nonce: aightbotWidget.nonce
                },
                success: (response) => {
                    if (response.success) {
                        this.setSessionId(response.data.session_id);
                    }
                }
            });
        },
        
        /**
         * Start new chat
         */
        newChat: function() {
            if (confirm(aightbotWidget.strings.new_chat + '?')) {
                const greeting = aightbotWidget.starter_message || 
                    `Hi! I'm ${aightbotWidget.bot_name}. How can I help you today?`;
                
                const greetingHtml = aightbotWidget.starter_message ? 
                    this.parseMarkdown(greeting) : 
                    this.escapeHtml(greeting);
                
                $('.aightbot-widget-messages').html(
                    '<div class="aightbot-message aightbot-bot-message">' +
                    '<div class="aightbot-message-content">' +
                    greetingHtml +
                    '</div></div>'
                );
                
                this.setSessionId(null);
                this.saveChatHistory();
                $('#aightbot-message-input').focus();
            }
        },
        
        /**
         * Escape HTML
         */
        escapeHtml: function(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    };
    
    // Initialize on document ready
    $(document).ready(function() {
        AightBot.init();
    });
    
})(jQuery);
