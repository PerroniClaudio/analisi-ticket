<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\TicketPredictionController;
use App\Http\Controllers\SimpleTicketAnalysisController;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/ticket-analysis', function () {
    return view('ticket-analysis');
})->name('ticket-analysis');

// API Routes per le predizioni dei ticket
Route::prefix('api/ticket-predictions')->group(function () {
    // Crea una nuova predizione
    Route::post('/predict', [TicketPredictionController::class, 'predictExecutionTime']);

    // Predizione con modello di testo (fallback)
    Route::post('/predict-text', [TicketPredictionController::class, 'predictWithTextModel']);

    // Predizione usando l'analisi del dataset dal bucket
    Route::post('/predict-dataset', [TicketPredictionController::class, 'predictWithDatasetAnalysis']);

    // Recupera predizioni per un ticket specifico
    Route::get('/ticket/{ticketId}', [TicketPredictionController::class, 'getTicketPredictions']);

    // Aggiorna il tempo effettivo di una predizione
    Route::put('/prediction/{predictionId}/actual-time', [TicketPredictionController::class, 'updateActualTime']);

    // Statistiche sulle predizioni
    Route::get('/statistics', [TicketPredictionController::class, 'getStatistics']);
});

// API SEMPLIFICATA per analisi ticket con Vertex AI
Route::prefix('api/simple-analysis')->group(function () {
    // Analizza tutti i ticket dal bucket
    Route::post('/analyze-bucket', [SimpleTicketAnalysisController::class, 'analyzeTicketsFromBucket']);

    // Analizza con job in background
    Route::post('/analyze-job', [SimpleTicketAnalysisController::class, 'analyzeTicketsJob']);

    // Analizza con streaming (Server-Sent Events)
    Route::get('/analyze-stream', [SimpleTicketAnalysisController::class, 'analyzeTicketsStream']);

    // Ottieni risultati dal database
    Route::get('/results', [SimpleTicketAnalysisController::class, 'getAnalysisResults']);

    // Ottieni statistiche dettagliate
    Route::get('/statistics', [SimpleTicketAnalysisController::class, 'getAnalysisStatistics']);

    // Lista modelli disponibili
    Route::get('/models', [SimpleTicketAnalysisController::class, 'getAvailableModels']);

    // Esporta risultati in CSV
    Route::post('/export-csv', [SimpleTicketAnalysisController::class, 'exportResults']);
});
