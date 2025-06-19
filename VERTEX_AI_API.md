# API Predizione Tempi di Esecuzione Ticket

Questa API utilizza Google Cloud Vertex AI per stimare i tempi di esecuzione dei ticket di supporto.

## Configurazione

### 1. Variabili d'ambiente

Aggiungi queste variabili al tuo file `.env`:

```env
VERTEX_AI_PROJECT_ID=your-gcp-project-id
VERTEX_AI_LOCATION=europe-west8
VERTEX_AI_ENDPOINT_ID=your-endpoint-id-here
VERTEX_AI_KEY_FILE_PATH=keys/service-account.json
```

### 2. Credenziali Google Cloud

1. Assicurati che il file `keys/service-account.json` contenga le credenziali corrette per il tuo progetto Google Cloud
2. Il service account deve avere i permessi per Vertex AI

### 3. Endpoint Vertex AI

-   Se hai un modello personalizzato deployato su Vertex AI, usa l'endpoint ID specifico
-   Altrimenti, il sistema userà predizioni euristiche basate su regole

## Endpoints API

### 1. Crea Predizione

**POST** `/api/ticket-predictions/predict`

Predice il tempo di esecuzione usando Vertex AI.

**Payload:**

```json
{
    "ticket_id": "TICKET-001",
    "subject": "Problema configurazione server",
    "description": "Descrizione del problema",
    "software_description": "Dettagli tecnici aggiuntivi",
    "ticket_type": "tecnico",
    "channel": "email",
    "company_name": "Azienda SRL",
    "all_messages_json": [
        {
            "message": "Testo del messaggio",
            "timestamp": "2025-06-19 10:00:00"
        }
    ],
    "all_updates_json": [
        {
            "update": "Descrizione aggiornamento",
            "timestamp": "2025-06-19 10:00:00"
        }
    ]
}
```

**Risposta:**

```json
{
    "success": true,
    "data": {
        "prediction_id": 1,
        "ticket_id": "TICKET-001",
        "predicted_minutes": 45,
        "predicted_hours": 0.75,
        "confidence_score": 0.85,
        "prediction_quality": "high",
        "predicted_at": "2025-06-19T10:00:00.000000Z"
    },
    "message": "Predizione completata con successo"
}
```

### 2. Predizione con Modello di Testo (Fallback)

**POST** `/api/ticket-predictions/predict-text`

Usa predizioni euristiche quando Vertex AI non è disponibile.

**Payload:** Stesso della predizione normale

**Risposta:** Stessa struttura della predizione normale

### 3. Predizione con Analisi Dataset (RACCOMANDATO)

**POST** `/api/ticket-predictions/predict-dataset`

Utilizza il dataset esistente dal bucket Google Cloud Storage per trovare ticket simili e fare predizioni più accurate basate sui dati storici.

**Payload:** Stesso della predizione normale

**Risposta:**

```json
{
    "success": true,
    "data": {
        "prediction_id": 1,
        "ticket_id": "TICKET-001",
        "predicted_minutes": 52,
        "predicted_hours": 0.87,
        "confidence_score": 0.78,
        "prediction_quality": "medium",
        "predicted_at": "2025-06-19T10:00:00.000000Z",
        "method": "dataset_analysis",
        "similar_tickets_found": 7
    },
    "message": "Predizione completata con analisi dataset"
}
```

**Vantaggi di questo metodo:**

-   Usa i dati storici reali dal tuo bucket
-   Trova ticket simili basandosi su soggetto, descrizione, tipo e azienda
-   Calcola predizioni più accurate usando la media ponderata dei ticket simili
-   Confidence score più alto quando trova molti ticket simili

### 4. Recupera Predizioni per Ticket

**GET** `/api/ticket-predictions/ticket/{ticketId}`

Recupera tutte le predizioni per un ticket specifico.

**Risposta:**

```json
{
    "success": true,
    "data": [
        {
            "id": 1,
            "predicted_minutes": 45,
            "predicted_hours": 0.75,
            "confidence_score": 0.85,
            "prediction_quality": "high",
            "model_version": "vertex-ai-v1",
            "predicted_at": "2025-06-19T10:00:00.000000Z",
            "actual_minutes": null,
            "accuracy_score": null
        }
    ],
    "count": 1
}
```

### 5. Aggiorna Tempo Effettivo

**PUT** `/api/ticket-predictions/prediction/{predictionId}/actual-time`

Aggiorna il tempo effettivo per calcolare l'accuratezza.

**Payload:**

```json
{
    "actual_minutes": 60
}
```

**Risposta:**

```json
{
    "success": true,
    "data": {
        "prediction_id": 1,
        "predicted_minutes": 45,
        "actual_minutes": 60,
        "accuracy_score": 0.75,
        "accuracy_percentage": "75%"
    },
    "message": "Tempo effettivo aggiornato con successo"
}
```

### 6. Statistiche

**GET** `/api/ticket-predictions/statistics`

Recupera statistiche generali sulle predizioni.

**Risposta:**

```json
{
    "success": true,
    "data": {
        "total_predictions": 150,
        "predictions_with_actual_time": 120,
        "completion_rate": 80,
        "average_accuracy": 0.78,
        "average_accuracy_percentage": "78%",
        "average_predicted_minutes": 42.5,
        "average_actual_minutes": 48.2
    }
}
```

## Qualità delle Predizioni

Le predizioni sono classificate in base al confidence score:

-   **high** (0.8 - 1.0): Predizione molto affidabile
-   **medium** (0.6 - 0.79): Predizione moderatamente affidabile
-   **low** (0.0 - 0.59): Predizione poco affidabile

## Algoritmo Euristico

Quando Vertex AI non è disponibile, il sistema usa un algoritmo euristico che considera:

-   **Tempo base**: 30 minuti
-   **Complessità del problema**: Parole chiave che indicano maggiore complessità
-   **Numero di messaggi**: Più messaggi = più tempo stimato
-   **Limiti**: Tra 15 e 240 minuti

### Parole chiave di complessità:

-   errore: +15 min
-   installazione: +25 min
-   configurazione: +20 min
-   rete: +30 min
-   database: +35 min
-   server: +40 min
-   sicurezza: +45 min
-   backup: +20 min
-   ripristino: +50 min
-   migrazione: +60 min

## Test

Esegui il test con:

```bash
php script/testPredictionApi.php
```

## Integrazione nel tuo codice

```php
use App\Services\VertexAiService;
use App\Models\TicketPrediction;

$vertexAi = new VertexAiService();

$ticketData = [
    'ticket_id' => 'TICKET-001',
    'subject' => 'Problema server',
    // ... altri dati
];

$prediction = $vertexAi->predictExecutionTime($ticketData);

// Salva nel database
TicketPrediction::create([
    'ticket_id' => $ticketData['ticket_id'],
    'predicted_minutes' => $prediction['predicted_minutes'],
    'confidence_score' => $prediction['confidence_score'],
    // ... altri campi
]);
```

## Monitoraggio

-   I log sono salvati nei log di Laravel
-   Le metriche di accuratezza sono calcolate automaticamente
-   Usa le statistiche per monitorare le performance del modello
