/**
 * Sistema di Analisi Ticket con Streaming in Tempo Reale
 * Gestisce la connessione Se        // Export button
        this.exportBtn.addEventListener('click', () => {
            this.exportResults();
        });

        // Analyze job button
        this.        // Mostra le statistiche live
        this.liveStats.classList.remove('hidden');
                this.exportBtn.classList.remove('hidden');
        this.exportBtn.classList.add('flex');his.liveStats.classList.add('grid', 'grid-cols-4');alyzeJobBtn.addEventListener('click', () => {
            this.startJobAnalysis();
        });

        // Load results button
        this.loadResultsBtn.addEventListener('click', () => {
            this.loadDatabaseResults();
        });-Sent Events per ricevere dati in streaming
 */

class TicketAnalysisManager {
    constructor() {
        this.eventSource = null;
        this.isAnalyzing = false;
        this.currentResults = [];
        this.currentPage = 1;
        this.itemsPerPage = 10;
        this.filteredResults = [];
        this.startTime = null;
        this.processingTimer = null;

        this.initializeElements();
        this.bindEvents();
        this.setupCSRF();
    }

    initializeElements() {
        // Form elements
        this.form = document.getElementById("analysisForm");
        this.analyzeBtn = document.getElementById("analyzeBtn");
        this.analyzeJobBtn = document.getElementById("analyzeJobBtn");
        this.loadResultsBtn = document.getElementById("loadResultsBtn");
        this.exportBtn = document.getElementById("exportBtn");

        // Input elements
        this.bucketNameInput = document.getElementById("bucketName");
        this.filePathInput = document.getElementById("filePath");
        this.modelSelect = document.getElementById("model");

        // State containers
        this.loadingState = document.getElementById("loadingState");
        this.resultsCard = document.getElementById("resultsCard");
        this.errorState = document.getElementById("errorState");
        this.liveResultsCard = document.getElementById("liveResultsCard");

        // Progress elements
        this.progressContainer = document.getElementById("progressContainer");
        this.progressBar = document.getElementById("progressBar");
        this.progressLabel = document.getElementById("progressLabel");
        this.progressPercentage = document.getElementById("progressPercentage");
        this.progressCurrent = document.getElementById("progressCurrent");
        this.progressTotal = document.getElementById("progressTotal");
        this.progressText = document.getElementById("progressText");

        // Live stats
        this.liveStats = document.getElementById("liveStats");
        this.liveSuccessful = document.getElementById("liveSuccessful");
        this.liveFailed = document.getElementById("liveFailed");
        this.liveAvgTime = document.getElementById("liveAvgTime");
        this.liveProcessingTime = document.getElementById("liveProcessingTime");

        // Live results
        this.liveResultsList = document.getElementById("liveResultsList");

        // Results table
        this.resultsTableBody = document.getElementById("resultsTableBody");
        this.searchInput = document.getElementById("searchInput");
        this.filterStatus = document.getElementById("filterStatus");

        // Pagination
        this.showingCount = document.getElementById("showingCount");
        this.totalCount = document.getElementById("totalCount");
        this.pageInfo = document.getElementById("pageInfo");
        this.prevPage = document.getElementById("prevPage");
        this.nextPage = document.getElementById("nextPage");

        // Error message
        this.errorMessage = document.getElementById("errorMessage");
    }

    setupCSRF() {
        // Configura il token CSRF per le richieste
        const token = document.querySelector('meta[name="csrf-token"]');
        if (token) {
            window.axios.defaults.headers.common["X-CSRF-TOKEN"] =
                token.getAttribute("content");
        }
    }

    bindEvents() {
        // Form submission
        this.form.addEventListener("submit", (e) => {
            e.preventDefault();
            this.startStreamingAnalysis();
        });

        // Export button
        this.exportBtn.addEventListener("click", () => {
            this.exportResults();
        });

        // Analyze job button
        this.analyzeJobBtn.addEventListener("click", () => {
            this.startJobAnalysis();
        });

        // Load results button
        this.loadResultsBtn.addEventListener("click", () => {
            this.loadDatabaseResults();
        });

        // Search and filter
        this.searchInput.addEventListener("input", () => {
            this.filterResults();
        });

        this.filterStatus.addEventListener("change", () => {
            this.filterResults();
        });

        // Pagination
        this.prevPage.addEventListener("click", () => {
            if (this.currentPage > 1) {
                this.currentPage--;
                this.renderResultsTable();
            }
        });

        this.nextPage.addEventListener("click", () => {
            const totalPages = Math.ceil(
                this.filteredResults.length / this.itemsPerPage
            );
            if (this.currentPage < totalPages) {
                this.currentPage++;
                this.renderResultsTable();
            }
        });

        // Gestisci la chiusura della pagina
        window.addEventListener("beforeunload", () => {
            this.cleanup();
        });

        // Gestisci la visibilità della pagina
        document.addEventListener("visibilitychange", () => {
            if (document.hidden && this.eventSource) {
                console.log("Pagina nascosta, mantengo la connessione attiva");
            }
        });
    }

