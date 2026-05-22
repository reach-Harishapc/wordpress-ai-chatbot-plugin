# 🔌 AightBot — WordPress AI Chatbot Plugin with RAG Support

A feature-rich, customizable **AI chatbot plugin for WordPress** with Retrieval-Augmented Generation (RAG), encrypted API key storage, session management, and a fully configurable admin panel. Built for [Ziegler Technical Solutions](https://ziegler.us).

---

## ✨ Features

- **AI-Powered Conversations** — Integrates with OpenAI API for intelligent chat responses
- **RAG Support** — Retrieval-Augmented Generation for context-aware answers using your site's content
- **Content Indexing** — Automatically indexes WordPress content for RAG queries
- **Encrypted API Keys** — Secure storage of API keys using OpenSSL encryption
- **Session Management** — Maintains conversation context across multiple messages
- **Admin Settings Panel** — Full configuration UI with general settings and RAG configuration
- **Frontend Chat Widget** — Embeddable chatbot widget with custom CSS styling
- **Logging System** — Built-in logger for debugging and monitoring
- **i18n Ready** — Internationalization support with text domain and language files
- **Clean Uninstall** — Removes all plugin data on uninstallation

## 🛠️ Tech Stack

| Layer       | Technology                         |
|-------------|------------------------------------|
| Platform    | WordPress (PHP)                    |
| AI          | OpenAI API                         |
| Security    | OpenSSL Encryption                 |
| Frontend    | HTML, CSS, JavaScript              |
| Database    | WordPress Options API              |
| Architecture| OOP with Singleton pattern         |

## 📁 Project Structure

```
wordpress-ai-plugin-2022/
├── aightbot.php                    # Main plugin file & bootstrap
├── index.php                       # Security index
├── uninstall.php                   # Clean uninstall handler
├── includes/
│   ├── class-admin-settings.php    # Admin panel settings
│   ├── class-api-handler.php       # OpenAI API integration
│   ├── class-content-indexer.php   # Content indexing for RAG
│   ├── class-encryption.php        # API key encryption
│   ├── class-frontend-widget.php   # Chat widget frontend
│   ├── class-install.php           # Activation/deactivation hooks
│   ├── class-logger.php            # Logging system
│   ├── class-rag-handler.php       # RAG query processing
│   └── class-session-manager.php   # Conversation session management
├── admin/
│   ├── css/admin-style.css         # Admin panel styles
│   ├── js/admin-script.js          # Admin panel scripts
│   └── partials/settings-page.php  # Settings page template
├── assets/
│   ├── css/widget-style.css        # Chat widget styles
│   └── js/widget-script.js         # Chat widget scripts
├── languages/                      # Translation files
└── .github/
    └── screenshots/                # Plugin screenshots
        ├── aightbot_chatbot.png
        ├── admin_general.png
        └── admin_rag.png
```

## 🚀 Installation

### From WordPress Admin

1. Download the plugin ZIP file
2. Go to **WordPress Admin → Plugins → Add New → Upload Plugin**
3. Upload the ZIP and click **Install Now**
4. Activate the plugin

### Manual Installation

1. Upload the `wordpress-ai-plugin-2022` folder to `/wp-content/plugins/`
2. Activate the plugin through the **Plugins** menu in WordPress

### Configuration

1. Go to **WordPress Admin → AightBot Settings**
2. Enter your OpenAI API key (stored encrypted)
3. Configure the chatbot behavior and appearance
4. Enable RAG and configure content indexing (optional)
5. Enable the chatbot to display the widget on your site

## 🔒 Security Features

- **Encrypted API Keys** — API keys are encrypted using OpenSSL before database storage
- **OpenSSL Requirement** — Plugin checks for the OpenSSL PHP extension on activation
- **Direct Access Prevention** — All PHP files check for `ABSPATH` constant
- **Clean Uninstall** — All plugin data is removed when the plugin is deleted

## 📸 Screenshots

| Admin Settings | RAG Configuration | Chat Widget |
|:-:|:-:|:-:|
| General settings panel | RAG setup & indexing | Frontend chatbot widget |

## ⚙️ Requirements

- WordPress 5.0+
- PHP 7.4+ with OpenSSL extension
- OpenAI API key

---
