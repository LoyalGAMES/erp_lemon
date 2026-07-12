package main

import (
	"context"
	"encoding/binary"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"
)

func TestParseBridgeConfigAcceptsHTTPSAndLegacyKeyNames(t *testing.T) {
	config, err := parseBridgeConfig(`[bridge]
baseUrl=https://erp.example.test/
token=secret=with=equals
station=station-1
workerName=PACK-PC-1
pollSeconds=3
sumatraPath=C:\Program Files\SumatraPDF\SumatraPDF.exe
`)
	if err != nil {
		t.Fatalf("parse config: %v", err)
	}
	if config.BaseURL != "https://erp.example.test" || config.Token != "secret=with=equals" {
		t.Fatalf("unexpected config: %#v", config)
	}
	if config.Station != "station-1" || config.WorkerName != "PACK-PC-1" || config.PollSeconds != 3 {
		t.Fatalf("unexpected station config: %#v", config)
	}
}

func TestParseBridgeConfigRejectsUnencryptedRemoteERP(t *testing.T) {
	_, err := parseBridgeConfig(`[bridge]
base_url=http://erp.example.test
token=secret
station=station-1
worker_name=PACK-PC-1
`)
	if err == nil || !strings.Contains(err.Error(), "HTTPS") {
		t.Fatalf("expected HTTPS validation error, got %v", err)
	}
}

func TestParseBridgeConfigAllowsLoopbackHTTPForInstallerSmokeTest(t *testing.T) {
	_, err := parseBridgeConfig(`[bridge]
base_url=http://127.0.0.1:18777
token=ci-token
station=station-ci
worker_name=CI-WINDOWS
`)
	if err != nil {
		t.Fatalf("parse loopback config: %v", err)
	}
}

func TestDecodeConfigTextSupportsUnicodeNSISIni(t *testing.T) {
	input := "[bridge]\r\nstation=stanowisko-1\r\n"
	encoded := []byte{0xff, 0xfe}
	for _, char := range []rune(input) {
		buffer := make([]byte, 2)
		binary.LittleEndian.PutUint16(buffer, uint16(char))
		encoded = append(encoded, buffer...)
	}
	decoded, err := decodeConfigText(encoded)
	if err != nil {
		t.Fatalf("decode UTF-16: %v", err)
	}
	if decoded != input {
		t.Fatalf("decoded %q, expected %q", decoded, input)
	}
}

func TestBridgePollWithoutJobUsesBearerTokenAndStation(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.URL.Path != "/api/print-bridge/jobs/next" {
			t.Fatalf("unexpected path %s", request.URL.Path)
		}
		if request.Header.Get("Authorization") != "Bearer bridge-secret" {
			t.Fatalf("unexpected authorization header")
		}
		if request.URL.Query().Get("station") != "station-1" || request.URL.Query().Get("worker") != "PACK-PC-1" {
			t.Fatalf("unexpected query %s", request.URL.RawQuery)
		}
		writer.Header().Set("Content-Type", "application/json")
		_, _ = io.WriteString(writer, `{"success":true,"job":null}`)
	}))
	defer server.Close()

	bridge := testBridge(server.URL)
	if err := bridge.pollOnce(context.Background()); err != nil {
		t.Fatalf("poll: %v", err)
	}
}

func TestBridgeDownloadsPrintsAndAcknowledgesJob(t *testing.T) {
	printedAcknowledgement := false
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		switch request.URL.Path {
		case "/api/print-bridge/jobs/next":
			_, _ = io.WriteString(writer, `{"success":true,"job":{"id":42,"printer_name":"Zebra ZD421","format":"zpl","label":{"filename":"label.zpl"}}}`)
		case "/api/print-bridge/jobs/42/file":
			writer.Header().Set("Content-Type", "application/zpl")
			_, _ = io.WriteString(writer, "^XA^FO20,20^FDTest^FS^XZ")
		case "/api/print-bridge/jobs/42/printed":
			if request.Method != http.MethodPost {
				t.Fatalf("expected POST acknowledgement")
			}
			printedAcknowledgement = true
			_, _ = io.WriteString(writer, `{"success":true}`)
		default:
			http.NotFound(writer, request)
		}
	}))
	defer server.Close()

	bridge := testBridge(server.URL)
	var printedRequest printRequest
	var printedData string
	bridge.print = func(request printRequest, data []byte) error {
		printedRequest = request
		printedData = string(data)
		return nil
	}

	if err := bridge.pollOnce(context.Background()); err != nil {
		t.Fatalf("poll: %v", err)
	}
	if printedRequest.PrinterName != "Zebra ZD421" || printedRequest.Format != "zpl" || printedData != "^XA^FO20,20^FDTest^FS^XZ" {
		t.Fatalf("unexpected print call: %#v %q", printedRequest, printedData)
	}
	if !printedAcknowledgement {
		t.Fatal("expected printed acknowledgement")
	}
}

func TestBridgeReportsPrintingFailure(t *testing.T) {
	failedReported := false
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		switch request.URL.Path {
		case "/api/print-bridge/jobs/next":
			_, _ = io.WriteString(writer, `{"success":true,"job":{"id":7,"printer_name":"Missing printer","format":"zpl","label":{"filename":"label.zpl"}}}`)
		case "/api/print-bridge/jobs/7/file":
			_, _ = io.WriteString(writer, "^XA^XZ")
		case "/api/print-bridge/jobs/7/failed":
			failedReported = true
			_, _ = io.WriteString(writer, `{"success":true}`)
		default:
			http.NotFound(writer, request)
		}
	}))
	defer server.Close()

	bridge := testBridge(server.URL)
	bridge.print = func(printRequest, []byte) error {
		return errors.New("printer is unavailable")
	}
	err := bridge.pollOnce(context.Background())
	if err == nil || !strings.Contains(err.Error(), "printer is unavailable") {
		t.Fatalf("expected print error, got %v", err)
	}
	if !failedReported {
		t.Fatal("expected failed status to be reported")
	}
}

func TestBridgeHealthDoesNotExposeToken(t *testing.T) {
	bridge := testBridge("https://erp.example.test")
	bridge.healthNow = func() time.Time { return time.Date(2026, 7, 12, 12, 0, 0, 0, time.UTC) }
	bridge.recordPoll(nil)

	request := httptest.NewRequest(http.MethodGet, "/health", nil)
	response := httptest.NewRecorder()
	bridge.healthHandler().ServeHTTP(response, request)

	if response.Code != http.StatusOK || !strings.Contains(response.Body.String(), `"connected":true`) {
		t.Fatalf("unexpected health response: %d %s", response.Code, response.Body.String())
	}
	if strings.Contains(response.Body.String(), "bridge-secret") || strings.Contains(response.Body.String(), "erp.example.test") {
		t.Fatalf("health response exposed configuration: %s", response.Body.String())
	}
}

func testBridge(baseURL string) *printBridge {
	return newPrintBridge(bridgeConfig{
		BaseURL:     baseURL,
		Token:       "bridge-secret",
		Station:     "station-1",
		WorkerName:  "PACK-PC-1",
		PollSeconds: 2,
	}, appConfig{})
}
