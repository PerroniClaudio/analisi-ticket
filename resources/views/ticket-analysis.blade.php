<!DOCTYPE html>
<html lang="it">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Analisi Ticket con Vertex AI</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-gray-50 min-h-screen">
    <div id="app">
        <!-- Header -->
        <header class="bg-white shadow-sm border-b">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="flex items-center justify-between h-16">
                    <div class="flex items-center">
                        <div class="flex-shrink-0">
                            <h1 class="text-2xl font-bold text-gray-900">
                                🎯 Analisi Ticket AI
                            </h1>
                        </div>
                    </div>
                    <div class="flex items-center space-x-4">
                        <span class="text-sm text-gray-500">Powered by Vertex AI</span>
                    </div>
                </div>
            </div>
        </header>

        <!-- Main Content -->
        <main class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
            <!-- Configuration Card -->
            <div class="bg-white rounded-lg shadow-sm border mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">Configurazione Analisi</h2>
                    <p class="text-sm text-gray-600">Configura i parametri per l'analisi dei ticket con Vertex AI</p>
                </div>
                <div class="px-6 py-4">
                    <form id="analysisForm" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label for="bucketName" class="block text-sm font-medium text-gray-700 mb-1">
                                    Nome Bucket
                                </label>
                                <input type="text" id="bucketName" name="bucket_name"
                                    value="{{ config('services.vertex_ai.bucket_name') }}"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    placeholder="nome-bucket" />
                            </div>
                            <div>
                                <label for="filePath" class="block text-sm font-medium text-gray-700 mb-1">
                                    Percorso File JSONL
                                </label>
                                <input type="text" id="filePath" name="file_path"
                                    value="{{ config('services.vertex_ai.dataset_path') }}"
                                    class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500"
                                    placeholder="path/to/file.jsonl" />
                            </div>
                        </div>
                        <div>
                            <label for="model" class="block text-sm font-medium text-gray-700 mb-1">
                                Modello Vertex AI
                            </label>
                            <select id="model" name="model"
                                class="w-full rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500">
                                <option value="gemini-2.0-flash-lite-001">Gemini 2.0 Flash (Experimental, più veloce)
                                </option>
                                <option value="gemini-1.5-flash">Gemini 1.5 Flash (Veloce ed Economico)</option>
                                <option value="gemini-1.5-pro">Gemini 1.5 Pro (Più Potente)</option>
                                <option value="text-bison">PaLM 2 Text Bison</option>
                                <option value="chat-bison">PaLM 2 Chat Bison</option>
                            </select>
                        </div>
                        <div class="flex items-center justify-between">
                            <div class="flex space-x-3">
                                <button type="submit" id="analyzeBtn"
                                    class="bg-blue-600 hover:bg-blue-700 text-white font-medium py-2 px-4 rounded-md transition duration-200 flex items-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M9 5l7 7-7 7"></path>
                                    </svg>
                                    Analisi Streaming
                                </button>
                                <button type="button" id="analyzeJobBtn"
                                    class="bg-purple-600 hover:bg-purple-700 text-white font-medium py-2 px-4 rounded-md transition duration-200 flex items-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4">
                                        </path>
                                    </svg>
                                    Analisi Background
                                </button>
                                <button type="button" id="loadResultsBtn"
                                    class="bg-green-600 hover:bg-green-700 text-white font-medium py-2 px-4 rounded-md transition duration-200 flex items-center">
                                    <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15">
                                        </path>
                                    </svg>
                                    Carica Risultati DB
                                </button>
                            </div>
                            <button type="button" id="exportBtn"
                                class="bg-orange-600 hover:bg-orange-700 text-white font-medium py-2 px-4 rounded-md transition duration-200 items-center hidden">
                                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M12 10v6m0 0l-3-3m3 3l3-3m2 8H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z">
                                    </path>
                                </svg>
                                Esporta CSV
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Loading State con Progresso Streaming -->
            <div id="loadingState" class="hidden">
                <div class="bg-blue-50 border border-blue-200 rounded-lg p-6">
                    <div class="flex items-center justify-center mb-4">
                        <svg class="animate-spin h-8 w-8 text-blue-600" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor"
                                stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor"
                                d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z">
                            </path>
                        </svg>
                    </div>
                    <h3 class="text-lg font-medium text-blue-900 mb-2">Analisi in corso...</h3>
                    <p class="text-blue-700 mb-4">Stiamo processando i ticket con Vertex AI in tempo reale.</p>

                    <!-- Progress Bar -->
                    <div id="progressContainer" class="hidden mb-4">
                        <div class="flex justify-between text-sm text-blue-600 mb-2">
                            <span id="progressLabel">Elaborazione...</span>
                            <span id="progressPercentage">0%</span>
                        </div>
                        <div class="w-full bg-blue-200 rounded-full h-2">
                            <div id="progressBar" class="bg-blue-600 h-2 rounded-full transition-all duration-300"
                                style="width: 0%"></div>
                        </div>
                        <div class="flex justify-between text-xs text-blue-500 mt-1">
                            <span id="progressCurrent">0</span>
                            <span id="progressTotal">0</span>
                        </div>
                    </div>

                    <div class="mt-4">
                        <div class="text-sm text-blue-600" id="progressText">Inizializzazione...</div>
                    </div>

                    <!-- Live Stats durante elaborazione -->
                    <div id="liveStats" class="hidden mt-4 gap-4 text-center">
                        <div class="bg-white rounded-lg p-3 shadow-sm">
                            <div class="text-lg font-bold text-green-600" id="liveSuccessful">0</div>
                            <div class="text-xs text-gray-600">Successi</div>
                        </div>
                        <div class="bg-white rounded-lg p-3 shadow-sm">
                            <div class="text-lg font-bold text-red-600" id="liveFailed">0</div>
                            <div class="text-xs text-gray-600">Errori</div>
                        </div>
                        <div class="bg-white rounded-lg p-3 shadow-sm">
                            <div class="text-lg font-bold text-purple-600" id="liveAvgTime">0</div>
                            <div class="text-xs text-gray-600">Min Medi</div>
                        </div>
                        <div class="bg-white rounded-lg p-3 shadow-sm">
                            <div class="text-lg font-bold text-blue-600" id="liveProcessingTime">-</div>
                            <div class="text-xs text-gray-600">Tempo Trascorso</div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Live Results Preview durante streaming -->
            <div id="liveResultsCard" class="hidden bg-white rounded-lg shadow-sm border mb-6">
                <div class="px-6 py-4 border-b border-gray-200">
                    <h2 class="text-lg font-semibold text-gray-900">📡 Risultati in Tempo Reale</h2>
                    <p class="text-sm text-gray-600">Ultimi ticket elaborati</p>
                </div>
                <div class="px-6 py-4">
                    <div id="liveResultsList" class="space-y-2 max-h-64 overflow-y-auto">
                        <!-- I risultati live verranno aggiunti qui -->
                    </div>
                </div>
            </div>

            <!-- Results Table -->
            <div id="resultsCard" class="hidden bg-white rounded-lg shadow-sm border">
                <div class="px-6 py-4 border-b border-gray-200 flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-semibold text-gray-900">Risultati Analisi</h2>
                        <p class="text-sm text-gray-600">Stime di tempo per ciascun ticket</p>
                    </div>
                    <div class="flex items-center space-x-2">
                        <input type="text" id="searchInput" placeholder="Cerca ticket..."
                            class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm" />
                        <select id="filterStatus"
                            class="rounded-md border-gray-300 shadow-sm focus:border-blue-500 focus:ring-blue-500 text-sm">
                            <option value="">Tutti gli stati</option>
                            <option value="success">Successo</option>
                            <option value="error">Errore</option>
                        </select>
                    </div>
                </div>
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200">
                        <thead class="bg-gray-50">
                            <tr>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Ticket ID
                                </th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Minuti Stimati
                                </th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Ore Stimate
                                </th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Stato
                                </th>
                                <th
                                    class="px-6 py-3 text-left text-xs font-medium text-gray-500 uppercase tracking-wider">
                                    Errore
                                </th>
                            </tr>
                        </thead>
                        <tbody id="resultsTableBody" class="bg-white divide-y divide-gray-200">
                            <!-- Results will be populated here -->
                        </tbody>
                    </table>
                </div>
                <div class="px-6 py-4 border-t border-gray-200">
                    <div class="flex items-center justify-between">
                        <div class="text-sm text-gray-600">
                            Mostrando <span id="showingCount">0</span> di <span id="totalCount">0</span> risultati
                        </div>
                        <div class="flex items-center space-x-2">
                            <button id="prevPage"
                                class="px-3 py-1 border border-gray-300 rounded-md text-sm hover:bg-gray-50">
                                Precedente
                            </button>
                            <span id="pageInfo" class="text-sm text-gray-600">Pagina 1 di 1</span>
                            <button id="nextPage"
                                class="px-3 py-1 border border-gray-300 rounded-md text-sm hover:bg-gray-50">
                                Successiva
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Error State -->
            <div id="errorState" class="hidden">
                <div class="bg-red-50 border border-red-200 rounded-lg p-6">
                    <div class="flex items-center mb-4">
                        <svg class="w-6 h-6 text-red-600 mr-2" fill="none" stroke="currentColor"
                            viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.996-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z">
                            </path>
                        </svg>
                        <h3 class="text-lg font-medium text-red-900">Errore nell'Analisi</h3>
                    </div>
                    <p class="text-red-700" id="errorMessage">Si è verificato un errore durante l'analisi dei ticket.
                    </p>
                </div>
            </div>
        </main>
    </div>

</body>

</html>
