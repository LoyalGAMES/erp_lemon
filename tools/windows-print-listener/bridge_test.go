package main

import (
	"context"
	"encoding/binary"
	"encoding/json"
	"errors"
	"io"
	"net/http"
	"net/http/httptest"
	"path/filepath"
	"strings"
	"sync/atomic"
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

func TestParseBridgeConfigNormalizesStationLikeERPSettings(t *testing.T) {
	config, err := parseBridgeConfig(`[bridge]
base_url=https://erp.example.test
token=secret
station= Station 1
worker_name=PACK-PC-1
`)
	if err != nil {
		t.Fatal(err)
	}
	if config.Station != "station-1" {
		t.Fatalf("station=%q, expected station-1", config.Station)
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

	bridge := testBridge(t, server.URL)
	if err := bridge.pollOnce(context.Background()); err != nil {
		t.Fatalf("poll: %v", err)
	}
}

func TestBridgeDownloadsPrintsAndAcknowledgesJob(t *testing.T) {
	printedAcknowledgement := false
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		switch request.URL.Path {
		case "/api/print-bridge/jobs/next":
			_, _ = io.WriteString(writer, `{"success":true,"job":{"id":42,"lease_token":"aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa","printer_name":"Zebra ZD421","format":"zpl","label":{"filename":"label.zpl"}}}`)
		case "/api/print-bridge/jobs/42/file":
			if request.Header.Get("X-Print-Lease") == "" {
				t.Fatal("missing print lease header")
			}
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

	bridge := testBridge(t, server.URL)
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
			_, _ = io.WriteString(writer, `{"success":true,"job":{"id":7,"lease_token":"bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb","printer_name":"Missing printer","format":"zpl","label":{"filename":"label.zpl"}}}`)
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

	bridge := testBridge(t, server.URL)
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

func TestBridgeRejectsMappedPrinterThatServiceCannotSeeBeforeDownloading(t *testing.T) {
	downloaded := false
	failedReported := false
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		switch request.URL.Path {
		case "/api/print-bridge/jobs/next":
			_, _ = io.WriteString(writer, `{"success":true,"job":{"id":8,"lease_token":"dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd","printer_name":"User profile Zebra","format":"zpl","label":{"filename":"label.zpl"}}}`)
		case "/api/print-bridge/jobs/8/file":
			downloaded = true
			_, _ = io.WriteString(writer, "^XA^XZ")
		case "/api/print-bridge/jobs/8/failed":
			failedReported = true
			_, _ = io.WriteString(writer, `{"success":true}`)
		default:
			http.NotFound(writer, request)
		}
	}))
	defer server.Close()

	bridge := testBridge(t, server.URL)
	bridge.listPrinters = func() ([]printerInfo, error) {
		return []printerInfo{{Name: "Zebra ZD421"}}, nil
	}
	printed := false
	bridge.print = func(printRequest, []byte) error { printed = true; return nil }

	err := bridge.pollOnce(context.Background())
	if err == nil || !strings.Contains(err.Error(), "not installed or visible") {
		t.Fatalf("expected unavailable mapped printer error, got %v", err)
	}
	if downloaded || printed || !failedReported {
		t.Fatalf("downloaded=%v printed=%v failedReported=%v", downloaded, printed, failedReported)
	}
}

func TestBridgeReportsServicePrinterInventoryWithoutBlockingPolling(t *testing.T) {
	statusReported := false
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		if request.URL.Path != "/api/print-bridge/status" || request.Method != http.MethodPost {
			http.NotFound(writer, request)
			return
		}
		var payload struct {
			Station  string        `json:"station"`
			Worker   string        `json:"worker"`
			Version  string        `json:"version"`
			Printers []printerInfo `json:"printers"`
		}
		if err := json.NewDecoder(request.Body).Decode(&payload); err != nil {
			t.Fatal(err)
		}
		if payload.Station != "station-1" || payload.Worker != "PACK-PC-1" || len(payload.Printers) != 1 {
			t.Fatalf("unexpected status payload: %#v", payload)
		}
		if payload.Printers[0].Name != "Zebra ZD421" || !payload.Printers[0].Default {
			t.Fatalf("unexpected printer inventory: %#v", payload.Printers)
		}
		statusReported = true
		_, _ = io.WriteString(writer, `{"success":true}`)
	}))
	defer server.Close()

	bridge := testBridge(t, server.URL)
	bridge.listPrinters = func() ([]printerInfo, error) {
		return []printerInfo{{Name: "Zebra ZD421", Driver: "ZDesigner", Port: "USB001", Default: true}}, nil
	}
	if err := bridge.reportStatus(context.Background()); err != nil {
		t.Fatal(err)
	}
	if !statusReported {
		t.Fatal("expected printer inventory status report")
	}
}

func TestBridgeUsesLastSuccessfulInventoryWhenRefreshFails(t *testing.T) {
	bridge := testBridge(t, "https://erp.example.test")
	now := time.Date(2026, 7, 13, 12, 0, 0, 0, time.UTC)
	bridge.healthNow = func() time.Time { return now }
	calls := 0
	bridge.listPrinters = func() ([]printerInfo, error) {
		calls++
		if calls == 1 {
			return []printerInfo{{Name: "Zebra ZD421"}}, nil
		}
		return nil, errors.New("spooler temporarily unavailable")
	}

	if printer, err := bridge.resolvePrinterName("zebra zd421"); err != nil || printer != "Zebra ZD421" {
		t.Fatalf("initial inventory: printer=%q err=%v", printer, err)
	}
	now = now.Add(printerInventoryCacheTime + time.Second)
	if printer, err := bridge.resolvePrinterName("Zebra ZD421"); err != nil || printer != "Zebra ZD421" {
		t.Fatalf("stale successful inventory should remain usable: printer=%q err=%v", printer, err)
	}
}

