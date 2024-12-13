<?php

namespace HsDeduper;

use HubSpot\Factory;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

class SequenceEnroller
{
    private $hubspot;
    private $apiKey;
    private $senders;
    private $currentSenderIndex = 0;
    private $enrollmentTracker;
    private $ignoreErrors;
    private const BATCH_SIZE = 100;

    public function __construct(string $apiKey, bool $ignoreErrors = false)
    {
        $this->apiKey = $apiKey;
        $this->ignoreErrors = $ignoreErrors;
        $this->hubspot = Factory::createWithApiKey($apiKey);
        $this->loadSenders();
    }

    private function loadSenders()
    {
        $jsonPath = __DIR__ . '/../config/sender_emails.json';
        if (!file_exists($jsonPath)) {
            throw new \RuntimeException('Sender configuration file not found');
        }

        $config = json_decode(file_get_contents($jsonPath), true);
        if (!isset($config['senders']) || empty($config['senders'])) {
            throw new \RuntimeException('No senders configured');
        }

        $this->senders = $config['senders'];
    }

    private function getNextSender(): array
    {
        if (empty($this->senders)) {
            throw new \RuntimeException('No senders available');
        }

        $sender = $this->senders[$this->currentSenderIndex];
        $this->currentSenderIndex = ($this->currentSenderIndex + 1) % count($this->senders);
        
        return $sender;
    }

    public function enrollListIntoSequence($listId, $sequenceId)
    {
        $this->enrollmentTracker = new EnrollmentTracker($listId, $sequenceId, $this->ignoreErrors);
        
        $stats = [
            'contacts_processed' => 0,
            'contacts_enrolled' => 0,
            'contacts_skipped' => 0,
            'errors' => 0,
            'sender_rotations' => 0
        ];
        $sender = $this->getNextSender();

        try {
            $client = new Client([
                'base_uri' => 'https://api.hubapi.com',
                'headers' => [
                    'Authorization' => "Bearer {$this->apiKey}",
                    'Content-Type' => 'application/json'
                ]
            ]);

            $hasMore = true;
            $vidOffset = null;

            while ($hasMore) {
                $queryParams = [
                    'count' => self::BATCH_SIZE,
                    'property' => ['email', 'firstname', 'lastname']
                ];

                if ($vidOffset !== null) {
                    $queryParams['vidOffset'] = $vidOffset;
                }

                $response = $client->get("/contacts/v1/lists/{$listId}/contacts/all", [
                    'query' => $queryParams
                ]);

                $data = json_decode($response->getBody()->getContents(), true);
                $contacts = $data['contacts'] ?? [];

                if (empty($contacts)) {
                    break;
                }

                foreach ($contacts as $contact) {
                    $stats['contacts_processed']++;
                    $contactId = (string)$contact['vid'];
                    $email = $contact['properties']['email']['value'] ?? 'N/A';

                    if (!$this->enrollmentTracker->shouldProcessContact($contactId)) {
                        $status = $this->enrollmentTracker->getContactStatus($contactId);
                        $stats['contacts_skipped']++;
                        if ($status['status'] === 1) {
                            echo "Contact {$contactId} ({$email}) was previously enrolled successfully, skipping...\n";
                        } else {
                            echo "Contact {$contactId} ({$email}) previously failed with error: {$status['error']}, skipping...\n";
                        }
                        continue;
                    }
                    
                    $enrolled = false;
                    $attemptCount = 0;
                    $maxAttempts = count($this->senders);

                    while (!$enrolled && $attemptCount < $maxAttempts) {
                        try {
                            $enrollResponse = $client->post("/automation/v4/sequences/enrollments", [
                                'json' => [
                                    'contactId' => $contactId,
                                    'sequenceId' => $sequenceId,
                                    'senderEmail' => $sender['email']
                                ],
                                'query' => [
                                    'userId' => $sender['userId']
                                ]
                            ]);
                            
                            $stats['contacts_enrolled']++;
                            $enrolled = true;
                            $this->enrollmentTracker->markAsEnrolled($contactId, true);
                            echo "Successfully enrolled contact {$contactId} ({$email})\n";
                        } catch (GuzzleException $e) {
                            $attemptCount++;

                            if ($e->hasResponse()) {
                                $statusCode = $e->getResponse()->getStatusCode();
                                $responseBody = json_decode($e->getResponse()->getBody()->getContents(), true);
                                
                                if ($statusCode === 400) {
                                    $error_message = $responseBody["message"] ?? "Unknown error";
                                    $error_message = $responseBody['errorType'] ?? $error_message;

                                    if ($error_message === 'SequenceError.SEND_LIMIT_EXCEEDED') {
                                        $sender = $this->getNextSender();
                                        echo "Send limit reached for sender {$sender['email']}, trying next sender...\n";
                                        continue;
                                    }
                                    
                                    $stats['errors']++;
                                    $this->enrollmentTracker->markAsEnrolled($contactId, false, $error_message);
                                    echo "Contact {$contactId} ({$email}) Error: {$error_message} \n";
                                    break;
                                }

                                if ($statusCode === 429) {
                                    $sender = $this->getNextSender();
                                    echo "Too many requests, Message: {$responseBody['message']} \n";
                                    continue;
                                }
                            }

                            if ($attemptCount >= $maxAttempts) {
                                $stats['errors']++;
                                echo "Error enrolling contact {$contactId}: " . $e->getMessage() . "\n";
                            }
                        }
                    }
                }

                $hasMore = $data['has-more'] ?? false;
                $vidOffset = $data['vid-offset'] ?? null;
                
                if ($hasMore) {
                    echo "\nProcessed {$stats['contacts_processed']} contacts so far. Moving to next batch...\n\n";
                }
            }

        } catch (GuzzleException $e) {
            echo "Error fetching contacts from list: " . $e->getMessage() . "\n";
        }

        return $stats;
    }

    public function printEnrollmentStats(array $stats)
    {
        echo "\nSequence Enrollment Statistics:\n";
        echo "Contacts processed: " . $stats['contacts_processed'] . "\n";
        echo "Contacts enrolled: " . $stats['contacts_enrolled'] . "\n";
        echo "Contacts skipped: " . $stats['contacts_skipped'] . "\n";
        echo "Sender rotations: " . $stats['sender_rotations'] . "\n";
        echo "Errors: " . $stats['errors'] . "\n";
        
        if (isset($this->enrollmentTracker)) {
            $totalStats = $this->enrollmentTracker->getEnrolledCount();
            echo "\nAll-time Enrollment Statistics:\n";
            echo "Total contacts processed: " . $totalStats['total'] . "\n";
            echo "Successfully enrolled: " . $totalStats['successful'] . "\n";
            echo "Failed to enroll: " . $totalStats['failed'] . "\n";
        }
    }
}
