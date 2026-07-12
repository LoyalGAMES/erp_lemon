//go:build windows

package main

import (
	"fmt"
	"os"
	"strings"

	"golang.org/x/sys/windows"
)

const (
	configDirectorySDDL = "O:BAG:BAD:P(A;OICI;FA;;;SY)(A;OICI;FA;;;BA)"
	configFileSDDL      = "O:BAG:BAD:P(A;;FA;;;SY)(A;;FA;;;BA)"
)

func protectConfigDirectory(path string) error {
	path = strings.TrimSpace(path)
	if path == "" {
		return fmt.Errorf("config directory path is empty")
	}
	if err := os.MkdirAll(path, 0o700); err != nil {
		return fmt.Errorf("create config directory: %w", err)
	}
	if err := rejectReparsePoint(path); err != nil {
		return err
	}
	if err := applyProtectedDACL(path, configDirectorySDDL); err != nil {
		return fmt.Errorf("protect config directory: %w", err)
	}
	return nil
}

func protectConfigFile(path string) error {
	path = strings.TrimSpace(path)
	if path == "" {
		return fmt.Errorf("config file path is empty")
	}
	info, err := os.Stat(path)
	if err != nil {
		return fmt.Errorf("open config file: %w", err)
	}
	if !info.Mode().IsRegular() {
		return fmt.Errorf("config path is not a regular file")
	}
	if err := rejectReparsePoint(path); err != nil {
		return err
	}
	if err := applyProtectedDACL(path, configFileSDDL); err != nil {
		return fmt.Errorf("protect config file: %w", err)
	}
	return nil
}

func applyProtectedDACL(path, sddl string) error {
	descriptor, err := windows.SecurityDescriptorFromString(sddl)
	if err != nil {
		return fmt.Errorf("create security descriptor: %w", err)
	}
	dacl, _, err := descriptor.DACL()
	if err != nil {
		return fmt.Errorf("read generated DACL: %w", err)
	}
	owner, _, err := descriptor.Owner()
	if err != nil {
		return fmt.Errorf("read generated owner: %w", err)
	}
	group, _, err := descriptor.Group()
	if err != nil {
		return fmt.Errorf("read generated group: %w", err)
	}
	if err := windows.SetNamedSecurityInfo(
		path,
		windows.SE_FILE_OBJECT,
		windows.OWNER_SECURITY_INFORMATION|windows.GROUP_SECURITY_INFORMATION|windows.DACL_SECURITY_INFORMATION|windows.PROTECTED_DACL_SECURITY_INFORMATION,
		owner,
		group,
		dacl,
		nil,
	); err != nil {
		return err
	}
	return nil
}

func rejectReparsePoint(path string) error {
	pathPointer, err := windows.UTF16PtrFromString(path)
	if err != nil {
		return err
	}
	attributes, err := windows.GetFileAttributes(pathPointer)
	if err != nil {
		return fmt.Errorf("read config path attributes: %w", err)
	}
	if attributes&windows.FILE_ATTRIBUTE_REPARSE_POINT != 0 {
		return fmt.Errorf("config path cannot be a reparse point")
	}
	return nil
}
