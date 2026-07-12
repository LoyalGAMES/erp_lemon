//go:build windows

package main

import (
	"bytes"
	"context"
	"encoding/json"
	"fmt"
	"os/exec"
	"sort"
	"strings"
	"time"
)

type win32Printer struct {
	Name       string `json:"Name"`
	DriverName string `json:"DriverName"`
	PortName   string `json:"PortName"`
	Default    bool   `json:"Default"`
}

func installedPrinters() ([]printerInfo, error) {
	ctx, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()

	command := "Get-CimInstance Win32_Printer | Select-Object Name,DriverName,PortName,Default | ConvertTo-Json -Compress"
	output, err := exec.CommandContext(ctx, "powershell.exe", "-NoLogo", "-NoProfile", "-NonInteractive", "-Command", command).CombinedOutput()
	if ctx.Err() != nil {
		return nil, fmt.Errorf("printer listing timed out")
	}
	if err != nil {
		return nil, fmt.Errorf("printer listing failed: %w %s", err, strings.TrimSpace(string(output)))
	}

	output = bytes.TrimSpace(output)
	if len(output) == 0 {
		return []printerInfo{}, nil
	}

	var rows []win32Printer
	if output[0] == '[' {
		if err := json.Unmarshal(output, &rows); err != nil {
			return nil, err
		}
	} else {
		var row win32Printer
		if err := json.Unmarshal(output, &row); err != nil {
			return nil, err
		}
		rows = append(rows, row)
	}

	printers := make([]printerInfo, 0, len(rows))
	for _, row := range rows {
		name := strings.TrimSpace(row.Name)
		if name == "" {
			continue
		}
		printers = append(printers, printerInfo{
			Name:    name,
			Driver:  strings.TrimSpace(row.DriverName),
			Port:    strings.TrimSpace(row.PortName),
			Default: row.Default,
		})
	}

	sort.SliceStable(printers, func(i, j int) bool {
		if printers[i].Default != printers[j].Default {
			return printers[i].Default
		}

		return strings.ToLower(printers[i].Name) < strings.ToLower(printers[j].Name)
	})

	return printers, nil
}
