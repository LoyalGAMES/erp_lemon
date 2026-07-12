//go:build !windows

package main

func validateConfigPermissions(_ string) error {
	return nil
}
