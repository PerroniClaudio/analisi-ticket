# Analisi Ticket con Vertex AI - Guida Rapida

## Funzione Principale

Analizza i ticket dal file JSONL nel bucket Google Cloud Storage e restituisce stime di tempo in minuti per ogni ticket usando modelli pre-addestrati di Vertex AI.

## Configurazione Rapida

### 1. Variabili d'ambiente (.env)

```env
VERTEX_AI_PROJECT_ID=your-gcp-project-id
VERTEX_AI_LOCATION=europe-west8
VERTEX_AI_KEY_FILE_PATH=keys/service-account.json
```

### 2. Credenziali

-   Assicurati che `keys/service-account.json` sia presente
-   Oppure configura `gcloud auth` nel sistema

## Uso

### Metodo 1: Frontend Web (Raccomandato)

```
http://localhost/ticket-analysis
```

Interfaccia web con streaming in tempo reale che mostra i risultati man mano che vengono elaborati.

### Metodo 2: Script PHP con Streaming

```bash
php script/testStreamingAnalysis.php
```

### Metodo 3: Script PHP Tradizionale

```bash
php script/analyzeTickets.php
```

### Metodo 4: API REST Streaming

```bash
curl -N "http://localhost/api/simple-analysis/analyze-stream?bucket_name=your-bucket-name&file_path=your-dataset-file.jsonl&model=gemini-2.0-flash-exp"
```

## Streaming in Tempo Reale

Il sistema supporta **Server-Sent Events (SSE)** per mostrare i risultati in tempo reale:

### Vantaggi dello Streaming:

-   ✅ **Nessun timeout**: Elabora migliaia di ticket senza problemi
-   ✅ **Feedback immediato**: Vedi i risultati man mano che vengono elaborati
-   ✅ **Statistiche live**: Contatori e percentuali aggiornati in tempo reale
-   ✅ **Gestione errori**: Continua anche se alcuni ticket falliscono
-   ✅ **Progressione visiva**: Barra di progresso e stato per ogni ticket

### Eventi Streaming:

-   `init`: Inizializzazione
-   `progress`: Caricamento dati
-   `tickets_loaded`: Ticket caricati dal bucket
-   `processing`: Elaborazione ticket corrente
-   `result`: Risultato di un singolo ticket
-   `completed`: Analisi completata
-   `error`: Errore generale

## Modelli Disponibili

1. **gemini-1.5-flash** (Raccomandato)

    - Veloce ed economico
    - Buona qualità per stime di tempo

2. **gemini-1.5-pro**

    - Più potente ma più lento
    - Migliore per analisi complesse

3. **text-bison**

    - PaLM 2 per generazione testo
    - Buono per analisi strutturate

4. **chat-bison**
    - PaLM 2 conversazionale
    - Buono per interpretazione contesto

## Output

Lo script restituisce:

-   **ticket_id**: ID del ticket
-   **estimated_minutes**: Stima in minuti (15-480)
-   **status**: success/error

### File generati:

-   `ticket_analysis_results_[timestamp].json` - Risultati completi
-   `ticket_estimates_[timestamp].csv` - Solo ID e stime

## Esempio Output

```
=== STATISTICHE ===
Ticket totali: 150
Analisi riuscite: 147
Analisi fallite: 3
Tasso di successo: 98%
Tempo medio stimato: 52.3 minuti

=== PRIMI 10 RISULTATI ===
TICKET ID       MINUTI   STATUS
-----------------------------------
12345           45       success
12346           30       success
12347           120      success
```

## Personalizzazione

Per modificare il comportamento, edita `app/Services/SimpleVertexAiService.php`:

-   **Prompt**: Modifica `createPromptForTicket()` per cambiare come vengono analizzati i ticket
-   **Limiti**: Cambia min/max minuti in `extractMinutesFromResponse()`
-   **Modelli**: Aggiungi nuovi modelli in `getAvailableModels()`

## Troubleshooting

### Errore di autenticazione

```bash
gcloud auth application-default login
```

### Rate limiting

Il sistema include pause di 0.1 secondi tra le richieste. Per dataset grandi, considera di processare in batch.

### Timeout

Per dataset molto grandi, aumenta il timeout in `analyzeTickets.php`

## Costi

**Stima approssimativa per 1000 ticket:**

-   Gemini 1.5 Flash: ~$0.10-0.20
-   Gemini 1.5 Pro: ~$0.50-1.00
-   Text/Chat Bison: ~$0.30-0.60

I costi dipendono dalla lunghezza dei prompt e dalle risposte.
