package main

import (
	"bytes"
	"fmt"
	"net"
	"net/url"
	"os"
	"strconv"
	"strings"
	"unicode/utf16"
)

type bridgeConfig struct {
	BaseURL     string
	Token       string
	Station     string
	WorkerName  string
	PollSeconds int
	SumatraPath string
}

func loadBridgeConfig(path string) (bridgeConfig, error) {
	path = strings.TrimSpace(path)
	if path == "" {
		return bridgeConfig{}, fmt.Errorf("config path is empty")
	}
	if err := validateConfigPermissions(path); err != nil {
		return bridgeConfig{}, fmt.Errorf("unsafe config permissions: %w", err)
	}

	data, err := os.ReadFile(path)
	if err != nil {
		return bridgeConfig{}, fmt.Errorf("read config %s: %w", path, err)
	}
	text, err := decodeConfigText(data)
	if err != nil {
		return bridgeConfig{}, fmt.Errorf("decode config %s: %w", path, err)
	}

	config, err := parseBridgeConfig(text)
	if err != nil {
		return bridgeConfig{}, fmt.Errorf("parse config %s: %w", path, err)
	}
	return config, nil
}

func decodeConfigText(data []byte) (string, error) {
	switch {
	case bytes.HasPrefix(data, []byte{0xef, 0xbb, 0xbf}):
		return string(data[3:]), nil
	case bytes.HasPrefix(data, []byte{0xff, 0xfe}):
		return decodeUTF16(data[2:], true)
	case bytes.HasPrefix(data, []byte{0xfe, 0xff}):
		return decodeUTF16(data[2:], false)
	case bytes.IndexByte(data, 0) >= 0:
		return "", fmt.Errorf("config contains NUL bytes without a supported BOM")
	default:
		return string(data), nil
	}
}

func decodeUTF16(data []byte, littleEndian bool) (string, error) {
	if len(data)%2 != 0 {
		return "", fmt.Errorf("invalid UTF-16 byte length")
	}
	words := make([]uint16, len(data)/2)
	for i := range words {
		first := uint16(data[i*2])
		second := uint16(data[i*2+1])
		if littleEndian {
			words[i] = first | second<<8
		} else {
			words[i] = first<<8 | second
		}
	}
	return string(utf16.Decode(words)), nil
}

func parseBridgeConfig(text string) (bridgeConfig, error) {
	values := make(map[string]string)
	inBridgeSection := false

	for lineNumber, rawLine := range strings.Split(strings.ReplaceAll(text, "\r\n", "\n"), "\n") {
		line := strings.TrimSpace(rawLine)
		if line == "" || strings.HasPrefix(line, ";") || strings.HasPrefix(line, "#") {
			continue
		}
		if strings.HasPrefix(line, "[") && strings.HasSuffix(line, "]") {
			inBridgeSection = strings.EqualFold(strings.TrimSpace(line[1:len(line)-1]), "bridge")
			continue
		}
		if !inBridgeSection {
			continue
		}

		parts := strings.SplitN(line, "=", 2)
		if len(parts) != 2 {
			return bridgeConfig{}, fmt.Errorf("line %d is not key=value", lineNumber+1)
		}
		key := canonicalConfigKey(parts[0])
		if key == "" {
			return bridgeConfig{}, fmt.Errorf("line %d has an unsupported key %q", lineNumber+1, strings.TrimSpace(parts[0]))
		}
		if _, exists := values[key]; exists {
			return bridgeConfig{}, fmt.Errorf("line %d duplicates key %s", lineNumber+1, key)
		}
		values[key] = strings.TrimSpace(parts[1])
	}

	pollSeconds := 2
	if value := values["poll_seconds"]; value != "" {
		parsed, err := strconv.Atoi(value)
		if err != nil {
			return bridgeConfig{}, fmt.Errorf("poll_seconds must be an integer")
		}
		pollSeconds = parsed
	}

	config := bridgeConfig{
		BaseURL:     strings.TrimRight(values["base_url"], "/"),
		Token:       values["token"],
		Station:     values["station"],
		WorkerName:  values["worker_name"],
		PollSeconds: pollSeconds,
		SumatraPath: values["sumatra_path"],
	}
	if config.WorkerName == "" {
		config.WorkerName, _ = os.Hostname()
	}
	if err := config.validate(); err != nil {
		return bridgeConfig{}, err
	}
	return config, nil
}

func canonicalConfigKey(key string) string {
	key = strings.ToLower(strings.TrimSpace(key))
	key = strings.NewReplacer("_", "", "-", "").Replace(key)
	switch key {
	case "baseurl":
		return "base_url"
	case "token":
		return "token"
	case "station":
		return "station"
	case "worker", "workername":
		return "worker_name"
	case "pollseconds":
		return "poll_seconds"
	case "sumatrapath":
		return "sumatra_path"
	default:
		return ""
	}
}

func (config bridgeConfig) validate() error {
	if config.BaseURL == "" {
		return fmt.Errorf("base_url is required")
	}
	parsedURL, err := url.Parse(config.BaseURL)
	if err != nil || parsedURL.Host == "" {
		return fmt.Errorf("base_url is not a valid absolute URL")
	}
	if parsedURL.User != nil || parsedURL.RawQuery != "" || parsedURL.Fragment != "" {
		return fmt.Errorf("base_url cannot contain credentials, a query, or a fragment")
	}
	if !strings.EqualFold(parsedURL.Scheme, "https") {
		host := parsedURL.Hostname()
		loopback := strings.EqualFold(host, "localhost")
		if address := net.ParseIP(host); address != nil {
			loopback = address.IsLoopback()
		}
		if !strings.EqualFold(parsedURL.Scheme, "http") || !loopback {
			return fmt.Errorf("base_url must use HTTPS (HTTP is allowed only for loopback tests)")
		}
	}
	if config.Token == "" {
		return fmt.Errorf("token is required")
	}
	if len(config.Token) > 4096 {
		return fmt.Errorf("token is too long")
	}
	if config.Station == "" || len(config.Station) > 40 || containsControl(config.Station) {
		return fmt.Errorf("station is required and must be at most 40 characters without control characters")
	}
	if config.WorkerName == "" || len(config.WorkerName) > 120 || containsControl(config.WorkerName) {
		return fmt.Errorf("worker_name is required and must be at most 120 characters without control characters")
	}
	if config.PollSeconds < 1 || config.PollSeconds > 60 {
		return fmt.Errorf("poll_seconds must be between 1 and 60")
	}
	if containsControl(config.SumatraPath) {
		return fmt.Errorf("sumatra_path cannot contain control characters")
	}
	return nil
}

func containsControl(value string) bool {
	return strings.ContainsAny(value, "\r\n\x00")
}
