//go:build windows

package main

import (
	"context"
	"fmt"
	"log"
	"os"
	"strings"
	"time"

	"golang.org/x/sys/windows/svc"
	"golang.org/x/sys/windows/svc/mgr"
)

const (
	windowsServiceName        = "SempreERPPrintListener"
	windowsServiceDisplayName = "Sempre ERP Print Listener"
)

func runApplication(runner applicationRunner) error {
	isService, err := svc.IsWindowsService()
	if err != nil {
		return fmt.Errorf("detect Windows service context: %w", err)
	}

	if !isService {
		return runner(context.Background())
	}

	log.Printf("Running as Windows service %s", windowsServiceName)
	return svc.Run(windowsServiceName, &windowsService{runner: runner})
}

type windowsService struct {
	runner applicationRunner
}

func (service *windowsService) Execute(
	_ []string,
	requests <-chan svc.ChangeRequest,
	changes chan<- svc.Status,
) (bool, uint32) {
	changes <- svc.Status{State: svc.StartPending}
	runnerErrors := make(chan error, 1)
	ctx, cancel := context.WithCancel(context.Background())
	defer cancel()

	go func() {
		runnerErrors <- service.runner(ctx)
	}()

	status := svc.Status{
		State:   svc.Running,
		Accepts: svc.AcceptStop | svc.AcceptShutdown,
	}
	changes <- status

	for {
		select {
		case err := <-runnerErrors:
			if err == nil {
				return false, 0
			}
			log.Printf("Print bridge stopped unexpectedly: %v", err)
			return true, 1

		case request := <-requests:
			switch request.Cmd {
			case svc.Interrogate:
				changes <- status
			case svc.Stop, svc.Shutdown:
				changes <- svc.Status{State: svc.StopPending}
				cancel()
				select {
				case err := <-runnerErrors:
					if err != nil {
						log.Printf("Graceful print bridge shutdown failed: %v", err)
						return true, 1
					}
				case <-time.After(15 * time.Second):
					log.Printf("Graceful print bridge shutdown timed out")
					return true, 1
				}
				return false, 0
			default:
				log.Printf("Ignoring unsupported Windows service control request: %d", request.Cmd)
			}
		}
	}
}

func manageService(action string, cfg appConfig) error {
	action = strings.ToLower(strings.TrimSpace(action))

	manager, err := mgr.Connect()
	if err != nil {
		return fmt.Errorf("connect to Windows Service Control Manager: %w", err)
	}
	defer manager.Disconnect()

	switch action {
	case "install":
		return installService(manager, cfg)
	case "uninstall":
		return uninstallService(manager)
	case "start":
		return startService(manager)
	case "stop":
		return stopService(manager)
	default:
		return fmt.Errorf("unknown Windows service action %q (expected install, uninstall, start, or stop)", action)
	}
}

func installService(manager *mgr.Mgr, cfg appConfig) error {
	executable, err := os.Executable()
	if err != nil {
		return fmt.Errorf("resolve listener executable: %w", err)
	}

	if existing, openErr := manager.OpenService(windowsServiceName); openErr == nil {
		existing.Close()
		return fmt.Errorf("Windows service %s already exists; uninstall it before installing", windowsServiceName)
	}

	args := []string{"-mode", cfg.mode}
	switch cfg.mode {
	case "bridge":
		if strings.TrimSpace(cfg.configPath) == "" {
			return fmt.Errorf("outbound bridge service requires -config")
		}
		args = append(args, "-config", cfg.configPath)
	case "listener":
		if err := validateLegacyListenerConfig(cfg); err != nil {
			return err
		}
		if cfg.token != "" {
			return fmt.Errorf("refusing to store a listener token in Windows service arguments")
		}
		args = append(args, "-listen", cfg.listen)
		if cfg.sumatraPath != "" {
			args = append(args, "-sumatra", cfg.sumatraPath)
		}
	default:
		return fmt.Errorf("unknown service mode %q", cfg.mode)
	}
	if cfg.logFile != "" {
		args = append(args, "-log-file", cfg.logFile)
	}

	service, err := manager.CreateService(executable, windowsServiceName, mgr.Config{
		DisplayName:      windowsServiceDisplayName,
		Description:      "Securely polls Sempre ERP over outbound HTTPS and sends labels to Windows printers.",
		StartType:        mgr.StartAutomatic,
		ErrorControl:     mgr.ErrorNormal,
		Dependencies:     []string{"Spooler"},
	}, args...)
	if err != nil {
		return fmt.Errorf("create Windows service %s: %w", windowsServiceName, err)
	}
	defer service.Close()

	log.Printf("Windows service %s installed", windowsServiceName)
	return nil
}

func uninstallService(manager *mgr.Mgr) error {
	service, err := manager.OpenService(windowsServiceName)
	if err != nil {
		return fmt.Errorf("open Windows service %s: %w", windowsServiceName, err)
	}
	defer service.Close()

	status, queryErr := service.Query()
	if queryErr == nil && status.State != svc.Stopped {
		if _, controlErr := service.Control(svc.Stop); controlErr == nil {
			if err := waitForServiceState(service, svc.Stopped, 20*time.Second); err != nil {
				return fmt.Errorf("stop Windows service before removal: %w", err)
			}
		}
	}
	if err := service.Delete(); err != nil {
		return fmt.Errorf("delete Windows service %s: %w", windowsServiceName, err)
	}

	log.Printf("Windows service %s removed", windowsServiceName)
	return nil
}

func startService(manager *mgr.Mgr) error {
	service, err := manager.OpenService(windowsServiceName)
	if err != nil {
		return fmt.Errorf("open Windows service %s: %w", windowsServiceName, err)
	}
	defer service.Close()

	if err := service.Start(); err != nil {
		return fmt.Errorf("start Windows service %s: %w", windowsServiceName, err)
	}

	return waitForServiceState(service, svc.Running, 20*time.Second)
}

func stopService(manager *mgr.Mgr) error {
	service, err := manager.OpenService(windowsServiceName)
	if err != nil {
		return fmt.Errorf("open Windows service %s: %w", windowsServiceName, err)
	}
	defer service.Close()

	status, err := service.Query()
	if err == nil && status.State == svc.Stopped {
		return nil
	}

	if _, err := service.Control(svc.Stop); err != nil {
		return fmt.Errorf("stop Windows service %s: %w", windowsServiceName, err)
	}

	return waitForServiceState(service, svc.Stopped, 20*time.Second)
}

func waitForServiceState(service *mgr.Service, expected svc.State, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)

	for time.Now().Before(deadline) {
		status, err := service.Query()
		if err != nil {
			return err
		}
		if status.State == expected {
			return nil
		}
		time.Sleep(250 * time.Millisecond)
	}

	return fmt.Errorf("Windows service did not reach state %d before timeout", expected)
}
