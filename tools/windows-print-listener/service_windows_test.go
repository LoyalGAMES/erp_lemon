//go:build windows

package main

import (
	"slices"
	"strings"
	"testing"
)

func TestBridgeServiceArgumentsKeepTokenOutOfSCM(t *testing.T) {
	args, err := serviceArguments(appConfig{
		mode:       "bridge",
		configPath: "C:\\ProgramData\\Sempre ERP\\Print Listener\\config.ini",
		logFile:    "C:\\ProgramData\\Sempre ERP\\Print Listener\\listener.log",
		token:      "must-never-enter-scm",
	})
	if err != nil {
		t.Fatal(err)
	}
	joined := strings.Join(args, " ")
	if strings.Contains(joined, "must-never-enter-scm") {
		t.Fatalf("token leaked into service arguments: %q", joined)
	}
	if !slices.Contains(args, "-config") || !slices.Contains(args, "-log-file") {
		t.Fatalf("required service arguments are missing: %#v", args)
	}
}

func TestLegacyServiceRefusesACommandLineToken(t *testing.T) {
	_, err := serviceArguments(appConfig{
		mode:   "listener",
		listen: "127.0.0.1:17777",
		token:  "secret",
	})
	if err == nil || !strings.Contains(err.Error(), "refusing") {
		t.Fatalf("expected token rejection, got %v", err)
	}
}