    async startStreamingAnalysis() {
        if (this.isAnalyzing) {
            console.warn("Analisi già in corso");
            return;
        }

        this.isAnalyzing = true;
        this.currentResults = [];
        this.startTime = Date.now();
        this.resetUI();
        this.showLoadingState();
        this.startProcessingTimer();

        const formData = new FormData(this.form);
        const params = new URLSearchParams(formData);

        try {
            // Inizializza EventSource per lo streaming
            this.eventSource = new EventSource(
                `/api/simple-analysis/analyze-stream?${params}`,
                {
                    withCredentials: true,
                }
            );

            this.eventSource.addEventListener("message", (event) => {
                this.handleStreamMessage(event);
            });

            this.eventSource.addEventListener("error", (event) => {
                console.error("Errore EventSource:", event);
                this.handleStreamError("Errore nella connessione streaming");
            });
        } catch (error) {
            console.error("Errore nell'avvio dello streaming:", error);
            this.handleStreamError(error.message);
        }
    }

    handleStreamMessage(event) {
        try {
            const data = JSON.parse(event.data);
            console.log("Messaggio ricevuto:", data);

            switch (data.type) {
                case "init":
                    this.updateProgressText(data.message);
                    break;

                case "progress":
                    this.updateProgressText(data.message);
                    break;

                case "tickets_loaded":
                    this.initializeProgress(data.total);
                    this.updateProgressText(data.message);
                    break;

                case "processing":
                    this.updateProgress(data.current, data.total, data.message);
                    break;

                case "result":
                    this.handleNewResult(data.result, data.statistics);
                    break;

                case "completed":
                    this.handleAnalysisComplete(data.results, data.statistics);
                    break;

                case "error":
                    this.handleStreamError(data.message);
                    break;

                default:
                    console.warn("Tipo di messaggio sconosciuto:", data.type);
            }
        } catch (error) {
            console.error("Errore nel parsing del messaggio:", error);
        }
    }

    handleNewResult(result, statistics) {
        // Aggiungi il risultato alla lista
        this.currentResults.push(result);

        // Aggiorna le statistiche live
        this.updateLiveStats(statistics);

        // Aggiungi il risultato alla lista live
        this.addLiveResult(result);

        // Aggiorna il filtro e la tabella
        this.filterResults();
    }

    updateLiveStats(stats) {
        this.liveSuccessful.textContent = stats.successful;
        this.liveFailed.textContent = stats.failed;

        // Calcola tempo medio
        const successfulResults = this.currentResults.filter(
            (r) => r.status === "success"
        );
        const avgMinutes =
            successfulResults.length > 0
                ? successfulResults.reduce(
                      (sum, r) => sum + r.estimated_minutes,
                      0
                  ) / successfulResults.length
                : 0;

        this.liveAvgTime.textContent = Math.round(avgMinutes);

        // Mostra le statistiche live
        this.liveStats.classList.remove("hidden");
    }

