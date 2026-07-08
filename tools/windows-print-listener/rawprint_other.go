//go:build !windows

package main

import "fmt"

func rawPrint(printerName string, data []byte) error {
	return fmt.Errorf("RAW printer output is only supported on Windows")
}
