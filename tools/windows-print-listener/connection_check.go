package main

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"strings"
	"time"
)

const defaultBridgeHealthURL = "http://" + bridgeHealthAddress + "/health"

func waitForBridgeConnection(ctx context.Context, endpoint string, retryInterval time.Duration) (bridgeHealthResponse, error) {
	if retryInterval <= 0 {
		retryInterval = 100 * time.Millisecond
	}

	client := &http.Client{
		Timeout: 3 * time.Second,
		CheckRedirect: func(*http.Request, []*http.Request) error {
			return http.ErrUseLastResponse
		},
	}
	var lastErr error
	for {
		status, err := readBridgeConnection(ctx, client, endpoint)
		if err == nil {
			return status, nil
		}
		lastErr = err

		timer := time.NewTimer(retryInterval)
		select {
		case <-ctx.Done():
			timer.Stop()
			if lastErr != nil {
				return bridgeHealthResponse{}, lastErr
			}
			return bridgeHealthResponse{}, ctx.Err()
		case <-timer.C:
		}
	}
}

func readBridgeConnection(ctx context.Context, client *http.Client, endpoint string) (bridgeHealthResponse, error) {
	request, err := http.NewRequestWithContext(ctx, http.MethodGet, endpoint, nil)
	if err != nil {
		return bridgeHealthResponse{}, err
	}
	request.Header.Set("Accept", "application/json")

	response, err := client.Do(request)
	if err != nil {
		return bridgeHealthResponse{}, fmt.Errorf("lokalna usługa drukowania nie odpowiada: %w", err)
	}
	defer response.Body.Close()
	if response.StatusCode != http.StatusOK {
		return bridgeHealthResponse{}, fmt.Errorf("lokalna usługa zwróciła HTTP %d", response.StatusCode)
	}

	var status bridgeHealthResponse
	decoder := json.NewDecoder(io.LimitReader(response.Body, 64<<10))
	if err := decoder.Decode(&status); err != nil {
		return bridgeHealthResponse{}, fmt.Errorf("nieprawidłowa odpowiedź lokalnej usługi: %w", err)
	}
	if !status.Success || status.Mode != "bridge" {
		return bridgeHealthResponse{}, fmt.Errorf("lokalna usługa nie działa w trybie mostu wydruku")
	}
	if !status.Connected || strings.TrimSpace(status.LastSuccessAt) == "" {
		message := strings.TrimSpace(status.ConnectionError)
		if message == "" {
			message = strings.TrimSpace(status.LastError)
		}
		if message != "" {
			return bridgeHealthResponse{}, fmt.Errorf("ERP odrzuca połączenie lub jest niedostępny: %s", message)
		}
		return bridgeHealthResponse{}, fmt.Errorf("usługa jeszcze nie potwierdziła autoryzowanego połączenia z ERP")
	}

	return status, nil
}
