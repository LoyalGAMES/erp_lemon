package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"sync/atomic"
	"testing"
	"time"
)

func TestWaitForBridgeConnectionRetriesUntilAuthorizedPollSucceeds(t *testing.T) {
	var requests atomic.Int32
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		writer.Header().Set("Content-Type", "application/json")
		if requests.Add(1) == 1 {
			_, _ = writer.Write([]byte(`{"success":true,"connected":false,"mode":"bridge","station":"station-1","worker":"PACK-PC-1"}`))
			return
		}
		_, _ = writer.Write([]byte(`{"success":true,"connected":true,"mode":"bridge","station":"station-1","worker":"PACK-PC-1","last_success_at":"2026-07-13T12:00:00Z"}`))
	}))
	defer server.Close()

	ctx, cancel := context.WithTimeout(context.Background(), time.Second)
	defer cancel()
	status, err := waitForBridgeConnection(ctx, server.URL, 5*time.Millisecond)
	if err != nil {
		t.Fatal(err)
	}
	if status.Station != "station-1" || status.Worker != "PACK-PC-1" || requests.Load() < 2 {
		t.Fatalf("unexpected connection status: %#v after %d requests", status, requests.Load())
	}
}

func TestReadBridgeConnectionReportsRemoteFailureWithoutConfigurationSecrets(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		writer.Header().Set("Content-Type", "application/json")
		_, _ = writer.Write([]byte(`{"success":true,"connected":false,"mode":"bridge","station":"station-1","worker":"PACK-PC-1","last_error":"print error","connection_error":"ERP returned HTTP 401"}`))
	}))
	defer server.Close()

	client := server.Client()
	_, err := readBridgeConnection(context.Background(), client, server.URL)
	if err == nil || !strings.Contains(err.Error(), "HTTP 401") {
		t.Fatalf("expected actionable connection error, got %v", err)
	}
	if strings.Contains(err.Error(), "token") || strings.Contains(err.Error(), "base_url") {
		t.Fatalf("connection error exposed configuration details: %v", err)
	}
}
