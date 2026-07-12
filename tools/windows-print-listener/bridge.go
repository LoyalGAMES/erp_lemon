package main

import (
	"bytes"
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"log"
	"net/http"
	"net/url"
	"strconv"
	"strings"
	"sync"
	"time"
)

const (
	bridgeHealthAddress = "127.0.0.1:17778"
	maxBridgeResponse   = 1 << 20
	maxLabelSize        = 25 << 20
)

type bridgeJob struct {
	ID          int64  `json:"id"`
	LeaseToken  string `json:"lease_token"`
	PrinterName string `json:"printer_name"`
	Format      string `json:"format"`
	Label       struct {
		Filename string `json:"filename"`
	} `json:"label"`
}

type bridgeState struct {
	mu            sync.RWMutex
	lastPollAt    time.Time
	lastSuccessAt time.Time
	lastError     string
}

type printBridge struct {
	config    bridgeConfig
	client    *http.Client
	print     func(printRequest, []byte) error
	state     *bridgeState
	healthNow func() time.Time
	journal   *printJournal
	retryWait func(context.Context, time.Duration) error
}

type bridgeHealthResponse struct {
	Success       bool   `json:"success"`
	Connected     bool   `json:"connected"`
	Message       string `json:"message"`
	Version       string `json:"version"`
	Mode          string `json:"mode"`
	Station       string `json:"station"`
	Worker        string `json:"worker"`
	LastPollAt    string `json:"last_poll_at,omitempty"`
	LastSuccessAt string `json:"last_success_at,omitempty"`
	LastError     string `json:"last_error,omitempty"`
}

func newPrintBridge(config bridgeConfig, printerConfig appConfig, journalPath string) (*printBridge, error) {
	journal, err := openPrintJournal(journalPath)
	if err != nil {
		return nil, err
	}
	transport := http.DefaultTransport.(*http.Transport).Clone()
	transport.TLSClientConfig = &tls.Config{MinVersion: tls.VersionTLS12}
	bridge := &printBridge{
		config: config,
		client: &http.Client{
			Timeout:   45 * time.Second,
			Transport: transport,
			CheckRedirect: func(*http.Request, []*http.Request) error {
				return http.ErrUseLastResponse
			},
		},
		print:     printerConfig.print,
		state:     &bridgeState{},
		healthNow: time.Now,
		journal:   journal,
		retryWait: waitForRetry,
	}
	return bridge, nil
}

func (bridge *printBridge) Run(ctx context.Context) error {
	healthServer := &http.Server{
		Addr:              bridgeHealthAddress,
		Handler:           bridge.healthHandler(),
		ReadHeaderTimeout: 3 * time.Second,
		ReadTimeout:       5 * time.Second,
		WriteTimeout:      5 * time.Second,
		IdleTimeout:       30 * time.Second,
		MaxHeaderBytes:    32 << 10,
	}
	healthErrors := make(chan error, 1)
	go func() {
		healthErrors <- healthServer.ListenAndServe()
	}()

	timer := time.NewTimer(0)
	defer timer.Stop()

	for {
		select {
		case <-ctx.Done():
			shutdownCtx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
			defer cancel()
			if err := healthServer.Shutdown(shutdownCtx); err != nil {
				_ = healthServer.Close()
				return err
			}
			return nil

		case err := <-healthErrors:
			if errors.Is(err, http.ErrServerClosed) && ctx.Err() != nil {
				return nil
			}
			return fmt.Errorf("local health endpoint %s failed: %w", bridgeHealthAddress, err)

		case <-timer.C:
			err := bridge.pollOnce(ctx)
			bridge.recordPoll(err)
			delay := time.Duration(bridge.config.PollSeconds) * time.Second
			if err != nil {
				log.Printf("Outbound print bridge poll failed: %s", bridge.redact(err.Error()))
				if delay < 5*time.Second {
					delay = 5 * time.Second
				}
			}
			timer.Reset(delay)
		}
	}
}

