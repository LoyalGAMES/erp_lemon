//go:build windows

package main

import (
	"errors"
	"strings"
	"syscall"
	"testing"

	"golang.org/x/sys/windows"
)

func TestRawPrintChecksFinalizationAndClosesPrinter(t *testing.T) {
	aborted, closed := 0, 0
	api := successfulRawPrinterAPI(&aborted, &closed)
	api.endDoc = func(syscall.Handle) error { return windows.ERROR_INVALID_FUNCTION }
	err := rawPrintWithAPI(api, "Zebra", []byte("^XA^XZ"))
	if !errors.Is(err, windows.ERROR_INVALID_FUNCTION) {
		t.Fatalf("unexpected error: %v", err)
	}
	if aborted != 1 || closed != 1 {
		t.Fatalf("abort=%d close=%d", aborted, closed)
	}
}

func TestRawPrintReturnsSuccessOnlyAfterEndPageEndDocAndClose(t *testing.T) {
	aborted, closed := 0, 0
	api := successfulRawPrinterAPI(&aborted, &closed)
	if err := rawPrintWithAPI(api, "Zebra", []byte("^XA^XZ")); err != nil {
		t.Fatal(err)
	}
	if aborted != 0 || closed != 1 {
		t.Fatalf("abort=%d close=%d", aborted, closed)
	}
}

func TestRawPrintAbortsAfterPartialWrite(t *testing.T) {
	aborted, closed := 0, 0
	api := successfulRawPrinterAPI(&aborted, &closed)
	api.write = func(_ syscall.Handle, data []byte) (uint32, error) {
		return uint32(len(data)) - 1, nil
	}
	err := rawPrintWithAPI(api, "Zebra", []byte("^XA^XZ"))
	if err == nil || !strings.Contains(err.Error(), "wrote") {
		t.Fatalf("unexpected error: %v", err)
	}
	if aborted != 1 || closed != 1 {
		t.Fatalf("abort=%d close=%d", aborted, closed)
	}
}

func TestRawPrintPropagatesClosePrinterFailure(t *testing.T) {
	aborted, closed := 0, 0
	api := successfulRawPrinterAPI(&aborted, &closed)
	api.close = func(syscall.Handle) error {
		closed++
		return windows.ERROR_INVALID_HANDLE
	}
	err := rawPrintWithAPI(api, "Zebra", []byte("^XA^XZ"))
	if !errors.Is(err, windows.ERROR_INVALID_HANDLE) {
		t.Fatalf("unexpected error: %v", err)
	}
	if aborted != 0 || closed != 1 {
		t.Fatalf("abort=%d close=%d", aborted, closed)
	}
}

func successfulRawPrinterAPI(aborted, closed *int) rawPrinterAPI {
	return rawPrinterAPI{
		open: func(_ *uint16, handle *syscall.Handle) error {
			*handle = syscall.Handle(123)
			return nil
		},
		close:    func(syscall.Handle) error { (*closed)++; return nil },
		startDoc: func(syscall.Handle, *docInfo1) (uint32, error) { return 42, nil },
		endDoc:   func(syscall.Handle) error { return nil },
		startPage: func(syscall.Handle) error {
			return nil
		},
		endPage: func(syscall.Handle) error {
			return nil
		},
		write: func(_ syscall.Handle, data []byte) (uint32, error) {
			return uint32(len(data)), nil
		},
		abort: func(syscall.Handle) error { (*aborted)++; return nil },
	}
}
