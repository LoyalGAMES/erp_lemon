//go:build !windows

package main

import "fmt"

func installedPrinters() ([]printerInfo, error) {
	return nil, fmt.Errorf("printer listing is only supported on Windows")
}