func (bridge *printBridge) pollOnce(ctx context.Context) error {
	if err := bridge.flushAcknowledgements(ctx); err != nil {
		return err
	}
	query := url.Values{}
	query.Set("worker", bridge.config.WorkerName)
	query.Set("station", bridge.config.Station)

	var response struct {
		Success bool       `json:"success"`
		Job     *bridgeJob `json:"job"`
	}
	if err := bridge.doJSON(ctx, http.MethodGet, "/api/print-bridge/jobs/next?"+query.Encode(), nil, &response); err != nil {
		return fmt.Errorf("claim next print job: %w", err)
	}
	if !response.Success {
		return fmt.Errorf("ERP returned success=false while claiming a print job")
	}
	if response.Job == nil {
		return nil
	}
	job := response.Job
	if job.ID <= 0 || strings.TrimSpace(job.PrinterName) == "" || strings.TrimSpace(job.LeaseToken) == "" {
		return fmt.Errorf("ERP returned an invalid print job")
	}

	data, mimeType, err := bridge.downloadLabel(ctx, job)
	if err == nil {
		err = bridge.print(printRequest{
			PrinterName: strings.TrimSpace(job.PrinterName),
			Format:      strings.TrimSpace(job.Format),
			Filename:    strings.TrimSpace(job.Label.Filename),
			MimeType:    mimeType,
		}, data)
	}
	if err != nil {
		reportErr := bridge.postWithRetry(ctx, "/api/print-bridge/jobs/"+strconv.FormatInt(job.ID, 10)+"/failed", map[string]string{
			"worker":      bridge.config.WorkerName,
			"station":     bridge.config.Station,
			"lease_token": job.LeaseToken,
			"error":       truncateMessage(bridge.redact(err.Error()), 2000),
		})
		if reportErr != nil {
			return fmt.Errorf("print job %d failed: %v; reporting failure also failed: %w", job.ID, err, reportErr)
		}
		return fmt.Errorf("print job %d failed and was reported to ERP: %w", job.ID, err)
	}

	record := printJournalRecord{JobID: job.ID, LeaseToken: job.LeaseToken, Worker: bridge.config.WorkerName, Station: bridge.config.Station, PrintedAt: time.Now().UTC()}
	if err := bridge.journal.record(record); err != nil {
		return fmt.Errorf("print job %d was spooled but its durable acknowledgement journal could not be written: %w", job.ID, err)
	}
	if err := bridge.acknowledgePrinted(ctx, record); err != nil {
		return fmt.Errorf("print job %d was printed but acknowledgement failed: %w", job.ID, err)
	}
	if err := bridge.journal.remove(job.ID); err != nil {
		return fmt.Errorf("print job %d was acknowledged but journal cleanup failed: %w", job.ID, err)
	}

	log.Printf("Printed outbound job %d on %s", job.ID, job.PrinterName)
	return nil
}

func (bridge *printBridge) downloadLabel(ctx context.Context, job *bridgeJob) ([]byte, string, error) {
	request, err := bridge.newRequest(
		ctx,
		http.MethodGet,
		"/api/print-bridge/jobs/"+strconv.FormatInt(job.ID, 10)+"/file",
		nil,
	)
	if err != nil {
		return nil, "", err
	}
	bridge.addLeaseHeaders(request, job.LeaseToken, bridge.config.WorkerName, bridge.config.Station)
	response, err := bridge.client.Do(request)
	if err != nil {
		return nil, "", err
	}
	defer response.Body.Close()
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return nil, "", responseError(response)
	}

	data, err := io.ReadAll(io.LimitReader(response.Body, maxLabelSize+1))
	if err != nil {
		return nil, "", err
	}
	if len(data) == 0 {
		return nil, "", fmt.Errorf("ERP returned an empty label")
	}
	if len(data) > maxLabelSize {
		return nil, "", fmt.Errorf("label exceeds %d bytes", maxLabelSize)
	}
	return data, strings.TrimSpace(response.Header.Get("Content-Type")), nil
}

func (bridge *printBridge) flushAcknowledgements(ctx context.Context) error {
	for _, record := range bridge.journal.pending() {
		if err := bridge.acknowledgePrinted(ctx, record); err != nil {
			return fmt.Errorf("retry acknowledgement for already printed job %d: %w", record.JobID, err)
		}
		if err := bridge.journal.remove(record.JobID); err != nil {
			return fmt.Errorf("remove acknowledged job %d from journal: %w", record.JobID, err)
		}
	}
	return nil
}

func (bridge *printBridge) acknowledgePrinted(ctx context.Context, record printJournalRecord) error {
	return bridge.postWithRetry(ctx, "/api/print-bridge/jobs/"+strconv.FormatInt(record.JobID, 10)+"/printed", map[string]string{
		"worker":      record.Worker,
		"station":     record.Station,
		"lease_token": record.LeaseToken,
		"message":     "Printed by Sempre ERP Print Listener " + version,
	})
}

func (bridge *printBridge) postWithRetry(ctx context.Context, path string, payload any) error {
	var lastErr error
	for attempt := 0; attempt < 3; attempt++ {
		if attempt > 0 {
			delay := time.Duration(1<<(attempt-1)) * time.Second
			if err := bridge.retryWait(ctx, delay); err != nil {
				return err
			}
		}
		var response struct {
			Success bool `json:"success"`
		}
		if err := bridge.doJSON(ctx, http.MethodPost, path, payload, &response); err != nil {
			lastErr = err
			continue
		}
		if !response.Success {
			lastErr = fmt.Errorf("ERP returned success=false")
			continue
		}
		return nil
	}
	return lastErr
}