    addLiveResult(result) {
        // Mostra la card dei risultati live
        this.liveResultsCard.classList.remove("hidden");

        // Crea elemento per il nuovo risultato
        const resultElement = document.createElement("div");
        resultElement.className = `p-3 rounded-lg border-l-4 ${
            result.status === "success"
                ? "bg-green-50 border-green-400"
                : "bg-red-50 border-red-400"
        }`;

        resultElement.innerHTML = `
            <div class="flex items-center justify-between">
                <div class="flex items-center space-x-3">
                    <div class="flex-shrink-0">
                        ${
                            result.status === "success"
                                ? '<div class="w-3 h-3 bg-green-400 rounded-full"></div>'
                                : '<div class="w-3 h-3 bg-red-400 rounded-full"></div>'
                        }
                    </div>
                    <div>
                        <div class="font-medium text-gray-900">${
                            result.ticket_id
                        }</div>
                        ${
                            result.status === "success"
                                ? `<div class="text-sm text-gray-600">${
                                      result.estimated_minutes
                                  } minuti (${(
                                      result.estimated_minutes / 60
                                  ).toFixed(1)} ore)</div>`
                                : `<div class="text-sm text-red-600">${result.error}</div>`
                        }
                    </div>
                </div>
                <div class="text-xs text-gray-500">
                    ${new Date().toLocaleTimeString()}
                </div>
            </div>
        `;

        // Aggiungi in cima alla lista
        this.liveResultsList.insertBefore(
            resultElement,
            this.liveResultsList.firstChild
        );

        // Mantieni solo gli ultimi 10 risultati visibili
        while (this.liveResultsList.children.length > 10) {
            this.liveResultsList.removeChild(this.liveResultsList.lastChild);
        }
    }

    initializeProgress(total) {
        this.progressContainer.classList.remove("hidden");
        this.progressTotal.textContent = total;
    }

    updateProgress(current, total, message) {
        const percentage = Math.round((current / total) * 100);

        this.progressBar.style.width = `${percentage}%`;
        this.progressPercentage.textContent = `${percentage}%`;
        this.progressCurrent.textContent = current;
        this.progressLabel.textContent = message;
        this.updateProgressText(message);
    }

    updateProgressText(message) {
        this.progressText.textContent = message;
    }

    handleAnalysisComplete(results, statistics) {
        console.log("Analisi completata:", { results, statistics });

        this.currentResults = results;
        this.isAnalyzing = false;
        this.stopProcessingTimer();

        // Chiudi la connessione
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }

        // Nascondi loading e mostra risultati
        this.loadingState.classList.add("hidden");
        this.liveResultsCard.classList.add("hidden");
        this.resultsCard.classList.remove("hidden");
        this.exportBtn.classList.remove("hidden");

        // Aggiorna la UI
        this.filterResults();
        this.updateAnalyzeButton();

