package main

import (
	"os"
	"path/filepath"
	"testing"
	"time"
)

func TestPrintJournalRejectsTrailingCorruption(t *testing.T) {
	path := filepath.Join(t.TempDir(), "print-journal.json")
	if err := os.WriteFile(path, []byte("[] trailing"), 0o600); err != nil {
		t.Fatal(err)
	}
	if _, err := openPrintJournal(path); err == nil {
		t.Fatal("expected corrupt journal to be rejected")
	}
}

func TestPrintJournalPersistsAndReloadsAcknowledgement(t *testing.T) {
	path := filepath.Join(t.TempDir(), "print-journal.json")
	journal, err := openPrintJournal(path)
	if err != nil {
		t.Fatal(err)
	}
	record := printJournalRecord{
		JobID:      17,
		LeaseToken: "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
		Worker:     "PACK-PC-1",
		Station:    "station-1",
		PrintedAt:  time.Now().UTC(),
	}
	if err := journal.record(record); err != nil {
		t.Fatal(err)
	}
	reloaded, err := openPrintJournal(path)
	if err != nil {
		t.Fatal(err)
	}
	if pending := reloaded.pending(); len(pending) != 1 || pending[0].JobID != record.JobID {
		t.Fatalf("unexpected reloaded journal: %#v", pending)
	}
}