func waitForRetry(ctx context.Context, delay time.Duration) error {
	timer := time.NewTimer(delay)
	defer timer.Stop()
	select {
	case <-ctx.Done():
		return ctx.Err()
	case <-timer.C:
		return nil
	}
}

func (bridge *printBridge) addLeaseHeaders(request *http.Request, lease, worker, station string) {
	request.Header.Set("X-Print-Lease", lease)
	request.Header.Set("X-Print-Worker", worker)
	request.Header.Set("X-Print-Station", station)
}

func (bridge *printBridge) doJSON(ctx context.Context, method, path string, payload any, destination any) error {
	var body io.Reader
	if payload != nil {
		encoded, err := json.Marshal(payload)
		if err != nil {
			return err
		}
		body = bytes.NewReader(encoded)
	}
	request, err := bridge.newRequest(ctx, method, path, body)
	if err != nil {
		return err
	}
	if payload != nil {
		request.Header.Set("Content-Type", "application/json")
	}

	response, err := bridge.client.Do(request)
	if err != nil {
		return err
	}
	defer response.Body.Close()
	if response.StatusCode < 200 || response.StatusCode >= 300 {
		return responseError(response)
	}
	decoder := json.NewDecoder(io.LimitReader(response.Body, maxBridgeResponse))
	if err := decoder.Decode(destination); err != nil {
		return fmt.Errorf("decode ERP response: %w", err)
	}
	return nil
}

func (bridge *printBridge) newRequest(ctx context.Context, method, path string, body io.Reader) (*http.Request, error) {
	request, err := http.NewRequestWithContext(ctx, method, bridge.config.BaseURL+path, body)
	if err != nil {
		return nil, err
	}
	request.Header.Set("Accept", "application/json")
	request.Header.Set("Authorization", "Bearer "+bridge.config.Token)
	request.Header.Set("User-Agent", "SempreERP-PrintListener/"+version)
	return request, nil
}

func responseError(response *http.Response) error {
	body, _ := io.ReadAll(io.LimitReader(response.Body, 4096))
	message := strings.TrimSpace(string(body))
	if message == "" {
		return fmt.Errorf("ERP returned HTTP %d", response.StatusCode)
	}
	return fmt.Errorf("ERP returned HTTP %d: %s", response.StatusCode, message)
}

func (bridge *printBridge) recordPoll(err error) {
	now := bridge.healthNow()
	bridge.state.mu.Lock()
	defer bridge.state.mu.Unlock()
	bridge.state.lastPollAt = now
	if err == nil {
		bridge.state.lastSuccessAt = now
		bridge.state.lastError = ""
		return
	}
	bridge.state.lastError = truncateMessage(bridge.redact(err.Error()), 500)
}

func (bridge *printBridge) healthHandler() http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", func(writer http.ResponseWriter, _ *http.Request) {
		bridge.state.mu.RLock()
		lastPollAt := bridge.state.lastPollAt
		lastSuccessAt := bridge.state.lastSuccessAt
		lastError := bridge.state.lastError
		bridge.state.mu.RUnlock()

		response := bridgeHealthResponse{
			Success:   true,
			Connected: !lastSuccessAt.IsZero() && lastError == "",
			Message:   "outbound bridge running",
			Version:   version,
			Mode:      "bridge",
			Station:   bridge.config.Station,
			Worker:    bridge.config.WorkerName,
			LastError: lastError,
		}
		if !lastPollAt.IsZero() {
			response.LastPollAt = lastPollAt.UTC().Format(time.RFC3339)
		}
		if !lastSuccessAt.IsZero() {
			response.LastSuccessAt = lastSuccessAt.UTC().Format(time.RFC3339)
		}
		writeBridgeHealthJSON(writer, response)
	})
	return mux
}

func writeBridgeHealthJSON(writer http.ResponseWriter, response bridgeHealthResponse) {
	writer.Header().Set("Content-Type", "application/json")
	writer.Header().Set("Cache-Control", "no-store")
	writer.Header().Set("X-Content-Type-Options", "nosniff")
	_ = json.NewEncoder(writer).Encode(response)
}

func truncateMessage(message string, limit int) string {
	runes := []rune(message)
	if len(runes) <= limit {
		return message
	}
	return string(runes[:limit])
}

func (bridge *printBridge) redact(message string) string {
	if bridge.config.Token == "" {
		return message
	}
	return strings.ReplaceAll(message, bridge.config.Token, "[REDACTED]")
}
