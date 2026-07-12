//go:build windows

package main

import (
	"fmt"
	"path/filepath"
	"unsafe"

	"golang.org/x/sys/windows"
)

type aclHeader struct {
	Revision byte
	Sbz1     byte
	Size     uint16
	Count    uint16
	Sbz2     uint16
}

func validateConfigPermissions(path string) error {
	if err := validateRestrictedACL(filepath.Dir(path)); err != nil {
		return fmt.Errorf("config directory: %w", err)
	}
	if err := validateRestrictedACL(path); err != nil {
		return fmt.Errorf("config file: %w", err)
	}
	return nil
}

func validateRestrictedACL(path string) error {
	descriptor, err := windows.GetNamedSecurityInfo(
		path,
		windows.SE_FILE_OBJECT,
		windows.DACL_SECURITY_INFORMATION|windows.OWNER_SECURITY_INFORMATION,
	)
	if err != nil {
		return fmt.Errorf("read ACL: %w", err)
	}
	control, _, err := descriptor.Control()
	if err != nil {
		return fmt.Errorf("read ACL control flags: %w", err)
	}
	if control&windows.SE_DACL_PROTECTED == 0 {
		return fmt.Errorf("DACL inheritance is enabled")
	}
	owner, _, err := descriptor.Owner()
	if err != nil {
		return fmt.Errorf("read owner: %w", err)
	}
	if !owner.IsWellKnown(windows.WinBuiltinAdministratorsSid) && !owner.IsWellKnown(windows.WinLocalSystemSid) {
		return fmt.Errorf("owner is not SYSTEM or Administrators")
	}
	dacl, _, err := descriptor.DACL()
	if err != nil || dacl == nil {
		return fmt.Errorf("config has no restrictive DACL")
	}

	header := (*aclHeader)(unsafe.Pointer(dacl))
	if header.Count != 2 {
		return fmt.Errorf("config DACL has %d entries; expected only SYSTEM and Administrators", header.Count)
	}

	seenSystem := false
	seenAdministrators := false
	const fileAllAccess = uint32(windows.STANDARD_RIGHTS_REQUIRED | windows.SYNCHRONIZE | 0x1ff)

	for index := uint32(0); index < uint32(header.Count); index++ {
		var ace *windows.ACCESS_ALLOWED_ACE
		if err := windows.GetAce(dacl, index, &ace); err != nil {
			return fmt.Errorf("read ACL entry %d: %w", index, err)
		}
		if ace.Header.AceType != windows.ACCESS_ALLOWED_ACE_TYPE || ace.Header.AceFlags&windows.INHERITED_ACE != 0 {
			return fmt.Errorf("ACL entry %d is not an explicit allow entry", index)
		}
		mask := uint32(ace.Mask)
		if mask&uint32(windows.GENERIC_ALL) == 0 && mask&fileAllAccess != fileAllAccess {
			return fmt.Errorf("ACL entry %d does not grant full control", index)
		}

		sid := (*windows.SID)(unsafe.Pointer(&ace.SidStart))
		switch {
		case sid.IsWellKnown(windows.WinLocalSystemSid):
			seenSystem = true
		case sid.IsWellKnown(windows.WinBuiltinAdministratorsSid):
			seenAdministrators = true
		default:
			return fmt.Errorf("ACL grants access to an account other than SYSTEM or Administrators")
		}
	}
	if !seenSystem || !seenAdministrators {
		return fmt.Errorf("ACL must grant SYSTEM and Administrators full control")
	}
	return nil
}
