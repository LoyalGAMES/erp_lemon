//go:build !windows

package main

import "fmt"

func protectConfigDirectory(path string) error {
	return fmt.Errorf("protect config directory %q: Windows only", path)
}

func protectConfigFile(path string) error {
	return fmt.Errorf("protect config file %q: Windows only", path)
}
