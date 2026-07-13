package main

import (
	"context"
	"crypto/sha256"
	"crypto/subtle"
	"encoding/base64"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"io"
	"log"
	"mime"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

var (
	version = "dev"
	commit  = "unknown"
)

const (
	defaultRunMode        = "bridge"
	defaultLegacyListen   = "127.0.0.1:17777"
	bundledSumatraVersion = "3.6.1"
	bundledSumatraSHA256  = "719f689b34f47be8ca105ce8484948474dafde0e106bab599e4a89326070c3d0"
)

type appConfig struct {
	mode        string
	configPath  string
	listen      string
	sumatraPath string
	token       string
	logFile     string
}

type printRequest struct {
	PrinterName   string `json:"printer_name"`
	Format        string `json:"format"`
	Filename      string `json:"filename"`
	MimeType      string `json:"mime_type"`
	TrackingNo    string `json:"tracking_number"`
	OrderNo       string `json:"order_number"`
	ContentBase64 string `json:"content_base64"`
}

type printerInfo struct {
	Name    string `json:"name"`
	Driver  string `json:"driver,omitempty"`
	Port    string `json:"port,omitempty"`
	Default bool   `json:"default"`
}

type jsonResponse struct {
	Success  bool          `json:"success"`
	Message  string        `json:"message,omitempty"`
	Version  string        `json:"version,omitempty"`
	Printers []printerInfo `json:"printers,omitempty"`
}

func main() {
	cfg := appConfig{}
	var showVersion bool
	var serviceAction string
	var protectDirectory string
	var protectFile string
	var validateBridgeConfig bool
	var validateRenderer bool
	var checkConnection bool
	flag.StringVar(&cfg.mode, "mode", defaultRunMode, "Run mode: bridge (outbound ERP polling) or listener (legacy inbound HTTP)")
	flag.StringVar(&cfg.configPath, "config", defaultBridgeConfigPath(), "Path to the ACL-protected outbound bridge configuration")
	flag.StringVar(&cfg.listen, "listen", defaultLegacyListen, "Legacy HTTP listen address; non-loopback requires -token")
	flag.StringVar(&cfg.sumatraPath, "sumatra", "", "Override path to the pinned SumatraPDF.exe renderer (exact SHA-256 required)")
	flag.StringVar(&cfg.token, "token", "", "Optional shared token required in Authorization: Bearer ... or X-Print-Token")
	flag.StringVar(&cfg.logFile, "log-file", "", "Optional append-only log file path (recommended for the Windows service)")
	flag.StringVar(&serviceAction, "service", "", "Windows service action: install, update, uninstall, start, or stop")
	flag.StringVar(&protectDirectory, "protect-config-directory", "", "Apply the restricted SYSTEM/Administrators ACL to a config directory")
	flag.StringVar(&protectFile, "protect-config-file", "", "Apply the restricted SYSTEM/Administrators ACL to a config file")
	flag.BoolVar(&validateBridgeConfig, "validate-config", false, "Validate the outbound bridge config and its Windows ACL, then exit")
	flag.BoolVar(&validateRenderer, "validate-renderer", false, "Validate the pinned bundled PDF renderer, then exit")
	flag.BoolVar(&checkConnection, "check-connection", false, "Verify that the local print bridge is authorized and connected to ERP, then exit")
	flag.BoolVar(&showVersion, "version", false, "Print version and source commit, then exit")
	flag.Parse()

	if showVersion {
		fmt.Printf("Sempre ERP Print Listener %s (%s)\n", version, commit)
		return
	}

	if err := configureLogging(cfg.logFile); err != nil {
		log.Fatalf("Cannot configure logging: %v", err)
	}

	if serviceAction != "" {
		if err := manageService(serviceAction, cfg); err != nil {
			log.Fatal(err)
		}
		return
	}
	if protectDirectory != "" && protectFile != "" {
		log.Fatal("Only one config protection action can be requested at a time")
	}
	if protectDirectory != "" {
		if err := protectConfigDirectory(protectDirectory); err != nil {
			log.Fatal(err)
		}
		return
	}
	if protectFile != "" {
		if err := protectConfigFile(protectFile); err != nil {
			log.Fatal(err)
		}
		return
	}
	if validateBridgeConfig {
		if strings.ToLower(strings.TrimSpace(cfg.mode)) != "bridge" {
			log.Fatal("-validate-config requires -mode bridge")
		}
		bridgeConfig, err := loadBridgeConfig(cfg.configPath)
		if err != nil {
			log.Fatal(err)
		}
		fmt.Printf("Outbound bridge configuration is valid (station=%s, worker=%s)\n", bridgeConfig.Station, bridgeConfig.WorkerName)
		return
	}
	if validateRenderer {
		path, err := cfg.resolveSumatra()
		if err != nil {
			log.Fatal(err)
		}
		fmt.Printf("Pinned SumatraPDF %s renderer is valid: %s\n", bundledSumatraVersion, path)
		return
	}
	if checkConnection {
		ctx, cancel := context.WithTimeout(context.Background(), 20*time.Second)
		defer cancel()
		status, err := waitForBridgeConnection(ctx, defaultBridgeHealthURL, 500*time.Millisecond)
		if err != nil {
			log.Fatalf("Połączenie z ERP nie działa: %v", err)
		}
		fmt.Printf(
			"Połączenie z ERP działa (stanowisko=%s, komputer=%s, ostatni sukces=%s).\n",
			status.Station,
			status.Worker,
			status.LastSuccessAt,
		)
		return
	}

	cfg.mode = strings.ToLower(strings.TrimSpace(cfg.mode))
	var runner applicationRunner

	switch cfg.mode {
	case "bridge":
		bridgeConfig, err := loadBridgeConfig(cfg.configPath)
		if err != nil {
			log.Fatalf("Cannot load outbound bridge configuration: %v", err)
		}
		printerConfig := cfg
		printerConfig.sumatraPath = bridgeConfig.SumatraPath
		bridge, err := newPrintBridge(bridgeConfig, printerConfig, filepath.Join(filepath.Dir(cfg.configPath), "print-journal.json"))
		if err != nil {
			log.Fatalf("Cannot open durable print acknowledgement journal: %v", err)
		}
		log.Printf(
			"Sempre ERP Print Listener %s (%s) starting in outbound bridge mode for station %s as %s",
			version,
			commit,
			bridgeConfig.Station,
			bridgeConfig.WorkerName,
		)
		runner = bridge.Run

	case "listener":
		if err := validateLegacyListenerConfig(cfg); err != nil {
			log.Fatal(err)
		}
		server := newHTTPServer(cfg)
		log.Printf("Sempre ERP Print Listener %s (%s) starting in legacy listener mode on %s", version, commit, cfg.listen)
		if cfg.sumatraPath != "" {
			log.Printf("Using SumatraPDF: %s", cfg.sumatraPath)
		}
		if cfg.token == "" {
			log.Printf("Warning: legacy listener authentication is disabled; never expose it to the Internet")
		}
		if runtime.GOOS != "windows" {
			log.Printf("Warning: this listener can receive requests on %s, but printing is implemented for Windows", runtime.GOOS)
		}
		runner = func(ctx context.Context) error {
			return serveHTTP(ctx, server)
		}

	default:
		log.Fatalf("Unknown mode %q (expected bridge or listener)", cfg.mode)
	}

	if err := runApplication(runner); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatal(err)
	}
}

