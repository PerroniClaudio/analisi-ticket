# 🎯 Analisi Ticket AI - Setup Guide

Questo è un sistema di analisi automatica dei ticket usando Vertex AI di Google Cloud con streaming in tempo reale.

## 📋 Prerequisiti

-   PHP 8.3+
-   Composer
-   Node.js e pnpm
-   Account Google Cloud con Vertex AI abilitato

## ⚙️ Configurazione

### 1. Clona il repository

```bash
git clone <your-repo-url>
cd analisi-ticket
```

### 2. Installa le dipendenze

```bash
# PHP dependencies
composer install

# JavaScript dependencies
pnpm install
```

### 3. Configura le variabili d'ambiente

```bash
# Copia il file di esempio
cp .env.example .env

# Modifica il file .env con i tuoi valori:
VERTEX_AI_PROJECT_ID=your-gcp-project-id
VERTEX_AI_BUCKET_NAME=your-bucket-name
VERTEX_AI_DATASET_PATH=your-dataset-file.jsonl
```

### 4. Configura Google Cloud

1. Scarica il file delle credenziali del service account da Google Cloud
2. Salvalo come `keys/service-account.json`
3. Assicurati che il service account abbia i permessi per:
    - Vertex AI (AI Platform Developer)
    - Cloud Storage (Storage Object Viewer)

### 5. Compila gli asset

```bash
pnpm run build
```

### 6. Avvia l'applicazione

```bash
php artisan serve
```

Vai su `https://analisi-ticket.test/ticket-analysis` per iniziare!

## 🚀 Funzionalità

-   **Streaming in tempo reale**: Visualizza i risultati mentre vengono elaborati
-   **Interfaccia moderna**: UI responsive con Tailwind CSS
-   **Esportazione CSV**: Scarica tutti i risultati
-   **Statistiche live**: Monitora il progresso in tempo reale
-   **Multi-modello**: Supporta diversi modelli Vertex AI

## 📁 Struttura del progetto

```
├── app/
│   ├── Http/Controllers/     # Controller per API e streaming
│   └── Services/            # Servizi per Vertex AI
├── resources/
│   ├── views/              # Template Blade
│   └── js/                # JavaScript per streaming
├── script/                # Script standalone PHP
└── keys/                 # Credenziali (escluse da git)
```

## 🔧 Configurazione Avanzata

Vedi i file di documentazione specifici:

-   `SIMPLE_ANALYSIS.md` - API di analisi semplificata
-   `VERTEX_AI_API.md` - Configurazione dettagliata Vertex AI

## 📝 Note di Sicurezza

-   Il file `keys/service-account.json` è escluso da git
-   Tutte le configurazioni sensibili sono gestite tramite variabili d'ambiente
-   Non committare mai credenziali nel codice