func TestBridgeHealthDoesNotExposeToken(t *testing.T) {
	bridge := testBridge(t, "https://erp.example.test")
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

func TestBridgeHealthSeparatesERPConnectionFromPrinterFailure(t *testing.T) {
	bridge := testBridge(t, "https://erp.example.test")
	bridge.healthNow = func() time.Time { return time.Date(2026, 7, 13, 12, 0, 0, 0, time.UTC) }
	bridge.recordConnectionSuccess()
	bridge.recordPoll(bridgeJobError{err: errors.New("mapped printer is unavailable")})

	request := httptest.NewRequest(http.MethodGet, "/health", nil)
	response := httptest.NewRecorder()
	bridge.healthHandler().ServeHTTP(response, request)

	var payload bridgeHealthResponse
	if err := json.NewDecoder(response.Body).Decode(&payload); err != nil {
		t.Fatal(err)
	}
	if !payload.Connected || payload.ConnectionError != "" {
		t.Fatalf("printer failure incorrectly marked ERP disconnected: %#v", payload)
	}
	if !strings.Contains(payload.LastPrintError, "printer") {
		t.Fatalf("missing print diagnostic: %#v", payload)
	}
}

func TestBridgeDoesNotFollowERPRedirects(t *testing.T) {
	redirectTargetReached := false
	target := httptest.NewServer(http.HandlerFunc(func(http.ResponseWriter, *http.Request) {
		redirectTargetReached = true
	}))
	defer target.Close()
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, _ *http.Request) {
		writer.Header().Set("Location", target.URL)
		writer.WriteHeader(http.StatusTemporaryRedirect)
	}))
	defer server.Close()

	err := testBridge(t, server.URL).pollOnce(context.Background())
	if err == nil || !strings.Contains(err.Error(), "HTTP 307") {
		t.Fatalf("expected redirect rejection, got %v", err)
	}
	if redirectTargetReached {
		t.Fatal("bridge followed an ERP redirect and risked leaking credentials")
	}
}

func TestPrintedJobIsNotPrintedAgainWhenAcknowledgementWasTemporarilyUnavailable(t *testing.T) {
	var acknowledgementsAvailable atomic.Bool
	claimed := 0
	server := httptest.NewServer(http.HandlerFunc(func(writer http.ResponseWriter, request *http.Request) {
		switch request.URL.Path {
		case "/api/print-bridge/jobs/next":
			claimed++
			if claimed == 1 {
				_, _ = io.WriteString(writer, `{"success":true,"job":{"id":99,"lease_token":"cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc","printer_name":"Zebra","format":"zpl","label":{"filename":"label.zpl"}}}`)
			} else {
				_, _ = io.WriteString(writer, `{"success":true,"job":null}`)
			}
		case "/api/print-bridge/jobs/99/file":
			_, _ = io.WriteString(writer, "^XA^XZ")
		case "/api/print-bridge/jobs/99/printed":
			if !acknowledgementsAvailable.Load() {
				http.Error(writer, "offline", http.StatusServiceUnavailable)
				return
			}
			_, _ = io.WriteString(writer, `{"success":true}`)
		default:
			http.NotFound(writer, request)
		}
	}))
	defer server.Close()

	journalPath := filepath.Join(t.TempDir(), "print-journal.json")
	bridge, err := newPrintBridge(bridgeConfig{BaseURL: server.URL, Token: "bridge-secret", Station: "station-1", WorkerName: "PACK-PC-1", PollSeconds: 2}, appConfig{}, journalPath)
	if err != nil {
		t.Fatal(err)
	}
	bridge.retryWait = func(context.Context, time.Duration) error { return nil }
	bridge.listPrinters = func() ([]printerInfo, error) { return []printerInfo{{Name: "Zebra"}}, nil }
	prints := 0
	bridge.print = func(printRequest, []byte) error { prints++; return nil }
	if err := bridge.pollOnce(context.Background()); err == nil {
		t.Fatal("expected acknowledgement failure")
	}
	if prints != 1 || len(bridge.journal.pending()) != 1 {
		t.Fatalf("prints=%d journal=%d", prints, len(bridge.journal.pending()))
	}

	acknowledgementsAvailable.Store(true)
	restarted, err := newPrintBridge(bridge.config, appConfig{}, journalPath)
	if err != nil {
		t.Fatal(err)
	}
	restarted.retryWait = func(context.Context, time.Duration) error { return nil }
	restarted.print = func(printRequest, []byte) error { prints++; return nil }
	if err := restarted.pollOnce(context.Background()); err != nil {
		t.Fatal(err)
	}
	if prints != 1 {
		t.Fatalf("already spooled job was printed %d times", prints)
	}
	if len(restarted.journal.pending()) != 0 {
		t.Fatal("acknowledged journal entry remained")
	}
}

func testBridge(t *testing.T, baseURL string) *printBridge {
	t.Helper()
	journalPath := filepath.Join(t.TempDir(), "print-journal.json")
	bridge, err := newPrintBridge(bridgeConfig{
		BaseURL:     baseURL,
		Token:       "bridge-secret",
		Station:     "station-1",
		WorkerName:  "PACK-PC-1",
		PollSeconds: 2,
	}, appConfig{}, journalPath)
	if err != nil {
		t.Fatal(err)
	}
	bridge.retryWait = func(context.Context, time.Duration) error { return nil }
	bridge.listPrinters = func() ([]printerInfo, error) {
		return []printerInfo{
			{Name: "Zebra"},
			{Name: "Zebra ZD421"},
			{Name: "Missing printer"},
		}, nil
	}
	return bridge
}
