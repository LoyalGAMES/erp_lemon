package main

import (
	"fmt"
	"os"
	"sync"
)

const (
	defaultLogMaxBytes = int64(5 << 20)
	defaultLogBackups  = 3
)

type rotatingLogWriter struct {
	mu       sync.Mutex
	path     string
	maxBytes int64
	backups  int
	file     *os.File
	size     int64
}

func newRotatingLogWriter(path string, maxBytes int64, backups int) (*rotatingLogWriter, error) {
	if maxBytes <= 0 || backups < 1 {
		return nil, fmt.Errorf("log rotation requires a positive size and at least one backup")
	}
	writer := &rotatingLogWriter{path: path, maxBytes: maxBytes, backups: backups}
	if err := writer.open(); err != nil {
		return nil, err
	}
	return writer, nil
}

func (writer *rotatingLogWriter) Write(data []byte) (int, error) {
	writer.mu.Lock()
	defer writer.mu.Unlock()
	if writer.file == nil {
		return 0, os.ErrClosed
	}
	if writer.size > 0 && writer.size+int64(len(data)) > writer.maxBytes {
		if err := writer.rotate(); err != nil {
			return 0, err
		}
	}
	n, err := writer.file.Write(data)
	writer.size += int64(n)
	return n, err
}

func (writer *rotatingLogWriter) Close() error {
	writer.mu.Lock()
	defer writer.mu.Unlock()
	if writer.file == nil {
		return nil
	}
	err := writer.file.Close()
	writer.file = nil
	return err
}

func (writer *rotatingLogWriter) open() error {
	file, err := os.OpenFile(writer.path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o640)
	if err != nil {
		return err
	}
	info, err := file.Stat()
	if err != nil {
		file.Close()
		return err
	}
	writer.file = file
	writer.size = info.Size()
	return nil
}

func (writer *rotatingLogWriter) rotate() error {
	if err := writer.file.Close(); err != nil {
		return err
	}
	for index := writer.backups; index >= 1; index-- {
		destination := fmt.Sprintf("%s.%d", writer.path, index)
		if err := os.Remove(destination); err != nil && !os.IsNotExist(err) {
			_ = writer.open()
			return err
		}
		source := writer.path
		if index > 1 {
			source = fmt.Sprintf("%s.%d", writer.path, index-1)
		}
		if err := os.Rename(source, destination); err != nil && !os.IsNotExist(err) {
			_ = writer.open()
			return err
		}
	}
	return writer.open()
}
