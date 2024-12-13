<?php

require 'vendor/autoload.php';

use HubSpot\Factory;
use HubSpot\Client\Crm\Companies\ApiException;
use HubSpot\Client\Crm\Contacts\ApiException as ContactsApiException;
use HsDeduper\Config;

Config::load();

class CompanyDeduper
{
    private $hubspot;
    private $isDryRun;
    private $duplicates = [];

    public function __construct(string $apiKey, bool $isDryRun = true)
    {
        $this->hubspot = Factory::createWithApiKey($apiKey);
        $this->isDryRun = $isDryRun;
    }

    public function findDuplicates()
    {
        $after = null;
        $companies = [];
        
        do {
            $apiResponse = $this->hubspot->crm()->companies()->basicApi()->getPage(
                100,
                $after,
                ['name', 'domain'],
                null,
                null,
                ['name']
            );
            
            $results = $apiResponse->getResults();
            
            foreach ($results as $company) {
                $name = strtolower($company->getProperties()['name']);
                if (!isset($companies[$name])) {
                    $companies[$name] = [];
                }
                $companies[$name][] = $company;
            }
            
            $after = $apiResponse->getPaging() ? $apiResponse->getPaging()->getNext() : null;
        } while ($after);

        // Filter only companies with duplicates
        $this->duplicates = array_filter($companies, function($group) {
            return count($group) > 1;
        });

        return $this->duplicates;
    }

    public function processDuplicates()
    {
        $stats = [
            'duplicates_found' => 0,
            'contacts_moved' => 0,
            'companies_removed' => 0
        ];

        foreach ($this->duplicates as $companyName => $duplicates) {
            $stats['duplicates_found'] += count($duplicates) - 1;
            
            // Use the first company as the main one
            $mainCompany = array_shift($duplicates);
            $mainCompanyId = $mainCompany->getId();
            
            foreach ($duplicates as $duplicate) {
                // Get associated contacts
                try {
                    $contactsResponse = $this->hubspot->crm()->companies()->associationsApi()
                        ->getAll($duplicate->getId(), 'contacts');
                    
                    foreach ($contactsResponse->getResults() as $contact) {
                        if (!$this->isDryRun) {
                            // Move contact to main company
                            $this->hubspot->crm()->companies()->associationsApi()
                                ->create($mainCompanyId, 'contacts', $contact->getId());
                            
                            // Remove association with duplicate company
                            $this->hubspot->crm()->companies()->associationsApi()
                                ->archive($duplicate->getId(), 'contacts', $contact->getId());
                        }
                        $stats['contacts_moved']++;
                    }

                    if (!$this->isDryRun) {
                        // Delete duplicate company
                        $this->hubspot->crm()->companies()->basicApi()->archive($duplicate->getId());
                    }
                    $stats['companies_removed']++;
                } catch (ApiException $e) {
                    echo "Error processing company {$duplicate->getId()}: " . $e->getMessage() . "\n";
                }
            }
        }

        return $stats;
    }

    public function printDuplicatesReport()
    {
        foreach ($this->duplicates as $companyName => $duplicates) {
            echo "Company: $companyName\n";
            echo "Number of duplicates: " . (count($duplicates) - 1) . "\n";
            echo "Records:\n";
            foreach ($duplicates as $company) {
                echo "- ID: " . $company->getId() . "\n";
            }
            echo "\n";
        }
    }
}

// Example usage
$apiKey = Config::getHubspotApiKey();
$isDryRun = true; // Set to false to actually perform the changes

$deduper = new CompanyDeduper($apiKey, $isDryRun);
$duplicates = $deduper->findDuplicates();
$deduper->printDuplicatesReport();

$stats = $deduper->processDuplicates();
echo "\nProcessing Statistics:\n";
echo "Duplicates found: " . $stats['duplicates_found'] . "\n";
echo "Contacts moved: " . $stats['contacts_moved'] . "\n";
echo "Companies removed: " . $stats['companies_removed'] . "\n";