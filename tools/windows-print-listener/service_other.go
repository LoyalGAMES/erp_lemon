//go:build !windows

package main

import (
	"context"
	"fmt"
)

func runApplication(runner applicationRunner) error {
	return runner(context.Background())
}

func manageService(action string, _ appConfig) error {
	return fmt.Errorf("Windows service action %q is only supported on Windows", action)
}
