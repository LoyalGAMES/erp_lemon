//go:build windows

package main

import (
	"fmt"
	"syscall"
	"unsafe"
)

type docInfo1 struct {
	docName    *uint16
	outputFile *uint16
	dataType   *uint16
}

var (
	winspool        = syscall.NewLazyDLL("winspool.drv")
	openPrinter     = winspool.NewProc("OpenPrinterW")
	closePrinter    = winspool.NewProc("ClosePrinter")
	startDocPrinter = winspool.NewProc("StartDocPrinterW")
	endDocPrinter   = winspool.NewProc("EndDocPrinter")
	startPage       = winspool.NewProc("StartPagePrinter")
	endPage         = winspool.NewProc("EndPagePrinter")
	writePrinter    = winspool.NewProc("WritePrinter")
	abortPrinter    = winspool.NewProc("AbortPrinter")
)

type rawPrinterAPI struct {
	open      func(*uint16, *syscall.Handle) error
	close     func(syscall.Handle) error
	startDoc  func(syscall.Handle, *docInfo1) (uint32, error)
	endDoc    func(syscall.Handle) error
	startPage func(syscall.Handle) error
	endPage   func(syscall.Handle) error
	write     func(syscall.Handle, []byte) (uint32, error)
	abort     func(syscall.Handle) error
}

var systemRawPrinterAPI = rawPrinterAPI{
	open: func(name *uint16, handle *syscall.Handle) error {
		ret, _, err := openPrinter.Call(
			uintptr(unsafe.Pointer(name)),
			uintptr(unsafe.Pointer(handle)),
			0,
		)
		if ret == 0 {
			return win32Error("OpenPrinter", err)
		}
		return nil
	},
	close: func(handle syscall.Handle) error {
		ret, _, err := closePrinter.Call(uintptr(handle))
		if ret == 0 {
			return win32Error("ClosePrinter", err)
		}
		return nil
	},
	startDoc: func(handle syscall.Handle, info *docInfo1) (uint32, error) {
		ret, _, err := startDocPrinter.Call(uintptr(handle), 1, uintptr(unsafe.Pointer(info)))
		if ret == 0 {
			return 0, win32Error("StartDocPrinter", err)
		}
		return uint32(ret), nil
	},
	endDoc: func(handle syscall.Handle) error {
		ret, _, err := endDocPrinter.Call(uintptr(handle))
		if ret == 0 {
			return win32Error("EndDocPrinter", err)
		}
		return nil
	},
	startPage: func(handle syscall.Handle) error {
		ret, _, err := startPage.Call(uintptr(handle))
		if ret == 0 {
			return win32Error("StartPagePrinter", err)
		}
		return nil
	},
	endPage: func(handle syscall.Handle) error {
		ret, _, err := endPage.Call(uintptr(handle))
		if ret == 0 {
			return win32Error("EndPagePrinter", err)
		}
		return nil
	},
	write: func(handle syscall.Handle, data []byte) (uint32, error) {
		var written uint32
		ret, _, err := writePrinter.Call(
			uintptr(handle),
			uintptr(unsafe.Pointer(&data[0])),
			uintptr(uint32(len(data))),
			uintptr(unsafe.Pointer(&written)),
		)
		if ret == 0 {
			return written, win32Error("WritePrinter", err)
		}
		return written, nil
	},
	abort: func(handle syscall.Handle) error {
		ret, _, err := abortPrinter.Call(uintptr(handle))
		if ret == 0 {
			return win32Error("AbortPrinter", err)
		}
		return nil
	},
}

func rawPrint(printerName string, data []byte) error {
	return rawPrintWithAPI(systemRawPrinterAPI, printerName, data)
}

func rawPrintWithAPI(api rawPrinterAPI, printerName string, data []byte) error {
	if len(data) == 0 {
		return fmt.Errorf("empty print payload")
	}

	printerNamePtr, err := syscall.UTF16PtrFromString(printerName)
	if err != nil {
		return err
	}

	var printerHandle syscall.Handle
	if err := api.open(printerNamePtr, &printerHandle); err != nil {
		return err
	}
	closed := false
	closeHandle := func() error {
		if closed {
			return nil
		}
		closed = true
		return api.close(printerHandle)
	}
	defer func() { _ = closeHandle() }()

	docName, _ := syscall.UTF16PtrFromString("Sempre ERP label")
	dataType, _ := syscall.UTF16PtrFromString("RAW")
	info := docInfo1{
		docName:  docName,
		dataType: dataType,
	}

	if _, err := api.startDoc(printerHandle, &info); err != nil {
		if closeErr := closeHandle(); closeErr != nil {
			return fmt.Errorf("%w; %v", err, closeErr)
		}
		return err
	}
	jobStarted := true
	abortJob := func(cause error) error {
		if jobStarted {
			abortErr := api.abort(printerHandle)
			jobStarted = false
			if abortErr != nil {
				cause = fmt.Errorf("%w; %v", cause, abortErr)
			}
		}
		if closeErr := closeHandle(); closeErr != nil {
			cause = fmt.Errorf("%w; %v", cause, closeErr)
		}
		return cause
	}

	if err := api.startPage(printerHandle); err != nil {
		return abortJob(err)
	}

	written, err := api.write(printerHandle, data)
	if err != nil {
		return abortJob(err)
	}
	if written != uint32(len(data)) {
		return abortJob(fmt.Errorf("WritePrinter wrote %d of %d bytes", written, len(data)))
	}
	if err := api.endPage(printerHandle); err != nil {
		return abortJob(err)
	}
	if err := api.endDoc(printerHandle); err != nil {
		return abortJob(err)
	}
	jobStarted = false
	if err := closeHandle(); err != nil {
		return err
	}
	return nil
}

func win32Error(operation string, err error) error {
	if err != nil && err != syscall.Errno(0) {
		return fmt.Errorf("%s failed: %w", operation, err)
	}

	return fmt.Errorf("%s failed", operation)
}