type applicationRunner func(context.Context) error

func defaultBridgeConfigPath() string {
	if programData := strings.TrimSpace(os.Getenv("ProgramData")); programData != "" {
		return filepath.Join(programData, "Sempre ERP", "Print Listener", "config.ini")
	}

	return "config.ini"
}

func validateLegacyListenerConfig(cfg appConfig) error {
	host, _, err := net.SplitHostPort(strings.TrimSpace(cfg.listen))
	if err != nil {
		return fmt.Errorf("invalid legacy listener address %q: %w", cfg.listen, err)
	}
	host = strings.Trim(strings.TrimSpace(host), "[]")
	isLoopback := strings.EqualFold(host, "localhost")
	if address := net.ParseIP(host); address != nil {
		isLoopback = address.IsLoopback()
	}
	if !isLoopback && strings.TrimSpace(cfg.token) == "" {
		return fmt.Errorf("legacy listener on non-loopback address %q requires a non-empty -token", cfg.listen)
	}
	return nil
}

func serveHTTP(ctx context.Context, server *http.Server) error {
	serverErrors := make(chan error, 1)
	go func() {
		serverErrors <- server.ListenAndServe()
	}()

	select {
	case err := <-serverErrors:
		return err
	case <-ctx.Done():
		shutdownCtx, cancel := context.WithTimeout(context.Background(), 15*time.Second)
		defer cancel()
		if err := server.Shutdown(shutdownCtx); err != nil {
			_ = server.Close()
			return err
		}
		return nil
	}
}

