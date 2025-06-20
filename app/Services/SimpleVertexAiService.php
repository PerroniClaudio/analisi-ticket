<?php

namespace App\Services;

use Google\Cloud\Storage\StorageClient;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Exception;

class SimpleVertexAiService {
    private $storageClient;
    private $projectId;
    private $location;
    private $accessToken;

    public function __construct() {
        $this->projectId = config('services.vertex_ai.project_id');
        $this->location = config('services.vertex_ai.location');

        $keyFilePath = config('services.vertex_ai.key_file_path', base_path('keys/service-account.json'));

        $this->storageClient = new StorageClient([
            'keyFilePath' => $keyFilePath
        ]);

        // Ottieni access token per le API REST
        $this->accessToken = $this->getAccessToken($keyFilePath);
    }

    /**
     * Funzione principale: analizza tutti i ticket dal bucket e restituisce stime
     */
    public function analyzeTicketsFromBucket(string $bucketName, string $filePath, string $modelName = 'gemini-1.5-flash'): array {
        try {
            // 1. Carica il file JSONL dal bucket
            $tickets = $this->loadTicketsFromBucket($bucketName, $filePath);

            Log::info("Caricati {count} ticket dal bucket", ['count' => count($tickets)]);

            // 2. Analizza ogni ticket con il modello pre-addestrato
            $results = [];

            foreach ($tickets as $index => $ticket) {
                $ticketId = $ticket['tid'] ?? $ticket['ticket_id'] ?? "TICKET-{$index}";

                try {
                    $estimatedMinutes = $this->estimateTicketTime($ticket, $modelName);

                    $results[] = [
                        'ticket_id' => $ticketId,
                        'estimated_minutes' => $estimatedMinutes,
                        'status' => 'success'
                    ];

                    Log::info("Ticket {ticket_id} analizzato: {minutes} minuti", [
                        'ticket_id' => $ticketId,
                        'minutes' => $estimatedMinutes
                    ]);
                } catch (Exception $e) {
                    $results[] = [
                        'ticket_id' => $ticketId,
                        'estimated_minutes' => null,
                        'status' => 'error',
                        'error' => $e->getMessage()
                    ];

                    Log::error("Errore nell'analisi del ticket {ticket_id}", [
                        'ticket_id' => $ticketId,
                        'error' => $e->getMessage()
                    ]);
                }

                // Pausa per evitare rate limiting
                usleep(100000); // 0.1 secondi
            }

            return $results;
        } catch (Exception $e) {
            Log::error('Errore nell\'analisi dei ticket dal bucket', [
                'bucket' => $bucketName,
                'file' => $filePath,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Carica i ticket dal bucket (per uso pubblico)
     */
    public function loadTicketsFromBucketPublic(string $bucketName, string $filePath): array {
        return $this->loadTicketsFromBucket($bucketName, $filePath);
    }

    /**
     * Stima il tempo di un singolo ticket (per uso pubblico)
     */
    public function estimateTicketTimePublic(array $ticket, string $modelName): int {
        return $this->estimateTicketTime($ticket, $modelName);
    }

    /**
     * Carica i ticket dal file JSONL nel bucket
     */
    private function loadTicketsFromBucket(string $bucketName, string $filePath): array {
        try {
            $bucket = $this->storageClient->bucket($bucketName);
            $object = $bucket->object($filePath);

            if (!$object->exists()) {
                throw new Exception("File non trovato: gs://{$bucketName}/{$filePath}");
            }

            $content = $object->downloadAsString();

            $tickets = [];
            $lines = explode("\n", trim($content));

            foreach ($lines as $lineNumber => $line) {
                $line = trim($line);
                if ($line) {
                    $ticketData = json_decode($line, true);
                    if ($ticketData) {
                        $tickets[] = $ticketData;
                    } else {
                        Log::warning("Riga {line} non è JSON valido", ['line' => $lineNumber + 1]);
                    }
                }
            }

            return $tickets;
        } catch (Exception $e) {
            throw new Exception("Errore nel caricamento del file dal bucket: " . $e->getMessage());
        }
    }

    /**
     * Stima il tempo di risoluzione per un singolo ticket usando Vertex AI
     */
    private function estimateTicketTime(array $ticket, string $modelName): int {
        // Prepara il prompt per il modello
        $prompt = $this->createPromptForTicket($ticket);

        // Chiama il modello pre-addestrato
        $response = $this->callVertexAiModel($prompt, $modelName);

        // Estrai i minuti dalla risposta
        return $this->extractMinutesFromResponse($response);
    }

    /**
     * Crea un prompt strutturato per il modello
     */
    private function createPromptForTicket(array $ticket): string {
        $subject = $ticket['obj'] ?? $ticket['subject'] ?? '';
        $type = $ticket['type'] ?? $ticket['ticket_type'] ?? '';
        $company = $ticket['azienda'] ?? $ticket['company_name'] ?? '';

        // 1. Unisci messaggi e aggiornamenti in un'unica timeline
        $timelineEvents = [];
        if (!empty($ticket['all_messages_json'])) {
            foreach ($ticket['all_messages_json'] as $msg) {
                $timelineEvents[] = [
                    'date' => $msg['date'] ?? '',
                    'text' => sprintf(
                        "[%s] Messaggio da %s: %s",
                        $msg['date'] ?? 'N/D',
                        $msg['author'] ?? 'Sconosciuto',
                        $msg['message'] ?? '' // Testo completo, senza substr!
                    ),
                ];
            }
        }
        if (!empty($ticket['all_updates_json'])) {
            foreach ($ticket['all_updates_json'] as $upd) {
                $timelineEvents[] = [
                    'date' => $upd['date'] ?? '',
                    'text' => sprintf(
                        "[%s] Aggiornamento di stato - %s: %s",
                        $upd['date'] ?? 'N/D',
                        $upd['type'] ?? 'Tipo sconosciuto',
                        $upd['value'] ?? '' // Valore completo, senza substr!
                    ),
                ];
            }
        }

        // 2. Ordina la timeline per data per coerenza cronologica
        usort($timelineEvents, function ($a, $b) {
            return strtotime($a['date']) <=> strtotime($b['date']);
        });

        // 3. Crea la stringa della cronologia completa
        $conversationChronology = '';
        foreach ($timelineEvents as $event) {
            $conversationChronology .= $event['text'] . "\n---\n";
        }

        // 4. Costruisci il prompt finale
        // Usiamo la sintassi HEREDOC per una migliore leggibilità
        return <<<PROMPT
            # RUOLO
            Sei un analista di supporto tecnico con grande esperienza, specializzato nel valutare la complessità dei ticket e stimare il tempo di lavoro necessario.

            # CONTESTO
            Ti fornirò la cronologia completa di un ticket di supporto. Il tuo compito è stimare il "tempo di lavoro effettivo" in minuti che un agente ha impiegato per risolverlo.
            Per "tempo di lavoro effettivo" si intende il tempo speso a leggere, capire, ricercare la soluzione e scrivere la risposta. Non include il tempo di attesa della risposta del cliente.

            # DATI DEL TICKET
            Oggetto: {$subject}
            Tipo: {$type}
            Azienda: {$company}

            # CRONOLOGIA COMPLETA DEL TICKET
            {$conversationChronology}

            # ISTRUZIONI FINALI
            1. Analizza tutta la cronologia per capire la complessità del problema e le azioni dell'agente.
            2. Considera il numero di interazioni e la natura tecnica della richiesta.
            3. Basandoti sulla tua analisi, fornisci la stima del tempo di lavoro effettivo.
            4. Rispondi SOLO e UNICAMENTE con un numero intero che rappresenta i minuti.
            5. Non aggiungere testo, spiegazioni, unità di misura (come "minuti") o qualsiasi altra parola.
            6. Se non sei sicuro, fornisci una stima ragionevole basata sulla tua esperienza.
            7. Il risultato deve essere un numero intero compreso tra 15 e 480 minuti (8 ore) e deve essere un multiplo di 10.
            STIMA IN MINUTI (solo numero intero):
        PROMPT;
    }

    /**
     * Chiama il modello pre-addestrato di Vertex AI via REST API
     */
    private function callVertexAiModel(string $prompt, string $modelName): string {
        // Per Gemini usa l'endpoint generateContent
        if (strpos($modelName, 'gemini') !== false) {
            return $this->callGeminiModel($prompt, $modelName);
        }

        // Per altri modelli (PaLM) usa l'endpoint predict
        $url = "https://{$this->location}-aiplatform.googleapis.com/v1/projects/{$this->projectId}/locations/{$this->location}/publishers/google/models/{$modelName}:predict";

        $payload = [
            'instances' => [
                [
                    'prompt' => $prompt
                ]
            ],
            'parameters' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 10,
                'topP' => 0.8,
                'topK' => 10
            ]
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/json'
        ])->timeout(30)->post($url, $payload);

        if (!$response->successful()) {
            throw new Exception("Errore chiamata Vertex AI: " . $response->body());
        }

        $data = $response->json();

        if (!isset($data['predictions'][0]['content'])) {
            throw new Exception("Risposta Vertex AI non valida: " . json_encode($data));
        }

        return $data['predictions'][0]['content'];
    }

    /**
     * Chiama specificamente i modelli Gemini
     */
    private function callGeminiModel(string $prompt, string $modelName): string {
        $url = "https://{$this->location}-aiplatform.googleapis.com/v1/projects/{$this->projectId}/locations/{$this->location}/publishers/google/models/{$modelName}:generateContent";

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        [
                            'text' => $prompt
                        ]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => 0.2,
                'maxOutputTokens' => 50,
                'topP' => 0.8,
                'topK' => 10
            ]
        ];

        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . $this->accessToken,
            'Content-Type' => 'application/json'
        ])->timeout(30)->post($url, $payload);

        if (!$response->successful()) {
            throw new Exception("Errore chiamata Gemini: " . $response->body());
        }

        $data = $response->json();

        if (!isset($data['candidates'][0]['content']['parts'][0]['text'])) {
            throw new Exception("Risposta Gemini non valida: " . json_encode($data));
        }

        return $data['candidates'][0]['content']['parts'][0]['text'];
    }

    /**
     * Estrae il numero di minuti dalla risposta del modello
     */
    private function extractMinutesFromResponse(string $response): int {
        // Cerca un numero nella risposta
        preg_match('/(\d+)/', $response, $matches);

        if (!empty($matches[1])) {
            $minutes = (int) $matches[1];

            // Limita a valori ragionevoli
            return max(15, min(480, $minutes));
        }

        // Fallback: stima basata sulla risposta testuale
        $response = strtolower($response);

        if (strpos($response, 'molto complesso') !== false || strpos($response, 'difficile') !== false) {
            return 120;
        } elseif (strpos($response, 'semplice') !== false || strpos($response, 'facile') !== false) {
            return 30;
        } else {
            return 60; // Default
        }
    }

    /**
     * Ottiene l'access token per le API di Google Cloud
     */
    private function getAccessToken(string $keyFilePath): string {
        try {
            $serviceAccount = json_decode(file_get_contents($keyFilePath), true);

            // Crea JWT per l'autenticazione
            $now = time();
            $payload = [
                'iss' => $serviceAccount['client_email'],
                'scope' => 'https://www.googleapis.com/auth/cloud-platform',
                'aud' => 'https://oauth2.googleapis.com/token',
                'iat' => $now,
                'exp' => $now + 3600
            ];

            // Per semplicità, uso il token del service account
            // In produzione, implementa JWT signing completo
            $response = Http::asForm()->post('https://oauth2.googleapis.com/token', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $this->createJWT($payload, $serviceAccount['private_key'])
            ]);

            if (!$response->successful()) {
                throw new Exception("Errore nell'ottenere l'access token");
            }

            return $response->json()['access_token'];
        } catch (Exception $e) {
            // Fallback: usa gcloud auth
            $command = 'gcloud auth print-access-token';
            $token = trim(shell_exec($command));

            if (empty($token)) {
                throw new Exception("Impossibile ottenere access token. Configura gcloud auth o il service account.");
            }

            return $token;
        }
    }

    /**
     * Crea un JWT semplificato (per demo - in produzione usa una libreria dedicata)
     */
    private function createJWT(array $payload, string $privateKey): string {
        // Header
        $header = json_encode(['typ' => 'JWT', 'alg' => 'RS256']);
        $headerEncoded = rtrim(strtr(base64_encode($header), '+/', '-_'), '=');

        // Payload
        $payloadEncoded = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');

        // Signature
        $signature = '';
        openssl_sign($headerEncoded . '.' . $payloadEncoded, $signature, $privateKey, OPENSSL_ALGO_SHA256);
        $signatureEncoded = rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        return $headerEncoded . '.' . $payloadEncoded . '.' . $signatureEncoded;
    }

    /**
     * Lista dei modelli disponibili
     */
    public function getAvailableModels(): array {
        return [
            'gemini-2.0-flash-lite-001' => 'Gemini 2.0 Flash (experimental, più veloce)',
            'gemini-1.5-flash' => 'Gemini 1.5 Flash (veloce, economico)',
            'gemini-1.5-pro' => 'Gemini 1.5 Pro (più potente)',
            'text-bison' => 'PaLM 2 Text Bison (text generation)',
            'chat-bison' => 'PaLM 2 Chat Bison (conversational)'
        ];
    }
}