        // Mostra notifica di completamento
        this.showCompletionNotification(statistics);
    }

    handleStreamError(errorMessage) {
        console.error("Errore nello streaming:", errorMessage);

        this.isAnalyzing = false;
        this.stopProcessingTimer();

        // Chiudi la connessione
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }

        // Mostra errore
        this.showErrorState(errorMessage);
        this.updateAnalyzeButton();
    }

    showCompletionNotification(statistics) {
        // Crea e mostra una notifica toast
        const notification = document.createElement("div");
        notification.className =
            "fixed top-4 right-4 bg-green-500 text-white px-6 py-3 rounded-lg shadow-lg z-50 transform transition-all duration-300";
        notification.innerHTML = `
            <div class="flex items-center space-x-2">
                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                </svg>
                <div>
                    <div class="font-medium">Analisi completata!</div>
                    <div class="text-sm opacity-90">
                        ${statistics.successful_analyses}/${statistics.total_tickets} ticket analizzati con successo
                    </div>
                </div>
            </div>
        `;

        document.body.appendChild(notification);

        // Rimuovi dopo 5 secondi
        setTimeout(() => {
            notification.style.transform = "translateX(100%)";
            setTimeout(() => {
                document.body.removeChild(notification);
            }, 300);
        }, 5000);
    }

    filterResults() {
        const searchTerm = this.searchInput.value.toLowerCase();
        const statusFilter = this.filterStatus.value;

        this.filteredResults = this.currentResults.filter((result) => {
            const matchesSearch = result.ticket_id
                .toLowerCase()
                .includes(searchTerm);
            const matchesStatus =
                !statusFilter || result.status === statusFilter;
            return matchesSearch && matchesStatus;
        });

        this.currentPage = 1;
        this.renderResultsTable();
    }

    renderResultsTable() {
        const startIndex = (this.currentPage - 1) * this.itemsPerPage;
        const endIndex = startIndex + this.itemsPerPage;
        const pageResults = this.filteredResults.slice(startIndex, endIndex);

        this.resultsTableBody.innerHTML = "";

        pageResults.forEach((result) => {
            const row = document.createElement("tr");
            row.className = "hover:bg-gray-50";

            const statusClass =
                result.status === "success" ? "text-green-600" : "text-red-600";
            const statusText =
                result.status === "success" ? "Successo" : "Errore";

            row.innerHTML = `
                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900">
                    ${result.ticket_id}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    ${result.estimated_minutes || "-"}
                </td>
                <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500">
                    ${
                        result.estimated_minutes
                            ? (result.estimated_minutes / 60).toFixed(2)
                            : "-"
                    }
                </td>
                <td class="px-6 py-4 whitespace-nowrap">
                    <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full ${
                        result.status === "success"
                            ? "bg-green-100 text-green-800"
                            : "bg-red-100 text-red-800"
                    }">
                        ${statusText}
                    </span>
                </td>
                <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate">
                    ${result.error || "-"}
                </td>
            `;

            this.resultsTableBody.appendChild(row);
        });

        this.updatePaginationInfo();
    }

    updatePaginationInfo() {
        const totalPages = Math.ceil(
            this.filteredResults.length / this.itemsPerPage
        );
        const startItem = (this.currentPage - 1) * this.itemsPerPage + 1;
        const endItem = Math.min(
            this.currentPage * this.itemsPerPage,
            this.filteredResults.length
        );

        this.showingCount.textContent = this.filteredResults.length;
        this.totalCount.textContent = this.currentResults.length;
        this.pageInfo.textContent = `Pagina ${this.currentPage} di ${totalPages}`;

        this.prevPage.disabled = this.currentPage === 1;
        this.nextPage.disabled = this.currentPage === totalPages;

        // Update button styles
        this.prevPage.className = `px-3 py-1 border border-gray-300 rounded-md text-sm ${
            this.currentPage === 1
                ? "bg-gray-100 text-gray-400 cursor-not-allowed"
                : "hover:bg-gray-50"
        }`;

        this.nextPage.className = `px-3 py-1 border border-gray-300 rounded-md text-sm ${
            this.currentPage === totalPages
                ? "bg-gray-100 text-gray-400 cursor-not-allowed"
                : "hover:bg-gray-50"
        }`;
    }

    async exportResults() {
        if (!this.currentResults.length) {
            alert("Nessun risultato da esportare");
            return;
        }

        try {
            const response = await window.axios.post(
                "/api/simple-analysis/export-csv",
                {
                    results: this.currentResults,
                }
            );

            if (response.data.success) {
                // Crea e scarica il file CSV
                const blob = new Blob([response.data.csv_data], {
                    type: "text/csv",
                });
                const url = window.URL.createObjectURL(blob);
                const a = document.createElement("a");
                a.href = url;
                a.download = response.data.filename;
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
                window.URL.revokeObjectURL(url);
            } else {
                alert("Errore nell'esportazione: " + response.data.message);
            }
        } catch (error) {
            console.error("Errore nell'esportazione:", error);
            alert("Errore nell'esportazione");
        }
    }

    resetUI() {
        this.hideAllStates();
        this.progressContainer.classList.add("hidden");
        this.liveStats.classList.add("hidden");
        this.liveResultsCard.classList.add("hidden");
        this.exportBtn.classList.add("hidden");
        this.liveResultsList.innerHTML = "";
    }

    showLoadingState() {
        this.loadingState.classList.remove("hidden");
        this.updateAnalyzeButton();
    }

    showErrorState(message) {
        this.errorState.classList.remove("hidden");
        this.loadingState.classList.add("hidden");
        this.errorMessage.textContent = message;
    }

    hideAllStates() {
        this.loadingState.classList.add("hidden");
        this.resultsCard.classList.add("hidden");
        this.errorState.classList.add("hidden");
    }

    updateAnalyzeButton() {
        if (this.isAnalyzing) {
            this.analyzeBtn.disabled = true;
            this.analyzeBtn.innerHTML = `
                <svg class="animate-spin w-5 h-5 mr-2" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
                Analisi in corso...
            `;
        } else {
            this.analyzeBtn.disabled = false;
            this.analyzeBtn.innerHTML = `
                <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
                Avvia Analisi
            `;
        }
    }

    startProcessingTimer() {
        this.processingTimer = setInterval(() => {
            if (this.startTime && this.liveProcessingTime) {
                const elapsed = Date.now() - this.startTime;
                const minutes = Math.floor(elapsed / 60000);
                const seconds = Math.floor((elapsed % 60000) / 1000);
                this.liveProcessingTime.textContent = `${minutes}:${seconds
                    .toString()
                    .padStart(2, "0")}`;
            }
        }, 1000);
    }

    stopProcessingTimer() {
        if (this.processingTimer) {
            clearInterval(this.processingTimer);
            this.processingTimer = null;
        }
    }

    cleanup() {
        this.stopProcessingTimer();
        if (this.eventSource) {
            this.eventSource.close();
            this.eventSource = null;
        }
        this.isAnalyzing = false;
    }

    async startJobAnalysis() {
        const formData = new FormData(this.form);

        try {
            this.showJobStartedState();

            const response = await window.axios.post(
                "https://analisi-ticket.test/api/simple-analysis/analyze-job",
                formData
            );

            if (response.data.success) {
                this.showJobStartedSuccess(response.data.data);
            } else {
                this.handleJobError(response.data.message);
            }
        } catch (error) {
            console.error("Errore nell'avvio del job:", error);
            this.handleJobError(error.response?.data?.message || error.message);
        }
    }

    async loadDatabaseResults() {
        try {
            this.showLoadingResultsState();

            const response = await window.axios.get(
                "https://analisi-ticket.test/api/simple-analysis/results"
            );

            if (response.data.success) {
                this.handleDatabaseResults(response.data.data);
            } else {
                this.handleJobError(response.data.message);
            }
        } catch (error) {
            console.error("Errore nel caricamento risultati:", error);
            this.handleJobError(error.response?.data?.message || error.message);
        }
    }

    showJobStartedState() {
        this.hideAllStates();

        const notification = this.createNotification(
            "info",
            "Job Avviato",
            "L'analisi è stata avviata in background. I risultati saranno salvati nel database.",
            5000
        );

        document.body.appendChild(notification);
    }

    showJobStartedSuccess(data) {
        const notification = this.createNotification(
            "success",
            "Job Avviato con Successo",
            `Batch ID: ${data.batch_id}. Controlla i log per il progresso.`,
            7000
        );

        document.body.appendChild(notification);
    }

    showLoadingResultsState() {
        this.hideAllStates();
        this.loadingState.classList.remove("hidden");
        this.updateProgressText("Caricamento risultati dal database...");
    }

    handleDatabaseResults(data) {
        this.currentResults = data.results.map((result) => ({
            ticket_id: result.ticket_id,
            estimated_minutes: result.predicted_minutes,
            status: result.status === "processed" ? "success" : "error",
            error: result.error_message,
        }));

        this.hideAllStates();
        this.resultsCard.classList.remove("hidden");
        this.exportBtn.classList.remove("hidden");

        this.filterResults();

        // Mostra notifica con statistiche
        const stats = data.statistics;
        const notification = this.createNotification(
            "success",
            "Risultati Caricati",
            `${stats.total} ticket trovati (${stats.processed} processati, ${stats.failed} falliti)`,
            5000
        );

        document.body.appendChild(notification);
    }

    handleJobError(message) {
        this.hideAllStates();
        this.showErrorState(message);
    }

    createNotification(type, title, message, duration = 5000) {
        const colors = {
            success: "bg-green-500",
            error: "bg-red-500",
            info: "bg-blue-500",
            warning: "bg-yellow-500",
        };

        const icons = {
            success: `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>`,
            error: `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>`,
            info: `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"></path>`,
            warning: `<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-2.5L13.732 4c-.77-.833-1.996-.833-2.732 0L3.732 16.5c-.77.833.192 2.5 1.732 2.5z"></path>`,
        };

        const notification = document.createElement("div");
        notification.className = `fixed top-4 right-4 ${colors[type]} text-white px-6 py-3 rounded-lg shadow-lg z-50 transform transition-all duration-300 max-w-md`;
        notification.innerHTML = `
            <div class="flex items-start space-x-3">
                <svg class="w-6 h-6 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    ${icons[type]}
                </svg>
                <div class="flex-1">
                    <div class="font-medium">${title}</div>
                    <div class="text-sm opacity-90 mt-1">${message}</div>
                </div>
                <button class="ml-4 text-white hover:text-gray-200" onclick="this.parentElement.parentElement.remove()">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                    </svg>
                </button>
            </div>
        `;

        // Auto remove
        setTimeout(() => {
            if (notification.parentElement) {
                notification.style.transform = "translateX(100%)";
                setTimeout(() => {
                    if (notification.parentElement) {
                        notification.remove();
                    }
                }, 300);
            }
        }, duration);

        return notification;
    }

    // ...existing code...
}

// Inizializza il manager quando il DOM è pronto
document.addEventListener("DOMContentLoaded", () => {
    window.ticketAnalysisManager = new TicketAnalysisManager();
});
