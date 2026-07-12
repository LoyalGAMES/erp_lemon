package main

import (
	"encoding/base64"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestHealthEndpointReportsVersion(t *testing.T) {
	previousVersion := version
	version = "1.2.3-test"
	t.Cleanup(func() { version = previousVersion })

	request := httptest.NewRequest(http.MethodGet, "/health", nil)
	response := httptest.NewRecorder()
	newHandler(appConfig{}).ServeHTTP(response, request)

	if response.Code != http.StatusOK {
		t.Fatalf("expected HTTP 200, got %d", response.Code)
	}

	var payload jsonResponse
	if err := json.NewDecoder(response.Body).Decode(&payload); err != nil {
		t.Fatalf("decode response: %v", err)
	}
	if !payload.Success || payload.Message != "ready" || payload.Version != "1.2.3-test" {
		t.Fatalf("unexpected health payload: %#v", payload)
	}
}

func TestProtectedEndpointsRejectInvalidTokenBeforePlatformAccess(t *testing.T) {
	cfg := appConfig{token: "shared-secret"}

	for _, path := range []string{"/printers", "/print"} {
		method := http.MethodGet
		if path == "/print" {
			method = http.MethodPost
		}

		request := httptest.NewRequest(method, path, strings.NewReader("{}"))
		request.Header.Set("Authorization", "Bearer wrong-secret")
		response := httptest.NewRecorder()
		newHandler(cfg).ServeHTTP(response, request)

		if response.Code != http.StatusUnauthorized {
			t.Fatalf("%s: expected HTTP 401, got %d", path, response.Code)
		}
	}
}

func TestAuthorizationAcceptsBearerAndPrintToken(t *testing.T) {
	cfg := appConfig{token: "shared-secret"}

	bearer := httptest.NewRequest(http.MethodGet, "/printers", nil)
	bearer.Header.Set("Authorization", "Bearer shared-secret")
	if !cfg.authorized(bearer) {
		t.Fatal("expected matching bearer token to be accepted")
	}

	header := httptest.NewRequest(http.MethodGet, "/printers", nil)
	header.Header.Set("X-Print-Token", "shared-secret")
	if !cfg.authorized(header) {
		t.Fatal("expected matching X-Print-Token to be accepted")
	}
}

func TestPrintEndpointRejectsInvalidPayloadWithoutPrinting(t *testing.T) {
	request := httptest.NewRequest(http.MethodPost, "/print", strings.NewReader(`{"printer_name":"Zebra","content_base64":"***"}`))
	response := httptest.NewRecorder()
	newHandler(appConfig{}).ServeHTTP(response, request)

	if response.Code != http.StatusBadRequest {
		t.Fatalf("expected HTTP 400, got %d: %s", response.Code, response.Body.String())
	}
}

func TestDetectFormatAndSanitizeExtension(t *testing.T) {
	zpl := []byte("\ufeff\r\n ^XA^FO20,20^FDTest^FS^XZ")
	if got := detectFormat(printRequest{}, zpl); got != "zpl" {
		t.Fatalf("expected zpl, got %q", got)
	}

	pdf := []byte("%PDF-1.7")
	if got := detectFormat(printRequest{Filename: "label.pdf", ContentBase64: base64.StdEncoding.EncodeToString(pdf)}, pdf); got != "document" {
		t.Fatalf("expected document, got %q", got)
	}

	for input, expected := range map[string]string{
		"pdf":          ".pdf",
		".PNG":         ".png",
		".exe & calc":  ".pdf",
		".verylongext": ".pdf",
	} {
		if got := sanitizeExtension(input); got != expected {
			t.Errorf("sanitizeExtension(%q) = %q, expected %q", input, got, expected)
		}
	}
}

func TestLegacyListenerRequiresTokenOutsideLoopback(t *testing.T) {
	for _, test := range []struct {
		name    string
		config  appConfig
		wantErr bool
	}{
		{name: "IPv4 loopback", config: appConfig{listen: "127.0.0.1:17777"}},
		{name: "IPv6 loopback", config: appConfig{listen: "[::1]:17777"}},
		{name: "localhost", config: appConfig{listen: "localhost:17777"}},
		{name: "all interfaces without token", config: appConfig{listen: "0.0.0.0:17777"}, wantErr: true},
		{name: "implicit all interfaces without token", config: appConfig{listen: ":17777"}, wantErr: true},
		{name: "LAN with token", config: appConfig{listen: "192.168.1.25:17777", token: "shared-secret"}},
		{name: "invalid address", config: appConfig{listen: "not-an-address"}, wantErr: true},
	} {
		t.Run(test.name, func(t *testing.T) {
			err := validateLegacyListenerConfig(test.config)
			if test.wantErr && err == nil {
				t.Fatal("expected validation error")
			}
			if !test.wantErr && err != nil {
				t.Fatalf("unexpected validation error: %v", err)
			}
		})
	}
}

func TestSafeRuntimeDefaults(t *testing.T) {
	if defaultRunMode != "bridge" {
		t.Fatalf("default mode must remain bridge, got %q", defaultRunMode)
	}
	if defaultLegacyListen != "127.0.0.1:17777" {
		t.Fatalf("legacy listener default must remain loopback-only, got %q", defaultLegacyListen)
	}
}