func newHTTPServer(cfg appConfig) *http.Server {
	return &http.Server{
		Addr:              cfg.listen,
		Handler:           requestLogger(newHandler(cfg)),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      90 * time.Second,
		IdleTimeout:       60 * time.Second,
		MaxHeaderBytes:    1 << 20,
	}
}

func newHandler(cfg appConfig) http.Handler {
	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, jsonResponse{Success: true, Message: "ready", Version: version})
	})
	mux.HandleFunc("GET /printers", cfg.handlePrinters)
	mux.HandleFunc("POST /print", cfg.handlePrint)

	return mux
}

func (cfg appConfig) handlePrinters(w http.ResponseWriter, r *http.Request) {
	if !cfg.authorized(r) {
		writeJSON(w, http.StatusUnauthorized, jsonResponse{Success: false, Message: "unauthorized"})
		return
	}

	printers, err := installedPrinters()
	if err != nil {
		writeJSON(w, http.StatusInternalServerError, jsonResponse{Success: false, Message: err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, jsonResponse{Success: true, Printers: printers})
}

func (cfg appConfig) handlePrint(w http.ResponseWriter, r *http.Request) {
	if !cfg.authorized(r) {
		writeJSON(w, http.StatusUnauthorized, jsonResponse{Success: false, Message: "unauthorized"})
		return
	}

	var req printRequest
	decoder := json.NewDecoder(http.MaxBytesReader(w, r.Body, 25<<20))
	if err := decoder.Decode(&req); err != nil {
		writeJSON(w, http.StatusBadRequest, jsonResponse{Success: false, Message: "invalid json: " + err.Error()})
		return
	}

	req.PrinterName = strings.TrimSpace(req.PrinterName)
	if req.PrinterName == "" {
		writeJSON(w, http.StatusBadRequest, jsonResponse{Success: false, Message: "printer_name is required"})
		return
	}

	data, err := base64.StdEncoding.DecodeString(strings.TrimSpace(req.ContentBase64))
	if err != nil || len(data) == 0 {
		writeJSON(w, http.StatusBadRequest, jsonResponse{Success: false, Message: "content_base64 is invalid or empty"})
		return
	}

	if err := cfg.print(req, data); err != nil {
		writeJSON(w, http.StatusInternalServerError, jsonResponse{Success: false, Message: err.Error()})
		return
	}

	writeJSON(w, http.StatusOK, jsonResponse{Success: true, Message: "printed"})
}

func (cfg appConfig) print(req printRequest, data []byte) error {
	format := detectFormat(req, data)
	if format == "zpl" {
		return rawPrint(req.PrinterName, data)
	}

	return cfg.printWithSumatra(req, data)
}

func (cfg appConfig) printWithSumatra(req printRequest, data []byte) error {
	sumatra, err := cfg.resolveSumatra()
	if err != nil {
		return err
	}

	ext := extensionFor(req)
	temp, err := os.CreateTemp("", "lemon-label-*"+ext)
	if err != nil {
		return err
	}
	defer os.Remove(temp.Name())

	if _, err := temp.Write(data); err != nil {
		temp.Close()
		return err
	}
	if err := temp.Close(); err != nil {
		return err
	}

	ctx, cancel := context.WithTimeout(context.Background(), 60*time.Second)
	defer cancel()

	cmd := exec.CommandContext(ctx, sumatra, "-print-to", req.PrinterName, "-silent", temp.Name())
	output, err := cmd.CombinedOutput()
	if ctx.Err() != nil {
		return fmt.Errorf("printing timed out")
	}
	if err != nil {
		return fmt.Errorf("SumatraPDF print failed: %w %s", err, strings.TrimSpace(string(output)))
	}

	return nil
}

func (cfg appConfig) resolveSumatra() (string, error) {
	candidates := []string{}
	if exe, err := os.Executable(); err == nil {
		candidates = append(candidates, filepath.Join(filepath.Dir(exe), "SumatraPDF.exe"))
	}
	if cfg.sumatraPath != "" {
		candidates = append(candidates, cfg.sumatraPath)
	}
	if env := strings.TrimSpace(os.Getenv("SUMATRA_PATH")); env != "" {
		candidates = append(candidates, env)
	}
	if programFiles := os.Getenv("ProgramFiles"); programFiles != "" {
		candidates = append(candidates, filepath.Join(programFiles, "SumatraPDF", "SumatraPDF.exe"))
	}
	if programFilesX86 := os.Getenv("ProgramFiles(x86)"); programFilesX86 != "" {
		candidates = append(candidates, filepath.Join(programFilesX86, "SumatraPDF", "SumatraPDF.exe"))
	}
	if path, err := exec.LookPath("SumatraPDF.exe"); err == nil {
		candidates = append(candidates, path)
	}

	for _, candidate := range candidates {
		if candidate == "" {
			continue
		}
		if info, err := os.Stat(candidate); err == nil && !info.IsDir() {
			if err := validateSumatraBinary(candidate); err == nil {
				return candidate, nil
			}
		}
	}

	return "", errors.New("PDF/image labels require the pinned SumatraPDF 3.6.1 renderer (SHA-256 mismatch or file missing); reinstall the signed Sempre ERP Print Listener")
}

func validateSumatraBinary(path string) error {
	file, err := os.Open(path)
	if err != nil {
		return err
	}
	defer file.Close()
	hash := sha256.New()
	if _, err := io.Copy(hash, io.LimitReader(file, 100<<20)); err != nil {
		return err
	}
	actual := fmt.Sprintf("%x", hash.Sum(nil))
	if !secureTokenEqual(actual, bundledSumatraSHA256) {
		return fmt.Errorf("SumatraPDF SHA-256 is %s, expected %s", actual, bundledSumatraSHA256)
	}
	return nil
}

func detectFormat(req printRequest, data []byte) string {
	format := strings.ToLower(strings.TrimSpace(req.Format))
	mimeType := strings.ToLower(strings.TrimSpace(req.MimeType))
	ext := strings.ToLower(filepath.Ext(req.Filename))

	if format == "zpl" || strings.Contains(mimeType, "zpl") || ext == ".zpl" || looksLikeZPL(data) {
		return "zpl"
	}

	return "document"
}

func looksLikeZPL(data []byte) bool {
	trimmed := strings.TrimLeft(string(data[:min(len(data), 128)]), "\ufeff\r\n\t ")

	return strings.HasPrefix(trimmed, "^XA") || strings.HasPrefix(trimmed, "~D")
}

func extensionFor(req printRequest) string {
	if ext := filepath.Ext(req.Filename); ext != "" {
		return sanitizeExtension(ext)
	}
	if exts, err := mime.ExtensionsByType(req.MimeType); err == nil && len(exts) > 0 {
		return sanitizeExtension(exts[0])
	}

	return ".pdf"
}

func sanitizeExtension(ext string) string {
	ext = strings.ToLower(strings.TrimSpace(ext))
	if !strings.HasPrefix(ext, ".") {
		ext = "." + ext
	}
	if len(ext) > 8 {
		return ".pdf"
	}
	for _, char := range ext[1:] {
		if (char < 'a' || char > 'z') && (char < '0' || char > '9') {
			return ".pdf"
		}
	}

	return ext
}

func (cfg appConfig) authorized(r *http.Request) bool {
	if cfg.token == "" {
		return true
	}

	auth := strings.TrimSpace(r.Header.Get("Authorization"))
	if strings.HasPrefix(strings.ToLower(auth), "bearer ") {
		return secureTokenEqual(strings.TrimSpace(auth[7:]), cfg.token)
	}

	return secureTokenEqual(strings.TrimSpace(r.Header.Get("X-Print-Token")), cfg.token)
}

func secureTokenEqual(provided, expected string) bool {
	if provided == "" || len(provided) != len(expected) {
		return false
	}

	return subtle.ConstantTimeCompare([]byte(provided), []byte(expected)) == 1
}

func configureLogging(path string) error {
	path = strings.TrimSpace(path)
	if path == "" {
		return nil
	}

	if err := os.MkdirAll(filepath.Dir(path), 0o750); err != nil {
		return err
	}

	file, err := newRotatingLogWriter(path, defaultLogMaxBytes, defaultLogBackups)
	if err != nil {
		return err
	}

	// Write to the durable service log first. A Windows service may not have a
	// usable stderr handle, and io.MultiWriter stops after the first write error.
	log.SetOutput(io.MultiWriter(file, os.Stderr))
	return nil
}

func writeJSON(w http.ResponseWriter, status int, payload jsonResponse) {
	w.Header().Set("Content-Type", "application/json")
	w.WriteHeader(status)
	_ = json.NewEncoder(w).Encode(payload)
}

func requestLogger(next http.Handler) http.Handler {
	return http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		started := time.Now()
		next.ServeHTTP(w, r)
		log.Printf("%s %s %s", r.Method, r.URL.Path, time.Since(started).Round(time.Millisecond))
	})
}
