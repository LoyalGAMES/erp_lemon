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
)

func rawPrint(printerName string, data []byte) error {
	if len(data) == 0 {
		return fmt.Errorf("empty print payload")
	}

	printerNamePtr, err := syscall.UTF16PtrFromString(printerName)
	if err != nil {
		return err
	}

	var printerHandle syscall.Handle
	ret, _, callErr := openPrinter.Call(
		uintptr(unsafe.Pointer(printerNamePtr)),
		uintptr(unsafe.Pointer(&printerHandle)),
		0,
	)
	if ret == 0 {
		return win32Error("OpenPrinter", callErr)
	}
	defer closePrinter.Call(uintptr(printerHandle))

	docName, _ := syscall.UTF16PtrFromString("Lemon ERP label")
	dataType, _ := syscall.UTF16PtrFromString("RAW")
	info := docInfo1{
		docName:  docName,
		dataType: dataType,
	}

	ret, _, callErr = startDocPrinter.Call(
		uintptr(printerHandle),
		1,
		uintptr(unsafe.Pointer(&info)),
	)
	if ret == 0 {
		return win32Error("StartDocPrinter", callErr)
	}
	defer endDocPrinter.Call(uintptr(printerHandle))

	ret, _, callErr = startPage.Call(uintptr(printerHandle))
	if ret == 0 {
		return win32Error("StartPagePrinter", callErr)
	}
	defer endPage.Call(uintptr(printerHandle))

	var written uint32
	ret, _, callErr = writePrinter.Call(
		uintptr(printerHandle),
		uintptr(unsafe.Pointer(&data[0])),
		uintptr(uint32(len(data))),
		uintptr(unsafe.Pointer(&written)),
	)
	if ret == 0 {
		return win32Error("WritePrinter", callErr)
	}
	if written != uint32(len(data)) {
		return fmt.Errorf("WritePrinter wrote %d of %d bytes", written, len(data))
	}

	return nil
}

func win32Error(operation string, err error) error {
	if err != nil && err != syscall.Errno(0) {
		return fmt.Errorf("%s failed: %w", operation, err)
	}

	return fmt.Errorf("%s failed", operation)
}
