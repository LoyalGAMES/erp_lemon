package main

import (
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
	"sync"
	"time"
)

const maxJournalSize = 1 << 20

type printJournalRecord struct {
	JobID      int64     `json:"job_id"`
	LeaseToken string    `json:"lease_token"`
	Worker     string    `json:"worker"`
	Station    string    `json:"station"`
	PrintedAt  time.Time `json:"printed_at"`
}

type printJournal struct {
	mu      sync.Mutex
	path    string
	records map[int64]printJournalRecord
}

func openPrintJournal(path string) (*printJournal, error) {
	journal := &printJournal{path: path, records: map[int64]printJournalRecord{}}
	file, err := os.Open(path)
	if os.IsNotExist(err) {
		return journal, nil
	}
	if err != nil {
		return nil, fmt.Errorf("open print acknowledgement journal: %w", err)
	}
	defer file.Close()

	data, err := io.ReadAll(io.LimitReader(file, maxJournalSize+1))
	if err != nil {
		return nil, fmt.Errorf("read print acknowledgement journal: %w", err)
	}
	if len(data) > maxJournalSize {
		return nil, fmt.Errorf("print acknowledgement journal exceeds %d bytes", maxJournalSize)
	}
	var records []printJournalRecord
	if err := json.Unmarshal(data, &records); err != nil {
		return nil, fmt.Errorf("decode print acknowledgement journal: %w", err)
	}
	for _, record := range records {
		if err := validateJournalRecord(record); err != nil {
			return nil, fmt.Errorf("print acknowledgement journal contains an invalid record")
		}
		journal.records[record.JobID] = record
	}
	return journal, nil
}

func (journal *printJournal) pending() []printJournalRecord {
	journal.mu.Lock()
	defer journal.mu.Unlock()
	records := make([]printJournalRecord, 0, len(journal.records))
	for _, record := range journal.records {
		records = append(records, record)
	}
	sort.Slice(records, func(i, j int) bool { return records[i].JobID < records[j].JobID })
	return records
}

func (journal *printJournal) record(record printJournalRecord) error {
	if err := validateJournalRecord(record); err != nil {
		return err
	}
	journal.mu.Lock()
	defer journal.mu.Unlock()
	journal.records[record.JobID] = record
	return journal.persistLocked()
}

func validateJournalRecord(record printJournalRecord) error {
	if record.JobID <= 0 || len(record.LeaseToken) != 64 || record.Worker == "" || len(record.Worker) > 120 ||
		record.Station == "" || len(record.Station) > 40 || record.PrintedAt.IsZero() {
		return fmt.Errorf("invalid print acknowledgement journal record")
	}
	return nil
}

func (journal *printJournal) remove(jobID int64) error {
	journal.mu.Lock()
	defer journal.mu.Unlock()
	delete(journal.records, jobID)
	return journal.persistLocked()
}

func (journal *printJournal) persistLocked() error {
	if err := os.MkdirAll(filepath.Dir(journal.path), 0o700); err != nil {
		return err
	}
	records := make([]printJournalRecord, 0, len(journal.records))
	for _, record := range journal.records {
		records = append(records, record)
	}
	sort.Slice(records, func(i, j int) bool { return records[i].JobID < records[j].JobID })

	temporary, err := os.CreateTemp(filepath.Dir(journal.path), ".print-journal-*.tmp")
	if err != nil {
		return err
	}
	temporaryPath := temporary.Name()
	defer os.Remove(temporaryPath)
	encoder := json.NewEncoder(temporary)
	if err := encoder.Encode(records); err != nil {
		temporary.Close()
		return err
	}
	if err := temporary.Sync(); err != nil {
		temporary.Close()
		return err
	}
	if err := temporary.Close(); err != nil {
		return err
	}
	if err := replaceFileDurably(temporaryPath, journal.path); err != nil {
		return err
	}
	return nil
}
