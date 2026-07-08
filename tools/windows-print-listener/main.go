package main

import (
	"context"
	"encoding/base64"
	"encoding/json"
	"errors"
	"flag"
	"fmt"
	"log"
	"mime"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"runtime"
	"strings"
	"time"
)

type appConfig struct {
	listen      string
	sumatraPath string
	token       string
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
	Printers []printerInfo `json:"printers,omitempty"`
}

func main() {
	cfg := appConfig{}
	flag.StringVar(&cfg.listen, "listen", ":17777", "HTTP listen address, for example :17777 or 0.0.0.0:17777")
	flag.StringVar(&cfg.sumatraPath, "sumatra", "", "Optional path to SumatraPDF.exe for PDF/image labels")
	flag.StringVar(&cfg.token, "token", "", "Optional shared token required in Authorization: Bearer ... or X-Print-Token")
	flag.Parse()

	mux := http.NewServeMux()
	mux.HandleFunc("GET /health", func(w http.ResponseWriter, r *http.Request) {
		writeJSON(w, http.StatusOK, jsonResponse{Success: true, Message: "ready"})
	})
	mux.HandleFunc("GET /printers", cfg.handlePrinters)
	mux.HandleFunc("POST /print", cfg.handlePrint)

	server := &http.Server{
		Addr:              cfg.listen,
		Handler:           requestLogger(mux),
		ReadHeaderTimeout: 5 * time.Second,
		ReadTimeout:       30 * time.Second,
		WriteTimeout:      90 * time.Second,
	}

	log.Printf("Lemon print listener started on %s", cfg.listen)
	if cfg.sumatraPath != "" {
		log.Printf("Using SumatraPDF: %s", cfg.sumatraPath)
	}
	if runtime.GOOS != "windows" {
		log.Printf("Warning: this listener can receive requests on %s, but printing is implemented for Windows", runtime.GOOS)
	}

	if err := server.ListenAndServe(); err != nil && !errors.Is(err, http.ErrServerClosed) {
		log.Fatal(err)
	}
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

	cmd := exec.CommandContext(ctx, sumatra, "-print-to", req.PrinterName, "-silent", "-exit-on-print", temp.Name())
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
	if cfg.sumatraPath != "" {
		candidates = append(candidates, cfg.sumatraPath)
	}
	if env := strings.TrimSpace(os.Getenv("SUMATRA_PATH")); env != "" {
		candidates = append(candidates, env)
	}
	if exe, err := os.Executable(); err == nil {
		candidates = append(candidates, filepath.Join(filepath.Dir(exe), "SumatraPDF.exe"))
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
			return candidate, nil
		}
	}

	return "", errors.New("PDF/image labels require SumatraPDF.exe next to the listener, installed in Program Files, or passed with -sumatra")
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
		return strings.TrimSpace(auth[7:]) == cfg.token
	}

	return strings.TrimSpace(r.Header.Get("X-Print-Token")) == cfg.token
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
