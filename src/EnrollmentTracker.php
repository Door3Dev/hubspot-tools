<?php

namespace HsDeduper;

class EnrollmentTracker
{
    private $logFile;
    private $enrolledContacts = [];
    private $ignoreErrors;

    public function __construct(string $listId, string $sequenceId, bool $ignoreErrors = false)
    {
        $this->logFile = $this->getLogFilePath($listId, $sequenceId);
        $this->ignoreErrors = $ignoreErrors;
        $this->loadEnrolledContacts();
    }

    private function getLogFilePath(string $listId, string $sequenceId): string
    {
        $logDir = __DIR__ . '/../logs';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }
        return $logDir . "/enrollments_{$listId}_{$sequenceId}.json";
    }

    private function loadEnrolledContacts(): void
    {
        if (file_exists($this->logFile)) {
            $content = file_get_contents($this->logFile);
            $data = json_decode($content, true);
            $this->enrolledContacts = [];
            
            // Index contacts by their ID
            foreach ($data['enrolled_contacts'] ?? [] as $contact) {
                if (isset($contact['id'])) {
                    $this->enrolledContacts[$contact['id']] = $contact;
                }
            }
        }
    }

    public function shouldProcessContact(string $contactId): bool
    {
        if (!isset($this->enrolledContacts[$contactId])) {
            return true;
        }

        $contact = $this->enrolledContacts[$contactId];
        
        // If ignoring errors, process contacts that previously failed
        if ($this->ignoreErrors && $contact['status'] === 0) {
            return true;
        }

        // Skip contacts that were successfully enrolled
        if ($contact['status'] === 1) {
            return false;
        }

        // Skip contacts that failed if not ignoring errors
        return false;
    }

    public function markAsEnrolled(string $contactId, bool $success = true, ?string $error = null): void
    {
        $this->enrolledContacts[$contactId] = [
            'id' => $contactId,
            'status' => $success ? 1 : 0,
            'error' => $error,
            'timestamp' => date('Y-m-d H:i:s')
        ];
        $this->saveEnrolledContacts();
    }

    private function saveEnrolledContacts(): void
    {
        $data = [
            'enrolled_contacts' => array_values($this->enrolledContacts),
            'last_updated' => date('Y-m-d H:i:s')
        ];
        file_put_contents($this->logFile, json_encode($data, JSON_PRETTY_PRINT));
    }

    public function getEnrolledCount(): array
    {
        $successful = 0;
        $failed = 0;

        foreach ($this->enrolledContacts as $contact) {
            if ($contact['status'] === 1) {
                $successful++;
            } else {
                $failed++;
            }
        }

        return [
            'total' => count($this->enrolledContacts),
            'successful' => $successful,
            'failed' => $failed
        ];
    }

    public function getContactStatus(string $contactId): ?array
    {
        return $this->enrolledContacts[$contactId] ?? null;
    }
}
